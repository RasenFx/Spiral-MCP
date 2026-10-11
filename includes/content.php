<?php
/**
 * Abilities de contenido: tipos de contenido; listar, leer, crear, editar,
 * duplicar, papelera y borrado; revisiones; y layout de YOOtheme de páginas,
 * entradas y demás tipos públicos.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Tipos de contenido editables: los públicos con interfaz en wp-admin (salvo
 * adjuntos). Se puede restringir con el filtro spiral_mcp_post_types.
 */
function spiral_mcp_post_types() {
	$types = array();
	foreach ( get_post_types( array( 'public' => true, 'show_ui' => true ) ) as $type ) {
		if ( 'attachment' !== $type ) {
			$types[] = $type;
		}
	}
	return array_values( (array) apply_filters( 'spiral_mcp_post_types', $types ) );
}

/** Estados permitidos al crear/actualizar. */
function spiral_mcp_statuses() {
	return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

/** Obtiene un post válido o WP_Error. */
function spiral_mcp_get_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || ! in_array( $post->post_type, spiral_mcp_post_types(), true ) ) {
		return new WP_Error( 'spiral_mcp_not_found', sprintf( 'No existe contenido editable con ese ID (tipos: %s). Búscalo con spiral-mcp/list-content.', implode( ', ', spiral_mcp_post_types() ) ) );
	}
	return $post;
}

/** Metas que el agente puede leer y escribir. Filtro spiral_mcp_allowed_meta (vacío por defecto). */
function spiral_mcp_allowed_meta( $post_type ) {
	return array_values( (array) apply_filters( 'spiral_mcp_allowed_meta', array(), $post_type ) );
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

/** Reemplaza el layout de YOOtheme de un post (crea revisión). Devuelve el ID o WP_Error. */
function spiral_mcp_layout_save_post( WP_Post $post, $layout ) {
	if ( ! is_array( $layout ) || ( $layout['type'] ?? '' ) !== 'layout' || empty( $layout['children'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto con "type": "layout" y al menos una sección.' );
	}
	$json = spiral_mcp_json( spiral_mcp_layout_stamp_version( $layout ) );
	if ( false === $json || false !== strpos( $json, '-->' ) ) {
		return new WP_Error( 'spiral_mcp_bad_layout', 'No se pudo serializar el layout (contiene "-->" o datos inválidos).' );
	}
	list( $html ) = spiral_mcp_split_layout( $post->post_content );
	return spiral_mcp_save( array(
		'ID'           => $post->ID,
		'post_content' => $html . '<!-- ' . $json . ' -->',
	) );
}

/** Ficha completa de un post: resumen + jerarquía, plantilla, autor, fecha, términos, imagen y metas. */
function spiral_mcp_content_details( WP_Post $post ) {
	$terms = array();
	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
		if ( ! $tax->show_ui ) {
			continue;
		}
		$list = wp_get_object_terms( $post->ID, $tax->name );
		if ( is_wp_error( $list ) ) {
			continue;
		}
		$terms[ $tax->name ] = array_map( function ( $t ) {
			return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
		}, $list );
	}
	$meta = array();
	foreach ( spiral_mcp_allowed_meta( $post->post_type ) as $key ) {
		$meta[ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
	}
	$thumb  = (int) get_post_thumbnail_id( $post );
	$author = get_userdata( (int) $post->post_author );
	return spiral_mcp_summary( $post ) + array(
		'parent'         => (int) $post->post_parent,
		'menu_order'     => (int) $post->menu_order,
		'template'       => get_page_template_slug( $post ) ? get_page_template_slug( $post ) : 'default',
		'author'         => array( 'id' => (int) $post->post_author, 'name' => $author ? $author->display_name : '' ),
		'date'           => mysql_to_rfc3339( $post->post_date ),
		'date_gmt'       => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : spiral_mcp_utc_date( $post->post_date_gmt ),
		'comment_status' => $post->comment_status,
		'terms'          => (object) $terms,
		'featured_media' => $thumb ? array( 'id' => $thumb, 'path_relative' => spiral_mcp_media_relative( (string) wp_get_attachment_url( $thumb ) ) ) : null,
		'meta'           => (object) $meta,
	);
}

/**
 * Valida y convierte la entrada de create/update-content. Devuelve
 * [ campos de wp_insert_post, extras (términos, imagen, plantilla, metas) ]
 * o WP_Error. No escribe nada.
 */
function spiral_mcp_content_prepare( array $in, $type, $post = null ) {
	$postarr = array();
	$extras  = array();
	$map     = array(
		'title'          => 'post_title',
		'excerpt'        => 'post_excerpt',
		'status'         => 'post_status',
		'slug'           => 'post_name',
		'menu_order'     => 'menu_order',
		'comment_status' => 'comment_status',
	);
	foreach ( $map as $key => $field ) {
		if ( array_key_exists( $key, $in ) ) {
			$postarr[ $field ] = $in[ $key ];
		}
	}

	if ( array_key_exists( 'parent', $in ) ) {
		$parent = (int) $in['parent'];
		if ( $parent ) {
			if ( ! is_post_type_hierarchical( $type ) ) {
				return new WP_Error( 'spiral_mcp_bad_parent', sprintf( 'El tipo "%s" no admite contenido padre (solo los jerárquicos, como page).', $type ) );
			}
			$p = get_post( $parent );
			if ( ! $p || $p->post_type !== $type ) {
				return new WP_Error( 'spiral_mcp_bad_parent', sprintf( 'No existe un "%s" con ID %d para usar como padre.', $type, $parent ) );
			}
			if ( $post && ( $parent === (int) $post->ID || in_array( (int) $post->ID, array_map( 'intval', get_post_ancestors( $parent ) ), true ) ) ) {
				return new WP_Error( 'spiral_mcp_bad_parent', 'Un contenido no puede ser hijo de sí mismo ni de sus descendientes.' );
			}
		}
		$postarr['post_parent'] = $parent;
	}

	if ( array_key_exists( 'template', $in ) ) {
		$tpl       = (string) $in['template'];
		$templates = wp_get_theme()->get_page_templates( null, $type );
		if ( '' !== $tpl && 'default' !== $tpl && ! isset( $templates[ $tpl ] ) ) {
			return new WP_Error( 'spiral_mcp_bad_template', sprintf( 'La plantilla "%s" no existe para "%s". Disponibles: default%s (ver spiral-mcp/list-post-types).', $tpl, $type, $templates ? ', ' . implode( ', ', array_keys( $templates ) ) : '' ) );
		}
		$extras['template'] = '' === $tpl ? 'default' : $tpl;
	}

	if ( array_key_exists( 'author', $in ) ) {
		$user = get_userdata( (int) $in['author'] );
		$obj  = get_post_type_object( $type );
		if ( ! $user || ! user_can( $user, $obj->cap->edit_posts ) ) {
			return new WP_Error( 'spiral_mcp_bad_author', 'El autor debe ser un usuario que pueda editar este tipo de contenido.' );
		}
		$postarr['post_author'] = (int) $user->ID;
	}

	if ( array_key_exists( 'date', $in ) ) {
		$ts = strtotime( (string) $in['date'] );
		if ( false === $ts ) {
			return new WP_Error( 'spiral_mcp_bad_date', '"date" no es una fecha válida. Usa ISO 8601 con zona, p. ej. 2026-11-01T09:00:00+01:00.' );
		}
		$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
		$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
		$postarr['edit_date']     = true;
		// Publicar con fecha futura = programar.
		if ( ( $postarr['post_status'] ?? ( $post ? $post->post_status : '' ) ) === 'future' && $ts <= time() ) {
			return new WP_Error( 'spiral_mcp_bad_date', 'Para programar ("status": "future") la fecha debe ser futura.' );
		}
	}
	if ( ( $postarr['post_status'] ?? '' ) === 'future' && ! array_key_exists( 'date', $in ) && ( ! $post || strtotime( $post->post_date_gmt . ' UTC' ) <= time() ) ) {
		return new WP_Error( 'spiral_mcp_bad_date', 'Para programar envía "date" con una fecha futura (y "status": "publish" o "future").' );
	}

	if ( array_key_exists( 'terms', $in ) ) {
		if ( ! is_array( $in['terms'] ) ) {
			return new WP_Error( 'spiral_mcp_bad_terms', '"terms" debe ser un objeto {taxonomía: [IDs o slugs]}.' );
		}
		foreach ( $in['terms'] as $tax => $values ) {
			$taxonomy = get_taxonomy( $tax );
			if ( ! $taxonomy || ! is_object_in_taxonomy( $type, $tax ) ) {
				return new WP_Error( 'spiral_mcp_bad_terms', sprintf( 'La taxonomía "%s" no existe para "%s". Disponibles: %s.', $tax, $type, implode( ', ', get_object_taxonomies( $type ) ) ) );
			}
			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				return new WP_Error( 'spiral_mcp_forbidden', sprintf( 'No tienes permiso para asignar términos de "%s".', $tax ) );
			}
			$ids = array();
			foreach ( (array) $values as $value ) {
				$term = is_numeric( $value ) ? get_term( (int) $value, $tax ) : get_term_by( 'slug', sanitize_title( $value ), $tax );
				if ( ! $term && ! is_numeric( $value ) ) {
					$term = get_term_by( 'name', (string) $value, $tax );
				}
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'spiral_mcp_bad_terms', sprintf( 'El término "%s" no existe en "%s". Créalo antes con spiral-mcp/create-term.', $value, $tax ) );
				}
				$ids[] = (int) $term->term_id;
			}
			$extras['terms'][ $tax ] = $ids;
		}
	}

	if ( array_key_exists( 'featured_media', $in ) ) {
		$media = (int) $in['featured_media'];
		if ( $media ) {
			if ( ! post_type_supports( $type, 'thumbnail' ) ) {
				return new WP_Error( 'spiral_mcp_no_thumbnail', sprintf( 'El tipo "%s" no admite imagen destacada.', $type ) );
			}
			if ( ! wp_attachment_is_image( $media ) ) {
				return new WP_Error( 'spiral_mcp_not_image', 'La imagen destacada debe ser un adjunto de imagen (ver spiral-mcp/list-media).' );
			}
		}
		$extras['featured'] = $media;
	}

	if ( array_key_exists( 'meta', $in ) ) {
		$allowed = spiral_mcp_allowed_meta( $type );
		foreach ( (array) $in['meta'] as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return new WP_Error( 'spiral_mcp_meta_locked', sprintf( 'El meta "%s" no está permitido. Permitidos: %s (filtro spiral_mcp_allowed_meta).', $key, $allowed ? implode( ', ', $allowed ) : 'ninguno' ) );
			}
			$extras['meta'][ $key ] = $value;
		}
	}
	return array( $postarr, $extras );
}

/** Aplica los extras preparados (términos, imagen destacada, plantilla, metas). */
function spiral_mcp_content_apply_extras( $id, array $extras ) {
	foreach ( (array) ( $extras['terms'] ?? array() ) as $tax => $ids ) {
		$res = wp_set_object_terms( $id, $ids, $tax );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}
	if ( array_key_exists( 'featured', $extras ) ) {
		if ( $extras['featured'] ) {
			set_post_thumbnail( $id, $extras['featured'] );
		} else {
			delete_post_thumbnail( $id );
		}
	}
	if ( isset( $extras['template'] ) ) {
		update_post_meta( $id, '_wp_page_template', $extras['template'] );
	}
	foreach ( (array) ( $extras['meta'] ?? array() ) as $key => $value ) {
		if ( null === $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}
	return true;
}

/** Permisos extra de create/update: publicar o programar, y escribir como otro autor. */
function spiral_mcp_content_can_write( array $in, $type, $post_id = 0 ) {
	$obj = get_post_type_object( $type );
	if ( ! $obj ) {
		return false;
	}
	if ( in_array( $in['status'] ?? '', array( 'publish', 'future' ), true ) && ! current_user_can( $obj->cap->publish_posts ) ) {
		return false;
	}
	if ( isset( $in['author'] ) && (int) $in['author'] !== get_current_user_id() && ! current_user_can( $obj->cap->edit_others_posts ) ) {
		return false;
	}
	return true;
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

	// Campos ampliados de create-content y update-content.
	$fields = array(
		'title'          => array( 'type' => 'string' ),
		'content'        => array( 'type' => 'string', 'description' => 'HTML o bloques de Gutenberg. En contenido de YOOtheme solo es el HTML de respaldo: el diseño se edita con las abilities de layout.' ),
		'excerpt'        => array( 'type' => 'string' ),
		'status'         => array( 'type' => 'string', 'enum' => spiral_mcp_statuses(), 'description' => 'draft (borrador), pending, private, publish, o future/publish con "date" futura para programar.' ),
		'slug'           => array( 'type' => 'string' ),
		'parent'         => array( 'type' => 'integer', 'description' => 'ID del contenido padre (solo tipos jerárquicos, como page; 0 = sin padre).' ),
		'menu_order'     => array( 'type' => 'integer' ),
		'template'       => array( 'type' => 'string', 'description' => 'Plantilla de página del tema ("default" = la predeterminada; ver spiral-mcp/list-post-types).' ),
		'author'         => array( 'type' => 'integer', 'description' => 'ID del usuario autor.' ),
		'date'           => array( 'type' => 'string', 'description' => 'Fecha de publicación en ISO 8601 con zona (p. ej. 2026-11-01T09:00:00+01:00). Futura + status publish = programar.' ),
		'comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
		'terms'          => array( 'type' => 'object', 'description' => '{taxonomía: [IDs o slugs]}, p. ej. {"category": ["noticias"]}. Reemplaza los términos de cada taxonomía enviada. Los términos deben existir (spiral-mcp/create-term).' ),
		'featured_media' => array( 'type' => 'integer', 'description' => 'ID de una imagen de la biblioteca (0 = quitar).' ),
		'meta'           => array( 'type' => 'object', 'description' => 'Campos personalizados permitidos por el filtro spiral_mcp_allowed_meta (null borra).' ),
	);

	/* 0. Tipos de contenido ---------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-post-types', array(
		'label'               => 'Listar tipos de contenido',
		'description'         => 'Lista los tipos de contenido que se pueden editar (page, post y los personalizados públicos): nombre, etiqueta, si es jerárquico (admite "parent"), qué admite (editor, thumbnail, excerpt, comments, page-attributes, revisions), sus taxonomías y las plantillas de página del tema disponibles para "template".',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function () {
			$out = array();
			foreach ( spiral_mcp_post_types() as $type ) {
				$obj = get_post_type_object( $type );
				if ( ! $obj ) {
					continue;
				}
				$supports = array_keys( array_filter( (array) get_all_post_type_supports( $type ) ) );
				$out[]    = array(
					'name'         => $type,
					'label'        => $obj->labels->singular_name,
					'hierarchical' => (bool) $obj->hierarchical,
					'supports'     => $supports,
					'taxonomies'   => array_values( get_object_taxonomies( $type ) ),
					'templates'    => (object) wp_get_theme()->get_page_templates( null, $type ),
					'can_create'   => current_user_can( $obj->cap->create_posts ),
					'can_publish'  => current_user_can( $obj->cap->publish_posts ),
				);
			}
			return array( 'post_types' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 1. Listar contenido ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-content', array(
		'label'               => 'Listar contenido',
		'description'         => 'Lista páginas, entradas u otro tipo de contenido (ver spiral-mcp/list-post-types) con filtros: estado (incluidos future = programado y trash = papelera), búsqueda y contenido padre. Devuelve ID, tipo, título, estado, slug, URL, fecha de modificación y si usa el constructor de YOOtheme.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'enum' => spiral_mcp_post_types(), 'default' => 'page' ),
				'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'future', 'draft', 'pending', 'private', 'trash' ), 'default' => 'any' ),
				'search'    => array( 'type' => 'string', 'description' => 'Texto a buscar en título/contenido.' ),
				'parent'    => array( 'type' => 'integer', 'description' => 'Solo hijos de este ID (0 = solo los de primer nivel).' ),
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
			$args  = array(
				'post_type'      => $input['post_type'] ?? 'page',
				'post_status'    => $input['status'] ?? 'any',
				's'              => $input['search'] ?? '',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			);
			if ( isset( $input['parent'] ) ) {
				$args['post_parent'] = (int) $input['parent'];
			}
			$q = new WP_Query( $args );
			return array(
				'total' => (int) $q->found_posts,
				'items' => array_map( 'spiral_mcp_summary', $q->posts ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Leer contenido -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-content', array(
		'label'               => 'Obtener contenido',
		'description'         => 'Devuelve la ficha completa de una página, entrada u otro contenido: título, estado, extracto, contenido en bruto, padre, orden, plantilla, autor, fecha (date local y date_gmt), comentarios, términos por taxonomía, imagen destacada (id y path_relative) y metas permitidos. Si usa YOOtheme, "content" es solo el HTML de respaldo; para el diseño usa spiral-mcp/get-layout-outline o spiral-mcp/get-yootheme-layout.',
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
			return array_merge( spiral_mcp_content_details( $post ), array(
				'excerpt'      => $post->post_excerpt,
				'content'      => $html,
				'has_yootheme' => null !== $layout,
			) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Crear contenido ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/create-content', array(
		'label'               => 'Crear contenido',
		'description'         => 'Crea una página, entrada u otro contenido. Por defecto queda como borrador. Admite padre (páginas hijas), orden, plantilla de página, autor, fecha (futura + "status": "publish" = programar), comentarios, términos ({"category": ["noticias"]}), imagen destacada y metas permitidos. Todo se valida antes de crear nada. Para el diseño con YOOtheme, después usa spiral-mcp/update-yootheme-layout o crea la página duplicando otra con spiral-mcp/duplicate-content.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'enum' => spiral_mcp_post_types(), 'default' => 'page' ),
			) + $fields,
		),
		'permission_callback' => function ( $input = array() ) {
			$input = (array) $input;
			$type  = get_post_type_object( $input['post_type'] ?? 'page' );
			return $type && current_user_can( $type->cap->create_posts ) && spiral_mcp_content_can_write( $input, $type->name );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$type  = $input['post_type'] ?? 'page';
			$prep  = spiral_mcp_content_prepare( $input, $type );
			if ( is_wp_error( $prep ) ) {
				return $prep;
			}
			list( $postarr, $extras ) = $prep;
			$id                       = wp_insert_post( wp_slash( $postarr + array(
				'post_type'    => $type,
				'post_content' => $input['content'] ?? '',
				'post_status'  => 'draft',
			) ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$res = spiral_mcp_content_apply_extras( $id, $extras );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_content_details( get_post( $id ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Actualizar contenido -------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-content', array(
		'label'               => 'Actualizar contenido',
		'description'         => 'Actualiza los campos enviados de una página, entrada u otro contenido (los mismos que spiral-mcp/create-content). Título, contenido y extracto guardan una revisión (spiral-mcp/list-revisions); términos, imagen destacada, plantilla y metas no tienen revisiones. En contenido de YOOtheme, "content" reemplaza solo el HTML de respaldo y se conserva el layout. Para mandar a la papelera usa spiral-mcp/trash-content.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ) + $fields,
		),
		'permission_callback' => function ( $input = array() ) {
			$input = (array) $input;
			$post  = get_post( (int) ( $input['id'] ?? 0 ) );
			return $post && current_user_can( 'edit_post', $post->ID ) && spiral_mcp_content_can_write( $input, $post->post_type, $post->ID );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$post  = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$prep = spiral_mcp_content_prepare( $input, $post->post_type, $post );
			if ( is_wp_error( $prep ) ) {
				return $prep;
			}
			list( $postarr, $extras ) = $prep;
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
			if ( ! $postarr && ! $extras ) {
				return new WP_Error( 'spiral_mcp_nothing', 'No se envió ningún campo para actualizar.' );
			}
			if ( $postarr ) {
				$result = spiral_mcp_save( array( 'ID' => $post->ID ) + $postarr );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$res = spiral_mcp_content_apply_extras( $post->ID, $extras );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			clean_post_cache( $post->ID );
			return spiral_mcp_content_details( get_post( $post->ID ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4b. Papelera, restaurar y borrar ----------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/trash-content', array(
		'label'               => 'Mandar a la papelera',
		'description'         => 'Mueve un contenido a la papelera (deja de verse en el sitio). Se puede recuperar con spiral-mcp/restore-content; WordPress vacía la papelera a los 30 días.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'delete_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( 'trash' === $post->post_status ) {
				return new WP_Error( 'spiral_mcp_already_trashed', 'Ya está en la papelera.' );
			}
			if ( ! wp_trash_post( $post->ID ) ) {
				return new WP_Error( 'spiral_mcp_trash_failed', 'No se pudo mandar a la papelera.' );
			}
			return spiral_mcp_summary( get_post( $post->ID ) );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/restore-content', array(
		'label'               => 'Sacar de la papelera',
		'description'         => 'Recupera un contenido de la papelera con el estado que tenía antes (publicado, borrador…).',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'delete_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( 'trash' !== $post->post_status ) {
				return new WP_Error( 'spiral_mcp_not_trashed', 'No está en la papelera.' );
			}
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
			$res = wp_untrash_post( $post->ID );
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
			if ( ! $res ) {
				return new WP_Error( 'spiral_mcp_untrash_failed', 'No se pudo sacar de la papelera.' );
			}
			return spiral_mcp_summary( get_post( $post->ID ) );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-content', array(
		'label'               => 'Borrar definitivamente',
		'description'         => 'Borra DEFINITIVAMENTE un contenido que ya está en la papelera, con sus revisiones y metas. No se puede deshacer. Primero mándalo a la papelera con spiral-mcp/trash-content. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'delete_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar definitivamente' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( 'trash' !== $post->post_status ) {
				return new WP_Error( 'spiral_mcp_not_trashed', 'Solo se borra definitivamente lo que está en la papelera. Usa antes spiral-mcp/trash-content.' );
			}
			$before = spiral_mcp_summary( $post );
			if ( ! wp_delete_post( $post->ID, true ) ) {
				return new WP_Error( 'spiral_mcp_delete_failed', 'No se pudo borrar.' );
			}
			return array( 'deleted' => true, 'content' => $before );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* 4c. Revisiones ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-revisions', array(
		'label'               => 'Listar revisiones',
		'description'         => 'Lista las revisiones de un contenido, de la más reciente a la más antigua: id, fecha (UTC), autor, título, si es un autoguardado, si tiene layout de YOOtheme y qué campos difieren del estado actual (title, content, excerpt). Restaura una con spiral-mcp/restore-revision.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$out = array();
			foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => (int) ( $input['per_page'] ?? 20 ) ) ) as $rev ) {
				$author  = get_userdata( (int) $rev->post_author );
				$changed = array();
				foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $key => $field ) {
					if ( $rev->$field !== $post->$field ) {
						$changed[] = $key;
					}
				}
				$out[] = array(
					'id'           => (int) $rev->ID,
					'date'         => spiral_mcp_utc_date( $rev->post_date_gmt ),
					'author'       => $author ? $author->display_name : '',
					'title'        => $rev->post_title,
					'autosave'     => wp_is_post_autosave( $rev ) ? true : false,
					'has_yootheme' => spiral_mcp_has_layout( $rev->post_content ),
					'differs_in'   => $changed,
				);
			}
			return array( 'id' => (int) $post->ID, 'revisions' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/restore-revision', array(
		'label'               => 'Restaurar una revisión',
		'description'         => 'Devuelve el título, contenido (incluido el layout de YOOtheme) y extracto de un contenido a una revisión anterior. Antes guarda el estado actual como revisión ("current_revision_id"), así que se puede deshacer. No cambia términos, imagen destacada, plantilla ni metas. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-content',
		'log'                 => array(
			'type' => 'post',
			'id'   => function ( $in, $res ) {
				return $res['id'] ?? ( $in['revision_id'] ?? '' );
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'revision_id', 'confirm' ),
			'properties' => array(
				'revision_id' => array( 'type' => 'integer', 'description' => 'ID de la revisión (ver spiral-mcp/list-revisions).' ),
				'confirm'     => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$rev_id = (int) ( ( (array) $input )['revision_id'] ?? 0 );
			$rev    = wp_get_post_revision( $rev_id );
			return $rev ? current_user_can( 'edit_post', $rev->post_parent ) : current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'restaurar la revisión' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$rev_id = (int) ( $input['revision_id'] ?? 0 );
			$rev    = wp_get_post_revision( $rev_id );
			if ( ! $rev ) {
				return new WP_Error( 'spiral_mcp_revision_not_found', 'No existe esa revisión (ver spiral-mcp/list-revisions).' );
			}
			$post = spiral_mcp_get_post( $rev->post_parent );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			// El estado actual queda como revisión (si ya lo era, se usa la última).
			$current = wp_save_post_revision( $post->ID );
			if ( ! $current ) {
				$latest  = wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 1 ) );
				$current = $latest ? (int) key( $latest ) : 0;
			}
			$res = wp_restore_post_revision( $rev->ID );
			if ( ! $res || is_wp_error( $res ) ) {
				return is_wp_error( $res ) ? $res : new WP_Error( 'spiral_mcp_restore_failed', 'No se pudo restaurar la revisión.' );
			}
			clean_post_cache( $post->ID );
			return spiral_mcp_summary( get_post( $post->ID ) ) + array(
				'restored_revision_id' => (int) $rev->ID,
				'current_revision_id'  => (int) $current,
			);
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
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
		'description'         => 'Reemplaza el árbol JSON completo del constructor de YOOtheme de una página. Primero lee el layout con spiral-mcp/get-yootheme-layout, modifícalo y envíalo entero; para cambios pequeños usa spiral-mcp/patch-layout. Valida el árbol con el catálogo de elementos (spiral-mcp/list-elements): los tipos desconocidos y las estructuras imposibles bloquean el guardado; los props desconocidos o fuera de sus opciones vuelven como "warnings". Si falta "version" en la raíz se añade la de YOOtheme. Guarda una revisión.',
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
			$layout   = $input['layout'] ?? null;
			$warnings = is_array( $layout ) ? spiral_mcp_layout_check( $layout ) : array();
			if ( is_wp_error( $warnings ) ) {
				return $warnings;
			}
			$result = spiral_mcp_layout_save_post( $post, $layout );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array_merge( spiral_mcp_summary( get_post( $post->ID ) ), array( 'updated' => true, 'warnings' => $warnings ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
