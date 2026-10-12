<?php
/**
 * Cachés, independiente de la herramienta: estado, purga (total, por post o
 * por URL), precarga y ajustes, a través de un adaptador por herramienta.
 *
 * Cada adaptador (filtro spiral_mcp_cache_providers) es un array:
 *   'label'    => nombre visible,
 *   'tested'   => true si se ha probado en un sitio real con esa herramienta,
 *   'detect'   => callable(): bool, ¿está activa en este sitio?,
 *   'supports' => lista de capacidades: purge_all, purge_post, purge_url, preload, read_settings,
 *   'status'   => callable( $with_stats ): array, estado (activa, modo, caducidad…),
 *   'purge_all', 'purge_post' ( $post_id ), 'purge_url' ( $url absoluta ) => callable(): bool,
 *   'preload'  => callable( 'start'|'stop'|'status' ): array|WP_Error,
 *   'settings' => callable(): [ 'common' => […], 'provider_settings' => […] ],
 *   'write_settings' => callable( $common, $provider_settings ): [ 'changed' => […], 'warnings' => […] ]|WP_Error
 *                       (valida todo antes de escribir; capacidad "write_settings").
 * Ajustes comunes: enabled, lifetime (segundos), cache_logged_in, mobile, compression,
 * exclude_urls, clear_on_edit (null si la herramienta no lo tiene).
 *
 * YOOtheme no cachea el HTML renderizado, así que lo que hay que vaciar son las
 * cachés de página de otros plugins (y del hosting).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Registro de adaptadores
 * ---------------------------------------------------------------------- */

/** Todos los adaptadores registrados: id => definición. */
function spiral_mcp_cache_providers() {
	$providers = array( 'wp-super-cache' => spiral_mcp_cache_wpsc_provider() ) + spiral_mcp_cache_other_providers() + array(
		'object-cache'   => array(
			'label'     => 'Caché de objetos (Redis, Memcached…)',
			'tested'    => true,
			'object'    => true, // Solo se vacía al pedirlo expresamente: no es una caché de páginas.
			'detect'    => 'wp_using_ext_object_cache',
			'supports'  => array( 'purge_all' ),
			'purge_all' => 'wp_cache_flush',
		),
	);
	/**
	 * Adaptadores de caché: añade herramientas o reemplaza las incluidas (ver la cabecera de includes/cache.php).
	 */
	$providers = (array) apply_filters( 'spiral_mcp_cache_providers', $providers );
	foreach ( $providers as $id => $p ) {
		if ( ! is_array( $p ) || empty( $p['detect'] ) ) {
			unset( $providers[ $id ] );
		}
	}
	return $providers;
}

/** Adaptadores cuya herramienta está activa en el sitio. */
function spiral_mcp_cache_active_providers() {
	$out = array();
	foreach ( spiral_mcp_cache_providers() as $id => $p ) {
		try {
			if ( call_user_func( $p['detect'] ) ) {
				$out[ $id ] = $p;
			}
		} catch ( Throwable $e ) {
			continue;
		}
	}
	return $out;
}

/** ¿El adaptador admite la capacidad y tiene la función que la implementa? */
function spiral_mcp_cache_can( array $p, $capability ) {
	$fn = array(
		'purge_all'     => 'purge_all',
		'purge_post'    => 'purge_post',
		'purge_url'     => 'purge_url',
		'preload'       => 'preload',
		'read_settings' => 'settings',
		'write_settings' => 'write_settings',
	);
	return in_array( $capability, (array) ( $p['supports'] ?? array() ), true ) && isset( $fn[ $capability ] ) && is_callable( $p[ $fn[ $capability ] ] ?? null );
}

/**
 * Purga en todas las herramientas activas (o solo en $only). $scope: all, post o url.
 * Si una herramienta no admite purgar un post o una URL, purga todo. La caché de
 * objetos solo se vacía con $object_cache = true. Devuelve un resultado por herramienta.
 */
function spiral_mcp_cache_purge( $scope = 'all', $target = null, $object_cache = false, $only = '' ) {
	$results = array();
	foreach ( spiral_mcp_cache_active_providers() as $id => $p ) {
		if ( '' !== $only && $only !== $id ) {
			continue;
		}
		if ( ! empty( $p['object'] ) && ! $object_cache ) {
			continue;
		}
		$done = $scope;
		try {
			if ( 'post' === $scope && spiral_mcp_cache_can( $p, 'purge_post' ) ) {
				$ok = call_user_func( $p['purge_post'], (int) $target );
			} elseif ( 'url' === $scope && spiral_mcp_cache_can( $p, 'purge_url' ) ) {
				$ok = call_user_func( $p['purge_url'], (string) $target );
			} elseif ( 'url' === $scope && spiral_mcp_cache_can( $p, 'purge_post' ) && url_to_postid( (string) $target ) ) {
				// Sin purga por URL, pero la URL es de un contenido: se purga ese contenido.
				$done = 'post';
				$ok   = call_user_func( $p['purge_post'], url_to_postid( (string) $target ) );
			} elseif ( spiral_mcp_cache_can( $p, 'purge_all' ) ) {
				$done = 'all';
				$ok   = call_user_func( $p['purge_all'] );
			} else {
				continue;
			}
			$results[] = array( 'provider' => $id, 'purged' => $done, 'ok' => false !== $ok );
		} catch ( Throwable $e ) {
			$results[] = array( 'provider' => $id, 'purged' => $done, 'ok' => false, 'error' => $e->getMessage() );
		}
	}
	return $results;
}

/**
 * Compatibilidad: vacía todas las cachés de página (y la de objetos si $object_cache).
 * Devuelve los IDs de las herramientas purgadas.
 */
function spiral_mcp_clear_cache( $object_cache = false ) {
	$done = array();
	foreach ( spiral_mcp_cache_purge( 'all', null, $object_cache ) as $r ) {
		if ( $r['ok'] ) {
			$done[] = $r['provider'];
		}
	}
	/**
	 * Para añadir otras cachés (obsoleto: mejor un adaptador con spiral_mcp_cache_providers).
	 */
	return (array) apply_filters( 'spiral_mcp_clear_cache', $done, $object_cache );
}

/**
 * Purga automática tras una escritura correcta (filtro spiral_mcp_auto_clear_cache).
 * Si la escritura afecta a un solo post (contenido, su layout, su SEO…), purga ese post
 * y lo que la herramienta asocie (portada, listados); si es de diseño global, todo.
 */
function spiral_mcp_maybe_auto_clear_cache( $ability, array $groups, $input = null, $result = null, array $log = array() ) {
	// Las abilities de caché (purgar, precargar, ajustes) ya hacen lo suyo: purgar todo después lo desharía.
	if ( 'cache' === ( $log['type'] ?? '' ) || ! array_intersect( $groups, array( 'content', 'design', 'media' ) ) ) {
		return;
	}
	if ( ! apply_filters( 'spiral_mcp_auto_clear_cache', true, $ability ) ) {
		return;
	}
	$post_id = 0;
	if ( in_array( $log['type'] ?? '', array( 'post', 'layout', 'seo' ), true ) && empty( ( (array) $input )['area'] ) ) {
		foreach ( array( (array) $result, (array) $input ) as $src ) {
			foreach ( array( 'id', 'post_id' ) as $key ) {
				if ( ! $post_id && isset( $src[ $key ] ) && is_numeric( $src[ $key ] ) ) {
					$post_id = (int) $src[ $key ];
				}
			}
		}
	}
	if ( $post_id && get_post( $post_id ) ) {
		spiral_mcp_cache_purge( 'post', $post_id );
	} else {
		spiral_mcp_cache_purge( 'all' );
	}
}

/** Número de archivos y bytes de una carpeta (hasta $max archivos, para no colgar la petición). */
function spiral_mcp_cache_dir_stats( $dir, $max = 50000 ) {
	$files = 0;
	$bytes = 0;
	if ( ! is_dir( $dir ) ) {
		return array( 'files' => 0, 'bytes' => 0, 'truncated' => false );
	}
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$files++;
				$bytes += $file->getSize();
				if ( $files >= $max ) {
					return array( 'files' => $files, 'bytes' => $bytes, 'truncated' => true );
				}
			}
		}
	} catch ( Throwable $e ) {
		return array( 'files' => $files, 'bytes' => $bytes, 'truncated' => true );
	}
	return array( 'files' => $files, 'bytes' => $bytes, 'truncated' => false );
}

/* -------------------------------------------------------------------------
 * Adaptador: WP Super Cache
 * ---------------------------------------------------------------------- */

function spiral_mcp_cache_wpsc_provider() {
	return array(
		'label'      => 'WP Super Cache',
		'tested'     => true,
		'detect'     => function () {
			return function_exists( 'wp_cache_clear_cache' );
		},
		'supports'   => array( 'purge_all', 'purge_post', 'purge_url', 'preload', 'read_settings', 'write_settings' ),
		'status'     => 'spiral_mcp_cache_wpsc_status',
		'purge_all'  => function () {
			wp_cache_clear_cache();
			return true;
		},
		// wp_cache_post_change borra el post, su autor, la portada y sus listados (según los ajustes).
		'purge_post' => function ( $post_id ) {
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $post_id );
				return true;
			}
			return function_exists( 'wpsc_delete_post_cache' ) ? (bool) wpsc_delete_post_cache( $post_id ) : false;
		},
		'purge_url'  => function ( $url ) {
			return function_exists( 'wpsc_delete_url_cache' ) ? (bool) wpsc_delete_url_cache( $url ) : false;
		},
		'preload'    => 'spiral_mcp_cache_wpsc_preload',
		'settings'   => 'spiral_mcp_cache_wpsc_settings',
		'write_settings' => 'spiral_mcp_cache_wpsc_write',
	);
}

/** Valor de un ajuste de WP Super Cache (variables globales cargadas de wp-cache-config.php). */
function spiral_mcp_cache_wpsc_get( $name, $default = null ) {
	return array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : $default;
}

function spiral_mcp_cache_wpsc_mode() {
	if ( ! spiral_mcp_cache_wpsc_get( 'super_cache_enabled' ) ) {
		return 'wp-cache'; // Solo la caché PHP de WP Super Cache.
	}
	return 1 === (int) spiral_mcp_cache_wpsc_get( 'wp_cache_mod_rewrite' ) ? 'expert' : 'simple';
}

function spiral_mcp_cache_wpsc_status( $with_stats ) {
	$status = array(
		'enabled'         => (bool) spiral_mcp_cache_wpsc_get( 'cache_enabled' ),
		'mode'            => spiral_mcp_cache_wpsc_mode(),
		'lifetime'        => (int) spiral_mcp_cache_wpsc_get( 'cache_max_time', 0 ),
		'wp_cache_const'  => defined( 'WP_CACHE' ) && WP_CACHE,
		'advanced_cache'  => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		'preload_running' => function_exists( 'wpsc_is_preloading' ) ? (bool) wpsc_is_preloading() : null,
	);
	if ( ! $status['wp_cache_const'] || ! $status['advanced_cache'] ) {
		$status['problem'] = 'WP_CACHE no está activo en wp-config.php o falta wp-content/advanced-cache.php: la caché no funcionará aunque esté activada. Hay que revisarlo en Ajustes > WP Super Cache.';
	}
	if ( $with_stats ) {
		$path            = (string) spiral_mcp_cache_wpsc_get( 'cache_path', WP_CONTENT_DIR . '/cache/' );
		$status['stats'] = spiral_mcp_cache_dir_stats( trailingslashit( $path ) . 'supercache' );
	}
	return $status;
}

function spiral_mcp_cache_wpsc_settings() {
	$not_logged = (int) spiral_mcp_cache_wpsc_get( 'wp_cache_not_logged_in', 0 );
	return array(
		'common'            => array(
			'enabled'         => (bool) spiral_mcp_cache_wpsc_get( 'cache_enabled' ),
			'lifetime'        => (int) spiral_mcp_cache_wpsc_get( 'cache_max_time', 0 ),
			'cache_logged_in' => 0 === $not_logged,
			'mobile'          => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_mobile_enabled' ),
			'compression'     => (bool) spiral_mcp_cache_wpsc_get( 'cache_compression' ),
			'exclude_urls'    => array_values( array_filter( (array) spiral_mcp_cache_wpsc_get( 'cache_rejected_uri', array() ) ) ),
			'clear_on_edit'   => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_clear_on_post_edit' ),
		),
		'provider_settings' => array(
			'mode'                     => spiral_mcp_cache_wpsc_mode(),
			'dont_cache_logged_in'     => $not_logged, // 0 = cachear a todos, 1 = no a usuarios con sesión, 2 = tampoco a visitantes conocidos.
			'refresh_single_only'      => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_refresh_single_only' ),
			'rebuild_files'            => (bool) spiral_mcp_cache_wpsc_get( 'cache_rebuild_files' ),
			'no_cache_for_get'         => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_no_cache_for_get' ),
			'make_known_anon'          => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_make_known_anon' ),
			'preload_on'               => (bool) spiral_mcp_cache_wpsc_get( 'wp_cache_preload_on' ),
			'preload_interval_minutes' => (int) spiral_mcp_cache_wpsc_get( 'wp_cache_preload_interval', 0 ),
			'preload_posts'            => spiral_mcp_cache_wpsc_get( 'wp_cache_preload_posts', 'all' ),
			'rejected_user_agents'     => array_values( array_filter( (array) spiral_mcp_cache_wpsc_get( 'cache_rejected_user_agent', array() ) ) ),
		),
	);
}

function spiral_mcp_cache_wpsc_preload( $action ) {
	if ( ! function_exists( 'wpsc_get_preload_status' ) ) {
		return new WP_Error( 'spiral_mcp_cache_unsupported', 'Esta versión de WP Super Cache no tiene precarga.' );
	}
	if ( 'start' === $action ) {
		if ( ! spiral_mcp_cache_wpsc_get( 'cache_enabled' ) || 'wp-cache' === spiral_mcp_cache_wpsc_mode() ) {
			return new WP_Error( 'spiral_mcp_cache_disabled', 'La precarga de WP Super Cache necesita la caché activada en modo Simple o Expert. Mira get-cache-settings y pide al usuario que la active (o usa update-cache-settings si tienes permiso).' );
		}
		wpsc_enable_preload();
	} elseif ( 'stop' === $action ) {
		wpsc_cancel_preload();
	}
	$status = wpsc_get_preload_status( true );
	return array(
		'running'      => function_exists( 'wpsc_is_preloading' ) ? (bool) wpsc_is_preloading() : (bool) ( $status['running'] ?? false ),
		'scheduled_at' => ! empty( $status['next'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $status['next'] ) : null,
		'last_run'     => $status['previous'] ?? null,
		'note'         => 'start' === $action ? 'La precarga se lanza con WP-Cron en unos segundos y recorre las entradas publicadas en segundo plano; consulta su avance con "action": "status".' : null,
	);
}

/**
 * Actualizador de ajustes de WP Super Cache: reutiliza la clase de su API REST (sus métodos
 * hacen los efectos secundarios correctos: vaciar la caché al cambiar la compresión, programar
 * la recogida de basura…) sin su comprobación de permisos (manage_options) ni la parte de depuración.
 */
function spiral_mcp_cache_wpsc_updater() {
	if ( ! class_exists( 'WP_Super_Cache_Rest_Update_Settings' ) ) {
		$dir = dirname( ( new ReflectionFunction( 'wp_cache_clear_cache' ) )->getFileName() ) . '/rest/';
		foreach ( array( 'class.wp-super-cache-settings-map.php', 'class.wp-super-cache-rest-get-settings.php', 'class.wp-super-cache-rest-update-settings.php' ) as $file ) {
			if ( is_file( $dir . $file ) ) {
				require_once $dir . $file;
			}
		}
	}
	if ( ! class_exists( 'WP_Super_Cache_Rest_Update_Settings' ) ) {
		return new WP_Error( 'spiral_mcp_cache_unsupported', 'Esta versión de WP Super Cache no permite cambiar ajustes desde aquí: hazlo en Ajustes > WP Super Cache.' );
	}
	if ( ! class_exists( 'Spiral_MCP_WPSC_Updater' ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		class Spiral_MCP_WPSC_Updater extends WP_Super_Cache_Rest_Update_Settings {
			/** Aplica ajustes por su clave de la API REST de WP Super Cache. Devuelve los errores. */
			public function apply( array $params ) {
				global $valid_nonce;
				$valid_nonce = true;
				$errors      = array();
				foreach ( $params as $key => $value ) {
					$error = $this->set_value_by_key( $value, $key );
					if ( null !== $error && ! is_bool( $error ) && ! is_numeric( $error ) ) {
						$errors[] = (string) $error;
					}
				}
				$this->save_time_settings( $params );
				$this->save_preload_settings();
				return $errors;
			}
		}
	}
	return new Spiral_MCP_WPSC_Updater();
}

/** Escribe ajustes de WP Super Cache (vocabulario común + provider_settings). Valida todo antes. */
function spiral_mcp_cache_wpsc_write( array $common, array $provider ) {
	$bool_common = array( 'enabled', 'cache_logged_in', 'mobile', 'compression', 'clear_on_edit' );
	$bool_prov   = array( 'refresh_single_only', 'rebuild_files', 'no_cache_for_get', 'make_known_anon', 'preload_on' );
	$known_prov  = array_merge( $bool_prov, array( 'mode', 'dont_cache_logged_in', 'preload_interval_minutes', 'preload_posts' ) );
	$read_only   = array( 'rejected_user_agents' );

	foreach ( array_keys( $common ) as $key ) {
		if ( ! in_array( $key, array_merge( $bool_common, array( 'lifetime', 'exclude_urls' ) ), true ) ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( 'Ajuste "%s" desconocido. Comunes: enabled, lifetime, cache_logged_in, mobile, compression, exclude_urls, clear_on_edit.', $key ) );
		}
	}
	foreach ( array_keys( $provider ) as $key ) {
		if ( in_array( $key, $read_only, true ) ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" es de solo lectura desde aquí: cámbialo en Ajustes > WP Super Cache.', $key ) );
		}
		if ( ! in_array( $key, $known_prov, true ) ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( 'Ajuste "%s" desconocido para WP Super Cache. Admite: %s.', $key, implode( ', ', $known_prov ) ) );
		}
	}
	foreach ( array( array( $common, $bool_common ), array( $provider, $bool_prov ) ) as $pair ) {
		foreach ( $pair[1] as $key ) {
			if ( isset( $pair[0][ $key ] ) && ! is_bool( $pair[0][ $key ] ) ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" debe ser true o false.', $key ) );
			}
		}
	}
	if ( isset( $common['lifetime'] ) && ( ! is_int( $common['lifetime'] ) || $common['lifetime'] < 0 || $common['lifetime'] > YEAR_IN_SECONDS ) ) {
		return new WP_Error( 'spiral_mcp_cache_bad_setting', '"lifetime" son segundos (0 = sin caducidad; máximo un año).' );
	}
	if ( isset( $common['exclude_urls'] ) ) {
		if ( ! is_array( $common['exclude_urls'] ) || count( $common['exclude_urls'] ) > 200 ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', '"exclude_urls" debe ser una lista (máximo 200).' );
		}
		foreach ( $common['exclude_urls'] as $rule ) {
			if ( ! is_string( $rule ) || '' === trim( $rule ) || strlen( $rule ) > 300 || preg_match( '/[
]/', $rule ) ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', 'Cada regla de "exclude_urls" es un texto de una línea (en WP Super Cache, un fragmento de expresión regular, p. ej. "/contacto/" o "wp-.*\.php").' );
			}
		}
	}
	$mode = $provider['mode'] ?? null;
	if ( null !== $mode ) {
		if ( 'expert' === $mode ) {
			return new WP_Error( 'spiral_mcp_cache_expert', 'El modo Expert reescribe .htaccess y un fallo puede dejar el sitio caído: no se activa desde aquí. Si el usuario lo quiere, que lo haga en Ajustes > WP Super Cache.' );
		}
		if ( ! in_array( $mode, array( 'simple', 'wp-cache' ), true ) ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', '"mode" debe ser "simple" o "wp-cache".' );
		}
		if ( 'expert' === spiral_mcp_cache_wpsc_mode() && $mode !== 'expert' ) {
			return new WP_Error( 'spiral_mcp_cache_expert', 'El sitio está en modo Expert (reglas en .htaccess): el cambio de modo hay que hacerlo en Ajustes > WP Super Cache.' );
		}
	}
	if ( isset( $provider['dont_cache_logged_in'] ) && ! in_array( $provider['dont_cache_logged_in'], array( 0, 1, 2 ), true ) ) {
		return new WP_Error( 'spiral_mcp_cache_bad_setting', '"dont_cache_logged_in": 0 = cachear a todos, 1 = no a usuarios con sesión, 2 = tampoco a visitantes conocidos (que han comentado).' );
	}
	if ( isset( $provider['dont_cache_logged_in'], $common['cache_logged_in'] ) && ( 0 === $provider['dont_cache_logged_in'] ) !== $common['cache_logged_in'] ) {
		return new WP_Error( 'spiral_mcp_cache_bad_setting', '"cache_logged_in" y "provider_settings.dont_cache_logged_in" se contradicen: envía solo uno.' );
	}
	if ( isset( $provider['preload_interval_minutes'] ) ) {
		$min = function_exists( 'wpsc_get_minimum_preload_interval' ) ? (int) wpsc_get_minimum_preload_interval() : 10;
		if ( ! is_int( $provider['preload_interval_minutes'] ) || ( 0 !== $provider['preload_interval_minutes'] && $provider['preload_interval_minutes'] < $min ) ) {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"preload_interval_minutes": 0 = sin repetir, o al menos %d minutos.', $min ) );
		}
	}
	if ( isset( $provider['preload_posts'] ) && 'all' !== $provider['preload_posts'] && ( ! is_int( $provider['preload_posts'] ) || $provider['preload_posts'] < 0 ) ) {
		return new WP_Error( 'spiral_mcp_cache_bad_setting', '"preload_posts": "all" o un número de entradas.' );
	}

	// Claves de la API REST de WP Super Cache (sus métodos hacen los efectos secundarios).
	$params = array();
	if ( isset( $common['enabled'] ) ) {
		$params['is_cache_enabled'] = $common['enabled'] ? 1 : 0;
	}
	if ( null !== $mode ) {
		$params['cache_type'] = 'simple' === $mode ? 'PHP' : 'wpcache';
	}
	if ( isset( $provider['dont_cache_logged_in'] ) ) {
		$params['dont_cache_logged_in'] = $provider['dont_cache_logged_in'];
	} elseif ( isset( $common['cache_logged_in'] ) ) {
		$current                        = (int) spiral_mcp_cache_wpsc_get( 'wp_cache_not_logged_in', 0 );
		$params['dont_cache_logged_in'] = $common['cache_logged_in'] ? 0 : ( $current ? $current : 1 );
	}
	if ( isset( $provider['make_known_anon'] ) ) {
		$params['make_known_anon'] = $provider['make_known_anon'] ? 1 : 0;
	}
	if ( isset( $common['compression'] ) ) {
		$params['cache_compression'] = $common['compression'] ? 1 : 0;
	}
	if ( isset( $common['exclude_urls'] ) ) {
		$params['cache_rejected_uri'] = array_values( $common['exclude_urls'] );
	}
	if ( isset( $common['lifetime'] ) ) {
		$params['cache_max_time'] = $common['lifetime'];
		// Al guardar la caducidad, WP Super Cache activa el aviso por correo de la recogida de basura
		// si no se le pasa su valor actual (lo rellena con 0 y lo trata como "enviado").
		$params['cache_gc_email_me'] = (int) spiral_mcp_cache_wpsc_get( 'cache_gc_email_me', 0 );
	}
	foreach ( array( 'preload_on' => 'preload_on', 'preload_interval_minutes' => 'preload_interval', 'preload_posts' => 'preload_posts' ) as $ours => $theirs ) {
		if ( isset( $provider[ $ours ] ) ) {
			$params[ $theirs ] = is_bool( $provider[ $ours ] ) ? (int) $provider[ $ours ] : $provider[ $ours ];
		}
	}
	// Al guardar la precarga, WP Super Cache activa las casillas que no se le pasan (las rellena con su
	// valor y luego solo mira si están): se le pasan siempre con su valor actual.
	if ( array_intersect_key( $params, array_flip( array( 'preload_on', 'preload_interval', 'preload_posts' ) ) ) ) {
		$params += array(
			'preload_on'         => (int) spiral_mcp_cache_wpsc_get( 'wp_cache_preload_on', 0 ),
			'preload_taxonomies' => (int) spiral_mcp_cache_wpsc_get( 'wp_cache_preload_taxonomies', 0 ),
		);
	}
	// Ajustes simples (la API REST de WP Super Cache los pierde: compara su clave con el nombre de la variable).
	$globals = array();
	foreach ( array( 'mobile' => 'wp_cache_mobile_enabled', 'clear_on_edit' => 'wp_cache_clear_on_post_edit' ) as $ours => $global ) {
		if ( isset( $common[ $ours ] ) ) {
			$globals[ $global ] = $common[ $ours ] ? 1 : 0;
		}
	}
	foreach ( array( 'rebuild_files' => 'cache_rebuild_files', 'refresh_single_only' => 'wp_cache_refresh_single_only', 'no_cache_for_get' => 'wp_cache_no_cache_for_get' ) as $ours => $global ) {
		if ( isset( $provider[ $ours ] ) ) {
			$globals[ $global ] = $provider[ $ours ] ? 1 : 0;
		}
	}

	$before = spiral_mcp_cache_wpsc_settings();
	$errors = array();
	if ( $params ) {
		$updater = spiral_mcp_cache_wpsc_updater();
		if ( is_wp_error( $updater ) ) {
			return $updater;
		}
		$saved_post    = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		try {
			$errors = $updater->apply( $params );
		} catch ( Throwable $e ) {
			$errors[] = $e->getMessage();
		}
		$_POST    = $saved_post;
		$_REQUEST = $saved_request;
	}
	foreach ( $globals as $global => $value ) {
		wp_cache_setting( $global, $value );
	}
	$after   = spiral_mcp_cache_wpsc_settings();
	$changed = array();
	foreach ( array( 'common', 'provider_settings' ) as $part ) {
		foreach ( $after[ $part ] as $key => $value ) {
			if ( ( $before[ $part ][ $key ] ?? null ) !== $value ) {
				$changed[] = ( 'common' === $part ? '' : 'provider_settings.' ) . $key;
			}
		}
	}
	$warnings = $errors;
	if ( ! empty( $after['common']['enabled'] ) && ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) || ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) ) {
		$warnings[] = 'La caché está activada pero WP_CACHE no está en wp-config.php o falta advanced-cache.php: no funcionará hasta que se arregle en Ajustes > WP Super Cache.';
	}
	return array( 'changed' => $changed, 'warnings' => $warnings );
}

/* -------------------------------------------------------------------------
 * Snapshots: ajustes de una herramienta de caché (ref = ID del adaptador)
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['cache_settings'] = array(
		'group'   => 'cache',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$p = spiral_mcp_cache_providers()[ $ref ] ?? null;
			return $p && spiral_mcp_cache_can( $p, 'read_settings' ) ? call_user_func( $p['settings'] ) : null;
		},
		'restore' => function ( $ref, $data ) {
			$p = spiral_mcp_cache_active_providers()[ $ref ] ?? null;
			if ( ! $p || ! spiral_mcp_cache_can( $p, 'write_settings' ) ) {
				return new WP_Error( 'spiral_mcp_cache_unknown', sprintf( 'La herramienta de caché "%s" ya no está activa o no permite cambiar ajustes.', $ref ) );
			}
			$provider = (array) ( $data['provider_settings'] ?? array() );
			// Solo lo que se puede escribir: lo de solo lectura y el modo Expert se dejan como estén.
			unset( $provider['rejected_user_agents'] );
			if ( 'expert' === ( $provider['mode'] ?? '' ) ) {
				unset( $provider['mode'] );
			}
			$res = call_user_func( $p['write_settings'], (array) ( $data['common'] ?? array() ), $provider );
			return $res;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can_any = function () {
		return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
	};

	/* 1. Estado ----------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-cache-status', array(
		'label'               => 'Estado de las cachés',
		'description'         => 'Dice qué herramientas de caché hay activas en el sitio (plugins como WP Super Cache, WP Rocket, LiteSpeed Cache, W3 Total Cache, o la caché de objetos) y, para cada una: qué se puede hacer con ella desde aquí ("supports": purge_all, purge_post, purge_url, preload, read_settings, write_settings), si se ha probado ("tested") y su estado (activada, modo, caducidad, precarga…). Con "include_stats": true añade cuántos archivos y bytes ocupa la caché cuando la herramienta lo permite. Úsala antes de purgar, precargar o tocar ajustes de caché. Si "providers" está vacío, el sitio no tiene caché de páginas.',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'content', 'design', 'media', 'settings' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'include_stats' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => $can_any,
		'execute_callback'    => function ( $input = array() ) {
			$out = array();
			foreach ( spiral_mcp_cache_active_providers() as $id => $p ) {
				$row = array(
					'id'       => $id,
					'label'    => (string) ( $p['label'] ?? $id ),
					'supports' => array_values( array_filter( array( 'purge_all', 'purge_post', 'purge_url', 'preload', 'read_settings', 'write_settings' ), function ( $c ) use ( $p ) {
						return spiral_mcp_cache_can( $p, $c );
					} ) ),
					'tested'   => ! empty( $p['tested'] ),
				);
				if ( isset( $p['status'] ) && is_callable( $p['status'] ) ) {
					try {
						$row['status'] = call_user_func( $p['status'], ! empty( ( (array) $input )['include_stats'] ) );
					} catch ( Throwable $e ) {
						$row['status'] = array( 'error' => $e->getMessage() );
					}
				}
				$out[] = $row;
			}
			return array(
				'providers' => $out,
				'note'      => $out ? null : 'No hay ninguna herramienta de caché de páginas compatible activa: los cambios se ven al instante.',
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Purgar ----------------------------------------------------------- */
	$purge = function ( $input = array() ) {
		$input = (array) $input;
		$scope = (string) ( $input['scope'] ?? 'all' );
		$target = null;
		if ( 'post' === $scope ) {
			$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
			if ( ! $post ) {
				return new WP_Error( 'spiral_mcp_not_found', 'Con "scope": "post" indica "post_id" con el ID de un contenido existente.' );
			}
			$target = $post->ID;
		} elseif ( 'url' === $scope ) {
			$path = (string) ( $input['url'] ?? '' );
			if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) {
				return new WP_Error( 'spiral_mcp_bad_path', 'Con "scope": "url" indica "url" como ruta relativa del sitio, p. ej. /contacto/.' );
			}
			$target = home_url( $path );
		}
		$only = (string) ( $input['provider'] ?? '' );
		if ( '' !== $only && ! isset( spiral_mcp_cache_active_providers()[ $only ] ) ) {
			return new WP_Error( 'spiral_mcp_cache_unknown', sprintf( 'La herramienta "%s" no está activa. Mira spiral-mcp/get-cache-status.', $only ) );
		}
		$object  = array_key_exists( 'object_cache', $input ) ? ! empty( $input['object_cache'] ) : 'all' === $scope;
		$results = spiral_mcp_cache_purge( $scope, $target, $object, $only );
		$out     = array(
			'scope'   => $scope,
			'results' => $results,
		);
		if ( ! $results ) {
			$out['note'] = 'No hay herramientas de caché activas: no había nada que purgar.';
		}
		foreach ( $results as $r ) {
			if ( $r['purged'] !== $scope ) {
				$out['note'] = 'Alguna herramienta no sabe purgar solo un post o una URL y ha purgado todo (ver "purged").';
			}
		}
		return $out;
	};

	spiral_mcp_register_ability( 'spiral-mcp/purge-cache', array(
		'label'               => 'Purgar cachés',
		'description'         => 'Vacía la caché de páginas en todas las herramientas de caché activas (o solo en "provider"): "scope": "all" (todo; por defecto también la caché de objetos), "post" (un contenido por "post_id", y lo que la herramienta asocie: portada, listados, autor) o "url" (una ruta relativa, p. ej. /contacto/). Si una herramienta no sabe purgar solo un post o una URL, purga todo y lo indica en "purged". Las escrituras de las abilities ya purgan solas lo que cambian; úsala si un cambio no se ve. Los cambios de estilos de YOOtheme no se ven hasta recompilar el CSS (get-style-status).',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'content', 'design', 'media' ),
		'log'                 => array( 'type' => 'cache', 'id' => function ( $in ) {
			$in = (array) $in;
			return ( $in['scope'] ?? 'all' ) . ( isset( $in['post_id'] ) ? ':' . $in['post_id'] : ( isset( $in['url'] ) ? ':' . $in['url'] : '' ) );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'scope'        => array( 'type' => 'string', 'enum' => array( 'all', 'post', 'url' ), 'default' => 'all' ),
				'post_id'      => array( 'type' => 'integer' ),
				'url'          => array( 'type' => 'string', 'description' => 'Ruta relativa, p. ej. /contacto/.' ),
				'provider'     => array( 'type' => 'string', 'description' => 'Solo esta herramienta (ID de get-cache-status).' ),
				'object_cache' => array( 'type' => 'boolean', 'description' => 'Vaciar también la caché de objetos (por defecto, solo con scope all).' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$input = (array) $input;
			if ( 'post' === ( $input['scope'] ?? '' ) && ! empty( $input['post_id'] ) ) {
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			}
			return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => $purge,
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 3. Alias obsoleto --------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/clear-cache', array(
		'label'               => 'Vaciar cachés (obsoleta)',
		'description'         => 'Obsoleta: usa spiral-mcp/purge-cache. Vacía todas las cachés de página activas y la caché de objetos. Devuelve qué limpió ("cleared").',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'content', 'design', 'media' ),
		'log'                 => array( 'type' => 'cache', 'id' => function () {
			return 'all';
		} ),
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can_any,
		'execute_callback'    => function () {
			return array( 'cleared' => spiral_mcp_clear_cache( true ) );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 4. Precarga --------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/preload-cache', array(
		'label'               => 'Precargar la caché',
		'description'         => 'Precarga la caché de páginas (genera de antemano la versión en caché de las páginas publicadas para que el primer visitante no espere): "action": "start" la lanza en segundo plano, "stop" la detiene y "status" dice si está en marcha. Solo en herramientas con "preload" en get-cache-status; con varias, indica "provider". Lanzarla consume recursos del servidor durante un rato: hazlo tras cambios grandes, no después de cada edición.',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'design', 'settings' ),
		'log'                 => array( 'type' => 'cache', 'id' => 'action' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array( 'type' => 'string', 'enum' => array( 'start', 'stop', 'status' ), 'default' => 'status' ),
				'provider' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input  = (array) $input;
			$action = (string) ( $input['action'] ?? 'status' );
			$only   = (string) ( $input['provider'] ?? '' );
			$out    = array();
			foreach ( spiral_mcp_cache_active_providers() as $id => $p ) {
				if ( ( '' !== $only && $only !== $id ) || ! spiral_mcp_cache_can( $p, 'preload' ) ) {
					continue;
				}
				$res = call_user_func( $p['preload'], $action );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$out[] = array( 'provider' => $id ) + (array) $res;
			}
			if ( ! $out ) {
				return new WP_Error( 'spiral_mcp_cache_unsupported', 'Ninguna herramienta de caché activa admite precarga' . ( '' !== $only ? ' (o "provider" no es una de ellas)' : '' ) . '. Mira spiral-mcp/get-cache-status.' );
			}
			return array( 'action' => $action, 'results' => $out );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 5. Ajustes ---------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-cache-settings', array(
		'label'               => 'Ajustes de caché',
		'description'         => 'Devuelve los ajustes de cada herramienta de caché activa que lo admita ("read_settings" en get-cache-status): "common" con el vocabulario común (enabled, lifetime en segundos, cache_logged_in, mobile, compression, exclude_urls, clear_on_edit; null = la herramienta no lo tiene) y "provider_settings" con lo propio de cada una. Solo lectura.',
		'category'            => 'spiral-mcp-settings',
		'group'               => array( 'design', 'settings' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'provider' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$only = (string) ( ( (array) $input )['provider'] ?? '' );
			$out  = array();
			foreach ( spiral_mcp_cache_active_providers() as $id => $p ) {
				if ( ( '' !== $only && $only !== $id ) || ! spiral_mcp_cache_can( $p, 'read_settings' ) ) {
					continue;
				}
				$out[] = array( 'provider' => $id, 'label' => (string) ( $p['label'] ?? $id ) ) + (array) call_user_func( $p['settings'] );
			}
			return array(
				'providers' => $out,
				'note'      => $out ? null : 'Ninguna herramienta de caché activa permite leer sus ajustes desde aquí.',
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 6. Cambiar ajustes -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-cache-settings', array(
		'label'               => 'Cambiar ajustes de caché',
		'description'         => 'Cambia los ajustes de una herramienta de caché ("write_settings" en get-cache-status). Primero lee los actuales con spiral-mcp/get-cache-settings. "settings" usa el vocabulario común (enabled, lifetime en segundos, cache_logged_in, mobile, compression, exclude_urls, clear_on_edit) y "provider_settings" lo propio de la herramienta; solo cambian las claves enviadas. Activar o desactivar la caché, o cambiar la caducidad, afecta a cómo se sirve todo el sitio: hazlo solo si el usuario lo pide. Los modos que reescriben .htaccess o la configuración del servidor (Expert de WP Super Cache…) no se pueden activar desde aquí. Guarda antes un snapshot ("snapshot_id"; se deshace con restore-snapshot). Requiere el permiso de caché (spiral_mcp_cache), que el agente no tiene por defecto: si falta, pide al usuario que lo cambie él o que te lo conceda.',
		'category'            => 'spiral-mcp-settings',
		'group'               => 'cache',
		'log'                 => array( 'type' => 'cache', 'id' => function ( $in, $res ) {
			return 'settings:' . ( is_array( $res ) ? ( $res['provider'] ?? '' ) : ( ( (array) $in )['provider'] ?? '' ) );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'provider'          => array( 'type' => 'string', 'description' => 'ID de la herramienta (get-cache-status). Obligatorio si hay varias que permiten cambiar ajustes.' ),
				'settings'          => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'         => array( 'type' => 'boolean' ),
						'lifetime'        => array( 'type' => 'integer', 'minimum' => 0 ),
						'cache_logged_in' => array( 'type' => 'boolean' ),
						'mobile'          => array( 'type' => 'boolean' ),
						'compression'     => array( 'type' => 'boolean' ),
						'exclude_urls'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'clear_on_edit'   => array( 'type' => 'boolean' ),
					),
				),
				'provider_settings' => array( 'type' => 'object', 'description' => 'Ajustes propios de la herramienta (ver get-cache-settings).' ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input    = (array) $input;
			$common   = (array) ( $input['settings'] ?? array() );
			$provider = (array) ( $input['provider_settings'] ?? array() );
			if ( ! $common && ! $provider ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "settings" o "provider_settings" con lo que quieras cambiar (ver spiral-mcp/get-cache-settings).' );
			}
			$writable = array_filter( spiral_mcp_cache_active_providers(), function ( $p ) {
				return spiral_mcp_cache_can( $p, 'write_settings' );
			} );
			$id = (string) ( $input['provider'] ?? '' );
			if ( '' === $id ) {
				if ( 1 !== count( $writable ) ) {
					return new WP_Error( 'spiral_mcp_cache_unsupported', $writable ? 'Hay varias herramientas que permiten cambiar ajustes: indica "provider" (' . implode( ', ', array_keys( $writable ) ) . ').' : 'Ninguna herramienta de caché activa permite cambiar ajustes desde aquí. Mira spiral-mcp/get-cache-status.' );
				}
				$id = (string) key( $writable );
			} elseif ( ! isset( $writable[ $id ] ) ) {
				return new WP_Error( 'spiral_mcp_cache_unsupported', sprintf( '"%s" no está activa o no permite cambiar ajustes desde aquí.', $id ) );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'cache_settings', $id, 'update-cache-settings' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = call_user_func( $writable[ $id ]['write_settings'], $common, $provider );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array(
				'provider'    => $id,
				'changed'     => (array) ( $res['changed'] ?? array() ),
				'settings'    => call_user_func( $writable[ $id ]['settings'] ),
				'snapshot_id' => $snapshot,
				'warnings'    => (array) ( $res['warnings'] ?? array() ),
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
