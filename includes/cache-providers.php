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
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
			'status'     => function () {
				return array(
					'enabled' => true,
					'version' => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : null,
				);
			},
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
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
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
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
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
			'supports'   => array( 'purge_all', 'purge_post' ),
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
