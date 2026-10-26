<?php
/**
 * Idiomas con Polylang (Fase 12): idiomas y ajustes, traducciones de contenidos y
 * términos, textos sueltos (nombre del sitio, widgets…), menús por idioma y selector
 * de idioma. Solo se registra si Polylang está activo.
 *
 * Además amplía abilities de otros módulos con el filtro spiral_mcp_ability_args:
 * "language" en list-content, get-content, create-content, duplicate-content,
 * list-terms y create-term, y las ubicaciones por idioma en list-menus, create-menu
 * y update-menu (con Polylang, la web ignora el theme mod nav_menu_locations: lee
 * las ubicaciones de la opción nav_menus de Polylang, por idioma).
 *
 * Polylang guarda el idioma y los grupos de traducción como taxonomías (language,
 * term_language, post_translations, term_translations), sus ajustes en la opción
 * "polylang" (se guarda en shutdown o con $options->save()) y las traducciones de
 * textos en el term meta _pll_strings_translations de cada idioma.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** ¿Está Polylang activo y listo? */
function spiral_mcp_pll_active() {
	return defined( 'POLYLANG_VERSION' ) && function_exists( 'PLL' ) && function_exists( 'pll_languages_list' )
		&& isset( $GLOBALS['polylang'] ) && isset( $GLOBALS['polylang']->model );
}

/** ¿Hay al menos un idioma creado? Sin idiomas, Polylang no filtra ni asigna nada. */
function spiral_mcp_pll_has_languages() {
	return spiral_mcp_pll_active() && PLL()->model->has_languages();
}

/** Idioma por slug, locale o ID de término; o WP_Error con los disponibles. */
function spiral_mcp_pll_language( $ref ) {
	$ref  = trim( (string) $ref );
	$lang = '' === $ref ? false : PLL()->model->get_language( $ref );
	if ( ! $lang ) {
		$list = pll_languages_list();
		return new WP_Error( 'spiral_mcp_pll_bad_language', sprintf( 'El idioma "%s" no existe. Idiomas del sitio: %s (ver spiral-mcp/list-languages).', $ref, $list ? implode( ', ', $list ) : 'ninguno; créalo con spiral-mcp/create-language' ), array( 'status' => 400 ) );
	}
	return $lang;
}

/** Idioma por defecto (objeto) o null. */
function spiral_mcp_pll_default_language() {
	$lang = PLL()->model->get_default_language();
	return $lang ? $lang : null;
}

/** Datos de un idioma. */
function spiral_mcp_pll_language_out( $lang ) {
	$term = get_term( (int) $lang->term_id, 'language' );
	$home = wp_make_link_relative( (string) $lang->get_home_url() );
	return array(
		'slug'    => $lang->slug,
		'locale'  => $lang->locale,
		'name'    => $lang->name,
		'flag'    => (string) $lang->flag_code,
		'default' => (bool) $lang->is_default,
		'rtl'     => (bool) $lang->is_rtl,
		'order'   => (int) $lang->term_group,
		'home'    => '' === $home ? '/' : $home,
		'published' => $term && ! is_wp_error( $term ) ? (int) $term->count : 0, // Solo cuenta lo publicado.
		'term_id' => (int) $lang->term_id,
		// Sin el paquete de idioma de WordPress, los textos del tema y de los plugins salen en inglés.
		'wordpress_translation_installed' => 'en_US' === $lang->locale || in_array( $lang->locale, get_available_languages(), true ),
	);
}

/** "post" o "term" según la entrada; WP_Error si no. */
function spiral_mcp_pll_type( $type ) {
	$type = (string) $type;
	if ( ! in_array( $type, array( 'post', 'term' ), true ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', '"type" debe ser "post" (páginas, entradas y otros contenidos) o "term" (categorías, etiquetas…).' );
	}
	return $type;
}

/**
 * Objeto traducible por tipo e ID: WP_Post o WP_Term de un tipo de contenido o taxonomía
 * que Polylang traduce. WP_Error si no existe o no se traduce.
 */
function spiral_mcp_pll_object( $type, $id ) {
	if ( 'post' === $type ) {
		$obj = get_post( (int) $id );
		if ( ! $obj || 'revision' === $obj->post_type ) {
			return new WP_Error( 'spiral_mcp_not_found', sprintf( 'No existe el contenido %d (búscalo con spiral-mcp/list-content).', (int) $id ), array( 'status' => 404 ) );
		}
		if ( ! pll_is_translated_post_type( $obj->post_type ) ) {
			return new WP_Error( 'spiral_mcp_pll_not_translated', sprintf( 'Polylang no traduce el tipo "%s". Actívalo en "post_types" con spiral-mcp/update-language-settings.', $obj->post_type ) );
		}
		return $obj;
	}
	$obj = get_term( (int) $id );
	if ( ! $obj || is_wp_error( $obj ) ) {
		return new WP_Error( 'spiral_mcp_term_not_found', sprintf( 'No existe el término %d (ver spiral-mcp/list-terms).', (int) $id ), array( 'status' => 404 ) );
	}
	if ( ! pll_is_translated_taxonomy( $obj->taxonomy ) ) {
		return new WP_Error( 'spiral_mcp_pll_not_translated', sprintf( 'Polylang no traduce la taxonomía "%s". Actívala en "taxonomies" con spiral-mcp/update-language-settings.', $obj->taxonomy ) );
	}
	return $obj;
}

/** ¿El usuario actual puede editar este objeto? */
function spiral_mcp_pll_can_edit( $type, $id ) {
	return 'post' === $type ? current_user_can( 'edit_post', (int) $id ) : current_user_can( 'edit_term', (int) $id );
}

/** Idioma (slug) de un objeto o null. */
function spiral_mcp_pll_object_language( $type, $id ) {
	$slug = 'post' === $type ? pll_get_post_language( (int) $id ) : pll_get_term_language( (int) $id );
	return $slug ? (string) $slug : null;
}

/** Grupo de traducciones de un objeto: [ slug => ID ]. */
function spiral_mcp_pll_translations( $type, $id ) {
	return array_map( 'intval', (array) ( 'post' === $type ? pll_get_post_translations( (int) $id ) : pll_get_term_translations( (int) $id ) ) );
}

/** Guarda un grupo de traducciones [ slug => ID ]. Un grupo de un solo objeto lo desvincula. */
function spiral_mcp_pll_save_group( $type, array $group ) {
	$group = array_filter( array_map( 'intval', $group ) );
	if ( count( $group ) === 1 ) {
		PLL()->model->$type->delete_translation( (int) reset( $group ) );
		return;
	}
	if ( $group ) {
		PLL()->model->$type->save_translations( (int) reset( $group ), $group );
	}
}

/** Resumen de un objeto traducido para las respuestas. */
function spiral_mcp_pll_object_brief( $type, $id ) {
	if ( 'post' === $type ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return null;
		}
		return array(
			'id'     => (int) $post->ID,
			'title'  => get_the_title( $post ),
			'status' => $post->post_status,
			'path'   => wp_make_link_relative( (string) get_permalink( $post ) ),
		);
	}
	$term = get_term( (int) $id );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	$link = get_term_link( $term );
	return array(
		'id'   => (int) $term->term_id,
		'name' => $term->name,
		'slug' => $term->slug,
		'path' => is_wp_error( $link ) ? null : wp_make_link_relative( $link ),
	);
}

/** Idioma y traducciones de un objeto, con un resumen de cada una y los idiomas que faltan. */
function spiral_mcp_pll_translations_out( $type, $id ) {
	$group   = spiral_mcp_pll_translations( $type, $id );
	$items   = array();
	$missing = array();
	foreach ( pll_languages_list() as $slug ) {
		if ( isset( $group[ $slug ] ) ) {
			$items[ $slug ] = spiral_mcp_pll_object_brief( $type, $group[ $slug ] );
		} else {
			$missing[] = $slug;
		}
	}
	return array(
		'type'         => $type,
		'id'           => (int) $id,
		'language'     => spiral_mcp_pll_object_language( $type, $id ),
		'translations' => (object) $items,
		'missing'      => $missing,
	);
}

/** Ajustes de Polylang que el agente puede cambiar, con su ayuda. */
function spiral_mcp_pll_settings_help() {
	return array(
		'default_lang'  => 'Idioma por defecto (slug). Cambiarlo cambia las URLs: exige "confirm".',
		'force_lang'    => 'Cómo lleva la URL el idioma: 0 = no lo lleva (se deduce del contenido), 1 = directorio (/en/), 2 = subdominio (en.ejemplo.com), 3 = un dominio por idioma (rellena "domains"). Exige "confirm".',
		'hide_default'  => 'true = las URLs del idioma por defecto no llevan su código (/contacto/ en vez de /es/contacto/). Exige "confirm".',
		'rewrite'       => 'true = /en/; false = /language/en/. Exige "confirm".',
		'redirect_lang' => 'true = la portada de cada idioma es /en/ en vez de /en/<slug-de-la-portada>/ (sin él, /en/ redirige allí). Exige "confirm".',
		'browser'       => 'true = en la primera visita a la portada, elegir el idioma según el navegador.',
		'media_support' => 'true = los medios también tienen idioma (texto alternativo y título traducibles; create-translation traduce la imagen destacada).',
		'post_types'    => 'Tipos de contenido personalizados que se traducen (páginas y entradas siempre se traducen). Lista de nombres.',
		'taxonomies'    => 'Taxonomías personalizadas que se traducen (categorías y etiquetas siempre). Lista de nombres.',
		'sync'          => 'Qué copia Polylang entre traducciones al guardar: taxonomies, post_meta, comment_status, ping_status, sticky_posts, post_date, post_format, post_parent, _wp_page_template, menu_order, _thumbnail_id.',
		'domains'       => 'Solo con force_lang = 3: un dominio por idioma, p. ej. {"es": "https://ejemplo.es", "en": "https://example.com"}. Exige "confirm".',
	);
}

/** Ajustes que cambian las URLs del sitio: exigen "confirm". */
function spiral_mcp_pll_url_settings() {
	return array( 'default_lang', 'force_lang', 'hide_default', 'rewrite', 'redirect_lang', 'domains' );
}

/** Valores actuales de los ajustes editables. */
function spiral_mcp_pll_settings_values() {
	$options = PLL()->model->options;
	$out     = array();
	foreach ( array_keys( spiral_mcp_pll_settings_help() ) as $key ) {
		$out[ $key ] = $options->get( $key );
	}
	return $out;
}

/**
 * ¿Un valor nuevo de un ajuste es el que ya hay? 1 y true cuentan igual, y una lista vacía igual que
 * una con todo vacío (Polylang guarda domains [] como {"es": "", "en": ""}).
 */
function spiral_mcp_pll_same_value( $new, $current ) {
	if ( is_bool( $current ) && ( is_bool( $new ) || in_array( $new, array( 0, 1 ), true ) ) ) {
		return (bool) $new === $current;
	}
	if ( is_int( $current ) && is_numeric( $new ) ) {
		return (string) (int) $new === (string) $new && (int) $new === $current;
	}
	if ( is_array( $current ) && is_array( $new ) ) {
		return $new === $current || ( ! array_filter( $new ) && ! array_filter( $current ) );
	}
	return $new === $current;
}

/**
 * Escribe ajustes de Polylang como su API REST: default_lang con update_default() (también
 * recoloca los menús), el resto con $options->set(), que valida contra su esquema. Devuelve
 * [ cambiados, no aplicados [ clave => mensaje ] ].
 */
function spiral_mcp_pll_settings_write( array $values ) {
	$options     = PLL()->model->options;
	$changed     = array();
	$not_applied = array();
	$flush       = false;
	foreach ( $values as $key => $value ) {
		$before = $options->get( $key );
		if ( spiral_mcp_pll_same_value( $value, $before ) ) {
			continue;
		}
		$result = 'default_lang' === $key ? PLL()->model->languages->update_default( (string) $value ) : $options->set( $key, $value );
		if ( is_wp_error( $result ) && $result->has_errors() ) {
			$not_applied[ $key ] = $result->get_error_message();
			continue;
		}
		if ( $options->get( $key ) !== $before ) {
			$changed[] = $key;
			$flush     = $flush || in_array( $key, array( 'rewrite', 'force_lang', 'hide_default', 'default_lang', 'redirect_lang' ), true );
		}
	}
	$options->save();
	if ( $changed ) {
		// La caché de idiomas guarda las URLs de cada idioma (portada incluida).
		PLL()->model->clean_languages_cache();
	}
	if ( $flush ) {
		flush_rewrite_rules();
	}
	return array( $changed, $not_applied );
}

/** Tema cuyas ubicaciones de menú guarda Polylang (el activo). */
function spiral_mcp_pll_theme() {
	return get_option( 'stylesheet' );
}

/** Ubicaciones por idioma que guarda Polylang: [ ubicación => [ slug => menú ] ]. */
function spiral_mcp_pll_menu_map() {
	$nav = (array) PLL()->model->options->get( 'nav_menus' );
	$out = array();
	foreach ( (array) ( $nav[ spiral_mcp_pll_theme() ] ?? array() ) as $loc => $langs ) {
		$out[ $loc ] = array_map( 'intval', (array) $langs );
	}
	return $out;
}

/** Ubicaciones de un menú por idioma: [ slug => [ ubicaciones ] ]. */
function spiral_mcp_pll_menu_locations_of( $menu_id ) {
	$out = array();
	foreach ( spiral_mcp_pll_menu_map() as $loc => $langs ) {
		foreach ( $langs as $slug => $id ) {
			if ( (int) $id === (int) $menu_id ) {
				$out[ $slug ][] = $loc;
			}
		}
	}
	return (object) $out;
}

/**
 * Asigna menús a ubicaciones en un idioma: [ ubicación => ID de menú (0 = ninguno) ].
 * Con $exclusive_menu, además quita ese menú de las demás ubicaciones del idioma (como
 * "locations" de update-menu, que da la lista completa). En el idioma por defecto también
 * escribe el theme mod nav_menu_locations, como hace Polylang en wp-admin.
 */
function spiral_mcp_pll_write_menu_locations( $lang, array $map, $exclusive_menu = 0 ) {
	$registered = get_registered_nav_menus();
	foreach ( array_keys( $map ) as $loc ) {
		if ( ! isset( $registered[ $loc ] ) ) {
			return new WP_Error( 'spiral_mcp_bad_location', sprintf( 'La ubicación "%s" no existe en el tema. Disponibles: %s', $loc, implode( ', ', array_keys( $registered ) ) ) );
		}
	}
	$theme = spiral_mcp_pll_theme();
	$nav   = (array) PLL()->model->options->get( 'nav_menus' );
	$mods  = (array) get_theme_mod( 'nav_menu_locations', array() );
	if ( $exclusive_menu ) {
		foreach ( (array) ( $nav[ $theme ] ?? array() ) as $loc => $langs ) {
			if ( (int) ( $langs[ $lang->slug ] ?? 0 ) === (int) $exclusive_menu && ! isset( $map[ $loc ] ) ) {
				$nav[ $theme ][ $loc ][ $lang->slug ] = 0;
				if ( $lang->is_default ) {
					unset( $mods[ $loc ] );
				}
			}
		}
	}
	foreach ( $map as $loc => $menu_id ) {
		$nav[ $theme ][ $loc ][ $lang->slug ] = (int) $menu_id;
		if ( $lang->is_default ) {
			if ( $menu_id ) {
				$mods[ $loc ] = (int) $menu_id;
			} else {
				unset( $mods[ $loc ] );
			}
		}
	}
	$result = PLL()->model->options->set( 'nav_menus', $nav );
	if ( is_wp_error( $result ) && $result->has_errors() ) {
		return $result;
	}
	PLL()->model->options->save();
	if ( $lang->is_default ) {
		set_theme_mod( 'nav_menu_locations', $mods );
	}
	return true;
}

/** Textos registrados para traducir en esta petición: [ original => [ name, string, context, multiline ] ]. */
function spiral_mcp_pll_strings() {
	if ( ! class_exists( 'PLL_Admin_Strings' ) ) {
		return array();
	}
	$out = array();
	foreach ( PLL_Admin_Strings::get_strings() as $s ) {
		$out[ (string) $s['string'] ] = $s;
	}
	return $out;
}

/** Traducciones de textos guardadas para un idioma (objeto PLL_MO). */
function spiral_mcp_pll_mo( $lang ) {
	$mo = new PLL_MO();
	$mo->import_from_db( $lang );
	return $mo;
}

/**
 * Copia el contenido de un post a otro idioma como borrador: contenido (con su layout de
 * YOOtheme), extracto, metas, plantilla, orden, padre traducido, términos traducidos e
 * imagen destacada (traducida si los medios tienen idioma). Enlaza la copia con el original.
 * Devuelve [ ID nuevo, avisos ] o WP_Error.
 */
function spiral_mcp_pll_translate_post( WP_Post $post, $lang, array $input ) {
	$warnings = array();
	$parent   = 0;
	if ( $post->post_parent ) {
		$parent = (int) pll_get_post( $post->post_parent, $lang->slug );
		if ( ! $parent ) {
			$warnings[] = sprintf( 'La página madre (%d) no tiene traducción en "%s": la traducción queda en el primer nivel. Traduce la madre y asígnala con update-content "parent".', (int) $post->post_parent, $lang->slug );
		}
	}
	$set_lang = function ( $post_id ) use ( $lang ) {
		if ( ! wp_is_post_revision( $post_id ) && ! pll_get_post_language( $post_id ) ) {
			pll_set_post_language( $post_id, $lang->slug );
		}
	};
	add_action( 'save_post', $set_lang, 0 );
	$new_id = wp_insert_post( wp_slash( array(
		'post_type'      => $post->post_type,
		'post_title'     => isset( $input['title'] ) ? (string) $input['title'] : $post->post_title,
		'post_name'      => (string) ( $input['slug'] ?? '' ),
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_status'    => 'draft',
		'post_parent'    => $parent,
		'menu_order'     => $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'post_author'    => get_current_user_id(),
	) ), true );
	remove_action( 'save_post', $set_lang, 0 );
	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	// Términos: solo los que ya tienen traducción (si no, Polylang crearía una copia sin traducir).
	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
		if ( ! $tax->show_ui ) {
			continue; // Las taxonomías internas de Polylang (idioma, grupos) no se copian.
		}
		$ids = wp_get_object_terms( $post->ID, $tax->name, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) || ! $ids ) {
			continue;
		}
		$target = array();
		foreach ( $ids as $term_id ) {
			if ( ! pll_is_translated_taxonomy( $tax->name ) ) {
				$target[] = (int) $term_id;
				continue;
			}
			$tr = (int) pll_get_term( $term_id, $lang->slug );
			if ( $tr ) {
				$target[] = $tr;
			} else {
				$term       = get_term( $term_id );
				$warnings[] = sprintf( 'El término "%s" (%s, ID %d) no tiene traducción en "%s" y no se ha asignado: tradúcelo con create-translation (type "term") y asígnalo con update-content "terms".', $term->name, $tax->name, (int) $term_id, $lang->slug );
			}
		}
		if ( $target ) {
			wp_set_object_terms( $new_id, $target, $tax->name );
		}
	}

	$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_trash_meta_status', '_wp_trash_meta_time', '_thumbnail_id' );
	foreach ( get_post_meta( $post->ID ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		foreach ( $values as $value ) {
			add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}

	$thumb = (int) get_post_thumbnail_id( $post );
	if ( $thumb ) {
		if ( PLL()->model->options->get( 'media_support' ) && pll_get_post_language( $thumb ) ) {
			$tr_thumb = (int) pll_get_post( $thumb, $lang->slug );
			if ( ! $tr_thumb ) {
				$tr_thumb = (int) PLL()->model->post->create_media_translation( $thumb, $lang );
			}
			$thumb = $tr_thumb ? $tr_thumb : $thumb;
		}
		set_post_thumbnail( $new_id, $thumb );
	}

	$group                = spiral_mcp_pll_translations( 'post', $post->ID );
	$group[ $lang->slug ] = (int) $new_id;
	pll_save_post_translations( $group );
	return array( (int) $new_id, $warnings );
}

/**
 * Portada estática: Polylang guarda en la caché de idiomas la portada de cada idioma y solo vigila
 * page_on_front y show_on_front (limpiar la caché y regenerar las reglas de reescritura) si, al
 * empezar la petición, la portada ya era una página. Si update-site-settings la pasa de "últimas
 * entradas" a una página, /en/ se quedaría en "Nothing Found". Además, la portada de cada idioma
 * solo la calcula PLL_Static_Pages al rehacer la caché: se carga para el resto de la petición.
 */
function spiral_mcp_pll_front_page_changed() {
	if ( ! spiral_mcp_pll_active() || ! empty( PLL()->static_pages ) || ! class_exists( 'PLL_Static_Pages' ) ) {
		return; // Con PLL_Static_Pages cargado, Polylang ya lo hace.
	}
	$polylang               = PLL();
	$polylang->static_pages = new PLL_Static_Pages( $polylang );
	$polylang->static_pages->clean_cache();
	flush_rewrite_rules();
}
add_action( 'update_option_show_on_front', 'spiral_mcp_pll_front_page_changed' );
add_action( 'update_option_page_on_front', 'spiral_mcp_pll_front_page_changed' );

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/**
 * Referencia "post:1,2|term:3" → [ 'post' => [1, 2], 'term' => [3] ]. Las referencias que no
 * caben (asignaciones masivas) llevan solo un resumen y no se pueden volver a capturar.
 */
function spiral_mcp_pll_parse_ref( $ref ) {
	$out = array( 'post' => array(), 'term' => array() );
	foreach ( explode( '|', (string) $ref ) as $part ) {
		$bits = explode( ':', $part, 2 );
		if ( 2 === count( $bits ) && isset( $out[ $bits[0] ] ) && preg_match( '/^[\d,]+$/', $bits[1] ) ) {
			$out[ $bits[0] ] = array_values( array_filter( array_map( 'intval', explode( ',', $bits[1] ) ) ) );
		}
	}
	return $out;
}

/** Referencia para unos objetos (cabe en 191 caracteres o se resume). */
function spiral_mcp_pll_make_ref( array $ids, $summary ) {
	$parts = array();
	foreach ( array( 'post', 'term' ) as $type ) {
		if ( ! empty( $ids[ $type ] ) ) {
			$parts[] = $type . ':' . implode( ',', array_unique( array_map( 'intval', $ids[ $type ] ) ) );
		}
	}
	$ref = implode( '|', $parts );
	return strlen( $ref ) <= 191 ? $ref : (string) $summary;
}

/** Idioma y grupo de traducciones de unos objetos: [ type => [ id => [ language, group ] ] ]. */
function spiral_mcp_pll_capture_objects( array $ids ) {
	$out = array( 'post' => array(), 'term' => array() );
	foreach ( array( 'post', 'term' ) as $type ) {
		foreach ( (array) ( $ids[ $type ] ?? array() ) as $id ) {
			$out[ $type ][ (int) $id ] = array(
				'language' => spiral_mcp_pll_object_language( $type, $id ),
				'group'    => spiral_mcp_pll_translations( $type, $id ),
			);
		}
	}
	return $out;
}

/** Vuelve a poner el idioma y los grupos capturados con spiral_mcp_pll_capture_objects(). */
function spiral_mcp_pll_restore_objects( $data ) {
	$restored = 0;
	foreach ( array( 'post', 'term' ) as $type ) {
		$items = (array) ( $data[ $type ] ?? array() );
		// Primero se desenlaza todo (Polylang reutiliza el término de grupo de cualquier miembro) y se ponen
		// los idiomas (un grupo exige que cada objeto tenga el idioma de su clave).
		foreach ( $items as $id => $state ) {
			$exists = 'post' === $type ? (bool) get_post( (int) $id ) : ( get_term( (int) $id ) instanceof WP_Term );
			if ( ! $exists ) {
				unset( $items[ $id ] );
				continue;
			}
			if ( spiral_mcp_pll_object_language( $type, $id ) ) {
				PLL()->model->$type->delete_translation( (int) $id );
			}
			if ( empty( $state['language'] ) ) {
				wp_delete_object_term_relationships( (int) $id, 'post' === $type ? 'language' : 'term_language' );
				'post' === $type ? clean_post_cache( (int) $id ) : clean_term_cache( (int) $id );
			} elseif ( spiral_mcp_pll_object_language( $type, $id ) !== $state['language'] ) {
				'post' === $type ? pll_set_post_language( (int) $id, $state['language'] ) : pll_set_term_language( (int) $id, $state['language'] );
			}
			++$restored;
		}
		$done = array();
		foreach ( $items as $id => $state ) {
			if ( empty( $state['language'] ) || isset( $done[ $id ] ) ) {
				continue;
			}
			$group = array_filter( (array) $state['group'], function ( $member ) use ( $type ) {
				return 'post' === $type ? (bool) get_post( (int) $member ) : ( get_term( (int) $member ) instanceof WP_Term );
			} );
			$group[ $state['language'] ] = (int) $id;
			spiral_mcp_pll_save_group( $type, $group );
			foreach ( $group as $member ) {
				$done[ (int) $member ] = true;
			}
		}
	}
	return array( 'objects' => $restored );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_pll_active() ) {
		return $kinds;
	}
	// Ajustes de Polylang (claves editables).
	$kinds['pll_settings'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => function () {
			return spiral_mcp_pll_settings_values();
		},
		'restore' => function ( $ref, $data ) {
			$values = array_intersect_key( (array) $data, spiral_mcp_pll_settings_help() );
			// El idioma por defecto primero: los demás ajustes se validan contra él.
			if ( isset( $values['default_lang'] ) ) {
				$values = array( 'default_lang' => $values['default_lang'] ) + $values;
			}
			list( $changed, $not_applied ) = spiral_mcp_pll_settings_write( $values );
			return array( 'changed' => $changed, 'not_applied' => (object) $not_applied );
		},
	);
	// Ubicaciones de menú por idioma (opción nav_menus de Polylang + theme mod del idioma por defecto).
	$kinds['pll_menu_locations'] = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => function () {
			return array(
				'nav_menus' => PLL()->model->options->get( 'nav_menus' ),
				'theme_mod' => get_theme_mod( 'nav_menu_locations', array() ),
			);
		},
		'restore' => function ( $ref, $data ) {
			$result = PLL()->model->options->set( 'nav_menus', (array) ( $data['nav_menus'] ?? array() ) );
			if ( is_wp_error( $result ) && $result->has_errors() ) {
				return $result;
			}
			PLL()->model->options->save();
			set_theme_mod( 'nav_menu_locations', (array) ( $data['theme_mod'] ?? array() ) );
			return true;
		},
	);
	// Idioma y grupo de traducciones de unos contenidos o términos (ref "post:1,2|term:3").
	$kinds['pll_translations'] = array(
		'group'   => 'content',
		'cap'     => 'edit_posts',
		'capture' => function ( $ref ) {
			return spiral_mcp_pll_capture_objects( spiral_mcp_pll_parse_ref( $ref ) );
		},
		'restore' => function ( $ref, $data ) {
			return spiral_mcp_pll_restore_objects( $data );
		},
	);
	// Traducciones de textos de un idioma (ref = slug del idioma).
	$kinds['pll_strings'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$lang = PLL()->model->get_language( (string) $ref );
			return $lang ? (array) get_term_meta( $lang->term_id, '_pll_strings_translations', true ) : null;
		},
		'restore' => function ( $ref, $data ) {
			$lang = PLL()->model->get_language( (string) $ref );
			if ( ! $lang ) {
				return new WP_Error( 'spiral_mcp_pll_bad_language', sprintf( 'El idioma "%s" ya no existe.', $ref ) );
			}
			$mo = new PLL_MO();
			foreach ( (array) $data as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== $pair[0] && '' !== $pair[1] ) {
					$mo->add_entry( $mo->make_entry( wp_unslash( $pair[0] ), wp_unslash( $pair[1] ) ) );
				}
			}
			$mo->export_to_db( $lang );
			return true;
		},
	);
	// Un idioma (ref = slug). Con "full", también qué contenidos y términos lo tenían y sus grupos.
	$kinds['pll_language'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			list( $slug, $full ) = array_pad( explode( ':', (string) $ref, 2 ), 2, '' );
			$lang                = PLL()->model->get_language( $slug );
			if ( ! $lang ) {
				return null;
			}
			$data = array(
				'args' => array(
					'name'       => $lang->name,
					'slug'       => $lang->slug,
					'locale'     => $lang->locale,
					'rtl'        => (bool) $lang->is_rtl,
					'term_group' => (int) $lang->term_group,
					'flag'       => (string) $lang->flag_code,
				),
				'default' => (bool) $lang->is_default,
			);
			if ( 'full' === $full ) {
				$posts   = get_objects_in_term( (int) $lang->get_tax_prop( 'language', 'term_id' ), 'language' );
				$terms   = get_objects_in_term( (int) $lang->get_tax_prop( 'term_language', 'term_id' ), 'term_language' );
				$data   += array(
					'objects' => spiral_mcp_pll_capture_objects( array(
						'post' => is_wp_error( $posts ) ? array() : $posts,
						'term' => is_wp_error( $terms ) ? array() : $terms,
					) ),
					'strings' => get_term_meta( $lang->term_id, '_pll_strings_translations', true ),
				);
			}
			return $data;
		},
		'restore' => function ( $ref, $data ) {
			list( $slug ) = explode( ':', (string) $ref, 2 );
			$lang         = PLL()->model->get_language( $slug );
			if ( null === $data ) {
				// No existía: se borra.
				if ( $lang ) {
					PLL()->model->languages->delete( $lang->term_id );
				}
				return array( 'deleted' => (bool) $lang );
			}
			$args = (array) $data['args'];
			if ( $lang ) {
				$result = PLL()->model->languages->update( $args + array( 'lang_id' => $lang->term_id ) );
			} else {
				$result = PLL()->model->languages->add( $args );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			PLL()->model->clean_languages_cache();
			$lang = PLL()->model->get_language( $args['slug'] );
			if ( ! $lang ) {
				return new WP_Error( 'spiral_mcp_pll_language_save', 'Polylang no ha vuelto a crear el idioma.' );
			}
			if ( ! empty( $data['default'] ) && ! $lang->is_default ) {
				spiral_mcp_pll_settings_write( array( 'default_lang' => $lang->slug ) );
			}
			$out = array( 'language' => $lang->slug );
			if ( isset( $data['objects'] ) ) {
				$out += spiral_mcp_pll_restore_objects( $data['objects'] );
			}
			if ( ! empty( $data['strings'] ) && is_array( $data['strings'] ) ) {
				update_term_meta( $lang->term_id, '_pll_strings_translations', $data['strings'] );
			}
			return $out;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities de otros módulos: idioma en contenidos, términos y menús
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_ability_args', function ( $args, $name ) {
	if ( ! spiral_mcp_pll_has_languages() || empty( $args['execute_callback'] ) ) {
		return $args;
	}
	$execute = $args['execute_callback'];
	$lang_ref = array( 'type' => 'string', 'description' => 'Idioma (slug como "en" o locale como "en_US"; ver spiral-mcp/list-languages).' );

	switch ( $name ) {
		case 'spiral-mcp/list-content':
			$args['input_schema']['properties']['language'] = array( 'type' => 'string', 'description' => 'Solo el contenido de este idioma (slug, p. ej. "en"). Sin él, todos los idiomas.' );
			$args['description']                          .= ' Con Polylang, cada elemento trae su idioma ("language") y se puede filtrar con "language".';
			$args['execute_callback'] = function ( $input = array() ) use ( $execute ) {
				$input = (array) $input;
				$slug  = '';
				if ( ! empty( $input['language'] ) ) {
					$lang = spiral_mcp_pll_language( $input['language'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
					$slug = $lang->slug;
				}
				// "" = todos los idiomas, aunque Polylang haya fijado un idioma para la petición.
				$hook = function ( $query ) use ( $slug ) {
					$query->set( 'lang', $slug );
				};
				add_action( 'pre_get_posts', $hook, 1 );
				$result = call_user_func( $execute, $input );
				remove_action( 'pre_get_posts', $hook, 1 );
				if ( is_array( $result ) && isset( $result['items'] ) ) {
					foreach ( $result['items'] as &$item ) {
						$item['language'] = spiral_mcp_pll_object_language( 'post', $item['id'] );
					}
					unset( $item );
				}
				return $result;
			};
			break;

		case 'spiral-mcp/get-content':
			$args['description']     .= ' Con Polylang, trae su idioma ("language") y sus traducciones ("translations": idioma → ID).';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$result = call_user_func( $execute, $input );
				if ( is_array( $result ) && ! empty( $result['id'] ) && pll_is_translated_post_type( $result['type'] ?? '' ) ) {
					$result['language']     = spiral_mcp_pll_object_language( 'post', $result['id'] );
					$result['translations'] = (object) spiral_mcp_pll_translations( 'post', $result['id'] );
				}
				return $result;
			};
			break;

		case 'spiral-mcp/create-content':
			$args['input_schema']['properties']['language']       = $lang_ref + array( 'description' => 'Idioma del contenido nuevo (slug o locale). Por defecto, el idioma por defecto del sitio.' );
			$args['input_schema']['properties']['translation_of'] = array( 'type' => 'integer', 'description' => 'ID del contenido del que este es la traducción (mismo tipo, otro idioma): quedan enlazados. Exige "language".' );
			$args['description'] .= ' Con Polylang: "language" fija el idioma y "translation_of" lo enlaza como traducción de otro contenido (para copiar también el diseño de YOOtheme usa mejor spiral-mcp/create-translation). Usa términos del mismo idioma (list-terms con "language"): si das uno de otro idioma, Polylang pone su traducción o crea una copia.';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$input  = (array) $input;
				$lang   = null;
				$source = null;
				if ( ! empty( $input['language'] ) ) {
					$lang = spiral_mcp_pll_language( $input['language'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
				}
				if ( ! empty( $input['translation_of'] ) ) {
					$source = get_post( (int) $input['translation_of'] );
					if ( ! $source || $source->post_type !== ( $input['post_type'] ?? 'page' ) ) {
						return new WP_Error( 'spiral_mcp_bad_input', '"translation_of" debe ser el ID de un contenido del mismo tipo (post_type).' );
					}
					if ( ! current_user_can( 'edit_post', $source->ID ) ) {
						return new WP_Error( 'spiral_mcp_forbidden', 'No puedes editar el contenido de "translation_of".', array( 'status' => 403 ) );
					}
					if ( ! $lang ) {
						return new WP_Error( 'spiral_mcp_bad_input', 'Con "translation_of" indica también "language" (el idioma de la traducción).' );
					}
					if ( pll_get_post_language( $source->ID ) === $lang->slug ) {
						return new WP_Error( 'spiral_mcp_pll_same_language', sprintf( 'El contenido %d ya está en "%s": una traducción tiene que estar en otro idioma.', $source->ID, $lang->slug ) );
					}
					$existing = (int) pll_get_post( $source->ID, $lang->slug );
					if ( $existing ) {
						return new WP_Error( 'spiral_mcp_pll_translation_exists', sprintf( 'El contenido %d ya tiene traducción en "%s" (ID %d). Edítala, o desvincúlala antes con spiral-mcp/update-translations.', $source->ID, $lang->slug, $existing ) );
					}
				}
				$hook = null;
				if ( $lang ) {
					// Antes que Polylang (prioridad 10), para que asigne términos y padre en ese idioma.
					$hook = function ( $post_id ) use ( $lang ) {
						if ( ! wp_is_post_revision( $post_id ) && ! pll_get_post_language( $post_id ) ) {
							pll_set_post_language( $post_id, $lang->slug );
						}
					};
					add_action( 'save_post', $hook, 0 );
				}
				$result = call_user_func( $execute, $input );
				if ( $hook ) {
					remove_action( 'save_post', $hook, 0 );
				}
				if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
					return $result;
				}
				if ( $source ) {
					$group                = spiral_mcp_pll_translations( 'post', $source->ID );
					$group[ $lang->slug ] = (int) $result['id'];
					pll_save_post_translations( $group );
				}
				$result['language']     = spiral_mcp_pll_object_language( 'post', $result['id'] );
				$result['translations'] = (object) spiral_mcp_pll_translations( 'post', $result['id'] );
				return $result;
			};
			break;

		case 'spiral-mcp/duplicate-content':
			$args['input_schema']['properties']['language'] = $lang_ref + array( 'description' => 'Idioma de la copia. Por defecto, el del original. La copia no queda enlazada como traducción (para eso, spiral-mcp/create-translation).' );
			$args['description']                          .= ' Con Polylang, la copia queda en el idioma del original (o en "language") y no se enlaza como traducción: para traducir una página usa spiral-mcp/create-translation.';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$input = (array) $input;
				$lang  = null;
				if ( ! empty( $input['language'] ) ) {
					$lang = spiral_mcp_pll_language( $input['language'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
				}
				$result = call_user_func( $execute, $input );
				if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
					return $result;
				}
				// La copia se lleva también las taxonomías internas de Polylang (idioma y grupo): se quita del grupo del original.
				$id = (int) $result['id'];
				wp_delete_object_term_relationships( $id, 'post_translations' );
				clean_post_cache( $id );
				if ( $lang ) {
					pll_set_post_language( $id, $lang->slug );
				}
				$result['language'] = spiral_mcp_pll_object_language( 'post', $id );
				return $result;
			};
			break;

		case 'spiral-mcp/list-terms':
			$args['input_schema']['properties']['language'] = array( 'type' => 'string', 'description' => 'Solo los términos de este idioma (slug). Sin él, todos los idiomas.' );
			$args['description']                          .= ' Con Polylang, cada término trae su idioma ("language") y se puede filtrar con "language".';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$input = (array) $input;
				$slug  = '';
				if ( ! empty( $input['language'] ) ) {
					$lang = spiral_mcp_pll_language( $input['language'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
					$slug = $lang->slug;
				}
				$hook = function ( $query_args ) use ( $slug ) {
					$query_args['lang'] = $slug;
					return $query_args;
				};
				add_filter( 'get_terms_args', $hook, 1 );
				$result = call_user_func( $execute, $input );
				remove_filter( 'get_terms_args', $hook, 1 );
				if ( is_array( $result ) && isset( $result['items'] ) ) {
					foreach ( $result['items'] as &$item ) {
						$item['language'] = spiral_mcp_pll_object_language( 'term', $item['id'] );
					}
					unset( $item );
				}
				return $result;
			};
			break;

		case 'spiral-mcp/create-term':
			$args['input_schema']['properties']['language']       = $lang_ref + array( 'description' => 'Idioma del término (slug o locale). Por defecto, el idioma por defecto.' );
			$args['input_schema']['properties']['translation_of'] = array( 'type' => 'integer', 'description' => 'ID del término del que este es la traducción (misma taxonomía, otro idioma). Exige "language".' );
			$args['description'] .= ' Con Polylang: "language" fija el idioma y "translation_of" lo enlaza como traducción de otro término. Puede llamarse igual que un término de otro idioma; si el slug ya existe, Polylang le añade el idioma (p. ej. "noticias-en").';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$input  = (array) $input;
				$lang   = null;
				$source = null;
				if ( ! empty( $input['language'] ) ) {
					$lang = spiral_mcp_pll_language( $input['language'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
				}
				if ( ! empty( $input['translation_of'] ) ) {
					$source = get_term( (int) $input['translation_of'] );
					if ( ! $source instanceof WP_Term || $source->taxonomy !== ( $input['taxonomy'] ?? '' ) ) {
						return new WP_Error( 'spiral_mcp_bad_input', '"translation_of" debe ser el ID de un término de la misma taxonomía.' );
					}
					if ( ! $lang ) {
						return new WP_Error( 'spiral_mcp_bad_input', 'Con "translation_of" indica también "language" (el idioma de la traducción).' );
					}
					if ( pll_get_term_language( $source->term_id ) === $lang->slug ) {
						return new WP_Error( 'spiral_mcp_pll_same_language', sprintf( 'El término %d ya está en "%s": una traducción tiene que estar en otro idioma.', $source->term_id, $lang->slug ) );
					}
					$existing = (int) pll_get_term( $source->term_id, $lang->slug );
					if ( $existing ) {
						return new WP_Error( 'spiral_mcp_pll_translation_exists', sprintf( 'El término %d ya tiene traducción en "%s" (ID %d).', $source->term_id, $lang->slug, $existing ) );
					}
				}
				if ( ! $lang ) {
					return call_user_func( $execute, $input );
				}
				$taxonomy  = (string) ( $input['taxonomy'] ?? '' );
				$parent    = (int) ( $input['parent'] ?? 0 );
				$filters   = array(
					// Idioma y padre para el sufijo del slug (como pll_insert_term()).
					'pll_inserted_term_language' => function ( $found ) use ( $lang ) {
						return $found instanceof PLL_Language ? $found : $lang;
					},
					'pll_inserted_term_parent'   => function ( $found ) use ( $parent ) {
						return $found ? $found : $parent;
					},
					// La comprobación de nombre repetido de WordPress, solo dentro del idioma.
					'get_terms_args'             => function ( $query_args ) use ( $lang ) {
						if ( ! isset( $query_args['lang'] ) ) {
							$query_args['lang'] = $lang->slug;
						}
						return $query_args;
					},
				);
				$set_lang = function ( $term_id, $tt_id, $tax ) use ( $lang, $taxonomy ) {
					if ( $tax === $taxonomy && ! pll_get_term_language( $term_id ) ) {
						pll_set_term_language( $term_id, $lang->slug );
					}
				};
				foreach ( $filters as $hook => $callback ) {
					add_filter( $hook, $callback );
				}
				add_action( 'create_term', $set_lang, 10, 3 );
				$result = call_user_func( $execute, $input );
				foreach ( $filters as $hook => $callback ) {
					remove_filter( $hook, $callback );
				}
				remove_action( 'create_term', $set_lang, 10 );
				if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
					return $result;
				}
				if ( $source ) {
					$group                = spiral_mcp_pll_translations( 'term', $source->term_id );
					$group[ $lang->slug ] = (int) $result['id'];
					pll_save_term_translations( $group );
				}
				$result['language']     = spiral_mcp_pll_object_language( 'term', $result['id'] );
				$result['translations'] = (object) spiral_mcp_pll_translations( 'term', $result['id'] );
				return $result;
			};
			break;

		case 'spiral-mcp/list-menus':
			$args['description']     .= ' Con Polylang, cada ubicación tiene un menú por idioma: "languages" en cada ubicación (idioma → ID de menú) y "language_locations" en cada menú. Es lo que usa la web; "menu_id" y "locations" son solo los del idioma por defecto.';
			$args['execute_callback'] = function ( $input = array() ) use ( $execute ) {
				$result = call_user_func( $execute, $input );
				if ( ! is_array( $result ) ) {
					return $result;
				}
				$map = spiral_mcp_pll_menu_map();
				foreach ( (array) ( $result['locations'] ?? array() ) as $i => $loc ) {
					$langs = array();
					foreach ( pll_languages_list() as $slug ) {
						$langs[ $slug ] = (int) ( $map[ $loc['location'] ][ $slug ] ?? 0 );
					}
					$result['locations'][ $i ]['languages'] = $langs;
				}
				foreach ( (array) ( $result['menus'] ?? array() ) as $i => $menu ) {
					$result['menus'][ $i ]['language_locations'] = spiral_mcp_pll_menu_locations_of( $menu['id'] );
				}
				return $result;
			};
			break;

		case 'spiral-mcp/create-menu':
		case 'spiral-mcp/update-menu':
			$args['input_schema']['properties']['language'] = $lang_ref + array( 'description' => 'Idioma en el que se asignan las ubicaciones de "locations" (con Polylang cada idioma tiene su menú en cada ubicación). Por defecto, el idioma por defecto.' );
			$args['description']                          .= ' Con Polylang, "locations" se aplica al idioma de "language" (por defecto, el idioma por defecto): crea un menú por idioma y asígnalo a la misma ubicación en cada idioma. Guarda un snapshot de las ubicaciones ("locations_snapshot_id").';
			$args['execute_callback'] = function ( $input ) use ( $execute, $name ) {
				$input = (array) $input;
				if ( ! isset( $input['locations'] ) ) {
					return call_user_func( $execute, $input );
				}
				$lang = ! empty( $input['language'] ) ? spiral_mcp_pll_language( $input['language'] ) : spiral_mcp_pll_default_language();
				if ( is_wp_error( $lang ) ) {
					return $lang;
				}
				$locations  = array_values( array_map( 'strval', (array) $input['locations'] ) );
				$registered = get_registered_nav_menus();
				foreach ( $locations as $loc ) {
					if ( ! isset( $registered[ $loc ] ) ) {
						return new WP_Error( 'spiral_mcp_bad_location', sprintf( 'La ubicación "%s" no existe en el tema. Disponibles: %s', $loc, implode( ', ', array_keys( $registered ) ) ) );
					}
				}
				$snapshot = spiral_mcp_snapshot_capture( 'pll_menu_locations', 'nav_menus', str_replace( 'spiral-mcp/', '', $name ) );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				if ( ! $lang->is_default ) {
					unset( $input['locations'] ); // El módulo de menús escribiría el theme mod, que es del idioma por defecto.
				}
				if ( 'spiral-mcp/update-menu' === $name && ! isset( $input['locations'] ) && ! isset( $input['name'] ) ) {
					// Solo cambian las ubicaciones en otro idioma: el módulo de menús no tiene nada que hacer.
					$menu = spiral_mcp_menu_get( $input['menu'] ?? 0 );
					if ( is_wp_error( $menu ) ) {
						return $menu;
					}
					$result = spiral_mcp_menu_out( $menu );
				} else {
					$result = call_user_func( $execute, $input );
				}
				if ( is_wp_error( $result ) || empty( $result['id'] ) ) {
					return $result;
				}
				$res = spiral_mcp_pll_write_menu_locations( $lang, array_fill_keys( $locations, (int) $result['id'] ), (int) $result['id'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$result['language_locations']    = spiral_mcp_pll_menu_locations_of( $result['id'] );
				$result['locations_snapshot_id'] = $snapshot;
				return $result;
			};
			break;

		case 'spiral-mcp/get-site-settings':
		case 'spiral-mcp/update-site-settings':
			$args['description'] .= ' Con Polylang, el nombre y la descripción del sitio son los del idioma por defecto; en los demás idiomas se traducen con spiral-mcp/update-string-translations.';
			break;

		case 'spiral-mcp/create-widget':
		case 'spiral-mcp/update-widget':
			$args['description'] .= ' Con Polylang, "pll_lang": "en" en "instance" hace que el widget solo se vea en ese idioma ("" = en todos): así se hace un header o footer distinto por idioma con widgets de builder.';
			$args['execute_callback'] = function ( $input ) use ( $execute ) {
				$input = (array) $input;
				if ( ! is_array( $input['instance'] ?? null ) || ! array_key_exists( 'pll_lang', $input['instance'] ) ) {
					return call_user_func( $execute, $input );
				}
				$slug = '';
				if ( '' !== (string) $input['instance']['pll_lang'] ) {
					$lang = spiral_mcp_pll_language( $input['instance']['pll_lang'] );
					if ( is_wp_error( $lang ) ) {
						return $lang;
					}
					$slug = $lang->slug;
				}
				$result = call_user_func( $execute, $input );
				$data   = is_array( $result ) && ! empty( $result['id'] ) ? spiral_mcp_widget_instance( $result['id'] ) : null;
				if ( ! is_array( $data ) ) {
					return $result;
				}
				// La clase del widget descarta las claves que no conoce; Polylang añade la suya solo desde el formulario de wp-admin.
				list( $base, $num, $all ) = $data;
				if ( '' === $slug ) {
					unset( $all[ $num ]['pll_lang'] );
				} else {
					$all[ $num ]['pll_lang'] = $slug;
				}
				update_option( 'widget_' . $base, $all );
				$result['language'] = '' === $slug ? null : $slug;
				return $result;
			};
			break;

		case 'spiral-mcp/create-template':
		case 'spiral-mcp/update-template':
			$args['description'] .= ' Con Polylang, la condición "locale" de la plantilla (p. ej. "en_US", el locale de list-languages) la limita a un idioma.';
			break;
	}
	return $args;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Categoría y abilities propias
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! spiral_mcp_pll_active() ) {
		return;
	}
	wp_register_ability_category( 'spiral-mcp-languages', array(
		'label'       => 'Idiomas Spiral-MCP',
		'description' => 'Polylang: idiomas, traducciones de contenidos, términos y textos, menús por idioma y selector de idioma.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_pll_active() ) {
		return;
	}

	$can_settings = function () {
		return current_user_can( 'edit_theme_options' );
	};
	$type_prop = array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'description' => '"post" (páginas, entradas…) o "term" (categorías, etiquetas…).' );
	$lang_prop = array( 'type' => 'string', 'description' => 'Idioma: slug (p. ej. "en") o locale (p. ej. "en_US").' );
	$can_object = function ( $input = array() ) {
		$input = (array) $input;
		$type  = (string) ( $input['type'] ?? '' );
		return in_array( $type, array( 'post', 'term' ), true ) && spiral_mcp_pll_can_edit( $type, (int) ( $input['id'] ?? 0 ) );
	};
	$language_fields = array(
		'name'   => array( 'type' => 'string', 'description' => 'Nombre visible (p. ej. "English").' ),
		'slug'   => array( 'type' => 'string', 'description' => 'Código de la URL (p. ej. "en").' ),
		'locale' => array( 'type' => 'string', 'description' => 'Locale de WordPress (p. ej. "en_US", "fr_FR", "pt_BR").' ),
		'rtl'    => array( 'type' => 'boolean', 'description' => 'Se escribe de derecha a izquierda.' ),
		'order'  => array( 'type' => 'integer', 'description' => 'Posición en el selector de idioma.' ),
		'flag'   => array( 'type' => 'string', 'description' => 'Código de bandera de Polylang (p. ej. "us", "gb", "es", "fr").' ),
	);
	$language_args = function ( array $input ) {
		$args = array_intersect_key( $input, array_flip( array( 'name', 'slug', 'locale', 'rtl', 'flag' ) ) );
		if ( isset( $input['order'] ) ) {
			$args['term_group'] = (int) $input['order'];
		}
		return $args;
	};

	/* 1. Idiomas y ajustes ---------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-languages', array(
		'label'               => 'Listar idiomas',
		'description'         => 'Idiomas del sitio con Polylang (slug, locale, nombre, bandera, si es el idioma por defecto, orden, portada de cada idioma "home" y número de contenidos publicados), si WordPress tiene instalada su traducción, los ajustes de Polylang con su ayuda ("settings", "settings_help"), qué tipos de contenido y taxonomías se traducen, y cuántos contenidos y términos no tienen idioma ("without_language": asígnalo con spiral-mcp/assign-missing-languages). Léelo antes de traducir: los demás abilities aceptan el slug o el locale en "language".',
		'category'            => 'spiral-mcp-languages',
		'group'               => array( 'content', 'design', 'settings' ),
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function () {
			$languages = array();
			foreach ( PLL()->model->get_languages_list() as $lang ) {
				$languages[] = spiral_mcp_pll_language_out( $lang );
			}
			$post_types = array_values( array_filter( get_post_types( array( 'show_ui' => true ) ), 'pll_is_translated_post_type' ) );
			$taxonomies = array_values( array_filter( get_taxonomies( array( 'show_ui' => true ) ), 'pll_is_translated_taxonomy' ) );
			$missing    = $languages ? PLL()->model->get_objects_with_no_lang( 1000 ) : array();
			$default    = spiral_mcp_pll_default_language();
			return array(
				'plugin'           => 'polylang',
				'version'          => POLYLANG_VERSION,
				'default'          => $default ? $default->slug : null,
				'languages'        => $languages,
				'translated'       => array( 'post_types' => array_values( array_diff( $post_types, array( 'attachment' ) ) ), 'taxonomies' => $taxonomies, 'media' => (bool) PLL()->model->options->get( 'media_support' ) ),
				'without_language' => array(
					'posts' => count( (array) ( $missing['posts'] ?? array() ) ),
					'terms' => count( (array) ( $missing['terms'] ?? array() ) ),
				),
				'settings'         => spiral_mcp_pll_settings_values(),
				'settings_help'    => spiral_mcp_pll_settings_help(),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-language', array(
		'label'               => 'Añadir un idioma',
		'description'         => 'Añade un idioma al sitio. Basta con "locale" (p. ej. "en_US"): Polylang completa nombre, slug, bandera y dirección de su lista de idiomas. El primero que se crea es el idioma por defecto. El contenido que ya existe no cambia de idioma (si no tenía, ver "without_language" en list-languages). Avisa si WordPress no tiene instalada la traducción del idioma (los textos del tema saldrían en inglés: el usuario puede instalarla en Ajustes > Generales). restore-snapshot con el "snapshot_id" lo borra.',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'language', 'id' => function ( $in, $res ) {
			return $res['slug'] ?? ( $in['slug'] ?? ( $in['locale'] ?? '' ) );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'locale' ),
			'properties' => $language_fields,
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) use ( $language_args ) {
			$args   = $language_args( (array) $input );
			$result = PLL()->model->languages->add( $args );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'spiral_mcp_pll_language_save', 'Polylang no ha creado el idioma: ' . implode( ' ', $result->get_error_messages() ) );
			}
			PLL()->model->clean_languages_cache();
			$lang = PLL()->model->get_language( (string) ( $args['slug'] ?? $args['locale'] ) );
			if ( ! $lang ) {
				return new WP_Error( 'spiral_mcp_pll_language_save', 'Polylang no ha creado el idioma.' );
			}
			$snapshot = spiral_mcp_snapshot_take( 'pll_language', $lang->slug, null, 'create-language: el idioma no existía' );
			$out      = spiral_mcp_pll_language_out( $lang ) + array( 'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot );
			if ( ! $out['wordpress_translation_installed'] ) {
				$out['warnings'] = array( sprintf( 'WordPress no tiene instalada la traducción "%s": los textos del tema y de los plugins se verán en inglés en ese idioma. El usuario puede instalarla eligiendo el idioma en Ajustes > Generales (y volviendo a dejar el suyo).', $lang->locale ) );
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-language', array(
		'label'               => 'Editar un idioma',
		'description'         => 'Cambia el nombre, slug, locale, dirección, orden o bandera de un idioma. Cambiar el slug cambia las URLs de ese idioma (/en/ → /us/) y exige "confirm". Guarda un snapshot ("snapshot_id"). Para cambiar el idioma por defecto usa spiral-mcp/update-language-settings con "default_lang".',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'language', 'id' => 'language' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'language' ),
			'properties' => array( 'language' => $lang_prop ) + $language_fields + array(
				'confirm' => array( 'type' => 'boolean', 'description' => 'true si cambias el slug, tras confirmarlo con el usuario.' ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) use ( $language_args ) {
			$input = (array) $input;
			$lang  = spiral_mcp_pll_language( $input['language'] ?? '' );
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			$args = $language_args( $input );
			if ( ! $args ) {
				return new WP_Error( 'spiral_mcp_nothing', 'No hay nada que cambiar: envía name, slug, locale, rtl, order o flag.' );
			}
			if ( isset( $args['slug'] ) && $args['slug'] !== $lang->slug ) {
				$confirm = spiral_mcp_require_confirm( $input, sprintf( 'cambiar el slug de "%s" a "%s" (cambian las URLs de ese idioma)', $lang->slug, $args['slug'] ) );
				if ( is_wp_error( $confirm ) ) {
					return $confirm;
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'pll_language', $lang->slug, 'update-language' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$result = PLL()->model->languages->update( $args + array( 'lang_id' => $lang->term_id ) );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'spiral_mcp_pll_language_save', 'Polylang no ha guardado el idioma: ' . implode( ' ', $result->get_error_messages() ) );
			}
			PLL()->model->clean_languages_cache();
			$lang = PLL()->model->get_language( (int) $lang->term_id );
			return spiral_mcp_pll_language_out( $lang ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-language', array(
		'label'               => 'Borrar un idioma',
		'description'         => 'Borra un idioma. Exige "confirm": true. Los contenidos y términos de ese idioma NO se borran, pero se quedan sin idioma y fuera de sus grupos de traducción; también se pierden sus ubicaciones de menú, sus traducciones de textos y su dominio. Si era el idioma por defecto, Polylang elige otro. Guarda un snapshot completo ("snapshot_id"): restore-snapshot vuelve a crear el idioma y le devuelve sus contenidos, términos, grupos y textos (las ubicaciones de menú hay que volver a asignarlas).',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'language', 'id' => 'language' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'language', 'confirm' ),
			'properties' => array(
				'language' => $lang_prop,
				'confirm'  => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) {
			$lang = spiral_mcp_pll_language( $input['language'] ?? '' );
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			$confirm = spiral_mcp_require_confirm( $input, sprintf( 'borrar el idioma "%s"', $lang->slug ) );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$before   = spiral_mcp_pll_language_out( $lang );
			$objects  = get_objects_in_term( (int) $lang->get_tax_prop( 'language', 'term_id' ), 'language' );
			$snapshot = spiral_mcp_snapshot_capture( 'pll_language', $lang->slug . ':full', 'delete-language' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if ( ! PLL()->model->languages->delete( $lang->term_id ) ) {
				return new WP_Error( 'spiral_mcp_pll_language_delete', 'Polylang no ha borrado el idioma.' );
			}
			$default = spiral_mcp_pll_default_language();
			return array(
				'deleted'          => true,
				'language'         => $before,
				'objects_unlinked' => is_wp_error( $objects ) ? null : count( $objects ),
				'default'          => $default ? $default->slug : null,
				'snapshot_id'      => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-language-settings', array(
		'label'               => 'Cambiar los ajustes de idiomas',
		'description'         => 'Cambia ajustes de Polylang (los de "settings" en spiral-mcp/list-languages; "settings_help" explica cada uno): idioma por defecto, cómo lleva la URL el idioma, ocultar el código del idioma por defecto, detección del navegador, idioma en los medios, tipos de contenido y taxonomías personalizadas que se traducen y qué se sincroniza entre traducciones. Los que cambian las URLs del sitio (default_lang, force_lang, hide_default, rewrite, redirect_lang, domains) exigen "confirm": true tras avisar al usuario (los enlaces y el SEO cambian). Polylang valida cada valor: lo que no acepta queda en "not_applied" sin cambiar. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'language_settings', 'id' => function () {
			return 'polylang';
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'settings' ),
			'properties' => array(
				'settings' => array( 'type' => 'object', 'description' => 'Solo las claves que cambian, p. ej. {"browser": true, "hide_default": true}.' ),
				'confirm'  => array( 'type' => 'boolean', 'description' => 'true si cambias un ajuste que cambia las URLs, tras confirmarlo con el usuario.' ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) {
			$input    = (array) $input;
			$settings = (array) ( $input['settings'] ?? array() );
			$help     = spiral_mcp_pll_settings_help();
			if ( ! $settings ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "settings" las claves que cambian.' );
			}
			$bad = array_diff( array_keys( $settings ), array_keys( $help ) );
			if ( $bad ) {
				return new WP_Error( 'spiral_mcp_bad_key', sprintf( 'Ajustes que no se pueden cambiar: %s. Editables: %s.', implode( ', ', $bad ), implode( ', ', array_keys( $help ) ) ) );
			}
			if ( isset( $settings['default_lang'] ) ) {
				$lang = spiral_mcp_pll_language( $settings['default_lang'] );
				if ( is_wp_error( $lang ) ) {
					return $lang;
				}
				$settings = array( 'default_lang' => $lang->slug ) + $settings;
			}
			$current     = spiral_mcp_pll_settings_values();
			$url_changes = array();
			foreach ( spiral_mcp_pll_url_settings() as $key ) {
				if ( array_key_exists( $key, $settings ) && ! spiral_mcp_pll_same_value( $settings[ $key ], $current[ $key ] ) ) {
					$url_changes[] = $key;
				}
			}
			if ( $url_changes && true !== ( $input['confirm'] ?? false ) ) {
				return new WP_Error( 'spiral_mcp_confirm', sprintf( 'Estos ajustes cambian las URLs del sitio: %s. Avisa al usuario (cambian los enlaces y el SEO) y repite con "confirm": true.', implode( ', ', $url_changes ) ) );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'pll_settings', 'polylang', 'update-language-settings' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			list( $changed, $not_applied ) = spiral_mcp_pll_settings_write( $settings );
			return array(
				'changed'     => $changed,
				'not_applied' => (object) $not_applied,
				'settings'    => spiral_mcp_pll_settings_values(),
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/assign-missing-languages', array(
		'label'               => 'Asignar idioma al contenido que no tiene',
		'description'         => 'Pone un idioma (por defecto, el idioma por defecto) a todos los contenidos y términos traducibles que no tienen ninguno: pasa al instalar Polylang en un sitio con contenido o al activar la traducción de un tipo de contenido. Sin idioma, Polylang no los muestra bien en la web. Devuelve cuántos ha asignado ("posts", "terms"); el snapshot ("snapshot_id") les vuelve a quitar el idioma.',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'content',
		'log'                 => array( 'type' => 'language', 'id' => 'language' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'language' => $lang_prop ),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_others_pages' ) && current_user_can( 'manage_categories' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$lang  = ! empty( $input['language'] ) ? spiral_mcp_pll_language( $input['language'] ) : spiral_mcp_pll_default_language();
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			if ( ! $lang ) {
				return new WP_Error( 'spiral_mcp_pll_no_languages', 'No hay idiomas: crea uno con spiral-mcp/create-language.' );
			}
			// Primero la lista completa (para el snapshot); luego Polylang los asigna por tandas.
			$missing = PLL()->model->get_objects_with_no_lang( -1 );
			$posts   = array_map( 'intval', (array) ( $missing['posts'] ?? array() ) );
			$terms   = array_map( 'intval', (array) ( $missing['terms'] ?? array() ) );
			if ( $posts || $terms ) {
				PLL()->model->set_language_in_mass( $lang );
				PLL()->model->clean_languages_cache();
			}
			$snapshot = null;
			if ( $posts || $terms ) {
				$data = array( 'post' => array_fill_keys( $posts, array( 'language' => null, 'group' => array() ) ), 'term' => array_fill_keys( $terms, array( 'language' => null, 'group' => array() ) ) );
				$ref  = spiral_mcp_pll_make_ref( array( 'post' => $posts, 'term' => $terms ), 'masivo:' . gmdate( 'YmdHis' ) );
				$snap = spiral_mcp_snapshot_take( 'pll_translations', $ref, $data, sprintf( 'assign-missing-languages (%s): no tenían idioma', $lang->slug ) );
				$snapshot = is_wp_error( $snap ) ? null : $snap;
			}
			return array(
				'language'    => $lang->slug,
				'posts'       => count( $posts ),
				'terms'       => count( $terms ),
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 2. Traducciones de contenidos y términos ------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/get-translations', array(
		'label'               => 'Ver las traducciones',
		'description'         => 'Idioma de un contenido o término y sus traducciones: por idioma, ID, título o nombre, estado y ruta; "missing" lista los idiomas sin traducción. Para crear la que falta, spiral-mcp/create-translation; para enlazar o desenlazar traducciones que ya existen, spiral-mcp/update-translations.',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'type', 'id' ),
			'properties' => array(
				'type' => $type_prop,
				'id'   => array( 'type' => 'integer' ),
			),
		),
		'permission_callback' => $can_object,
		'execute_callback'    => function ( $input ) {
			$type = spiral_mcp_pll_type( $input['type'] ?? '' );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$obj = spiral_mcp_pll_object( $type, $input['id'] ?? 0 );
			if ( is_wp_error( $obj ) ) {
				return $obj;
			}
			return spiral_mcp_pll_translations_out( $type, (int) $input['id'] );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-translation', array(
		'label'               => 'Crear una traducción',
		'description'         => 'Crea la traducción de un contenido o término en otro idioma y la enlaza con el original. Contenido ("type": "post"): copia como borrador el contenido con su diseño de YOOtheme, extracto, metas, plantilla y orden, la página madre traducida, los términos que ya tienen traducción e imagen destacada; los textos siguen en el idioma original: tradúcelos después con spiral-mcp/get-layout-texts y spiral-mcp/patch-layout (o update-content), cambia el título y el slug, revisa que los enlaces internos apunten a las páginas de ese idioma, y publícala. "warnings" dice qué no se ha podido traducir (madre o términos sin traducción). Término ("type": "term"): lo crea con el nombre que des en "title" (obligatorio) y el padre traducido.',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'content',
		'log'                 => array( 'type' => 'translation', 'id' => function ( $in, $res ) {
			return ( $in['type'] ?? '' ) . ':' . ( $res['id'] ?? '' );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'type', 'id', 'language' ),
			'properties' => array(
				'type'     => $type_prop,
				'id'       => array( 'type' => 'integer', 'description' => 'ID del original.' ),
				'language' => $lang_prop,
				'title'    => array( 'type' => 'string', 'description' => 'Título (contenido) o nombre (término) traducido. En contenidos, por defecto el del original.' ),
				'slug'     => array( 'type' => 'string', 'description' => 'Slug traducido (p. ej. "contact" para "contacto"). Por defecto, el que genere WordPress a partir del título.' ),
				'description' => array( 'type' => 'string', 'description' => 'Solo términos: descripción traducida.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) use ( $can_object ) {
			$input = (array) $input;
			if ( ! $can_object( $input ) ) {
				return false;
			}
			if ( 'post' === ( $input['type'] ?? '' ) ) {
				$post = get_post( (int) ( $input['id'] ?? 0 ) );
				$type = $post ? get_post_type_object( $post->post_type ) : null;
				return $type && current_user_can( $type->cap->create_posts );
			}
			$term = get_term( (int) ( $input['id'] ?? 0 ) );
			$tax  = $term instanceof WP_Term ? get_taxonomy( $term->taxonomy ) : null;
			return $tax && current_user_can( $tax->cap->edit_terms );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$type  = spiral_mcp_pll_type( $input['type'] ?? '' );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$obj = spiral_mcp_pll_object( $type, $input['id'] ?? 0 );
			if ( is_wp_error( $obj ) ) {
				return $obj;
			}
			$lang = spiral_mcp_pll_language( $input['language'] ?? '' );
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			$id     = (int) $input['id'];
			$source = spiral_mcp_pll_object_language( $type, $id );
			if ( ! $source ) {
				return new WP_Error( 'spiral_mcp_pll_no_language', 'El original no tiene idioma: asígnaselo con spiral-mcp/update-translations ("language") o spiral-mcp/assign-missing-languages.' );
			}
			if ( $source === $lang->slug ) {
				return new WP_Error( 'spiral_mcp_pll_same_language', sprintf( 'El original ya está en "%s": elige otro idioma.', $lang->slug ) );
			}
			$group = spiral_mcp_pll_translations( $type, $id );
			if ( ! empty( $group[ $lang->slug ] ) ) {
				return new WP_Error( 'spiral_mcp_pll_translation_exists', sprintf( 'Ya existe la traducción en "%s" (ID %d). Edítala, o desvincúlala antes con spiral-mcp/update-translations.', $lang->slug, $group[ $lang->slug ] ) );
			}

			if ( 'post' === $type ) {
				$res = spiral_mcp_pll_translate_post( $obj, $lang, $input );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				list( $new_id, $warnings ) = $res;
				$out = spiral_mcp_summary( get_post( $new_id ) ) + array(
					'language'     => $lang->slug,
					'source_id'    => $id,
					'translations' => (object) spiral_mcp_pll_translations( 'post', $new_id ),
				);
				if ( $warnings ) {
					$out['warnings'] = $warnings;
				}
				return $out;
			}

			if ( empty( $input['title'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Para traducir un término envía "title" con el nombre traducido.' );
			}
			$warnings = array();
			$parent   = 0;
			if ( $obj->parent ) {
				$parent = (int) pll_get_term( $obj->parent, $lang->slug );
				if ( ! $parent ) {
					$warnings[] = sprintf( 'El término padre (%d) no tiene traducción en "%s": la traducción queda en el primer nivel.', (int) $obj->parent, $lang->slug );
				}
			}
			$group[ $lang->slug ] = 0;
			$args                 = array_filter( array(
				'slug'        => (string) ( $input['slug'] ?? '' ),
				'description' => (string) ( $input['description'] ?? '' ),
				'parent'      => $parent,
			) );
			$res = pll_insert_term( (string) $input['title'], $obj->taxonomy, $lang, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$new_id               = (int) $res['term_id'];
			$group[ $lang->slug ] = $new_id;
			pll_save_term_translations( $group );
			$snapshot = spiral_mcp_snapshot_take( 'term', $obj->taxonomy . ':' . $new_id, null, 'create-translation: el término no existía' );
			$out      = spiral_mcp_term_out( get_term( $new_id ) ) + array(
				'language'     => $lang->slug,
				'source_id'    => $id,
				'translations' => (object) spiral_mcp_pll_translations( 'term', $new_id ),
				'snapshot_id'  => is_wp_error( $snapshot ) ? null : $snapshot,
			);
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-translations', array(
		'label'               => 'Cambiar idioma y traducciones',
		'description'         => 'Cambia el idioma de un contenido o término ("language") y/o con qué otros está enlazado como traducción ("translations": {"en": 12} enlaza el 12 como su versión en inglés; {"en": 0} desenlaza la inglesa, que sigue existiendo). Los enlazados deben ser del mismo tipo de contenido o taxonomía y estar en el idioma de su clave (si no tienen idioma, se les pone). Un contenido que ya estaba en otro grupo de traducciones se mueve a este. Primero mira el estado con spiral-mcp/get-translations. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'content',
		'log'                 => array( 'type' => 'translation', 'id' => function ( $in ) {
			return ( $in['type'] ?? '' ) . ':' . ( $in['id'] ?? '' );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'type', 'id' ),
			'properties' => array(
				'type'         => $type_prop,
				'id'           => array( 'type' => 'integer' ),
				'language'     => $lang_prop + array( 'description' => 'Nuevo idioma de este objeto (slug o locale).' ),
				'translations' => array( 'type' => 'object', 'description' => 'Idioma → ID del objeto que es su traducción (0 = desenlazar la de ese idioma). Solo los idiomas que cambian.' ),
			),
		),
		'permission_callback' => $can_object,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$type  = spiral_mcp_pll_type( $input['type'] ?? '' );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$obj = spiral_mcp_pll_object( $type, $input['id'] ?? 0 );
			if ( is_wp_error( $obj ) ) {
				return $obj;
			}
			$id    = (int) $input['id'];
			$group = spiral_mcp_pll_translations( $type, $id );
			$lang  = spiral_mcp_pll_object_language( $type, $id );
			if ( empty( $input['language'] ) && ! isset( $input['translations'] ) ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "language" y/o "translations".' );
			}

			$new_lang = $lang;
			if ( ! empty( $input['language'] ) ) {
				$l = spiral_mcp_pll_language( $input['language'] );
				if ( is_wp_error( $l ) ) {
					return $l;
				}
				$new_lang = $l->slug;
				if ( $new_lang !== $lang && ! empty( $group[ $new_lang ] ) && (int) $group[ $new_lang ] !== $id ) {
					return new WP_Error( 'spiral_mcp_pll_translation_exists', sprintf( 'En su grupo ya hay una traducción en "%s" (ID %d): desenlázala antes ("translations": {"%s": 0}) o cambia el idioma de esa.', $new_lang, $group[ $new_lang ], $new_lang ) );
				}
			}
			if ( ! $new_lang ) {
				return new WP_Error( 'spiral_mcp_pll_no_language', 'Este objeto no tiene idioma: indica "language".' );
			}

			// Grupo nuevo: el actual sin este objeto en su idioma viejo, con los cambios pedidos.
			$new_group = $group;
			if ( $lang ) {
				unset( $new_group[ $lang ] );
			}
			$set_langs = array();
			$touched   = array( $id );
			foreach ( (array) ( $input['translations'] ?? array() ) as $slug => $tr_id ) {
				$l = spiral_mcp_pll_language( $slug );
				if ( is_wp_error( $l ) ) {
					return $l;
				}
				if ( $l->slug === $new_lang ) {
					return new WP_Error( 'spiral_mcp_bad_input', sprintf( '"%s" es el idioma de este mismo objeto: en "translations" van los demás idiomas.', $l->slug ) );
				}
				if ( isset( $new_group[ $l->slug ] ) ) {
					$touched[] = (int) $new_group[ $l->slug ];
				}
				$tr_id = (int) $tr_id;
				if ( ! $tr_id ) {
					unset( $new_group[ $l->slug ] );
					continue;
				}
				$tr = spiral_mcp_pll_object( $type, $tr_id );
				if ( is_wp_error( $tr ) ) {
					return $tr;
				}
				if ( 'post' === $type ? $tr->post_type !== $obj->post_type : $tr->taxonomy !== $obj->taxonomy ) {
					return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'El %d no es del mismo tipo de contenido o taxonomía que el %d.', $tr_id, $id ) );
				}
				if ( ! spiral_mcp_pll_can_edit( $type, $tr_id ) ) {
					return new WP_Error( 'spiral_mcp_forbidden', sprintf( 'No puedes editar el %d.', $tr_id ), array( 'status' => 403 ) );
				}
				$tr_lang = spiral_mcp_pll_object_language( $type, $tr_id );
				if ( $tr_lang && $tr_lang !== $l->slug ) {
					return new WP_Error( 'spiral_mcp_pll_language_mismatch', sprintf( 'El %d está en "%s", no en "%s". Cambia antes su idioma (update-translations con "language") o ponlo en su clave.', $tr_id, $tr_lang, $l->slug ) );
				}
				if ( ! $tr_lang ) {
					$set_langs[ $tr_id ] = $l->slug;
				}
				$new_group[ $l->slug ] = $tr_id;
				$touched               = array_merge( $touched, array( $tr_id ), array_values( spiral_mcp_pll_translations( $type, $tr_id ) ) );
			}
			$new_group[ $new_lang ] = $id;
			$touched                = array_values( array_unique( array_merge( $touched, array_values( $group ) ) ) );

			$snapshot = spiral_mcp_snapshot_take( 'pll_translations', spiral_mcp_pll_make_ref( array( $type => $touched ), $type . ':' . $id . '+' ), spiral_mcp_pll_capture_objects( array( $type => $touched ) ), 'update-translations' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			// Grupos de antes de todos los implicados: los que no entran en el nuevo siguen juntos entre ellos.
			$old_groups = array();
			foreach ( $touched as $member ) {
				$old = spiral_mcp_pll_translations( $type, $member );
				ksort( $old );
				$old_groups[ spiral_mcp_json( $old ) ] = $old;
			}
			// Polylang reutiliza el término del grupo de cualquier miembro: se desenlaza todo antes de rehacer los grupos.
			foreach ( $touched as $member ) {
				PLL()->model->$type->delete_translation( (int) $member );
			}
			if ( $new_lang !== $lang ) {
				'post' === $type ? pll_set_post_language( $id, $new_lang ) : pll_set_term_language( $id, $new_lang );
			}
			foreach ( $set_langs as $tr_id => $slug ) {
				'post' === $type ? pll_set_post_language( $tr_id, $slug ) : pll_set_term_language( $tr_id, $slug );
			}
			spiral_mcp_pll_save_group( $type, $new_group );
			foreach ( $old_groups as $old ) {
				$rest = array_diff( $old, $new_group );
				if ( count( $rest ) > 1 ) {
					spiral_mcp_pll_save_group( $type, $rest );
				}
			}
			return spiral_mcp_pll_translations_out( $type, $id ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 3. Textos sueltos ------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-string-translations', array(
		'label'               => 'Listar textos traducibles',
		'description'         => 'Textos sueltos que Polylang traduce fuera de los contenidos: nombre y descripción del sitio, formatos de fecha y hora, títulos y textos de widgets que se ven en todos los idiomas y los que registran otros plugins (p. ej. Yoast SEO). Para cada uno: nombre, grupo ("context"), texto original y su traducción en cada idioma ("" = sin traducir, se muestra el original). Con "untranslated" y "language", solo los que faltan en ese idioma. Los textos que algunos temas o plugins registran solo dentro de wp-admin no salen aquí.',
		'category'            => 'spiral-mcp-languages',
		'group'               => array( 'settings', 'design' ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'language'     => $lang_prop + array( 'description' => 'Solo la traducción en este idioma.' ),
				'context'      => array( 'type' => 'string', 'description' => 'Solo este grupo (p. ej. "WordPress", "Widget", "wordpress-seo").' ),
				'search'       => array( 'type' => 'string', 'description' => 'Texto en el original o en el nombre.' ),
				'untranslated' => array( 'type' => 'boolean', 'description' => 'Solo los que no tienen traducción (en "language" o en algún idioma).' ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input = array() ) {
			$input     = (array) $input;
			$languages = PLL()->model->get_languages_list();
			if ( ! empty( $input['language'] ) ) {
				$lang = spiral_mcp_pll_language( $input['language'] );
				if ( is_wp_error( $lang ) ) {
					return $lang;
				}
				$languages = array( $lang );
			}
			$mos = array();
			foreach ( $languages as $lang ) {
				$mos[ $lang->slug ] = spiral_mcp_pll_mo( $lang );
			}
			$items    = array();
			$contexts = array();
			foreach ( spiral_mcp_pll_strings() as $original => $s ) {
				$contexts[ $s['context'] ] = true;
				if ( ! empty( $input['context'] ) && 0 !== strcasecmp( $s['context'], (string) $input['context'] ) ) {
					continue;
				}
				if ( ! empty( $input['search'] ) && false === stripos( $original . ' ' . $s['name'], (string) $input['search'] ) ) {
					continue;
				}
				$translations = array();
				foreach ( $mos as $slug => $mo ) {
					$translations[ $slug ] = $mo->translate_if_any( (string) $original );
				}
				if ( ! empty( $input['untranslated'] ) && ! in_array( '', $translations, true ) ) {
					continue;
				}
				$items[] = array(
					'name'         => $s['name'],
					'context'      => $s['context'],
					'original'     => (string) $original,
					'multiline'    => (bool) $s['multiline'],
					'translations' => $translations,
				);
			}
			return array(
				'total'    => count( $items ),
				'contexts' => array_keys( $contexts ),
				'strings'  => $items,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-string-translations', array(
		'label'               => 'Traducir textos sueltos',
		'description'         => 'Guarda traducciones de textos sueltos en un idioma (los de spiral-mcp/list-string-translations: nombre y descripción del sitio, widgets…). Cada elemento de "strings" lleva el texto "original" exacto y su "translation" ("" la borra y se vuelve a ver el original). Polylang sanea como en wp-admin (los títulos de widget sin HTML). Guarda un snapshot de las traducciones de ese idioma ("snapshot_id"). Para cambiar el original (el texto del idioma por defecto) usa la ability del objeto (update-site-settings, update-widget…).',
		'category'            => 'spiral-mcp-languages',
		'group'               => array( 'settings', 'design' ),
		'log'                 => array( 'type' => 'string_translations', 'id' => 'language' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'language', 'strings' ),
			'properties' => array(
				'language' => $lang_prop,
				'strings'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'original', 'translation' ),
						'properties' => array(
							'original'    => array( 'type' => 'string' ),
							'translation' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$lang  = spiral_mcp_pll_language( $input['language'] ?? '' );
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			$registered = spiral_mcp_pll_strings();
			$unknown    = array();
			foreach ( (array) ( $input['strings'] ?? array() ) as $pair ) {
				if ( ! isset( $registered[ (string) ( $pair['original'] ?? '' ) ] ) ) {
					$unknown[] = (string) ( $pair['original'] ?? '' );
				}
			}
			if ( $unknown ) {
				return new WP_Error( 'spiral_mcp_pll_unknown_string', sprintf( 'Textos que Polylang no tiene registrados: "%s". Copia el "original" exacto de spiral-mcp/list-string-translations.', implode( '", "', $unknown ) ) );
			}
			if ( ! has_filter( 'pll_sanitize_string_translation', array( 'PLL_Admin_Strings', 'sanitize_string_translation' ) ) ) {
				PLL_Admin_Strings::init();
			}
			$snapshot = spiral_mcp_snapshot_capture( 'pll_strings', $lang->slug, 'update-string-translations' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$mo      = spiral_mcp_pll_mo( $lang );
			$changed = array();
			foreach ( (array) $input['strings'] as $pair ) {
				$original    = (string) $pair['original'];
				$s           = $registered[ $original ];
				$previous    = $mo->translate_if_any( $original );
				$translation = (string) apply_filters( 'pll_sanitize_string_translation', (string) $pair['translation'], $s['name'], $s['context'], $original, $previous );
				if ( $translation === $previous ) {
					continue;
				}
				if ( '' === $translation ) {
					$mo->delete_entry( $original );
				} else {
					$mo->add_entry( $mo->make_entry( $original, $translation ) );
				}
				$changed[] = array( 'original' => $original, 'translation' => $translation );
			}
			if ( $changed ) {
				$mo->export_to_db( $lang );
			}
			return array(
				'language'    => $lang->slug,
				'changed'     => $changed,
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 4. Menús por idioma ------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/set-menu-locations', array(
		'label'               => 'Asignar menús por idioma',
		'description'         => 'Con Polylang, cada ubicación de menú del tema tiene un menú por idioma. Asigna en un idioma los menús de las ubicaciones que envíes ({"navbar": 12, "dialog-mobile": 12}; 0 = ninguno) sin tocar las demás. Mira las ubicaciones y lo asignado con spiral-mcp/list-menus ("languages" de cada ubicación). Lo normal: un menú por idioma (create-menu) con los enlaces a las páginas de ese idioma, en la misma ubicación. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'design',
		'log'                 => array( 'type' => 'menu_locations', 'id' => 'language' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'language', 'locations' ),
			'properties' => array(
				'language'  => $lang_prop,
				'locations' => array( 'type' => 'object', 'description' => 'Ubicación → ID, slug o nombre del menú (0 = quitar el menú de esa ubicación en ese idioma).' ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$lang  = spiral_mcp_pll_language( $input['language'] ?? '' );
			if ( is_wp_error( $lang ) ) {
				return $lang;
			}
			$map = array();
			foreach ( (array) ( $input['locations'] ?? array() ) as $loc => $menu ) {
				if ( empty( $menu ) ) {
					$map[ (string) $loc ] = 0;
					continue;
				}
				$obj = spiral_mcp_menu_get( $menu );
				if ( is_wp_error( $obj ) ) {
					return $obj;
				}
				$map[ (string) $loc ] = (int) $obj->term_id;
			}
			if ( ! $map ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "locations" al menos una ubicación.' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'pll_menu_locations', 'nav_menus', 'set-menu-locations' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_pll_write_menu_locations( $lang, $map );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$out = array();
			foreach ( spiral_mcp_pll_menu_map() as $loc => $langs ) {
				$out[ $loc ] = (int) ( $langs[ $lang->slug ] ?? 0 );
			}
			return array(
				'language'    => $lang->slug,
				'locations'   => (object) $out,
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/add-language-switcher', array(
		'label'               => 'Añadir el selector de idioma a un menú',
		'description'         => 'Añade a un menú el selector de idioma de Polylang (un elemento que en la web se convierte en un enlace por idioma a la traducción de la página actual). Opciones: "layout" (horizontal = un enlace por idioma; dropdown = desplegable), "show_labels" (names, codes o "" para solo banderas), "show_flags", "hide_current", "hide_if_no_translation" y "force_home" (enlazar siempre a la portada). Añádelo al menú de cada idioma. Guarda un snapshot del menú ("snapshot_id"). Comprueba el resultado con inspect-page.',
		'category'            => 'spiral-mcp-languages',
		'group'               => 'design',
		'log'                 => array( 'type' => 'menu', 'id' => function ( $in, $res ) {
			return $res['menu_id'] ?? ( $in['menu'] ?? '' );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'menu' ),
			'properties' => array(
				'menu'                   => array( 'type' => array( 'integer', 'string' ), 'description' => 'ID, slug o nombre del menú.' ),
				'parent_id'              => array( 'type' => 'integer', 'description' => 'Elemento padre (submenú). Por defecto, en el primer nivel, al final.' ),
				'layout'                 => array( 'type' => 'string', 'enum' => array( 'horizontal', 'dropdown' ), 'default' => 'horizontal' ),
				'show_labels'            => array( 'type' => 'string', 'enum' => array( 'names', 'codes', '' ), 'default' => 'names' ),
				'show_flags'             => array( 'type' => 'boolean', 'default' => false ),
				'hide_current'           => array( 'type' => 'boolean', 'default' => false ),
				'hide_if_no_translation' => array( 'type' => 'boolean', 'default' => false ),
				'force_home'             => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => $can_settings,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$menu  = spiral_mcp_menu_get( $input['menu'] ?? 0 );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$parent = (int) ( $input['parent_id'] ?? 0 );
			if ( $parent && ! spiral_mcp_menu_has_item( $menu->term_id, $parent ) ) {
				return new WP_Error( 'spiral_mcp_bad_parent', sprintf( 'El elemento %d no está en el menú "%s" (ver spiral-mcp/get-menu).', $parent, $menu->name ) );
			}
			foreach ( spiral_mcp_menu_items( $menu->term_id ) as $item ) {
				if ( '#pll_switcher' === $item->url ) {
					return new WP_Error( 'spiral_mcp_pll_switcher_exists', sprintf( 'El menú "%s" ya tiene el selector de idioma (elemento %d). Para cambiarlo, bórralo con delete-menu-item y vuelve a añadirlo.', $menu->name, (int) $item->ID ) );
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'add-language-switcher' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$item_id = wp_update_nav_menu_item( $menu->term_id, 0, array(
				'menu-item-title'     => 'Languages',
				'menu-item-url'       => '#pll_switcher',
				'menu-item-type'      => 'custom',
				'menu-item-object'    => 'custom',
				'menu-item-parent-id' => $parent,
				'menu-item-status'    => 'publish',
			) );
			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}
			// YOOtheme reconoce el selector por el slug "languages…" (quita las clases de elemento actual).
			wp_update_post( array( 'ID' => (int) $item_id, 'post_name' => 'languages' ) );
			$settings = array(
				'layout'                 => (string) ( $input['layout'] ?? 'horizontal' ),
				'show_labels'            => (string) ( $input['show_labels'] ?? 'names' ),
				'show_flags'             => ! empty( $input['show_flags'] ) ? 1 : 0,
				'hide_current'           => ! empty( $input['hide_current'] ) ? 1 : 0,
				'hide_if_no_translation' => ! empty( $input['hide_if_no_translation'] ) ? 1 : 0,
				'force_home'             => ! empty( $input['force_home'] ) ? 1 : 0,
			);
			// Como Polylang al guardar el menú en wp-admin (valida y añade las claves antiguas dropdown y show_names).
			if ( class_exists( '\WP_Syntex\Polylang\Switcher\Fields\Menu' ) && class_exists( '\WP_Syntex\Polylang\Switcher\Settings\Menu' ) ) {
				$validated = \WP_Syntex\Polylang\Switcher\Fields\Menu::filter( new \WP_Syntex\Polylang\Switcher\Settings\Menu( $settings ) );
				$settings  = \WP_Syntex\Polylang\Switcher\Fields\Menu::add_legacy_settings( $validated );
			} else {
				$settings += array( 'dropdown' => 'dropdown' === $settings['layout'] ? 1 : 0, 'show_names' => '' !== $settings['show_labels'] ? 1 : 0 );
			}
			update_post_meta( (int) $item_id, '_pll_menu_item', $settings );
			return array(
				'menu_id'     => (int) $menu->term_id,
				'item_id'     => (int) $item_id,
				'settings'    => $settings,
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
