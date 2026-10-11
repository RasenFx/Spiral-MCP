<?php
/**
 * SEO por contenido: título, meta description, Open Graph, canonical y
 * noindex.
 *
 * Proveedores: si Yoast SEO está activo se usan sus metas; Rank Math tiene su
 * mapa de claves preparado pero aún no está soportado; sin plugin de SEO, el
 * propio Spiral-MCP guarda los valores y los imprime en el <head>.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Campos de SEO que se manejan. */
function spiral_mcp_seo_fields() {
	return array( 'title', 'description', 'og_title', 'og_description', 'canonical', 'noindex' );
}

/**
 * Proveedores de SEO con su mapa campo => meta. Ajustable con el filtro
 * spiral_mcp_seo_providers. El primero activo y no "native" gana.
 */
function spiral_mcp_seo_providers() {
	return (array) apply_filters( 'spiral_mcp_seo_providers', array(
		'yoast'    => array(
			'label'     => 'Yoast SEO',
			'active'    => defined( 'WPSEO_VERSION' ),
			'supported' => true,
			'keys'      => array(
				'title'          => '_yoast_wpseo_title',
				'description'    => '_yoast_wpseo_metadesc',
				'og_title'       => '_yoast_wpseo_opengraph-title',
				'og_description' => '_yoast_wpseo_opengraph-description',
				'canonical'      => '_yoast_wpseo_canonical',
				'noindex'        => '_yoast_wpseo_meta-robots-noindex', // '1' = noindex.
			),
		),
		// Preparado para más adelante: Rank Math guarda noindex dentro de la lista rank_math_robots.
		'rankmath' => array(
			'label'     => 'Rank Math',
			'active'    => defined( 'RANK_MATH_VERSION' ),
			'supported' => false,
			'keys'      => array(
				'title'          => 'rank_math_title',
				'description'    => 'rank_math_description',
				'og_title'       => 'rank_math_facebook_title',
				'og_description' => 'rank_math_facebook_description',
				'canonical'      => 'rank_math_canonical_url',
				'noindex'        => 'rank_math_robots',
			),
		),
		'other'    => array(
			'label'     => 'All in One SEO / SEOPress',
			'active'    => defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ),
			'supported' => false,
			'keys'      => array(),
		),
		'native'   => array(
			'label'     => 'Spiral-MCP (sin plugin de SEO)',
			'active'    => true,
			'supported' => true,
			'keys'      => array(
				'title'          => '_spiral_mcp_seo_title',
				'description'    => '_spiral_mcp_seo_description',
				'og_title'       => '_spiral_mcp_seo_og_title',
				'og_description' => '_spiral_mcp_seo_og_description',
				'canonical'      => '_spiral_mcp_seo_canonical',
				'noindex'        => '_spiral_mcp_seo_noindex', // '1' = noindex.
			),
		),
	) );
}

/** Proveedor activo: [ id, definición ] o WP_Error si el plugin de SEO activo no está soportado. */
function spiral_mcp_seo_provider() {
	foreach ( spiral_mcp_seo_providers() as $id => $p ) {
		if ( empty( $p['active'] ) ) {
			continue;
		}
		if ( empty( $p['supported'] ) ) {
			return new WP_Error( 'spiral_mcp_seo_unsupported', sprintf( '%s está activo y todavía no está soportado por Spiral-MCP. Edita su SEO desde ese plugin.', $p['label'] ) );
		}
		return array( $id, $p );
	}
	return new WP_Error( 'spiral_mcp_seo_unsupported', 'No hay proveedor de SEO disponible.' );
}

/** Valores de SEO de un post con el proveedor activo. */
function spiral_mcp_seo_read( $post_id, array $provider ) {
	$out = array();
	foreach ( $provider['keys'] as $field => $key ) {
		$value         = get_post_meta( (int) $post_id, $key, true );
		$out[ $field ] = 'noindex' === $field ? '1' === (string) $value : (string) $value;
	}
	return $out;
}

/** Escribe valores de SEO ("" o null borra el meta). */
function spiral_mcp_seo_write( $post_id, array $provider, array $values ) {
	foreach ( $values as $field => $value ) {
		$key = $provider['keys'][ $field ] ?? null;
		if ( ! $key ) {
			continue;
		}
		if ( 'noindex' === $field ) {
			$value = $value ? '1' : '';
		}
		if ( null === $value || '' === $value ) {
			delete_post_meta( (int) $post_id, $key );
		} else {
			update_post_meta( (int) $post_id, $key, wp_slash( (string) $value ) );
		}
	}
}

/** Canonical: acepta rutas relativas (/contacto/) y las guarda absolutas, como esperan los plugins de SEO. */
function spiral_mcp_seo_canonical( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	if ( '/' === $value[0] ) {
		$value = home_url( $value );
	}
	$url = esc_url_raw( $value, array( 'http', 'https' ) );
	return '' === $url ? new WP_Error( 'spiral_mcp_bad_canonical', '"canonical" debe ser una URL o una ruta relativa como /contacto/.' ) : $url;
}

/* -------------------------------------------------------------------------
 * Salida en el <head> cuando no hay plugin de SEO
 * ---------------------------------------------------------------------- */

/** Valores nativos del contenido que se está viendo, o null. */
function spiral_mcp_seo_current() {
	if ( ! is_singular() ) {
		return null;
	}
	$provider = spiral_mcp_seo_provider();
	if ( is_wp_error( $provider ) || 'native' !== $provider[0] ) {
		return null;
	}
	return spiral_mcp_seo_read( get_queried_object_id(), $provider[1] );
}

add_filter( 'pre_get_document_title', function ( $title ) {
	$seo = spiral_mcp_seo_current();
	return $seo && '' !== $seo['title'] ? $seo['title'] : $title;
}, 20 );

add_filter( 'get_canonical_url', function ( $url ) {
	$seo = spiral_mcp_seo_current();
	return $seo && '' !== $seo['canonical'] ? $seo['canonical'] : $url;
} );

add_filter( 'wp_robots', function ( $robots ) {
	$seo = spiral_mcp_seo_current();
	if ( $seo && $seo['noindex'] ) {
		$robots['noindex'] = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

add_action( 'wp_head', function () {
	$seo = spiral_mcp_seo_current();
	if ( ! $seo ) {
		return;
	}
	$title       = '' !== $seo['og_title'] ? $seo['og_title'] : ( '' !== $seo['title'] ? $seo['title'] : '' );
	$description = '' !== $seo['og_description'] ? $seo['og_description'] : $seo['description'];
	if ( '' !== $seo['description'] ) {
		echo '<meta name="description" content="' . esc_attr( $seo['description'] ) . '">' . "\n";
	}
	if ( '' !== $title ) {
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	}
	if ( '' !== $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( '' !== $title || '' !== $description ) {
		echo '<meta property="og:url" content="' . esc_url( wp_get_canonical_url() ? wp_get_canonical_url() : get_permalink() ) . '">' . "\n";
		echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
		$thumb = get_the_post_thumbnail_url( get_queried_object_id(), 'large' );
		if ( $thumb ) {
			echo '<meta property="og:image" content="' . esc_url( $thumb ) . '">' . "\n";
		}
	}
}, 5 );

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['seo'] = array(
		'group'   => 'content',
		'cap'     => 'edit_posts',
		'capture' => function ( $post_id ) {
			$provider = spiral_mcp_seo_provider();
			return is_wp_error( $provider ) ? null : array( 'provider' => $provider[0], 'values' => spiral_mcp_seo_read( $post_id, $provider[1] ) );
		},
		'restore' => function ( $post_id, $data ) {
			$provider = spiral_mcp_seo_provider();
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
			if ( ! is_array( $data ) || $data['provider'] !== $provider[0] ) {
				return new WP_Error( 'spiral_mcp_bad_snapshot', 'El snapshot de SEO es de otro proveedor de SEO distinto del activo.' );
			}
			spiral_mcp_seo_write( $post_id, $provider[1], $data['values'] );
			return true;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function ( $input = array() ) {
		return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-seo', array(
		'label'               => 'Leer el SEO de un contenido',
		'description'         => 'Devuelve el SEO de una página o entrada: title (título para buscadores), description (meta description), og_title y og_description (al compartir en redes), canonical y noindex, y "provider": el plugin que los guarda (Yoast SEO si está activo; si no hay plugin de SEO, Spiral-MCP los guarda y los imprime en el <head>). Un campo vacío = el valor por defecto del sitio.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$provider = spiral_mcp_seo_provider();
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
			return array( 'id' => (int) $post->ID, 'provider' => $provider[0] ) + spiral_mcp_seo_read( $post->ID, $provider[1] );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-seo', array(
		'label'               => 'Cambiar el SEO de un contenido',
		'description'         => 'Cambia los campos de SEO enviados de una página o entrada (vacío = volver al valor por defecto): title (unos 60 caracteres), description (unos 155), og_title, og_description, canonical (URL o ruta relativa como /contacto/) y noindex (true oculta la página a los buscadores). Usa Yoast SEO si está activo. Antes guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-content',
		'log'                 => array( 'type' => 'post', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'title'          => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'og_title'       => array( 'type' => 'string' ),
				'og_description' => array( 'type' => 'string' ),
				'canonical'      => array( 'type' => 'string' ),
				'noindex'        => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$provider = spiral_mcp_seo_provider();
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
			$values = array_intersect_key( (array) $input, array_flip( spiral_mcp_seo_fields() ) );
			if ( ! $values ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos uno de: ' . implode( ', ', spiral_mcp_seo_fields() ) . '.' );
			}
			if ( isset( $values['canonical'] ) ) {
				$values['canonical'] = spiral_mcp_seo_canonical( $values['canonical'] );
				if ( is_wp_error( $values['canonical'] ) ) {
					return $values['canonical'];
				}
			}
			foreach ( array( 'title', 'description', 'og_title', 'og_description' ) as $field ) {
				if ( isset( $values[ $field ] ) ) {
					$values[ $field ] = sanitize_text_field( $values[ $field ] );
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'seo', $post->ID, 'update-seo' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			spiral_mcp_seo_write( $post->ID, $provider[1], $values );
			$warnings = array();
			$now      = spiral_mcp_seo_read( $post->ID, $provider[1] );
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $now['title'] ) : strlen( $now['title'] ) ) > 70 ) {
				$warnings[] = 'El título tiene más de 70 caracteres: los buscadores lo cortarán.';
			}
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $now['description'] ) : strlen( $now['description'] ) ) > 170 ) {
				$warnings[] = 'La descripción tiene más de 170 caracteres: los buscadores la cortarán.';
			}
			return array( 'id' => (int) $post->ID, 'provider' => $provider[0] ) + $now + array( 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
