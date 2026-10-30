<?php
/**
 * Secure Custom Fields (Fase 15): valores de los campos personalizados de contenidos,
 * términos y páginas de opciones, y envoltorio de las abilities scf/* que registra el
 * propio plugin (grupos de campos, campos, tipos de contenido, taxonomías y páginas de
 * opciones). Solo si SCF está activo.
 *
 * Dos partes:
 * 1. Valores (abilities propias, grupo "content"): list-custom-fields (qué campos aplican y
 *    cómo escribirlos), get-custom-fields y update-custom-fields; además "fields" en
 *    get-content, create-content y update-content (filtro spiral_mcp_ability_args). Se
 *    escriben con update_field() de SCF (validación de SCF, kses sin unfiltered_html) tras
 *    un snapshot (kind "scf_values").
 * 2. Estructura (abilities scf/* de SCF, grupo "settings"): filtro del core
 *    wp_register_ability_args, como con Elementor: capacidad spiral_mcp_settings, permiso
 *    nativo (SCF exige manage_options, que el agente nunca tiene: Spiral acepta también
 *    edit_theme_options, como con CPTUI), auditoría, snapshot antes de cada escritura
 *    (kind "scf_entity", restaurable con el importador de SCF), "confirm" en las
 *    destructivas, kses en los textos de campos y catálogo de Ajustes > Spiral-MCP.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** ¿Secure Custom Fields (o ACF) está activo con la API que se usa aquí? */
function spiral_mcp_scf_active() {
	return function_exists( 'acf_get_field_groups' ) && function_exists( 'update_field' ) && function_exists( 'acf_get_fields' );
}

/** Nombre del plugin para las respuestas. */
function spiral_mcp_scf_plugin() {
	return array(
		'plugin'  => defined( 'ACF_PATH' ) && false !== stripos( (string) ACF_PATH, 'secure-custom-fields' ) ? 'secure-custom-fields' : 'advanced-custom-fields',
		'version' => defined( 'ACF_VERSION' ) ? ACF_VERSION : '',
	);
}

/** ¿El usuario puede escribir HTML sin filtrar en los valores? Misma regla que los layouts. */
function spiral_mcp_scf_unfiltered() {
	return current_user_can( 'unfiltered_html' ) || current_user_can( 'spiral_mcp_code' );
}

/**
 * Resuelve el objetivo de una petición de valores: "id" (contenido), "term_id" (término) u
 * "options_page" (slug de menú o clave de una página de opciones de SCF). Devuelve
 * [ post_id de SCF, args de ubicación para acf_get_field_groups(), etiqueta ] o WP_Error.
 */
function spiral_mcp_scf_target( array $input, $write = false ) {
	if ( ! empty( $input['id'] ) ) {
		$post = spiral_mcp_get_post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( $write && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'spiral_mcp_forbidden', 'No puedes editar ese contenido.', array( 'status' => 403 ) );
		}
		return array( $post->ID, array( 'post_id' => $post->ID, 'post_type' => $post->post_type ), sprintf( 'contenido %d (%s)', $post->ID, $post->post_type ) );
	}
	if ( ! empty( $input['term_id'] ) ) {
		$term = spiral_mcp_term_get( (int) $input['term_id'] );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		if ( $write && ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new WP_Error( 'spiral_mcp_forbidden', 'No puedes editar ese término.', array( 'status' => 403 ) );
		}
		return array( 'term_' . $term->term_id, array( 'taxonomy' => $term->taxonomy ), sprintf( 'término %d (%s)', $term->term_id, $term->taxonomy ) );
	}
	if ( ! empty( $input['options_page'] ) ) {
		$page = spiral_mcp_scf_options_page( (string) $input['options_page'] );
		if ( ! $page ) {
			$names = array_map( function ( $p ) {
				return $p['menu_slug'];
			}, spiral_mcp_scf_options_pages() );
			return new WP_Error( 'spiral_mcp_scf_not_found', sprintf( 'No existe la página de opciones "%s". Disponibles: %s.', $input['options_page'], $names ? implode( ', ', $names ) : '(ninguna; se crean con scf/create-ui-options-page)' ) );
		}
		if ( $write && ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'spiral_mcp_forbidden', 'No puedes editar las páginas de opciones.', array( 'status' => 403 ) );
		}
		return array( $page['post_id'], array( 'options_page' => $page['menu_slug'] ), sprintf( 'página de opciones "%s"', $page['menu_slug'] ) );
	}
	return new WP_Error( 'spiral_mcp_bad_input', 'Indica "id" (contenido), "term_id" (término) u "options_page" (slug de una página de opciones de SCF).' );
}

/** Páginas de opciones registradas (con la UI de SCF o por código). */
function spiral_mcp_scf_options_pages() {
	$pages = function_exists( 'acf_get_options_pages' ) ? acf_get_options_pages() : array();
	return is_array( $pages ) ? array_values( $pages ) : array();
}

/** Página de opciones por slug de menú, clave o título. */
function spiral_mcp_scf_options_page( $ref ) {
	$ref = trim( (string) $ref );
	foreach ( spiral_mcp_scf_options_pages() as $page ) {
		if ( in_array( $ref, array( (string) ( $page['menu_slug'] ?? '' ), (string) ( $page['key'] ?? '' ), (string) ( $page['page_title'] ?? '' ) ), true ) ) {
			$page['post_id'] = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
			return $page;
		}
	}
	return null;
}

/** Grupos de campos que aplican a una ubicación (activos y con sus campos). */
function spiral_mcp_scf_groups( array $location ) {
	$groups = acf_get_field_groups( $location );
	$out    = array();
	foreach ( (array) $groups as $group ) {
		$group['fields'] = acf_get_fields( $group );
		$out[]           = $group;
	}
	return $out;
}

/** Campos de primer nivel de varios grupos, por nombre (los de maquetación —tab, accordion, message— no llevan valor). */
function spiral_mcp_scf_fields_by_name( array $groups ) {
	$by_name = array();
	foreach ( $groups as $group ) {
		foreach ( (array) $group['fields'] as $field ) {
			if ( '' === (string) ( $field['name'] ?? '' ) || in_array( $field['type'], array( 'tab', 'accordion', 'message' ), true ) ) {
				continue;
			}
			$by_name[ $field['name'] ] = $field;
		}
	}
	return $by_name;
}

/** Cómo se escribe el valor de cada tipo de campo (texto para el agente). */
function spiral_mcp_scf_value_hint( array $field ) {
	$type = (string) ( $field['type'] ?? '' );
	switch ( $type ) {
		case 'text':
		case 'textarea':
		case 'email':
		case 'url':
		case 'password':
			return 'texto';
		case 'wysiwyg':
			return 'HTML (se filtra con wp_kses_post sin unfiltered_html)';
		case 'number':
		case 'range':
			return 'número';
		case 'true_false':
			return 'true o false';
		case 'select':
		case 'radio':
		case 'button_group':
		case 'checkbox':
			$multi = 'checkbox' === $type || ! empty( $field['multiple'] );
			return ( $multi ? 'lista de valores' : 'un valor' ) . ' de "choices" (la clave, no la etiqueta)';
		case 'image':
		case 'file':
			return 'ID de un medio de la biblioteca (spiral-mcp/list-media o upload-media)';
		case 'gallery':
			return 'lista de IDs de imágenes';
		case 'post_object':
		case 'relationship':
			return ( ! empty( $field['multiple'] ) || 'relationship' === $type ? 'lista de IDs' : 'ID' ) . ' de contenidos' . ( ! empty( $field['post_type'] ) ? ' de tipo ' . implode( '/', (array) $field['post_type'] ) : '' );
		case 'page_link':
			return ( ! empty( $field['multiple'] ) ? 'lista de IDs' : 'ID' ) . ' de contenidos (se guarda como enlace)';
		case 'taxonomy':
			return ( in_array( $field['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true ) ? 'lista de IDs' : 'ID' ) . ' de términos de "' . ( $field['taxonomy'] ?? '' ) . '"';
		case 'user':
			return ( ! empty( $field['multiple'] ) ? 'lista de IDs' : 'ID' ) . ' de usuarios';
		case 'link':
			return 'objeto {"url": "/ruta/", "title": "Texto", "target": ""}';
		case 'date_picker':
			return 'fecha "Ymd" (p. ej. "20261101")';
		case 'date_time_picker':
			return 'fecha y hora "Y-m-d H:i:s"';
		case 'time_picker':
			return 'hora "H:i:s"';
		case 'color_picker':
			return 'color "#rrggbb"' . ( ! empty( $field['enable_opacity'] ) ? ' o rgba(...)' : '' );
		case 'google_map':
			return 'objeto {"address": "…", "lat": 0, "lng": 0}';
		case 'oembed':
			return 'URL del vídeo o recurso';
		case 'icon_picker':
			return 'objeto {"type": "dashicons", "value": "dashicons-star-filled"} o {"type": "media_library", "value": ID}';
		case 'nav_menu':
			return 'ID del menú (spiral-mcp/list-menus)';
		case 'repeater':
			return 'lista de filas; cada fila es un objeto {subcampo: valor} con los "sub_fields"';
		case 'group':
			return 'objeto {subcampo: valor} con los "sub_fields"';
		case 'flexible_content':
			return 'lista de bloques {"acf_fc_layout": "<nombre del layout>", subcampo: valor…} con los "layouts"';
		case 'clone':
			return 'según los campos clonados';
	}
	return 'según el tipo';
}

/** Esquema compacto de un campo para el agente (recursivo en repeater, group y flexible_content). */
function spiral_mcp_scf_field_schema( array $field ) {
	$out = array(
		'name'     => (string) ( $field['name'] ?? '' ),
		'key'      => (string) ( $field['key'] ?? '' ),
		'label'    => (string) ( $field['label'] ?? '' ),
		'type'     => (string) ( $field['type'] ?? '' ),
		'required' => ! empty( $field['required'] ),
		'value'    => spiral_mcp_scf_value_hint( $field ),
	);
	if ( ! empty( $field['instructions'] ) ) {
		$out['instructions'] = (string) $field['instructions'];
	}
	foreach ( array( 'choices', 'multiple', 'allow_null', 'default_value', 'min', 'max', 'step', 'maxlength', 'return_format', 'display_format', 'post_type', 'taxonomy', 'field_type', 'mime_types', 'min_size', 'max_size', 'layout', 'placeholder' ) as $key ) {
		if ( isset( $field[ $key ] ) && '' !== $field[ $key ] && array() !== $field[ $key ] ) {
			$out[ $key ] = $field[ $key ];
		}
	}
	if ( ! empty( $field['conditional_logic'] ) ) {
		$out['conditional_logic'] = $field['conditional_logic'];
	}
	if ( ! empty( $field['sub_fields'] ) ) {
		$out['sub_fields'] = array_map( 'spiral_mcp_scf_field_schema', array_values( (array) $field['sub_fields'] ) );
	}
	if ( ! empty( $field['layouts'] ) ) {
		$layouts = array();
		foreach ( (array) $field['layouts'] as $layout ) {
			$layouts[] = array(
				'name'       => (string) ( $layout['name'] ?? '' ),
				'label'      => (string) ( $layout['label'] ?? '' ),
				'sub_fields' => array_map( 'spiral_mcp_scf_field_schema', array_values( (array) ( $layout['sub_fields'] ?? array() ) ) ),
			);
		}
		$out['layouts'] = $layouts;
	}
	return $out;
}

/** Resumen de un grupo de campos. */
function spiral_mcp_scf_group_summary( array $group, $with_fields = true ) {
	$rules = array();
	foreach ( (array) ( $group['location'] ?? array() ) as $and ) {
		$parts = array();
		foreach ( (array) $and as $rule ) {
			$parts[] = sprintf( '%s %s %s', $rule['param'] ?? '', $rule['operator'] ?? '', $rule['value'] ?? '' );
		}
		$rules[] = implode( ' y ', $parts );
	}
	$out = array(
		'key'      => (string) ( $group['key'] ?? '' ),
		'id'       => (int) ( $group['ID'] ?? 0 ),
		'title'    => (string) ( $group['title'] ?? '' ),
		'active'   => ! empty( $group['active'] ),
		'location' => $rules,
		'local'    => ! empty( $group['local'] ) ? (string) $group['local'] : null,
	);
	if ( $with_fields ) {
		$fields = isset( $group['fields'] ) ? (array) $group['fields'] : acf_get_fields( $group );
		$out['fields'] = array_map( 'spiral_mcp_scf_field_schema', array_values( $fields ) );
	} else {
		$out['field_count'] = count( isset( $group['fields'] ) ? (array) $group['fields'] : acf_get_fields( $group ) );
	}
	return $out;
}

/** Valores crudos (tal como se escriben) de los campos que aplican: nombre => valor. */
function spiral_mcp_scf_values( $post_id, array $by_name ) {
	$values = array();
	foreach ( $by_name as $name => $field ) {
		$values[ $name ] = spiral_mcp_scf_has_value( $post_id, $field ) ? spiral_mcp_scf_raw_by_name( get_field( $field['key'], $post_id, false ), $field ) : null;
	}
	return $values;
}

/** ¿El campo tiene un valor guardado? Sin él, get_field() devuelve el valor por defecto ("" en texto), no false. */
function spiral_mcp_scf_has_value( $post_id, array $field ) {
	return '' !== (string) acf_get_reference( (string) $field['name'], $post_id );
}

/**
 * Valor crudo para el agente: sin valor = null (get_field devuelve false, que en true_false
 * sí es un valor) y las filas de repeater, group y flexible_content por nombre de subcampo
 * (SCF las devuelve por clave).
 */
function spiral_mcp_scf_raw_by_name( $value, array $field ) {
	$type = (string) ( $field['type'] ?? '' );
	if ( false === $value && 'true_false' !== $type ) {
		return null;
	}
	if ( 'true_false' === $type ) {
		return (bool) $value;
	}
	$rows_by_name = function ( array $row, array $sub_fields ) {
		$out = array();
		foreach ( $sub_fields as $sub ) {
			$name = (string) ( $sub['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( array_key_exists( $sub['key'], $row ) ) {
				$out[ $name ] = spiral_mcp_scf_raw_by_name( $row[ $sub['key'] ], $sub );
			} elseif ( array_key_exists( $name, $row ) ) {
				$out[ $name ] = spiral_mcp_scf_raw_by_name( $row[ $name ], $sub );
			}
		}
		return $out;
	};
	if ( 'repeater' === $type ) {
		$rows = array();
		foreach ( (array) $value as $row ) {
			$rows[] = $rows_by_name( (array) $row, (array) ( $field['sub_fields'] ?? array() ) );
		}
		return $rows;
	}
	if ( 'group' === $type ) {
		return $rows_by_name( (array) $value, (array) ( $field['sub_fields'] ?? array() ) );
	}
	if ( 'flexible_content' === $type ) {
		$rows = array();
		foreach ( (array) $value as $row ) {
			$row    = (array) $row;
			$layout = (string) ( $row['acf_fc_layout'] ?? '' );
			$subs   = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $l ) {
				if ( ( $l['name'] ?? '' ) === $layout ) {
					$subs = (array) ( $l['sub_fields'] ?? array() );
				}
			}
			$rows[] = array( 'acf_fc_layout' => $layout ) + $rows_by_name( $row, $subs );
		}
		return $rows;
	}
	return $value;
}

/** Medio en formato compacto. */
function spiral_mcp_scf_media_out( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return null;
	}
	$url = (string) wp_get_attachment_url( $id );
	return array(
		'id'            => $id,
		'path_relative' => function_exists( 'spiral_mcp_media_relative' ) ? spiral_mcp_media_relative( $url ) : $url,
		'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'title'         => get_the_title( $id ),
		'mime'          => (string) get_post_mime_type( $id ),
	);
}

/** Contenido en formato compacto. */
function spiral_mcp_scf_post_out( $id ) {
	$post = is_object( $id ) ? $id : get_post( (int) $id );
	if ( ! $post instanceof WP_Post ) {
		return is_scalar( $id ) ? $id : null;
	}
	return array( 'id' => $post->ID, 'type' => $post->post_type, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'url' => wp_make_link_relative( (string) get_permalink( $post ) ) );
}

/**
 * Valor crudo "resuelto": los IDs de medios, contenidos, términos y usuarios se expanden a
 * fichas compactas; repeater, group y flexible_content se recorren con sus subcampos.
 */
function spiral_mcp_scf_resolve( $value, array $field ) {
	$type  = (string) ( $field['type'] ?? '' );
	$multi = is_array( $value );
	switch ( $type ) {
		case 'image':
		case 'file':
			return spiral_mcp_scf_media_out( is_array( $value ) ? ( $value['ID'] ?? $value['id'] ?? 0 ) : $value );
		case 'gallery':
			return array_values( array_filter( array_map( 'spiral_mcp_scf_media_out', (array) $value ) ) );
		case 'post_object':
		case 'relationship':
		case 'page_link':
			if ( 'page_link' === $type && ! $multi && ! is_numeric( $value ) ) {
				return $value;
			}
			return $multi ? array_values( array_map( 'spiral_mcp_scf_post_out', $value ) ) : spiral_mcp_scf_post_out( $value );
		case 'taxonomy':
			$out = array();
			foreach ( (array) $value as $id ) {
				$term = is_object( $id ) ? $id : get_term( (int) $id );
				if ( $term && ! is_wp_error( $term ) ) {
					$out[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'taxonomy' => $term->taxonomy );
				}
			}
			return $multi ? $out : ( $out[0] ?? null );
		case 'user':
			$out = array();
			foreach ( (array) $value as $id ) {
				$user = get_userdata( (int) ( is_array( $id ) ? ( $id['ID'] ?? 0 ) : $id ) );
				if ( $user ) {
					$out[] = array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'login' => $user->user_login );
				}
			}
			return $multi ? $out : ( $out[0] ?? null );
		case 'nav_menu':
			$menu = $value ? wp_get_nav_menu_object( (int) $value ) : null;
			return $menu ? array( 'id' => (int) $menu->term_id, 'name' => $menu->name ) : $value;
		case 'repeater':
			$rows = array();
			foreach ( (array) $value as $row ) {
				$rows[] = spiral_mcp_scf_resolve_row( (array) $row, (array) ( $field['sub_fields'] ?? array() ) );
			}
			return $rows;
		case 'group':
			return spiral_mcp_scf_resolve_row( (array) $value, (array) ( $field['sub_fields'] ?? array() ) );
		case 'flexible_content':
			$rows = array();
			foreach ( (array) $value as $row ) {
				$row    = (array) $row;
				$layout = (string) ( $row['acf_fc_layout'] ?? '' );
				$subs   = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $l ) {
					if ( ( $l['name'] ?? '' ) === $layout ) {
						$subs = (array) ( $l['sub_fields'] ?? array() );
					}
				}
				$rows[] = array( 'acf_fc_layout' => $layout ) + spiral_mcp_scf_resolve_row( $row, $subs );
			}
			return $rows;
	}
	return $value;
}

/** Resuelve una fila (repeater, group o layout) por sus subcampos, aceptando claves o nombres. */
function spiral_mcp_scf_resolve_row( array $row, array $sub_fields ) {
	$out = array();
	foreach ( $sub_fields as $sub ) {
		$name = (string) ( $sub['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$raw          = array_key_exists( $name, $row ) ? $row[ $name ] : ( $row[ $sub['key'] ] ?? null );
		$out[ $name ] = null === $raw ? null : spiral_mcp_scf_resolve( $raw, $sub );
	}
	return $out;
}

/** Normaliza un valor entrante según el tipo (booleanos, números, objetos de enlace y mapa). */
function spiral_mcp_scf_normalize( $value, array $field, &$warnings, $path ) {
	$type = (string) ( $field['type'] ?? '' );
	if ( null === $value ) {
		return null;
	}
	switch ( $type ) {
		case 'true_false':
			return ( is_string( $value ) ? in_array( strtolower( $value ), array( 'true', '1', 'yes', 'on' ), true ) : (bool) $value ) ? 1 : 0;
		case 'image':
		case 'file':
		case 'post_object':
		case 'page_link':
		case 'user':
		case 'nav_menu':
			if ( is_array( $value ) && ! empty( $field['multiple'] ) ) {
				return array_values( array_map( 'intval', $value ) );
			}
			if ( is_array( $value ) ) {
				return (int) ( $value['id'] ?? $value['ID'] ?? reset( $value ) );
			}
			if ( '' === $value ) {
				return null;
			}
			if ( ! is_numeric( $value ) && 'page_link' !== $type ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s": envía el ID numérico (%s), no "%s".', $path, spiral_mcp_scf_value_hint( $field ), is_scalar( $value ) ? $value : gettype( $value ) ) );
			}
			return is_numeric( $value ) ? (int) $value : $value;
		case 'gallery':
		case 'relationship':
		case 'taxonomy':
			if ( 'taxonomy' === $type && ! in_array( $field['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true ) ) {
				return '' === $value ? null : ( is_array( $value ) ? (int) reset( $value ) : (int) $value );
			}
			$list = array_values( (array) $value );
			foreach ( $list as $i => $v ) {
				if ( ! is_numeric( $v ) ) {
					return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s": envía una lista de IDs numéricos (%s).', $path, spiral_mcp_scf_value_hint( $field ) ) );
				}
				$list[ $i ] = (int) $v;
			}
			return $list;
		case 'checkbox':
			return array_values( array_map( 'strval', (array) $value ) );
		case 'select':
		case 'radio':
		case 'button_group':
			if ( ! empty( $field['multiple'] ) ) {
				return array_values( array_map( 'strval', (array) $value ) );
			}
			if ( is_array( $value ) ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" admite un solo valor.', $path ) );
			}
			$choices = (array) ( $field['choices'] ?? array() );
			if ( $choices && ! isset( $choices[ (string) $value ] ) && empty( $field['allow_custom'] ) ) {
				$warnings[] = sprintf( '"%s": "%s" no está entre sus opciones (%s); SCF lo guarda igual pero no se mostrará seleccionado.', $path, $value, implode( ', ', array_keys( $choices ) ) );
			}
			return (string) $value;
		case 'number':
		case 'range':
			if ( '' === $value ) {
				return null;
			}
			if ( ! is_numeric( $value ) ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser un número.', $path ) );
			}
			return $value + 0;
		case 'link':
			if ( is_string( $value ) ) {
				return array( 'url' => $value, 'title' => '', 'target' => '' );
			}
			if ( ! is_array( $value ) || empty( $value['url'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser {"url": "/ruta/", "title": "Texto", "target": ""} (o solo la URL).', $path ) );
			}
			return array( 'url' => (string) $value['url'], 'title' => (string) ( $value['title'] ?? '' ), 'target' => (string) ( $value['target'] ?? '' ) );
		case 'date_picker':
			if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
				return substr( str_replace( '-', '', $value ), 0, 8 );
			}
			return (string) $value;
		case 'repeater':
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser una lista de filas (objetos).', $path ) );
			}
			$rows = array();
			foreach ( array_values( $value ) as $i => $row ) {
				$r = spiral_mcp_scf_normalize_row( $row, (array) ( $field['sub_fields'] ?? array() ), $warnings, $path . '[' . $i . ']' );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$rows[] = $r;
			}
			return $rows;
		case 'group':
			return spiral_mcp_scf_normalize_row( $value, (array) ( $field['sub_fields'] ?? array() ), $warnings, $path );
		case 'flexible_content':
			if ( ! is_array( $value ) ) {
				return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser una lista de bloques con "acf_fc_layout".', $path ) );
			}
			$rows    = array();
			$layouts = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $l ) {
				$layouts[ (string) ( $l['name'] ?? '' ) ] = (array) ( $l['sub_fields'] ?? array() );
			}
			foreach ( array_values( $value ) as $i => $row ) {
				$row    = (array) $row;
				$layout = (string) ( $row['acf_fc_layout'] ?? '' );
				if ( ! isset( $layouts[ $layout ] ) ) {
					return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s[%d]": "acf_fc_layout" debe ser uno de: %s.', $path, $i, implode( ', ', array_keys( $layouts ) ) ) );
				}
				unset( $row['acf_fc_layout'] );
				$r = spiral_mcp_scf_normalize_row( $row, $layouts[ $layout ], $warnings, $path . '[' . $i . ']' );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				$rows[] = array( 'acf_fc_layout' => $layout ) + $r;
			}
			return $rows;
	}
	if ( is_string( $value ) && ! spiral_mcp_scf_unfiltered() ) {
		$clean = wp_kses_post( $value );
		if ( $clean !== $value ) {
			$warnings[] = sprintf( '"%s": se ha filtrado HTML no permitido (sin unfiltered_html).', $path );
		}
		return $clean;
	}
	return $value;
}

/** Normaliza una fila por sus subcampos (acepta nombres o claves; rechaza subcampos desconocidos). */
function spiral_mcp_scf_normalize_row( $row, array $sub_fields, &$warnings, $path ) {
	if ( ! is_array( $row ) ) {
		return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser un objeto {subcampo: valor}.', $path ) );
	}
	$by  = array();
	foreach ( $sub_fields as $sub ) {
		if ( '' !== (string) ( $sub['name'] ?? '' ) ) {
			$by[ $sub['name'] ] = $sub;
			$by[ $sub['key'] ]  = $sub;
		}
	}
	$out = array();
	foreach ( $row as $k => $v ) {
		if ( ! isset( $by[ $k ] ) ) {
			return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s": el subcampo "%s" no existe. Subcampos: %s.', $path, $k, implode( ', ', array_unique( array_map( function ( $s ) {
				return $s['name'];
			}, array_filter( $sub_fields, function ( $s ) {
				return '' !== (string) ( $s['name'] ?? '' );
			} ) ) ) ) ) );
		}
		$n = spiral_mcp_scf_normalize( $v, $by[ $k ], $warnings, $path . '.' . $k );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		$out[ $by[ $k ]['key'] ] = $n;
	}
	return $out;
}

/**
 * Valida y normaliza {nombre: valor} contra los campos que aplican. Devuelve
 * [ clave de campo => valor normalizado ] o WP_Error. No escribe.
 */
function spiral_mcp_scf_prepare( array $values, array $by_name, &$warnings ) {
	if ( ! $values ) {
		return new WP_Error( 'spiral_mcp_nothing', 'Envía en "fields" al menos un campo: {nombre: valor}. null borra el valor.' );
	}
	$by_key = array();
	foreach ( $by_name as $field ) {
		$by_key[ $field['key'] ] = $field;
	}
	$out = array();
	foreach ( $values as $name => $value ) {
		$field = $by_name[ $name ] ?? ( $by_key[ $name ] ?? null );
		if ( ! $field ) {
			return new WP_Error( 'spiral_mcp_scf_unknown_field', sprintf( 'El campo "%s" no aplica a este objeto. Campos disponibles: %s (ver spiral-mcp/list-custom-fields).', $name, $by_name ? implode( ', ', array_keys( $by_name ) ) : '(ninguno: no hay grupos de campos para esta ubicación)' ) );
		}
		$n = spiral_mcp_scf_normalize( $value, $field, $warnings, (string) $name );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		if ( null !== $n ) {
			acf_reset_validation_errors();
			if ( ! acf_validate_value( $n, $field, (string) $name ) ) {
				$errors = (array) acf_get_validation_errors();
				$msg    = $errors ? implode( '; ', array_map( function ( $e ) {
					return (string) ( $e['message'] ?? '' );
				}, $errors ) ) : 'valor no válido';
				acf_reset_validation_errors();
				return new WP_Error( 'spiral_mcp_scf_invalid_value', sprintf( '"%s": %s', $name, $msg ) );
			}
		} elseif ( ! empty( $field['required'] ) ) {
			$warnings[] = sprintf( '"%s" es obligatorio en el editor y se ha dejado vacío.', $name );
		}
		$out[ $field['key'] ] = $n;
	}
	return $out;
}

/**
 * Escribe [ clave => valor ] con update_field(); null borra con delete_field() (update_field con
 * null pasa antes por los filtros update_value, que en texto y número lo convierten en "").
 */
function spiral_mcp_scf_write( $post_id, array $prepared ) {
	foreach ( $prepared as $key => $value ) {
		if ( null === $value ) {
			delete_field( $key, $post_id );
		} else {
			update_field( $key, $value, $post_id );
		}
	}
	acf_flush_value_cache( $post_id );
}

/** Salida de valores: grupos que aplican, valores crudos y resueltos. */
function spiral_mcp_scf_values_out( $post_id, array $location ) {
	$groups  = spiral_mcp_scf_groups( $location );
	$by_name = spiral_mcp_scf_fields_by_name( $groups );
	$values  = spiral_mcp_scf_values( $post_id, $by_name );
	$solved  = array();
	foreach ( $values as $name => $value ) {
		$solved[ $name ] = null === $value || false === $value ? $value : spiral_mcp_scf_resolve( $value, $by_name[ $name ] );
	}
	return array(
		'field_groups' => array_map( function ( $g ) {
			return spiral_mcp_scf_group_summary( $g, false );
		}, $groups ),
		'fields'       => (object) $values,
		'resolved'     => (object) $solved,
	);
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Valores crudos de todos los campos que aplican a un objetivo. ref = "post:12", "term:5" u "options:<post_id>|<slug>". */
function spiral_mcp_scf_values_capture( $ref ) {
	$target = spiral_mcp_scf_ref_target( $ref );
	if ( ! $target ) {
		return null;
	}
	list( $post_id, $location ) = $target;
	$by_name = spiral_mcp_scf_fields_by_name( spiral_mcp_scf_groups( $location ) );
	$values  = array();
	foreach ( $by_name as $name => $field ) {
		$values[ $field['key'] ] = spiral_mcp_scf_has_value( $post_id, $field ) ? get_field( $field['key'], $post_id, false ) : null;
	}
	return array( 'post_id' => $post_id, 'values' => $values );
}

/** Restaura los valores guardados (los que ahora existen y no estaban, se borran). */
function spiral_mcp_scf_values_restore( $ref, $data ) {
	$target = spiral_mcp_scf_ref_target( $ref );
	if ( ! $target ) {
		return new WP_Error( 'spiral_mcp_not_found', 'El objeto del snapshot ya no existe.' );
	}
	list( $post_id, $location ) = $target;
	$by_name = spiral_mcp_scf_fields_by_name( spiral_mcp_scf_groups( $location ) );
	$saved   = (array) ( $data['values'] ?? array() );
	$n       = 0;
	$values = array();
	foreach ( $by_name as $field ) {
		$key            = $field['key'];
		$values[ $key ] = array_key_exists( $key, $saved ) && null !== $saved[ $key ] && false !== $saved[ $key ] ? $saved[ $key ] : null;
		++$n;
	}
	spiral_mcp_scf_write( $post_id, $values );
	return array( 'post_id' => $post_id, 'fields' => $n );
}

/** Referencia de snapshot a partir del objetivo. */
function spiral_mcp_scf_ref( $post_id, array $location ) {
	if ( isset( $location['post_id'] ) ) {
		return 'post:' . (int) $post_id;
	}
	if ( isset( $location['taxonomy'] ) ) {
		return 'term:' . (int) substr( (string) $post_id, 5 );
	}
	return 'options:' . $post_id . '|' . ( $location['options_page'] ?? '' );
}

/** Objetivo a partir de una referencia de snapshot. */
function spiral_mcp_scf_ref_target( $ref ) {
	list( $kind, $rest ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	if ( 'post' === $kind ) {
		$post = get_post( (int) $rest );
		return $post ? array( $post->ID, array( 'post_id' => $post->ID, 'post_type' => $post->post_type ) ) : null;
	}
	if ( 'term' === $kind ) {
		$term = get_term( (int) $rest );
		return $term && ! is_wp_error( $term ) ? array( 'term_' . $term->term_id, array( 'taxonomy' => $term->taxonomy ) ) : null;
	}
	if ( 'options' === $kind ) {
		list( $post_id, $slug ) = array_pad( explode( '|', $rest, 2 ), 2, '' );
		return array( $post_id, array( 'options_page' => $slug ) );
	}
	return null;
}

/** Tipo interno de SCF de una ability scf/*: acf-field-group, acf-field, acf-post-type, acf-taxonomy o acf-ui-options-page. */
function spiral_mcp_scf_entity_type( $ability ) {
	$short = preg_replace( '/^scf\/(list|get|create|update|delete|duplicate|export|import|trash|untrash)-/', '', (string) $ability );
	switch ( $short ) {
		case 'field-group':
		case 'field-groups':
			return 'acf-field-group';
		case 'field':
		case 'fields':
			return 'acf-field';
		case 'post-type':
		case 'post-types':
			return 'acf-post-type';
		case 'taxonomy':
		case 'taxonomies':
			return 'acf-taxonomy';
		case 'ui-options-page':
		case 'ui-options-pages':
			return 'acf-ui-options-page';
	}
	return '';
}

/** Entidad de SCF por ID o clave: array con "key" o null. */
function spiral_mcp_scf_entity_get( $type, $identifier ) {
	if ( '' === (string) $identifier ) {
		return null;
	}
	if ( 'acf-field' === $type ) {
		$field = acf_get_field( is_numeric( $identifier ) ? (int) $identifier : (string) $identifier );
		return $field ? $field : null;
	}
	$post = acf_get_internal_post_type( is_numeric( $identifier ) ? (int) $identifier : (string) $identifier, $type );
	return $post ? $post : null;
}

/** Estado exportable de una entidad de SCF. ref = "<tipo>:<clave>". null = no existe. */
function spiral_mcp_scf_entity_capture( $ref ) {
	list( $type, $key ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	$entity             = spiral_mcp_scf_entity_get( $type, $key );
	if ( ! $entity ) {
		return null;
	}
	if ( 'acf-field' === $type ) {
		$field = $entity;
		if ( ! empty( $field['parent'] ) && is_numeric( $field['parent'] ) ) {
			$parent = get_post( (int) $field['parent'] );
			if ( $parent ) {
				$field['parent'] = $parent->post_name; // clave del grupo o del campo padre
			}
		}
		unset( $field['ID'], $field['_valid'], $field['_prepare'] );
		return array( 'type' => $type, 'data' => $field );
	}
	if ( 'acf-field-group' === $type ) {
		$entity['fields'] = acf_get_fields( $entity );
		$data             = acf_prepare_field_group_for_export( $entity );
	} else {
		$data = acf_prepare_internal_post_type_for_export( $entity, $type );
	}
	return array( 'type' => $type, 'data' => $data );
}

/** Restaura una entidad de SCF con su importador (por clave); null = borrarla. */
function spiral_mcp_scf_entity_restore( $ref, $data ) {
	list( $type, $key ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	$current            = spiral_mcp_scf_entity_get( $type, $key );
	if ( null === $data ) {
		if ( $current ) {
			if ( 'acf-field' === $type ) {
				acf_delete_field( $current['ID'] );
			} else {
				acf_delete_internal_post_type( $current['ID'], $type );
			}
		}
		return array( 'key' => $key, 'exists' => false );
	}
	$payload = (array) ( $data['data'] ?? array() );
	// Los importadores de SCF no buscan por clave: sin ID crearían un duplicado.
	if ( $current && ! empty( $current['ID'] ) ) {
		$payload['ID'] = (int) $current['ID'];
	}
	if ( 'acf-field' === $type ) {
		if ( ! empty( $payload['parent'] ) && ! is_numeric( $payload['parent'] ) ) {
			$parent = acf_get_field_post( $payload['parent'] );
			if ( ! $parent ) {
				$group = acf_get_field_group( $payload['parent'] );
				$parent = $group ? get_post( (int) $group['ID'] ) : null;
			}
			if ( ! $parent ) {
				return new WP_Error( 'spiral_mcp_not_found', sprintf( 'El grupo o campo padre "%s" ya no existe.', $payload['parent'] ) );
			}
			$payload['parent'] = $parent->ID;
		}
		$field = acf_update_field( $payload );
		return array( 'key' => $key, 'exists' => true, 'id' => (int) ( $field['ID'] ?? 0 ) );
	}
	if ( 'acf-field-group' === $type ) {
		$group = acf_import_field_group( $payload );
	} else {
		$group = acf_import_internal_post_type( $payload, $type );
	}
	return array( 'key' => $key, 'exists' => true, 'id' => (int) ( $group['ID'] ?? 0 ) );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_scf_active() ) {
		return $kinds;
	}
	$kinds['scf_values'] = array(
		'group'   => 'content',
		'cap'     => 'edit_posts',
		'capture' => 'spiral_mcp_scf_values_capture',
		'restore' => 'spiral_mcp_scf_values_restore',
	);
	$kinds['scf_entity'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => 'spiral_mcp_scf_entity_capture',
		'restore' => 'spiral_mcp_scf_entity_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Envoltorio de las abilities scf/* (estructura)
 * ---------------------------------------------------------------------- */

/** Claves de las abilities scf/* que son callables o clases PHP: solo con spiral_mcp_code. */
function spiral_mcp_scf_code_keys() {
	return array( 'register_meta_box_cb', 'meta_box_cb', 'meta_box_sanitize_cb', 'rest_controller_class' );
}

/** ¿El permiso nativo de SCF (manage_options por defecto) o, en su lugar, edit_theme_options? */
function spiral_mcp_scf_can_manage() {
	$cap = function_exists( 'acf_get_setting' ) ? (string) acf_get_setting( 'capability' ) : 'manage_options';
	return current_user_can( $cap ) || current_user_can( 'edit_theme_options' );
}

/** Kses sobre los textos de un campo o grupo (instrucciones, mensajes, valores por defecto, etiquetas…) sin unfiltered_html. */
function spiral_mcp_scf_kses_deep( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = spiral_mcp_scf_kses_deep( $v );
		}
		return $value;
	}
	return is_string( $value ) ? wp_kses_post( $value ) : $value;
}

add_filter( 'wp_register_ability_args', function ( $args, $name ) {
	if ( ! is_array( $args ) || 0 !== strpos( (string) $name, 'scf/' ) || ! function_exists( 'spiral_mcp_ability_catalog' ) ) {
		return $args;
	}
	$type        = spiral_mcp_scf_entity_type( $name );
	$action      = preg_replace( '/^scf\/([a-z]+)-.*$/', '$1', (string) $name );
	$annotations = (array) ( $args['meta']['annotations'] ?? array() );
	$read        = ! empty( $annotations['readonly'] ) || in_array( $action, array( 'list', 'get', 'export' ), true );
	$destructive = ! empty( $annotations['destructive'] ) || 'delete' === $action;
	$groups      = array( 'settings' );
	$enabled     = ! in_array( $name, spiral_mcp_disabled_abilities(), true );
	$log         = array(
		'type' => str_replace( 'acf-', 'scf_', $type ? $type : 'scf' ),
		'id'   => function ( $input, $result ) {
			$input = (array) $input;
			foreach ( array( 'identifier', 'ID', 'key' ) as $k ) {
				if ( ! empty( $input[ $k ] ) ) {
					return (string) $input[ $k ];
				}
			}
			return is_array( $result ) ? (string) ( $result['key'] ?? ( $result['ID'] ?? '' ) ) : '';
		},
	);

	$description = (string) ( $args['description'] ?? '' );
	if ( $destructive ) {
		$description .= ' Exige "confirm": true (Spiral). Antes se guarda un snapshot restaurable con spiral-mcp/restore-snapshot.';
		if ( is_array( $args['input_schema'] ?? null ) ) {
			$args['input_schema']['properties']['confirm'] = array( 'type' => 'boolean', 'description' => 'Debe ser true.' );
		}
	} elseif ( ! $read ) {
		$description .= ' Spiral guarda un snapshot antes (snapshot_id en la auditoría).';
	}
	if ( 'acf-field-group' === $type && in_array( $action, array( 'create', 'update' ), true ) ) {
		$description .= ' "fields" se guarda con el importador de SCF: en update reemplaza la lista de campos del grupo (los que falten se borran); sin "fields" los campos no se tocan. Cada campo: key (field_…), label, name, type y sus ajustes (sub_fields en repeater y group, layouts en flexible_content); scf/list-fields con filter.parent los muestra.';
	}
	if ( 'acf-field-group' === $type && in_array( $action, array( 'create', 'import' ), true ) ) {
		$description .= ' Para escribir valores en los contenidos después, spiral-mcp/update-custom-fields o "fields" en create-content / update-content; spiral-mcp/list-custom-fields muestra cómo.';
	}
	$args['description'] = $description;
	// SCF valida la salida contra un esquema cerrado: hay que declarar snapshot_id, y las de borrar
	// (que devuelven true) pasan a devolver {deleted, snapshot_id}.
	if ( ! $read && is_array( $args['output_schema'] ?? null ) ) {
		if ( 'boolean' === ( $args['output_schema']['type'] ?? '' ) ) {
			$args['output_schema'] = array(
				'type'       => 'object',
				'properties' => array(
					'deleted'     => array( 'type' => 'boolean' ),
					'snapshot_id' => array( 'type' => array( 'integer', 'null' ) ),
				),
			);
		} elseif ( 'object' === ( $args['output_schema']['type'] ?? '' ) ) {
			$args['output_schema']['properties']['snapshot_id'] = array( 'type' => array( 'integer', 'null' ), 'description' => 'Snapshot de Spiral anterior al cambio (spiral-mcp/restore-snapshot).' );
			// Los campos guardados se devuelven exportados (con sus subcampos): el esquema cerrado de SCF no los admite.
			if ( 'acf-field-group' === $type && isset( $args['output_schema']['properties']['fields'] ) ) {
				$args['output_schema']['properties']['fields'] = array( 'type' => 'array', 'description' => 'Campos del grupo tal como quedaron guardados.' );
			}
		}
	}

	spiral_mcp_ability_catalog( $name, array(
		'label'       => (string) ( $args['label'] ?? $name ),
		'description' => $description,
		'category'    => (string) ( $args['category'] ?? 'scf' ),
		'groups'      => $groups,
		'kind'        => $read ? 'read' : ( $destructive ? 'destructive' : 'write' ),
		'enabled'     => $enabled,
		'available'   => true,
		'requires'    => '',
		'external'    => true,
	) );

	$args['permission_callback'] = function ( $input = null ) use ( $groups, $enabled ) {
		return $enabled && spiral_mcp_user_can_any( $groups ) && spiral_mcp_scf_can_manage();
	};
	if ( ! $enabled ) {
		$args['meta']['show_in_rest']  = false;
		$args['meta']['mcp']['public'] = false;
	}

	if ( isset( $args['execute_callback'] ) ) {
		$execute                  = $args['execute_callback'];
		$args['execute_callback'] = function ( $input = null ) use ( $name, $execute, $log, $groups, $read, $destructive, $type, $action ) {
			if ( $read ) {
				$result = call_user_func( $execute, $input );
				return is_wp_error( $result ) ? spiral_mcp_error_status( $result ) : $result;
			}
			$run = function ( $input ) use ( $execute, $destructive, $type, $action ) {
				$input = (array) $input;
				if ( $destructive ) {
					$confirm = spiral_mcp_require_confirm( $input, 'borrar' );
					if ( is_wp_error( $confirm ) ) {
						return $confirm;
					}
					unset( $input['confirm'] );
				}
				// Callables y clases PHP solo con el permiso de código.
				if ( ! current_user_can( 'spiral_mcp_code' ) ) {
					foreach ( spiral_mcp_scf_code_keys() as $key ) {
						$v = $input[ $key ] ?? '';
						if ( '' !== (string) $v && ! in_array( strtolower( (string) $v ), array( 'false', '0' ), true ) ) {
							return new WP_Error( 'spiral_mcp_code_capability', sprintf( '"%s" es una función o clase PHP: solo se puede indicar con la capacidad spiral_mcp_code. Déjalo vacío.', $key ), array( 'status' => 403 ) );
						}
					}
				}
				// HTML en instrucciones, mensajes, etiquetas y valores por defecto: como cualquier texto del agente.
				if ( in_array( $type, array( 'acf-field', 'acf-field-group' ), true ) && ! spiral_mcp_scf_unfiltered() ) {
					$input = spiral_mcp_scf_kses_deep( $input );
				}
				// Snapshot de la entidad (por clave) antes de tocarla.
				$ref = '';
				foreach ( array( 'identifier', 'ID', 'key' ) as $k ) {
					if ( ! empty( $input[ $k ] ) ) {
						$entity = spiral_mcp_scf_entity_get( $type, $input[ $k ] );
						$ref    = $entity ? (string) $entity['key'] : ( 'key' === $k ? (string) $input[ $k ] : '' );
						if ( '' !== $ref ) {
							break;
						}
					}
				}
				if ( '' !== $ref && $type ) {
					$snapshot = spiral_mcp_snapshot_capture( 'scf_entity', $type . ':' . $ref, $action );
					if ( is_wp_error( $snapshot ) ) {
						return $snapshot;
					}
				}
				// "import" sin ID de una clave que ya existe: SCF crearía un duplicado; se actualiza el existente.
				if ( 'import' === $action && empty( $input['ID'] ) && ! empty( $input['key'] ) ) {
					$existing = spiral_mcp_scf_entity_get( $type, $input['key'] );
					if ( $existing && ! empty( $existing['ID'] ) ) {
						$input['ID'] = (int) $existing['ID'];
					}
				}
				$result = call_user_func( $execute, $input );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				// create/update-field-group de SCF ignoran "fields": se guardan con su importador (reemplaza la lista de campos del grupo).
				if ( 'acf-field-group' === $type && in_array( $action, array( 'create', 'update' ), true ) && isset( $input['fields'] ) && is_array( $input['fields'] ) && is_array( $result ) && ! empty( $result['ID'] ) ) {
					$group = acf_get_field_group( (int) $result['ID'] );
					if ( $group ) {
						acf_import_field_group( array_merge( $group, array( 'fields' => array_values( $input['fields'] ) ) ) );
						$result           = acf_get_field_group( (int) $result['ID'] );
						$result['fields'] = acf_prepare_fields_for_export( acf_get_fields( $result ) );
					}
				}
				if ( true === $result ) {
					$result = array( 'deleted' => true, 'snapshot_id' => $snapshot ?? null );
				} elseif ( is_array( $result ) ) {
					$result['snapshot_id'] = $snapshot ?? null;
				}
				return $result;
			};
			$result = spiral_mcp_log_run( $name, $run, $input, $log );
			if ( ! is_wp_error( $result ) ) {
				spiral_mcp_maybe_auto_clear_cache( $name, $groups, $input, $result, $log );
			}
			return is_wp_error( $result ) ? spiral_mcp_error_status( $result ) : $result;
		};
	}
	return $args;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * "fields" en las abilities de contenido
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_ability_args', function ( $args, $name ) {
	if ( ! spiral_mcp_scf_active() || empty( $args['execute_callback'] ) ) {
		return $args;
	}
	$execute = $args['execute_callback'];
	$fields_prop = array( 'type' => 'object', 'description' => 'Campos personalizados de SCF: {nombre: valor} (null borra). Qué campos admite el tipo y cómo se escribe cada valor: spiral-mcp/list-custom-fields.' );

	switch ( $name ) {
		case 'spiral-mcp/get-content':
			$args['description']     .= ' Con Secure Custom Fields, trae "field_groups" (grupos que aplican), "fields" (valores tal como se escriben) y "resolved" (medios, contenidos y términos expandidos).';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$result = call_user_func( $execute, $input );
				if ( is_array( $result ) && ! empty( $result['id'] ) ) {
					$result += spiral_mcp_scf_values_out( (int) $result['id'], array( 'post_id' => (int) $result['id'], 'post_type' => (string) ( $result['type'] ?? '' ) ) );
				}
				return $result;
			};
			break;

		case 'spiral-mcp/create-content':
		case 'spiral-mcp/update-content':
			$args['input_schema']['properties']['fields'] = $fields_prop;
			$args['description']                         .= ' Con Secure Custom Fields acepta "fields" ({nombre: valor}; ver spiral-mcp/list-custom-fields): se validan antes de guardar y se escriben con SCF.';
			$create                                       = 'spiral-mcp/create-content' === $name;
			$args['execute_callback']                     = function ( $input ) use ( $execute, $create ) {
				$input  = (array) $input;
				$values = isset( $input['fields'] ) ? (array) $input['fields'] : null;
				unset( $input['fields'] );
				$warnings = array();
				$prepared = null;
				if ( null !== $values ) {
					if ( $create ) {
						$location = array( 'post_type' => (string) ( $input['post_type'] ?? 'page' ) );
					} else {
						$post = spiral_mcp_get_post( (int) ( $input['id'] ?? 0 ) );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						$location = array( 'post_id' => $post->ID, 'post_type' => $post->post_type );
					}
					$by_name  = spiral_mcp_scf_fields_by_name( spiral_mcp_scf_groups( $location ) );
					$prepared = spiral_mcp_scf_prepare( $values, $by_name, $warnings );
					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}
					if ( ! $create ) {
						$snapshot = spiral_mcp_snapshot_capture( 'scf_values', 'post:' . $post->ID, 'update-content' );
						if ( is_wp_error( $snapshot ) ) {
							return $snapshot;
						}
					}
				}
				// Solo "fields" en update-content: no hay nada que pasar a wp_update_post.
				if ( ! $create && null !== $prepared && ! array_diff_key( $input, array( 'id' => 1 ) ) ) {
					$result = array( 'id' => $post->ID, 'type' => $post->post_type, 'title' => get_the_title( $post ) );
				} else {
					$result = call_user_func( $execute, $input );
				}
				if ( is_wp_error( $result ) || null === $prepared ) {
					return $result;
				}
				$id = (int) ( $result['id'] ?? 0 );
				if ( $id ) {
					spiral_mcp_scf_write( $id, $prepared );
					$result['fields']   = spiral_mcp_scf_values_out( $id, array( 'post_id' => $id, 'post_type' => get_post_type( $id ) ) )['fields'];
					$result['warnings'] = array_merge( (array) ( $result['warnings'] ?? array() ), $warnings );
					if ( isset( $snapshot ) ) {
						$result['fields_snapshot_id'] = $snapshot;
					}
				}
				return $result;
			};
			break;
	}
	return $args;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Abilities de valores
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! spiral_mcp_scf_active() ) {
		return;
	}
	wp_register_ability_category( 'spiral-mcp-fields', array(
		'label'       => 'Campos personalizados Spiral-MCP',
		'description' => 'Valores de los campos de Secure Custom Fields en contenidos, términos y páginas de opciones.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_scf_active() ) {
		return;
	}
	$target_props = array(
		'id'           => array( 'type' => 'integer', 'description' => 'ID de un contenido (página, entrada o tipo personalizado).' ),
		'term_id'      => array( 'type' => 'integer', 'description' => 'ID de un término (categoría, etiqueta o taxonomía personalizada).' ),
		'options_page' => array( 'type' => 'string', 'description' => 'Slug de menú de una página de opciones de SCF (ver spiral-mcp/list-custom-fields sin parámetros).' ),
	);
	$can_read = function ( $input = array() ) {
		$input = (array) $input;
		if ( ! empty( $input['term_id'] ) ) {
			return current_user_can( 'manage_categories' );
		}
		if ( ! empty( $input['options_page'] ) ) {
			return current_user_can( 'edit_theme_options' );
		}
		return current_user_can( 'edit_posts' );
	};

	/* 1. Qué campos hay ---------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-custom-fields', array(
		'label'               => 'Listar campos personalizados (SCF)',
		'description'         => 'Qué campos personalizados de Secure Custom Fields aplican a un objeto y cómo se escribe cada valor. Con "post_type" (o "id"), los grupos y campos de ese tipo de contenido; con "taxonomy" (o "term_id"), los de sus términos; con "options_page", los de esa página de opciones. Cada campo trae name, type, required, "value" (qué enviar: ID de medio, lista de IDs, clave de "choices", fila de subcampos…), choices, sub_fields y layouts. Sin parámetros: todos los grupos de campos del sitio (título, clave, reglas de ubicación, número de campos) y las páginas de opciones. Para crear o cambiar grupos y campos, las herramientas scf/* (scf/create-field-group…).',
		'category'            => 'spiral-mcp-fields',
		'group'               => 'content',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => $target_props + array(
				'post_type' => array( 'type' => 'string', 'description' => 'Tipo de contenido (ver spiral-mcp/list-post-types).' ),
				'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomía (ver spiral-mcp/list-taxonomies).' ),
			),
		),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$base  = spiral_mcp_scf_plugin();
			if ( ! empty( $input['post_type'] ) && empty( $input['id'] ) ) {
				if ( ! post_type_exists( (string) $input['post_type'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'El tipo "%s" no existe (ver spiral-mcp/list-post-types).', $input['post_type'] ) );
				}
				$location = array( 'post_type' => (string) $input['post_type'] );
				$label    = 'tipo ' . $input['post_type'];
			} elseif ( ! empty( $input['taxonomy'] ) && empty( $input['term_id'] ) ) {
				if ( ! taxonomy_exists( (string) $input['taxonomy'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'La taxonomía "%s" no existe (ver spiral-mcp/list-taxonomies).', $input['taxonomy'] ) );
				}
				$location = array( 'taxonomy' => (string) $input['taxonomy'] );
				$label    = 'taxonomía ' . $input['taxonomy'];
			} elseif ( ! empty( $input['id'] ) || ! empty( $input['term_id'] ) || ! empty( $input['options_page'] ) ) {
				$target = spiral_mcp_scf_target( $input );
				if ( is_wp_error( $target ) ) {
					return $target;
				}
				list( , $location, $label ) = $target;
			} else {
				$groups = array();
				foreach ( acf_get_field_groups() as $group ) {
					$groups[] = spiral_mcp_scf_group_summary( $group, false );
				}
				$pages = array();
				foreach ( spiral_mcp_scf_options_pages() as $page ) {
					$pages[] = array( 'menu_slug' => (string) ( $page['menu_slug'] ?? '' ), 'title' => (string) ( $page['page_title'] ?? '' ), 'parent' => (string) ( $page['parent_slug'] ?? '' ), 'post_id' => (string) ( $page['post_id'] ?? 'options' ) );
				}
				return $base + array( 'field_groups' => $groups, 'options_pages' => $pages, 'field_types' => array_keys( acf_get_field_types() ) );
			}
			$groups = spiral_mcp_scf_groups( $location );
			return $base + array(
				'target'       => $label,
				'field_groups' => array_map( 'spiral_mcp_scf_group_summary', $groups ),
				'field_names'  => array_keys( spiral_mcp_scf_fields_by_name( $groups ) ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Leer valores ------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/get-custom-fields', array(
		'label'               => 'Leer campos personalizados (SCF)',
		'description'         => 'Valores de los campos de Secure Custom Fields de un contenido ("id"), un término ("term_id") o una página de opciones ("options_page"): "fields" tal como se escriben (IDs de medios y contenidos, claves de opciones, filas de repetidores) y "resolved" con los medios, contenidos, términos y usuarios expandidos. spiral-mcp/get-content ya incluye lo mismo para los contenidos.',
		'category'            => 'spiral-mcp-fields',
		'group'               => 'content',
		'input_schema'        => array( 'type' => 'object', 'properties' => $target_props ),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input = array() ) {
			$target = spiral_mcp_scf_target( (array) $input );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			list( $post_id, $location, $label ) = $target;
			return array( 'target' => $label, 'post_id' => $post_id ) + spiral_mcp_scf_values_out( $post_id, $location );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Escribir valores -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-custom-fields', array(
		'label'               => 'Cambiar campos personalizados (SCF)',
		'description'         => 'Escribe valores de campos de Secure Custom Fields en un contenido ("id"), un término ("term_id") o una página de opciones ("options_page"): "fields" = {nombre: valor}; solo cambian los enviados y null borra uno. Lee antes spiral-mcp/list-custom-fields (qué campos aplican y qué forma tiene cada valor) y spiral-mcp/get-custom-fields. Los valores se validan como en el editor (obligatorios, tipos, filas de repetidores por subcampo) y el HTML se filtra sin unfiltered_html. Guarda un snapshot ("snapshot_id", kind scf_values). Para los contenidos también vale "fields" en spiral-mcp/update-content.',
		'category'            => 'spiral-mcp-fields',
		'group'               => 'content',
		'log'                 => array(
			'type' => 'scf_values',
			'id'   => function ( $input ) {
				$input = (array) $input;
				return ! empty( $input['id'] ) ? 'post:' . (int) $input['id'] : ( ! empty( $input['term_id'] ) ? 'term:' . (int) $input['term_id'] : 'options:' . (string) ( $input['options_page'] ?? '' ) );
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'fields' ),
			'properties' => $target_props + array( 'fields' => array( 'type' => 'object', 'description' => '{nombre o clave del campo: valor}. null borra el valor.' ) ),
		),
		'permission_callback' => function ( $input = array() ) {
			$input = (array) $input;
			if ( ! empty( $input['term_id'] ) ) {
				return current_user_can( 'manage_categories' );
			}
			if ( ! empty( $input['options_page'] ) ) {
				return current_user_can( 'edit_theme_options' );
			}
			return ! empty( $input['id'] ) && current_user_can( 'edit_post', (int) $input['id'] );
		},
		'execute_callback'    => function ( $input ) {
			$input  = (array) $input;
			$target = spiral_mcp_scf_target( $input, true );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			list( $post_id, $location, $label ) = $target;
			$groups   = spiral_mcp_scf_groups( $location );
			$by_name  = spiral_mcp_scf_fields_by_name( $groups );
			$warnings = array();
			$prepared = spiral_mcp_scf_prepare( (array) ( $input['fields'] ?? array() ), $by_name, $warnings );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$ref      = spiral_mcp_scf_ref( $post_id, $location );
			$snapshot = spiral_mcp_snapshot_capture( 'scf_values', $ref, 'update-custom-fields' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			spiral_mcp_scf_write( $post_id, $prepared );
			$names = array();
			foreach ( $by_name as $name => $field ) {
				if ( isset( $prepared[ $field['key'] ] ) || array_key_exists( $field['key'], $prepared ) ) {
					$names[] = $name;
				}
			}
			return array( 'target' => $label, 'post_id' => $post_id, 'updated' => $names, 'snapshot_id' => $snapshot, 'warnings' => $warnings ) + spiral_mcp_scf_values_out( $post_id, $location );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
