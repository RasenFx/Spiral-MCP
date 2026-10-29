<?php
/**
 * Verificación: URLs de vista previa sin sesión (token firmado, de un solo
 * post, con caducidad y revocables) e inspección de páginas renderizadas.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Tokens de vista previa
 *
 * Formato: "<post_id>.<caduca>.<id>.<firma>", con firma = HMAC-SHA256 de los
 * tres primeros campos con una sal de WordPress. El <id> se guarda en el meta
 * _spiral_mcp_previews del post ( id => caducidad, o id => [ 'exp' => caducidad,
 * 'draft' => usuario ] para ver el borrador de Elementor de ese usuario ):
 * borrarlo revoca el token.
 * ---------------------------------------------------------------------- */

/** Caducidad de una entrada del meta de tokens (número o array con "exp"). */
function spiral_mcp_preview_expiry( $entry ) {
	return (int) ( is_array( $entry ) ? ( $entry['exp'] ?? 0 ) : $entry );
}

/** Firma de un token. */
function spiral_mcp_preview_sign( $post_id, $expires, $nonce ) {
	return hash_hmac( 'sha256', (int) $post_id . '|' . (int) $expires . '|' . $nonce, wp_salt( 'nonce' ) );
}

/** Tokens vigentes de un post (borra los caducados). */
function spiral_mcp_preview_tokens( $post_id ) {
	$tokens = get_post_meta( (int) $post_id, '_spiral_mcp_previews', true );
	$tokens = is_array( $tokens ) ? $tokens : array();
	$valid  = array_filter( $tokens, function ( $entry ) {
		return spiral_mcp_preview_expiry( $entry ) > time();
	} );
	if ( count( $valid ) !== count( $tokens ) ) {
		$valid ? update_post_meta( (int) $post_id, '_spiral_mcp_previews', $valid ) : delete_post_meta( (int) $post_id, '_spiral_mcp_previews' );
	}
	return $valid;
}

/**
 * Crea un token. Devuelve [ token, id, caducidad ]. Con $draft_user, la vista previa muestra
 * el borrador de Elementor (autosave) de ese usuario en vez de la versión guardada.
 */
function spiral_mcp_preview_create( $post_id, $seconds, $draft_user = 0 ) {
	$expires            = time() + (int) $seconds;
	$nonce              = wp_generate_password( 12, false, false );
	$tokens             = spiral_mcp_preview_tokens( $post_id );
	$tokens[ $nonce ]   = $draft_user ? array( 'exp' => $expires, 'draft' => (int) $draft_user ) : $expires;
	update_post_meta( (int) $post_id, '_spiral_mcp_previews', $tokens );
	$token = (int) $post_id . '.' . $expires . '.' . $nonce . '.' . spiral_mcp_preview_sign( $post_id, $expires, $nonce );
	return array( $token, $nonce, $expires );
}

/** Revoca un token (por su id) o todos los del post. Devuelve cuántos revocó. */
function spiral_mcp_preview_revoke( $post_id, $nonce = null ) {
	$tokens = spiral_mcp_preview_tokens( $post_id );
	$before = count( $tokens );
	if ( null === $nonce ) {
		$tokens = array();
	} else {
		unset( $tokens[ $nonce ] );
	}
	$tokens ? update_post_meta( (int) $post_id, '_spiral_mcp_previews', $tokens ) : delete_post_meta( (int) $post_id, '_spiral_mcp_previews' );
	return $before - count( $tokens );
}

/** Comprueba un token. Devuelve el ID del post o 0. */
function spiral_mcp_preview_verify( $token ) {
	$parts = explode( '.', (string) $token );
	if ( 4 !== count( $parts ) ) {
		return 0;
	}
	list( $post_id, $expires, $nonce, $sig ) = $parts;
	if ( (int) $expires <= time() || ! hash_equals( spiral_mcp_preview_sign( $post_id, $expires, $nonce ), $sig ) ) {
		return 0;
	}
	$tokens = spiral_mcp_preview_tokens( $post_id );
	return isset( $tokens[ $nonce ] ) ? (int) $post_id : 0;
}

/** Usuario cuyo borrador de Elementor muestra un token (0 si es una vista previa normal). */
function spiral_mcp_preview_draft_user( $token ) {
	$parts = explode( '.', (string) $token );
	if ( 4 !== count( $parts ) ) {
		return 0;
	}
	$tokens = spiral_mcp_preview_tokens( $parts[0] );
	$entry  = $tokens[ $parts[2] ] ?? null;
	return is_array( $entry ) ? (int) ( $entry['draft'] ?? 0 ) : 0;
}

/** Vista previa del borrador de Elementor: autosave del usuario del token, o null si no hay. */
function spiral_mcp_preview_elementor_autosave( $post_id, $user_id ) {
	$post     = get_post( $post_id );
	$autosave = $post ? wp_get_post_autosave( $post_id, (int) $user_id ) : false;
	if ( ! $autosave || mysql2date( 'U', $autosave->post_modified_gmt, false ) <= mysql2date( 'U', $post->post_modified_gmt, false ) ) {
		return null;
	}
	return (int) $autosave->ID;
}

/** URL de vista previa de un post con un token. */
function spiral_mcp_preview_link( WP_Post $post, $token ) {
	$link = 'publish' === $post->post_status ? get_permalink( $post ) : add_query_arg( 'preview', 'true', get_permalink( $post ) );
	return add_query_arg( 'spiral_preview', rawurlencode( $token ), $link );
}

// Con un token válido, el post se muestra aunque sea un borrador (sin sesión), sin caché y con noindex.
add_action( 'init', function () {
	if ( is_admin() || empty( $_GET['spiral_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$token   = sanitize_text_field( wp_unslash( $_GET['spiral_preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = spiral_mcp_preview_verify( $token );
	if ( ! $post_id ) {
		return;
	}
	// Borrador de Elementor: Elementor renderiza el autosave (con su CSS de vista previa) en vez del documento guardado.
	$draft_user = spiral_mcp_preview_draft_user( $token );
	$autosave   = $draft_user ? spiral_mcp_preview_elementor_autosave( $post_id, $draft_user ) : null;
	if ( $autosave ) {
		add_filter( 'elementor/documents/get/post_id', function ( $id ) use ( $post_id, $autosave ) {
			return (int) $id === $post_id ? $autosave : $id;
		} );
	}
	add_filter( 'posts_results', function ( $posts, $query ) use ( $post_id ) {
		if ( $query->is_main_query() && 1 === count( $posts ) && (int) $posts[0]->ID === $post_id && 'publish' !== $posts[0]->post_status ) {
			$posts[0]->post_status = 'publish';
		}
		return $posts;
	}, 10, 2 );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_action( 'send_headers', 'nocache_headers' );
} );

/* -------------------------------------------------------------------------
 * Inspección de páginas
 * ---------------------------------------------------------------------- */

/**
 * Base de las URLs que se piden al propio sitio (por defecto home_url()).
 * Filtro spiral_mcp_inspect_base_url: útil si el servidor no se alcanza a sí
 * mismo por su dominio público.
 */
function spiral_mcp_inspect_base() {
	return untrailingslashit( (string) apply_filters( 'spiral_mcp_inspect_base_url', home_url() ) );
}

/** Convierte una URL del sitio a la base de inspección. */
function spiral_mcp_inspect_url( $url ) {
	$home = untrailingslashit( home_url() );
	if ( 0 === strpos( $url, $home ) ) {
		return spiral_mcp_inspect_base() . substr( $url, strlen( $home ) );
	}
	if ( '/' === substr( $url, 0, 1 ) ) {
		return spiral_mcp_inspect_base() . $url;
	}
	return $url;
}

/** Pide una URL al propio sitio. */
function spiral_mcp_inspect_fetch( $url, $method = 'GET' ) {
	$args = array(
		'timeout'     => 20,
		'redirection' => 3,
		'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		'headers'     => array( 'Cache-Control' => 'no-cache' ),
		'user-agent'  => 'Spiral-MCP-Inspect/' . SPIRAL_MCP_VERSION,
	);
	return 'HEAD' === $method ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
}

/** Texto plano de un nodo DOM. */
function spiral_mcp_dom_text( $node ) {
	return trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
}

/** ¿Es una URL de este sitio? Devuelve la ruta relativa o null. */
function spiral_mcp_internal_path( $href ) {
	$href = trim( (string) $href );
	if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|data):/i', $href ) ) {
		return null;
	}
	if ( '/' === $href[0] && ( ! isset( $href[1] ) || '/' !== $href[1] ) ) {
		return $href;
	}
	$host  = wp_parse_url( $href, PHP_URL_HOST );
	$hosts = array_filter( array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( spiral_mcp_inspect_base(), PHP_URL_HOST ) ) );
	if ( $host && in_array( strtolower( $host ), array_map( 'strtolower', $hosts ), true ) ) {
		$path  = (string) wp_parse_url( $href, PHP_URL_PATH );
		$query = wp_parse_url( $href, PHP_URL_QUERY );
		return ( '' === $path ? '/' : $path ) . ( $query ? '?' . $query : '' );
	}
	return null;
}

/** Analiza el HTML: SEO efectivo, encabezados, enlaces e imágenes. */
function spiral_mcp_inspect_html( $html, $check_links, $max_links ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );
	$meta  = function ( $query, $attr = 'content' ) use ( $xpath ) {
		$n = $xpath->query( $query )->item( 0 );
		return $n ? trim( (string) $n->getAttribute( $attr ) ) : null;
	};

	$out = array(
		'seo'      => array(
			'title'          => ( $t = $xpath->query( '//head/title' )->item( 0 ) ) ? spiral_mcp_dom_text( $t ) : null,
			'description'    => $meta( '//meta[@name="description"]' ),
			'canonical'      => $meta( '//link[@rel="canonical"]', 'href' ),
			'robots'         => $meta( '//meta[@name="robots"]' ),
			'og_title'       => $meta( '//meta[@property="og:title"]' ),
			'og_description' => $meta( '//meta[@property="og:description"]' ),
			'og_image'       => $meta( '//meta[@property="og:image"]' ),
		),
		'headings' => array(),
		'links'    => array( 'internal' => array(), 'external' => array(), 'broken' => array() ),
		'images'   => array( 'total' => 0, 'missing_alt' => array(), 'empty_alt' => array() ),
		'warnings' => array(),
		'notes'    => array(),
	);

	// Encabezados en orden de documento (sin la barra de administración). "in_content" distingue
	// los del contenido (dentro de <main>, sin barra lateral, widgets ni comentarios) de los del tema:
	// los avisos de h1 y saltos de nivel solo miran los del contenido.
	$has_main = $xpath->query( '//body//main' )->length > 0;
	$theme_q  = 'ancestor::*[self::aside or @id="comments" or @id="respond" or contains(concat(" ", normalize-space(@class), " "), " comments-area ") or contains(concat(" ", normalize-space(@class), " "), " widget ")]';
	foreach ( $xpath->query( '//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][not(ancestor::*[@id="wpadminbar"])]' ) as $h ) {
		$in_content        = ( ! $has_main || $xpath->query( 'ancestor::main', $h )->length > 0 ) && 0 === $xpath->query( $theme_q, $h )->length;
		$out['headings'][] = array( 'level' => (int) substr( $h->nodeName, 1 ), 'text' => spiral_mcp_dom_text( $h ), 'in_content' => $in_content );
	}
	$content_h = array_values( array_filter( $out['headings'], function ( $h ) {
		return $h['in_content'];
	} ) );
	$h1 = count( array_filter( $content_h, function ( $h ) {
		return 1 === $h['level'];
	} ) );
	if ( 0 === $h1 ) {
		$out['warnings'][] = 'El contenido no tiene ningún h1.';
	} elseif ( $h1 > 1 ) {
		$out['warnings'][] = sprintf( 'El contenido tiene %d h1; lo recomendable es uno.', $h1 );
	}
	$prev = 0;
	foreach ( $content_h as $h ) {
		if ( $prev && $h['level'] > $prev + 1 ) {
			$out['warnings'][] = sprintf( 'Salto de nivel de encabezado en el contenido: h%d después de h%d ("%s").', $h['level'], $prev, $h['text'] );
		}
		$prev = $h['level'];
	}
	if ( empty( $out['seo']['description'] ) ) {
		$out['warnings'][] = 'Sin meta description (ver spiral-mcp/update-seo).';
	}

	// Enlaces, agrupados por destino: "count" dice cuántos hay y "texts", con qué textos.
	// Los que llevan el token de la vista previa apuntan a la propia página: se marcan "self",
	// sin token ni comprobación (un borrador da 404 a un visitante sin token).
	$seen = array();
	foreach ( $xpath->query( '//body//a[@href][not(ancestor::*[@id="wpadminbar"])]' ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		$text = spiral_mcp_dom_text( $a );
		$path = spiral_mcp_internal_path( $href );
		if ( null === $path ) {
			if ( preg_match( '#^(https?:)?//#i', $href ) ) {
				if ( isset( $seen[ $href ] ) ) {
					$out['links']['external'][ $seen[ $href ] ]['count']++;
					continue;
				}
				$seen[ $href ]              = count( $out['links']['external'] );
				$out['links']['external'][] = array( 'href' => $href, 'text' => $text, 'count' => 1 );
			}
			continue;
		}
		$path = preg_replace( '/#.*$/', '', $path );
		$self = false !== strpos( $path, 'spiral_preview=' );
		if ( $self ) {
			$path = remove_query_arg( array( 'spiral_preview', 'preview' ), $path );
		}
		$key = 'i:' . $path;
		if ( isset( $seen[ $key ] ) ) {
			$link = &$out['links']['internal'][ $seen[ $key ] ];
			$link['count']++;
			if ( '' !== $text && ! in_array( $text, $link['texts'], true ) ) {
				$link['texts'][] = $text;
			}
			unset( $link );
			continue;
		}
		$seen[ $key ]               = count( $out['links']['internal'] );
		$out['links']['internal'][] = array( 'href' => $path, 'text' => $text, 'texts' => '' === $text ? array() : array( $text ), 'count' => 1 ) + ( $self ? array( 'self' => true ) : array() );
	}
	if ( $check_links ) {
		$checked = 0;
		foreach ( $out['links']['internal'] as $i => $link ) {
			if ( $checked >= $max_links || ! empty( $link['self'] ) || preg_match( '#^/(wp-admin|wp-login\.php|wp-json|feed)#', $link['href'] ) ) {
				continue;
			}
			$checked++;
			$res = spiral_mcp_inspect_fetch( spiral_mcp_inspect_url( $link['href'] ), 'HEAD' );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 405 === $code ) {
				$res  = spiral_mcp_inspect_fetch( spiral_mcp_inspect_url( $link['href'] ) );
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			}
			$out['links']['internal'][ $i ]['status'] = $code;
			if ( 0 === $code || $code >= 400 ) {
				// Si el destino es contenido sin publicar, decirlo: se arregla publicándolo, no cambiando el enlace.
				$target = url_to_postid( home_url( $link['href'] ) );
				if ( ! $target ) { // url_to_postid() no resuelve borradores por su ruta.
					$by_path = get_page_by_path( trim( (string) wp_parse_url( $link['href'], PHP_URL_PATH ), '/' ), OBJECT, spiral_mcp_post_types() );
					$target  = $by_path ? $by_path->ID : 0;
				}
				$status = $target ? get_post_status( $target ) : false;
				if ( $status && 'publish' !== $status ) {
					$out['links']['internal'][ $i ]['target_id']     = $target;
					$out['links']['internal'][ $i ]['target_status'] = $status;
				}
				$out['links']['broken'][] = $out['links']['internal'][ $i ];
			}
		}
		$checkable = count( array_filter( $out['links']['internal'], function ( $l ) {
			return empty( $l['self'] ) && ! preg_match( '#^/(wp-admin|wp-login\.php|wp-json|feed)#', $l['href'] );
		} ) );
		if ( $checkable > $checked ) {
			$out['warnings'][] = sprintf( 'Solo se comprobaron %d de %d enlaces internos (max_links).', $checked, $checkable );
		}
	}
	if ( $out['links']['broken'] ) {
		$out['warnings'][] = sprintf( '%d enlaces internos rotos (ver links.broken).', count( $out['links']['broken'] ) );
	}

	// Imágenes.
	foreach ( $xpath->query( '//body//img[not(ancestor::*[@id="wpadminbar"])]' ) as $img ) {
		$out['images']['total']++;
		$src = $img->getAttribute( 'src' ) ? $img->getAttribute( 'src' ) : $img->getAttribute( 'data-src' );
		$src = null !== spiral_mcp_internal_path( $src ) ? spiral_mcp_internal_path( $src ) : $src;
		if ( ! $img->hasAttribute( 'alt' ) ) {
			$out['images']['missing_alt'][] = $src;
		} elseif ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
			$out['images']['empty_alt'][] = $src;
		}
	}
	if ( $out['images']['missing_alt'] ) {
		$out['warnings'][] = sprintf( '%d imágenes sin atributo alt (ver images.missing_alt).', count( $out['images']['missing_alt'] ) );
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Categoría y abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-preview', array(
		'label'       => 'Verificación Spiral-MCP',
		'description' => 'Vistas previas sin sesión e inspección de páginas renderizadas.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {

	$can_post = function ( $input = array() ) {
		return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-preview-url', array(
		'label'               => 'Obtener URL de vista previa',
		'description'         => 'Devuelve una URL temporal para ver una página o entrada (también borradores, pendientes o programadas) sin iniciar sesión: por ejemplo, para abrirla con tu navegador y hacer una captura antes de publicar. Caduca a las "hours" horas (1 por defecto, máximo 24), solo sirve para ese contenido, no se indexa y se puede revocar con spiral-mcp/revoke-preview-url. Compártela solo con quien deba ver el borrador. En una página de Elementor, "draft": true muestra tu borrador de Elementor (el autosave de patch-elementor-data con "draft": true) en vez de la versión publicada.',
		'category'            => 'spiral-mcp-preview',
		'log'                 => array( 'type' => 'post', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'hours' => array( 'type' => 'number', 'minimum' => 0.1, 'maximum' => 24, 'default' => 1 ),
				'draft' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Página de Elementor: ver tu borrador (autosave) en vez de la versión guardada.' ),
			),
		),
		'permission_callback' => $can_post,
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( 'trash' === $post->post_status ) {
				return new WP_Error( 'spiral_mcp_trashed', 'El contenido está en la papelera.' );
			}
			$hours = min( 24, max( 0.1, (float) ( $input['hours'] ?? 1 ) ) );
			$draft = ! empty( $input['draft'] );
			$notes = array();
			if ( $draft && ! spiral_mcp_preview_elementor_autosave( $post->ID, get_current_user_id() ) ) {
				$draft   = false;
				$notes[] = 'No tienes ningún borrador de Elementor de este contenido: la URL muestra la versión guardada.';
			}
			list( $token, $id, $exp ) = spiral_mcp_preview_create( $post->ID, (int) round( $hours * HOUR_IN_SECONDS ), $draft ? get_current_user_id() : 0 );
			return array(
				'id'         => (int) $post->ID,
				'status'     => $post->post_status,
				'url'        => spiral_mcp_preview_link( $post, $token ),
				'token_id'   => $id,
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $exp ),
				'draft'      => $draft,
			) + ( $notes ? array( 'notes' => $notes ) : array() );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/revoke-preview-url', array(
		'label'               => 'Revocar URLs de vista previa',
		'description'         => 'Invalida una URL de vista previa ("token_id", devuelto por spiral-mcp/get-preview-url) o, sin "token_id", todas las de ese contenido.',
		'category'            => 'spiral-mcp-preview',
		'log'                 => array( 'type' => 'post', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'token_id' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => $can_post,
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			return array( 'id' => (int) $post->ID, 'revoked' => spiral_mcp_preview_revoke( $post->ID, $input['token_id'] ?? null ) );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/inspect-page', array(
		'label'               => 'Inspeccionar una página',
		'description'         => 'Renderiza una página como la ve un visitante (también borradores, con una vista previa interna que se revoca al terminar) y devuelve un informe sin el HTML: código HTTP; SEO efectivo (title, description, canonical, robots, Open Graph); encabezados h1–h6 en orden ("in_content": false si son del tema, como widgets de la cabecera o el formulario de comentarios); enlaces internos agrupados por destino ("count" y "texts"; con su código HTTP: los rotos van en links.broken, con "target_status" si el destino existe pero no está publicado; "self" si apuntan a la propia vista previa) y externos; imágenes sin alt; "warnings" con lo que conviene corregir en el contenido (sin h1, varios h1, saltos de nivel, sin description, enlaces rotos, imágenes sin alt); y "notes" aclaratorias. Úsala antes de publicar y después de cada cambio importante. Indica "id" o "path" (ruta relativa, p. ej. /contacto/). En una página de Elementor, "draft": true inspecciona tu borrador de Elementor (autosave) en vez de la versión publicada.',
		'category'            => 'spiral-mcp-preview',
		'group'               => array( 'content', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'path'        => array( 'type' => 'string', 'description' => 'Ruta relativa del sitio, p. ej. / o /contacto/.' ),
				'check_links' => array( 'type' => 'boolean', 'default' => true ),
				'max_links'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 40 ),
				'draft'       => array( 'type' => 'boolean', 'default' => false, 'description' => 'Con "id" de una página de Elementor: inspeccionar tu borrador (autosave).' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$id = (int) ( ( (array) $input )['id'] ?? 0 );
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input  = (array) $input;
			$revoke = null;
			if ( ! empty( $input['id'] ) ) {
				$post = spiral_mcp_get_post( $input['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$draft  = ! empty( $input['draft'] ) && spiral_mcp_preview_elementor_autosave( $post->ID, get_current_user_id() );
				$public = 'publish' === $post->post_status && ! $draft;
				if ( $public ) {
					$url = get_permalink( $post );
				} else {
					list( $token, $revoke ) = spiral_mcp_preview_create( $post->ID, 10 * MINUTE_IN_SECONDS, $draft ? get_current_user_id() : 0 );
					$url                    = spiral_mcp_preview_link( $post, $token );
				}
				if ( ! empty( $input['draft'] ) && ! $draft ) {
					$draft_note = 'No tienes ningún borrador de Elementor de este contenido: se inspeccionó la versión guardada.';
				}
			} elseif ( ! empty( $input['path'] ) ) {
				$path = (string) $input['path'];
				if ( '/' !== substr( $path, 0, 1 ) || '//' === substr( $path, 0, 2 ) ) {
					return new WP_Error( 'spiral_mcp_bad_path', '"path" debe ser una ruta relativa del sitio, p. ej. /contacto/.' );
				}
				$url = home_url( $path );
			} else {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "id" o "path".' );
			}

			$res = spiral_mcp_inspect_fetch( spiral_mcp_inspect_url( $url ) );
			if ( $revoke ) {
				spiral_mcp_preview_revoke( $post->ID, $revoke );
			}
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'spiral_mcp_inspect_failed', 'No se pudo cargar la página desde el propio servidor: ' . $res->get_error_message() . ' (si el servidor no se alcanza a sí mismo por su dominio, define el filtro spiral_mcp_inspect_base_url).' );
			}
			$code   = (int) wp_remote_retrieve_response_code( $res );
			$report = spiral_mcp_inspect_html( (string) wp_remote_retrieve_body( $res ), ! empty( $input['check_links'] ?? true ), (int) ( $input['max_links'] ?? 40 ) );
			if ( $code >= 400 ) {
				array_unshift( $report['warnings'], sprintf( 'La página responde con HTTP %d.', $code ) );
			}
			if ( $revoke ) {
				$report['notes'][] = ! empty( $draft ) ? 'Inspeccionado tu borrador de Elementor (autosave), no la versión publicada: publícalo con spiral-mcp/publish-elementor-draft.' : 'Inspeccionada como vista previa (no está publicada): el robots "noindex" lo añade la vista previa; al publicar se aplica el del sitio y el SEO del contenido.';
			}
			if ( ! empty( $draft_note ) ) {
				$report['notes'][] = $draft_note;
			}
			return array(
				'url'     => $revoke ? remove_query_arg( array( 'spiral_preview', 'preview' ), $url ) : $url,
				'status'  => $code,
				'preview' => (bool) $revoke,
				'draft'   => ! empty( $draft ),
			) + $report;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
