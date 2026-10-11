<?php
/**
 * Librería online de YOOtheme (api.yootheme.com): catálogo de layouts (páginas,
 * plantillas, footers, mega menús…) y de elementos prediseñados, e importación
 * al sitio como plantilla, en la biblioteca o dentro de un layout.
 *
 * El catálogo es público. Descargar un diseño exige la clave de API de YOOtheme
 * (config.yootheme_apikey), que se usa solo en el servidor y nunca se devuelve.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** URL base de la API de la librería. Filtro spiral_mcp_online_library_api. */
function spiral_mcp_online_api() {
	return untrailingslashit( (string) apply_filters( 'spiral_mcp_online_library_api', 'https://api.yootheme.com/v1/library' ) );
}

/** Clave de API de YOOtheme guardada en la configuración del tema ('' si no hay). */
function spiral_mcp_online_apikey() {
	$read = function_exists( 'spiral_mcp_theme_config_read' ) ? spiral_mcp_theme_config_read() : null;
	if ( ! is_array( $read ) ) {
		return '';
	}
	return trim( (string) ( $read['config']['yootheme_apikey'] ?? '' ) );
}

/** Quita la clave de cualquier texto (mensajes de error, URLs). */
function spiral_mcp_online_scrub( $text ) {
	$key = spiral_mcp_online_apikey();
	return '' === $key ? (string) $text : str_replace( array( $key, rawurlencode( $key ) ), '[clave]', (string) $text );
}

/**
 * Tipo de plantilla del sitio que corresponde a un diseño del catálogo (grupo "template"),
 * o null si no hay equivalencia clara (tipos de contenido propios de la web de ejemplo).
 */
function spiral_mcp_online_template_type( $type, $name ) {
	$name = strtolower( (string) $name );
	switch ( (string) $type ) {
		case 'Post':
			return 'single-post';
		case 'Search':
			return 'search';
		case 'Error 404':
			return 'error-404';
		case 'Index':
			if ( 'post index' === $name ) {
				return 'archive-post';
			}
			if ( 'author' === $name ) {
				return 'author-archive';
			}
			if ( 0 === strpos( $name, 'post category' ) ) {
				return 'taxonomy-category';
			}
			return null;
	}
	return null;
}

/** Normaliza una entrada del catálogo. */
function spiral_mcp_online_entry( array $raw, $kind ) {
	$meta = (array) ( $raw['meta'] ?? array() );
	$link = (string) ( $raw['link'] ?? '' );
	$out  = array(
		'id'      => basename( (string) wp_parse_url( $link, PHP_URL_PATH ) ),
		'kind'    => $kind,
		'name'    => (string) ( $raw['name'] ?? '' ),
		'group'   => 'element' === $kind ? 'element' : (string) ( $meta['group'] ?? '' ),
		'type'    => (string) ( 'element' === $kind ? ( $meta['type'] ?? '' ) : ( $meta['layout'] ?? '' ) ),
		'website' => (string) ( $meta['website'] ?? '' ),
		'topic'   => (string) ( $meta['topic'] ?? '' ),
		'preview' => (string) ( $meta['image'] ?? '' ),
	);
	if ( 'template' === $out['group'] ) {
		$out['template_type'] = spiral_mcp_online_template_type( $out['type'], $out['name'] );
	}
	return $out;
}

/** Catálogo (lista normalizada) de "layout" o "element". Se cachea 12 horas. */
function spiral_mcp_online_catalog( $kind, $refresh = false ) {
	$kind = 'element' === $kind ? 'element' : 'layout';
	$key  = 'spiral_mcp_online_catalog_' . $kind;
	if ( ! $refresh ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$res = wp_remote_get( spiral_mcp_online_api() . '/' . $kind, array( 'timeout' => 30 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		$why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		return new WP_Error( 'spiral_mcp_online_unavailable', 'No se pudo leer el catálogo de la librería de YOOtheme (' . $why . '). ¿Tiene el servidor salida a internet?' );
	}
	$raw = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $raw ) ) {
		return new WP_Error( 'spiral_mcp_online_unavailable', 'La librería de YOOtheme devolvió un catálogo que no se entiende.' );
	}
	$out = array();
	foreach ( $raw as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['link'] ) ) {
			$out[] = spiral_mcp_online_entry( $entry, $kind );
		}
	}
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/** Busca un diseño en el catálogo por ID. */
function spiral_mcp_online_find( $kind, $id ) {
	$catalog = spiral_mcp_online_catalog( $kind );
	if ( is_wp_error( $catalog ) ) {
		return $catalog;
	}
	foreach ( $catalog as $entry ) {
		if ( $entry['id'] === (string) $id ) {
			return $entry;
		}
	}
	return new WP_Error( 'spiral_mcp_online_not_found', sprintf( 'No existe el diseño "%s" en el catálogo de %s. Búscalo con spiral-mcp/list-online-library (y "kind": "element" para elementos).', $id, 'element' === $kind ? 'elementos' : 'layouts' ) );
}

/** Descarga un diseño con la clave de API. Devuelve [ entrada del catálogo, árbol ] o WP_Error. */
function spiral_mcp_online_fetch( $kind, $id ) {
	if ( ! preg_match( '/^[a-z0-9]{4,40}$/i', (string) $id ) ) {
		return new WP_Error( 'spiral_mcp_bad_id', '"id" no válido: usa el "id" que devuelve spiral-mcp/list-online-library.' );
	}
	$entry = spiral_mcp_online_find( $kind, $id );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	$key = spiral_mcp_online_apikey();
	if ( '' === $key ) {
		return new WP_Error( 'spiral_mcp_no_apikey', 'Para descargar diseños de la librería hace falta la clave de API de YOOtheme: pide al usuario que la ponga en Personalizar > Ajustes > API Key.' );
	}
	$url = spiral_mcp_online_api() . '/' . $entry['kind'] . '/' . rawurlencode( $entry['id'] );
	$res = wp_remote_get( add_query_arg( 'key', rawurlencode( $key ), $url ), array( 'timeout' => 30 ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'spiral_mcp_online_unavailable', 'No se pudo descargar el diseño: ' . spiral_mcp_online_scrub( $res->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 401 === $code || 403 === $code ) {
		return new WP_Error( 'spiral_mcp_bad_apikey', 'YOOtheme rechazó la clave de API (HTTP ' . $code . '). Pide al usuario que revise la clave en Personalizar > Ajustes > API Key.' );
	}
	$tree = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $tree ) || empty( $tree['type'] ) ) {
		return new WP_Error( 'spiral_mcp_online_unavailable', 'La librería de YOOtheme no devolvió un diseño válido (HTTP ' . $code . ').' );
	}
	return array( $entry, $tree );
}

/** Cuenta los nodos con fuente dinámica de un árbol. */
function spiral_mcp_online_count_sources( array $node ) {
	$n = ! empty( $node['source'] ) ? 1 : 0;
	foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
		if ( is_array( $child ) ) {
			$n += spiral_mcp_online_count_sources( $child );
		}
	}
	return $n;
}

/**
 * Descarga las imágenes de un diseño a la carpeta de subidas (como el Personalizador:
 * sin subcarpeta de fecha y sin duplicar si ya existe un archivo con el mismo md5), las
 * registra en la biblioteca de medios y cambia sus URLs del árbol por la ruta relativa.
 * Devuelve [ árbol, medios (id + path), avisos ].
 */
function spiral_mcp_online_import_images( array $tree, array $images ) {
	$media    = array();
	$warnings = array();
	if ( ! $images ) {
		return array( $tree, $media, $warnings );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$json   = spiral_mcp_json( $tree );
	$no_sub = function ( $upload ) {
		if ( ! empty( $upload['subdir'] ) ) {
			$upload['url']  = $upload['baseurl'];
			$upload['path'] = $upload['basedir'];
		}
		return $upload;
	};
	$upload = wp_upload_dir( null, false );
	$base   = $upload['basedir'];
	// Ruta relativa a la raíz del sitio, como la guarda YOOtheme (wp-content/uploads/foto.jpg).
	$rel = function ( $url ) {
		return ltrim( (string) wp_make_link_relative( (string) $url ), '/' );
	};
	foreach ( $images as $image ) {
		$src = (string) ( $image['src'] ?? '' );
		$md5 = (string) ( $image['md5'] ?? '' );
		if ( '' === $src || false === strpos( $json, $src ) ) {
			continue;
		}
		$file = sanitize_file_name( basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$path = null;
		// ¿Ya existe (misma imagen)? Si el nombre está ocupado por otra, se prueba nombre-01, -02…
		for ( $i = 0; $i < 50 && is_file( $base . '/' . $file ); $i++ ) {
			if ( '' !== $md5 && md5_file( $base . '/' . $file ) === $md5 ) {
				$path = $file;
				break;
			}
			$file = preg_replace_callback( '/(?:-(\d{2}))?(\.[^.]+)?$/', function ( $m ) {
				return sprintf( '-%02d%s', (int) ( $m[1] ?? 0 ) + 1, $m[2] ?? '' );
			}, $file, 1 );
		}
		if ( null === $path ) {
			$tmp = download_url( $src, 60 );
			if ( is_wp_error( $tmp ) ) {
				$warnings[] = sprintf( 'No se pudo descargar la imagen %s: %s. Se ha quitado del diseño; sube otra con spiral-mcp/upload-media.', $file, $tmp->get_error_message() );
				$json       = str_replace( spiral_mcp_json_string( $src ), '""', $json );
				continue;
			}
			add_filter( 'upload_dir', $no_sub );
			$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0 );
			remove_filter( 'upload_dir', $no_sub );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$warnings[] = sprintf( 'No se pudo importar la imagen %s: %s. Se ha quitado del diseño.', $file, $id->get_error_message() );
				$json       = str_replace( spiral_mcp_json_string( $src ), '""', $json );
				continue;
			}
			$path    = $rel( wp_get_attachment_url( $id ) );
			$media[] = array( 'id' => (int) $id, 'path_relative' => $path );
		} else {
			$url     = trailingslashit( $upload['baseurl'] ) . $path;
			$path    = $rel( $url );
			$media[] = array( 'id' => (int) attachment_url_to_postid( $url ), 'path_relative' => $path, 'existing' => true );
		}
		$json = str_replace( spiral_mcp_json_string( $src ), spiral_mcp_json_string( $path ), $json );
	}
	$out = json_decode( $json, true );
	return array( is_array( $out ) ? $out : $tree, $media, $warnings );
}

/** Contenido de un texto tal como queda dentro de un JSON (sin las comillas). */
function spiral_mcp_json_string( $text ) {
	return substr( spiral_mcp_json( (string) $text ), 1, -1 );
}

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	/* 1. Catálogo --------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-online-library', array(
		'label'               => 'Listar la librería online de YOOtheme',
		'description'         => 'Lista los diseños prediseñados de la librería online de YOOtheme (los mismos que ofrece el Personalizador). "kind": "layout" (por defecto) trae diseños completos agrupados en "group": template (plantillas: Post, Index, Search, Error 404…), layout (páginas: Home, Services, About, Contact…), footer, dropdown (mega menús), search y dialog; "kind": "element" trae elementos sueltos (accordion, button, grid, slideshow…). Cada diseño pertenece a una web de ejemplo ("website", con el mismo nombre que los estilos: Circle, Fuse…): los del estilo activo del sitio encajan mejor. Filtra por group, type, website o search; "facets" da los valores posibles. En las plantillas, "template_type" es el tipo de plantilla del sitio que le corresponde (null = sin equivalencia clara). "preview" es una imagen de muestra. Después: spiral-mcp/get-online-library-item para ver su estructura y spiral-mcp/import-from-online-library para traerlo. No necesita la clave de API ("has_api_key" dice si la descarga será posible).',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'kind'     => array( 'type' => 'string', 'enum' => array( 'layout', 'element' ), 'default' => 'layout' ),
				'group'    => array( 'type' => 'string', 'description' => 'template, layout, footer, dropdown, search o dialog (solo kind layout).' ),
				'type'     => array( 'type' => 'string', 'description' => 'Tipo dentro del grupo: Post, Index, Home, Services… (en elementos: accordion, grid…).' ),
				'website'  => array( 'type' => 'string', 'description' => 'Web de ejemplo, p. ej. Circle.' ),
				'search'   => array( 'type' => 'string', 'description' => 'Texto a buscar en el nombre, la web o el tema.' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'refresh'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'true = vuelve a pedir el catálogo (se guarda 12 horas).' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$input   = (array) $input;
			$kind    = 'element' === ( $input['kind'] ?? '' ) ? 'element' : 'layout';
			$catalog = spiral_mcp_online_catalog( $kind, ! empty( $input['refresh'] ) );
			if ( is_wp_error( $catalog ) ) {
				return $catalog;
			}
			$facets = array( 'group' => array(), 'type' => array(), 'website' => array() );
			foreach ( $catalog as $entry ) {
				foreach ( array_keys( $facets ) as $f ) {
					$facets[ $f ][ $entry[ $f ] ] = ( $facets[ $f ][ $entry[ $f ] ] ?? 0 ) + 1;
				}
			}
			foreach ( $facets as $f => $counts ) {
				ksort( $counts );
				$facets[ $f ] = (object) $counts;
			}
			$match = function ( $value, $want ) {
				return '' === (string) $want || 0 === strcasecmp( (string) $value, (string) $want );
			};
			$search = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
			$items  = array_values( array_filter( $catalog, function ( $e ) use ( $input, $match, $search ) {
				return $match( $e['group'], $input['group'] ?? '' ) && $match( $e['type'], $input['type'] ?? '' ) && $match( $e['website'], $input['website'] ?? '' )
					&& ( '' === $search || false !== strpos( strtolower( $e['name'] . ' ' . $e['website'] . ' ' . $e['topic'] . ' ' . $e['type'] ), $search ) );
			} ) );
			$per  = (int) ( $input['per_page'] ?? 50 );
			$page = max( 1, (int) ( $input['page'] ?? 1 ) );
			return array(
				'kind'        => $kind,
				'total'       => count( $items ),
				'items'       => array_slice( $items, ( $page - 1 ) * $per, $per ),
				'facets'      => $facets,
				'has_api_key' => '' !== spiral_mcp_online_apikey(),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Ver un diseño ---------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-online-library-item', array(
		'label'               => 'Ver un diseño de la librería online',
		'description'         => 'Descarga un diseño de la librería online de YOOtheme (con la clave de API del sitio, que no se muestra) y devuelve su estructura sin importarlo: el esquema de nodos (como spiral-mcp/get-layout-outline), cuántas imágenes trae, cuántos nodos tienen fuente dinámica y, en plantillas, el tipo de plantilla del sitio que le corresponde. Ojo: muchos diseños de la librería son contenido de muestra fijo (textos e imágenes de la web de ejemplo, sin fuentes dinámicas): tras importarlos hay que adaptarlos. "include_tree": true devuelve también el árbol completo.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'           => array( 'type' => 'string', 'description' => 'ID del diseño (ver spiral-mcp/list-online-library).' ),
				'kind'         => array( 'type' => 'string', 'enum' => array( 'layout', 'element' ), 'default' => 'layout' ),
				'include_tree' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$got = spiral_mcp_online_fetch( 'element' === ( $input['kind'] ?? '' ) ? 'element' : 'layout', $input['id'] ?? '' );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $entry, $tree ) = $got;
			$images               = (array) ( $tree['images'] ?? array() );
			$clean                = array_diff_key( $tree, array_flip( array( 'id', 'path', 'meta', 'name', 'images' ) ) );
			$out                  = $entry + array(
				'version'         => (string) ( $tree['version'] ?? '' ),
				'root'            => (string) $tree['type'],
				'images'          => count( $images ),
				'dynamic_sources' => spiral_mcp_online_count_sources( $clean ),
				'outline'         => spiral_mcp_layout_outline( $clean ),
			);
			if ( ! empty( $input['include_tree'] ) ) {
				// Las URLs de las imágenes son temporales (caducan en una hora): no sirven para guardarlas.
				$out['tree'] = $clean;
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Importar --------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/import-from-online-library', array(
		'label'               => 'Importar de la librería online',
		'description'         => 'Importa un diseño de la librería online de YOOtheme (ver spiral-mcp/list-online-library) con la clave de API del sitio. Descarga sus imágenes a la biblioteca de medios (como el Personalizador) y cambia sus URLs por rutas del sitio. "target": "template" crea una plantilla (solo diseños kind layout; "template_type" por defecto es el que corresponde al diseño; "status" por defecto "disabled", para revisarla antes de activarla con spiral-mcp/update-template); "library" lo guarda en la biblioteca del sitio (para spiral-mcp/insert-from-library); "insert" lo inserta en un layout ("id" de página o "area", y "path" como en spiral-mcp/insert-from-library: secciones en la raíz de una página, un elemento dentro de los children de una columna). Muchos diseños son contenido de muestra fijo: después cambia textos, enlaces e imágenes y, en plantillas, añade fuentes dinámicas (p. ej. posts.singlePost) para que muestren el contenido real.',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'design' ),
		'log'                 => array(
			'type' => 'online_library',
			'id'   => function ( $in, $res ) {
				return is_array( $res ) && isset( $res['template']['id'] ) ? 'template:' . $res['template']['id'] : (string) ( $in['id'] ?? '' );
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'target' ),
			'properties' => array(
				'id'            => array( 'type' => 'string', 'description' => 'ID del diseño (ver spiral-mcp/list-online-library).' ),
				'kind'          => array( 'type' => 'string', 'enum' => array( 'layout', 'element' ), 'default' => 'layout' ),
				'target'        => array( 'type' => 'string', 'enum' => array( 'template', 'library', 'insert' ) ),
				'name'          => array( 'type' => 'string', 'description' => 'Nombre de la plantilla o del elemento de biblioteca (por defecto, "<web> – <nombre>").' ),
				'template_type' => array( 'type' => 'string', 'description' => 'Solo target template: tipo de plantilla del sitio (single-post, archive-post…).' ),
				'status'        => array( 'type' => 'string', 'enum' => array( 'enabled', 'disabled' ), 'default' => 'disabled' ),
				'post_id'       => array( 'type' => 'integer', 'description' => 'Solo target insert: página de destino.' ),
				'area'          => array( 'type' => 'string', 'description' => 'Solo target insert, en lugar de "post_id": área del tema o "template:<id>".' ),
				'path'          => array( 'type' => 'string', 'description' => 'Solo target insert: posición en una lista children, p. ej. /children/1.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$input = (array) $input;
			if ( ! current_user_can( 'upload_files' ) ) {
				return false;
			}
			if ( 'insert' === ( $input['target'] ?? '' ) ) {
				return spiral_mcp_layout_target_can( array( 'id' => $input['post_id'] ?? 0, 'area' => $input['area'] ?? '' ) );
			}
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input ) {
			$kind   = 'element' === ( $input['kind'] ?? '' ) ? 'element' : 'layout';
			$target = (string) ( $input['target'] ?? '' );
			if ( 'template' === $target && 'element' === $kind ) {
				return new WP_Error( 'spiral_mcp_bad_target', 'Un elemento suelto no puede ser una plantilla: usa "target": "insert" o "library".' );
			}
			if ( 'insert' === $target && ( empty( $input['path'] ) || ( empty( $input['post_id'] ) && empty( $input['area'] ) ) ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Con "target": "insert" indica "post_id" (o "area") y "path".' );
			}
			$got = spiral_mcp_online_fetch( $kind, $input['id'] ?? '' );
			if ( is_wp_error( $got ) ) {
				return $got;
			}
			list( $entry, $tree ) = $got;
			$template_type        = (string) ( $input['template_type'] ?? ( $entry['template_type'] ?? '' ) );
			if ( 'template' === $target && '' === $template_type ) {
				return new WP_Error( 'spiral_mcp_bad_template_type', sprintf( 'El diseño "%s" (%s) no tiene un tipo de plantilla equivalente claro: indica "template_type" (ver "types" en spiral-mcp/list-templates).', $entry['name'], $entry['type'] ) );
			}

			list( $tree, $media, $warnings ) = spiral_mcp_online_import_images( $tree, (array) ( $tree['images'] ?? array() ) );
			$tree                            = array_diff_key( $tree, array_flip( array( 'id', 'path', 'meta', 'name', 'images' ) ) );
			// Migraciones de YOOtheme según la versión con la que se hizo el diseño.
			$tree = spiral_mcp_yootheme_normalize( $tree );
			$name = (string) ( $input['name'] ?? ( ( '' !== $entry['website'] && 'elements' !== $entry['website'] ? $entry['website'] . ' – ' : '' ) . $entry['name'] ) );
			$out  = array(
				'imported'        => $entry,
				'target'          => $target,
				'media'           => $media,
				'dynamic_sources' => spiral_mcp_online_count_sources( $tree ),
			);

			if ( 'template' === $target ) {
				$prep = spiral_mcp_template_prepare( array(
					'name'   => $name,
					'type'   => $template_type,
					'status' => (string) ( $input['status'] ?? 'disabled' ),
					'layout' => $tree,
				) );
				if ( is_wp_error( $prep ) ) {
					return $prep;
				}
				list( $tpl, $tpl_warnings ) = $prep;
				$id                         = spiral_mcp_yootheme_id( 'templates' );
				$snapshot                   = spiral_mcp_template_put( $id, $tpl, 'import-from-online-library' );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				$out['template']    = spiral_mcp_template_out( $id, $tpl );
				$out['snapshot_id'] = $snapshot;
				$warnings           = array_merge( $warnings, $tpl_warnings );
			} elseif ( 'library' === $target ) {
				$storage = spiral_mcp_yootheme_storage();
				if ( is_wp_error( $storage ) ) {
					return $storage;
				}
				$snapshot = spiral_mcp_snapshot_capture( 'yootheme_storage', 'library', 'import-from-online-library' );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				$tree['name']     = $name;
				$tree['modified'] = gmdate( 'Y-m-d\TH:i:s.v\Z' );
				$id               = spiral_mcp_yootheme_id( 'library' );
				$storage->set( 'library.' . $id, $tree );
				$out['library']     = spiral_mcp_library_out( $id, $tree );
				$out['snapshot_id'] = $snapshot;
			} elseif ( 'insert' === $target ) {
				$dest = array_filter( array(
					'id'   => (int) ( $input['post_id'] ?? 0 ),
					'area' => (string) ( $input['area'] ?? '' ),
					'path' => (string) $input['path'],
				) );
				$res  = spiral_mcp_library_insert( $dest, $tree, 'import-from-online-library' );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$warnings      = array_merge( $warnings, (array) ( $res['warnings'] ?? array() ) );
				unset( $res['warnings'] );
				$out['result'] = $res;
			} else {
				return new WP_Error( 'spiral_mcp_bad_target', '"target" debe ser template, library o insert.' );
			}
			if ( 0 === $out['dynamic_sources'] && 'template' === $target ) {
				$warnings[] = 'La plantilla importada no tiene fuentes dinámicas: muestra el contenido de muestra de la web de ejemplo en todas las páginas a las que se aplique. Antes de activarla, conecta título, contenido, imagen… con fuentes (p. ej. posts.singlePost) usando spiral-mcp/patch-layout con "area": "template:<id>".';
			}
			$out['warnings'] = $warnings;
			return $out;
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
