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

/** Nombre de la opción donde se guardan las copias de la configuración. */
const SPIRAL_MCP_THEME_BACKUPS = 'spiral_mcp_yootheme_config_backups';

/** Cuántas copias se conservan. */
const SPIRAL_MCP_THEME_BACKUPS_MAX = 10;

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

/** Guarda una copia de la configuración actual antes de modificarla. */
function spiral_mcp_theme_config_backup( $raw, $reason ) {
	$backups = get_option( SPIRAL_MCP_THEME_BACKUPS, array() );
	if ( ! is_array( $backups ) ) {
		$backups = array();
	}
	$id        = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false );
	$backups[] = array(
		'id'     => $id,
		'date'   => gmdate( 'c' ),
		'user'   => get_current_user_id(),
		'reason' => $reason,
		'raw'    => $raw,
	);
	$backups = array_slice( $backups, -SPIRAL_MCP_THEME_BACKUPS_MAX );
	update_option( SPIRAL_MCP_THEME_BACKUPS, $backups, false ); // Sin autoload: puede pesar.
	return $id;
}

/** Escribe la configuración respetando el formato original (string JSON o array). */
function spiral_mcp_theme_config_write( array $config, $original_raw ) {
	$value = is_string( $original_raw )
		? wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		: $config;
	if ( false === $value ) {
		return new WP_Error( 'spiral_mcp_bad_config', 'No se pudo serializar la configuración.' );
	}
	set_theme_mod( 'config', $value );
	return true;
}

/**
 * Áreas del tema que tienen builder propio. Ajustable con el filtro
 * spiral_mcp_theme_builder_areas.
 */
function spiral_mcp_theme_builder_areas() {
	return apply_filters( 'spiral_mcp_theme_builder_areas', array( 'footer' ) );
}

/** Devuelve el layout de un área (decodificado) o null. */
function spiral_mcp_theme_area_layout( array $config, $area ) {
	$content = $config[ $area ]['content'] ?? null;
	if ( is_string( $content ) ) {
		$content = json_decode( $content, true );
	}
	return is_array( $content ) ? $content : null;
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
			if ( is_array( $decoded ) && ( $decoded['type'] ?? '' ) === 'layout' ) {
				$out['layouts'][ $key ] = $decoded;
			}
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-theme', array(
		'label'       => 'Tema Spiral-MCP',
		'description' => 'Leer y editar el builder del footer de YOOtheme y los widgets.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	$meta = function ( $readonly, $destructive = false, $idempotent = false ) {
		return array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ),
			'mcp'          => array( 'public' => true ),
		);
	};

	$area_prop = array(
		'type'        => 'string',
		'enum'        => spiral_mcp_theme_builder_areas(),
		'default'     => 'footer',
		'description' => 'Área del tema con builder propio.',
	);

	/* 1. Leer layout del footer ------------------------------------------ */
	wp_register_ability( 'spiral-mcp/get-theme-layout', array(
		'label'               => 'Obtener layout del footer (YOOtheme)',
		'description'         => 'Devuelve el árbol JSON del builder del footer de YOOtheme (Personalizar > Footer), el mismo formato que los layouts de página. Incluye también las claves de primer nivel de la configuración del tema, para diagnóstico.',
		'category'            => 'spiral-mcp-theme',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'area' => $area_prop ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$area = $input['area'] ?? 'footer';
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$config = $read['config'];
			return array(
				'area'        => $area,
				'layout'      => spiral_mcp_theme_area_layout( $config, $area ),
				'area_config' => array_diff_key( (array) ( $config[ $area ] ?? array() ), array( 'content' => 1 ) ),
				'config_keys' => array_keys( $config ),
			);
		},
		'meta'                => $meta( true, false, true ),
	) );

	/* 2. Actualizar layout del footer ------------------------------------ */
	wp_register_ability( 'spiral-mcp/update-theme-layout', array(
		'label'               => 'Actualizar layout del footer (YOOtheme)',
		'description'         => 'Reemplaza el árbol JSON completo del builder del footer de YOOtheme. Primero léelo con spiral-mcp/get-theme-layout, modifícalo y envíalo entero. Antes de escribir guarda una copia de toda la configuración del tema (ver spiral-mcp/list-theme-backups y spiral-mcp/restore-theme-backup).',
		'category'            => 'spiral-mcp-theme',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'layout' ),
			'properties' => array(
				'area'   => $area_prop,
				'layout' => array( 'type' => 'object', 'description' => 'Árbol completo con "type": "layout" en la raíz.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$area   = $input['area'] ?? 'footer';
			$layout = $input['layout'] ?? null;
			if ( ! in_array( $area, spiral_mcp_theme_builder_areas(), true ) ) {
				return new WP_Error( 'spiral_mcp_bad_area', 'Área no permitida.' );
			}
			if ( ! is_array( $layout ) || ( $layout['type'] ?? '' ) !== 'layout' ) {
				return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto con "type": "layout".' );
			}
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$config = $read['config'];
			$backup = spiral_mcp_theme_config_backup( $read['raw'], 'update-theme-layout:' . $area );

			// Respeta el formato en que estaba guardado el contenido del área.
			$was_string = is_string( $config[ $area ]['content'] ?? null );
			if ( ! isset( $config[ $area ] ) || ! is_array( $config[ $area ] ) ) {
				$config[ $area ] = array();
			}
			$config[ $area ]['content'] = $was_string
				? wp_json_encode( $layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				: $layout;

			$res = spiral_mcp_theme_config_write( $config, $read['raw'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'area' => $area, 'updated' => true, 'backup_id' => $backup );
		},
		'meta'                => $meta( false, false, true ),
	) );

	/* 3. Listar copias de la configuración ------------------------------- */
	wp_register_ability( 'spiral-mcp/list-theme-backups', array(
		'label'               => 'Listar copias de la configuración del tema',
		'description'         => 'Lista las copias de la configuración de YOOtheme que se guardan automáticamente antes de cada cambio (las últimas 10).',
		'category'            => 'spiral-mcp-theme',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$out = array();
			foreach ( (array) get_option( SPIRAL_MCP_THEME_BACKUPS, array() ) as $b ) {
				$out[] = array(
					'id'     => $b['id'] ?? '',
					'date'   => $b['date'] ?? '',
					'user'   => $b['user'] ?? 0,
					'reason' => $b['reason'] ?? '',
				);
			}
			return array( 'backups' => array_reverse( $out ) );
		},
		'meta'                => $meta( true, false, true ),
	) );

	/* 4. Restaurar copia -------------------------------------------------- */
	wp_register_ability( 'spiral-mcp/restore-theme-backup', array(
		'label'               => 'Restaurar copia de la configuración del tema',
		'description'         => 'Restaura TODA la configuración de YOOtheme a una copia anterior. Antes guarda una copia del estado actual. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-theme',
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
			if ( true !== ( $input['confirm'] ?? false ) ) {
				return new WP_Error( 'spiral_mcp_confirm', 'Para restaurar envía "confirm": true.' );
			}
			foreach ( (array) get_option( SPIRAL_MCP_THEME_BACKUPS, array() ) as $b ) {
				if ( ( $b['id'] ?? '' ) === ( $input['backup_id'] ?? '' ) ) {
					$current = get_theme_mod( 'config', null );
					$new_id  = spiral_mcp_theme_config_backup( $current, 'restore:' . $b['id'] );
					set_theme_mod( 'config', $b['raw'] );
					return array( 'restored' => $b['id'], 'backup_of_previous' => $new_id );
				}
			}
			return new WP_Error( 'spiral_mcp_backup_not_found', 'No existe una copia con ese ID.' );
		},
		'meta'                => $meta( false, true, false ),
	) );

	/* 5. Listar widgets --------------------------------------------------- */
	wp_register_ability( 'spiral-mcp/list-widgets', array(
		'label'               => 'Listar widgets',
		'description'         => 'Lista las áreas de widgets del tema y los widgets de cada una (incluidos los inactivos), con su configuración. Los nav_menu indican qué menú muestran y los widgets de builder de YOOtheme devuelven su layout decodificado en "layouts".',
		'category'            => 'spiral-mcp-theme',
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
		'meta'                => $meta( true, false, true ),
	) );

	/* 6. Actualizar widget ------------------------------------------------ */
	wp_register_ability( 'spiral-mcp/update-widget', array(
		'label'               => 'Actualizar widget',
		'description'         => 'Cambia la configuración de un widget (solo las claves enviadas en "instance"; p. ej. "title" o "nav_menu" con el ID del menú) y/o lo mueve a otra área con "sidebar" ("wp_inactive_widgets" lo desactiva sin borrarlo). En widgets de builder de YOOtheme, "layout" reemplaza el layout guardado en la clave "layout_key" (por defecto "content").',
		'category'            => 'spiral-mcp-theme',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'widget_id' ),
			'properties' => array(
				'widget_id'  => array( 'type' => 'string', 'description' => 'ID del widget, p. ej. nav_menu-4.' ),
				'instance'   => array( 'type' => 'object', 'description' => 'Claves de configuración a cambiar.' ),
				'layout'     => array( 'type' => 'object', 'description' => 'Layout de YOOtheme (type: layout) para widgets de builder.' ),
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

			if ( ! empty( $input['instance'] ) && is_array( $input['instance'] ) ) {
				foreach ( $input['instance'] as $key => $value ) {
					// Si el valor original era JSON en texto y llega un objeto, se vuelve a codificar.
					if ( is_array( $value ) && isset( $instance[ $key ] ) && is_string( $instance[ $key ] ) ) {
						$value = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					}
					$instance[ $key ] = $value;
				}
				$changed = true;
			}
			if ( isset( $input['layout'] ) ) {
				$layout = $input['layout'];
				if ( ! is_array( $layout ) || ( $layout['type'] ?? '' ) !== 'layout' ) {
					return new WP_Error( 'spiral_mcp_bad_layout', 'El layout debe ser un objeto con "type": "layout".' );
				}
				$key              = (string) ( $input['layout_key'] ?? 'content' );
				$was_array        = isset( $instance[ $key ] ) && is_array( $instance[ $key ] );
				$instance[ $key ] = $was_array ? $layout : wp_json_encode( $layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$changed          = true;
			}
			if ( 'nav_menu' === $base && isset( $instance['nav_menu'] ) && $instance['nav_menu'] && ! wp_get_nav_menu_object( (int) $instance['nav_menu'] ) ) {
				return new WP_Error( 'spiral_mcp_menu_not_found', 'El menú indicado en "nav_menu" no existe.' );
			}
			if ( $changed ) {
				$all[ $num ] = $instance;
				update_option( 'widget_' . $base, $all );
			}

			if ( ! empty( $input['sidebar'] ) ) {
				$target = (string) $input['sidebar'];
				if ( 'wp_inactive_widgets' !== $target && ! isset( $wp_registered_sidebars[ $target ] ) ) {
					return new WP_Error( 'spiral_mcp_bad_sidebar', sprintf( 'El área "%s" no existe. Disponibles: %s', $target, implode( ', ', array_keys( (array) $wp_registered_sidebars ) ) ) );
				}
				$sidebars = wp_get_sidebars_widgets();
				foreach ( $sidebars as $s => $ids ) {
					if ( is_array( $ids ) ) {
						$sidebars[ $s ] = array_values( array_diff( $ids, array( $wid ) ) );
					}
				}
				$sidebars[ $target ]   = isset( $sidebars[ $target ] ) && is_array( $sidebars[ $target ] ) ? $sidebars[ $target ] : array();
				$sidebars[ $target ][] = $wid;
				wp_set_sidebars_widgets( $sidebars );
				$changed = true;
			}
			if ( ! $changed ) {
				return new WP_Error( 'spiral_mcp_nothing', 'No se envió ningún cambio (instance, layout o sidebar).' );
			}
			return spiral_mcp_widget_out( $wid );
		},
		'meta'                => $meta( false, false, true ),
	) );
} );
