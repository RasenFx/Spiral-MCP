<?php
/**
 * Edición eficiente de layouts de YOOtheme: resumen por rutas, lectura de un
 * nodo, operaciones al estilo JSON Patch (RFC 6902) y duplicado de contenido.
 *
 * Las rutas son JSON Pointer (RFC 6901) sobre el árbol: "/children/0/props/content".
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers: JSON Pointer
 * ---------------------------------------------------------------------- */

/** "/children/0/props" => [ 'children', '0', 'props' ]. "" = raíz. */
function spiral_mcp_pointer_parse( $path ) {
	$path = (string) $path;
	if ( '' === $path ) {
		return array();
	}
	if ( '/' !== $path[0] ) {
		return new WP_Error( 'spiral_mcp_bad_path', sprintf( 'Ruta "%s" no válida: debe empezar por "/", p. ej. /children/0/children/1.', $path ) );
	}
	return array_map( function ( $t ) {
		return str_replace( array( '~1', '~0' ), array( '/', '~' ), $t );
	}, explode( '/', substr( $path, 1 ) ) );
}

/** ¿Es una lista JSON (claves 0..n-1)? */
function spiral_mcp_is_list( $value ) {
	return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
}

/**
 * Valor en una ruta, o WP_Error si no existe. $hint: ability que da las rutas del documento
 * (los helpers de puntero sirven para los layouts de YOOtheme y los árboles de Elementor).
 */
function spiral_mcp_pointer_get( $doc, $path, $hint = 'spiral-mcp/get-layout-outline' ) {
	$tokens = spiral_mcp_pointer_parse( $path );
	if ( is_wp_error( $tokens ) ) {
		return $tokens;
	}
	foreach ( $tokens as $t ) {
		if ( ! is_array( $doc ) || ! array_key_exists( $t, $doc ) ) {
			return new WP_Error( 'spiral_mcp_path_not_found', sprintf( 'La ruta "%s" no existe en el documento. Consulta las rutas con %s.', $path, $hint ) );
		}
		$doc = $doc[ $t ];
	}
	return $doc;
}

/**
 * Aplica una función al contenedor padre de una ruta. $fn( &$parent, $key )
 * devuelve true o WP_Error.
 */
function spiral_mcp_pointer_parent( &$doc, $path, callable $fn, $hint = 'spiral-mcp/get-layout-outline' ) {
	$tokens = spiral_mcp_pointer_parse( $path );
	if ( is_wp_error( $tokens ) ) {
		return $tokens;
	}
	if ( ! $tokens ) {
		return new WP_Error( 'spiral_mcp_bad_path', 'No se puede operar sobre la raíz del documento; usa una ruta como /children/0 (YOOtheme) o /0 (Elementor).' );
	}
	$key = array_pop( $tokens );
	$ref = &$doc;
	foreach ( $tokens as $t ) {
		if ( ! is_array( $ref ) || ! array_key_exists( $t, $ref ) ) {
			return new WP_Error( 'spiral_mcp_path_not_found', sprintf( 'La ruta "%s" no existe en el documento. Consulta las rutas con %s.', $path, $hint ) );
		}
		$ref = &$ref[ $t ];
	}
	if ( ! is_array( $ref ) ) {
		return new WP_Error( 'spiral_mcp_path_not_found', sprintf( 'El padre de "%s" no es un objeto ni una lista.', $path ) );
	}
	return $fn( $ref, $key );
}

function spiral_mcp_pointer_add( &$doc, $path, $value, $hint = 'spiral-mcp/get-layout-outline' ) {
	return spiral_mcp_pointer_parent( $doc, $path, function ( &$parent, $key ) use ( $value, $path ) {
		if ( spiral_mcp_is_list( $parent ) && ( '-' === $key || ctype_digit( (string) $key ) ) ) {
			$index = '-' === $key ? count( $parent ) : (int) $key;
			if ( $index > count( $parent ) ) {
				return new WP_Error( 'spiral_mcp_bad_path', sprintf( 'Índice fuera de rango en "%s" (la lista tiene %d elementos; usa "-" para añadir al final).', $path, count( $parent ) ) );
			}
			array_splice( $parent, $index, 0, array( $value ) );
		} else {
			$parent[ $key ] = $value;
		}
		return true;
	}, $hint );
}

function spiral_mcp_pointer_remove( &$doc, $path, $hint = 'spiral-mcp/get-layout-outline' ) {
	return spiral_mcp_pointer_parent( $doc, $path, function ( &$parent, $key ) use ( $path ) {
		if ( ! array_key_exists( $key, $parent ) ) {
			return new WP_Error( 'spiral_mcp_path_not_found', sprintf( 'La ruta "%s" no existe en el documento.', $path ) );
		}
		if ( spiral_mcp_is_list( $parent ) ) {
			array_splice( $parent, (int) $key, 1 );
		} else {
			unset( $parent[ $key ] );
		}
		return true;
	}, $hint );
}

function spiral_mcp_pointer_replace( &$doc, $path, $value, $hint = 'spiral-mcp/get-layout-outline' ) {
	return spiral_mcp_pointer_parent( $doc, $path, function ( &$parent, $key ) use ( $value, $path ) {
		if ( ! array_key_exists( $key, $parent ) ) {
			return new WP_Error( 'spiral_mcp_path_not_found', sprintf( 'La ruta "%s" no existe; para crearla usa "add".', $path ) );
		}
		$parent[ $key ] = $value;
		return true;
	}, $hint );
}

/** Una operación JSON Patch (add, remove, replace, move, copy, test) sobre el documento. true o WP_Error. */
function spiral_mcp_pointer_apply_op( array &$doc, array $op, $hint = 'spiral-mcp/get-layout-outline' ) {
	$name = (string) ( $op['op'] ?? '' );
	$path = (string) ( $op['path'] ?? '' );
	$from = (string) ( $op['from'] ?? '' );
	switch ( $name ) {
		case 'add':
		case 'replace':
			if ( ! array_key_exists( 'value', $op ) ) {
				return new WP_Error( 'spiral_mcp_bad_patch', '"' . $name . '" necesita "value".' );
			}
			return 'add' === $name ? spiral_mcp_pointer_add( $doc, $path, $op['value'], $hint ) : spiral_mcp_pointer_replace( $doc, $path, $op['value'], $hint );
		case 'remove':
			return spiral_mcp_pointer_remove( $doc, $path, $hint );
		case 'move':
		case 'copy':
			$value = spiral_mcp_pointer_get( $doc, $from, $hint );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			if ( 'move' === $name ) {
				if ( 0 === strpos( $path . '/', $from . '/' ) ) {
					return new WP_Error( 'spiral_mcp_bad_patch', 'No se puede mover un nodo dentro de sí mismo.' );
				}
				$res = spiral_mcp_pointer_remove( $doc, $from, $hint );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return spiral_mcp_pointer_add( $doc, $path, $value, $hint );
		case 'test':
			$value = spiral_mcp_pointer_get( $doc, $path, $hint );
			return is_wp_error( $value ) ? $value : ( $value == ( $op['value'] ?? null ) ? true : new WP_Error( 'spiral_mcp_test_failed', sprintf( 'El valor de "%s" no coincide: el documento cambió desde que lo leíste.', $path ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}
	return new WP_Error( 'spiral_mcp_bad_patch', sprintf( 'Operación "%s" no válida. Usa add, remove, replace, move, copy o test.', $name ) );
}

/**
 * Aplica operaciones JSON Patch a una copia del documento. Si alguna falla,
 * devuelve WP_Error (indicando cuál) y el documento original no cambia.
 */
function spiral_mcp_layout_apply_patch( array $doc, array $ops, $hint = 'spiral-mcp/get-layout-outline' ) {
	foreach ( array_values( $ops ) as $i => $op ) {
		$op  = (array) $op;
		$res = spiral_mcp_pointer_apply_op( $doc, $op, $hint );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), sprintf( 'Operación %d (%s): %s No se ha guardado nada.', $i, (string) ( $op['op'] ?? '' ), $res->get_error_message() ) );
		}
	}
	return $doc;
}

/* -------------------------------------------------------------------------
 * Helpers: resumen del árbol
 * ---------------------------------------------------------------------- */

/** Extracto legible de un nodo: su texto, título o imagen. */
function spiral_mcp_layout_excerpt( array $node ) {
	$props = (array) ( $node['props'] ?? array() );
	foreach ( array( 'content', 'title', 'text', 'meta', 'image', 'video', 'link' ) as $key ) {
		if ( isset( $props[ $key ] ) && is_string( $props[ $key ] ) && '' !== trim( $props[ $key ] ) ) {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $props[ $key ] ) ) );
			if ( '' !== $text ) {
				return ( 'content' === $key ? '' : $key . ': ' ) . ( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 80, '…', 'UTF-8' ) : substr( $text, 0, 80 ) );
			}
		}
	}
	return '';
}

/** Recorre el árbol y devuelve una línea por nodo: ruta, tipo, nombre y extracto. */
function spiral_mcp_layout_outline( array $node, $path = '', $depth = 0, array &$lines = array(), $max = 1000 ) {
	if ( count( $lines ) >= $max ) {
		return $lines;
	}
	$line = str_repeat( '  ', $depth ) . ( '' === $path ? '/' : $path ) . '  ' . ( $node['type'] ?? '?' );
	if ( ! empty( $node['name'] ) ) {
		$line .= ' "' . $node['name'] . '"';
	}
	if ( ! empty( $node['source'] ) ) {
		$line .= ' [fuente dinámica]';
	}
	$excerpt = spiral_mcp_layout_excerpt( $node );
	if ( '' !== $excerpt ) {
		$line .= ' — ' . $excerpt;
	}
	$lines[] = $line;
	foreach ( (array) ( $node['children'] ?? array() ) as $i => $child ) {
		if ( is_array( $child ) ) {
			spiral_mcp_layout_outline( $child, $path . '/children/' . $i, $depth + 1, $lines, $max );
		}
	}
	return $lines;
}

/**
 * Recoge los textos visibles, enlaces e imágenes de un árbol según el catálogo de elementos:
 * campos text/textarea/editor (textos), link (enlaces) e image (imágenes), también los anidados
 * ("provider.message" = props.provider.message). Omite los campos internos (id, class, css,
 * atributos, medidas). Cada entrada lleva la ruta JSON Pointer lista para patch-layout.
 */
function spiral_mcp_layout_texts( array $node, $path, array $types, array $kinds, array &$out, $max = 2000 ) {
	if ( count( $out ) >= $max ) {
		return;
	}
	$type   = (string) ( $node['type'] ?? '' );
	$fields = (array) ( $types[ $type ]['fields'] ?? array() );
	$props  = (array) ( $node['props'] ?? array() );
	$mapped = (array) ( $node['source']['props'] ?? array() );
	foreach ( $fields as $name => $field ) {
		$ftype = (string) ( $field['type'] ?? 'text' );
		$kind  = in_array( $ftype, array( 'text', 'textarea', 'editor' ), true ) ? 'text' : ( 'link' === $ftype ? 'link' : ( 'image' === $ftype ? 'image' : null ) );
		if ( null === $kind || ! in_array( $kind, $kinds, true ) ) {
			continue;
		}
		if ( in_array( $name, array( 'name', 'id', 'class', 'attributes', 'css' ), true ) || preg_match( '/(width|height|delay|offset|ratio|size|_breakpoint|focal_point)$/', $name ) ) {
			continue;
		}
		$value = $props;
		foreach ( explode( '.', (string) $name ) as $key ) {
			$value = is_array( $value ) && array_key_exists( $key, $value ) ? $value[ $key ] : null;
		}
		$dynamic = isset( $mapped[ $name ] );
		if ( ( ! is_string( $value ) || '' === trim( $value ) || ( 'text' === $kind && is_numeric( trim( $value ) ) ) ) && ! $dynamic ) {
			continue;
		}
		$entry = array(
			'path'    => $path . '/props/' . str_replace( '.', '/', (string) $name ),
			'element' => $type,
			'prop'    => (string) $name,
			'kind'    => $kind,
			'value'   => is_string( $value ) ? $value : '',
		);
		if ( 'text' === $kind && is_string( $value ) && preg_match( '#<[a-z/!]#i', $value ) ) {
			$entry['html'] = true;
		}
		if ( $dynamic ) {
			$entry['dynamic'] = true; // Lo rellena una fuente dinámica: cambiarlo aquí no se verá.
		}
		$out[] = $entry;
	}
	foreach ( (array) ( $node['children'] ?? array() ) as $i => $child ) {
		if ( is_array( $child ) ) {
			spiral_mcp_layout_texts( $child, $path . '/children/' . $i, $types, $kinds, $out, $max );
		}
	}
}

/* -------------------------------------------------------------------------
 * Helpers: destino (página o área del tema)
 * ---------------------------------------------------------------------- */

/**
 * Carga el layout de una página ("id") o de un área del tema ("area").
 * Devuelve [ 'target' => array, 'layout' => array ] o WP_Error.
 */
function spiral_mcp_layout_target_load( array $input ) {
	$has_id   = ! empty( $input['id'] );
	$has_area = ! empty( $input['area'] );
	if ( $has_id === $has_area ) {
		return new WP_Error( 'spiral_mcp_bad_target', 'Indica "id" (página o entrada) o "area" (área del tema, p. ej. footer), uno de los dos.' );
	}
	if ( $has_id ) {
		$post = spiral_mcp_get_post( $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		list( , $layout ) = spiral_mcp_split_layout( $post->post_content );
		if ( null === $layout ) {
			return new WP_Error( 'spiral_mcp_no_layout', 'Esta página no usa el constructor de YOOtheme.' );
		}
		return array( 'target' => array( 'id' => (int) $post->ID ), 'layout' => $layout, 'post' => $post );
	}
	$got = spiral_mcp_theme_area_get( $input['area'] );
	if ( is_wp_error( $got ) ) {
		return $got;
	}
	if ( null === $got['layout'] ) {
		return new WP_Error( 'spiral_mcp_no_layout', sprintf( 'El área "%s" todavía no tiene layout; créalo con spiral-mcp/update-theme-layout.', $got['area'] ) );
	}
	return array( 'target' => array( 'area' => $got['area'] ), 'layout' => $got['layout'] );
}

/**
 * Aplica operaciones a un layout (página o área), lo valida y lo guarda.
 * Atómico: si algo falla no se guarda nada.
 */
function spiral_mcp_layout_patch_target( array $input, array $ops, $note ) {
	$loaded = spiral_mcp_layout_target_load( $input );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}
	$layout = spiral_mcp_layout_apply_patch( $loaded['layout'], $ops );
	if ( is_wp_error( $layout ) ) {
		return $layout;
	}
	$warnings = spiral_mcp_layout_check( $layout );
	if ( is_wp_error( $warnings ) ) {
		return $warnings;
	}
	if ( isset( $loaded['post'] ) ) {
		$res = spiral_mcp_layout_save_post( $loaded['post'], $layout );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array( 'id' => (int) $loaded['post']->ID ) + spiral_mcp_revision_info( $loaded['post']->ID );
	} else {
		$area = $loaded['target']['area'];
		$res  = spiral_mcp_theme_area_save( $area, $layout, $note . ':' . $area );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array( 'area' => $area, 'snapshot_id' => $res );
	}
	return $out + array(
		'updated'    => true,
		'operations' => count( $ops ),
		'warnings'   => $warnings,
	);
}

/** Permiso según el destino: edit_post para páginas, edit_theme_options para áreas. */
function spiral_mcp_layout_target_can( $input ) {
	$input = (array) $input;
	if ( ! empty( $input['id'] ) ) {
		return current_user_can( 'edit_post', (int) $input['id'] ) && current_user_can( 'spiral_mcp_content' );
	}
	return current_user_can( 'edit_theme_options' ) && current_user_can( 'spiral_mcp_design' );
}

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-layout', array(
		'label'       => 'Layouts Spiral-MCP',
		'description' => 'Leer y editar por partes los layouts de YOOtheme de páginas y áreas del tema.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$target_props = array(
		'id'   => array( 'type' => 'integer', 'description' => 'ID de la página o entrada.' ),
		'area' => array( 'type' => 'string', 'description' => 'En lugar de "id": área del tema con builder, "footer", "menu_item:<ID>", "widget:<ID>" (ver spiral-mcp/get-theme-layout) o una plantilla, "template:<id>" (ver spiral-mcp/list-templates).' ),
	);

	/* 1. Resumen ---------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-layout-outline', array(
		'requires'            => 'yootheme',
		'label'               => 'Resumen del layout',
		'description'         => 'Devuelve el árbol de un layout de YOOtheme (de una página con "id" o de un área del tema con "area") resumido en una línea por nodo: ruta JSON Pointer (p. ej. /children/0/children/1/children/0/children/2), tipo, nombre y un extracto de su texto. Es el primer paso para editar: localiza aquí la ruta del nodo y después usa spiral-mcp/get-layout-node y spiral-mcp/patch-layout, sin descargar todo el JSON.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => $target_props,
		),
		'permission_callback' => 'spiral_mcp_layout_target_can',
		'execute_callback'    => function ( $input = array() ) {
			$loaded = spiral_mcp_layout_target_load( (array) $input );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$lines = spiral_mcp_layout_outline( $loaded['layout'] );
			return $loaded['target'] + array(
				'nodes'   => count( $lines ),
				'outline' => $lines,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 1b. Textos ---------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-layout-texts', array(
		'requires'            => 'yootheme',
		'label'               => 'Textos del layout',
		'description'         => 'Lista todos los textos visibles de un layout de YOOtheme (de una página con "id" o de un área con "area"), y si se pide también sus enlaces e imágenes, cada uno con su ruta JSON Pointer completa lista para un "replace" de spiral-mcp/patch-layout. Incluye los textos que no salen en spiral-mcp/get-layout-outline: títulos y metas de items, textos de botones, etiquetas y mensajes de formularios, títulos de sección, alt de imágenes y ajustes anidados (p. ej. props/provider/message de un newsletter). Úsala para traducir o adaptar un diseño importado (spiral-mcp/import-from-online-library) sin dejarte nada. "html": true = el valor es HTML: conserva sus etiquetas y enlaces al cambiarlo. "dynamic": true = lo rellena una fuente dinámica, cambiarlo no se verá. "path" limita la búsqueda a un nodo.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => $target_props + array(
				'path'  => array( 'type' => 'string', 'description' => 'Ruta JSON Pointer de un nodo para limitar la búsqueda (por defecto, todo el layout).' ),
				'kinds' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string', 'enum' => array( 'text', 'link', 'image' ) ),
					'default'     => array( 'text' ),
					'description' => 'Qué devolver: text (por defecto), link, image.',
				),
			),
		),
		'permission_callback' => 'spiral_mcp_layout_target_can',
		'execute_callback'    => function ( $input = array() ) {
			$input  = (array) $input;
			$loaded = spiral_mcp_layout_target_load( $input );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$types = spiral_mcp_element_types();
			if ( null === $types ) {
				return new WP_Error( 'spiral_mcp_no_yootheme', 'YOOtheme Pro no está activo en este sitio.' );
			}
			$path = rtrim( (string) ( $input['path'] ?? '' ), '/' );
			$node = '' === $path ? $loaded['layout'] : spiral_mcp_pointer_get( $loaded['layout'], $path );
			if ( is_wp_error( $node ) || ! is_array( $node ) ) {
				return is_wp_error( $node ) ? $node : new WP_Error( 'spiral_mcp_bad_path', 'La ruta no apunta a un nodo del layout (ver spiral-mcp/get-layout-outline).' );
			}
			$kinds = array_values( array_intersect( array( 'text', 'link', 'image' ), (array) ( $input['kinds'] ?? array( 'text' ) ) ) );
			$items = array();
			spiral_mcp_layout_texts( $node, $path, $types, $kinds ? $kinds : array( 'text' ), $items );
			return $loaded['target'] + array(
				'total' => count( $items ),
				'items' => $items,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Nodo ------------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-layout-node', array(
		'requires'            => 'yootheme',
		'label'               => 'Obtener un nodo del layout',
		'description'         => 'Devuelve el JSON completo de un nodo de un layout de YOOtheme (sección, fila, columna o elemento con sus props, fuente dinámica e hijos) por su ruta JSON Pointer, tomada de spiral-mcp/get-layout-outline. "path": "" devuelve el layout entero.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'path' ),
			'properties' => $target_props + array(
				'path' => array( 'type' => 'string', 'description' => 'Ruta JSON Pointer, p. ej. /children/0/children/0.' ),
			),
		),
		'permission_callback' => 'spiral_mcp_layout_target_can',
		'execute_callback'    => function ( $input ) {
			$loaded = spiral_mcp_layout_target_load( (array) $input );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$node = spiral_mcp_pointer_get( $loaded['layout'], $input['path'] ?? '' );
			if ( is_wp_error( $node ) ) {
				return $node;
			}
			return $loaded['target'] + array( 'path' => (string) $input['path'], 'node' => $node );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Patch ------------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/patch-layout', array(
		'requires'            => 'yootheme',
		'label'               => 'Editar un layout por partes',
		'description'         => 'Aplica operaciones JSON Patch (RFC 6902) a un layout de YOOtheme de una página ("id") o de un área del tema ("area"), sin reenviar el árbol completo. Operaciones: {"op":"replace","path":"/children/0/children/0/children/0/children/0/props/content","value":"Nuevo título"}; {"op":"add","path":"/children/1","value":{sección}} (inserta en esa posición de children; "-" al final); {"op":"remove","path":…}; {"op":"move","from":…,"path":…}; {"op":"copy","from":…,"path":…}; {"op":"test","path":…,"value":…} (falla si el valor cambió). Las rutas se evalúan en orden, tras aplicar las operaciones anteriores (al borrar o insertar en una lista, los índices siguientes cambian). Es atómico: si una operación falla, o el resultado tiene errores de estructura o tipos de elemento desconocidos, no se guarda nada. Conserva las fuentes dinámicas ("source") que no toques. Página: crea una revisión. Área del tema: guarda un snapshot ("snapshot_id"). Devuelve "warnings" con props desconocidos o valores fuera de sus opciones.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'log'                 => array(
			'type' => 'layout',
			'id'   => function ( $in ) {
				return ! empty( $in['id'] ) ? 'post:' . $in['id'] : 'area:' . ( $in['area'] ?? '' );
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'operations' ),
			'properties' => $target_props + array(
				'operations' => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'op' ),
						'properties' => array(
							'op'    => array( 'type' => 'string', 'enum' => array( 'add', 'remove', 'replace', 'move', 'copy', 'test' ) ),
							'path'  => array( 'type' => 'string' ),
							'from'  => array( 'type' => 'string' ),
							'value' => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
						),
					),
				),
			),
		),
		'permission_callback' => 'spiral_mcp_layout_target_can',
		'execute_callback'    => function ( $input ) {
			return spiral_mcp_layout_patch_target( (array) $input, (array) ( $input['operations'] ?? array() ), 'patch-layout' );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Duplicar --------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/duplicate-content', array(
		'label'               => 'Duplicar página o entrada',
		'description'         => 'Crea una copia de una página o entrada como borrador: contenido con su layout de YOOtheme o sus datos de Elementor, extracto, página madre, orden, plantilla, imagen destacada, categorías/etiquetas y campos personalizados. Devuelve la copia (su "id"). Útil para crear una página nueva a partir de otra parecida.',
		'category'            => 'spiral-mcp-layout',
		'group'               => 'content',
		'log'                 => array( 'type' => 'post', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'    => array( 'type' => 'integer', 'description' => 'ID de la página o entrada a copiar.' ),
				'title' => array( 'type' => 'string', 'description' => 'Título de la copia. Por defecto, el original + " (copia)".' ),
				'slug'  => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$post = get_post( (int) ( ( (array) $input )['id'] ?? 0 ) );
			$type = $post ? get_post_type_object( $post->post_type ) : null;
			return $post && $type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->create_posts );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$new_id = wp_insert_post( wp_slash( array(
				'post_type'      => $post->post_type,
				'post_title'     => isset( $input['title'] ) ? (string) $input['title'] : $post->post_title . ' (copia)',
				'post_name'      => (string) ( $input['slug'] ?? '' ),
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => 'draft',
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_author'    => get_current_user_id(),
			) ), true );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				$terms = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
			$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_elementor_css' );
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
			return spiral_mcp_summary( get_post( $new_id ) ) + array( 'source_id' => (int) $post->ID );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
