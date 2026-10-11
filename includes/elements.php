<?php
/**
 * Catálogo de elementos del builder de YOOtheme y validación de layouts.
 *
 * Los tipos se leen con la API del propio YOOtheme:
 * \YOOtheme\app( \YOOtheme\Builder::class )->getTypes(). Su JSON serializado
 * ya trae los campos compartidos (${builder.xxx}) resueltos.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Tipos de campo que solo organizan el formulario: no son props. */
const SPIRAL_MCP_LAYOUT_FIELD_TYPES = array( 'group', 'grid', 'tabs', 'fields', 'description', 'button', 'child-prop' );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Servicio Builder de YOOtheme, o null si YOOtheme no está activo. */
function spiral_mcp_yootheme_builder() {
	if ( ! function_exists( 'YOOtheme\app' ) || ! class_exists( 'YOOtheme\Builder' ) ) {
		return null;
	}
	try {
		return \YOOtheme\app( \YOOtheme\Builder::class );
	} catch ( Throwable $e ) {
		return null;
	}
}

/** Versión de YOOtheme (se escribe en la raíz de los layouts). */
function spiral_mcp_yootheme_version() {
	if ( function_exists( 'YOOtheme\app' ) && interface_exists( 'YOOtheme\Config' ) ) {
		try {
			$version = \YOOtheme\app( \YOOtheme\Config::class )->get( 'theme.version' );
			if ( $version ) {
				return (string) $version;
			}
		} catch ( Throwable $e ) {
			// Sin YOOtheme: se usa la cabecera del tema.
		}
	}
	return (string) wp_get_theme( get_template() )->get( 'Version' );
}

/**
 * Pone "version" en la raíz si falta. Sin ella YOOtheme supone 1.0.0 y, al
 * renderizar, aplica todas sus migraciones al JSON (y puede cambiar props).
 */
function spiral_mcp_layout_stamp_version( array $layout ) {
	if ( empty( $layout['version'] ) ) {
		$version = spiral_mcp_yootheme_version();
		if ( '' !== $version ) {
			$layout['version'] = $version;
		}
	}
	return $layout;
}

/** Valores de un campo select (aplana los grupos de opciones). */
function spiral_mcp_field_option_values( $options ) {
	if ( ! is_array( $options ) ) {
		return null;
	}
	$values = array();
	foreach ( $options as $label => $value ) {
		if ( is_array( $value ) && isset( $value['options'] ) && is_array( $value['options'] ) ) {
			// Grupo de opciones: [ 'label' => 'Animations', 'options' => [ … ] ].
			$values = array_merge( $values, (array) spiral_mcp_field_option_values( $value['options'] ) );
		} elseif ( is_array( $value ) && ! isset( $value['label'] ) && ! isset( $value['value'] ) ) {
			$values = array_merge( $values, (array) spiral_mcp_field_option_values( $value ) );
		} elseif ( is_array( $value ) ) {
			$values[] = (string) ( $value['value'] ?? $label );
		} else {
			$values[] = (string) $value;
		}
	}
	return $values;
}

/**
 * Recorre definiciones de campos (mapa nombre => campo o lista de campos con
 * "name") y devuelve los props reales. Los campos child-prop se devuelven
 * aparte: son props de los hijos (p. ej. los anchos de las columnas de una fila).
 */
function spiral_mcp_collect_fields( $fields, array &$out, array &$child_props ) {
	foreach ( (array) $fields as $key => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$type = $field['type'] ?? '';
		if ( 'child-prop' === $type && isset( $field['field']['name'] ) ) {
			$child_props[ $field['field']['name'] ] = $field['field'];
			continue;
		}
		if ( in_array( $type, SPIRAL_MCP_LAYOUT_FIELD_TYPES, true ) ) {
			if ( isset( $field['fields'] ) ) {
				spiral_mcp_collect_fields( $field['fields'], $out, $child_props );
			}
			continue;
		}
		$name = is_string( $key ) ? $key : ( $field['name'] ?? null );
		if ( null !== $name && '' !== $name ) {
			$out[ $name ] = $field;
		}
	}
}

/**
 * Catálogo de tipos: name => [ title, group, element, container, fragment,
 * item, fields, child_props, defaults ]. null si YOOtheme no está activo.
 */
function spiral_mcp_element_types() {
	static $types = null;
	if ( null !== $types ) {
		return $types ? $types : null;
	}
	$builder = spiral_mcp_yootheme_builder();
	if ( ! $builder ) {
		$types = array();
		return null;
	}
	$raw   = json_decode( wp_json_encode( $builder->getTypes() ), true );
	$types = array();
	foreach ( (array) $raw as $name => $def ) {
		$fields      = array();
		$child_props = array();
		spiral_mcp_collect_fields( $def['fields'] ?? array(), $fields, $child_props );
		foreach ( (array) ( $def['panels'] ?? array() ) as $panel ) {
			spiral_mcp_collect_fields( $panel['fields'] ?? array(), $fields, $child_props );
		}
		$item = null;
		foreach ( $fields as $field ) {
			if ( 'content-items' === ( $field['type'] ?? '' ) && ! empty( $field['item'] ) ) {
				$item = $field['item'];
			}
		}
		$types[ $name ] = array(
			'name'        => $name,
			'title'       => $def['title'] ?? $name,
			'group'       => $def['group'] ?? '',
			'element'     => ! empty( $def['element'] ),
			'container'   => ! empty( $def['container'] ),
			'fragment'    => ! empty( $def['fragment'] ),
			'item'        => $item,
			'fields'      => $fields,
			'child_props' => $child_props,
			'defaults'    => (array) ( $def['defaults'] ?? array() ),
			'fieldset'    => $def['fieldset'] ?? null,
		);
	}
	return $types;
}

/** Tipos de los que $type es el elemento hijo ("list_item" => [ "list" ]). */
function spiral_mcp_element_parents_of( $type, array $types ) {
	$out = array();
	foreach ( $types as $name => $t ) {
		if ( $t['item'] === $type ) {
			$out[] = $name;
		}
	}
	return $out;
}

/**
 * Qué hijos admite un tipo: 'section', 'row', 'column', 'element' (cualquier
 * elemento), un tipo concreto (los items) o null (ninguno).
 */
function spiral_mcp_element_children_rule( $type, array $types ) {
	switch ( $type ) {
		case 'layout':
			return 'section';
		case 'section':
		case 'fragment':
			return 'row';
		case 'row':
			return 'column';
		case 'column':
			return 'element';
	}
	if ( isset( $types[ $type ]['item'] ) && $types[ $type ]['item'] ) {
		return $types[ $type ]['item'];
	}
	return null;
}

/** Catálogo en formato de salida para list-elements. */
function spiral_mcp_element_summary( array $t, array $types ) {
	$rule = spiral_mcp_element_children_rule( $t['name'], $types );
	return array(
		'name'      => $t['name'],
		'title'     => $t['title'],
		'group'     => $t['group'],
		'kind'      => in_array( $t['name'], array( 'layout', 'section', 'row', 'column' ), true ) ? 'structure' : ( $t['element'] ? 'element' : 'item' ),
		'children'  => 'element' === $rule ? 'cualquier elemento' : $rule,
		'parent'    => $t['element'] || in_array( $t['name'], array( 'layout', 'section', 'row', 'column' ), true ) ? null : implode( ', ', spiral_mcp_element_parents_of( $t['name'], $types ) ),
	);
}

/**
 * Valida un layout: errores (bloquean el guardado) para tipos desconocidos y
 * estructuras imposibles; avisos para props desconocidos y valores fuera de
 * sus opciones. Sin YOOtheme activo solo se valida la estructura básica.
 *
 * @return array{errors: string[], warnings: string[]}
 */
function spiral_mcp_layout_validate( array $layout ) {
	$types  = spiral_mcp_element_types();
	$result = array( 'errors' => array(), 'warnings' => array() );
	$root   = $layout['type'] ?? '';
	if ( ! in_array( $root, array( 'layout', 'fragment' ), true ) ) {
		$result['errors'][] = 'La raíz debe tener "type": "layout" (páginas y áreas del tema) o "fragment" (widgets y plantillas).';
		return $result;
	}
	if ( null === $types ) {
		$result['warnings'][] = 'YOOtheme no está activo: solo se ha comprobado la raíz, no los tipos ni los props.';
		return $result;
	}
	spiral_mcp_validate_node( $layout, '', null, $types, $result );

	// No ahogar al agente: como mucho 50 avisos.
	if ( count( $result['warnings'] ) > 50 ) {
		$extra              = count( $result['warnings'] ) - 50;
		$result['warnings'] = array_slice( $result['warnings'], 0, 50 );
		$result['warnings'][] = sprintf( '… y %d avisos más.', $extra );
	}
	return $result;
}

function spiral_mcp_validate_node( $node, $path, $parent, array $types, array &$result ) {
	$at = '' === $path ? '/' : $path;
	if ( ! is_array( $node ) || empty( $node['type'] ) || ! is_string( $node['type'] ) ) {
		$result['errors'][] = sprintf( 'En %s: cada nodo debe ser un objeto con "type".', $at );
		return;
	}
	$type = $node['type'];
	if ( ! isset( $types[ $type ] ) ) {
		$result['errors'][] = sprintf( 'En %s: tipo de elemento desconocido "%s". Consulta spiral-mcp/list-elements.', $at, $type );
		return;
	}

	// ¿Puede ir aquí?
	if ( null !== $parent ) {
		$rule = spiral_mcp_element_children_rule( $parent, $types );
		$ok   = 'element' === $rule ? $types[ $type ]['element'] : $rule === $type;
		if ( ! $ok ) {
			$expected = null === $rule ? 'ningún hijo' : ( 'element' === $rule ? 'elementos' : '"' . $rule . '"' );
			$result['errors'][] = sprintf( 'En %s: "%s" no puede ir dentro de "%s" (admite %s).', $at, $type, $parent, $expected );
			return;
		}
	}

	// Props.
	$props = $node['props'] ?? array();
	if ( ! is_array( $props ) ) {
		$result['errors'][] = sprintf( 'En %s: "props" debe ser un objeto.', $at );
	} elseif ( null !== $parent || 'layout' !== $type ) {
		$known = $types[ $type ]['fields'];
		if ( null !== $parent ) {
			$known += $types[ $parent ]['child_props'];
		}
		foreach ( $props as $prop => $value ) {
			if ( ! isset( $known[ $prop ] ) && ! array_key_exists( $prop, $types[ $type ]['defaults'] ) ) {
				$result['warnings'][] = sprintf( 'En %s (%s): prop desconocido "%s"; YOOtheme lo ignorará. Revisa spiral-mcp/get-element-schema.', $at, $type, $prop );
				continue;
			}
			$field = $known[ $prop ] ?? array();
			if ( in_array( $field['type'] ?? '', array( 'select', 'select-img' ), true ) && is_scalar( $value ) && '' !== $value ) {
				$values = spiral_mcp_field_option_values( $field['options'] ?? null );
				if ( null !== $values && ! in_array( is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value, $values, true ) ) {
					$result['warnings'][] = sprintf( 'En %s (%s): "%s" = "%s" no está entre sus opciones (%s).', $at, $type, $prop, $value, implode( ', ', array_slice( array_map( function ( $v ) {
						return '' === $v ? '""' : $v;
					}, $values ), 0, 20 ) ) );
				}
			}
		}
	}

	// Hijos.
	$children = $node['children'] ?? array();
	if ( ! is_array( $children ) ) {
		$result['errors'][] = sprintf( 'En %s: "children" debe ser una lista.', $at );
		return;
	}
	if ( $children && null === spiral_mcp_element_children_rule( $type, $types ) ) {
		$result['errors'][] = sprintf( 'En %s: "%s" no admite hijos.', $at, $type );
		return;
	}
	foreach ( array_values( $children ) as $i => $child ) {
		spiral_mcp_validate_node( $child, $path . '/children/' . $i, $type, $types, $result );
	}
}

/** Valida y devuelve WP_Error si hay errores. Si no, la lista de avisos. */
function spiral_mcp_layout_check( array $layout ) {
	$check = spiral_mcp_layout_validate( $layout );
	if ( $check['errors'] ) {
		return new WP_Error( 'spiral_mcp_invalid_layout', 'El layout no es válido; no se ha guardado nada. ' . implode( ' ', array_slice( $check['errors'], 0, 10 ) ) );
	}
	return $check['warnings'];
}

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
	};

	spiral_mcp_register_ability( 'spiral-mcp/list-elements', array(
		'label'               => 'Listar elementos del builder',
		'description'         => 'Lista los tipos de nodo del builder de YOOtheme disponibles en este sitio: estructura (layout > section > row > column), elementos (headline, text, image, button, grid…) e items (los hijos de un elemento múltiple: list > list_item, button > button_item, nav > nav_item…). Para cada uno: name (el valor de "type"), title, group, kind, qué hijos admite y, en los items, su elemento padre. Un "fragment" (sublayout) contiene filas. Después usa spiral-mcp/get-element-schema para ver sus props.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'group' => array( 'type' => 'string', 'description' => 'Filtra por grupo (basic, multiple items, system…).' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$types = spiral_mcp_element_types();
			if ( null === $types ) {
				return new WP_Error( 'spiral_mcp_no_yootheme', 'YOOtheme Pro no está activo en este sitio.' );
			}
			$group = (string) ( ( (array) $input )['group'] ?? '' );
			$out   = array();
			foreach ( $types as $t ) {
				if ( '' === $group || $t['group'] === $group ) {
					$out[] = spiral_mcp_element_summary( $t, $types );
				}
			}
			return array( 'version' => spiral_mcp_yootheme_version(), 'total' => count( $out ), 'elements' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-element-schema', array(
		'label'               => 'Esquema de un elemento',
		'description'         => 'Devuelve los props que admite un tipo de elemento de YOOtheme: para cada uno, su tipo de campo (text si no se indica, select, checkbox, number, editor, image, link, color…), etiqueta, opciones permitidas (en los select) y si admite contenido dinámico ("source"). Incluye los valores por defecto del tipo y, en filas, los props que se ponen en las columnas hijas (width_default, width_small, width_medium…). En una fila con varias columnas pon "layout" (una de sus opciones, p. ej. "1-3,1-3,1-3") y los width_* coherentes en cada columna: sin "layout" la fila apila las columnas a ancho completo hasta que un width_* lo cambie. Omite los controles internos del editor (botones de panel y selectores de fuente dinámica). Los props de imagen llevan rutas relativas como wp-content/uploads/2024/11/logo.webp y los enlaces, rutas relativas como /contacto/.',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'type' ),
			'properties' => array(
				'type' => array( 'type' => 'string', 'description' => 'Nombre del tipo, p. ej. headline, button_item.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$types = spiral_mcp_element_types();
			if ( null === $types ) {
				return new WP_Error( 'spiral_mcp_no_yootheme', 'YOOtheme Pro no está activo en este sitio.' );
			}
			$name = (string) ( $input['type'] ?? '' );
			if ( ! isset( $types[ $name ] ) ) {
				return new WP_Error( 'spiral_mcp_unknown_element', sprintf( 'No existe el tipo "%s". Consulta spiral-mcp/list-elements.', $name ) );
			}
			$t      = $types[ $name ];
			$format = function ( array $fields ) {
				$out = array();
				foreach ( $fields as $prop => $f ) {
					$row = array(
						'type'  => $f['type'] ?? 'text',
						'label' => $f['label'] ?? '',
					);
					// Controles del editor que no son props (botones de panel, selectores de fuente dinámica).
					if ( '_' === substr( (string) $prop, 0, 1 ) || in_array( $row['type'], array( 'button-panel', 'hidden' ), true ) || 0 === strpos( $row['type'], 'source-' ) ) {
						continue;
					}
					$values = in_array( $row['type'], array( 'select', 'select-img' ), true ) ? spiral_mcp_field_option_values( $f['options'] ?? null ) : null;
					if ( null !== $values ) {
						$row['options'] = $values;
					}
					if ( isset( $f['attrs']['true-value'] ) ) {
						$row['true_value']  = $f['attrs']['true-value'];
						$row['false_value'] = $f['attrs']['false-value'] ?? '';
					}
					if ( ! empty( $f['source'] ) ) {
						$row['dynamic'] = true;
					}
					if ( ! empty( $f['item'] ) ) {
						$row['item'] = $f['item'];
					}
					if ( ! empty( $f['description'] ) ) {
						$row['description'] = wp_strip_all_tags( $f['description'] );
					}
					$out[ $prop ] = $row;
				}
				return (object) $out;
			};
			return spiral_mcp_element_summary( $t, $types ) + array(
				'defaults'    => (object) $t['defaults'],
				'props'       => $format( $t['fields'] ),
				'child_props' => $format( $t['child_props'] ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
