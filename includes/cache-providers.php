<?php
/**
 * Adaptadores de caché para herramientas distintas de WP Super Cache (que está
 * en cache.php): purga y estado. Formato de cada adaptador: ver la cabecera de
 * includes/cache.php. "tested" = comprobado en un WordPress real que la purga tiene efecto:
 * WP Fastest Cache 1.5.3 y W3 Total Cache 2.10.7 (archivos y páginas regeneradas), LiteSpeed Cache
 * 7.9.1 (cabeceras X-LiteSpeed-Purge). SiteGround Optimizer y Nginx Helper: sus llamadas funcionan,
 * pero el efecto solo existe en su hosting o con Nginx. WP Rocket y los hostings: sin probar.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** URL de un post para las herramientas que solo saben purgar por URL. */
function spiral_mcp_cache_post_url( $post_id ) {
	$url = get_permalink( (int) $post_id );
	return $url ? $url : '';
}

/** ¿El servidor es LiteSpeed/OpenLiteSpeed? (LiteSpeed Cache solo cachea ahí.) */
function spiral_mcp_cache_is_litespeed_server() {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	return false !== stripos( $software, 'litespeed' ) || isset( $_SERVER['X-LSCACHE'] ) || isset( $_SERVER['LSWS_EDITION'] );
}

/** Adaptadores de las demás herramientas: id => definición. */
function spiral_mcp_cache_other_providers() {
	return array(

		/* WP Rocket (de pago: sin probar). */
		'wp-rocket'          => array(
			'label'      => 'WP Rocket',
			'tested'     => false,
			'detect'     => function () {
				return function_exists( 'rocket_clean_domain' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url', 'read_settings', 'write_settings' ),
			'status'     => function () {
				return array(
					'enabled' => true,
					'version' => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null,
				);
			},
			'settings'       => 'spiral_mcp_cache_rocket_settings',
			'write_settings' => 'spiral_mcp_cache_rocket_write',
			'writable'       => array(
				'common'   => array( 'lifetime', 'cache_logged_in', 'mobile', 'exclude_urls' ),
				'provider' => array( 'cache_mobile' ),
			),
			'purge_all'  => function () {
				rocket_clean_domain();
				return true;
			},
			'purge_post' => function ( $post_id ) {
				return function_exists( 'rocket_clean_post' ) ? false !== rocket_clean_post( $post_id ) : false;
			},
			'purge_url'  => function ( $url ) {
				if ( ! function_exists( 'rocket_clean_files' ) ) {
					return false;
				}
				rocket_clean_files( array( $url ) );
				return true;
			},
		),

		/* LiteSpeed Cache: sus acciones públicas. Solo cachea en servidores LiteSpeed. */
		'litespeed'          => array(
			'label'      => 'LiteSpeed Cache',
			'tested'     => true,
			'detect'     => function () {
				return defined( 'LSCWP_V' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url', 'read_settings', 'write_settings' ),
			'settings'       => 'spiral_mcp_cache_litespeed_settings',
			'write_settings' => 'spiral_mcp_cache_litespeed_write',
			'writable'       => array(
				'common'   => array( 'enabled', 'lifetime', 'cache_logged_in', 'mobile', 'exclude_urls' ),
				'provider' => array( 'ttl_frontpage', 'ttl_feed', 'cache_rest', 'cache_commenter', 'cache_login_page' ),
			),
			'status'     => function () {
				$server = spiral_mcp_cache_is_litespeed_server();
				$out    = array(
					'enabled'          => (bool) get_option( 'litespeed.conf.cache' ),
					'version'          => LSCWP_V,
					'litespeed_server' => $server,
				);
				if ( ! $server ) {
					$out['problem'] = 'El servidor no es LiteSpeed/OpenLiteSpeed: LiteSpeed Cache no guarda páginas aquí (solo optimiza). Purgar no tiene efecto en la caché de páginas.';
				}
				return $out;
			},
			'purge_all'  => function () {
				do_action( 'litespeed_purge_all' );
				return true;
			},
			'purge_post' => function ( $post_id ) {
				do_action( 'litespeed_purge_post', $post_id );
				return true;
			},
			'purge_url'  => function ( $url ) {
				do_action( 'litespeed_purge_url', $url );
				return true;
			},
		),

		/* W3 Total Cache: sus funciones públicas w3tc_flush_*. */
		'w3-total-cache'     => array(
			'label'      => 'W3 Total Cache',
			'tested'     => true,
			'detect'     => function () {
				return function_exists( 'w3tc_flush_all' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url', 'read_settings' ),
			'settings'   => 'spiral_mcp_cache_w3tc_settings',
			'status'     => function ( $with_stats ) {
				$out = array( 'version' => defined( 'W3TC_VERSION' ) ? W3TC_VERSION : null );
				if ( class_exists( '\W3TC\Dispatcher' ) ) {
					try {
						$config         = \W3TC\Dispatcher::config();
						$out['enabled'] = (bool) $config->get_boolean( 'pgcache.enabled' );
						$out['engine']  = (string) $config->get_string( 'pgcache.engine' );
						$out['lifetime'] = (int) $config->get_integer( 'pgcache.lifetime' );
					} catch ( Throwable $e ) {
						$out['enabled'] = null;
					}
				}
				// Motores en disco: "file" (Disk: Basic) guarda en cache/page; "file_generic" (Disk: Enhanced), en cache/page_enhanced.
				$dirs = array( 'file' => '/cache/page', 'file_generic' => '/cache/page_enhanced' );
				if ( $with_stats && isset( $dirs[ $out['engine'] ?? '' ] ) ) {
					$out['stats'] = spiral_mcp_cache_dir_stats( WP_CONTENT_DIR . $dirs[ $out['engine'] ] );
				}
				return $out;
			},
			'purge_all'  => function () {
				w3tc_flush_all();
				return true;
			},
			'purge_post' => function ( $post_id ) {
				if ( ! function_exists( 'w3tc_flush_post' ) ) {
					return false;
				}
				w3tc_flush_post( $post_id );
				return true;
			},
			'purge_url'  => function ( $url ) {
				if ( ! function_exists( 'w3tc_flush_url' ) ) {
					return false;
				}
				w3tc_flush_url( $url );
				return true;
			},
		),

		/* WP Fastest Cache: sus funciones públicas wpfc_clear_*. No purga por URL. */
		'wp-fastest-cache'   => array(
			'label'      => 'WP Fastest Cache',
			'tested'     => true,
			'detect'     => function () {
				return function_exists( 'wpfc_clear_all_cache' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'read_settings', 'write_settings' ),
			'settings'       => 'spiral_mcp_cache_wpfc_settings',
			'write_settings' => 'spiral_mcp_cache_wpfc_write',
			'writable'       => array(
				'common'   => array( 'enabled', 'cache_logged_in', 'exclude_urls', 'clear_on_edit' ),
				'provider' => array( 'dont_cache_mobile', 'clear_on_new_post' ),
			),
			'status'     => function ( $with_stats ) {
				$options = json_decode( (string) get_option( 'WpFastestCache' ), true );
				$out     = array(
					'enabled'       => 'on' === ( $options['wpFastestCacheStatus'] ?? '' ),
					'mobile'        => 'on' === ( $options['wpFastestCacheMobile'] ?? '' ),
					'logged_in_off' => 'on' === ( $options['wpFastestCacheLoggedInUser'] ?? '' ),
				);
				if ( $with_stats ) {
					$out['stats'] = spiral_mcp_cache_dir_stats( WP_CONTENT_DIR . '/cache/all' );
				}
				return $out;
			},
			'purge_all'  => function () {
				wpfc_clear_all_cache();
				return true;
			},
			'purge_post' => function ( $post_id ) {
				if ( ! function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
					return false;
				}
				wpfc_clear_post_cache_by_id( $post_id );
				return true;
			},
		),

		/* SiteGround Optimizer: purga la caché dinámica del hosting SiteGround. */
		'siteground'         => array(
			'label'      => 'SiteGround Optimizer',
			'tested'     => false, // API comprobada (7.8.4 / 2.4.1); el efecto solo se ve en su plataforma.
			'detect'     => function () {
				return function_exists( 'sg_cachepress_purge_everything' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
			'status'     => function () {
				return array(
					'enabled' => (bool) get_option( 'siteground_optimizer_enable_cache' ),
					'note'    => 'La caché dinámica la sirve el hosting SiteGround; fuera de SiteGround purgar no tiene efecto.',
				);
			},
			'purge_all'  => function () {
				sg_cachepress_purge_everything();
				return true;
			},
			'purge_post' => function ( $post_id ) {
				$url = spiral_mcp_cache_post_url( $post_id );
				return '' !== $url && function_exists( 'sg_cachepress_purge_cache' ) ? false !== sg_cachepress_purge_cache( $url ) : false;
			},
			'purge_url'  => function ( $url ) {
				return function_exists( 'sg_cachepress_purge_cache' ) ? false !== sg_cachepress_purge_cache( $url ) : false;
			},
		),

		/* Nginx Helper: purga la caché FastCGI o Redis de Nginx. */
		'nginx-helper'       => array(
			'label'      => 'Nginx Helper',
			'tested'     => false, // API comprobada (7.8.4 / 2.4.1); el efecto solo se ve en su plataforma.
			'detect'     => function () {
				return isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
			'status'     => function () {
				$options = (array) get_option( 'rt_wp_nginx_helper_options', array() );
				return array(
					'enabled' => ! empty( $options['enable_purge'] ),
					'method'  => (string) ( $options['cache_method'] ?? '' ),
					'note'    => 'Solo purga si el servidor es Nginx con la caché FastCGI o Redis configurada.',
				);
			},
			'purge_all'  => function () {
				do_action( 'rt_nginx_helper_purge_all' );
				return true;
			},
			'purge_post' => function ( $post_id ) {
				$purger = $GLOBALS['nginx_purger'];
				if ( ! method_exists( $purger, 'purge_post' ) ) {
					return false;
				}
				$purger->purge_post( $post_id );
				return true;
			},
			'purge_url'  => function ( $url ) {
				$GLOBALS['nginx_purger']->purge_url( $url );
				return true;
			},
		),

		/* Cachés de hosting (sin probar: solo existen en su plataforma). */
		'wp-engine'          => array(
			'label'      => 'WP Engine',
			'tested'     => false,
			'detect'     => function () {
				return class_exists( 'WpeCommon' );
			},
			'supports'   => array( 'purge_all', 'purge_post' ),
			'purge_all'  => function () {
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					WpeCommon::purge_varnish_cache();
				}
				return true;
			},
			'purge_post' => function ( $post_id ) {
				if ( ! method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					return false;
				}
				WpeCommon::purge_varnish_cache( $post_id );
				return true;
			},
		),
		'pantheon'           => array(
			'label'      => 'Pantheon (caché perimetral)',
			'tested'     => false,
			'detect'     => function () {
				return function_exists( 'pantheon_wp_clear_edge_all' );
			},
			'supports'   => array( 'purge_all', 'purge_url' ),
			'purge_all'  => function () {
				pantheon_wp_clear_edge_all();
				return true;
			},
			'purge_url'  => function ( $url ) {
				if ( ! function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
					return false;
				}
				pantheon_wp_clear_edge_paths( array( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
				return true;
			},
		),
		'kinsta'             => array(
			'label'     => 'Kinsta',
			'tested'    => false,
			'detect'    => function () {
				return isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) && method_exists( $GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_complete_caches' );
			},
			'supports'  => array( 'purge_all' ),
			'purge_all' => function () {
				$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
				return true;
			},
		),
	);
}

/* -------------------------------------------------------------------------
 * Ajustes: validación común
 * ---------------------------------------------------------------------- */

/**
 * Valida claves y tipos de los ajustes comunes y de provider_settings contra lo que admite una
 * herramienta. $spec: [ 'common' => [ clave => tipo ], 'provider' => [ clave => tipo ],
 * 'readonly' => [ claves comunes que existen pero no se pueden escribir aquí => motivo ] ].
 * Tipos: bool, int, uris (lista de textos de una línea).
 */
function spiral_mcp_cache_check_settings( array $common, array $provider, array $spec, $label ) {
	foreach ( array( 'common' => $common, 'provider' => $provider ) as $part => $values ) {
		foreach ( $values as $key => $value ) {
			if ( 'common' === $part && isset( $spec['readonly'][ $key ] ) ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" no se puede cambiar desde aquí en %s: %s', $key, $label, $spec['readonly'][ $key ] ) );
			}
			$type = $spec[ $part ][ $key ] ?? null;
			if ( null === $type ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" no es un ajuste %s que %s admita aquí. Admite: %s.', $key, 'common' === $part ? 'común' : 'propio', $label, implode( ', ', array_keys( (array) $spec[ $part ] ) ) ?: 'ninguno' ) );
			}
			if ( 'bool' === $type && ! is_bool( $value ) ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" debe ser true o false.', $key ) );
			}
			if ( 'int' === $type && ( ! is_int( $value ) || $value < 0 || $value > YEAR_IN_SECONDS ) ) {
				return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" debe ser un entero de 0 a %d.', $key, YEAR_IN_SECONDS ) );
			}
			if ( 'uris' === $type ) {
				if ( ! is_array( $value ) || count( $value ) > 200 ) {
					return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( '"%s" debe ser una lista (máximo 200).', $key ) );
				}
				foreach ( $value as $rule ) {
					if ( ! is_string( $rule ) || '' === trim( $rule ) || strlen( $rule ) > 300 || preg_match( '/[\r\n]/', $rule ) ) {
						return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( 'Cada elemento de "%s" es un texto de una línea.', $key ) );
					}
				}
			}
		}
	}
	return true;
}

/** Lista de claves que cambian entre dos lecturas de ajustes. */
function spiral_mcp_cache_settings_diff( array $before, array $after ) {
	$changed = array();
	foreach ( array( 'common', 'provider_settings' ) as $part ) {
		foreach ( (array) ( $after[ $part ] ?? array() ) as $key => $value ) {
			if ( ( $before[ $part ][ $key ] ?? null ) !== $value ) {
				$changed[] = ( 'common' === $part ? '' : 'provider_settings.' ) . $key;
			}
		}
	}
	return $changed;
}

/* -------------------------------------------------------------------------
 * LiteSpeed Cache: lee con el filtro litespeed_conf y guarda con la acción pública
 * litespeed_save_conf (lo mismo que su panel: purga lo necesario y actualiza su bloque
 * de .htaccess y la constante WP_CACHE, como al guardar allí).
 * ---------------------------------------------------------------------- */

function spiral_mcp_cache_litespeed_conf( $key ) {
	return apply_filters( 'litespeed_conf', $key );
}

function spiral_mcp_cache_litespeed_settings() {
	return array(
		'common'            => array(
			'enabled'         => (bool) spiral_mcp_cache_litespeed_conf( 'cache' ),
			'lifetime'        => (int) spiral_mcp_cache_litespeed_conf( 'cache-ttl_pub' ),
			'cache_logged_in' => (bool) spiral_mcp_cache_litespeed_conf( 'cache-priv' ),
			'mobile'          => (bool) spiral_mcp_cache_litespeed_conf( 'cache-mobile' ),
			'compression'     => null, // La comprime el servidor LiteSpeed.
			'exclude_urls'    => array_values( array_filter( (array) spiral_mcp_cache_litespeed_conf( 'cache-exc' ) ) ),
			'clear_on_edit'   => null, // LiteSpeed purga siempre lo relacionado al editar (reglas "purge-post_all").
		),
		'provider_settings' => array(
			'ttl_frontpage'   => (int) spiral_mcp_cache_litespeed_conf( 'cache-ttl_frontpage' ),
			'ttl_feed'        => (int) spiral_mcp_cache_litespeed_conf( 'cache-ttl_feed' ),
			'cache_rest'      => (bool) spiral_mcp_cache_litespeed_conf( 'cache-rest' ),
			'cache_commenter' => (bool) spiral_mcp_cache_litespeed_conf( 'cache-commenter' ),
			'cache_login_page' => (bool) spiral_mcp_cache_litespeed_conf( 'cache-page_login' ),
		),
	);
}

function spiral_mcp_cache_litespeed_write( array $common, array $provider ) {
	$spec = array(
		'common'   => array( 'enabled' => 'bool', 'lifetime' => 'int', 'cache_logged_in' => 'bool', 'mobile' => 'bool', 'exclude_urls' => 'uris' ),
		'provider' => array( 'ttl_frontpage' => 'int', 'ttl_feed' => 'int', 'cache_rest' => 'bool', 'cache_commenter' => 'bool', 'cache_login_page' => 'bool' ),
		'readonly' => array(
			'compression'   => 'la comprime el servidor LiteSpeed.',
			'clear_on_edit' => 'LiteSpeed ya purga lo relacionado al editar.',
		),
	);
	$ok = spiral_mcp_cache_check_settings( $common, $provider, $spec, 'LiteSpeed Cache' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$map = array(
		'enabled'          => 'cache',
		'lifetime'         => 'cache-ttl_pub',
		'cache_logged_in'  => 'cache-priv',
		'mobile'           => 'cache-mobile',
		'exclude_urls'     => 'cache-exc',
		'ttl_frontpage'    => 'cache-ttl_frontpage',
		'ttl_feed'         => 'cache-ttl_feed',
		'cache_rest'       => 'cache-rest',
		'cache_commenter'  => 'cache-commenter',
		'cache_login_page' => 'cache-page_login',
	);
	$matrix = array();
	foreach ( $common + $provider as $key => $value ) {
		$matrix[ $map[ $key ] ] = is_array( $value ) ? array_values( $value ) : $value;
	}
	$before = spiral_mcp_cache_litespeed_settings();
	do_action( 'litespeed_save_conf', $matrix );
	$after    = spiral_mcp_cache_litespeed_settings();
	$warnings = array();
	if ( ! spiral_mcp_cache_is_litespeed_server() ) {
		$warnings[] = 'El servidor no es LiteSpeed: los ajustes se guardan, pero LiteSpeed Cache no cachea páginas aquí.';
	}
	return array( 'changed' => spiral_mcp_cache_settings_diff( $before, $after ), 'warnings' => $warnings );
}

/* -------------------------------------------------------------------------
 * WP Fastest Cache: opciones WpFastestCache (casillas marcadas = "on") y
 * WpFastestCacheExclude (reglas). Su botón Guardar reescribe .htaccess; aquí no:
 * se escriben las opciones y, al desactivar, se vacía la caché para que las reglas
 * de .htaccess que hubiera no sigan sirviendo copias.
 * ---------------------------------------------------------------------- */

function spiral_mcp_cache_wpfc_options() {
	$o = json_decode( (string) get_option( 'WpFastestCache' ), true );
	return is_array( $o ) ? $o : array();
}

/** Reglas de exclusión de páginas: "/ruta/" (exacta), "/ruta*" (empieza por), "*texto*" (contiene). */
function spiral_mcp_cache_wpfc_exclude_read() {
	$rules = json_decode( (string) get_option( 'WpFastestCacheExclude' ), true );
	$urls  = array();
	$other = array();
	foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
		$prefix  = (string) ( $rule['prefix'] ?? '' );
		$content = (string) ( $rule['content'] ?? '' );
		if ( 'page' === ( $rule['type'] ?? '' ) && 'exact' === $prefix ) {
			$urls[] = '/' . trim( $content, '/' ) . ( '' === trim( $content, '/' ) ? '' : '/' );
		} elseif ( 'page' === ( $rule['type'] ?? '' ) && 'startwith' === $prefix ) {
			$urls[] = '/' . ltrim( $content, '/' ) . '*';
		} elseif ( 'page' === ( $rule['type'] ?? '' ) && 'contain' === $prefix ) {
			$urls[] = '*' . $content . '*';
		} else {
			$other[] = $rule; // Tipos de página (homepage, category…), user agents, cookies…: se conservan.
		}
	}
	return array( $urls, $other );
}

function spiral_mcp_cache_wpfc_settings() {
	$o              = spiral_mcp_cache_wpfc_options();
	list( $urls, $other ) = spiral_mcp_cache_wpfc_exclude_read();
	$on             = function ( $key ) use ( $o ) {
		return 'on' === ( $o[ $key ] ?? '' );
	};
	return array(
		'common'            => array(
			'enabled'         => $on( 'wpFastestCacheStatus' ),
			'lifetime'        => null, // WP Fastest Cache no caduca páginas: vacía la caché con un cron (provider_settings.timeout).
			'cache_logged_in' => ! $on( 'wpFastestCacheLoggedInUser' ),
			'mobile'          => null, // La caché móvil aparte es de la versión Premium.
			'compression'     => $on( 'wpFastestCacheGzip' ),
			'exclude_urls'    => $urls,
			'clear_on_edit'   => $on( 'wpFastestCacheUpdatePost' ),
		),
		'provider_settings' => array(
			'dont_cache_mobile'  => $on( 'wpFastestCacheMobile' ),
			'clear_on_new_post'  => $on( 'wpFastestCacheNewPost' ),
			'timeout'            => (string) ( $o['wpFastestCacheTimeOut'] ?? '' ),
			'other_exclude_rules' => count( $other ),
		),
	);
}

function spiral_mcp_cache_wpfc_write( array $common, array $provider ) {
	$spec = array(
		'common'   => array( 'enabled' => 'bool', 'cache_logged_in' => 'bool', 'exclude_urls' => 'uris', 'clear_on_edit' => 'bool' ),
		'provider' => array( 'dont_cache_mobile' => 'bool', 'clear_on_new_post' => 'bool' ),
		'readonly' => array(
			'lifetime'    => 'WP Fastest Cache no caduca páginas; la limpieza periódica se programa en su panel.',
			'mobile'      => 'la caché móvil aparte es de la versión Premium.',
			'compression' => 'la compresión de WP Fastest Cache va en .htaccess: cámbiala en su panel.',
		),
	);
	$ok = spiral_mcp_cache_check_settings( $common, $provider, $spec, 'WP Fastest Cache' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$parsed = array();
	foreach ( (array) ( $common['exclude_urls'] ?? array() ) as $url ) {
		$url = trim( $url );
		if ( strlen( $url ) > 2 && '*' === $url[0] && '*' === substr( $url, -1 ) ) {
			$parsed[] = array( 'prefix' => 'contain', 'content' => substr( $url, 1, -1 ), 'type' => 'page' );
		} elseif ( '/' === $url[0] && '*' === substr( $url, -1 ) ) {
			$parsed[] = array( 'prefix' => 'startwith', 'content' => ltrim( substr( $url, 0, -1 ), '/' ), 'type' => 'page' );
		} elseif ( '/' === $url[0] && false === strpos( $url, '*' ) ) {
			$parsed[] = array( 'prefix' => 'exact', 'content' => trim( $url, '/' ), 'type' => 'page' );
		} else {
			return new WP_Error( 'spiral_mcp_cache_bad_setting', sprintf( 'Regla "%s" no válida para WP Fastest Cache: usa "/ruta/" (exacta), "/ruta*" (empieza por) o "*texto*" (contiene).', $url ) );
		}
	}

	$before = spiral_mcp_cache_wpfc_settings();
	$o      = spiral_mcp_cache_wpfc_options();
	$set    = function ( $key, $on ) use ( &$o ) {
		if ( $on ) {
			$o[ $key ] = 'on';
		} else {
			unset( $o[ $key ] );
		}
	};
	if ( isset( $common['enabled'] ) ) {
		$set( 'wpFastestCacheStatus', $common['enabled'] );
	}
	if ( isset( $common['cache_logged_in'] ) ) {
		$set( 'wpFastestCacheLoggedInUser', ! $common['cache_logged_in'] );
	}
	if ( isset( $common['clear_on_edit'] ) ) {
		$set( 'wpFastestCacheUpdatePost', $common['clear_on_edit'] );
	}
	if ( isset( $provider['dont_cache_mobile'] ) ) {
		$set( 'wpFastestCacheMobile', $provider['dont_cache_mobile'] );
	}
	if ( isset( $provider['clear_on_new_post'] ) ) {
		$set( 'wpFastestCacheNewPost', $provider['clear_on_new_post'] );
	}
	update_option( 'WpFastestCache', wp_json_encode( $o ) );
	if ( isset( $common['exclude_urls'] ) ) {
		list( , $other ) = spiral_mcp_cache_wpfc_exclude_read();
		update_option( 'WpFastestCacheExclude', wp_json_encode( array_merge( $other, $parsed ) ) );
	}
	$warnings = array();
	if ( isset( $common['enabled'] ) && ! $common['enabled'] && function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache();
		$warnings[] = 'Caché desactivada y vaciada (por si había reglas en .htaccess que sirvieran copias).';
	}
	if ( isset( $common['cache_logged_in'] ) || isset( $common['exclude_urls'] ) || isset( $provider['dont_cache_mobile'] ) ) {
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache(); // Las copias guardadas con las reglas anteriores dejan de valer.
		}
	}
	return array( 'changed' => spiral_mcp_cache_settings_diff( $before, spiral_mcp_cache_wpfc_settings() ), 'warnings' => $warnings );
}

/* -------------------------------------------------------------------------
 * W3 Total Cache: solo lectura (su configuración tiene muchas dependencias entre opciones).
 * ---------------------------------------------------------------------- */

function spiral_mcp_cache_w3tc_settings() {
	if ( ! class_exists( '\W3TC\Dispatcher' ) ) {
		return array( 'common' => array(), 'provider_settings' => array() );
	}
	$c = \W3TC\Dispatcher::config();
	return array(
		'common'            => array(
			'enabled'         => (bool) $c->get_boolean( 'pgcache.enabled' ),
			'lifetime'        => (int) $c->get_integer( 'pgcache.lifetime' ),
			'cache_logged_in' => ! $c->get_boolean( 'pgcache.reject.logged' ),
			'mobile'          => (bool) $c->get_boolean( 'mobile.enabled' ),
			'compression'     => (bool) $c->get_boolean( 'browsercache.html.compression' ),
			'exclude_urls'    => array_values( array_filter( (array) $c->get_array( 'pgcache.reject.uri' ) ) ),
			'clear_on_edit'   => null,
		),
		'provider_settings' => array(
			'engine'          => (string) $c->get_string( 'pgcache.engine' ),
			'cache_home'      => (bool) $c->get_boolean( 'pgcache.cache.home' ),
			'cache_feeds'     => (bool) $c->get_boolean( 'pgcache.cache.feed' ),
			'purge_front_page' => (bool) $c->get_boolean( 'pgcache.purge.front_page' ),
			'note'            => 'Solo lectura desde aquí: cambia los ajustes de W3 Total Cache en su panel.',
		),
	);
}

/* -------------------------------------------------------------------------
 * WP Rocket: opción wp_rocket_settings (sin probar). update_option dispara sus propios
 * ganchos, que regeneran su configuración y vacían la caché como al guardar en su panel.
 * ---------------------------------------------------------------------- */

function spiral_mcp_cache_rocket_settings() {
	$o    = (array) get_option( 'wp_rocket_settings', array() );
	$unit = (string) ( $o['purge_cron_unit'] ?? 'HOUR_IN_SECONDS' );
	$mult = defined( $unit ) ? (int) constant( $unit ) : HOUR_IN_SECONDS;
	return array(
		'common'            => array(
			'enabled'         => true, // WP Rocket cachea siempre que está activo.
			'lifetime'        => (int) ( $o['purge_cron_interval'] ?? 0 ) * $mult,
			'cache_logged_in' => ! empty( $o['cache_logged_user'] ),
			'mobile'          => ! empty( $o['do_caching_mobile_files'] ),
			'compression'     => null,
			'exclude_urls'    => array_values( array_filter( (array) ( $o['cache_reject_uri'] ?? array() ) ) ),
			'clear_on_edit'   => null,
		),
		'provider_settings' => array(
			'cache_mobile'    => ! empty( $o['cache_mobile'] ),
			'purge_cron_unit' => $unit,
		),
	);
}

function spiral_mcp_cache_rocket_write( array $common, array $provider ) {
	$spec = array(
		'common'   => array( 'lifetime' => 'int', 'cache_logged_in' => 'bool', 'mobile' => 'bool', 'exclude_urls' => 'uris' ),
		'provider' => array( 'cache_mobile' => 'bool' ),
		'readonly' => array( 'enabled' => 'WP Rocket cachea mientras el plugin está activo; para apagarlo hay que desactivar el plugin.' ),
	);
	$ok = spiral_mcp_cache_check_settings( $common, $provider, $spec, 'WP Rocket' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$before = spiral_mcp_cache_rocket_settings();
	$o      = (array) get_option( 'wp_rocket_settings', array() );
	if ( isset( $common['lifetime'] ) ) {
		$o['purge_cron_interval'] = (int) ceil( $common['lifetime'] / HOUR_IN_SECONDS );
		$o['purge_cron_unit']     = 'HOUR_IN_SECONDS';
	}
	if ( isset( $common['cache_logged_in'] ) ) {
		$o['cache_logged_user'] = $common['cache_logged_in'] ? 1 : 0;
	}
	if ( isset( $common['mobile'] ) ) {
		$o['do_caching_mobile_files'] = $common['mobile'] ? 1 : 0;
		if ( $common['mobile'] ) {
			$o['cache_mobile'] = 1; // La caché móvil aparte necesita la caché móvil activada.
		}
	}
	if ( isset( $provider['cache_mobile'] ) ) {
		$o['cache_mobile'] = $provider['cache_mobile'] ? 1 : 0;
	}
	if ( isset( $common['exclude_urls'] ) ) {
		$o['cache_reject_uri'] = array_values( $common['exclude_urls'] );
	}
	update_option( 'wp_rocket_settings', $o );
	return array(
		'changed'  => spiral_mcp_cache_settings_diff( $before, spiral_mcp_cache_rocket_settings() ),
		'warnings' => array( 'El adaptador de WP Rocket no se ha probado en un sitio real: comprueba el resultado en su panel.' ),
	);
}
