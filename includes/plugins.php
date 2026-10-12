<?php
/**
 * Plugins de wordpress.org: listar los instalados y buscar en el directorio
 * (Fase 8.1). Gestionarlos (instalar, activar, desactivar, actualizar, borrar)
 * exige dos cerrojos: la capacidad spiral_mcp_plugins y el ajuste
 * "plugins_management" (Ajustes > Spiral-MCP > Comportamiento), además de que
 * el sitio permita modificar archivos (DISALLOW_FILE_MODS).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Plugins que el agente nunca puede desactivar, actualizar ni borrar: sin ellos perdería el acceso. */
function spiral_mcp_plugins_protected() {
	$protected = array( plugin_basename( SPIRAL_MCP_FILE ) );
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( 'mcp-adapter' === dirname( $file ) ) {
			$protected[] = $file;
		}
	}
	return array_values( array_unique( (array) apply_filters( 'spiral_mcp_plugins_protected', $protected ) ) );
}

/** Estado de los cerrojos de la gestión de plugins para el usuario actual. */
function spiral_mcp_plugins_locks() {
	return array(
		'capability'        => current_user_can( 'spiral_mcp_plugins' ),
		'setting'           => spiral_mcp_setting( 'plugins_management' ),
		'file_mods_allowed' => wp_is_file_mod_allowed( 'spiral_mcp_plugins' ),
	);
}

/** true si se puede gestionar plugins; si no, WP_Error que dice qué cerrojo falta. */
function spiral_mcp_plugins_check_locks() {
	$locks = spiral_mcp_plugins_locks();
	if ( ! $locks['file_mods_allowed'] ) {
		return new WP_Error( 'spiral_mcp_plugins_file_mods', 'Este sitio no permite modificar archivos (DISALLOW_FILE_MODS en wp-config.php): no se pueden gestionar plugins desde aquí ni desde wp-admin.' );
	}
	if ( ! $locks['setting'] ) {
		return new WP_Error( 'spiral_mcp_plugins_disabled', 'La gestión de plugins por el agente está desactivada. Un administrador puede activarla en Ajustes > Spiral-MCP > Comportamiento ("Permitir que el agente gestione plugins").' );
	}
	if ( ! $locks['capability'] ) {
		return new WP_Error( 'spiral_mcp_plugins_capability', 'Tu rol no tiene la capacidad spiral_mcp_plugins. Un administrador puede dártela en Ajustes > Spiral-MCP > Permisos del agente.' );
	}
	return true;
}

/** Slug (carpeta en wp-content/plugins) de un archivo de plugin; plugins sueltos: nombre sin .php. */
function spiral_mcp_plugin_slug( $file ) {
	$dir = dirname( (string) $file );
	return '.' === $dir ? basename( (string) $file, '.php' ) : $dir;
}

/** ¿Cumple el sitio los requisitos de WordPress y PHP? Devuelve lista de problemas. */
function spiral_mcp_plugin_compat_issues( $requires_wp, $requires_php ) {
	$issues = array();
	if ( $requires_wp && version_compare( get_bloginfo( 'version' ), (string) $requires_wp, '<' ) ) {
		$issues[] = sprintf( 'requiere WordPress %s (el sitio tiene %s)', $requires_wp, get_bloginfo( 'version' ) );
	}
	if ( $requires_php && version_compare( PHP_VERSION, (string) $requires_php, '<' ) ) {
		$issues[] = sprintf( 'requiere PHP %s (el servidor tiene %s)', $requires_php, PHP_VERSION );
	}
	return $issues;
}

/* -------------------------------------------------------------------------
 * Categoría y abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-plugins', array(
		'label'       => 'Plugins Spiral-MCP',
		'description' => 'Plugins instalados y directorio de wordpress.org.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {

	$can_read = function () {
		return current_user_can( 'edit_theme_options' );
	};

	/* 1. Instalados ------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-plugins', array(
		'label'               => 'Listar plugins instalados',
		'description'         => 'Lista los plugins instalados: archivo, slug, nombre, versión, si está activo, si hay actualización (y a qué versión), si tiene las actualizaciones automáticas activas, requisitos y si está protegido (Spiral-MCP-Control y MCP Adapter: no se pueden desactivar, actualizar ni borrar desde aquí). Incluye en solo lectura los mu-plugins y los drop-ins (advanced-cache.php, object-cache.php…). "management" dice si puedes gestionar plugins: hacen falta la capacidad spiral_mcp_plugins y el ajuste del administrador; si falta algo, explica al usuario qué tendría que activar. Filtra con "status" (active, inactive, update) o "search".',
		'category'            => 'spiral-mcp-plugins',
		'group'               => array( 'plugins', 'settings' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'status' => array( 'type' => 'string', 'enum' => array( 'all', 'active', 'inactive', 'update' ), 'default' => 'all' ),
				'search' => array( 'type' => 'string', 'description' => 'Texto en el nombre, el slug o la descripción.' ),
			),
		),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$updates   = get_site_transient( 'update_plugins' );
			$auto      = (array) get_site_option( 'auto_update_plugins', array() );
			$protected = spiral_mcp_plugins_protected();
			$status    = (string) ( $input['status'] ?? 'all' );
			$search    = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
			$items     = array();
			foreach ( get_plugins() as $file => $data ) {
				$active = is_plugin_active( $file );
				$update = isset( $updates->response[ $file ] ) ? (string) $updates->response[ $file ]->new_version : null;
				if ( ( 'active' === $status && ! $active ) || ( 'inactive' === $status && $active ) || ( 'update' === $status && null === $update ) ) {
					continue;
				}
				if ( '' !== $search && false === strpos( strtolower( $file . ' ' . $data['Name'] . ' ' . $data['Description'] ), $search ) ) {
					continue;
				}
				$items[] = array(
					'file'           => $file,
					'slug'           => spiral_mcp_plugin_slug( $file ),
					'name'           => wp_strip_all_tags( (string) $data['Name'] ),
					'version'        => (string) $data['Version'],
					'active'         => $active,
					'update'         => $update,
					'auto_update'    => in_array( $file, $auto, true ),
					'protected'      => in_array( $file, $protected, true ),
					'author'         => wp_strip_all_tags( (string) $data['Author'] ),
					'requires_wp'    => (string) ( $data['RequiresWP'] ?? '' ),
					'requires_php'   => (string) ( $data['RequiresPHP'] ?? '' ),
					'description'    => wp_trim_words( wp_strip_all_tags( (string) $data['Description'] ), 30 ),
				);
			}
			$simple = function ( $list ) {
				$out = array();
				foreach ( (array) $list as $file => $data ) {
					$out[] = array(
						'file'    => $file,
						'name'    => wp_strip_all_tags( (string) ( $data['Name'] ?? $file ) ),
						'version' => (string) ( $data['Version'] ?? '' ),
					);
				}
				return $out;
			};
			$locks = spiral_mcp_plugins_locks();
			$check = spiral_mcp_plugins_check_locks();
			return array(
				'total'      => count( $items ),
				'plugins'    => $items,
				'mu_plugins' => $simple( get_mu_plugins() ),
				'dropins'    => $simple( get_dropins() ),
				'management' => $locks + array(
					'allowed' => true === $check,
					'reason'  => is_wp_error( $check ) ? $check->get_error_message() : null,
				),
				'updates_checked_at' => isset( $updates->last_checked ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $updates->last_checked ) : null,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Buscar en wordpress.org ------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/search-plugins', array(
		'label'               => 'Buscar plugins en wordpress.org',
		'description'         => 'Busca en el directorio oficial de plugins de wordpress.org ("search") o da la ficha de uno ("slug"). Para cada plugin: nombre, slug, versión, valoración (0-100) y número de valoraciones, instalaciones activas, última actualización, compatible hasta (WordPress), requisitos de WordPress y PHP, si es compatible con este sitio ("compatible" y "issues"), si ya está instalado y una descripción corta. Úsala para proponer opciones al usuario antes de instalar nada: prefiere plugins mantenidos (actualizados en el último año), compatibles y con muchas instalaciones y buena valoración. Solo lectura.',
		'category'            => 'spiral-mcp-plugins',
		'group'               => array( 'plugins', 'settings' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string', 'description' => 'Texto a buscar, p. ej. "formulario de contacto".' ),
				'slug'     => array( 'type' => 'string', 'description' => 'En lugar de "search": la ficha de un plugin, p. ej. contact-form-7.' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 10 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = array();
			foreach ( array_keys( get_plugins() ) as $file ) {
				$installed[ spiral_mcp_plugin_slug( $file ) ] = $file;
			}
			$fields = array(
				'short_description' => true,
				'active_installs'   => true,
				'last_updated'      => true,
				'rating'            => true,
				'num_ratings'       => true,
				'requires'          => true,
				'requires_php'      => true,
				'tested'            => true,
				'sections'          => false,
				'description'       => false,
				'icons'             => false,
				'banners'           => false,
				'screenshots'       => false,
				'tags'              => false,
				'versions'          => false,
				'compatibility'     => false,
				'contributors'      => false,
				'reviews'           => false,
			);
			$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
			if ( '' !== $slug ) {
				$res  = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => $fields ) );
				$list = is_wp_error( $res ) ? $res : array( $res );
			} elseif ( '' !== trim( (string) ( $input['search'] ?? '' ) ) ) {
				$res  = plugins_api( 'query_plugins', array(
					'search'   => (string) $input['search'],
					'per_page' => (int) ( $input['per_page'] ?? 10 ),
					'page'     => (int) ( $input['page'] ?? 1 ),
					'fields'   => $fields,
				) );
				$list = is_wp_error( $res ) ? $res : (array) ( $res->plugins ?? array() );
			} else {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "search" (texto) o "slug" (ficha de un plugin).' );
			}
			if ( is_wp_error( $list ) ) {
				return new WP_Error( 'spiral_mcp_plugins_api', 'wordpress.org no respondió como se esperaba: ' . $list->get_error_message() . ( '' !== $slug ? ' (¿existe el slug?)' : '' ) );
			}
			$items = array();
			foreach ( $list as $p ) {
				$p      = (array) $p;
				$issues = spiral_mcp_plugin_compat_issues( $p['requires'] ?? '', $p['requires_php'] ?? '' );
				$items[] = array(
					'name'              => wp_strip_all_tags( html_entity_decode( (string) ( $p['name'] ?? '' ) ) ),
					'slug'              => (string) ( $p['slug'] ?? '' ),
					'version'           => (string) ( $p['version'] ?? '' ),
					'rating'            => (int) ( $p['rating'] ?? 0 ),
					'num_ratings'       => (int) ( $p['num_ratings'] ?? 0 ),
					'active_installs'   => (int) ( $p['active_installs'] ?? 0 ),
					'last_updated'      => ! empty( $p['last_updated'] ) && strtotime( (string) $p['last_updated'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( (string) $p['last_updated'] ) ) : null,
					'tested'            => (string) ( $p['tested'] ?? '' ),
					'requires_wp'       => (string) ( $p['requires'] ?? '' ),
					'requires_php'      => (string) ( $p['requires_php'] ?? '' ),
					'compatible'        => ! $issues,
					'issues'            => $issues,
					'installed'         => isset( $installed[ (string) ( $p['slug'] ?? '' ) ] ),
					'short_description' => wp_strip_all_tags( html_entity_decode( (string) ( $p['short_description'] ?? '' ) ) ),
				);
			}
			$out = array( 'items' => $items );
			if ( '' === $slug ) {
				$out['total'] = (int) ( $res->info['results'] ?? count( $items ) );
				$out['pages'] = (int) ( $res->info['pages'] ?? 1 );
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
