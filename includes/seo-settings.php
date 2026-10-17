<?php
/**
 * Ajustes generales de Yoast SEO: plantillas de título y descripción, tipos de contenido,
 * taxonomías, archivos, representación del sitio (Schema), redes sociales, migas de pan,
 * RSS, verificación de buscadores, funciones y limpieza del rastreo.
 *
 * Solo se registra si Yoast SEO está activo. Yoast guarda sus ajustes en tres opciones
 * (wpseo, wpseo_titles, wpseo_social); se escriben con update_option() para que pasen por
 * su validación y sus watchers (reglas de reescritura, indexables), y solo las claves de la
 * lista blanca de spiral_mcp_yoast_sections(): el estado interno (tokens, indexación,
 * versión, integraciones) no se toca. Grupo de permisos "settings".
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Regex que reconoce exactamente uno de estos nombres (tipos de contenido, taxonomías). */
function spiral_mcp_yoast_names_regex( array $names ) {
	return $names ? '(?:' . implode( '|', array_map( function ( $n ) {
		return preg_quote( (string) $n, '/' );
	}, $names ) ) . ')' : '(?!)';
}

/**
 * Secciones editables: id => [ label, description, option, patterns ]. Una clave de la opción
 * es editable si existe en ella y encaja con algún patrón. Filtro spiral_mcp_yoast_sections.
 */
function spiral_mcp_yoast_sections() {
	$pt  = spiral_mcp_yoast_names_regex( array_keys( get_post_types( array( 'public' => true ) ) ) );
	$tax = spiral_mcp_yoast_names_regex( array_keys( get_taxonomies( array( 'public' => true ) ) ) );

	return (array) apply_filters( 'spiral_mcp_yoast_sections', array(
		'general'             => array(
			'label'       => 'General y portada',
			'description' => 'Separador de título, título y meta description de la portada y su Open Graph.',
			'option'      => 'wpseo_titles',
			'patterns'    => array( '/^separator$/', '/^(title|metadesc)-home-wpseo$/', '/^open_graph_frontpage_(title|desc|image|image_id)$/' ),
		),
		'site_representation' => array(
			'label'       => 'Representación del sitio (Schema)',
			'description' => 'Si el sitio es una organización o una persona, nombre, nombre alternativo, logo y datos de la organización (org-*).',
			'option'      => 'wpseo_titles',
			'patterns'    => array( '/^company_or_person$/', '/^(company_name|company_alternate_name|company_logo|company_logo_id|person_logo|person_logo_id|website_name|alternate_website_name)$/', '/^org-/' ),
		),
		'content_types'       => array(
			'label'       => 'Tipos de contenido',
			'description' => 'Por tipo de contenido público: plantilla de título y description, noindex, metabox de Yoast, tipo de Schema, Open Graph por defecto y, si tiene archivo, lo mismo para su archivo (-ptarchive-). Las plantillas social-* solo las usa Yoast SEO Premium.',
			'option'      => 'wpseo_titles',
			'patterns'    => array(
				'/^(title|metadesc|noindex|bctitle|social-title|social-description|social-image-url|social-image-id)-(ptarchive-)?' . $pt . '$/',
				'/^display-metabox-pt-' . $pt . '$/',
				'/^post_types-' . $pt . '-maintax$/',
				'/^schema-(page|article)-type-' . $pt . '$/',
			),
		),
		'taxonomies'          => array(
			'label'       => 'Taxonomías',
			'description' => 'Por taxonomía pública: plantilla de título y description, noindex, metabox, Open Graph y padre en las migas; stripcategorybase quita /category/ de las URLs (Yoast redirige las antiguas con 301). Las plantillas social-* solo las usa Yoast SEO Premium.',
			'option'      => 'wpseo_titles',
			'patterns'    => array(
				'/^(title|metadesc|noindex|display-metabox|social-title|social-description|social-image-url|social-image-id)-tax-' . $tax . '$/',
				'/^taxonomy-' . $tax . '-ptparent$/',
				'/^stripcategorybase$/',
			),
		),
		'archives'            => array(
			'label'       => 'Archivos y páginas especiales',
			'description' => 'Archivos de autor y de fecha (título, description, noindex, Open Graph, desactivarlos), páginas de búsqueda y 404, y desactivar las páginas de adjuntos y formatos.',
			'option'      => 'wpseo_titles',
			'patterns'    => array(
				'/^(title|metadesc|noindex|social-title|social-description|social-image-url|social-image-id)-(author|archive)-wpseo$/',
				'/^noindex-author-noposts-wpseo$/',
				'/^title-(search|404)-wpseo$/',
				'/^disable-(author|date|post_format|attachment)$/',
			),
		),
		'breadcrumbs'         => array(
			'label'       => 'Migas de pan',
			'description' => 'Activarlas y sus textos (inicio, separador, prefijos, 404).',
			'option'      => 'wpseo_titles',
			'patterns'    => array( '/^breadcrumbs-/' ),
		),
		'rss'                 => array(
			'label'       => 'RSS',
			'description' => 'Texto antes y después de cada entrada en el feed (admite %%POSTLINK%%, %%BLOGLINK%%…).',
			'option'      => 'wpseo_titles',
			'patterns'    => array( '/^rss(before|after)$/' ),
		),
		'social'              => array(
			'label'       => 'Redes sociales',
			'description' => 'Perfiles (Facebook, X, Instagram, LinkedIn…, other_social_urls), Open Graph y tarjetas de X activados, tipo de tarjeta, imagen por defecto al compartir y verificación de Pinterest.',
			'option'      => 'wpseo_social',
			'patterns'    => array( '/^(facebook_site|instagram_url|linkedin_url|myspace_url|pinterest_url|youtube_url|wikipedia_url|mastodon_url|twitter_site|other_social_urls|opengraph|twitter|twitter_card_type|og_default_image|og_default_image_id|pinterestverify)$/' ),
		),
		'verification'        => array(
			'label'       => 'Verificación de buscadores',
			'description' => 'Códigos de verificación (solo el código, no la etiqueta <meta>) de Google, Bing, Baidu, Yandex y Ahrefs.',
			'option'      => 'wpseo',
			'patterns'    => array( '/^(google|ms|baidu|yandex|ahrefs)verify$/' ),
		),
		'features'            => array(
			'label'       => 'Funciones de Yoast',
			'description' => 'Mapas del sitio XML, análisis SEO y de legibilidad, contenido esencial, contador de enlaces, IndexNow, menú de la barra de admin, API REST headless, llms.txt…',
			'option'      => 'wpseo',
			'patterns'    => array( '/^enable_(xml_sitemap|admin_bar_menu|cornerstone_content|text_link_counter|index_now|metabox_insights|link_suggestions|headless_rest_endpoints|enhanced_slack_sharing|llms_txt|ai_generator)$/', '/^(content|keyword|inclusive_language)_analysis_active$/' ),
		),
		'crawl'               => array(
			'label'       => 'Optimización del rastreo',
			'description' => 'Quitar del <head> y de las cabeceras lo que no hace falta (feeds, shortlinks, enlaces REST, oEmbed, emojis…), limpiar parámetros de campaña y de búsqueda, y bloquear bots (deny_*) en robots.txt.',
			'option'      => 'wpseo',
			'patterns'    => array( '/^remove_/', '/^clean_/', '/^search_cleanup/', '/^search_character_limit$/', '/^deny_/', '/^redirect_search_pretty_urls$/' ),
		),
	) );
}

/** Valor actual de una opción de Yoast (con sus valores por defecto). */
function spiral_mcp_yoast_option( $option ) {
	WPSEO_Options::clear_cache();
	return (array) WPSEO_Options::get_option( $option );
}

/** Claves editables de una sección => valor actual. */
function spiral_mcp_yoast_section_values( $section ) {
	$sections = spiral_mcp_yoast_sections();
	if ( ! isset( $sections[ $section ] ) ) {
		return array();
	}
	$out = array();
	foreach ( spiral_mcp_yoast_option( $sections[ $section ]['option'] ) as $key => $value ) {
		foreach ( $sections[ $section ]['patterns'] as $pattern ) {
			if ( preg_match( $pattern, (string) $key ) ) {
				$out[ $key ] = $value;
				break;
			}
		}
	}
	return $out;
}

/** Mapa clave editable => [ sección, opción ]. */
function spiral_mcp_yoast_key_map() {
	$map = array();
	foreach ( spiral_mcp_yoast_sections() as $id => $section ) {
		foreach ( array_keys( spiral_mcp_yoast_section_values( $id ) ) as $key ) {
			$map[ $key ] = array( $id, $section['option'] );
		}
	}
	return $map;
}

/** Clave de URL emparejada con una clave de ID de imagen (social-image-id-post → social-image-url-post), o null. */
function spiral_mcp_yoast_image_url_key( $key ) {
	if ( preg_match( '/^social-image-id-(.+)$/', $key, $m ) ) {
		return 'social-image-url-' . $m[1];
	}
	if ( preg_match( '/^(company_logo|person_logo|open_graph_frontpage_image|og_default_image)_id$/', $key, $m ) ) {
		return $m[1];
	}
	return null;
}

/** Convierte un valor de entrada al tipo de la clave en Yoast, o WP_Error. */
function spiral_mcp_yoast_coerce( $key, $current, $value ) {
	$bad = function ( $type ) use ( $key ) {
		return new WP_Error( 'spiral_mcp_bad_value', sprintf( '"%s" debe ser %s.', $key, $type ) );
	};
	if ( null !== spiral_mcp_yoast_image_url_key( $key ) ) {
		if ( '' === $value || null === $value || 0 === $value || '0' === $value ) {
			return 0;
		}
		if ( ! is_numeric( $value ) || ! wp_attachment_is_image( (int) $value ) ) {
			return new WP_Error( 'spiral_mcp_not_image', sprintf( '"%s" debe ser el ID de una imagen de la biblioteca (ver spiral-mcp/list-media), o 0 para quitarla.', $key ) );
		}
		return (int) $value;
	}
	if ( 'other_social_urls' === $key ) {
		if ( ! is_array( $value ) ) {
			return $bad( 'una lista de URLs' );
		}
		return array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $value ) ) ) );
	}
	// El tipo de Schema principal (maintax) y el padre en las migas (ptparent) son 0 o un nombre.
	if ( preg_match( '/(-maintax|-ptparent)$/', $key ) ) {
		return is_scalar( $value ) ? ( is_numeric( $value ) ? (int) $value : sanitize_key( $value ) ) : $bad( '0 o un nombre' );
	}
	if ( is_bool( $current ) ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
			return in_array( $value, array( 1, '1', 'true' ), true );
		}
		return $bad( 'true o false' );
	}
	if ( is_int( $current ) ) {
		return is_numeric( $value ) ? (int) $value : $bad( 'un número' );
	}
	if ( is_array( $current ) ) {
		return $bad( 'una lista' );
	}
	return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $bad( 'un texto' );
}

/** ¿Mismo valor? Yoast guarda algunos IDs como texto ("12") y otros como número. */
function spiral_mcp_yoast_same( $a, $b ) {
	if ( is_bool( $a ) || is_bool( $b ) || ! is_scalar( $a ) || ! is_scalar( $b ) ) {
		return $a === $b;
	}
	return (string) $a === (string) $b;
}

/** Ayudas por sección: valores posibles de los desplegables y variables de las plantillas. */
function spiral_mcp_yoast_help( $section ) {
	$vars = 'Variables: %%title%%, %%sitename%%, %%sitedesc%%, %%sep%%, %%page%%, %%excerpt%%, %%term_title%%, %%name%% (autor), %%date%%, %%searchphrase%%, %%category%%, %%primary_category%%, %%currentyear%%. Ejemplo: "%%title%% %%page%% %%sep%% %%sitename%%".';
	switch ( $section ) {
		case 'general':
			$out = array( 'separator' => array(), 'variables' => $vars );
			foreach ( WPSEO_Option_Titles::get_instance()->get_separator_options() as $id => $char ) {
				$out['separator'][ $id ] = html_entity_decode( $char, ENT_QUOTES, 'UTF-8' );
			}
			return $out;
		case 'content_types':
			$out = array( 'variables' => $vars, 'premium_only' => 'social-title-*, social-description-* y social-image-* no tienen efecto sin Yoast SEO Premium; la imagen por defecto al compartir es og_default_image_id (sección social).', 'post_types' => array() );
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
				$out['post_types'][ $name ] = array( 'label' => $obj->label, 'has_archive' => (bool) $obj->has_archive );
			}
			if ( class_exists( '\Yoast\WP\SEO\Config\Schema_Types' ) ) {
				$types                     = new \Yoast\WP\SEO\Config\Schema_Types();
				$out['schema-page-type']    = array_column( $types->get_page_type_options(), 'value' );
				$out['schema-article-type'] = array_column( $types->get_article_type_options(), 'value' );
			}
			return $out;
		case 'taxonomies':
			$out = array( 'variables' => $vars, 'premium_only' => 'social-title-tax-*, social-description-tax-* y social-image-*-tax-* no tienen efecto sin Yoast SEO Premium.', 'taxonomies' => array() );
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $obj ) {
				$out['taxonomies'][ $name ] = $obj->label;
			}
			return $out;
		case 'archives':
			return array( 'variables' => $vars );
		case 'site_representation':
			return array( 'company_or_person' => array( 'company', 'person' ), 'note' => 'Para "person", el usuario se elige en wp-admin (Yoast SEO > Ajustes > Representación del sitio); aquí solo su logo.' );
		case 'social':
			return array( 'twitter_card_type' => array( 'summary', 'summary_large_image' ) );
	}
	return array();
}

/* -------------------------------------------------------------------------
 * Snapshots: ref = opciones de Yoast separadas por comas; solo claves editables
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		return $kinds;
	}
	$kinds['seo_settings'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$map = spiral_mcp_yoast_key_map();
			$out = array();
			foreach ( array_filter( explode( ',', (string) $ref ) ) as $option ) {
				$out[ $option ] = array_intersect_key( spiral_mcp_yoast_option( $option ), array_filter( $map, function ( $m ) use ( $option ) {
					return $m[1] === $option;
				} ) );
			}
			return $out;
		},
		'restore' => function ( $ref, $data ) {
			$map = spiral_mcp_yoast_key_map();
			foreach ( (array) $data as $option => $values ) {
				$values = array_filter( (array) $values, function ( $key ) use ( $map, $option ) {
					return isset( $map[ $key ] ) && $map[ $key ][1] === $option;
				}, ARRAY_FILTER_USE_KEY );
				if ( $values ) {
					update_option( $option, array_merge( spiral_mcp_yoast_option( $option ), $values ) );
				}
			}
			WPSEO_Options::clear_cache();
			return true;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {
	if ( ! defined( 'WPSEO_VERSION' ) || ! class_exists( 'WPSEO_Options' ) ) {
		return;
	}

	// Como los ajustes del sitio: el rol del agente no tiene manage_options.
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-seo-settings', array(
		'label'               => 'Leer los ajustes generales de Yoast SEO',
		'description'         => 'Ajustes generales de Yoast SEO (lo que en wp-admin está en Yoast SEO > Ajustes). Sin "section" devuelve la lista de secciones con su descripción y cuántas claves tiene. Con "section" devuelve sus claves con el valor actual ("settings") y "help": valores posibles de los desplegables (separator, schema-page-type…) y las variables de las plantillas de título (%%title%%, %%sitename%%…). El SEO de una página concreta no está aquí: es spiral-mcp/get-seo.',
		'category'            => 'spiral-mcp-settings',
		'group'               => 'settings',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'section' => array( 'type' => 'string', 'enum' => array_keys( spiral_mcp_yoast_sections() ) ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$section  = (string) ( ( (array) $input )['section'] ?? '' );
			$sections = spiral_mcp_yoast_sections();
			if ( '' === $section ) {
				$list = array();
				foreach ( $sections as $id => $s ) {
					$list[] = array( 'section' => $id, 'label' => $s['label'], 'description' => $s['description'], 'keys' => count( spiral_mcp_yoast_section_values( $id ) ) );
				}
				return array( 'provider' => 'yoast', 'version' => WPSEO_VERSION, 'sections' => $list );
			}
			if ( ! isset( $sections[ $section ] ) ) {
				return new WP_Error( 'spiral_mcp_bad_section', 'Sección no válida. Disponibles: ' . implode( ', ', array_keys( $sections ) ) . '.' );
			}
			return array(
				'section'  => $section,
				'label'    => $sections[ $section ]['label'],
				'settings' => spiral_mcp_yoast_section_values( $section ),
				'help'     => spiral_mcp_yoast_help( $section ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-seo-settings', array(
		'label'               => 'Cambiar los ajustes generales de Yoast SEO',
		'description'         => 'Cambia ajustes generales de Yoast SEO. Primero lee la sección con spiral-mcp/get-seo-settings y envía en "settings" solo las claves que cambian, con el mismo tipo que tienen allí (p. ej. {"title-page": "%%title%% %%sep%% %%sitename%%", "noindex-tax-post_tag": true}); pueden ser de varias secciones. Las imágenes se indican por su ID de la biblioteca en la clave *_id (social-image-id-post, og_default_image_id, company_logo_id…; 0 = quitarla) y la URL se rellena sola. Yoast valida cada valor: los que rechaza quedan en "not_applied" con el valor que conserva. Afecta a todo el sitio (títulos, Schema, robots.txt, mapas del sitio): confírmalo con el usuario si no lo pidió así, y revisa después con spiral-mcp/inspect-page. Antes guarda un snapshot ("snapshot_id"; restore-snapshot lo deshace) y después purga la caché.',
		'category'            => 'spiral-mcp-settings',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'seo_settings', 'id' => function ( $in ) {
			return substr( implode( ',', array_keys( (array) ( ( (array) $in )['settings'] ?? array() ) ) ), 0, 180 );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'settings' ),
			'properties' => array(
				'settings' => array(
					'type'                 => 'object',
					'description'          => 'Clave de Yoast => valor nuevo (claves de spiral-mcp/get-seo-settings).',
					'additionalProperties' => true,
				),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$settings = (array) ( ( (array) $input )['settings'] ?? array() );
			if ( ! $settings ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "settings" al menos una clave (ver spiral-mcp/get-seo-settings).' );
			}
			$map      = spiral_mcp_yoast_key_map();
			$unknown  = array_diff( array_keys( $settings ), array_keys( $map ) );
			if ( $unknown ) {
				return new WP_Error( 'spiral_mcp_bad_key', sprintf( 'Claves no editables o inexistentes: %s. Consulta las de cada sección con spiral-mcp/get-seo-settings.', implode( ', ', $unknown ) ) );
			}

			// Valores nuevos agrupados por opción, con el tipo de Yoast.
			$by_option = array();
			$warnings  = array();
			foreach ( $settings as $key => $value ) {
				$option  = $map[ $key ][1];
				$current = spiral_mcp_yoast_option( $option );
				$value   = spiral_mcp_yoast_coerce( $key, $current[ $key ] ?? null, $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$by_option[ $option ][ $key ] = $value;
				$url_key                      = spiral_mcp_yoast_image_url_key( $key );
				if ( $url_key && array_key_exists( $url_key, $current ) ) {
					$by_option[ $option ][ $url_key ] = $value ? (string) wp_get_attachment_url( $value ) : '';
				}
			}
			foreach ( array_keys( $settings ) as $key ) {
				if ( preg_match( '/^(social-image-url-.+|company_logo|person_logo|open_graph_frontpage_image|og_default_image)$/', $key ) ) {
					$warnings[] = sprintf( '"%s" se ha guardado como URL; mejor usa la clave *_id con el ID de la imagen para que Yoast tenga sus medidas.', $key );
				}
			}

			$snapshot = spiral_mcp_snapshot_capture( 'seo_settings', implode( ',', array_keys( $by_option ) ), 'update-seo-settings' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$changed     = array();
			$not_applied = array();
			foreach ( $by_option as $option => $values ) {
				$before = spiral_mcp_yoast_option( $option );
				// update_option() pasa por la validación de Yoast (sanitize_option_<opción>) y sus watchers.
				update_option( $option, array_merge( $before, $values ) );
				$after = spiral_mcp_yoast_option( $option );
				foreach ( $values as $key => $wanted ) {
					$now = $after[ $key ] ?? null;
					if ( ! spiral_mcp_yoast_same( $now, $wanted ) ) {
						$not_applied[ $key ] = array( 'sent' => $wanted, 'kept' => $now );
					}
					if ( ! spiral_mcp_yoast_same( $before[ $key ] ?? null, $now ) ) {
						$changed[ $key ] = array( 'before' => $before[ $key ] ?? null, 'after' => $now );
					}
				}
			}
			if ( $not_applied ) {
				$warnings[] = 'Yoast no aceptó algunos valores (ver "not_applied"): revisa el formato o los valores posibles en "help" de spiral-mcp/get-seo-settings.';
			}
			if ( $changed && spiral_mcp_setting( 'auto_purge' ) && function_exists( 'spiral_mcp_cache_purge' ) ) {
				spiral_mcp_cache_purge( 'all' );
			}
			return array(
				'changed'     => $changed,
				'not_applied' => $not_applied,
				'snapshot_id' => $snapshot,
				'warnings'    => $warnings,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
