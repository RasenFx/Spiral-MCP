<?php
/**
 * Abilities del tema: builder del footer (y demás áreas de YOOtheme guardadas
 * en la configuración del tema) y widgets.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers: configuración de YOOtheme
 * ---------------------------------------------------------------------- */

/**
 * Lee la configuración de YOOtheme. YOOtheme Pro la guarda como JSON en el
 * theme mod "config" del tema activo.
 *
 * @return array|WP_Error [ 'config' => array, 'raw' => mixed ]
 */
function spiral_mcp_theme_config_read() {
	$raw = get_theme_mod( 'config', null );
	if ( null === $raw || '' === $raw ) {
		return new WP_Error( 'spiral_mcp_no_config', 'No se encontró la configuración de YOOtheme (theme mod "config"). ¿Está activo YOOtheme Pro o un tema hijo de YOOtheme?' );
	}
	$config = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
	if ( ! is_array( $config ) ) {
		return new WP_Error( 'spiral_mcp_bad_config', 'La configuración de YOOtheme no es un JSON válido.' );
	}
	return array( 'config' => $config, 'raw' => $raw );
}


/**
 * Escribe la configuración respetando el formato original (string JSON o
 * array). Antes pasa por el filtro config.save de YOOtheme, como hace el
 * Personalizador al guardar (normaliza los layouts de los mega menús).
 */
function spiral_mcp_theme_config_write( array $config, $original_raw ) {
	// config.save de YOOtheme reescribe nav_menu_locations a partir de config.menu.positions.<ubicación>.menu,
	// que en la BD puede estar desfasado (YOOtheme lo rellena en memoria al cargar). Sin esto, guardar la
	// configuración quitaría a los menús las ubicaciones asignadas con create-menu o update-menu.
	$locations = get_nav_menu_locations();
	foreach ( array_keys( get_registered_nav_menus() ) as $name ) {
		$config['menu']['positions'][ $name ]['menu'] = empty( $locations[ $name ] ) ? '' : $locations[ $name ];
	}
	if ( class_exists( 'YOOtheme\Event' ) ) {
		try {
			$filtered = \YOOtheme\Event::emit( 'config.save|filter', $config );
			if ( is_array( $filtered ) ) {
				$config = $filtered;
			}
		} catch ( Throwable $e ) {
			// Si YOOtheme falla aquí se guarda tal cual.
		}
	}
	$value = is_string( $original_raw ) ? spiral_mcp_json( $config ) : $config;
	if ( false === $value ) {
		return new WP_Error( 'spiral_mcp_bad_config', 'No se pudo serializar la configuración.' );
	}
	set_theme_mod( 'config', $value );
	return true;
}

/**
 * Áreas simples de la configuración con builder propio (config.<área>.content).
 * Ajustable con el filtro spiral_mcp_theme_builder_areas.
 */
function spiral_mcp_theme_builder_areas() {
	return (array) apply_filters( 'spiral_mcp_theme_builder_areas', array( 'footer' ) );
}

/**
 * Interpreta el nombre de un área con builder:
 * - "footer" (y las de spiral_mcp_theme_builder_areas): config.<área>.content, raíz "layout".
 * - "menu_item:<ID>": contenido de builder (mega menú) de un elemento de menú, raíz "fragment".
 * - "widget:<ID del widget>": widget de builder de YOOtheme (header, navbar, top, bottom…), raíz "fragment".
 */
function spiral_mcp_theme_area_parse( $area ) {
	$area = (string) $area;
	if ( in_array( $area, spiral_mcp_theme_builder_areas(), true ) ) {
		return array( 'area' => $area, 'kind' => 'config', 'path' => array( $area, 'content' ), 'root' => 'layout' );
	}
	if ( preg_match( '/^menu_item:(\d+)$/', $area, $m ) ) {
		if ( ! is_nav_menu_item( (int) $m[1] ) ) {
			return new WP_Error( 'spiral_mcp_item_not_found', sprintf( 'No existe el elemento de menú %d (ver spiral-mcp/get-menu).', $m[1] ) );
		}
		return array( 'area' => $area, 'kind' => 'config', 'path' => array( 'menu', 'items', $m[1], 'content' ), 'root' => 'fragment' );
	}
	if ( preg_match( '/^widget:(.+-\d+)$/', $area, $m ) ) {
		$data = spiral_mcp_widget_instance( $m[1] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( 'builderwidget' !== $data[0] ) {
			return new WP_Error( 'spiral_mcp_bad_area', sprintf( 'El widget "%s" no es un widget de builder de YOOtheme (builderwidget-N).', $m[1] ) );
		}
		return array( 'area' => $area, 'kind' => 'widget', 'widget_id' => $m[1], 'root' => 'fragment' );
	}
	if ( preg_match( '/^template:([\w-]+)$/', $area, $m ) ) {
		$tpl = spiral_mcp_template_get( $m[1] );
		if ( is_wp_error( $tpl ) ) {
			return $tpl;
		}
		return array( 'area' => $area, 'kind' => 'template', 'template_id' => $m[1], 'root' => 'layout' );
	}
	return new WP_Error( 'spiral_mcp_bad_area', sprintf( 'Área "%s" no válida. Usa %s, "menu_item:<ID>", "widget:<ID>" o "template:<id>" (lista completa en spiral-mcp/get-theme-layout).', $area, implode( ', ', spiral_mcp_theme_builder_areas() ) ) );
}

/** Lee un valor anidado de un array por claves. */
function spiral_mcp_array_get( array $data, array $path ) {
	foreach ( $path as $key ) {
		if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
			return null;
		}
		$data = $data[ $key ];
	}
	return $data;
}

/** Decodifica un layout guardado como array o como JSON en texto. */
function spiral_mcp_decode_layout( $value ) {
	if ( is_string( $value ) && '' !== $value ) {
		$value = json_decode( $value, true );
	}
	return is_array( $value ) && isset( $value['type'] ) ? $value : null;
}

/**
 * Layout y ajustes de un área. Devuelve [ 'area', 'root', 'layout' (o null),
 * 'settings' ] o WP_Error.
 */
function spiral_mcp_theme_area_get( $area ) {
	$info = spiral_mcp_theme_area_parse( $area );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	if ( 'template' === $info['kind'] ) {
		$tpl = spiral_mcp_template_get( $info['template_id'] );
		return array(
			'area'     => $info['area'],
			'root'     => $info['root'],
			'layout'   => spiral_mcp_decode_layout( $tpl['layout'] ?? null ),
			'settings' => array_diff_key( $tpl, array( 'layout' => 1 ) ),
		);
	}
	if ( 'widget' === $info['kind'] ) {
		$instance = spiral_mcp_widget_instance( $info['widget_id'] )[3];
		return array(
			'area'     => $info['area'],
			'root'     => $info['root'],
			'layout'   => spiral_mcp_decode_layout( $instance['content'] ?? null ),
			'settings' => array_diff_key( $instance, array( 'content' => 1 ) ),
		);
	}
	$read = spiral_mcp_theme_config_read();
	if ( is_wp_error( $read ) ) {
		return $read;
	}
	$parent = spiral_mcp_array_get( $read['config'], array_slice( $info['path'], 0, -1 ) );
	return array(
		'area'     => $info['area'],
		'root'     => $info['root'],
		'layout'   => spiral_mcp_decode_layout( is_array( $parent ) ? ( $parent['content'] ?? null ) : null ),
		'settings' => is_array( $parent ) ? array_diff_key( $parent, array( 'content' => 1 ) ) : array(),
	);
}

/**
 * Guarda el layout de un área. Antes hace snapshot (de toda la configuración
 * del tema o del widget). Devuelve el ID del snapshot o WP_Error.
 */
function spiral_mcp_theme_area_save( $area, array $layout, $note ) {
	$info = spiral_mcp_theme_area_parse( $area );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	if ( ( $layout['type'] ?? '' ) !== $info['root'] ) {
		return new WP_Error( 'spiral_mcp_bad_layout', sprintf( 'El área "%s" necesita un layout con "type": "%s" en la raíz.', $info['area'], $info['root'] ) );
	}
	$layout = spiral_mcp_layout_stamp_version( $layout );

	if ( 'template' === $info['kind'] ) {
		$tpl           = spiral_mcp_template_get( $info['template_id'] );
		$tpl['layout'] = spiral_mcp_yootheme_normalize( $layout );
		return spiral_mcp_template_put( $info['template_id'], $tpl, $note );
	}
	if ( 'widget' === $info['kind'] ) {
		list( $base, $num, $all, $instance ) = spiral_mcp_widget_instance( $info['widget_id'] );
		$backup = spiral_mcp_snapshot_capture( 'widgets', $info['widget_id'], $note );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$instance['content'] = isset( $instance['content'] ) && is_array( $instance['content'] ) ? $layout : spiral_mcp_json( $layout );
		$all[ $num ]         = $instance;
		update_option( 'widget_' . $base, $all );
		return $backup;
	}

	$read = spiral_mcp_theme_config_read();
	if ( is_wp_error( $read ) ) {
		return $read;
	}
	$backup = spiral_mcp_snapshot_take( 'theme_config', 'config', $read['raw'], $note );
	if ( is_wp_error( $backup ) ) {
		return $backup;
	}
	$config = $read['config'];
	$ref    = &$config;
	foreach ( array_slice( $info['path'], 0, -1 ) as $key ) {
		if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
			$ref[ $key ] = array();
		}
		$ref = &$ref[ $key ];
	}
	// Respeta el formato en que estaba guardado (JSON en texto u objeto).
	$ref['content'] = is_string( $ref['content'] ?? null ) ? spiral_mcp_json( $layout ) : $layout;
	unset( $ref );

	$res = spiral_mcp_theme_config_write( $config, $read['raw'] );
	return is_wp_error( $res ) ? $res : $backup;
}

/** Todas las áreas con builder que existen ahora, para que el agente las descubra. */
function spiral_mcp_theme_areas_list() {
	$out    = array();
	$read   = spiral_mcp_theme_config_read();
	$config = is_wp_error( $read ) ? array() : $read['config'];
	foreach ( spiral_mcp_theme_builder_areas() as $area ) {
		$out[] = array( 'area' => $area, 'label' => ucfirst( $area ), 'root' => 'layout', 'has_layout' => null !== spiral_mcp_decode_layout( $config[ $area ]['content'] ?? null ) );
	}
	foreach ( (array) ( $config['menu']['items'] ?? array() ) as $id => $item ) {
		if ( is_array( $item ) && null !== spiral_mcp_decode_layout( $item['content'] ?? null ) ) {
			$post  = get_post( (int) $id );
			$out[] = array( 'area' => 'menu_item:' . $id, 'label' => 'Mega menú: ' . ( $post ? wp_setup_nav_menu_item( $post )->title : $id ), 'root' => 'fragment', 'has_layout' => true );
		}
	}
	foreach ( (array) get_option( 'widget_builderwidget', array() ) as $num => $instance ) {
		if ( is_numeric( $num ) && is_array( $instance ) ) {
			$wid   = 'builderwidget-' . $num;
			$out[] = array( 'area' => 'widget:' . $wid, 'label' => 'Widget de builder' . ( ! empty( $instance['title'] ) ? ' "' . $instance['title'] . '"' : '' ) . ' en ' . ( spiral_mcp_widget_sidebar_of( $wid ) ?? 'ninguna área' ), 'root' => 'fragment', 'has_layout' => null !== spiral_mcp_decode_layout( $instance['content'] ?? null ) );
		}
	}
	return $out;
}
/* -------------------------------------------------------------------------
 * Helpers: widgets
 * ---------------------------------------------------------------------- */

/** Separa "nav_menu-4" en [ 'nav_menu', 4 ]. */
function spiral_mcp_widget_parse_id( $widget_id ) {
	if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
		return new WP_Error( 'spiral_mcp_bad_widget', 'ID de widget no válido. Formato esperado: base-número, p. ej. nav_menu-4.' );
	}
	return array( $m[1], (int) $m[2] );
}

/** Área (sidebar) donde está un widget, o null. */
function spiral_mcp_widget_sidebar_of( $widget_id ) {
	foreach ( (array) wp_get_sidebars_widgets() as $sidebar => $ids ) {
		if ( is_array( $ids ) && in_array( $widget_id, $ids, true ) ) {
			return $sidebar;
		}
	}
	return null;
}

/** Instancia guardada de un widget. */
function spiral_mcp_widget_instance( $widget_id ) {
	$parsed = spiral_mcp_widget_parse_id( $widget_id );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	list( $base, $num ) = $parsed;
	$all                = get_option( 'widget_' . $base );
	if ( ! is_array( $all ) || ! isset( $all[ $num ] ) ) {
		return new WP_Error( 'spiral_mcp_widget_not_found', sprintf( 'No existe el widget "%s".', $widget_id ) );
	}
	return array( $base, $num, $all, (array) $all[ $num ] );
}

/** Formato de salida de un widget. */
function spiral_mcp_widget_out( $widget_id, $sidebar = null ) {
	global $wp_registered_sidebars;
	$data = spiral_mcp_widget_instance( $widget_id );
	if ( is_wp_error( $data ) ) {
		return array( 'id' => $widget_id, 'error' => $data->get_error_message() );
	}
	list( $base, , , $instance ) = $data;
	$sidebar                     = $sidebar ?? spiral_mcp_widget_sidebar_of( $widget_id );
	$out                         = array(
		'id'            => $widget_id,
		'type'          => $base,
		'sidebar'       => $sidebar,
		'sidebar_label' => $wp_registered_sidebars[ $sidebar ]['name'] ?? ( 'wp_inactive_widgets' === $sidebar ? 'Widgets inactivos' : null ),
		'title'         => $instance['title'] ?? '',
		'instance'      => $instance,
	);
	if ( 'nav_menu' === $base && ! empty( $instance['nav_menu'] ) ) {
		$menu             = wp_get_nav_menu_object( (int) $instance['nav_menu'] );
		$out['menu_name'] = $menu ? $menu->name : null;
	}
	// Los widgets de builder de YOOtheme guardan su layout como JSON: se devuelve decodificado.
	foreach ( $instance as $key => $value ) {
		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) && in_array( $decoded['type'] ?? '', array( 'layout', 'fragment' ), true ) ) {
				$out['layouts'][ $key ] = $decoded;
			}
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Restaura el theme mod "config" tal cual (null = no existía). */
function spiral_mcp_theme_config_restore( $ref, $data ) {
	// Si cambia el estilo (style, less o custom_less) el CSS queda desfasado: hay que recompilar.
	$decode = function ( $raw ) {
		$c = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
		return array_intersect_key( (array) $c, array_flip( array( 'style', 'less', 'custom_less' ) ) );
	};
	if ( $decode( get_theme_mod( 'config', null ) ) != $decode( $data ) && function_exists( 'spiral_mcp_style_mark_dirty' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		spiral_mcp_style_mark_dirty();
	}
	if ( null === $data ) {
		remove_theme_mod( 'config' );
	} else {
		set_theme_mod( 'config', $data );
	}
	return true;
}

/** Decodifica un valor del theme mod "config" (texto JSON o array). */
function spiral_mcp_theme_config_decode( $raw ) {
	$c = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return is_array( $c ) ? $c : array();
}

/** Secciones de primer nivel en que un snapshot de theme_config difiere de la configuración actual. */
function spiral_mcp_theme_config_diff( $ref, $data ) {
	$old  = spiral_mcp_theme_config_decode( $data );
	$cur  = spiral_mcp_theme_config_decode( get_theme_mod( 'config', null ) );
	$diff = array();
	foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $cur ) ) ) as $key ) {
		// menu.positions lo sincroniza YOOtheme con las ubicaciones de los menús: no cuenta como cambio.
		$a = $old[ $key ] ?? null;
		$b = $cur[ $key ] ?? null;
		if ( 'menu' === $key ) {
			unset( $a['positions'], $b['positions'] );
		}
		if ( $a != $b ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			$diff[] = (string) $key;
		}
	}
	return $diff;
}

/** Configuración actual con solo las secciones $parts tomadas del snapshot (mismo formato que el theme mod). */
function spiral_mcp_theme_config_partial( $ref, $data, array $parts ) {
	$raw = get_theme_mod( 'config', null );
	$old = spiral_mcp_theme_config_decode( $data );
	$cur = spiral_mcp_theme_config_decode( $raw );
	foreach ( $parts as $key ) {
		if ( in_array( $key, array( 'yootheme_apikey', 'version', 'site_url' ), true ) ) {
			return new WP_Error( 'spiral_mcp_snapshot_parts', sprintf( 'La sección "%s" no se puede restaurar.', $key ) );
		}
		if ( ! array_key_exists( $key, $old ) && ! array_key_exists( $key, $cur ) ) {
			return new WP_Error( 'spiral_mcp_snapshot_parts', sprintf( 'La sección "%s" no existe en el snapshot ni en la configuración actual.', $key ) );
		}
		if ( array_key_exists( $key, $old ) ) {
			$cur[ $key ] = $old[ $key ];
		} else {
			unset( $cur[ $key ] );
		}
	}
	return is_string( $raw ) || null === $raw ? spiral_mcp_json( $cur ) : $cur;
}

/** Estado de un widget: su opción widget_<base> y la asignación de áreas. */
function spiral_mcp_widget_capture( $widget_id ) {
	$parsed = spiral_mcp_widget_parse_id( $widget_id );
	$base   = is_wp_error( $parsed ) ? '' : $parsed[0];
	return array(
		'base'     => $base,
		'option'   => '' === $base ? null : get_option( 'widget_' . $base, null ),
		'sidebars' => get_option( 'sidebars_widgets', null ),
	);
}

function spiral_mcp_widget_restore( $widget_id, $data ) {
	if ( ! is_array( $data ) || ! array_key_exists( 'sidebars', $data ) ) {
		return new WP_Error( 'spiral_mcp_bad_snapshot', 'El snapshot del widget está incompleto.' );
	}
	if ( ! empty( $data['base'] ) && null !== $data['option'] ) {
		update_option( 'widget_' . $data['base'], $data['option'] );
	}
	if ( null !== $data['sidebars'] ) {
		update_option( 'sidebars_widgets', $data['sidebars'] );
	}
	return true;
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['theme_config'] = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => function () {
			return get_theme_mod( 'config', null );
		},
		'restore' => 'spiral_mcp_theme_config_restore',
		'parts'   => 'spiral_mcp_theme_config_diff',
		'partial' => 'spiral_mcp_theme_config_partial',
	);
	$kinds['widgets']      = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => 'spiral_mcp_widget_capture',
		'restore' => 'spiral_mcp_widget_restore',
	);
	return $kinds;
} );

/**
 * Busca el snapshot de theme_config de un ID de copia de la 1.3.0
 * ("20241102-153000-abcd") o de un ID numérico de snapshot.
 */
function spiral_mcp_theme_backup_find( $backup_id ) {
	global $wpdb;
	$table = spiral_mcp_table( 'snapshots' );
	if ( ctype_digit( (string) $backup_id ) ) {
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d AND kind = 'theme_config'", (int) $backup_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$like = '%' . $wpdb->esc_like( '[legacy:' . $backup_id . ']' );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE kind = 'theme_config' AND note LIKE %s ORDER BY id DESC LIMIT 1", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	return $id ? $id : new WP_Error( 'spiral_mcp_backup_not_found', 'No existe una copia con ese ID. Búscala con spiral-mcp/list-snapshots (kind theme_config).' );
}

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-theme', array(
		'label'       => 'Tema Spiral-MCP',
		'description' => 'Leer y editar la configuración de YOOtheme, los layouts del footer, los mega menús y los widgets de builder, y los widgets.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};


	$area_prop = array(
		'type'        => 'string',
		'default'     => 'footer',
		'description' => 'Área con builder: "footer", "menu_item:<ID del elemento de menú>" (mega menú) o "widget:<ID del widget de builder>" (header, navbar, top, bottom… se maquetan con widgets de builder). Lista en "areas" de spiral-mcp/get-theme-layout.',
	);

	/* 1. Leer layout del footer ------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/get-theme-layout', array(
		'label'               => 'Obtener layout de un área del tema (YOOtheme)',
		'description'         => 'Devuelve el árbol JSON del builder de un área del tema: el footer (raíz "layout", como una página), el mega menú de un elemento de menú o un widget de builder (raíz "fragment": filas > columnas > elementos, sin secciones). Incluye "settings" (los demás ajustes del área) y "areas": todas las áreas con builder del sitio. En YOOtheme el header, la navbar, top y bottom no tienen layout propio en la configuración: su contenido de builder son widgets de builder en esas posiciones; sus ajustes (disposición, logo, sticky…) se cambian con spiral-mcp/update-theme-config. Para editar por partes usa spiral-mcp/get-layout-outline y spiral-mcp/patch-layout con "area".',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'area' => $area_prop ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$got = spiral_mcp_theme_area_get( ( (array) $input )['area'] ?? 'footer' );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			return array(
				'area'     => $got['area'],
				'root'     => $got['root'],
				'layout'   => $got['layout'],
				'settings' => (object) $got['settings'],
				'areas'    => spiral_mcp_theme_areas_list(),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Actualizar layout del footer ------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/update-theme-layout', array(
		'label'               => 'Actualizar layout de un área del tema (YOOtheme)',
		'description'         => 'Reemplaza el árbol JSON completo del builder de un área del tema ("footer", "menu_item:<ID>" o "widget:<ID>"; ver spiral-mcp/get-theme-layout). La raíz debe ser "layout" en el footer y "fragment" en mega menús y widgets. Para cambios pequeños usa spiral-mcp/patch-layout. Valida el árbol con el catálogo de elementos: los errores bloquean el guardado y los avisos vuelven en "warnings". Antes guarda un snapshot ("snapshot_id"; se deshace con spiral-mcp/restore-snapshot).',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array(
			'type' => 'theme_area',
			'id'   => function ( $in ) {
				return $in['area'] ?? 'footer';
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'layout' ),
			'properties' => array(
				'area'   => $area_prop,
				'layout' => array( 'type' => 'object', 'description' => 'Árbol completo: raíz "layout" (footer) o "fragment" (mega menú, widget).' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$area   = (string) ( $input['area'] ?? 'footer' );
			$layout = $input['layout'] ?? null;
			if ( ! is_array( $layout ) ) {
				return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto JSON.' );
			}
			$warnings = spiral_mcp_layout_check( $layout );
			if ( is_wp_error( $warnings ) ) {
				return $warnings;
			}
			$backup = spiral_mcp_theme_area_save( $area, $layout, 'update-theme-layout:' . $area );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
			return array( 'area' => $area, 'updated' => true, 'backup_id' => $backup, 'snapshot_id' => $backup, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 3. Listar copias de la configuración ------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-theme-backups', array(
		'label'               => 'Listar copias de la configuración del tema',
		'description'         => 'OBSOLETA: usa spiral-mcp/list-snapshots con kind "theme_config". Se eliminará en la próxima versión. Lista las copias de la configuración de YOOtheme guardadas antes de cada cambio (las últimas 50); "id" es el ID del snapshot.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$out  = array();
			$list = spiral_mcp_snapshot_list( array( 'kind' => 'theme_config' ), 50 );
			foreach ( $list['items'] as $snap ) {
				$out[] = array(
					'id'     => (string) $snap['id'],
					'date'   => $snap['created_at'],
					'user'   => $snap['user_id'],
					'reason' => $snap['note'],
				);
			}
			return array( 'backups' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 4. Restaurar copia -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/restore-theme-backup', array(
		'label'               => 'Restaurar copia de la configuración del tema',
		'description'         => 'OBSOLETA: usa spiral-mcp/restore-snapshot. Se eliminará en la próxima versión. Restaura TODA la configuración de YOOtheme a una copia anterior (acepta el ID de snapshot o un ID de copia de la 1.3.0). Antes guarda un snapshot del estado actual. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'snapshot', 'id' => 'backup_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'backup_id', 'confirm' ),
			'properties' => array(
				'backup_id' => array( 'type' => 'string' ),
				'confirm'   => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'restaurar' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$id = spiral_mcp_theme_backup_find( $input['backup_id'] ?? '' );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$res = spiral_mcp_snapshot_restore( $id, array( 'include_later' => true ) ); // Alias obsoleto: conserva su comportamiento.
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'restored' => (string) $input['backup_id'], 'backup_of_previous' => (string) $res['snapshot_of_previous'] );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	/* 5. Listar widgets --------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-widgets', array(
		'label'               => 'Listar widgets',
		'description'         => 'Lista las áreas de widgets del tema y los widgets de cada una (incluidos los inactivos), con su configuración. Los nav_menu indican qué menú muestran y los widgets de builder de YOOtheme devuelven su layout decodificado en "layouts".',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			global $wp_registered_sidebars;
			$areas = array();
			foreach ( (array) wp_get_sidebars_widgets() as $sidebar => $ids ) {
				if ( 'array_version' === $sidebar || ! is_array( $ids ) ) {
					continue;
				}
				$widgets = array();
				foreach ( $ids as $wid ) {
					$widgets[] = spiral_mcp_widget_out( $wid, $sidebar );
				}
				$areas[] = array(
					'sidebar' => $sidebar,
					'label'   => $wp_registered_sidebars[ $sidebar ]['name'] ?? ( 'wp_inactive_widgets' === $sidebar ? 'Widgets inactivos' : $sidebar ),
					'widgets' => $widgets,
				);
			}
			return array( 'areas' => $areas );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 6. Actualizar widget ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/update-widget', array(
		'label'               => 'Actualizar widget',
		'description'         => 'Cambia la configuración de un widget (solo las claves enviadas en "instance"; p. ej. "title" o "nav_menu" con el ID del menú) y/o lo mueve a otra área con "sidebar" ("wp_inactive_widgets" lo desactiva sin borrarlo). En widgets de builder de YOOtheme, "layout" reemplaza el layout guardado en la clave "layout_key" (por defecto "content"). Antes guarda un snapshot del widget y de las áreas ("snapshot_id").',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'widget', 'id' => 'widget_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'widget_id' ),
			'properties' => array(
				'widget_id'  => array( 'type' => 'string', 'description' => 'ID del widget, p. ej. nav_menu-4.' ),
				'instance'   => array( 'type' => 'object', 'description' => 'Claves de configuración a cambiar.' ),
				'layout'     => array( 'type' => 'object', 'description' => 'Layout de YOOtheme para widgets de builder (raíz "fragment"). También se puede editar con spiral-mcp/patch-layout y "area": "widget:<ID>".' ),
				'layout_key' => array( 'type' => 'string', 'default' => 'content' ),
				'sidebar'    => array( 'type' => 'string', 'description' => 'Área destino. "wp_inactive_widgets" = desactivar.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			global $wp_registered_sidebars;
			$wid  = (string) ( $input['widget_id'] ?? '' );
			$data = spiral_mcp_widget_instance( $wid );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			list( $base, $num, $all, $instance ) = $data;
			$changed                             = false;

			// Se valida el área antes de escribir nada.
			$target = (string) ( $input['sidebar'] ?? '' );
			if ( '' !== $target && 'wp_inactive_widgets' !== $target && ! isset( $wp_registered_sidebars[ $target ] ) ) {
				return new WP_Error( 'spiral_mcp_bad_sidebar', sprintf( 'El área "%s" no existe. Disponibles: %s', $target, implode( ', ', array_keys( (array) $wp_registered_sidebars ) ) ) );
			}
			if ( empty( $input['instance'] ) && ! isset( $input['layout'] ) && '' === $target ) {
				return new WP_Error( 'spiral_mcp_nothing', 'No se envió ningún cambio (instance, layout o sidebar).' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'widgets', $wid, 'update-widget' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			if ( ! empty( $input['instance'] ) && is_array( $input['instance'] ) ) {
				foreach ( $input['instance'] as $key => $value ) {
					// Si el valor original era JSON en texto y llega un objeto, se vuelve a codificar.
					if ( is_array( $value ) && isset( $instance[ $key ] ) && is_string( $instance[ $key ] ) ) {
						$value = spiral_mcp_json( $value );
					}
					$instance[ $key ] = $value;
				}
				$changed = true;
			}
			if ( isset( $input['layout'] ) ) {
				$layout = $input['layout'];
				if ( ! is_array( $layout ) || ! in_array( $layout['type'] ?? '', array( 'layout', 'fragment' ), true ) ) {
					return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto con "type": "fragment" (o "layout").' );
				}
				$warnings = spiral_mcp_layout_check( $layout );
				if ( is_wp_error( $warnings ) ) {
					return $warnings;
				}
				$layout           = spiral_mcp_layout_stamp_version( $layout );
				$key              = (string) ( $input['layout_key'] ?? 'content' );
				$was_array        = isset( $instance[ $key ] ) && is_array( $instance[ $key ] );
				$instance[ $key ] = $was_array ? $layout : spiral_mcp_json( $layout );
				$changed          = true;
			}
			if ( 'nav_menu' === $base && isset( $instance['nav_menu'] ) && $instance['nav_menu'] && ! wp_get_nav_menu_object( (int) $instance['nav_menu'] ) ) {
				return new WP_Error( 'spiral_mcp_menu_not_found', 'El menú indicado en "nav_menu" no existe.' );
			}
			if ( $changed ) {
				$all[ $num ] = $instance;
				update_option( 'widget_' . $base, $all );
			}

			if ( '' !== $target ) {
				$sidebars = wp_get_sidebars_widgets();
				foreach ( $sidebars as $s => $ids ) {
					if ( is_array( $ids ) ) {
						$sidebars[ $s ] = array_values( array_diff( $ids, array( $wid ) ) );
					}
				}
				$sidebars[ $target ]   = isset( $sidebars[ $target ] ) && is_array( $sidebars[ $target ] ) ? $sidebars[ $target ] : array();
				$sidebars[ $target ][] = $wid;
				wp_set_sidebars_widgets( $sidebars );
			}
			return spiral_mcp_widget_out( $wid ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
