<?php
/**
 * Plugins de wordpress.org: listar los instalados, buscar en el directorio,
 * instalar (sin activar), activar, desactivar, actualizar y borrar (Fase 8). Gestionarlos
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
		return new WP_Error( 'spiral_mcp_plugins_file_mods', 'Este sitio no permite modificar archivos (DISALLOW_FILE_MODS en wp-config.php): no se pueden gestionar plugins desde aquí ni desde wp-admin.', array( 'status' => 403 ) );
	}
	if ( ! $locks['setting'] ) {
		return new WP_Error( 'spiral_mcp_plugins_disabled', 'La gestión de plugins por el agente está desactivada. Un administrador puede activarla en Ajustes > Spiral-MCP > Comportamiento ("Permitir que el agente gestione plugins").', array( 'status' => 403 ) );
	}
	if ( ! $locks['capability'] ) {
		return new WP_Error( 'spiral_mcp_plugins_capability', 'Tu rol no tiene la capacidad spiral_mcp_plugins. Un administrador puede dártela en Ajustes > Spiral-MCP > Permisos del agente.', array( 'status' => 403 ) );
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

/** ¿Viene el paquete (ZIP) de un origen permitido? Por defecto, solo downloads.wordpress.org. */
function spiral_mcp_plugin_package_allowed( $url ) {
	$hosts = (array) apply_filters( 'spiral_mcp_plugins_allowed_hosts', array( 'downloads.wordpress.org' ) );
	return in_array( wp_parse_url( (string) $url, PHP_URL_HOST ), $hosts, true );
}

/** WP_Error si WordPress no puede escribir directamente en wp-content/plugins. */
function spiral_mcp_plugins_check_filesystem() {
	if ( ! WP_Filesystem() || 'direct' !== get_filesystem_method() ) {
		return new WP_Error( 'spiral_mcp_plugin_filesystem', 'WordPress no puede escribir directamente en wp-content/plugins (pide credenciales FTP). Hazlo desde wp-admin.' );
	}
	return true;
}

/** Carga las partes de wp-admin que hacen falta para gestionar plugins. */
function spiral_mcp_plugins_load_admin() {
	foreach ( array( 'plugin.php', 'file.php', 'misc.php', 'plugin-install.php', 'class-wp-upgrader.php' ) as $file ) {
		require_once ABSPATH . 'wp-admin/includes/' . $file;
	}
}

/**
 * Archivo de un plugin instalado a partir de "plugin" (archivo, p. ej. contact-form-7/wp-contact-form-7.php,
 * o slug, p. ej. contact-form-7). WP_Error si no está instalado.
 */
function spiral_mcp_plugin_resolve( $plugin ) {
	spiral_mcp_plugins_load_admin();
	$plugin  = trim( (string) $plugin );
	$plugins = get_plugins();
	if ( isset( $plugins[ $plugin ] ) ) {
		return $plugin;
	}
	foreach ( array_keys( $plugins ) as $file ) {
		if ( spiral_mcp_plugin_slug( $file ) === $plugin ) {
			return $file;
		}
	}
	return new WP_Error( 'spiral_mcp_plugin_not_found', sprintf( 'El plugin "%s" no está instalado. Mira spiral-mcp/list-plugins (o instálalo con spiral-mcp/install-plugin).', $plugin ) );
}

/**
 * Comprueba que el sitio sigue respondiendo: pide la portada y wp-admin al propio servidor.
 * Un 500 (o el aviso de error crítico de WordPress) es un fallo.
 */
function spiral_mcp_plugins_health() {
	$checks = array();
	$ok     = true;
	foreach ( array( '/', '/wp-admin/' ) as $path ) {
		$url = function_exists( 'spiral_mcp_inspect_url' ) ? spiral_mcp_inspect_url( home_url( $path ) ) : home_url( $path );
		$res = wp_remote_get( add_query_arg( 'spiral_mcp_health', time(), $url ), array(
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'Spiral-MCP-Health/' . SPIRAL_MCP_VERSION,
		) );
		if ( is_wp_error( $res ) ) {
			// Sin respuesta (el servidor no se alcanza a sí mismo): no se puede saber; no cuenta como fallo.
			$checks[] = array( 'path' => $path, 'status' => null, 'error' => $res->get_error_message() );
			continue;
		}
		$code     = (int) wp_remote_retrieve_response_code( $res );
		$critical = false !== stripos( (string) wp_remote_retrieve_body( $res ), 'critical error' );
		$checks[] = array( 'path' => $path, 'status' => $code );
		if ( $code >= 500 || $critical ) {
			$ok = false;
		}
	}
	return array( 'ok' => $ok, 'checks' => $checks );
}

/** Marca un plugin como activo sin cargarlo ni ejecutar su activación (como hace WordPress tras actualizar en segundo plano). */
function spiral_mcp_plugins_set_active( $file ) {
	$active = (array) get_option( 'active_plugins', array() );
	if ( ! in_array( $file, $active, true ) ) {
		$active[] = $file;
		sort( $active );
		update_option( 'active_plugins', $active );
	}
}

/** Tras activar o desactivar: purga la caché si la purga automática está activa. */
function spiral_mcp_plugins_after_change() {
	if ( spiral_mcp_setting( 'auto_purge' ) && function_exists( 'spiral_mcp_cache_purge' ) ) {
		spiral_mcp_cache_purge( 'all' );
	}
}

/**
 * Activa un plugin protegiendo el resto del sitio: un error fatal al cargarlo o en su activación
 * se captura (WordPress aún no lo ha marcado como activo) y, si después el sitio responde con
 * error 500, se desactiva solo. Devuelve [ 'health' => … ] o WP_Error.
 */
function spiral_mcp_plugin_activate_safely( $file ) {
	$level = ob_get_level();
	try {
		$res = activate_plugin( $file, '', false, false );
	} catch ( Throwable $e ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		deactivate_plugins( $file, true ); // Por si llegó a marcarse como activo.
		return new WP_Error( 'spiral_mcp_plugin_fatal', sprintf( 'El plugin da un error fatal al cargarse o activarse y no se ha activado: %s (%s, línea %d).', $e->getMessage(), str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $e->getFile() ) ), $e->getLine() ) );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'spiral_mcp_plugin_activation', 'WordPress no ha activado el plugin: ' . $res->get_error_message() );
	}
	$health = spiral_mcp_plugins_health();
	if ( ! $health['ok'] ) {
		deactivate_plugins( $file, true );
		$again = spiral_mcp_plugins_health();
		return new WP_Error( 'spiral_mcp_plugin_broke_site', 'Al activarlo, el sitio empezó a responder con error 500, así que se ha desactivado otra vez.' . ( $again['ok'] ? ' El sitio vuelve a funcionar.' : ' OJO: el sitio sigue dando error; revísalo ya.' ), array( 'before_rollback' => $health['checks'], 'after_rollback' => $again['checks'] ) );
	}
	return array( 'health' => $health );
}

/* -------------------------------------------------------------------------
 * Snapshots: plugins activos (ref "active_plugins")
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['plugins_state'] = array(
		'group'   => 'plugins',
		'cap'     => 'edit_theme_options',
		'capture' => function () {
			return array_values( (array) get_option( 'active_plugins', array() ) );
		},
		'restore' => function ( $ref, $data ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			spiral_mcp_plugins_load_admin();
			$want      = (array) $data;
			$current   = (array) get_option( 'active_plugins', array() );
			$protected = spiral_mcp_plugins_protected();
			$installed = get_plugins();
			$done      = array( 'activated' => array(), 'deactivated' => array(), 'skipped' => array() );
			foreach ( array_diff( $current, $want ) as $file ) {
				if ( in_array( $file, $protected, true ) ) {
					$done['skipped'][] = $file;
					continue;
				}
				deactivate_plugins( $file, true );
				$done['deactivated'][] = $file;
			}
			foreach ( array_diff( $want, $current ) as $file ) {
				if ( ! isset( $installed[ $file ] ) ) {
					$done['skipped'][] = $file; // Ya no está instalado.
					continue;
				}
				$res = spiral_mcp_plugin_activate_safely( $file );
				if ( is_wp_error( $res ) ) {
					$done['skipped'][] = $file;
					continue;
				}
				$done['activated'][] = $file;
			}
			spiral_mcp_plugins_after_change();
			return $done;
		},
	);
	return $kinds;
} );

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

	$can_manage = function () {
		// Los cerrojos se comprueban al ejecutar, para devolver un mensaje que diga cuál falta.
		return current_user_can( 'edit_theme_options' );
	};
	$plugin_prop = array( 'type' => 'string', 'description' => 'Archivo (p. ej. contact-form-7/wp-contact-form-7.php) o slug (contact-form-7), como en list-plugins.' );

	/* 3. Instalar --------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/install-plugin', array(
		'label'               => 'Instalar un plugin de wordpress.org',
		'description'         => 'Instala un plugin del directorio oficial de wordpress.org por su "slug" (ver spiral-mcp/search-plugins), sin activarlo: activarlo es otro paso (spiral-mcp/activate-plugin). Hazlo solo si el usuario lo ha pedido o lo ha aprobado tras enseñarle las opciones. Comprueba antes que sea compatible con la versión de WordPress y PHP del sitio. Solo wordpress.org: no acepta ZIP ni URLs. Requiere los dos cerrojos de la gestión de plugins (capacidad spiral_mcp_plugins y el ajuste del administrador; list-plugins dice si están abiertos). Para deshacerlo, bórralo con spiral-mcp/delete-plugin.',
		'category'            => 'spiral-mcp-plugins',
		// Pasa también con "settings" para que, sin spiral_mcp_plugins, la respuesta diga qué cerrojo falta
		// (spiral_mcp_plugins_check_locks() exige la capacidad igualmente).
		'group'               => array( 'plugins', 'settings' ),
		'log'                 => array( 'type' => 'plugin', 'id' => 'slug' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'slug' ),
			'properties' => array(
				'slug' => array( 'type' => 'string', 'description' => 'Slug en wordpress.org, p. ej. classic-editor.' ),
			),
		),
		'permission_callback' => $can_manage,
		'execute_callback'    => function ( $input ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			spiral_mcp_plugins_load_admin();
			$slug = sanitize_key( (string) ( $input['slug'] ?? '' ) );
			if ( '' === $slug ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "slug" (p. ej. classic-editor).' );
			}
			foreach ( array_keys( get_plugins() ) as $file ) {
				if ( spiral_mcp_plugin_slug( $file ) === $slug ) {
					return new WP_Error( 'spiral_mcp_plugin_installed', sprintf( 'El plugin "%s" ya está instalado (%s). Para activarlo, spiral-mcp/activate-plugin.', $slug, $file ) );
				}
			}
			$info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false, 'requires' => true, 'requires_php' => true, 'versions' => false ) ) );
			if ( is_wp_error( $info ) ) {
				return new WP_Error( 'spiral_mcp_plugins_api', sprintf( 'No se encontró "%s" en wordpress.org: %s', $slug, $info->get_error_message() ) );
			}
			$issues = spiral_mcp_plugin_compat_issues( $info->requires ?? '', $info->requires_php ?? '' );
			if ( $issues ) {
				return new WP_Error( 'spiral_mcp_plugin_incompatible', sprintf( '"%s" no es compatible con este sitio: %s. No se ha instalado.', $slug, implode( '; ', $issues ) ) );
			}
			$link = (string) ( $info->download_link ?? '' );
			if ( ! spiral_mcp_plugin_package_allowed( $link ) ) {
				return new WP_Error( 'spiral_mcp_plugin_source', 'La descarga no viene de downloads.wordpress.org: no se instala.' );
			}
			$fs = spiral_mcp_plugins_check_filesystem();
			if ( is_wp_error( $fs ) ) {
				return $fs;
			}
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $link );
			if ( is_wp_error( $result ) || ! $result ) {
				$msg = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $upgrader->skin->get_upgrade_messages() );
				return new WP_Error( 'spiral_mcp_plugin_install_failed', 'No se pudo instalar: ' . $msg );
			}
			wp_clean_plugins_cache( true );
			$file = $upgrader->plugin_info();
			$data = $file ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array();
			return array(
				'installed' => true,
				'active'    => false,
				'file'      => (string) $file,
				'slug'      => $slug,
				'name'      => wp_strip_all_tags( (string) ( $data['Name'] ?? $info->name ?? $slug ) ),
				'version'   => (string) ( $data['Version'] ?? $info->version ?? '' ),
				'next'      => 'Está instalado pero desactivado. Para usarlo, spiral-mcp/activate-plugin (confírmalo antes con el usuario).',
			);
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Activar ---------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/activate-plugin', array(
		'label'               => 'Activar un plugin',
		'description'         => 'Activa un plugin instalado. Si da un error fatal al cargarse no se activa; si después el sitio responde con error 500 (se comprueban la portada y wp-admin), se desactiva solo y lo dice. Antes guarda un snapshot de los plugins activos ("snapshot_id"; restore-snapshot lo deshace) y después purga la caché. Revisa la web con inspect-page o una captura: un plugin puede cambiar el diseño o añadir contenido. Requiere los dos cerrojos de la gestión de plugins.',
		'category'            => 'spiral-mcp-plugins',
		// Pasa también con "settings" para que, sin spiral_mcp_plugins, la respuesta diga qué cerrojo falta
		// (spiral_mcp_plugins_check_locks() exige la capacidad igualmente).
		'group'               => array( 'plugins', 'settings' ),
		'log'                 => array( 'type' => 'plugin', 'id' => 'plugin' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'plugin' ),
			'properties' => array( 'plugin' => $plugin_prop ),
		),
		'permission_callback' => $can_manage,
		'execute_callback'    => function ( $input ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			$file = spiral_mcp_plugin_resolve( $input['plugin'] ?? '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( is_plugin_active( $file ) ) {
				return new WP_Error( 'spiral_mcp_plugin_active', sprintf( '"%s" ya está activo.', $file ) );
			}
			$data   = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$issues = spiral_mcp_plugin_compat_issues( $data['RequiresWP'] ?? '', $data['RequiresPHP'] ?? '' );
			if ( $issues ) {
				return new WP_Error( 'spiral_mcp_plugin_incompatible', sprintf( '"%s" no es compatible con este sitio: %s.', $file, implode( '; ', $issues ) ) );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'plugins_state', 'active_plugins', 'activate-plugin:' . $file );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_plugin_activate_safely( $file );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_plugins_after_change();
			return array(
				'active'      => true,
				'file'        => $file,
				'name'        => wp_strip_all_tags( (string) $data['Name'] ),
				'version'     => (string) $data['Version'],
				'health'      => $res['health'],
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 5. Desactivar ------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/deactivate-plugin', array(
		'label'               => 'Desactivar un plugin',
		'description'         => 'Desactiva un plugin (no lo borra: sus ajustes y datos se conservan). Spiral-MCP-Control y MCP Adapter no se pueden desactivar desde aquí. Antes guarda un snapshot de los plugins activos ("snapshot_id"; restore-snapshot lo vuelve a activar) y después purga la caché y comprueba que el sitio responde. Avisa al usuario de lo que dejará de funcionar (formularios, tienda…). Requiere los dos cerrojos de la gestión de plugins.',
		'category'            => 'spiral-mcp-plugins',
		// Pasa también con "settings" para que, sin spiral_mcp_plugins, la respuesta diga qué cerrojo falta
		// (spiral_mcp_plugins_check_locks() exige la capacidad igualmente).
		'group'               => array( 'plugins', 'settings' ),
		'log'                 => array( 'type' => 'plugin', 'id' => 'plugin' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'plugin' ),
			'properties' => array( 'plugin' => $plugin_prop ),
		),
		'permission_callback' => $can_manage,
		'execute_callback'    => function ( $input ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			$file = spiral_mcp_plugin_resolve( $input['plugin'] ?? '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( in_array( $file, spiral_mcp_plugins_protected(), true ) ) {
				return new WP_Error( 'spiral_mcp_plugin_protected', sprintf( '"%s" está protegido: sin él perderías el acceso. Si hay que desactivarlo, que lo haga el usuario desde wp-admin.', $file ) );
			}
			if ( ! is_plugin_active( $file ) ) {
				return new WP_Error( 'spiral_mcp_plugin_inactive', sprintf( '"%s" ya está desactivado.', $file ) );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'plugins_state', 'active_plugins', 'deactivate-plugin:' . $file );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			deactivate_plugins( $file );
			spiral_mcp_plugins_after_change();
			$health = spiral_mcp_plugins_health();
			$out    = array(
				'active'      => false,
				'file'        => $file,
				'health'      => $health,
				'snapshot_id' => $snapshot,
			);
			if ( ! $health['ok'] ) {
				$out['warnings'] = array( 'Tras desactivarlo, el sitio responde con error: puede que otro plugin dependa de él. Restaura el snapshot para volver a activarlo.' );
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 6. Actualizar ------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-plugin', array(
		'label'               => 'Actualizar un plugin',
		'description'         => 'Actualiza un plugin a la última versión de wordpress.org (list-plugins con status "update" dice cuáles tienen actualización y a qué versión). Exige "confirm": true: díselo antes al usuario, con la versión actual y la nueva. Comprueba que la nueva versión sea compatible con WordPress y PHP. Si el plugin estaba activo, sigue activo y después se comprueban la portada y wp-admin: si responden con error 500, se vuelve a poner la versión anterior (copia temporal de WordPress) y lo dice. Purga la caché. No se puede deshacer con restore-snapshot (es código, no ajustes). Spiral-MCP-Control y MCP Adapter no se actualizan desde aquí, ni los plugins de pago que no vienen de wordpress.org. Requiere los dos cerrojos de la gestión de plugins.',
		'category'            => 'spiral-mcp-plugins',
		// Pasa también con "settings" para que, sin spiral_mcp_plugins, la respuesta diga qué cerrojo falta
		// (spiral_mcp_plugins_check_locks() exige la capacidad igualmente).
		'group'               => array( 'plugins', 'settings' ),
		'log'                 => array( 'type' => 'plugin', 'id' => 'plugin' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'plugin', 'confirm' ),
			'properties' => array(
				'plugin'  => $plugin_prop,
				'confirm' => array( 'type' => 'boolean', 'description' => 'true para actualizar.' ),
			),
		),
		'permission_callback' => $can_manage,
		'execute_callback'    => function ( $input ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			$file = spiral_mcp_plugin_resolve( $input['plugin'] ?? '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( in_array( $file, spiral_mcp_plugins_protected(), true ) ) {
				return new WP_Error( 'spiral_mcp_plugin_protected', sprintf( '"%s" está protegido: actualízalo desde wp-admin (si la actualización fallara, perderías el acceso).', $file ) );
			}
			$confirm = spiral_mcp_require_confirm( $input, 'actualizar el plugin' );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$updates = get_site_transient( 'update_plugins' );
			if ( ! isset( $updates->response[ $file ] ) ) {
				// Puede que la información esté desfasada: se pregunta otra vez a wordpress.org.
				delete_site_transient( 'update_plugins' );
				wp_update_plugins();
				$updates = get_site_transient( 'update_plugins' );
			}
			if ( ! isset( $updates->response[ $file ] ) ) {
				return new WP_Error( 'spiral_mcp_plugin_no_update', sprintf( '"%s" no tiene actualizaciones (o no viene de wordpress.org).', $file ) );
			}
			$update = $updates->response[ $file ];
			if ( empty( $update->package ) || ! spiral_mcp_plugin_package_allowed( $update->package ) ) {
				return new WP_Error( 'spiral_mcp_plugin_source', 'La actualización no se descarga de downloads.wordpress.org (plugin de pago o de otro origen): que la haga el usuario desde wp-admin.' );
			}
			$issues = spiral_mcp_plugin_compat_issues( $update->requires ?? '', $update->requires_php ?? '' );
			if ( $issues ) {
				return new WP_Error( 'spiral_mcp_plugin_incompatible', sprintf( 'La versión %s de "%s" no es compatible con este sitio: %s. No se ha actualizado.', $update->new_version, $file, implode( '; ', $issues ) ) );
			}
			$fs = spiral_mcp_plugins_check_filesystem();
			if ( is_wp_error( $fs ) ) {
				return $fs;
			}
			$data   = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$from   = (string) $data['Version'];
			$active = is_plugin_active( $file );

			// Plugin_Upgrader lo desactiva sin avisar y deja la versión anterior en
			// wp-content/upgrade-temp-backup hasta el final de la petición (WordPress 6.3+).
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $file );
			if ( is_wp_error( $result ) || ! $result ) {
				if ( $active && ! is_plugin_active( $file ) && file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
					spiral_mcp_plugins_set_active( $file );
				}
				$msg = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $upgrader->skin->get_upgrade_messages() );
				return new WP_Error( 'spiral_mcp_plugin_update_failed', 'No se pudo actualizar (WordPress deja la versión anterior si falla la copia): ' . html_entity_decode( wp_strip_all_tags( $msg ), ENT_QUOTES, 'UTF-8' ) );
			}
			$new_file = $upgrader->plugin_info() ? $upgrader->plugin_info() : $file;
			$new_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $new_file, false, false );
			$out      = array(
				'updated' => true,
				'file'    => $new_file,
				'name'    => wp_strip_all_tags( (string) $new_data['Name'] ),
				'from'    => $from,
				'to'      => (string) $new_data['Version'],
				'active'  => $active,
			);
			if ( $active ) {
				// Se vuelve a marcar como activo sin cargarlo aquí (en esta petición sigue cargado el código
				// viejo); la nueva versión se prueba con peticiones nuevas al propio sitio.
				spiral_mcp_plugins_set_active( $new_file );
				$health = spiral_mcp_plugins_health();
				if ( ! $health['ok'] ) {
					$backup = array( 'slug' => dirname( $file ), 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins' );
					$restored = '.' !== $backup['slug'] ? $upgrader->restore_temp_backup( array( $backup ) ) : false;
					if ( true === $restored ) {
						wp_clean_plugins_cache( false );
						spiral_mcp_plugins_set_active( $file );
						$again = spiral_mcp_plugins_health();
					} else {
						deactivate_plugins( $new_file, true );
						$again = spiral_mcp_plugins_health();
					}
					spiral_mcp_plugins_after_change();
					return new WP_Error(
						'spiral_mcp_plugin_update_rolled_back',
						( true === $restored
							? sprintf( 'Con la versión %s el sitio respondía con error 500: se ha vuelto a la %s y sigue activo.', $out['to'], $from )
							: sprintf( 'Con la versión %s el sitio respondía con error 500 y no se pudo recuperar la anterior: el plugin se ha desactivado.', $out['to'] ) )
						. ( $again['ok'] ? ' El sitio vuelve a funcionar.' : ' OJO: el sitio sigue dando error; revísalo ya.' ),
						array( 'before_rollback' => $health['checks'], 'after_rollback' => $again['checks'] )
					);
				}
				$out['health'] = $health;
			}
			spiral_mcp_plugins_after_change();
			return $out;
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	/* 7. Borrar ----------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-plugin', array(
		'label'               => 'Borrar un plugin',
		'description'         => 'Borra un plugin desactivado: sus archivos y, si el plugin tiene rutina de desinstalación, también sus ajustes y datos (tablas, opciones…). IRREVERSIBLE: no hay snapshot; se puede volver a instalar con install-plugin, pero los datos borrados no vuelven. Exige "confirm": true: avisa antes al usuario. Desactívalo antes con spiral-mcp/deactivate-plugin. Spiral-MCP-Control y MCP Adapter no se pueden borrar. Requiere los dos cerrojos de la gestión de plugins.',
		'category'            => 'spiral-mcp-plugins',
		// Pasa también con "settings" para que, sin spiral_mcp_plugins, la respuesta diga qué cerrojo falta
		// (spiral_mcp_plugins_check_locks() exige la capacidad igualmente).
		'group'               => array( 'plugins', 'settings' ),
		'log'                 => array( 'type' => 'plugin', 'id' => 'plugin' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'plugin', 'confirm' ),
			'properties' => array(
				'plugin'  => $plugin_prop,
				'confirm' => array( 'type' => 'boolean', 'description' => 'true para borrar.' ),
			),
		),
		'permission_callback' => $can_manage,
		'execute_callback'    => function ( $input ) {
			$locks = spiral_mcp_plugins_check_locks();
			if ( is_wp_error( $locks ) ) {
				return $locks;
			}
			$file = spiral_mcp_plugin_resolve( $input['plugin'] ?? '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( in_array( $file, spiral_mcp_plugins_protected(), true ) ) {
				return new WP_Error( 'spiral_mcp_plugin_protected', sprintf( '"%s" está protegido: sin él perderías el acceso.', $file ) );
			}
			if ( is_plugin_active( $file ) ) {
				return new WP_Error( 'spiral_mcp_plugin_active', sprintf( '"%s" está activo: desactívalo antes con spiral-mcp/deactivate-plugin.', $file ) );
			}
			$confirm = spiral_mcp_require_confirm( $input, 'borrar el plugin (irreversible)' );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$fs = spiral_mcp_plugins_check_filesystem();
			if ( is_wp_error( $fs ) ) {
				return $fs;
			}
			$data         = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$uninstalls   = is_uninstallable_plugin( $file );
			$level        = ob_get_level();
			try {
				// Ejecuta la desinstalación del plugin (uninstall.php o su hook), que carga su código.
				$res = delete_plugins( array( $file ) );
			} catch ( Throwable $e ) {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
				return new WP_Error( 'spiral_mcp_plugin_fatal', sprintf( 'La desinstalación del plugin da un error fatal y no se ha borrado del todo: %s. Revisa wp-content/plugins desde wp-admin.', $e->getMessage() ) );
			}
			if ( is_wp_error( $res ) || ! $res ) {
				return new WP_Error( 'spiral_mcp_plugin_delete_failed', 'No se pudo borrar: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'WordPress no lo ha permitido.' ) );
			}
			wp_clean_plugins_cache( true );
			return array(
				'deleted'        => true,
				'file'           => $file,
				'name'           => wp_strip_all_tags( (string) $data['Name'] ),
				'version'        => (string) $data['Version'],
				'data_removed'   => $uninstalls,
				'note'           => $uninstalls ? 'El plugin tenía rutina de desinstalación: sus ajustes y datos también se han borrado.' : 'Puede que hayan quedado ajustes suyos en la base de datos (el plugin no tiene rutina de desinstalación).',
			);
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );
} );
