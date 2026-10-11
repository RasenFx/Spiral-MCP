<?php
/**
 * Abilities de contenido: listar, leer, crear y editar páginas y entradas, y
 * leer y reemplazar su layout de YOOtheme.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Tipos de contenido permitidos. Ajustable con el filtro spiral_mcp_post_types. */
function spiral_mcp_post_types() {
	return apply_filters( 'spiral_mcp_post_types', array( 'page', 'post' ) );
}

/** Estados permitidos al crear/actualizar. */
function spiral_mcp_statuses() {
	return array( 'publish', 'draft', 'pending', 'private' );
}

/** Obtiene un post válido o WP_Error. */
function spiral_mcp_get_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || ! in_array( $post->post_type, spiral_mcp_post_types(), true ) ) {
		return new WP_Error( 'spiral_mcp_not_found', 'No existe una página o entrada con ese ID.' );
	}
	return $post;
}

/** Resumen de un post para listados. */
function spiral_mcp_summary( WP_Post $post ) {
	return array(
		'id'       => $post->ID,
		'type'     => $post->post_type,
		'title'    => get_the_title( $post ),
		'status'   => $post->post_status,
		'slug'     => $post->post_name,
		'url'      => get_permalink( $post ),
		'modified' => mysql_to_rfc3339( $post->post_modified ),
		'yootheme' => spiral_mcp_has_layout( $post->post_content ),
	);
}

/**
 * Separa el contenido de YOOtheme en [html, layout_array|null].
 * YOOtheme guarda el layout como JSON en el último comentario: <!-- {...} -->
 */
function spiral_mcp_split_layout( $content ) {
	$pos = strrpos( $content, '<!-- {' );
	if ( false === $pos ) {
		return array( $content, null );
	}
	$comment = substr( $content, $pos );
	if ( ! preg_match( '/^<!--\s(.*)\s-->\s*$/s', $comment, $m ) ) {
		return array( $content, null );
	}
	$layout = json_decode( $m[1], true );
	if ( ! is_array( $layout ) || ( $layout['type'] ?? '' ) !== 'layout' ) {
		return array( $content, null );
	}
	return array( substr( $content, 0, $pos ), $layout );
}

function spiral_mcp_has_layout( $content ) {
	list( , $layout ) = spiral_mcp_split_layout( $content );
	return null !== $layout;
}

/** Guarda cambios con wp_update_post (crea revisión). */
function spiral_mcp_save( array $postarr ) {
	// wp_insert_post espera datos "slashed"; sin esto se rompen las \ del JSON.
	$result = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	clean_post_cache( $result );
	return $result;
}

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-content', array(
		'label'       => 'Contenido Spiral-MCP',
		'description' => 'Leer y editar páginas, entradas y layouts de YOOtheme.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	/* 1. Listar contenido ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-content', array(
		'label'               => 'Listar páginas y entradas',
		'description'         => 'Lista páginas o entradas con filtros opcionales (tipo, estado, búsqueda). Devuelve ID, título, estado, URL y si usa el constructor de YOOtheme.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'enum' => spiral_mcp_post_types(), 'default' => 'page' ),
				'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'draft', 'pending', 'private' ), 'default' => 'any' ),
				'search'    => array( 'type' => 'string', 'description' => 'Texto a buscar en título/contenido.' ),
				'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total' => array( 'type' => 'integer' ),
				'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$q     = new WP_Query( array(
				'post_type'      => $input['post_type'] ?? 'page',
				'post_status'    => $input['status'] ?? 'any',
				's'              => $input['search'] ?? '',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			) );
			return array(
				'total' => (int) $q->found_posts,
				'items' => array_map( 'spiral_mcp_summary', $q->posts ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Leer contenido -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-content', array(
		'label'               => 'Obtener página o entrada',
		'description'         => 'Devuelve título, estado, extracto y contenido en bruto de una página o entrada. Si usa YOOtheme, el contenido incluye el HTML de respaldo; para editar el diseño usa spiral-mcp/get-yootheme-layout.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			list( $html, $layout ) = spiral_mcp_split_layout( $post->post_content );
			return array_merge( spiral_mcp_summary( $post ), array(
				'excerpt'      => $post->post_excerpt,
				'content'      => $html,
				'has_yootheme' => null !== $layout,
			) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Crear contenido ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/create-content', array(
		'label'               => 'Crear página o entrada',
		'description'         => 'Crea una página o entrada nueva. Por defecto queda como borrador.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'enum' => spiral_mcp_post_types(), 'default' => 'page' ),
				'title'     => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string', 'description' => 'HTML o bloques de Gutenberg.' ),
				'excerpt'   => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string', 'enum' => spiral_mcp_statuses(), 'default' => 'draft' ),
				'slug'      => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$type = get_post_type_object( $input['post_type'] ?? 'page' );
			if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
				return false;
			}
			if ( ( $input['status'] ?? 'draft' ) === 'publish' ) {
				return current_user_can( $type->cap->publish_posts );
			}
			return true;
		},
		'execute_callback'    => function ( $input ) {
			$id = wp_insert_post( wp_slash( array(
				'post_type'    => $input['post_type'] ?? 'page',
				'post_title'   => $input['title'],
				'post_content' => $input['content'] ?? '',
				'post_excerpt' => $input['excerpt'] ?? '',
				'post_status'  => $input['status'] ?? 'draft',
				'post_name'    => $input['slug'] ?? '',
			) ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			return spiral_mcp_summary( get_post( $id ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Actualizar contenido -------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-content', array(
		'label'               => 'Actualizar página o entrada',
		'description'         => 'Actualiza título, contenido, extracto, estado o slug. Solo cambia los campos enviados y guarda una revisión. En páginas de YOOtheme, "content" reemplaza solo el HTML de respaldo y se conserva el layout; para cambiar lo que se ve usa spiral-mcp/update-yootheme-layout.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string', 'enum' => spiral_mcp_statuses() ),
				'slug'    => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$id = (int) ( $input['id'] ?? 0 );
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
			if ( ( $input['status'] ?? '' ) === 'publish' ) {
				return current_user_can( 'publish_post', $id ) || current_user_can( 'publish_pages' );
			}
			return true;
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$postarr = array( 'ID' => $post->ID );
			$map     = array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name' );
			foreach ( $map as $in => $field ) {
				if ( array_key_exists( $in, $input ) ) {
					$postarr[ $field ] = $input[ $in ];
				}
			}
			if ( array_key_exists( 'content', $input ) ) {
				list( , $layout ) = spiral_mcp_split_layout( $post->post_content );
				$content          = $input['content'];
				if ( null !== $layout ) {
					// Conserva el layout de YOOtheme al final del contenido.
					$pos      = strrpos( $post->post_content, '<!-- {' );
					$content .= substr( $post->post_content, $pos );
				}
				$postarr['post_content'] = $content;
			}
			if ( count( $postarr ) === 1 ) {
				return new WP_Error( 'spiral_mcp_nothing', 'No se envió ningún campo para actualizar.' );
			}
			$result = spiral_mcp_save( $postarr );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return spiral_mcp_summary( get_post( $result ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 5. Leer layout YOOtheme -------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-yootheme-layout', array(
		'label'               => 'Obtener layout de YOOtheme',
		'description'         => 'Devuelve el árbol JSON del constructor de YOOtheme de una página (section > row > column > elementos como headline, text, button, html). Es lo que realmente se muestra en el sitio.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			list( , $layout ) = spiral_mcp_split_layout( $post->post_content );
			if ( null === $layout ) {
				return new WP_Error( 'spiral_mcp_no_layout', 'Esta página no usa el constructor de YOOtheme.' );
			}
			return array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'layout' => $layout,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 6. Actualizar layout YOOtheme -------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-yootheme-layout', array(
		'label'               => 'Actualizar layout de YOOtheme',
		'description'         => 'Reemplaza el árbol JSON completo del constructor de YOOtheme de una página. Primero lee el layout con spiral-mcp/get-yootheme-layout, modifícalo y envíalo entero. Guarda una revisión.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'layout' ),
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'layout' => array( 'type' => 'object', 'description' => 'Árbol completo con "type": "layout" en la raíz.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$layout = $input['layout'] ?? null;
			if ( ! is_array( $layout ) || ( $layout['type'] ?? '' ) !== 'layout' || empty( $layout['children'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto con "type": "layout" y al menos una sección.' );
			}
			$json = spiral_mcp_json( $layout );
			if ( false === $json || false !== strpos( $json, '-->' ) ) {
				return new WP_Error( 'spiral_mcp_bad_layout', 'No se pudo serializar el layout (contiene "-->" o datos inválidos).' );
			}
			list( $html ) = spiral_mcp_split_layout( $post->post_content );
			$result       = spiral_mcp_save( array(
				'ID'           => $post->ID,
				'post_content' => $html . '<!-- ' . $json . ' -->',
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array_merge( spiral_mcp_summary( get_post( $result ) ), array( 'updated' => true ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
