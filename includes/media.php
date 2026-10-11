<?php
/**
 * Abilities de medios: subir (desde URL o base64), listar, leer, editar y
 * borrar adjuntos, imagen destacada y búsqueda de usos.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Extensiones que se pueden subir. Ajustable con el filtro spiral_mcp_upload_types. */
function spiral_mcp_media_types() {
	$types = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf', 'mp4', 'webm' );
	// SVG solo si un plugin de saneado de SVG lo permite explícitamente.
	if ( apply_filters( 'spiral_mcp_allow_svg', false ) ) {
		$types[] = 'svg';
	}
	return array_map( 'strtolower', (array) apply_filters( 'spiral_mcp_upload_types', $types ) );
}

/** Carga las funciones de wp-admin necesarias para subir archivos. */
function spiral_mcp_media_require_admin() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}

/** IPs (v4 y v6) a las que resuelve un host. */
function spiral_mcp_media_resolve( $host ) {
	$ips = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$ips = is_array( $ips ) ? $ips : array();
	if ( function_exists( 'dns_get_record' ) ) {
		$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $aaaa as $rec ) {
			if ( ! empty( $rec['ipv6'] ) ) {
				$ips[] = $rec['ipv6'];
			}
		}
	}
	return $ips;
}

/**
 * Comprueba que una URL sea segura para descargar: https, sin credenciales,
 * puerto 443 y que no resuelva a IPs privadas, reservadas ni de loopback
 * (protección contra SSRF). wp_safe_remote_get repite la comprobación en
 * cada redirección.
 */
function spiral_mcp_media_check_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_url', 'Solo se aceptan URLs públicas que empiecen por https://.' );
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_url', 'La URL no puede llevar usuario, contraseña ni un puerto distinto de 443.' );
	}
	$host = trim( $parts['host'], '[]' );
	$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : spiral_mcp_media_resolve( $host );
	if ( ! $ips ) {
		return new WP_Error( 'spiral_mcp_bad_url', sprintf( 'No se pudo resolver el dominio "%s".', $host ) );
	}
	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error( 'spiral_mcp_bad_url', 'La URL apunta a una dirección privada o local; solo se permiten servidores públicos.' );
		}
	}
	return true;
}

/** Descarga una URL a un archivo temporal respetando el límite de subida. Devuelve [ ruta, nombre ] o WP_Error. */
function spiral_mcp_media_download( $url ) {
	$check = spiral_mcp_media_check_url( $url );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$max  = wp_max_upload_size();
	$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	$tmp  = wp_tempnam( $name ? $name : 'spiral-mcp-media' );
	$res  = wp_safe_remote_get( $url, array(
		'timeout'             => 60,
		'stream'              => true,
		'filename'            => $tmp,
		'limit_response_size' => $max + 1,
	) );
	if ( is_wp_error( $res ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return new WP_Error( 'spiral_mcp_download_failed', 'No se pudo descargar la URL: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return new WP_Error( 'spiral_mcp_download_failed', sprintf( 'La URL respondió con HTTP %d.', $code ) );
	}
	if ( filesize( $tmp ) > $max ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return new WP_Error( 'spiral_mcp_too_big', sprintf( 'El archivo supera el máximo de subida (%s).', size_format( $max ) ) );
	}

	// Sin extensión en la URL: se deduce del Content-Type.
	if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
		$mime = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $res, 'content-type' ) )[0] ) );
		foreach ( wp_get_mime_types() as $exts => $type ) {
			if ( $type === $mime ) {
				$name = ( $name ? $name : 'archivo' ) . '.' . explode( '|', $exts )[0];
				break;
			}
		}
	}
	return array( $tmp, $name );
}

/** Escribe un base64 en un archivo temporal. Devuelve [ ruta, nombre ] o WP_Error. */
function spiral_mcp_media_from_base64( $data, $filename ) {
	$name = sanitize_file_name( (string) $filename );
	if ( '' === $name || ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
		return new WP_Error( 'spiral_mcp_bad_filename', 'Con "data_base64" envía también "filename" con extensión, p. ej. logo.png.' );
	}
	// Admite "data:image/png;base64,..." además del base64 puro.
	$data   = preg_replace( '/^data:[^,]*,/', '', (string) $data );
	$binary = base64_decode( preg_replace( '/\s+/', '', $data ), true );
	if ( false === $binary || '' === $binary ) {
		return new WP_Error( 'spiral_mcp_bad_base64', '"data_base64" no es base64 válido.' );
	}
	if ( strlen( $binary ) > wp_max_upload_size() ) {
		return new WP_Error( 'spiral_mcp_too_big', sprintf( 'El archivo supera el máximo de subida (%s).', size_format( wp_max_upload_size() ) ) );
	}
	$tmp = wp_tempnam( $name );
	if ( false === file_put_contents( $tmp, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'spiral_mcp_tmp_failed', 'No se pudo escribir el archivo temporal.' );
	}
	return array( $tmp, $name );
}

/** Valida el tipo real del archivo. Devuelve el nombre definitivo o WP_Error. */
function spiral_mcp_media_check_type( $tmp, $name ) {
	$allowed = spiral_mcp_media_types();
	$check   = wp_check_filetype_and_ext( $tmp, $name );
	$name    = $check['proper_filename'] ? $check['proper_filename'] : $name;
	$ext     = strtolower( (string) $check['ext'] );
	if ( '' === $ext || ! in_array( $ext, $allowed, true ) ) {
		return new WP_Error( 'spiral_mcp_bad_type', sprintf( 'Tipo de archivo no permitido. Permitidos: %s.', implode( ', ', $allowed ) ) );
	}
	return $name;
}

/** Ruta relativa a la raíz del sitio, el formato de los props de imagen de YOOtheme. */
function spiral_mcp_media_relative( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( '' !== $home && 0 === strpos( $path, $home ) ) {
		$path = substr( $path, strlen( $home ) );
	}
	return ltrim( rawurldecode( $path ), '/' );
}

/** Adjunto válido o WP_Error. */
function spiral_mcp_media_get( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'spiral_mcp_media_not_found', 'No existe un adjunto con ese ID. Búscalo con spiral-mcp/list-media.' );
	}
	return $post;
}

/** Formato de salida de un adjunto. */
function spiral_mcp_media_out( $post ) {
	$post  = get_post( $post );
	$url   = wp_get_attachment_url( $post->ID );
	$meta  = wp_get_attachment_metadata( $post->ID );
	$meta  = is_array( $meta ) ? $meta : array();
	$sizes = array();
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
		$src = wp_get_attachment_image_src( $post->ID, $size );
		if ( $src ) {
			$sizes[ $size ] = array(
				'url'           => $src[0],
				'path_relative' => spiral_mcp_media_relative( $src[0] ),
				'width'         => (int) $src[1],
				'height'        => (int) $src[2],
			);
		}
	}
	return array(
		'id'            => (int) $post->ID,
		'url'           => $url,
		'path_relative' => spiral_mcp_media_relative( $url ),
		'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'mime'          => $post->post_mime_type,
		'filesize'      => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
		'title'         => $post->post_title,
		'alt'           => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
		'caption'       => $post->post_excerpt,
		'description'   => $post->post_content,
		'parent'        => (int) $post->post_parent,
		'date'          => mysql_to_rfc3339( $post->post_date ),
		'sizes'         => (object) $sizes,
	);
}

/** Aplica title, caption, description y alt a un adjunto. */
function spiral_mcp_media_apply_fields( $id, array $in ) {
	$postarr = array( 'ID' => (int) $id );
	$map     = array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' );
	foreach ( $map as $key => $field ) {
		if ( array_key_exists( $key, $in ) ) {
			$postarr[ $field ] = (string) $in[ $key ];
		}
	}
	if ( count( $postarr ) > 1 ) {
		$res = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
	}
	if ( array_key_exists( 'alt', $in ) ) {
		update_post_meta( (int) $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $in['alt'] ) ) );
	}
	return true;
}

/**
 * Lugares donde se usa un adjunto: contenido de posts (también layouts de
 * YOOtheme), imagen destacada, configuración del tema, widgets, logo e icono
 * del sitio.
 */
function spiral_mcp_media_usage( $id ) {
	global $wpdb;
	$out     = spiral_mcp_media_out( $id );
	$needles = array( $out['path_relative'] );
	foreach ( (array) $out['sizes'] as $size ) {
		$needles[] = $size['path_relative'];
	}
	$needles = array_values( array_unique( array_filter( $needles ) ) );
	// En JSON guardado por YOOtheme las barras pueden ir escapadas (\/).
	$variants = array();
	foreach ( $needles as $n ) {
		$variants[] = $n;
		$variants[] = str_replace( '/', '\\/', $n );
	}
	$variants[] = 'wp-image-' . (int) $id;
	$contains   = function ( $haystack ) use ( $variants ) {
		foreach ( $variants as $v ) {
			if ( false !== strpos( (string) $haystack, $v ) ) {
				return true;
			}
		}
		return false;
	};

	// Posts: búsqueda en SQL y confirmación en PHP.
	$likes  = array();
	$values = array();
	foreach ( $variants as $v ) {
		$likes[]  = 'post_content LIKE %s';
		$values[] = '%' . $wpdb->esc_like( $v ) . '%';
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status NOT IN ('trash','auto-draft','inherit') AND (" . implode( ' OR ', $likes ) . ') LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values
	) );
	$posts = array();
	foreach ( (array) $rows as $r ) {
		$posts[ (int) $r->ID ] = array( 'id' => (int) $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'status' => $r->post_status, 'where' => array( 'content' ) );
	}
	$thumbs = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) (int) $id ) );
	foreach ( $thumbs as $pid ) {
		$p = get_post( (int) $pid );
		if ( ! $p || in_array( $p->post_type, array( 'revision' ), true ) ) {
			continue;
		}
		if ( isset( $posts[ $p->ID ] ) ) {
			$posts[ $p->ID ]['where'][] = 'featured_image';
		} else {
			$posts[ $p->ID ] = array( 'id' => (int) $p->ID, 'title' => $p->post_title, 'type' => $p->post_type, 'status' => $p->post_status, 'where' => array( 'featured_image' ) );
		}
	}

	// Configuración de YOOtheme: claves de primer nivel que lo contienen.
	$theme_keys = array();
	$raw        = get_theme_mod( 'config', null );
	$config     = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	foreach ( (array) $config as $key => $value ) {
		if ( $contains( is_string( $value ) ? $value : spiral_mcp_json( $value ) ) || $contains( wp_json_encode( $value ) ) ) {
			$theme_keys[] = $key;
		}
	}

	// Widgets.
	$widgets = array();
	$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%'" );
	foreach ( $options as $option ) {
		$base = substr( $option, 7 );
		foreach ( (array) get_option( $option ) as $num => $instance ) {
			if ( is_numeric( $num ) && is_array( $instance ) && $contains( spiral_mcp_json( $instance ) . wp_json_encode( $instance ) ) ) {
				$widgets[] = $base . '-' . $num;
			}
		}
	}

	$site = array();
	if ( (int) get_option( 'site_icon' ) === (int) $id ) {
		$site[] = 'site_icon';
	}
	if ( (int) get_theme_mod( 'custom_logo' ) === (int) $id ) {
		$site[] = 'custom_logo';
	}

	$posts = array_values( $posts );
	return array(
		'id'           => (int) $id,
		'searched'     => $needles,
		'used'         => (bool) ( $posts || $theme_keys || $widgets || $site ),
		'posts'        => $posts,
		'theme_config' => $theme_keys,
		'widgets'      => $widgets,
		'site'         => $site,
	);
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Título, leyenda, descripción y alt de un adjunto. */
function spiral_mcp_media_meta_capture( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post ) {
		return null;
	}
	return array(
		'title'       => $post->post_title,
		'caption'     => $post->post_excerpt,
		'description' => $post->post_content,
		'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
	);
}

function spiral_mcp_media_meta_restore( $id, $data ) {
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'spiral_mcp_bad_snapshot', 'El snapshot del adjunto está vacío.' );
	}
	$post = spiral_mcp_media_get( $id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$res = spiral_mcp_media_apply_fields( $post->ID, $data );
	return is_wp_error( $res ) ? $res : array( 'media' => spiral_mcp_media_out( $post->ID ) );
}

/** Un meta de un post. $ref = "ID:clave". null = no existía. */
function spiral_mcp_post_meta_capture( $ref ) {
	list( $id, $key ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	return metadata_exists( 'post', (int) $id, $key ) ? get_post_meta( (int) $id, $key, true ) : null;
}

function spiral_mcp_post_meta_restore( $ref, $data ) {
	list( $id, $key ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
	if ( ! get_post( (int) $id ) || '' === $key ) {
		return new WP_Error( 'spiral_mcp_not_found', 'El post del snapshot ya no existe.' );
	}
	if ( null === $data ) {
		delete_post_meta( (int) $id, $key );
	} else {
		update_post_meta( (int) $id, $key, wp_slash( $data ) );
	}
	return true;
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['media_meta'] = array(
		'group'   => 'media',
		'cap'     => 'upload_files',
		'capture' => 'spiral_mcp_media_meta_capture',
		'restore' => 'spiral_mcp_media_meta_restore',
	);
	$kinds['post_meta']  = array(
		'group'   => 'content',
		'cap'     => 'edit_posts',
		'capture' => 'spiral_mcp_post_meta_capture',
		'restore' => 'spiral_mcp_post_meta_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-media', array(
		'label'       => 'Medios Spiral-MCP',
		'description' => 'Subir, listar, editar y borrar imágenes y archivos de la biblioteca de medios.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'upload_files' );
	};

	$media_desc = 'Cada adjunto incluye id, url, path_relative (p. ej. wp-content/uploads/2024/11/logo.webp: es el valor que va en los props de imagen de YOOtheme, como "image"), width, height, mime, alt, title, caption, description y sizes (miniaturas con url, path_relative, width y height).';

	/* 1. Subir ------------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/upload-media', array(
		'label'               => 'Subir archivo a la biblioteca de medios',
		'description'         => 'Sube una imagen, PDF o vídeo a la biblioteca de medios desde una URL pública https ("url") o desde datos en base64 ("data_base64" + "filename"). Tipos: ' . implode( ', ', spiral_mcp_media_types() ) . '. Máximo ' . size_format( wp_max_upload_size() ) . '. Rellena siempre "alt" en las imágenes. ' . $media_desc . ' Para usarla en un layout pon su path_relative en el prop de imagen del elemento.',
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'log'                 => array( 'type' => 'media', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'url'         => array( 'type' => 'string', 'description' => 'URL https pública del archivo.' ),
				'data_base64' => array( 'type' => 'string', 'description' => 'Contenido en base64 (admite el prefijo data:...;base64,). Alternativa a "url".' ),
				'filename'    => array( 'type' => 'string', 'description' => 'Nombre con extensión. Obligatorio con data_base64; con url, opcional (renombra el archivo).' ),
				'title'       => array( 'type' => 'string' ),
				'alt'         => array( 'type' => 'string', 'description' => 'Texto alternativo (accesibilidad y SEO).' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'attach_to'   => array( 'type' => 'integer', 'description' => 'ID de la página o entrada a la que se asocia.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$attach = (int) ( ( (array) $input )['attach_to'] ?? 0 );
			return current_user_can( 'upload_files' ) && ( ! $attach || current_user_can( 'edit_post', $attach ) );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$has_url = ! empty( $input['url'] );
			$has_b64 = ! empty( $input['data_base64'] );
			if ( $has_url === $has_b64 ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Envía "url" o "data_base64" (uno de los dos).' );
			}
			$attach = (int) ( $input['attach_to'] ?? 0 );
			if ( $attach && ! get_post( $attach ) ) {
				return new WP_Error( 'spiral_mcp_not_found', 'No existe el post indicado en "attach_to".' );
			}
			spiral_mcp_media_require_admin();

			$file = $has_url ? spiral_mcp_media_download( $input['url'] ) : spiral_mcp_media_from_base64( $input['data_base64'], $input['filename'] ?? '' );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			list( $tmp, $name ) = $file;
			if ( $has_url && ! empty( $input['filename'] ) ) {
				$name = sanitize_file_name( $input['filename'] );
			}
			$name = spiral_mcp_media_check_type( $tmp, $name );
			if ( is_wp_error( $name ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $name;
			}

			$post_data = array();
			if ( isset( $input['title'] ) ) {
				$post_data['post_title'] = (string) $input['title'];
			}
			$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $attach, null, $post_data );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $id;
			}
			$res = spiral_mcp_media_apply_fields( $id, array_intersect_key( $input, array_flip( array( 'alt', 'caption', 'description' ) ) ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_media_out( $id );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 2. Listar ----------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-media', array(
		'label'               => 'Listar medios',
		'description'         => 'Lista la biblioteca de medios, más reciente primero, con filtros por texto, tipo y post al que está asociado. ' . $media_desc,
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string', 'description' => 'Busca en título, nombre de archivo y textos.' ),
				'mime'     => array( 'type' => 'string', 'enum' => array( 'image', 'video', 'application/pdf' ) ),
				'parent'   => array( 'type' => 'integer', 'description' => 'ID del post al que están asociados (0 = sin asociar).' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$args  = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			if ( ! empty( $input['search'] ) ) {
				$args['s'] = (string) $input['search'];
			}
			if ( ! empty( $input['mime'] ) ) {
				$args['post_mime_type'] = (string) $input['mime'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['post_parent'] = (int) $input['parent'];
			}
			$q = new WP_Query( $args );
			return array(
				'total' => (int) $q->found_posts,
				'items' => array_map( 'spiral_mcp_media_out', $q->posts ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Obtener ---------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-media', array(
		'label'               => 'Obtener adjunto',
		'description'         => 'Devuelve un adjunto de la biblioteca de medios por ID. ' . $media_desc,
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_media_get( $input['id'] ?? 0 );
			return is_wp_error( $post ) ? $post : spiral_mcp_media_out( $post );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 4. Editar ----------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-media', array(
		'label'               => 'Editar adjunto',
		'description'         => 'Cambia alt, title, caption o description de un adjunto (solo los campos enviados). No cambia el archivo. Antes guarda un snapshot ("snapshot_id", deshacer con spiral-mcp/restore-snapshot).',
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'log'                 => array( 'type' => 'media', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'alt'         => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_media_get( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$fields = array_intersect_key( (array) $input, array_flip( array( 'alt', 'title', 'caption', 'description' ) ) );
			if ( ! $fields ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos uno de: alt, title, caption, description.' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'media_meta', $post->ID, 'update-media' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_media_apply_fields( $post->ID, $fields );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_media_out( $post->ID ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 5. Borrar ----------------------------------------------------------- */
	$trash = defined( 'MEDIA_TRASH' ) && MEDIA_TRASH;
	spiral_mcp_register_ability( 'spiral-mcp/delete-media', array(
		'label'               => 'Borrar adjunto',
		'description'         => ( $trash
			? 'Mueve un adjunto a la papelera de medios (en este sitio MEDIA_TRASH está activo; se puede recuperar desde wp-admin).'
			: 'Borra DEFINITIVAMENTE un adjunto y todos sus tamaños del disco (en este sitio MEDIA_TRASH está desactivado: no hay papelera ni snapshot, no se puede deshacer).' )
			. ' Antes comprueba con spiral-mcp/find-media-usage que no se use en páginas, el tema o widgets: esas referencias quedarían rotas. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'log'                 => array( 'type' => 'media', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'delete_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) use ( $trash ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar el adjunto' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$post = spiral_mcp_media_get( $input['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$before = spiral_mcp_media_out( $post );
			if ( ! wp_delete_attachment( $post->ID, ! $trash ) ) {
				return new WP_Error( 'spiral_mcp_delete_failed', 'No se pudo borrar el adjunto.' );
			}
			return array( 'deleted' => true, 'trashed' => $trash, 'media' => $before );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* 6. Imagen destacada ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/set-featured-image', array(
		'label'               => 'Poner o quitar la imagen destacada',
		'description'         => 'Asigna una imagen de la biblioteca como imagen destacada de una página o entrada ("media_id"), o la quita con "media_id": 0. Antes guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-media',
		'group'               => array( 'media', 'content' ),
		'log'                 => array( 'type' => 'post', 'id' => 'post_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'media_id' ),
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'media_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'ID del adjunto (imagen); 0 = quitar.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_post', (int) ( ( (array) $input )['post_id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_get_post( $input['post_id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				return new WP_Error( 'spiral_mcp_no_thumbnail', sprintf( 'El tipo "%s" no admite imagen destacada.', $post->post_type ) );
			}
			$media_id = (int) ( $input['media_id'] ?? 0 );
			if ( $media_id ) {
				$media = spiral_mcp_media_get( $media_id );
				if ( is_wp_error( $media ) ) {
					return $media;
				}
				if ( ! wp_attachment_is_image( $media_id ) ) {
					return new WP_Error( 'spiral_mcp_not_image', 'La imagen destacada debe ser una imagen (no un PDF ni un vídeo).' );
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'post_meta', $post->ID . ':_thumbnail_id', 'set-featured-image' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if ( $media_id ) {
				set_post_thumbnail( $post, $media_id );
			} else {
				delete_post_thumbnail( $post );
			}
			return array(
				'post_id'     => (int) $post->ID,
				'media'       => $media_id ? spiral_mcp_media_out( $media_id ) : null,
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 7. Buscar usos ------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/find-media-usage', array(
		'label'               => 'Buscar dónde se usa un adjunto',
		'description'         => 'Busca un adjunto (su path_relative y los de sus tamaños, también con barras escapadas) en el contenido y layouts de YOOtheme de páginas y entradas, en la imagen destacada, en la configuración del tema (claves de primer nivel), en los widgets y en el logo e icono del sitio. Úsalo antes de spiral-mcp/delete-media. No detecta usos por URL externa ni en CSS compilado.',
		'category'            => 'spiral-mcp-media',
		'group'               => 'media',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => function () {
			return current_user_can( 'upload_files' ) && current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input ) {
			$post = spiral_mcp_media_get( $input['id'] ?? 0 );
			return is_wp_error( $post ) ? $post : spiral_mcp_media_usage( $post->ID );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
