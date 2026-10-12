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
 *   'settings' => callable(): [ 'common' => […], 'provider_settings' => […] ].
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
	$providers = array(
		'wp-super-cache' => spiral_mcp_cache_wpsc_provider(),
		'wp-rocket'      => array(
			'label'      => 'WP Rocket',
			'tested'     => false,
			'detect'     => function () {
				return function_exists( 'rocket_clean_domain' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
			'purge_all'  => function () {
				rocket_clean_domain();
				return true;
			},
			'purge_post' => function ( $post_id ) {
				return function_exists( 'rocket_clean_post' ) ? (bool) rocket_clean_post( $post_id ) : false;
			},
			'purge_url'  => function ( $url ) {
				if ( ! function_exists( 'rocket_clean_files' ) ) {
					return false;
				}
				rocket_clean_files( array( $url ) );
				return true;
			},
		),
		'litespeed'      => array(
			'label'      => 'LiteSpeed Cache',
			'tested'     => false,
			'detect'     => function () {
				return defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
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
		'w3-total-cache' => array(
			'label'      => 'W3 Total Cache',
			'tested'     => false,
			'detect'     => function () {
				return function_exists( 'w3tc_flush_all' );
			},
			'supports'   => array( 'purge_all', 'purge_post', 'purge_url' ),
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
		'supports'   => array( 'purge_all', 'purge_post', 'purge_url', 'preload', 'read_settings' ),
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
		'description'         => 'Dice qué herramientas de caché hay activas en el sitio (plugins como WP Super Cache, WP Rocket, LiteSpeed Cache, W3 Total Cache, o la caché de objetos) y, para cada una: qué se puede hacer con ella desde aquí ("supports": purge_all, purge_post, purge_url, preload, read_settings), si se ha probado ("tested") y su estado (activada, modo, caducidad, precarga…). Con "include_stats": true añade cuántos archivos y bytes ocupa la caché cuando la herramienta lo permite. Úsala antes de purgar, precargar o tocar ajustes de caché. Si "providers" está vacío, el sitio no tiene caché de páginas.',
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
					'supports' => array_values( array_filter( array( 'purge_all', 'purge_post', 'purge_url', 'preload', 'read_settings' ), function ( $c ) use ( $p ) {
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
} );
