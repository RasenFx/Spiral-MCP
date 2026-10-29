<?php
/**
 * Elementor: widgets clásicos (V3). Lee y modifica el árbol de elementos de un documento de
 * Elementor (meta _elementor_data) a través de su Document API, sin pasar por su MCP y sin
 * reimplementar el builder.
 *
 * El MCP de Elementor (4.3+) solo edita elementos V4 "atómicos" (puerta Mcp_V4_Gate y una
 * lista cerrada de seis widgets V3): un sitio maquetado con widgets clásicos (heading,
 * text-editor, image, image-carousel, section/column/container…) no se puede tocar desde él.
 * Aquí se usa lo mismo que usa el editor de Elementor al guardar: Document::save(), que
 * aplica kses (sin unfiltered_html), guarda la revisión de WordPress con los metas
 * _elementor* copiados, la versión, y borra el CSS de la página y la caché de elementos.
 * Spiral añade lo suyo: snapshot antes de escribir, patch por rutas o por ID de elemento,
 * validación (tipos registrados, colocación, ajustes conocidos), auditoría y purga de caché.
 *
 * Solo API estable de Elementor (desde 2.0): Documents_Manager::get(), Document::get_json_meta(),
 * get_autosave(), save(), DB::copy_elementor_meta(), DB::save_plain_text(), Post_CSS::update().
 * No se usa Document_Mutator (llegó con el MCP en 4.3.0).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Metas que forman el diseño de un documento de Elementor (los del snapshot). */
const SPIRAL_MCP_ELEMENTOR_META = array( '_elementor_data', '_elementor_page_settings', '_elementor_template_type', '_elementor_version', '_elementor_edit_mode' );

/** Claves de settings que no son controles pero Elementor usa o guarda. */
const SPIRAL_MCP_ELEMENTOR_INTERNAL_KEYS = array( '__dynamic__', '__globals__', '_title', '_column_size', 'isLocked' );

/** Sufijos de breakpoint de los controles responsive. */
const SPIRAL_MCP_ELEMENTOR_BREAKPOINTS = array( '_tablet_extra', '_mobile_extra', '_widescreen', '_laptop', '_tablet', '_mobile' );

/* -------------------------------------------------------------------------
 * Acceso al documento
 * ---------------------------------------------------------------------- */

/** Árbol crudo de un post (o de un autosave): el meta _elementor_data decodificado. */
function spiral_mcp_elementor_meta_tree( $post_id ) {
	$raw = get_post_meta( (int) $post_id, '_elementor_data', true );
	if ( is_array( $raw ) ) {
		return array_values( $raw );
	}
	$tree = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
	return is_array( $tree ) ? array_values( $tree ) : array();
}

/** Quién tiene el post abierto en el editor (meta _edit_lock), o 0. */
function spiral_mcp_elementor_locked_by( $post_id ) {
	$lock = get_post_meta( (int) $post_id, '_edit_lock', true );
	if ( ! $lock || false === strpos( (string) $lock, ':' ) ) {
		return 0;
	}
	list( $time, $user ) = explode( ':', (string) $lock );
	$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );
	if ( (int) $user && (int) $user !== get_current_user_id() && (int) $time > time() - $window ) {
		return (int) $user;
	}
	return 0;
}

/**
 * Carga un documento de Elementor: [ post, doc, elements, autosave (Document|null del usuario
 * actual, más nuevo que el post), draft (si "elements" es el del autosave), warnings ].
 * Con $allow_convert acepta un post que aún no es de Elementor (para update-elementor-data
 * con "convert").
 */
function spiral_mcp_elementor_load( $id, $want_draft = false, $allow_convert = false, $for_write = false ) {
	if ( ! spiral_mcp_elementor_active() || ! class_exists( '\Elementor\Plugin' ) ) {
		return new WP_Error( 'spiral_mcp_no_elementor', 'Elementor no está activo en este sitio.' );
	}
	$post = spiral_mcp_get_post( $id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return new WP_Error( 'spiral_mcp_elementor_bad_id', 'Ese ID es una revisión o un autosave: usa el ID de la página o entrada.' );
	}
	$built = spiral_mcp_elementor_built( $post->ID );
	if ( ! $built && ! $allow_convert ) {
		return new WP_Error( 'spiral_mcp_not_elementor', sprintf( 'El contenido %d no está construido con Elementor ("builder" distinto de "elementor" en spiral-mcp/get-content). Para convertirlo en una página de Elementor, llama a spiral-mcp/update-elementor-data con "convert": true y su árbol de elementos; para crear una nueva, spiral-mcp/create-content en borrador y después esa misma llamada.', $post->ID ) );
	}
	try {
		$doc = \Elementor\Plugin::$instance->documents->get( $post->ID, false );
	} catch ( Throwable $e ) {
		$doc = null;
	}
	if ( ! $doc ) {
		return new WP_Error( 'spiral_mcp_elementor_no_document', sprintf( 'Elementor no reconoce el contenido %d como documento suyo.', $post->ID ) );
	}
	if ( 'kit' === $doc->get_name() ) {
		return new WP_Error( 'spiral_mcp_elementor_kit', 'El kit de Elementor (estilos globales) no es un documento con elementos: no se edita por aquí.' );
	}
	$warnings = array();
	$locked   = spiral_mcp_elementor_locked_by( $post->ID );
	if ( $locked ) {
		$user       = get_userdata( $locked );
		$warnings[] = sprintf( 'El usuario %s tiene este contenido abierto en el editor de Elementor ahora mismo: si guarda, puede pisar tus cambios (y tú los suyos).', $user ? $user->display_name : '#' . $locked );
	}
	$elements = $built ? spiral_mcp_elementor_meta_tree( $post->ID ) : array();
	$autosave = null;
	if ( $built ) {
		try {
			$autosave = $doc->get_autosave( get_current_user_id() );
		} catch ( Throwable $e ) {
			$autosave = null;
		}
		$autosave = $autosave ? $autosave : null;
	}
	$draft = false;
	if ( $want_draft && $autosave ) {
		$elements = spiral_mcp_elementor_meta_tree( $autosave->get_post()->ID );
		$draft    = true;
	} elseif ( $want_draft && ! $for_write ) {
		$warnings[] = 'No tienes ningún borrador de Elementor de este contenido: se devuelve la versión guardada.';
	}
	return array(
		'post'     => $post,
		'doc'      => $doc,
		'built'    => $built,
		'elements' => $elements,
		'autosave' => $autosave,
		'draft'    => $draft,
		'warnings' => $warnings,
	);
}

/** Datos de Elementor de un post para get-content. */
function spiral_mcp_elementor_info( WP_Post $post ) {
	$autosave = function_exists( 'wp_get_post_autosave' ) ? wp_get_post_autosave( $post->ID, get_current_user_id() ) : false;
	$newer    = $autosave && mysql2date( 'U', $autosave->post_modified_gmt, false ) > mysql2date( 'U', $post->post_modified_gmt, false );
	$locked   = spiral_mcp_elementor_locked_by( $post->ID );
	return array(
		'version'       => (string) get_post_meta( $post->ID, '_elementor_version', true ),
		'template_type' => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
		'has_draft'     => (bool) $newer,
		'autosave_id'   => $newer ? (int) $autosave->ID : null,
		'locked_by'     => $locked ? (int) $locked : null,
		'edit_with'     => 'spiral-mcp/get-elementor-outline y spiral-mcp/patch-elementor-data (widgets clásicos); elementor/* solo para elementos V4.',
	);
}

/* -------------------------------------------------------------------------
 * Catálogo de tipos y controles
 * ---------------------------------------------------------------------- */

/** ¿Es un elemento V4 (atómico)? */
function spiral_mcp_elementor_is_atomic( $instance ) {
	if ( ! $instance ) {
		return false;
	}
	if ( class_exists( '\Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils' ) ) {
		try {
			return (bool) \Elementor\Modules\GlobalClasses\Utils\Atomic_Elements_Utils::is_atomic_element( $instance );
		} catch ( Throwable $e ) {
			return false;
		}
	}
	return ( class_exists( '\Elementor\Modules\AtomicWidgets\Elements\Atomic_Widget_Base' ) && $instance instanceof \Elementor\Modules\AtomicWidgets\Elements\Atomic_Widget_Base )
		|| ( class_exists( '\Elementor\Modules\AtomicWidgets\Elements\Atomic_Element_Base' ) && $instance instanceof \Elementor\Modules\AtomicWidgets\Elements\Atomic_Element_Base );
}

/** ¿Es el marcador de un widget de Elementor Pro en un sitio sin Pro? */
function spiral_mcp_elementor_is_promo( $instance ) {
	return class_exists( '\Elementor\Modules\Promotions\Widgets\Pro_Widget_Promotion' ) && $instance instanceof \Elementor\Modules\Promotions\Widgets\Pro_Widget_Promotion;
}

/**
 * Tipos registrados: elements (section, column, container…), widgets (los del panel), hidden
 * (registrados pero fuera del panel: válidos en un árbol, no se ofrecen), promo (widgets de
 * Pro sin Pro: no se guardan), atomic (V4: no se crean). Todo clásico salvo "atomic".
 */
function spiral_mcp_elementor_types() {
	static $types = null;
	if ( null !== $types ) {
		return $types;
	}
	$types = array( 'elements' => array(), 'widgets' => array(), 'hidden' => array(), 'promo' => array(), 'atomic' => array() );
	if ( ! spiral_mcp_elementor_active() || ! class_exists( '\Elementor\Plugin' ) ) {
		return $types;
	}
	// Con la carga optimizada de controles, get_controls() omitiría la pestaña Style.
	if ( class_exists( '\Elementor\Core\Frontend\Performance' ) && method_exists( '\Elementor\Core\Frontend\Performance', 'set_use_style_controls' ) ) {
		\Elementor\Core\Frontend\Performance::set_use_style_controls( true );
	}
	$plugin = \Elementor\Plugin::$instance;
	try {
		foreach ( (array) $plugin->elements_manager->get_element_types() as $name => $el ) {
			if ( spiral_mcp_elementor_is_atomic( $el ) ) {
				$types['atomic'][ $name ] = true;
				continue;
			}
			$types['elements'][ $name ] = $el;
		}
		foreach ( (array) $plugin->widgets_manager->get_widget_types() as $name => $w ) {
			if ( spiral_mcp_elementor_is_atomic( $w ) ) {
				$types['atomic'][ $name ] = true;
			} elseif ( spiral_mcp_elementor_is_promo( $w ) ) {
				$types['promo'][ $name ] = true;
			} elseif ( 0 === strpos( $name, 'common' ) ) {
				continue;
			} elseif ( ! $w->show_in_panel() ) {
				$types['hidden'][ $name ] = $w;
			} else {
				$types['widgets'][ $name ] = $w;
			}
		}
	} catch ( Throwable $e ) {
		// Catálogo parcial: la validación avisará de los tipos que no encuentre.
	}
	return $types;
}

/** Instancia registrada de un tipo (clásico), o null. */
function spiral_mcp_elementor_instance( $el_type, $widget_type = '' ) {
	$types = spiral_mcp_elementor_types();
	if ( 'widget' === $el_type ) {
		return $types['widgets'][ $widget_type ] ?? ( $types['hidden'][ $widget_type ] ?? null );
	}
	return $types['elements'][ $el_type ] ?? null;
}

/**
 * Controles de un tipo: [ 'controls' => nombre => control (incluye Style y Advanced),
 * 'tabs' => id => etiqueta ]. null si el tipo no está registrado.
 */
function spiral_mcp_elementor_controls( $el_type, $widget_type = '' ) {
	static $cache = array();
	$key = 'widget' === $el_type ? 'widget:' . $widget_type : (string) $el_type;
	if ( array_key_exists( $key, $cache ) ) {
		return $cache[ $key ];
	}
	$instance = spiral_mcp_elementor_instance( $el_type, $widget_type );
	if ( ! $instance ) {
		$cache[ $key ] = null;
		return null;
	}
	$controls = array();
	$tabs     = array();
	try {
		$stack    = 'widget' === $el_type ? $instance->get_stack( true ) : $instance->get_stack();
		$controls = (array) ( $stack['controls'] ?? array() );
		if ( ! empty( $stack['style_controls'] ) && is_array( $stack['style_controls'] ) ) {
			$controls += $stack['style_controls'];
		}
		$tabs = (array) ( $stack['tabs'] ?? array() );
	} catch ( Throwable $e ) {
		$controls = array();
	}
	$cache[ $key ] = array( 'controls' => $controls, 'tabs' => $tabs );
	return $cache[ $key ];
}

/** Tipos de control que solo organizan el panel: no son ajustes. */
function spiral_mcp_elementor_ui_control( $type ) {
	return in_array( (string) $type, array( 'heading', 'raw_html', 'notice', 'alert', 'deprecated_notice', 'divider', 'section', 'tab', 'tabs', 'button' ), true );
}

/** Forma del valor que espera un control, para el agente. */
function spiral_mcp_elementor_control_hint( array $c ) {
	switch ( (string) ( $c['type'] ?? 'text' ) ) {
		case 'text':
		case 'textarea':
		case 'code':
			return 'string';
		case 'wysiwyg':
			return 'string con HTML (p, strong, a, ul…)';
		case 'number':
			return 'number';
		case 'select':
		case 'choose':
		case 'visual_choice':
		case 'select2':
			return ! empty( $c['multiple'] ) ? 'lista de valores de "options"' : 'uno de los valores de "options"';
		case 'switcher':
			return '"' . (string) ( $c['return_value'] ?? 'yes' ) . '" (activo) o "" (inactivo)';
		case 'url':
			return '{"url": "/ruta/ o https://…", "is_external": "on"|"", "nofollow": "on"|"", "custom_attributes": ""}';
		case 'media':
			return '{"id": ID del adjunto, "url": "URL completa del archivo"} (sube con spiral-mcp/upload-media)';
		case 'gallery':
			return '[{"id": ID, "url": "URL"}, …] (adjuntos de la biblioteca; spiral-mcp/upload-media)';
		case 'icons':
			return '{"value": "fas fa-star", "library": "fa-solid"} o {"value": {"id": ID, "url": "…svg"}, "library": "svg"}';
		case 'slider':
			return '{"unit": "px"|"%"|"em"|…, "size": número, "sizes": []}';
		case 'dimensions':
			return '{"top": "", "right": "", "bottom": "", "left": "", "unit": "px", "isLinked": true}';
		case 'color':
			return '"#rrggbb" o "rgba(r,g,b,a)" ("" = por defecto/global)';
		case 'repeater':
			return 'lista de filas [{"_id": "7 hex", …campos de "fields"}]';
		case 'date_time':
			return '"YYYY-MM-DD HH:MM"';
		case 'font':
			return 'nombre de la familia tipográfica';
		case 'popover_toggle':
			return '"' . (string) ( $c['return_value'] ?? 'custom' ) . '" para activar el grupo o ""';
		case 'hidden':
			return 'interno de Elementor';
		case 'animation':
		case 'hover_animation':
		case 'exit_animation':
			return 'nombre de la animación (p. ej. "fadeIn") o ""';
		default:
			return (string) ( $c['type'] ?? 'text' );
	}
}

/** Opciones de un select/choose como valor => etiqueta. */
function spiral_mcp_elementor_control_options( array $c ) {
	if ( empty( $c['options'] ) || ! is_array( $c['options'] ) ) {
		return null;
	}
	$out = array();
	foreach ( $c['options'] as $value => $label ) {
		$out[ (string) $value ] = is_array( $label ) ? (string) ( $label['title'] ?? $value ) : (string) $label;
	}
	return $out;
}

/** Formato de un control para get-elementor-widget-schema. */
function spiral_mcp_elementor_control_format( $name, array $c ) {
	$row = array(
		'type'  => (string) ( $c['type'] ?? 'text' ),
		'label' => wp_strip_all_tags( (string) ( $c['label'] ?? ( $c['text'] ?? '' ) ) ),
		'hint'  => spiral_mcp_elementor_control_hint( $c ),
	);
	if ( array_key_exists( 'default', $c ) && '' !== $c['default'] && array() !== $c['default'] ) {
		$row['default'] = $c['default'];
	}
	$options = spiral_mcp_elementor_control_options( $c );
	if ( null !== $options ) {
		$row['options'] = $options;
	}
	if ( ! empty( $c['is_responsive'] ) || ( isset( $c['responsive'] ) && is_array( $c['responsive'] ) ) ) {
		$row['responsive'] = true;
	}
	if ( ! empty( $c['condition'] ) ) {
		$row['condition'] = $c['condition'];
	}
	if ( ! empty( $c['dynamic']['active'] ) ) {
		$row['dynamic'] = true;
	}
	if ( ! empty( $c['description'] ) ) {
		$row['description'] = wp_strip_all_tags( (string) $c['description'] );
	}
	if ( 'repeater' === $row['type'] && ! empty( $c['fields'] ) && is_array( $c['fields'] ) ) {
		$row['fields'] = array();
		foreach ( $c['fields'] as $fname => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$fname = is_string( $fname ) ? $fname : (string) ( $field['name'] ?? $fname );
			if ( '_id' === $fname || spiral_mcp_elementor_ui_control( $field['type'] ?? '' ) ) {
				continue;
			}
			$row['fields'][ $fname ] = spiral_mcp_elementor_control_format( $fname, $field );
		}
		if ( ! empty( $c['title_field'] ) ) {
			$row['title_field'] = (string) $c['title_field'];
		}
	}
	return $row;
}

/** ¿La clave de settings es un control del tipo (o una variante responsive / interna)? */
function spiral_mcp_elementor_known_setting( $key, array $controls ) {
	$key = (string) $key;
	if ( isset( $controls[ $key ] ) || in_array( $key, SPIRAL_MCP_ELEMENTOR_INTERNAL_KEYS, true ) ) {
		return true;
	}
	foreach ( SPIRAL_MCP_ELEMENTOR_BREAKPOINTS as $suffix ) {
		if ( substr( $key, -strlen( $suffix ) ) === $suffix && isset( $controls[ substr( $key, 0, -strlen( $suffix ) ) ] ) ) {
			return true;
		}
	}
	return false;
}

/** Control base de una clave (quita el sufijo de breakpoint), o null. */
function spiral_mcp_elementor_control_of( $key, array $controls ) {
	$key = (string) $key;
	if ( isset( $controls[ $key ] ) ) {
		return $controls[ $key ];
	}
	foreach ( SPIRAL_MCP_ELEMENTOR_BREAKPOINTS as $suffix ) {
		if ( substr( $key, -strlen( $suffix ) ) === $suffix && isset( $controls[ substr( $key, 0, -strlen( $suffix ) ) ] ) ) {
			return $controls[ substr( $key, 0, -strlen( $suffix ) ) ];
		}
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Árbol: ids, recorrido, búsqueda y operaciones
 * ---------------------------------------------------------------------- */

/** ID nuevo como los del editor de Elementor: 7 caracteres hexadecimales. */
function spiral_mcp_elementor_new_id() {
	return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
}

/** Etiqueta corta de un nodo: "widget:heading", "section", "column"… */
function spiral_mcp_elementor_label( array $node ) {
	$el = (string) ( $node['elType'] ?? '?' );
	return 'widget' === $el ? 'widget:' . (string) ( $node['widgetType'] ?? '?' ) : $el;
}

/** Nombre de tipo para el catálogo: widgetType en widgets, elType en el resto. */
function spiral_mcp_elementor_type_key( array $node ) {
	return 'widget' === ( $node['elType'] ?? '' ) ? (string) ( $node['widgetType'] ?? '' ) : (string) ( $node['elType'] ?? '' );
}

/**
 * Recorre el árbol. $fn( &$node, $path, $parent (array|null), $depth ); si devuelve false no
 * entra en los hijos. Rutas JSON Pointer: /0, /0/elements/1…
 */
function spiral_mcp_elementor_walk( array &$list, callable $fn, $path = '', $parent = null, $depth = 0 ) {
	foreach ( $list as $i => &$node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$p = $path . '/' . $i;
		if ( false === $fn( $node, $p, $parent, $depth ) ) {
			continue;
		}
		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			spiral_mcp_elementor_walk( $node['elements'], $fn, $p . '/elements', $node, $depth + 1 );
		}
	}
	unset( $node );
}

/** Busca un elemento por id: [ path, node, list_path (lista que lo contiene), index ] o null. */
function spiral_mcp_elementor_find( array $list, $element_id, $path = '' ) {
	foreach ( $list as $i => $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$p = $path . '/' . $i;
		if ( isset( $node['id'] ) && (string) $node['id'] === (string) $element_id ) {
			return array( 'path' => $p, 'node' => $node, 'list_path' => $path, 'index' => (int) $i );
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$found = spiral_mcp_elementor_find( $node['elements'], $element_id, $p . '/elements' );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}

/** Todos los ids del árbol (id => ruta). */
function spiral_mcp_elementor_ids( array $list ) {
	$ids = array();
	spiral_mcp_elementor_walk( $list, function ( &$node, $path ) use ( &$ids ) {
		if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
			$ids[ (string) $node['id'] ] = $path;
		}
	} );
	return $ids;
}

/** Ids nuevos para un nodo y todo su subárbol (incluidas las filas _id de los repeaters). */
function spiral_mcp_elementor_regenerate_ids( array $node ) {
	$node['id'] = spiral_mcp_elementor_new_id();
	if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
		foreach ( $node['settings'] as &$value ) {
			if ( is_array( $value ) && spiral_mcp_is_list( $value ) ) {
				foreach ( $value as &$row ) {
					if ( is_array( $row ) && array_key_exists( '_id', $row ) ) {
						$row['_id'] = spiral_mcp_elementor_new_id();
					}
				}
				unset( $row );
			}
		}
		unset( $value );
	}
	if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
		foreach ( $node['elements'] as &$child ) {
			if ( is_array( $child ) ) {
				$child = spiral_mcp_elementor_regenerate_ids( $child );
			}
		}
		unset( $child );
	}
	return $node;
}

/** Ids del subárbol que ya existen en el árbol: se regeneran (solo esos nodos). */
function spiral_mcp_elementor_dedupe_ids( array $node, array $existing, &$changed = 0 ) {
	if ( ! isset( $node['id'] ) || ! is_scalar( $node['id'] ) || '' === (string) $node['id'] || isset( $existing[ (string) $node['id'] ] ) ) {
		$node['id'] = spiral_mcp_elementor_new_id();
		$changed++;
	}
	if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
		foreach ( $node['elements'] as &$child ) {
			if ( is_array( $child ) ) {
				$child = spiral_mcp_elementor_dedupe_ids( $child, $existing, $changed );
			}
		}
		unset( $child );
	}
	return $node;
}

/** Lista (JSON Pointer) donde insertar bajo un padre: "" (raíz) o "/0/elements". */
function spiral_mcp_elementor_parent_list( array &$tree, $parent_id ) {
	$parent_id = (string) $parent_id;
	if ( '' === $parent_id || 'document' === $parent_id || 'root' === $parent_id ) {
		return '';
	}
	$found = spiral_mcp_elementor_find( $tree, $parent_id );
	if ( ! $found ) {
		return new WP_Error( 'spiral_mcp_element_not_found', sprintf( 'No hay ningún elemento con id "%s" (parent_id). Consulta los ids con spiral-mcp/get-elementor-outline; "document" es la raíz.', $parent_id ) );
	}
	if ( 'widget' === ( $found['node']['elType'] ?? '' ) ) {
		return new WP_Error( 'spiral_mcp_bad_patch', sprintf( 'El elemento "%s" es un widget (%s) y no admite hijos: inserta en una column, un container o una section.', $parent_id, (string) ( $found['node']['widgetType'] ?? '' ) ) );
	}
	if ( empty( $found['node']['elements'] ) || ! is_array( $found['node']['elements'] ) ) {
		$res = spiral_mcp_pointer_replace( $tree, $found['path'] . '/elements', array() );
		if ( is_wp_error( $res ) ) {
			$res = spiral_mcp_pointer_add( $tree, $found['path'] . '/elements', array() );
		}
	}
	return $found['path'] . '/elements';
}

/** Índice de inserción ("-" = al final) dentro de una lista del árbol. */
function spiral_mcp_elementor_index_path( array $tree, $list_path, $index ) {
	if ( null === $index || '' === $index || '-' === $index ) {
		return $list_path . '/-';
	}
	$list  = spiral_mcp_pointer_get( $tree, $list_path );
	$count = is_array( $list ) ? count( $list ) : 0;
	$index = max( 0, min( $count, (int) $index ) );
	return $list_path . '/' . $index;
}

/** Operaciones por id de elemento: update, replace_settings, remove, duplicate, move, insert. */
function spiral_mcp_elementor_apply_id_op( array &$tree, $name, array $op, array &$warnings ) {
	$id = (string) ( $op['element_id'] ?? '' );
	if ( 'insert' !== $name ) {
		if ( '' === $id ) {
			return new WP_Error( 'spiral_mcp_bad_patch', sprintf( '"%s" necesita "element_id".', $name ) );
		}
		$found = spiral_mcp_elementor_find( $tree, $id );
		if ( ! $found ) {
			return new WP_Error( 'spiral_mcp_element_not_found', sprintf( 'No hay ningún elemento con id "%s". Consulta los ids con spiral-mcp/get-elementor-outline (el árbol pudo cambiar desde que lo leíste).', $id ) );
		}
	}
	switch ( $name ) {
		case 'update':
		case 'replace_settings':
			if ( ! isset( $op['settings'] ) || ! is_array( $op['settings'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_patch', sprintf( '"%s" necesita "settings" (objeto).', $name ) );
			}
			if ( 'update' === $name ) {
				$settings = isset( $found['node']['settings'] ) && is_array( $found['node']['settings'] ) ? $found['node']['settings'] : array();
				foreach ( $op['settings'] as $key => $value ) {
					if ( null === $value ) {
						unset( $settings[ $key ] );
					} else {
						$settings[ $key ] = $value;
					}
				}
			} else {
				$settings = $op['settings'];
			}
			$res = spiral_mcp_pointer_replace( $tree, $found['path'] . '/settings', $settings );
			return is_wp_error( $res ) ? spiral_mcp_pointer_add( $tree, $found['path'] . '/settings', $settings ) : $res;

		case 'remove':
			return spiral_mcp_pointer_remove( $tree, $found['path'] );

		case 'duplicate':
			$clone = spiral_mcp_elementor_regenerate_ids( $found['node'] );
			$res   = spiral_mcp_pointer_add( $tree, $found['list_path'] . '/' . ( $found['index'] + 1 ), $clone );
			if ( ! is_wp_error( $res ) ) {
				$res = array( 'new_id' => $clone['id'] );
			}
			return $res;

		case 'move':
			$parent_id = (string) ( $op['parent_id'] ?? '' );
			if ( '' === $parent_id ) {
				return new WP_Error( 'spiral_mcp_bad_patch', '"move" por element_id necesita "parent_id" ("document" para la raíz) y, opcionalmente, "index".' );
			}
			if ( $parent_id === $id ) {
				return new WP_Error( 'spiral_mcp_bad_patch', 'No se puede mover un elemento dentro de sí mismo.' );
			}
			$target = spiral_mcp_elementor_find( $tree, $parent_id );
			if ( $target && 0 === strpos( $target['path'] . '/', $found['path'] . '/' ) ) {
				return new WP_Error( 'spiral_mcp_bad_patch', 'No se puede mover un elemento dentro de uno de sus descendientes.' );
			}
			$node = $found['node'];
			$res  = spiral_mcp_pointer_remove( $tree, $found['path'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$list = spiral_mcp_elementor_parent_list( $tree, $parent_id );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			return spiral_mcp_pointer_add( $tree, spiral_mcp_elementor_index_path( $tree, $list, $op['index'] ?? null ), $node );

		case 'insert':
			$node = $op['node'] ?? ( $op['value'] ?? null );
			if ( ! is_array( $node ) || empty( $node['elType'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_patch', '"insert" necesita "node": un objeto con "elType" ("section", "column", "container" o "widget" + "widgetType"), "settings" y, si procede, "elements". Mira spiral-mcp/get-elementor-widget-schema para sus ajustes.' );
			}
			$changed = 0;
			$node    = spiral_mcp_elementor_dedupe_ids( $node, spiral_mcp_elementor_ids( $tree ), $changed );
			$list    = spiral_mcp_elementor_parent_list( $tree, (string) ( $op['parent_id'] ?? 'document' ) );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$res = spiral_mcp_pointer_add( $tree, spiral_mcp_elementor_index_path( $tree, $list, $op['index'] ?? null ), $node );
			if ( ! is_wp_error( $res ) ) {
				$res = array( 'new_id' => $node['id'] );
			}
			return $res;
	}
	return new WP_Error( 'spiral_mcp_bad_patch', sprintf( 'Operación "%s" no válida.', $name ) );
}

/**
 * Aplica las operaciones a una copia del árbol. Por ruta (JSON Patch: add, remove, replace,
 * move, copy, test) o por id de elemento (update, replace_settings, remove, duplicate, move,
 * insert). Atómico: si una falla, WP_Error y nada cambia. Devuelve [ tree, ids nuevos ].
 */
function spiral_mcp_elementor_apply_ops( array $tree, array $ops, array &$warnings ) {
	$hint    = 'spiral-mcp/get-elementor-outline';
	$new_ids = array();
	foreach ( array_values( $ops ) as $i => $op ) {
		$op    = (array) $op;
		$name  = (string) ( $op['op'] ?? '' );
		$by_id = isset( $op['element_id'] ) || in_array( $name, array( 'update', 'replace_settings', 'duplicate', 'insert' ), true );
		if ( $by_id ) {
			$res = spiral_mcp_elementor_apply_id_op( $tree, $name, $op, $warnings );
		} elseif ( 'copy' === $name ) {
			// Una copia necesita ids nuevos en todo el subárbol.
			$value = spiral_mcp_pointer_get( $tree, (string) ( $op['from'] ?? '' ) );
			$res   = $value;
			if ( ! is_wp_error( $value ) ) {
				if ( is_array( $value ) && isset( $value['elType'] ) ) {
					$value = spiral_mcp_elementor_regenerate_ids( $value );
				}
				$res = spiral_mcp_pointer_add( $tree, (string) ( $op['path'] ?? '' ), $value );
			}
		} elseif ( in_array( $name, array( 'add', 'remove', 'replace', 'move', 'test' ), true ) ) {
			if ( 'add' === $name && is_array( $op['value'] ?? null ) && isset( $op['value']['elType'] ) ) {
				$changed     = 0;
				$op['value'] = spiral_mcp_elementor_dedupe_ids( $op['value'], spiral_mcp_elementor_ids( $tree ), $changed );
				if ( $changed ) {
					$warnings[] = sprintf( 'Operación %d (add): se generaron %d ids nuevos para el nodo añadido (faltaban o ya existían en el árbol).', $i, $changed );
				}
			}
			$res = spiral_mcp_pointer_apply_op( $tree, $op, $hint );
		} else {
			$res = new WP_Error( 'spiral_mcp_bad_patch', sprintf( 'Operación "%s" no válida. Por ruta: add, remove, replace, move, copy, test. Por element_id: update, replace_settings, remove, duplicate, move, insert.', $name ) );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), sprintf( 'Operación %d (%s): %s No se ha guardado nada.', $i, $name, $res->get_error_message() ) );
		}
		if ( is_array( $res ) && isset( $res['new_id'] ) ) {
			$new_ids[ $i ] = $res['new_id'];
		}
	}
	return array( $tree, $new_ids );
}

/* -------------------------------------------------------------------------
 * Validación
 * ---------------------------------------------------------------------- */

/** Un nodo de la lista: errores, avisos y normalización (ids, isInner, _column_size). */
function spiral_mcp_elementor_check_list( array &$list, $path, $parent_type, array &$ctx ) {
	$types = $ctx['types'];
	foreach ( $list as $i => &$node ) {
		$at = $path . '/' . $i;
		if ( ! is_array( $node ) || empty( $node['elType'] ) || ! is_string( $node['elType'] ) ) {
			$ctx['errors'][] = sprintf( 'En %s: cada nodo debe ser un objeto con "elType" (section, column, container o widget).', $at );
			continue;
		}
		$el    = $node['elType'];
		$label = spiral_mcp_elementor_label( $node );
		$type  = spiral_mcp_elementor_type_key( $node );

		// Forma.
		if ( ! isset( $node['settings'] ) || array() === $node['settings'] || null === $node['settings'] ) {
			$node['settings'] = array();
		} elseif ( ! is_array( $node['settings'] ) ) {
			$ctx['errors'][] = sprintf( 'En %s (%s): "settings" debe ser un objeto.', $at, $label );
			continue;
		}
		if ( ! isset( $node['elements'] ) || null === $node['elements'] ) {
			$node['elements'] = array();
		} elseif ( ! is_array( $node['elements'] ) ) {
			$ctx['errors'][] = sprintf( 'En %s (%s): "elements" debe ser una lista.', $at, $label );
			continue;
		}
		$node['elements'] = array_values( $node['elements'] );
		if ( 'widget' === $el ) {
			if ( empty( $node['widgetType'] ) || ! is_string( $node['widgetType'] ) ) {
				$ctx['errors'][] = sprintf( 'En %s: un widget necesita "widgetType" (heading, text-editor, image, image-carousel…; lista en spiral-mcp/list-elementor-widgets).', $at );
				continue;
			}
			if ( $node['elements'] ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): un widget no admite "elements".', $at, $label );
				continue;
			}
		}

		// Tipo registrado.
		$instance = spiral_mcp_elementor_instance( $el, $type );
		if ( ! $instance ) {
			$orig_type = isset( $node['id'] ) ? ( $ctx['orig'][ (string) $node['id'] ] ?? null ) : null;
			if ( isset( $types['atomic'][ $type ] ) ) {
				if ( $orig_type === $type ) {
					$ctx['warnings'][] = sprintf( 'En %s (%s): elemento V4 (atómico) ya existente; se conserva tal cual. Edítalo con elementor/manage-elements.', $at, $label );
				} else {
					$ctx['errors'][] = sprintf( 'En %s (%s): "%s" es un elemento V4 (atómico). Este sitio se edita con widgets clásicos: usa heading, text-editor, image, button, image-carousel… (spiral-mcp/list-elementor-widgets) dentro de section > column o container.', $at, $label, $type );
				}
			} elseif ( isset( $types['promo'][ $type ] ) ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): el widget "%s" es de Elementor Pro y Pro no está activo: Elementor lo descartaría al guardar.', $at, $label, $type );
			} else {
				$ctx['errors'][] = sprintf( 'En %s (%s): tipo "%s" no registrado en este sitio (¿widget de un plugin desactivado o de Elementor Pro?). Elementor lo descartaría al guardar; consulta spiral-mcp/list-elementor-widgets.', $at, $label, $type );
			}
			continue;
		}

		// Colocación.
		if ( null === $parent_type ) {
			if ( 'widget' === $el || 'column' === $el ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): en la raíz solo van "section" (con columns dentro) o "container"; los widgets van dentro de una column o un container.', $at, $label );
				continue;
			}
		} elseif ( 'section' === $parent_type ) {
			if ( 'column' !== $el ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): dentro de una "section" solo van "column"; pon el %s dentro de una column.', $at, $label, $label );
				continue;
			}
		} elseif ( 'column' === $parent_type ) {
			if ( 'column' === $el ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): una column no puede contener otra column; usa una section interna con sus columns.', $at, $label );
				continue;
			}
		} elseif ( 'container' === $parent_type ) {
			if ( 'section' === $el ) {
				$ctx['errors'][] = sprintf( 'En %s (%s): dentro de un container no va una section; usa containers anidados.', $at, $label );
				continue;
			}
			if ( 'column' === $el ) {
				$ctx['warnings'][] = sprintf( 'En %s (%s): una column dentro de un container no es una estructura normal de Elementor.', $at, $label );
			}
		}
		if ( in_array( $el, array( 'section', 'container' ), true ) ) {
			$node['isInner'] = null !== $parent_type;
		}

		// Ids.
		if ( empty( $node['id'] ) || ! is_scalar( $node['id'] ) ) {
			$node['id'] = spiral_mcp_elementor_new_id();
		} elseif ( isset( $ctx['seen'][ (string) $node['id'] ] ) ) {
			$old                = (string) $node['id'];
			$node['id']         = spiral_mcp_elementor_new_id();
			$ctx['warnings'][] = sprintf( 'En %s (%s): id "%s" repetido; se le asignó "%s".', $at, $label, $old, $node['id'] );
		}
		$node['id'] = (string) $node['id'];
		$ctx['seen'][ $node['id'] ] = true;

		// Settings frente a los controles del tipo.
		$info     = spiral_mcp_elementor_controls( $el, $type );
		$controls = $info ? $info['controls'] : array();
		if ( $controls ) {
			foreach ( $node['settings'] as $key => &$value ) {
				if ( ! spiral_mcp_elementor_known_setting( $key, $controls ) ) {
					$ctx['warnings'][] = sprintf( 'En %s (%s): ajuste desconocido "%s"; Elementor lo ignorará. Revisa spiral-mcp/get-elementor-widget-schema.', $at, $label, $key );
					continue;
				}
				$c = spiral_mcp_elementor_control_of( $key, $controls );
				if ( ! $c ) {
					continue;
				}
				$ctype = (string) ( $c['type'] ?? 'text' );
				if ( in_array( $ctype, array( 'select', 'choose' ), true ) && is_scalar( $value ) && '' !== (string) $value && empty( $c['multiple'] ) ) {
					$options = spiral_mcp_elementor_control_options( $c );
					if ( $options && ! array_key_exists( (string) $value, $options ) ) {
						$ctx['warnings'][] = sprintf( 'En %s (%s): "%s" = "%s" no está entre sus opciones (%s).', $at, $label, $key, $value, implode( ', ', array_slice( array_keys( $options ), 0, 20 ) ) );
					}
				} elseif ( 'switcher' === $ctype && is_scalar( $value ) && ! in_array( (string) $value, array( '', (string) ( $c['return_value'] ?? 'yes' ) ), true ) ) {
					$ctx['warnings'][] = sprintf( 'En %s (%s): "%s" es un interruptor: usa "%s" o "".', $at, $label, $key, (string) ( $c['return_value'] ?? 'yes' ) );
				} elseif ( in_array( $ctype, array( 'media', 'url', 'icons', 'slider', 'dimensions' ), true ) && ! is_array( $value ) && '' !== $value && null !== $value ) {
					$ctx['warnings'][] = sprintf( 'En %s (%s): "%s" espera un objeto: %s.', $at, $label, $key, spiral_mcp_elementor_control_hint( $c ) );
				} elseif ( in_array( $ctype, array( 'gallery', 'repeater' ), true ) && ! is_array( $value ) && '' !== $value && null !== $value ) {
					$ctx['warnings'][] = sprintf( 'En %s (%s): "%s" espera una lista: %s.', $at, $label, $key, spiral_mcp_elementor_control_hint( $c ) );
				} elseif ( 'repeater' === $ctype && is_array( $value ) ) {
					$fields = is_array( $c['fields'] ?? null ) ? $c['fields'] : array();
					foreach ( $value as $r => &$row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						if ( empty( $row['_id'] ) ) {
							$row['_id'] = spiral_mcp_elementor_new_id();
						}
						if ( $fields ) {
							foreach ( array_keys( $row ) as $fkey ) {
								if ( '_id' !== $fkey && ! spiral_mcp_elementor_known_setting( $fkey, $fields ) ) {
									$ctx['warnings'][] = sprintf( 'En %s (%s): fila %d de "%s": campo desconocido "%s".', $at, $label, $r, $key, $fkey );
								}
							}
						}
					}
					unset( $row );
				}
			}
			unset( $value );
		}

		// HTML que el usuario no puede guardar: Elementor lo filtrará con kses al guardar.
		if ( ! $ctx['unfiltered'] && $node['settings'] ) {
			$changed = spiral_mcp_sanitize_props( $node['settings'] );
			if ( $changed ) {
				if ( 'html' === $type ) {
					$ctx['html_errors'][] = sprintf( 'En %s (widget:html): "%s" lleva HTML que tu usuario no puede guardar (script, iframe, atributos on*, javascript:…). Elementor aplica wp_kses_post a todo lo que guarda un usuario sin unfiltered_html; para texto o imágenes usa text-editor, heading o image.', $at, implode( '", "', $changed ) );
				} else {
					$ctx['warnings'][] = sprintf( 'En %s (%s): se ha quitado HTML no permitido de "%s" (script, iframe, atributos on*, javascript:…) porque tu usuario no tiene unfiltered_html; Elementor haría lo mismo al guardar.', $at, $label, implode( '", "', $changed ) );
				}
			}
		}

		// Hijos.
		if ( $node['elements'] ) {
			spiral_mcp_elementor_check_list( $node['elements'], $at . '/elements', $el, $ctx );
		}
		if ( 'section' === $el ) {
			$columns = array_filter( $node['elements'], function ( $c ) {
				return is_array( $c ) && 'column' === ( $c['elType'] ?? '' );
			} );
			if ( ! $columns ) {
				$ctx['warnings'][] = sprintf( 'En %s (section): no tiene columns, así que no se verá nada; añade una column con los widgets.', $at );
			} else {
				$missing = 0;
				foreach ( $node['elements'] as &$col ) {
					if ( is_array( $col ) && 'column' === ( $col['elType'] ?? '' ) && empty( $col['settings']['_column_size'] ) ) {
						$col['settings']['_column_size'] = (int) round( 100 / count( $columns ) );
						$missing++;
					}
				}
				unset( $col );
				if ( $missing ) {
					$ctx['warnings'][] = sprintf( 'En %s (section): %d column(s) sin "_column_size"; se repartió el ancho a partes iguales (%d%%).', $at, $missing, (int) round( 100 / count( $columns ) ) );
				}
			}
		}
	}
	unset( $node );
}

/**
 * Valida y normaliza el árbol antes de guardarlo. Errores (bloquean): estructura, tipos no
 * registrados, V4 nuevos, widgets de Pro sin Pro, colocación. Avisos: ajustes desconocidos,
 * valores fuera de opciones, HTML filtrado. Devuelve la lista de avisos o WP_Error.
 */
function spiral_mcp_elementor_check( array &$tree, array $original ) {
	$ctx = array(
		'types'       => spiral_mcp_elementor_types(),
		'orig'        => array(),
		'seen'        => array(),
		'errors'      => array(),
		'html_errors' => array(),
		'warnings'    => array(),
		'unfiltered'  => current_user_can( 'unfiltered_html' ),
	);
	spiral_mcp_elementor_walk( $original, function ( &$node ) use ( &$ctx ) {
		if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
			$ctx['orig'][ (string) $node['id'] ] = spiral_mcp_elementor_type_key( $node );
		}
	} );
	if ( ! spiral_mcp_is_list( $tree ) ) {
		return new WP_Error( 'spiral_mcp_invalid_elementor_data', 'El árbol debe ser una lista de secciones o containers (el contenido del meta _elementor_data), no un objeto.' );
	}
	$tree = array_values( $tree );
	spiral_mcp_elementor_check_list( $tree, '', null, $ctx );
	if ( $ctx['html_errors'] ) {
		return new WP_Error( 'spiral_mcp_unfiltered_html', 'No se ha guardado nada. ' . implode( ' ', array_slice( $ctx['html_errors'], 0, 10 ) ), array( 'status' => 403 ) );
	}
	if ( $ctx['errors'] ) {
		return new WP_Error( 'spiral_mcp_invalid_elementor_data', 'El árbol de Elementor no es válido; no se ha guardado nada. ' . implode( ' ', array_slice( $ctx['errors'], 0, 10 ) ) );
	}
	$warnings = $ctx['warnings'];
	if ( count( $warnings ) > 50 ) {
		$extra      = count( $warnings ) - 50;
		$warnings   = array_slice( $warnings, 0, 50 );
		$warnings[] = sprintf( '… y %d avisos más.', $extra );
	}
	return $warnings;
}

/* -------------------------------------------------------------------------
 * Resumen del árbol: outline, extractos y textos
 * ---------------------------------------------------------------------- */

/** Extracto legible de un nodo: su texto, título o imagen. */
function spiral_mcp_elementor_excerpt( array $node ) {
	$s = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
	foreach ( array( 'title', 'editor', 'text', 'html', 'description', 'caption', 'content', 'alert_title', 'description_text', 'title_text', 'button_text', 'heading' ) as $key ) {
		if ( ! empty( $s[ $key ] ) && is_string( $s[ $key ] ) ) {
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s[ $key ] ) ) );
			if ( '' !== $text ) {
				return mb_strimwidth( $text, 0, 80, '…' );
			}
		}
	}
	foreach ( array( 'image', 'video', 'background_image' ) as $key ) {
		if ( ! empty( $s[ $key ]['url'] ) && is_string( $s[ $key ]['url'] ) ) {
			return $key . ': ' . wp_basename( $s[ $key ]['url'] );
		}
	}
	foreach ( array( 'carousel', 'gallery', 'wp_gallery', 'slides', 'icon_list', 'tabs', 'items' ) as $key ) {
		if ( ! empty( $s[ $key ] ) && is_array( $s[ $key ] ) ) {
			return sprintf( '%s: %d elementos', $key, count( $s[ $key ] ) );
		}
	}
	if ( ! empty( $s['link']['url'] ) ) {
		return 'link: ' . $s['link']['url'];
	}
	return '';
}

/** Una línea por nodo: sangría, ruta, tipo, #id, "nombre" y extracto. */
function spiral_mcp_elementor_outline( array $tree, $max = 1000 ) {
	$lines  = array();
	$types  = spiral_mcp_elementor_types();
	$has_v4 = false;
	$unreg  = false;
	spiral_mcp_elementor_walk( $tree, function ( &$node, $path, $parent, $depth ) use ( &$lines, &$has_v4, &$unreg, $types, $max ) {
		if ( count( $lines ) >= $max ) {
			return false;
		}
		$type  = spiral_mcp_elementor_type_key( $node );
		$flags = array();
		if ( isset( $types['atomic'][ $type ] ) ) {
			$flags[] = '[V4]';
			$has_v4  = true;
		} elseif ( ! spiral_mcp_elementor_instance( (string) ( $node['elType'] ?? '' ), $type ) ) {
			$flags[] = isset( $types['promo'][ $type ] ) ? '[necesita Elementor Pro]' : '[tipo no registrado]';
			$unreg   = true;
		}
		if ( ! empty( $node['settings']['__dynamic__'] ) ) {
			$flags[] = '[dinámico]';
		}
		$name    = isset( $node['settings']['_title'] ) && is_string( $node['settings']['_title'] ) && '' !== $node['settings']['_title'] ? ' "' . $node['settings']['_title'] . '"' : '';
		$excerpt = spiral_mcp_elementor_excerpt( $node );
		$lines[] = str_repeat( '  ', $depth ) . $path . '  ' . spiral_mcp_elementor_label( $node ) . ' #' . (string) ( $node['id'] ?? '?' ) . $name . ( $flags ? ' ' . implode( ' ', $flags ) : '' ) . ( '' !== $excerpt ? ' — ' . $excerpt : '' );
	} );
	return array( 'lines' => $lines, 'has_v4' => $has_v4, 'has_unregistered' => $unreg );
}

/** Clase de un control para get-elementor-texts: text, link, image o null. */
function spiral_mcp_elementor_text_kind( array $c ) {
	switch ( (string) ( $c['type'] ?? '' ) ) {
		case 'text':
		case 'textarea':
		case 'wysiwyg':
		case 'code':
			return 'text';
		case 'url':
			return 'link';
		case 'media':
		case 'gallery':
			return 'image';
	}
	return null;
}

/** Textos, enlaces e imágenes de un conjunto de settings según sus controles. */
function spiral_mcp_elementor_collect_texts( array $settings, array $controls, $base, array $kinds, array $node_info, array &$out, $max ) {
	$skip    = array( '_css_classes', '_element_id', 'button_css_id', 'css_id', 'custom_attributes', 'anchor', '_title' );
	$dynamic = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? $settings['__dynamic__'] : array();
	foreach ( $settings as $key => $value ) {
		if ( count( $out ) >= $max ) {
			return;
		}
		if ( in_array( $key, $skip, true ) || in_array( $key, SPIRAL_MCP_ELEMENTOR_INTERNAL_KEYS, true ) ) {
			continue;
		}
		$c = spiral_mcp_elementor_control_of( $key, $controls );
		if ( ! $c ) {
			continue;
		}
		$ctype = (string) ( $c['type'] ?? '' );
		if ( 'repeater' === $ctype && is_array( $value ) ) {
			$fields = is_array( $c['fields'] ?? null ) ? $c['fields'] : array();
			foreach ( $value as $r => $row ) {
				if ( is_array( $row ) ) {
					spiral_mcp_elementor_collect_texts( $row, $fields, $base . '/' . $key . '/' . $r, $kinds, $node_info, $out, $max );
				}
			}
			continue;
		}
		$kind = spiral_mcp_elementor_text_kind( $c );
		if ( ! $kind || ! in_array( $kind, $kinds, true ) ) {
			continue;
		}
		$items = array();
		if ( 'text' === $kind && is_string( $value ) && '' !== trim( $value ) ) {
			$items[] = array( $base . '/' . $key, $value );
		} elseif ( 'link' === $kind && is_array( $value ) && ! empty( $value['url'] ) ) {
			$items[] = array( $base . '/' . $key . '/url', (string) $value['url'] );
		} elseif ( 'image' === $kind && 'media' === $ctype && is_array( $value ) && ! empty( $value['url'] ) ) {
			$items[] = array( $base . '/' . $key . '/url', (string) $value['url'] );
		} elseif ( 'image' === $kind && 'gallery' === $ctype && is_array( $value ) ) {
			foreach ( $value as $g => $img ) {
				if ( is_array( $img ) && ! empty( $img['url'] ) ) {
					$items[] = array( $base . '/' . $key . '/' . $g . '/url', (string) $img['url'] );
				}
			}
		}
		if ( ! $items && isset( $dynamic[ $key ] ) ) {
			$items[] = array( $base . '/__dynamic__/' . $key, (string) $dynamic[ $key ] );
		}
		foreach ( $items as $item ) {
			$row = $node_info + array( 'setting' => $key, 'kind' => $kind, 'path' => $item[0], 'value' => $item[1] );
			if ( 'text' === $kind && false !== strpos( $item[1], '<' ) ) {
				$row['html'] = true;
			}
			if ( isset( $dynamic[ $key ] ) ) {
				$row['dynamic'] = true;
			}
			$out[] = $row;
		}
	}
}

/** Textos editables del árbol (o de un subárbol): items { path, element_id, element, setting, kind, value }. */
function spiral_mcp_elementor_texts( array $tree, $root_path, array $kinds, $max = 2000 ) {
	$out = array();
	spiral_mcp_elementor_walk( $tree, function ( &$node, $path ) use ( &$out, $root_path, $kinds, $max ) {
		if ( count( $out ) >= $max ) {
			return false;
		}
		$info = spiral_mcp_elementor_controls( (string) ( $node['elType'] ?? '' ), spiral_mcp_elementor_type_key( $node ) );
		if ( ! $info || empty( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
			return;
		}
		spiral_mcp_elementor_collect_texts( $node['settings'], $info['controls'], $root_path . $path . '/settings', $kinds, array(
			'element_id' => (string) ( $node['id'] ?? '' ),
			'element'    => spiral_mcp_elementor_label( $node ),
		), $out, $max );
	} );
	return $out;
}

/* -------------------------------------------------------------------------
 * Guardado, borrador, publicación y snapshot
 * ---------------------------------------------------------------------- */

/** Revisiones (sin autosaves) tras guardar: la recién creada y la anterior (para deshacer). */
function spiral_mcp_elementor_revision_info( $post_id ) {
	$ids = array();
	foreach ( wp_get_post_revisions( $post_id, array( 'posts_per_page' => 6, 'orderby' => 'ID', 'order' => 'DESC' ) ) as $rev ) {
		if ( ! wp_is_post_autosave( $rev ) ) {
			$ids[] = (int) $rev->ID;
		}
	}
	return array(
		'revision_id'      => $ids[0] ?? null,
		'undo_revision_id' => $ids[1] ?? null,
	);
}

/** Tras escribir: caché de elementos fuera, CSS de la página regenerado, caché de WordPress limpia. */
function spiral_mcp_elementor_after_write( $post_id, $rebuild_css = true ) {
	delete_post_meta( (int) $post_id, '_elementor_element_cache' );
	if ( $rebuild_css && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
		try {
			\Elementor\Core\Files\CSS\Post::create( (int) $post_id )->update();
		} catch ( Throwable $e ) {
			// Se regenera en la primera visita.
		}
	}
	clean_post_cache( (int) $post_id );
}

/**
 * Elementor y WordPress consideran "borrador" un autosave solo si su post_modified_gmt es
 * posterior al del post (comparación estricta). Si se guardó en el mismo segundo que el último
 * guardado del post, se adelanta un segundo para que cuente.
 */
function spiral_mcp_elementor_autosave_touch( $autosave_id, $post_id ) {
	global $wpdb;
	$autosave = get_post( (int) $autosave_id );
	$post     = get_post( (int) $post_id );
	if ( ! $autosave || ! $post ) {
		return;
	}
	$a = mysql2date( 'U', $autosave->post_modified_gmt, false );
	$p = mysql2date( 'U', $post->post_modified_gmt, false );
	if ( $a > $p ) {
		return;
	}
	$gmt   = gmdate( 'Y-m-d H:i:s', $p + 1 );
	$local = get_date_from_gmt( $gmt );
	$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $gmt, 'post_modified' => $local ), array( 'ID' => (int) $autosave_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	clean_post_cache( (int) $autosave_id );
}

/** Por qué Elementor no deja editar un documento al usuario actual (User::is_current_user_can_edit). */
function spiral_mcp_elementor_forbidden( WP_Post $post ) {
	$reasons = array();
	if ( 'trash' === $post->post_status ) {
		$reasons[] = 'está en la papelera';
	}
	$supported = (array) get_option( 'elementor_cpt_support', array( 'page', 'post' ) );
	if ( ! in_array( $post->post_type, $supported, true ) && 'elementor_library' !== $post->post_type ) {
		$reasons[] = sprintf( 'el tipo "%s" no tiene activado Elementor (Elementor > Ajustes > Tipos de contenido)', $post->post_type );
	}
	$user     = wp_get_current_user();
	$excluded = (array) get_option( 'elementor_exclude_user_roles', array() );
	if ( $user && array_intersect( (array) $user->roles, $excluded ) ) {
		$reasons[] = 'tu rol está excluido del editor de Elementor (Elementor > Ajustes > Avanzado)';
	}
	if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
		$reasons[] = 'es la página de entradas del blog, que Elementor no edita';
	}
	if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === $post->ID ) {
		$reasons[] = 'es la página de la tienda de WooCommerce, que Elementor no edita';
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		$reasons[] = 'no tienes permiso para editar este contenido';
	}
	return new WP_Error( 'spiral_mcp_elementor_forbidden', sprintf( 'Elementor no permite guardar el contenido %d con tu usuario: %s.', $post->ID, $reasons ? implode( '; ', $reasons ) : 'Document::is_editable_by_current_user() devuelve false' ), array( 'status' => 403 ) );
}

/**
 * Guarda el árbol con Document::save(). Directo (la web cambia, revisión de WordPress) o, con
 * "draft" en un contenido publicado, en un autosave de Elementor del usuario actual (la web
 * no cambia hasta spiral-mcp/publish-elementor-draft). Snapshot antes de escribir.
 */
function spiral_mcp_elementor_save( array $loaded, array $tree, array $opts ) {
	$post     = $loaded['post'];
	$doc      = $loaded['doc'];
	$draft    = ! empty( $opts['draft'] );
	$convert  = ! empty( $opts['convert'] );
	$warnings = array_merge( (array) ( $loaded['warnings'] ?? array() ), (array) ( $opts['warnings'] ?? array() ) );

	if ( ! $loaded['built'] ) {
		if ( ! $convert ) {
			return new WP_Error( 'spiral_mcp_not_elementor', sprintf( 'El contenido %d no es de Elementor. Pasa "convert": true para convertirlo (su contenido actual dejará de mostrarse; Elementor sustituye post_content por el texto plano del diseño).', $post->ID ) );
		}
		if ( 'elementor_library' === $post->post_type ) {
			return new WP_Error( 'spiral_mcp_elementor_type_unsupported', 'No se crean plantillas de la biblioteca de Elementor desde aquí: solo se editan las existentes.' );
		}
	}
	try {
		$editable = (bool) $doc->is_editable_by_current_user();
	} catch ( Throwable $e ) {
		$editable = false;
	}
	if ( ! $editable ) {
		return spiral_mcp_elementor_forbidden( $post );
	}
	if ( 'elementor_library' === $post->post_type ) {
		$tpl = (string) get_post_meta( $post->ID, '_elementor_template_type', true );
		if ( '' !== $tpl && $tpl !== $doc->get_name() ) {
			return new WP_Error( 'spiral_mcp_elementor_type_unsupported', sprintf( 'La plantilla %d es de tipo "%s" y este sitio no tiene registrado ese tipo de documento (¿necesita Elementor Pro activo?). Guardarla la convertiría en "%s" y perdería sus widgets.', $post->ID, $tpl, $doc->get_name() ) );
		}
	}

	$use_autosave = $draft && in_array( $post->post_status, array( 'publish', 'private' ), true );
	if ( $draft && ! $use_autosave ) {
		$warnings[] = sprintf( 'El contenido está en estado "%s" (no publicado): se guardó directamente, no hacía falta borrador.', $post->post_status );
	}
	if ( ! $use_autosave && ! empty( $loaded['autosave'] ) ) {
		$warnings[] = sprintf( 'Tenías un borrador de Elementor (autosave %d) que queda obsoleto frente a este guardado directo.', (int) $loaded['autosave']->get_post()->ID );
	}

	$snapshot = spiral_mcp_snapshot_capture( 'elementor_data', $post->ID, (string) ( $opts['note'] ?? '' ) );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}
	if ( $convert && ! $loaded['built'] ) {
		$doc->set_is_built_with_elementor( true );
	}
	$target = $doc;
	if ( $use_autosave ) {
		try {
			$target = $doc->get_autosave( 0, true );
		} catch ( Throwable $e ) {
			$target = null;
		}
		if ( ! $target ) {
			return new WP_Error( 'spiral_mcp_elementor_save_failed', 'Elementor no pudo crear el autosave del borrador.' );
		}
	}
	try {
		$ok = $target->save( array( 'elements' => $tree ) );
	} catch ( Throwable $e ) {
		return new WP_Error( 'spiral_mcp_elementor_save_failed', 'Elementor falló al guardar: ' . $e->getMessage() );
	}
	if ( ! $ok ) {
		return new WP_Error( 'spiral_mcp_elementor_save_failed', 'Elementor no guardó el documento (Document::save() devolvió false). Suele ser un permiso: ' . spiral_mcp_elementor_forbidden( $post )->get_error_message() );
	}
	$target_id = (int) $target->get_post()->ID;
	spiral_mcp_elementor_after_write( $target_id, ! $use_autosave );
	if ( $use_autosave ) {
		spiral_mcp_elementor_autosave_touch( $target_id, $post->ID );
		clean_post_cache( $post->ID );
	}
	if ( 'elementor_library' === $post->post_type && function_exists( 'spiral_mcp_cache_purge' ) ) {
		spiral_mcp_cache_purge( 'all' );
	}

	$result = array(
		'id'          => (int) $post->ID,
		'type'        => $post->post_type,
		'title'       => get_the_title( $post ),
		'updated'     => true,
		'draft'       => $use_autosave,
		'autosave_id' => $use_autosave ? $target_id : null,
	);
	if ( $use_autosave ) {
		$result += array( 'revision_id' => null, 'undo_revision_id' => null, 'live_untouched' => true );
		$result['next'] = 'Revisa con spiral-mcp/get-preview-url o spiral-mcp/inspect-page con "draft": true y publica con spiral-mcp/publish-elementor-draft.';
	} else {
		$result += spiral_mcp_elementor_revision_info( $post->ID );
		if ( ! wp_revisions_enabled( $post ) ) {
			$warnings[] = 'Las revisiones están desactivadas para este tipo de contenido: para deshacer usa restore-snapshot con snapshot_id.';
		}
	}
	if ( $convert && ! $loaded['built'] ) {
		$result['converted'] = true;
	}
	$result['snapshot_id'] = $snapshot;
	$result['warnings']    = array_values( $warnings );
	return $result;
}

/** Publica el borrador (autosave) de Elementor del usuario actual sobre el contenido publicado. */
function spiral_mcp_elementor_promote( array $loaded ) {
	$post = $loaded['post'];
	$doc  = $loaded['doc'];
	if ( ! function_exists( 'wp_get_post_autosave' ) ) {
		require_once ABSPATH . 'wp-includes/revision.php';
	}
	$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
	if ( ! $autosave ) {
		$other = wp_get_post_autosave( $post->ID );
		return new WP_Error( 'spiral_mcp_elementor_no_draft', $other
			? sprintf( 'No tienes ningún borrador de Elementor del contenido %d (los autosaves son por usuario; hay uno del usuario %d que no es tuyo).', $post->ID, (int) $other->post_author )
			: sprintf( 'No hay ningún borrador de Elementor del contenido %d: guárdalo antes con "draft": true o, si ya está publicado, no hay nada que publicar.', $post->ID ) );
	}
	if ( mysql2date( 'U', $autosave->post_modified_gmt, false ) <= mysql2date( 'U', $post->post_modified_gmt, false ) ) {
		return new WP_Error( 'spiral_mcp_elementor_stale_draft', sprintf( 'El borrador (autosave %d) es anterior al último guardado del contenido: alguien publicó después. Léelo con get-elementor-data {"draft": true}, compáralo con la versión publicada y rehaz el cambio.', (int) $autosave->ID ) );
	}
	try {
		$editable = (bool) $doc->is_editable_by_current_user();
	} catch ( Throwable $e ) {
		$editable = false;
	}
	if ( ! $editable ) {
		return spiral_mcp_elementor_forbidden( $post );
	}
	$snapshot = spiral_mcp_snapshot_capture( 'elementor_data', $post->ID, 'publish-elementor-draft' );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}
	try {
		$db = \Elementor\Plugin::$instance->db;
		$db->copy_elementor_meta( $autosave->ID, $post->ID, array( '_elementor_data', '_elementor_version' ) );
		// Como hace Elementor al guardar: fuerza la revisión aunque el texto plano no cambie.
		do_action( 'elementor/db/before_save', $post->post_status, true );
		$db->save_plain_text( $post->ID );
	} catch ( Throwable $e ) {
		return new WP_Error( 'spiral_mcp_elementor_save_failed', 'Elementor falló al publicar el borrador: ' . $e->getMessage() );
	}
	delete_post_meta( $post->ID, '_elementor_css' );
	spiral_mcp_elementor_after_write( $post->ID );
	wp_delete_post_revision( $autosave->ID );
	if ( 'elementor_library' === $post->post_type && function_exists( 'spiral_mcp_cache_purge' ) ) {
		spiral_mcp_cache_purge( 'all' );
	}
	return array(
		'id'          => (int) $post->ID,
		'promoted'    => true,
		'autosave_id' => (int) $autosave->ID,
	) + spiral_mcp_elementor_revision_info( $post->ID ) + array( 'snapshot_id' => $snapshot );
}

/** Snapshot kind elementor_data: los metas del diseño de un post. */
function spiral_mcp_elementor_snapshot_capture( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return new WP_Error( 'spiral_mcp_not_found', 'No existe ese contenido.' );
	}
	$meta = array();
	foreach ( SPIRAL_MCP_ELEMENTOR_META as $key ) {
		$meta[ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
	}
	return array( 'id' => (int) $post->ID, 'title' => $post->post_title, 'meta' => $meta );
}

function spiral_mcp_elementor_snapshot_restore( $post_id, $data ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return new WP_Error( 'spiral_mcp_not_found', sprintf( 'El contenido %d ya no existe; no se puede restaurar su diseño de Elementor.', (int) $post_id ) );
	}
	$meta = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();
	foreach ( SPIRAL_MCP_ELEMENTOR_META as $key ) {
		if ( ! array_key_exists( $key, $meta ) || null === $meta[ $key ] ) {
			delete_metadata( 'post', $post->ID, $key );
		} else {
			update_metadata( 'post', $post->ID, $key, wp_slash( $meta[ $key ] ) );
		}
	}
	delete_post_meta( $post->ID, '_elementor_css' );
	spiral_mcp_elementor_after_write( $post->ID, spiral_mcp_elementor_active() );
	return array( 'id' => (int) $post->ID, 'restored' => array_keys( $meta ) );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['elementor_data'] = array(
		'group'   => 'content',
		'cap'     => 'edit_posts',
		'capture' => 'spiral_mcp_elementor_snapshot_capture',
		'restore' => 'spiral_mcp_elementor_snapshot_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría y abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-elementor', array(
		'label'       => 'Elementor Spiral-MCP',
		'description' => 'Widgets clásicos de Elementor: leer el árbol de una página, cambiar ajustes, mover, borrar e insertar elementos, y publicar borradores.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {

	$can      = function ( $input = array() ) {
		return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
	};
	$can_list = function () {
		return current_user_can( 'edit_posts' );
	};
	$log      = array( 'type' => 'post', 'id' => 'id' );
	$target   = array(
		'id'    => array( 'type' => 'integer', 'description' => 'ID de la página, entrada o plantilla de la biblioteca (elementor_library).' ),
		'draft' => array( 'type' => 'boolean', 'default' => false, 'description' => 'true: lee tu borrador de Elementor (autosave) si lo hay en vez de la versión publicada.' ),
	);
	$head     = function ( array $loaded ) {
		return array(
			'id'            => (int) $loaded['post']->ID,
			'type'          => $loaded['post']->post_type,
			'title'         => get_the_title( $loaded['post'] ),
			'status'        => $loaded['post']->post_status,
			'template_type' => (string) get_post_meta( $loaded['post']->ID, '_elementor_template_type', true ),
			'draft'         => (bool) $loaded['draft'],
			'autosave_id'   => $loaded['autosave'] ? (int) $loaded['autosave']->get_post()->ID : null,
		);
	};
	$value    = array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) );

	spiral_mcp_register_ability( 'spiral-mcp/list-elementor-widgets', array(
		'requires'            => 'elementor',
		'label'               => 'Listar widgets de Elementor',
		'description'         => 'Lista los tipos clásicos de Elementor disponibles en este sitio: la estructura ("elements": section > column, container) y los widgets (heading, text-editor, image, button, image-carousel, video, icon-list…), con título, categorías y palabras clave. "requires_pro" son widgets de Elementor Pro que este sitio no tiene (no se pueden guardar). No incluye los elementos V4 (atómicos): este sitio se maqueta con widgets clásicos, que una persona puede seguir editando en el editor de Elementor. Después, spiral-mcp/get-elementor-widget-schema da los ajustes de cada uno.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'category' => array( 'type' => 'string', 'description' => 'Filtra por categoría (basic, general, pro-elements, theme-elements, woocommerce-elements…).' ),
				'search'   => array( 'type' => 'string', 'description' => 'Texto a buscar en nombre, título y palabras clave.' ),
			),
		),
		'permission_callback' => $can_list,
		'execute_callback'    => function ( $input = array() ) {
			$input    = (array) $input;
			$types    = spiral_mcp_elementor_types();
			$category = (string) ( $input['category'] ?? '' );
			$search   = mb_strtolower( (string) ( $input['search'] ?? '' ) );
			$widgets  = array();
			foreach ( $types['widgets'] as $name => $w ) {
				try {
					$row = array(
						'name'       => (string) $name,
						'title'      => wp_strip_all_tags( (string) $w->get_title() ),
						'categories' => array_values( (array) $w->get_categories() ),
						'keywords'   => array_values( (array) $w->get_keywords() ),
					);
				} catch ( Throwable $e ) {
					continue;
				}
				if ( '' !== $category && ! in_array( $category, $row['categories'], true ) ) {
					continue;
				}
				if ( '' !== $search && false === strpos( mb_strtolower( $name . ' ' . $row['title'] . ' ' . implode( ' ', $row['keywords'] ) ), $search ) ) {
					continue;
				}
				$widgets[] = $row;
			}
			usort( $widgets, function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			} );
			$elements = array();
			foreach ( array( 'section' => 'column', 'column' => 'widgets, section interna o container', 'container' => 'widgets o container' ) as $name => $children ) {
				if ( isset( $types['elements'][ $name ] ) ) {
					$elements[] = array( 'name' => $name, 'title' => wp_strip_all_tags( (string) $types['elements'][ $name ]->get_title() ), 'children' => $children );
				}
			}
			$atomic = false;
			try {
				$atomic = (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_atomic_elements' );
			} catch ( Throwable $e ) {
				$atomic = false;
			}
			return array(
				'version'           => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'pro'               => defined( 'ELEMENTOR_PRO_VERSION' ),
				'atomic_experiment' => $atomic,
				'elements'          => $elements,
				'widgets'           => $widgets,
				'total'             => count( $widgets ),
				'requires_pro'      => array_keys( $types['promo'] ),
				'notes'             => array(
					'Un widget va dentro de una column (que va dentro de una section) o de un container. Las sections de la raíz llevan "isInner": false.',
					'Los ids son 7 caracteres hexadecimales; si no los pones, se generan.',
				),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-elementor-widget-schema', array(
		'requires'            => 'elementor',
		'label'               => 'Esquema de un widget de Elementor',
		'description'         => 'Devuelve los ajustes ("settings") que admite un tipo clásico de Elementor (un widget como image-carousel, o section, column, container), agrupados por pestaña (content, style, advanced; en section/column/container, layout) y sección del panel. Para cada ajuste: tipo de control, etiqueta, valor por defecto, opciones (select/choose), si es responsive (admite variantes _tablet y _mobile), condición de visibilidad, si admite contenido dinámico y "hint" con la forma del valor (p. ej. media = {"id", "url"}, gallery = [{"id", "url"}], url = {"url", "is_external", "nofollow"}, switcher = "yes" o ""). Los repeaters (icon_list, slides, tabs…) traen "fields" y cada fila lleva "_id". Por defecto solo la pestaña content, que es la que cambia el contenido; pide "tabs": ["all"] para estilos y avanzado. Imágenes: sube con spiral-mcp/upload-media y usa su id y URL.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'type' ),
			'properties' => array(
				'type' => array( 'type' => 'string', 'description' => 'widgetType (heading, image-carousel…) o section, column, container.' ),
				'tabs' => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string', 'enum' => array( 'content', 'style', 'advanced', 'layout', 'all' ) ),
					'default' => array( 'content' ),
				),
			),
		),
		'permission_callback' => $can_list,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$type  = (string) ( $input['type'] ?? '' );
			$types = spiral_mcp_elementor_types();
			if ( isset( $types['elements'][ $type ] ) ) {
				$el_type = $type;
				$widget  = '';
			} else {
				$el_type = 'widget';
				$widget  = $type;
			}
			$instance = spiral_mcp_elementor_instance( $el_type, $widget );
			if ( ! $instance ) {
				if ( isset( $types['atomic'][ $type ] ) ) {
					return new WP_Error( 'spiral_mcp_unknown_element', sprintf( '"%s" es un elemento V4 (atómico), que no se crea desde aquí: usa los widgets clásicos de spiral-mcp/list-elementor-widgets.', $type ) );
				}
				if ( isset( $types['promo'][ $type ] ) ) {
					return new WP_Error( 'spiral_mcp_unknown_element', sprintf( '"%s" es un widget de Elementor Pro y este sitio no tiene Pro activo.', $type ) );
				}
				return new WP_Error( 'spiral_mcp_unknown_element', sprintf( 'No existe el tipo "%s". Consulta spiral-mcp/list-elementor-widgets.', $type ) );
			}
			$want = array_values( (array) ( $input['tabs'] ?? array( 'content' ) ) );
			$all  = in_array( 'all', $want, true );
			$info = spiral_mcp_elementor_controls( $el_type, $widget );
			$tabs = array();
			$sections = array();
			foreach ( $info['controls'] as $name => $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$tab = (string) ( $c['tab'] ?? 'content' );
				if ( 'section' === ( $c['type'] ?? '' ) ) {
					$sections[ $name ] = wp_strip_all_tags( (string) ( $c['label'] ?? $name ) );
					continue;
				}
				if ( ! $all && ! in_array( $tab, $want, true ) ) {
					continue;
				}
				if ( spiral_mcp_elementor_ui_control( $c['type'] ?? '' ) || ! empty( $c['parent'] ) ) {
					continue; // Controles de interfaz y variantes responsive (slides_to_show_tablet…).
				}
				$section = (string) ( $c['section'] ?? '' );
				if ( ! isset( $tabs[ $tab ][ $section ] ) ) {
					$tabs[ $tab ][ $section ] = array( 'label' => $sections[ $section ] ?? $section, 'controls' => array() );
				}
				$tabs[ $tab ][ $section ]['controls'][ (string) $name ] = spiral_mcp_elementor_control_format( (string) $name, $c );
			}
			foreach ( $tabs as $tab => &$list ) {
				foreach ( $list as $section => &$group ) {
					if ( '' === $group['label'] && isset( $sections[ $section ] ) ) {
						$group['label'] = $sections[ $section ];
					}
					$group['controls'] = (object) $group['controls'];
				}
				unset( $group );
				$list = (object) $list;
			}
			unset( $list );
			return array(
				'type'      => $type,
				'title'     => wp_strip_all_tags( (string) $instance->get_title() ),
				'is_widget' => 'widget' === $el_type,
				'tab_names' => (object) $info['tabs'],
				'tabs'      => (object) $tabs,
				'notes'     => array(
					'Un ajuste responsive admite variantes con sufijo _tablet y _mobile (p. ej. slides_to_show_tablet).',
					'Contenido dinámico: settings.__dynamic__ = {"ajuste": "[elementor-tag …]"}; consérvalo si no lo cambias.',
					'Los ajustes que no envíes toman su valor por defecto. "_title" es el nombre del elemento en el navegador del editor.',
				),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-elementor-data', array(
		'requires'            => 'elementor',
		'label'               => 'Leer el árbol de Elementor',
		'description'         => 'Devuelve el árbol completo de elementos de una página construida con Elementor (el contenido del meta _elementor_data): lista de nodos {id, elType, settings, elements, isInner; widgetType en los widgets}. Puede ser grande: para orientarte usa spiral-mcp/get-elementor-outline y para un solo elemento spiral-mcp/get-elementor-node; úsalo entero solo para reenviarlo con spiral-mcp/update-elementor-data. Con "draft": true devuelve tu borrador de Elementor (autosave) si existe.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $target ),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $head ) {
			$input  = (array) $input;
			$loaded = spiral_mcp_elementor_load( $input['id'] ?? 0, ! empty( $input['draft'] ) );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			return $head( $loaded ) + array(
				'elements' => $loaded['elements'],
				'warnings' => $loaded['warnings'],
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-elementor-outline', array(
		'requires'            => 'elementor',
		'label'               => 'Esquema del árbol de Elementor',
		'description'         => 'Resume el árbol de una página de Elementor en una línea por elemento: ruta (JSON Pointer, p. ej. /0/elements/1/elements/2), tipo (section, column, container, widget:heading…), #id, el nombre que le dio el diseñador y un extracto de su contenido. Marca [V4] los elementos atómicos (no se tocan desde aquí), [necesita Elementor Pro] y [tipo no registrado] los que Elementor descartaría al guardar, y [dinámico] los que toman contenido de una fuente dinámica. Es el primer paso para editar: con los ids haces spiral-mcp/patch-elementor-data (update, remove, move, insert, duplicate) y con las rutas, operaciones JSON Patch.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $target ),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $head ) {
			$input  = (array) $input;
			$loaded = spiral_mcp_elementor_load( $input['id'] ?? 0, ! empty( $input['draft'] ) );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$outline = spiral_mcp_elementor_outline( $loaded['elements'] );
			$nodes   = count( spiral_mcp_elementor_ids( $loaded['elements'] ) );
			$warn    = $loaded['warnings'];
			if ( $outline['has_v4'] ) {
				$warn[] = 'La página tiene elementos V4 (atómicos): se conservan tal cual; edítalos con elementor/manage-elements si el MCP de Elementor está activo.';
			}
			if ( $outline['has_unregistered'] ) {
				$warn[] = 'Hay elementos de tipos no registrados en este sitio: cualquier guardado fallará mientras sigan en el árbol (quítalos con una operación remove si el usuario lo acepta, o pídele que active el plugin que los aporta).';
			}
			if ( ! $loaded['elements'] ) {
				$warn[] = 'La página no tiene elementos todavía.';
			}
			return $head( $loaded ) + array(
				'nodes'            => $nodes,
				'outline'          => $outline['lines'],
				'has_v4'           => $outline['has_v4'],
				'has_unregistered' => $outline['has_unregistered'],
				'warnings'         => $warn,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-elementor-texts', array(
		'requires'            => 'elementor',
		'label'               => 'Textos de una página de Elementor',
		'description'         => 'Lista los textos editables de una página de Elementor (y, si lo pides, los enlaces e imágenes) con la ruta exacta de cada uno: {path, element_id, element, setting, kind, value, html, dynamic}. Para cambiar uno: spiral-mcp/patch-elementor-data con {"op": "replace", "path": <path>, "value": "nuevo texto"} o {"op": "update", "element_id": …, "settings": {setting: valor}}. "html": true si el valor lleva etiquetas (consérvalas); "dynamic": true si viene de una fuente dinámica (no lo sustituyas por texto fijo sin preguntar). "element_id" limita la lista a un elemento y sus hijos.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => $target + array(
				'element_id' => array( 'type' => 'string', 'description' => 'Solo ese elemento y sus descendientes.' ),
				'kinds'      => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string', 'enum' => array( 'text', 'link', 'image' ) ),
					'default' => array( 'text' ),
				),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $head ) {
			$input  = (array) $input;
			$loaded = spiral_mcp_elementor_load( $input['id'] ?? 0, ! empty( $input['draft'] ) );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$kinds = array_values( array_intersect( (array) ( $input['kinds'] ?? array( 'text' ) ), array( 'text', 'link', 'image' ) ) );
			if ( ! $kinds ) {
				$kinds = array( 'text' );
			}
			$tree = $loaded['elements'];
			if ( ! empty( $input['element_id'] ) ) {
				$found = spiral_mcp_elementor_find( $tree, (string) $input['element_id'] );
				if ( ! $found ) {
					return new WP_Error( 'spiral_mcp_element_not_found', sprintf( 'No hay ningún elemento con id "%s". Consulta spiral-mcp/get-elementor-outline.', (string) $input['element_id'] ) );
				}
				// Se recorre solo ese nodo (ruta /0) y se recoloca su ruta real en los items.
				$items = spiral_mcp_elementor_texts( array( $found['node'] ), '', $kinds );
				foreach ( $items as &$item ) {
					$item['path'] = $found['path'] . substr( $item['path'], 2 );
				}
				unset( $item );
			} else {
				$items = spiral_mcp_elementor_texts( $tree, '', $kinds );
			}
			return $head( $loaded ) + array(
				'total'    => count( $items ),
				'items'    => $items,
				'warnings' => $loaded['warnings'],
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-elementor-node', array(
		'requires'            => 'elementor',
		'label'               => 'Leer un elemento de Elementor',
		'description'         => 'Devuelve un elemento de una página de Elementor con todos sus ajustes e hijos, por "element_id" (de spiral-mcp/get-elementor-outline) o por "path" (JSON Pointer; también vale una ruta interior como /0/elements/0/elements/1/settings). Úsalo para ver los settings reales antes de un update y para copiar un nodo existente como plantilla de uno nuevo.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => $target + array(
				'element_id' => array( 'type' => 'string' ),
				'path'       => array( 'type' => 'string', 'description' => 'JSON Pointer, p. ej. /0/elements/1.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $head ) {
			$input  = (array) $input;
			$loaded = spiral_mcp_elementor_load( $input['id'] ?? 0, ! empty( $input['draft'] ) );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			if ( ! empty( $input['element_id'] ) ) {
				$found = spiral_mcp_elementor_find( $loaded['elements'], (string) $input['element_id'] );
				if ( ! $found ) {
					return new WP_Error( 'spiral_mcp_element_not_found', sprintf( 'No hay ningún elemento con id "%s". Consulta spiral-mcp/get-elementor-outline.', (string) $input['element_id'] ) );
				}
				$path = $found['path'];
				$node = $found['node'];
			} elseif ( isset( $input['path'] ) && '' !== (string) $input['path'] ) {
				$path = (string) $input['path'];
				$node = spiral_mcp_pointer_get( $loaded['elements'], $path, 'spiral-mcp/get-elementor-outline' );
				if ( is_wp_error( $node ) ) {
					return $node;
				}
			} else {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "element_id" o "path".' );
			}
			return $head( $loaded ) + array(
				'path'       => $path,
				'element_id' => is_array( $node ) && isset( $node['id'] ) ? (string) $node['id'] : null,
				'node'       => $node,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	$write_props = array(
		'draft' => array( 'type' => 'boolean', 'default' => false, 'description' => 'true en un contenido publicado: guarda en tu borrador de Elementor (autosave) y la web no cambia hasta spiral-mcp/publish-elementor-draft. false (por defecto): la web cambia al momento (con revisión de WordPress para deshacer).' ),
		'note'  => array( 'type' => 'string', 'description' => 'Nota para el snapshot y la auditoría.' ),
	);

	spiral_mcp_register_ability( 'spiral-mcp/patch-elementor-data', array(
		'requires'            => 'elementor',
		'label'               => 'Modificar elementos de Elementor',
		'description'         => 'Aplica cambios al árbol de una página construida con widgets clásicos de Elementor sin reenviarlo entero. Primero lee spiral-mcp/get-elementor-outline (ids y rutas) y, si vas a tocar ajustes, spiral-mcp/get-elementor-node o get-elementor-widget-schema. Operaciones por id de elemento: {"op":"update","element_id":"a1b2c3d","settings":{"title":"Nuevo"}} (mezcla; null borra un ajuste), {"op":"replace_settings",…} (sustituye todos los ajustes), {"op":"remove","element_id":…}, {"op":"duplicate","element_id":…} (copia justo detrás, ids nuevos), {"op":"move","element_id":…,"parent_id":"<id de column/container>" o "document","index":0} y {"op":"insert","parent_id":…,"index":2,"node":{"elType":"widget","widgetType":"image-carousel","settings":{…}}} (un nodo o un subárbol: section con columns y widgets; los ids que falten se generan). Operaciones por ruta (JSON Patch): add, remove, replace, move, copy, test, p. ej. {"op":"replace","path":"/0/elements/0/elements/1/settings/title","value":"Nuevo"}; {"op":"test",…} falla si el valor cambió desde que lo leíste. Se evalúan en orden sobre el resultado de las anteriores; atómico: si una falla no se guarda nada. Antes de guardar valida: tipos registrados (los V4 nuevos, los de Pro sin Pro y los desconocidos bloquean), colocación (widgets dentro de column o container; columns dentro de section), ajustes conocidos (avisos), y HTML (sin unfiltered_html Elementor filtra con kses; un widget html con script se rechaza). Guarda con Document::save() de Elementor: revisión de WordPress (revision_id / undo_revision_id para spiral-mcp/restore-revision), snapshot (snapshot_id), CSS regenerado y caché purgada. Con "draft": true en un contenido publicado la web no cambia hasta publish-elementor-draft; sin él, cambia al momento. Devuelve "new_ids" de insert/duplicate y "warnings": corrígelos o explícalos. En una plantilla de la biblioteca (elementor_library) el cambio se ve en todas las páginas que la usan.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'log'                 => $log,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'operations' ),
			'properties' => array(
				'id'         => $target['id'],
				'operations' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'op' ),
						'properties' => array(
							'op'         => array( 'type' => 'string', 'enum' => array( 'update', 'replace_settings', 'remove', 'duplicate', 'move', 'insert', 'add', 'replace', 'copy', 'test' ) ),
							'element_id' => array( 'type' => 'string' ),
							'parent_id'  => array( 'type' => 'string', 'description' => 'Para move e insert: id de una column, container o section, o "document" (raíz).' ),
							'index'      => array( 'type' => array( 'integer', 'null' ), 'description' => 'Posición entre los hijos del padre; sin él, al final.' ),
							'settings'   => array( 'type' => 'object' ),
							'node'       => array( 'type' => 'object', 'description' => 'Para insert: {elType, widgetType?, settings, elements?}.' ),
							'path'       => array( 'type' => 'string' ),
							'from'       => array( 'type' => 'string' ),
							'value'      => $value,
						),
					),
				),
			) + $write_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input  = (array) $input;
			$draft  = ! empty( $input['draft'] );
			$loaded = spiral_mcp_elementor_load( $input['id'] ?? 0, $draft, false, true );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$warnings = array();
			$applied  = spiral_mcp_elementor_apply_ops( $loaded['elements'], (array) ( $input['operations'] ?? array() ), $warnings );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			list( $tree, $new_ids ) = $applied;
			$check = spiral_mcp_elementor_check( $tree, $loaded['elements'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$result = spiral_mcp_elementor_save( $loaded, $tree, array(
				'draft'    => $draft,
				'note'     => (string) ( $input['note'] ?? 'patch-elementor-data' ),
				'warnings' => array_merge( $warnings, $check ),
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'operations' => count( (array) $input['operations'] ), 'new_ids' => (object) $new_ids ) + $result;
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-elementor-data', array(
		'requires'            => 'elementor',
		'label'               => 'Reemplazar el árbol de Elementor',
		'description'         => 'Sustituye el árbol de elementos completo de una página de Elementor por "elements" (lista de sections o containers; el mismo formato que devuelve spiral-mcp/get-elementor-data). Para cambios parciales usa spiral-mcp/patch-elementor-data. Con "convert": true convierte en página de Elementor un contenido que no lo era (p. ej. recién creado con spiral-mcp/create-content en borrador): su contenido anterior deja de mostrarse. Misma validación, snapshot, revisión y opción "draft" que patch-elementor-data. No crea plantillas de la biblioteca.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'log'                 => $log,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'elements' ),
			'properties' => array(
				'id'       => $target['id'],
				'elements' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'convert'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'true para convertir en página de Elementor un contenido que no lo es.' ),
			) + $write_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input   = (array) $input;
			$draft   = ! empty( $input['draft'] );
			$convert = ! empty( $input['convert'] );
			$loaded  = spiral_mcp_elementor_load( $input['id'] ?? 0, $draft, $convert, true );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$tree  = (array) ( $input['elements'] ?? array() );
			$check = spiral_mcp_elementor_check( $tree, $loaded['elements'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			return spiral_mcp_elementor_save( $loaded, $tree, array(
				'draft'    => $draft,
				'convert'  => $convert,
				'note'     => (string) ( $input['note'] ?? 'update-elementor-data' ),
				'warnings' => $check,
			) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/publish-elementor-draft', array(
		'requires'            => 'elementor',
		'label'               => 'Publicar un borrador de Elementor',
		'description'         => 'Publica tu borrador de Elementor (el autosave que dejó patch-elementor-data o update-elementor-data con "draft": true) sobre la versión en vivo del contenido: a partir de ahí la web muestra el cambio. Crea una revisión de WordPress (undo_revision_id para spiral-mcp/restore-revision) y un snapshot. Falla si no hay borrador tuyo o si alguien guardó la página después de crearlo (spiral_mcp_elementor_stale_draft: léelo con get-elementor-data {"draft": true} y rehaz el cambio). Antes, revísalo con spiral-mcp/inspect-page {"id": …, "draft": true}.',
		'category'            => 'spiral-mcp-elementor',
		'group'               => array( 'content', 'design' ),
		'log'                 => $log,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => $target['id'] ),
		),
		'permission_callback' => function ( $input = array() ) {
			$id   = (int) ( ( (array) $input )['id'] ?? 0 );
			$post = $id ? get_post( $id ) : null;
			$type = $post ? get_post_type_object( $post->post_type ) : null;
			return $post && current_user_can( 'edit_post', $id ) && ( ! $type || current_user_can( $type->cap->publish_posts ) );
		},
		'execute_callback'    => function ( $input ) {
			$loaded = spiral_mcp_elementor_load( ( (array) $input )['id'] ?? 0 );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			return spiral_mcp_elementor_promote( $loaded );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
