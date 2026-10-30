<?php
/**
 * Tipos de contenido y taxonomías personalizados de Custom Post Type UI (Fase 14):
 * listar, leer, crear, editar, renombrar y borrar los tipos y taxonomías que
 * CPTUI guarda en sus opciones. Solo se registran si CPTUI está activo.
 *
 * Se escriben las opciones cptui_post_types / cptui_taxonomies con la misma
 * estructura que guarda CPTUI, disparando sus hooks (cptui_before_update_*,
 * cptui_pre_save_*, cptui_*_update_save, cptui_after_update_*, los de borrado)
 * y su transient de flush. No se usan cptui_update_post_type() ni
 * cptui_update_taxonomy(): dependen de $_POST (descripción, renombrado,
 * override) y de manage_options, que el rol del agente no tiene.
 *
 * Tras guardar, el tipo o la taxonomía se registra en la misma petición y se
 * regeneran las reglas de reescritura, así la respuesta ya trae el objeto
 * registrado y las URLs funcionan sin esperar a que alguien entre en wp-admin.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** ¿Está Custom Post Type UI activo? */
function spiral_mcp_cptui_active() {
	return defined( 'CPTUI_VERSION' ) && function_exists( 'cptui_register_single_post_type' ) && function_exists( 'cptui_register_single_taxonomy' );
}

/** Opción cptui_post_types (con el filtro de CPTUI). */
function spiral_mcp_cptui_post_types() {
	$data = function_exists( 'cptui_get_post_type_data' ) ? cptui_get_post_type_data() : get_option( 'cptui_post_types', array() );
	return is_array( $data ) ? $data : array();
}

/** Opción cptui_taxonomies (con el filtro de CPTUI). */
function spiral_mcp_cptui_taxonomies() {
	$data = function_exists( 'cptui_get_taxonomy_data' ) ? cptui_get_taxonomy_data() : get_option( 'cptui_taxonomies', array() );
	return is_array( $data ) ? $data : array();
}

/** Booleano en el formato de CPTUI ("true"/"false" en texto). */
function spiral_mcp_cptui_bool( $value ) {
	if ( is_string( $value ) ) {
		$value = in_array( strtolower( trim( $value ) ), array( 'true', '1', 'yes', 'on' ), true );
	}
	return $value ? 'true' : 'false';
}

/** Booleano de CPTUI a PHP. */
function spiral_mcp_cptui_is_true( $value ) {
	return in_array( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : strtolower( trim( (string) $value ) ), array( 'true', '1' ), true );
}

/**
 * Valores por defecto de un tipo de contenido de CPTUI, iguales a los del formulario
 * «Añadir tipo» de wp-admin. Claves en el formato que guarda la opción.
 */
function spiral_mcp_cptui_post_type_defaults() {
	return array(
		'name'                  => '',
		'label'                 => '',
		'singular_label'        => '',
		'description'           => '',
		'public'                => 'true',
		'publicly_queryable'    => 'true',
		'show_ui'               => 'true',
		'show_in_nav_menus'     => 'true',
		'delete_with_user'      => 'false',
		'show_in_rest'          => 'true',
		'rest_base'             => '',
		'rest_controller_class' => '',
		'rest_namespace'        => '',
		'has_archive'           => 'false',
		'has_archive_string'    => '',
		'exclude_from_search'   => 'false',
		'capability_type'       => 'post',
		'hierarchical'          => 'false',
		'can_export'            => 'true',
		'rewrite'               => 'true',
		'rewrite_slug'          => '',
		'rewrite_withfront'     => 'true',
		'query_var'             => 'true',
		'query_var_slug'        => '',
		'menu_position'         => '',
		'show_in_menu'          => 'true',
		'show_in_menu_string'   => '',
		'menu_icon'             => null,
		'register_meta_box_cb'  => null,
		'supports'              => array( 'title', 'editor', 'thumbnail' ),
		'taxonomies'            => array(),
		'labels'                => array(),
		'custom_supports'       => '',
		'enter_title_here'      => '',
	);
}

/** Valores por defecto de una taxonomía de CPTUI (formulario «Añadir taxonomía»). */
function spiral_mcp_cptui_taxonomy_defaults() {
	return array(
		'name'                  => '',
		'label'                 => '',
		'singular_label'        => '',
		'description'           => '',
		'public'                => 'true',
		'publicly_queryable'    => 'true',
		'hierarchical'          => 'false',
		'show_ui'               => 'true',
		'show_in_menu'          => 'true',
		'show_in_nav_menus'     => 'true',
		'query_var'             => 'true',
		'query_var_slug'        => '',
		'rewrite'               => 'true',
		'rewrite_slug'          => '',
		'rewrite_withfront'     => '1',
		'rewrite_hierarchical'  => '0',
		'show_admin_column'     => 'false',
		'show_in_rest'          => 'true',
		'show_tagcloud'         => 'false',
		'sort'                  => 'false',
		'show_in_quick_edit'    => '',
		'rest_base'             => '',
		'rest_controller_class' => '',
		'rest_namespace'        => '',
		'labels'                => array(),
		'meta_box_cb'           => '',
		'default_term'          => '',
		'object_types'          => array(),
	);
}

/** Claves booleanas de un tipo de contenido (se guardan como "true"/"false"). */
function spiral_mcp_cptui_post_type_bools() {
	return array( 'public', 'publicly_queryable', 'show_ui', 'show_in_nav_menus', 'delete_with_user', 'show_in_rest', 'has_archive', 'exclude_from_search', 'hierarchical', 'can_export', 'rewrite', 'rewrite_withfront', 'query_var', 'show_in_menu' );
}

/** Claves booleanas de una taxonomía. rewrite_withfront y rewrite_hierarchical se guardan como "1"/"0" (así lo hace CPTUI). */
function spiral_mcp_cptui_taxonomy_bools() {
	return array( 'public', 'publicly_queryable', 'hierarchical', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'query_var', 'rewrite', 'rewrite_withfront', 'rewrite_hierarchical', 'show_admin_column', 'show_in_rest', 'show_tagcloud', 'sort', 'show_in_quick_edit' );
}

/** Claves de texto editables de un tipo de contenido. */
function spiral_mcp_cptui_post_type_strings() {
	return array( 'label', 'singular_label', 'description', 'rest_base', 'rest_controller_class', 'rest_namespace', 'has_archive_string', 'capability_type', 'rewrite_slug', 'query_var_slug', 'menu_position', 'show_in_menu_string', 'menu_icon', 'register_meta_box_cb', 'custom_supports', 'enter_title_here' );
}

/** Claves de texto editables de una taxonomía. */
function spiral_mcp_cptui_taxonomy_strings() {
	return array( 'label', 'singular_label', 'description', 'query_var_slug', 'rewrite_slug', 'rest_base', 'rest_controller_class', 'rest_namespace', 'meta_box_cb', 'default_term' );
}

/** Funciones ("supports") que admite un tipo de contenido en CPTUI. */
function spiral_mcp_cptui_supports() {
	return array( 'title', 'editor', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'author', 'page-attributes', 'post-formats', 'none' );
}

/** Etiquetas que CPTUI deja rellenar en un tipo de contenido (además de label y singular_label). */
function spiral_mcp_cptui_post_type_label_keys() {
	return array( 'menu_name', 'all_items', 'add_new', 'add_new_item', 'edit_item', 'new_item', 'view_item', 'view_items', 'search_items', 'not_found', 'not_found_in_trash', 'parent', 'featured_image', 'set_featured_image', 'remove_featured_image', 'use_featured_image', 'archives', 'insert_into_item', 'uploaded_to_this_item', 'filter_items_list', 'filter_by_date', 'items_list_navigation', 'items_list', 'attributes', 'name_admin_bar', 'item_published', 'item_published_privately', 'item_reverted_to_draft', 'item_trashed', 'item_scheduled', 'item_updated', 'enter_title_here', 'template_name' );
}

/** Etiquetas que CPTUI deja rellenar en una taxonomía. */
function spiral_mcp_cptui_taxonomy_label_keys() {
	return array( 'menu_name', 'all_items', 'edit_item', 'view_item', 'update_item', 'add_new_item', 'new_item_name', 'parent_item', 'parent_item_colon', 'search_items', 'popular_items', 'separate_items_with_commas', 'add_or_remove_items', 'choose_from_most_used', 'not_found', 'no_terms', 'filter_by_item', 'items_list_navigation', 'items_list', 'back_to_items', 'name_field_description', 'parent_field_description', 'slug_field_description', 'desc_field_description', 'template_name' );
}

/** Slug válido para CPTUI: minúsculas, dígitos, guiones y guiones bajos; 20 caracteres (tipos) o 32 (taxonomías). */
function spiral_mcp_cptui_check_slug( $slug, $what = 'post_type' ) {
	$slug = strtolower( trim( (string) $slug ) );
	$max  = 'taxonomy' === $what ? 32 : 20;
	if ( '' === $slug ) {
		return new WP_Error( 'spiral_mcp_bad_input', 'Indica "name": el slug interno (p. ej. "proyecto", "testimonio").' );
	}
	if ( false !== strpos( $slug, "'" ) || false !== strpos( $slug, '"' ) ) {
		return new WP_Error( 'spiral_mcp_bad_slug', 'El slug no puede llevar comillas.' );
	}
	if ( ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
		return new WP_Error( 'spiral_mcp_bad_slug', sprintf( 'El slug "%s" solo puede tener minúsculas, dígitos, guiones y guiones bajos (sin espacios ni acentos).', $slug ) );
	}
	if ( strlen( $slug ) > $max ) {
		return new WP_Error( 'spiral_mcp_bad_slug', sprintf( 'El slug "%s" supera los %d caracteres que permite WordPress.', $slug, $max ) );
	}
	return $slug;
}

/**
 * ¿Se puede usar este slug para un tipo nuevo? Mismas comprobaciones que CPTUI
 * (reservados, ya registrado por CPTUI u otro plugin, slug de una página) y
 * su filtro cptui_post_type_slug_exists.
 */
function spiral_mcp_cptui_post_type_slug_free( $slug ) {
	$saved = spiral_mcp_cptui_post_types();
	if ( isset( $saved[ $slug ] ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'CPTUI ya tiene el tipo "%s": edítalo con spiral-mcp/update-custom-post-type.', $slug ) );
	}
	if ( function_exists( 'cptui_reserved_post_types' ) && in_array( $slug, cptui_reserved_post_types(), true ) ) {
		return new WP_Error( 'spiral_mcp_cptui_reserved', sprintf( '"%s" es un nombre reservado de WordPress: elige otro slug.', $slug ) );
	}
	if ( post_type_exists( $slug ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'El tipo "%s" ya lo registra el tema u otro plugin: CPTUI no puede registrarlo otra vez.', $slug ) );
	}
	if ( apply_filters( 'cptui_post_type_slug_exists', false, $slug, $saved ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'El slug "%s" está en uso según las comprobaciones de CPTUI.', $slug ) );
	}
	$page = get_page_by_path( $slug );
	if ( $page instanceof WP_Post ) {
		return new WP_Error( 'spiral_mcp_cptui_page_slug', sprintf( 'Ya hay una página con el slug "%s" (ID %d): un tipo con ese nombre tendría URLs en conflicto. Elige otro slug o cambia el de la página.', $slug, $page->ID ) );
	}
	return true;
}

/** ¿Se puede usar este slug para una taxonomía nueva? Comprobaciones de CPTUI. */
function spiral_mcp_cptui_taxonomy_slug_free( $slug ) {
	$saved = spiral_mcp_cptui_taxonomies();
	if ( isset( $saved[ $slug ] ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'CPTUI ya tiene la taxonomía "%s": edítala con spiral-mcp/update-custom-taxonomy.', $slug ) );
	}
	if ( function_exists( 'cptui_reserved_taxonomies' ) && in_array( $slug, cptui_reserved_taxonomies(), true ) ) {
		return new WP_Error( 'spiral_mcp_cptui_reserved', sprintf( '"%s" es un nombre reservado de WordPress (variable de consulta): elige otro slug.', $slug ) );
	}
	if ( taxonomy_exists( $slug ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'La taxonomía "%s" ya la registra el tema u otro plugin: CPTUI no puede registrarla otra vez.', $slug ) );
	}
	if ( apply_filters( 'cptui_taxonomy_slug_exists', false, $slug, $saved ) ) {
		return new WP_Error( 'spiral_mcp_cptui_exists', sprintf( 'El slug "%s" está en uso según las comprobaciones de CPTUI.', $slug ) );
	}
	return true;
}

/**
 * Mezcla la entrada del agente sobre un tipo guardado (o los valores por defecto)
 * y valida. Devuelve el array en el formato de la opción, o WP_Error.
 */
function spiral_mcp_cptui_post_type_prepare( array $in, ?array $current = null, &$warnings = array() ) {
	$data = $current ? array_merge( spiral_mcp_cptui_post_type_defaults(), $current ) : spiral_mcp_cptui_post_type_defaults();
	if ( isset( $in['name'] ) ) {
		$data['name'] = (string) $in['name'];
	}

	foreach ( spiral_mcp_cptui_post_type_bools() as $key ) {
		if ( array_key_exists( $key, $in ) ) {
			$data[ $key ] = spiral_mcp_cptui_bool( $in[ $key ] );
		}
	}
	foreach ( spiral_mcp_cptui_post_type_strings() as $key ) {
		if ( array_key_exists( $key, $in ) ) {
			$value = null === $in[ $key ] ? '' : sanitize_text_field( (string) $in[ $key ] );
			if ( in_array( $key, array( 'label', 'singular_label' ), true ) ) {
				$value = str_replace( '"', '', $value );
			}
			$data[ $key ] = $value;
		}
	}
	if ( array_key_exists( 'description', $in ) ) {
		$data['description'] = wp_kses_post( (string) $in['description'] );
	}
	if ( '' === $data['label'] ) {
		$data['label'] = ucwords( str_replace( '_', ' ', $data['name'] ) );
	}
	if ( '' === $data['singular_label'] ) {
		$data['singular_label'] = $data['label'];
	}
	if ( '' !== $data['menu_position'] && ! ctype_digit( (string) $data['menu_position'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', '"menu_position" debe ser un entero (p. ej. 20 debajo de Páginas) o vacío.' );
	}
	if ( '' !== $data['capability_type'] && ! preg_match( '/^[a-z_]+(\s*,\s*[a-z_]+)?$/', $data['capability_type'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', '"capability_type" debe ser "post", "page" o "singular,plural" (p. ej. "proyecto,proyectos").' );
	}
	if ( '' !== $data['menu_icon'] && null !== $data['menu_icon'] && 0 !== strpos( $data['menu_icon'], 'dashicons-' ) && ! preg_match( '#^(https?://|data:image/svg\+xml)#', $data['menu_icon'] ) ) {
		$warnings[] = sprintf( '"menu_icon" suele ser un dashicon ("dashicons-portfolio") o una URL de imagen: "%s" puede no mostrarse.', $data['menu_icon'] );
	}
	if ( '' === (string) $data['menu_icon'] ) {
		$data['menu_icon'] = null;
	}
	if ( '' === (string) $data['register_meta_box_cb'] ) {
		$data['register_meta_box_cb'] = null;
	}
	if ( array_key_exists( 'supports', $in ) ) {
		$supports = array_values( array_unique( array_map( 'strval', (array) $in['supports'] ) ) );
		$bad      = array_diff( $supports, spiral_mcp_cptui_supports() );
		if ( $bad ) {
			return new WP_Error( 'spiral_mcp_bad_input', sprintf( '"supports" no admite: %s. Válidos: %s (o "custom_supports" con nombres propios separados por comas).', implode( ', ', $bad ), implode( ', ', spiral_mcp_cptui_supports() ) ) );
		}
		if ( in_array( 'none', $supports, true ) && count( $supports ) > 1 ) {
			$warnings[] = '"supports" lleva "none" junto a otras funciones: CPTUI registra el tipo sin ninguna.';
		}
		$data['supports'] = $supports;
	}
	if ( array_key_exists( 'taxonomies', $in ) ) {
		$taxes = array_values( array_unique( array_map( 'strval', (array) $in['taxonomies'] ) ) );
		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'La taxonomía "%s" no existe (ver spiral-mcp/list-taxonomies o créala con spiral-mcp/create-custom-taxonomy).', $tax ) );
			}
		}
		$data['taxonomies'] = $taxes;
	}
	if ( array_key_exists( 'labels', $in ) ) {
		$labels = array();
		foreach ( (array) $in['labels'] as $key => $label ) {
			if ( ! in_array( $key, spiral_mcp_cptui_post_type_label_keys(), true ) ) {
				$warnings[] = sprintf( 'La etiqueta "%s" no es de las que admite CPTUI y se ignora.', $key );
				continue;
			}
			$label = trim( str_replace( '"', '', sanitize_text_field( (string) $label ) ) );
			if ( '' !== $label ) {
				$labels[ $key ] = $label;
			}
		}
		if ( isset( $labels['parent'] ) ) {
			$labels['parent_item_colon'] = $labels['parent'];
		}
		$data['labels'] = $labels;
	}
	if ( 'true' === $data['rewrite'] && '' !== $data['rewrite_slug'] && ! preg_match( '#^[a-z0-9_/-]+$#', $data['rewrite_slug'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', '"rewrite_slug" solo puede tener minúsculas, dígitos, guiones, guiones bajos y barras.' );
	}
	if ( 'false' === $data['public'] && 'true' === $data['show_ui'] ) {
		$warnings[] = 'Con "public": false el tipo no tiene URL pública ni aparece en spiral-mcp/list-post-types (solo lista los públicos); sí se ve en wp-admin.';
	}
	return $data;
}

/** Mezcla la entrada del agente sobre una taxonomía guardada (o los valores por defecto) y valida. */
function spiral_mcp_cptui_taxonomy_prepare( array $in, ?array $current = null, &$warnings = array() ) {
	$data = $current ? array_merge( spiral_mcp_cptui_taxonomy_defaults(), $current ) : spiral_mcp_cptui_taxonomy_defaults();
	if ( isset( $in['name'] ) ) {
		$data['name'] = (string) $in['name'];
	}

	foreach ( spiral_mcp_cptui_taxonomy_bools() as $key ) {
		if ( array_key_exists( $key, $in ) ) {
			$value = spiral_mcp_cptui_bool( $in[ $key ] );
			if ( in_array( $key, array( 'rewrite_withfront', 'rewrite_hierarchical' ), true ) ) {
				$value = 'true' === $value ? '1' : '0';
			}
			$data[ $key ] = $value;
		}
	}
	foreach ( spiral_mcp_cptui_taxonomy_strings() as $key ) {
		if ( array_key_exists( $key, $in ) ) {
			$value = null === $in[ $key ] ? '' : sanitize_text_field( (string) $in[ $key ] );
			if ( in_array( $key, array( 'label', 'singular_label' ), true ) ) {
				$value = str_replace( '"', '', $value );
			}
			$data[ $key ] = $value;
		}
	}
	if ( '' === $data['label'] ) {
		$data['label'] = ucwords( str_replace( '_', ' ', $data['name'] ) );
	}
	if ( '' === $data['singular_label'] ) {
		$data['singular_label'] = $data['label'];
	}
	if ( array_key_exists( 'object_types', $in ) ) {
		$types = array_values( array_unique( array_map( 'strval', (array) $in['object_types'] ) ) );
		foreach ( $types as $type ) {
			if ( ! post_type_exists( $type ) && ! isset( spiral_mcp_cptui_post_types()[ $type ] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'El tipo de contenido "%s" no existe (ver spiral-mcp/list-post-types).', $type ) );
			}
		}
		$data['object_types'] = $types;
	}
	if ( empty( $data['object_types'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', 'Indica "object_types": los tipos de contenido a los que se asigna la taxonomía (p. ej. ["post"] o ["proyecto"]).' );
	}
	if ( array_key_exists( 'labels', $in ) ) {
		$labels = array();
		foreach ( (array) $in['labels'] as $key => $label ) {
			if ( ! in_array( $key, spiral_mcp_cptui_taxonomy_label_keys(), true ) ) {
				$warnings[] = sprintf( 'La etiqueta "%s" no es de las que admite CPTUI y se ignora.', $key );
				continue;
			}
			$label = trim( str_replace( '"', '', sanitize_text_field( (string) $label ) ) );
			if ( '' !== $label ) {
				$labels[ $key ] = $label;
			}
		}
		$data['labels'] = $labels;
	}
	if ( 'true' === $data['rewrite'] && '' !== $data['rewrite_slug'] && ! preg_match( '#^[a-z0-9_/-]+$#', $data['rewrite_slug'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_input', '"rewrite_slug" solo puede tener minúsculas, dígitos, guiones, guiones bajos y barras.' );
	}
	if ( 'false' === strtolower( (string) $data['meta_box_cb'] ) ) {
		$data['meta_box_cb'] = 'false';
	}
	return $data;
}

/**
 * Guarda la opción cptui_post_types con un tipo (o sin él, si $entry es null),
 * disparando los hooks de CPTUI. Devuelve true o WP_Error.
 */
function spiral_mcp_cptui_post_types_write( $slug, $entry ) {
	$all = spiral_mcp_cptui_post_types();
	if ( null === $entry ) {
		do_action( 'cptui_before_delete_post_type', array( 'name' => $slug ) );
		unset( $all[ $slug ] );
		$success = apply_filters( 'cptui_post_type_delete_type', false, $all, array( 'name' => $slug ) );
		if ( false === $success ) {
			update_option( 'cptui_post_types', $all );
		}
		do_action( 'cptui_after_delete_post_type', array( 'name' => $slug ) );
	} else {
		do_action( 'cptui_before_update_post_type', array( 'cpt_custom_post_type' => $entry ) );
		$all[ $slug ] = $entry;
		$all          = apply_filters( 'cptui_pre_save_post_type', $all, $slug );
		$success      = apply_filters( 'cptui_post_type_update_save', false, $all, array( 'cpt_custom_post_type' => $entry ) );
		if ( false === $success ) {
			update_option( 'cptui_post_types', $all );
		}
		do_action( 'cptui_after_update_post_type', array( 'cpt_custom_post_type' => $entry ) );
	}
	set_transient( 'cptui_flush_rewrite_rules', 'true', 5 * MINUTE_IN_SECONDS );
	return true;
}

/** Guarda la opción cptui_taxonomies con una taxonomía (o sin ella), disparando los hooks de CPTUI. */
function spiral_mcp_cptui_taxonomies_write( $slug, $entry ) {
	$all = spiral_mcp_cptui_taxonomies();
	if ( null === $entry ) {
		do_action( 'cptui_before_delete_taxonomy', array( 'name' => $slug ) );
		unset( $all[ $slug ] );
		$success = apply_filters( 'cptui_taxonomy_delete_tax', false, $all, array( 'name' => $slug ) );
		if ( false === $success ) {
			update_option( 'cptui_taxonomies', $all );
		}
		delete_option( "default_term_{$slug}" );
		do_action( 'cptui_after_delete_taxonomy', array( 'name' => $slug ) );
	} else {
		do_action( 'cptui_before_update_taxonomy', array( 'cpt_custom_tax' => $entry ) );
		// CPTUI borra la opción del término por defecto al guardar para que register_taxonomy() lo recree.
		delete_option( "default_term_{$slug}" );
		$all[ $slug ] = $entry;
		$all          = apply_filters( 'cptui_pre_save_taxonomy', $all, $slug );
		$success      = apply_filters( 'cptui_taxonomy_update_save', false, $all, array( 'cpt_custom_tax' => $entry ) );
		if ( false === $success ) {
			update_option( 'cptui_taxonomies', $all );
		}
		do_action( 'cptui_after_update_taxonomy', array( 'cpt_custom_tax' => $entry ) );
	}
	set_transient( 'cptui_flush_rewrite_rules', 'true', 5 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * Registra (o vuelve a registrar) el tipo en esta misma petición y regenera las
 * reglas de reescritura, como hace CPTUI en el siguiente admin_init.
 */
function spiral_mcp_cptui_register_post_type_now( array $entry ) {
	if ( post_type_exists( $entry['name'] ) ) {
		unregister_post_type( $entry['name'] );
	}
	cptui_register_single_post_type( $entry );
	spiral_mcp_cptui_flush();
}

/** Registra (o vuelve a registrar) la taxonomía en esta misma petición y regenera las reglas. */
function spiral_mcp_cptui_register_taxonomy_now( array $entry ) {
	if ( taxonomy_exists( $entry['name'] ) ) {
		unregister_taxonomy( $entry['name'] );
	}
	cptui_register_single_taxonomy( $entry );
	spiral_mcp_cptui_flush();
}

/** Regenera las reglas de reescritura y borra el transient de CPTUI (ya está hecho). */
function spiral_mcp_cptui_flush() {
	flush_rewrite_rules( false );
	delete_transient( 'cptui_flush_rewrite_rules' );
}

/** Número de contenidos de un tipo (todos los estados salvo auto-draft), aunque el tipo no esté registrado. */
function spiral_mcp_cptui_post_count( $post_type ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $post_type ) );
}

/** Número de términos de una taxonomía, aunque no esté registrada. */
function spiral_mcp_cptui_term_count( $taxonomy ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
}

/** Salida de un tipo de contenido de CPTUI: lo guardado más lo que WordPress tiene registrado. */
function spiral_mcp_cptui_post_type_out( $slug, array $entry, $full = false ) {
	$obj = get_post_type_object( $slug );
	$out = array(
		'name'           => $slug,
		'label'          => (string) $entry['label'],
		'singular_label' => (string) $entry['singular_label'],
		'description'    => (string) ( $entry['description'] ?? '' ),
		'public'         => spiral_mcp_cptui_is_true( $entry['public'] ?? 'true' ),
		'hierarchical'   => spiral_mcp_cptui_is_true( $entry['hierarchical'] ?? 'false' ),
		'has_archive'    => spiral_mcp_cptui_is_true( $entry['has_archive'] ?? 'false' ),
		'show_in_rest'   => spiral_mcp_cptui_is_true( $entry['show_in_rest'] ?? 'false' ),
		'supports'       => array_values( (array) ( $entry['supports'] ?? array() ) ),
		'taxonomies'     => $obj ? array_values( get_object_taxonomies( $slug ) ) : array_values( (array) ( $entry['taxonomies'] ?? array() ) ),
		'registered'     => (bool) $obj,
		'count'          => spiral_mcp_cptui_post_count( $slug ),
		'archive_url'    => $obj && $obj->has_archive ? wp_make_link_relative( (string) get_post_type_archive_link( $slug ) ) : null,
		'editable'       => in_array( $slug, spiral_mcp_post_types(), true ),
	);
	if ( $full ) {
		$settings = $entry;
		foreach ( spiral_mcp_cptui_post_type_bools() as $key ) {
			$settings[ $key ] = spiral_mcp_cptui_is_true( $entry[ $key ] ?? 'false' );
		}
		$settings['supports']   = array_values( (array) ( $entry['supports'] ?? array() ) );
		$settings['taxonomies'] = array_values( (array) ( $entry['taxonomies'] ?? array() ) );
		$settings['labels']     = (object) (array) ( $entry['labels'] ?? array() );
		unset( $settings['name'] );
		$out['settings']   = $settings;
		$out['registered_args'] = $obj ? array(
			'labels'              => (array) $obj->labels,
			'rewrite'             => $obj->rewrite,
			'query_var'           => $obj->query_var,
			'capability_type'     => $obj->capability_type,
			'menu_position'       => $obj->menu_position,
			'menu_icon'           => $obj->menu_icon,
			'show_in_menu'        => $obj->show_in_menu,
			'exclude_from_search' => (bool) $obj->exclude_from_search,
			'publicly_queryable'  => (bool) $obj->publicly_queryable,
			'rest_base'           => $obj->rest_base,
		) : null;
	}
	return $out;
}

/** Salida de una taxonomía de CPTUI. */
function spiral_mcp_cptui_taxonomy_out( $slug, array $entry, $full = false ) {
	$obj = get_taxonomy( $slug );
	$out = array(
		'name'           => $slug,
		'label'          => (string) $entry['label'],
		'singular_label' => (string) $entry['singular_label'],
		'description'    => (string) ( $entry['description'] ?? '' ),
		'object_types'   => $obj ? array_values( (array) $obj->object_type ) : array_values( (array) ( $entry['object_types'] ?? array() ) ),
		'public'         => spiral_mcp_cptui_is_true( $entry['public'] ?? 'true' ),
		'hierarchical'   => spiral_mcp_cptui_is_true( $entry['hierarchical'] ?? 'false' ),
		'show_in_rest'   => spiral_mcp_cptui_is_true( $entry['show_in_rest'] ?? 'false' ),
		'registered'     => (bool) $obj,
		'count'          => spiral_mcp_cptui_term_count( $slug ),
	);
	if ( $full ) {
		$settings = $entry;
		foreach ( spiral_mcp_cptui_taxonomy_bools() as $key ) {
			$settings[ $key ] = spiral_mcp_cptui_is_true( $entry[ $key ] ?? 'false' );
		}
		$settings['object_types'] = array_values( (array) ( $entry['object_types'] ?? array() ) );
		$settings['labels']       = (object) (array) ( $entry['labels'] ?? array() );
		unset( $settings['name'] );
		$out['settings']        = $settings;
		$out['registered_args'] = $obj ? array(
			'labels'            => (array) $obj->labels,
			'rewrite'           => $obj->rewrite,
			'query_var'         => $obj->query_var,
			'show_admin_column' => (bool) $obj->show_admin_column,
			'show_in_menu'      => (bool) $obj->show_in_menu,
			'default_term'      => $obj->default_term,
			'rest_base'         => $obj->rest_base,
		) : null;
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Estado de un tipo de contenido de CPTUI (la entrada guardada). null = no existía. */
function spiral_mcp_cptui_post_type_capture( $ref ) {
	$all = spiral_mcp_cptui_post_types();
	return isset( $all[ $ref ] ) ? $all[ $ref ] : null;
}

/** Restaura un tipo de CPTUI (lo recrea si se borró; lo quita si no existía). */
function spiral_mcp_cptui_post_type_restore( $ref, $data ) {
	if ( null === $data ) {
		spiral_mcp_cptui_post_types_write( $ref, null );
		if ( post_type_exists( $ref ) ) {
			unregister_post_type( $ref );
		}
		spiral_mcp_cptui_flush();
		return array( 'post_type' => $ref, 'registered' => false );
	}
	$data['name'] = $ref;
	spiral_mcp_cptui_post_types_write( $ref, $data );
	spiral_mcp_cptui_register_post_type_now( $data );
	return array( 'post_type' => $ref, 'registered' => post_type_exists( $ref ) );
}

/** Estado de una taxonomía de CPTUI. */
function spiral_mcp_cptui_taxonomy_capture( $ref ) {
	$all = spiral_mcp_cptui_taxonomies();
	return isset( $all[ $ref ] ) ? $all[ $ref ] : null;
}

/** Restaura una taxonomía de CPTUI. */
function spiral_mcp_cptui_taxonomy_restore( $ref, $data ) {
	if ( null === $data ) {
		spiral_mcp_cptui_taxonomies_write( $ref, null );
		if ( taxonomy_exists( $ref ) ) {
			unregister_taxonomy( $ref );
		}
		spiral_mcp_cptui_flush();
		return array( 'taxonomy' => $ref, 'registered' => false );
	}
	$data['name'] = $ref;
	spiral_mcp_cptui_taxonomies_write( $ref, $data );
	spiral_mcp_cptui_register_taxonomy_now( $data );
	return array( 'taxonomy' => $ref, 'registered' => taxonomy_exists( $ref ) );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_cptui_active() ) {
		return $kinds;
	}
	$kinds['cptui_post_type'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => 'spiral_mcp_cptui_post_type_capture',
		'restore' => 'spiral_mcp_cptui_post_type_restore',
	);
	$kinds['cptui_taxonomy']  = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => 'spiral_mcp_cptui_taxonomy_capture',
		'restore' => 'spiral_mcp_cptui_taxonomy_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! spiral_mcp_cptui_active() ) {
		return;
	}
	wp_register_ability_category( 'spiral-mcp-cptui', array(
		'label'       => 'Tipos de contenido Spiral-MCP',
		'description' => 'Tipos de contenido y taxonomías personalizados de Custom Post Type UI.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_cptui_active() ) {
		return;
	}

	// Crear un tipo de contenido es estructura del sitio, como los ajustes: grupo "settings" y la
	// capacidad nativa de los ajustes del tema (CPTUI exige manage_options, que el agente no tiene).
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	$bool = function ( $desc ) {
		return array( 'type' => 'boolean', 'description' => $desc );
	};
	$post_type_props = array(
		'label'               => array( 'type' => 'string', 'description' => 'Nombre en plural, como se ve en el menú de wp-admin (p. ej. "Proyectos").' ),
		'singular_label'      => array( 'type' => 'string', 'description' => 'Nombre en singular ("Proyecto").' ),
		'description'         => array( 'type' => 'string' ),
		'public'              => $bool( 'Visible en la web y en wp-admin (true por defecto). false = solo interno.' ),
		'publicly_queryable'  => $bool( 'Se puede consultar por URL (true).' ),
		'show_ui'             => $bool( 'Tiene pantalla en wp-admin (true).' ),
		'show_in_menu'        => $bool( 'Aparece en el menú lateral de wp-admin (true).' ),
		'show_in_menu_string' => array( 'type' => 'string', 'description' => 'Para colgarlo de otro menú: slug de la página padre (p. ej. "edit.php?post_type=page" o "tools.php").' ),
		'show_in_nav_menus'   => $bool( 'Se puede añadir a los menús de navegación (true).' ),
		'show_in_rest'        => $bool( 'Editor de bloques y API REST (true). Elementor y YOOtheme lo necesitan.' ),
		'rest_base'           => array( 'type' => 'string', 'description' => 'Ruta en la API REST (por defecto, el slug).' ),
		'has_archive'         => $bool( 'Página de archivo en /slug/ con el listado (false por defecto).' ),
		'has_archive_string'  => array( 'type' => 'string', 'description' => 'Slug del archivo si no es el del tipo (p. ej. "portfolio").' ),
		'exclude_from_search' => $bool( 'Fuera de los resultados de búsqueda del sitio (false).' ),
		'capability_type'     => array( 'type' => 'string', 'description' => '"post" (por defecto) o "page": qué permisos se aplican. "singular,plural" crea capacidades propias que ningún rol tiene todavía.' ),
		'hierarchical'        => $bool( 'Con páginas madre e hijas, como las páginas (false = como las entradas).' ),
		'can_export'          => $bool( 'Incluido en Herramientas > Exportar (true).' ),
		'delete_with_user'    => $bool( 'Borrar sus contenidos al borrar a su autor (false).' ),
		'rewrite'             => $bool( 'URLs amigables (true).' ),
		'rewrite_slug'        => array( 'type' => 'string', 'description' => 'Base de las URLs si no es el slug (p. ej. "trabajos" → /trabajos/mi-proyecto/).' ),
		'rewrite_withfront'   => $bool( 'Anteponer la base de los enlaces permanentes (true).' ),
		'query_var'           => $bool( 'Variable de consulta ?slug=… (true).' ),
		'query_var_slug'      => array( 'type' => 'string' ),
		'menu_position'       => array( 'type' => array( 'string', 'integer' ), 'description' => 'Posición en el menú de wp-admin: 5 debajo de Entradas, 20 debajo de Páginas, 25 debajo de Comentarios. Vacío = al final.' ),
		'menu_icon'           => array( 'type' => 'string', 'description' => 'Dashicon ("dashicons-portfolio", "dashicons-groups", "dashicons-format-quote"…) o URL de una imagen.' ),
		'supports'            => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => spiral_mcp_cptui_supports() ), 'description' => 'Funciones del editor: title, editor, thumbnail (imagen destacada), excerpt, custom-fields, comments, revisions, author, page-attributes (orden y padre), post-formats, trackbacks. Por defecto title, editor y thumbnail. Reemplaza la lista.' ),
		'custom_supports'     => array( 'type' => 'string', 'description' => 'Funciones propias de otros plugins, separadas por comas.' ),
		'taxonomies'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Taxonomías existentes que se asignan al tipo (p. ej. ["category", "post_tag"]). Reemplaza la lista. Para una taxonomía propia, spiral-mcp/create-custom-taxonomy con "object_types".' ),
		'labels'              => array( 'type' => 'object', 'description' => 'Etiquetas de wp-admin por clave (menu_name, all_items, add_new, add_new_item, edit_item, new_item, view_item, view_items, search_items, not_found, not_found_in_trash, parent, featured_image, set_featured_image, archives, insert_into_item, name_admin_bar, item_published, item_updated…). Las que falten las deduce CPTUI de label y singular_label. Reemplaza el conjunto.' ),
		'enter_title_here'    => array( 'type' => 'string', 'description' => 'Texto del campo de título vacío en el editor.' ),
	);
	$taxonomy_props  = array(
		'label'                => array( 'type' => 'string', 'description' => 'Nombre en plural ("Sectores").' ),
		'singular_label'       => array( 'type' => 'string', 'description' => 'Nombre en singular ("Sector").' ),
		'description'          => array( 'type' => 'string' ),
		'object_types'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tipos de contenido a los que se asigna (p. ej. ["proyecto"] o ["post", "page"]). Reemplaza la lista.' ),
		'public'               => $bool( 'Visible en la web (true).' ),
		'publicly_queryable'   => $bool( 'Se puede consultar por URL (true).' ),
		'hierarchical'         => $bool( 'Con términos padre e hijos, como las categorías (false = como las etiquetas).' ),
		'show_ui'              => $bool( 'Pantalla en wp-admin (true).' ),
		'show_in_menu'         => $bool( 'En el menú de wp-admin, debajo de su tipo (true).' ),
		'show_in_nav_menus'    => $bool( 'Se puede añadir a los menús de navegación (true).' ),
		'show_in_rest'         => $bool( 'Editor de bloques y API REST (true).' ),
		'rest_base'            => array( 'type' => 'string' ),
		'show_admin_column'    => $bool( 'Columna en el listado de contenidos de wp-admin (false).' ),
		'show_tagcloud'        => $bool( 'Widget de nube de etiquetas (false).' ),
		'show_in_quick_edit'   => $bool( 'En la edición rápida (false).' ),
		'sort'                 => $bool( 'Conservar el orden en que se asignan los términos (false).' ),
		'rewrite'              => $bool( 'URLs amigables (true).' ),
		'rewrite_slug'         => array( 'type' => 'string', 'description' => 'Base de las URLs si no es el slug.' ),
		'rewrite_withfront'    => $bool( 'Anteponer la base de los enlaces permanentes (true).' ),
		'rewrite_hierarchical' => $bool( 'URLs con los términos padre (/sector/industria/metal/) (false).' ),
		'query_var'            => $bool( 'Variable de consulta (true).' ),
		'query_var_slug'       => array( 'type' => 'string' ),
		'default_term'         => array( 'type' => 'string', 'description' => 'Término por defecto: "Nombre" o "Nombre, slug, descripción". Se crea al registrar la taxonomía.' ),
		'labels'               => array( 'type' => 'object', 'description' => 'Etiquetas por clave (menu_name, all_items, edit_item, view_item, update_item, add_new_item, new_item_name, parent_item, search_items, popular_items, separate_items_with_commas, add_or_remove_items, choose_from_most_used, not_found, no_terms, back_to_items…). Las que falten las deduce CPTUI. Reemplaza el conjunto.' ),
	);

	/* 1. Tipos: listar ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-custom-post-types', array(
		'label'               => 'Listar tipos de contenido de CPTUI',
		'description'         => 'Lista los tipos de contenido personalizados creados con Custom Post Type UI (CPTUI): slug, etiquetas, si es público, jerárquico, con archivo, qué admite, sus taxonomías, cuántos contenidos tiene ("count") y si está registrado. "editable" indica si aparece en spiral-mcp/list-post-types (públicos) y por tanto se puede gestionar con list-content / create-content. Los tipos que registran el tema u otros plugins (p. ej. "product" de WooCommerce) no salen aquí: están en spiral-mcp/list-post-types. Para crear uno, spiral-mcp/create-custom-post-type.',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$items = array();
			foreach ( spiral_mcp_cptui_post_types() as $slug => $entry ) {
				$items[] = spiral_mcp_cptui_post_type_out( (string) $slug, (array) $entry );
			}
			return array(
				'plugin'     => 'custom-post-type-ui',
				'version'    => CPTUI_VERSION,
				'total'      => count( $items ),
				'post_types' => $items,
				'other_post_types' => array_values( array_diff( spiral_mcp_post_types(), array_keys( spiral_mcp_cptui_post_types() ) ) ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Tipos: leer ------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-custom-post-type', array(
		'label'               => 'Leer un tipo de contenido de CPTUI',
		'description'         => 'Devuelve un tipo de contenido de Custom Post Type UI completo: "settings" (todo lo guardado en CPTUI: visibilidad, archivo, URLs, menú, icono, funciones, taxonomías y etiquetas, con los mismos nombres que acepta spiral-mcp/update-custom-post-type) y "registered_args" (cómo lo ha registrado WordPress en esta petición). Léelo antes de editarlo.',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array( 'name' => array( 'type' => 'string', 'description' => 'Slug del tipo (ver spiral-mcp/list-custom-post-types).' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$slug = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all  = spiral_mcp_cptui_post_types();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene el tipo "%s" (ver spiral-mcp/list-custom-post-types).%s', $slug, post_type_exists( $slug ) ? ' Existe, pero lo registra el tema u otro plugin: no se edita desde aquí.' : '' ) );
			}
			return spiral_mcp_cptui_post_type_out( $slug, (array) $all[ $slug ], true );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Tipos: crear ------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/create-custom-post-type', array(
		'label'               => 'Crear un tipo de contenido (CPTUI)',
		'description'         => 'Crea un tipo de contenido personalizado con Custom Post Type UI (p. ej. "proyecto", "testimonio", "equipo") y lo registra al momento: en la siguiente llamada ya aparece en spiral-mcp/list-post-types y se pueden crear contenidos con spiral-mcp/create-content. Obligatorio "name" (slug interno, minúsculas, 20 caracteres como mucho, sin cambiarlo después); conviene "label" y "singular_label". Por defecto: público, con archivo desactivado ("has_archive"), no jerárquico, con título, editor e imagen destacada ("supports") y en la API REST. Para que tenga categorías propias, crea después una taxonomía con spiral-mcp/create-custom-taxonomy y "object_types". Si Elementor o YOOtheme deben editar este tipo, hay que activarlo en sus ajustes (Elementor > Ajustes > Tipos de contenido): avisa al usuario. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_post_type', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array( 'name' => array( 'type' => 'string', 'description' => 'Slug interno: minúsculas, dígitos, guiones o guiones bajos, máximo 20 caracteres (p. ej. "proyecto"). No puede ser uno reservado (post, page, attachment…) ni coincidir con el slug de una página.' ) ) + $post_type_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = spiral_mcp_cptui_check_slug( $input['name'] ?? '' );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$free = spiral_mcp_cptui_post_type_slug_free( $slug );
			if ( is_wp_error( $free ) ) {
				return $free;
			}
			$warnings = array();
			$entry    = spiral_mcp_cptui_post_type_prepare( array( 'name' => $slug ) + $input, null, $warnings );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			$entry['name'] = $slug;
			$snapshot      = spiral_mcp_snapshot_capture( 'cptui_post_type', $slug, 'create-custom-post-type' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			spiral_mcp_cptui_post_types_write( $slug, $entry );
			spiral_mcp_cptui_register_post_type_now( $entry );
			$out = spiral_mcp_cptui_post_type_out( $slug, $entry, true );
			if ( ! $out['registered'] ) {
				$warnings[] = 'WordPress no ha registrado el tipo en esta petición; en la siguiente debería estar.';
			}
			return $out + array( 'created' => true, 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Tipos: editar ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-custom-post-type', array(
		'label'               => 'Editar un tipo de contenido (CPTUI)',
		'description'         => 'Cambia los ajustes enviados de un tipo de contenido de Custom Post Type UI (los demás se conservan): etiquetas, visibilidad, archivo, URLs, menú, icono, "supports", "taxonomies", "labels"… Primero léelo con spiral-mcp/get-custom-post-type. Para cambiar el slug envía "new_name" y "confirm": true: CPTUI mueve los contenidos al slug nuevo (las URLs cambian; avisa al usuario y considera redirecciones). Cambiar "rewrite_slug" o "has_archive" también cambia las URLs públicas. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_post_type', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'name'     => array( 'type' => 'string', 'description' => 'Slug actual del tipo.' ),
				'new_name' => array( 'type' => 'string', 'description' => 'Slug nuevo (renombrar). Exige "confirm": true; los contenidos pasan al slug nuevo.' ),
				'confirm'  => array( 'type' => 'boolean', 'description' => 'true para renombrar ("new_name").' ),
			) + $post_type_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all   = spiral_mcp_cptui_post_types();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene el tipo "%s" (ver spiral-mcp/list-custom-post-types).', $slug ) );
			}
			$changes = array_diff_key( $input, array_flip( array( 'name', 'new_name', 'confirm' ) ) );
			$new     = isset( $input['new_name'] ) ? spiral_mcp_cptui_check_slug( $input['new_name'] ) : $slug;
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			if ( $new !== $slug ) {
				$confirm = spiral_mcp_require_confirm( $input, sprintf( 'renombrar el tipo "%s" a "%s" (mueve %d contenido(s) y cambia sus URLs)', $slug, $new, spiral_mcp_cptui_post_count( $slug ) ) );
				if ( is_wp_error( $confirm ) ) {
					return $confirm;
				}
				$free = spiral_mcp_cptui_post_type_slug_free( $new );
				if ( is_wp_error( $free ) ) {
					return $free;
				}
			} elseif ( ! $changes ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos un ajuste que cambiar (ver spiral-mcp/get-custom-post-type) o "new_name" para renombrar.' );
			}
			$warnings = array();
			$entry    = spiral_mcp_cptui_post_type_prepare( $changes, (array) $all[ $slug ], $warnings );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			$entry['name'] = $new;
			$snapshot      = spiral_mcp_snapshot_capture( 'cptui_post_type', $slug, 'update-custom-post-type' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$moved = 0;
			if ( $new !== $slug ) {
				// Como cptui_convert_post_type_posts(): mover los contenidos y borrar la entrada antigua.
				global $wpdb;
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $slug ) );
				foreach ( $ids as $id ) {
					set_post_type( (int) $id, $new );
					++$moved;
				}
				spiral_mcp_cptui_post_types_write( $slug, null );
				if ( post_type_exists( $slug ) ) {
					unregister_post_type( $slug );
				}
				// Las taxonomías de CPTUI asignadas al slug antiguo pasan al nuevo.
				foreach ( spiral_mcp_cptui_taxonomies() as $tax_slug => $tax ) {
					$types = (array) ( $tax['object_types'] ?? array() );
					if ( in_array( $slug, $types, true ) ) {
						$tax['object_types'] = array_values( array_unique( array_map( function ( $t ) use ( $slug, $new ) {
							return $t === $slug ? $new : $t;
						}, $types ) ) );
						spiral_mcp_cptui_taxonomies_write( (string) $tax_slug, $tax );
					}
				}
				$snapshot_new = spiral_mcp_snapshot_capture( 'cptui_post_type', $new, 'update-custom-post-type (renombrado desde ' . $slug . ')' );
				$warnings[]   = sprintf( 'Tipo renombrado de "%s" a "%s": %d contenido(s) movido(s). Las URLs antiguas dejan de funcionar. Para deshacerlo, spiral-mcp/update-custom-post-type con "new_name": "%s".', $slug, $new, $moved, $slug );
			}
			spiral_mcp_cptui_post_types_write( $new, $entry );
			spiral_mcp_cptui_register_post_type_now( $entry );
			// Las taxonomías de CPTUI que apunten a este tipo se vuelven a registrar para que el cambio de tipos se vea en esta petición.
			foreach ( spiral_mcp_cptui_taxonomies() as $tax ) {
				if ( in_array( $new, (array) ( $tax['object_types'] ?? array() ), true ) ) {
					spiral_mcp_cptui_register_taxonomy_now( (array) $tax );
				}
			}
			$out = spiral_mcp_cptui_post_type_out( $new, $entry, true );
			return $out + array( 'updated' => true, 'renamed_from' => $new !== $slug ? $slug : null, 'moved' => $moved, 'snapshot_id' => $snapshot, 'snapshot_id_new' => $snapshot_new ?? null, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 5. Tipos: borrar ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-custom-post-type', array(
		'label'               => 'Borrar un tipo de contenido (CPTUI)',
		'description'         => 'Quita un tipo de contenido de Custom Post Type UI. Sus contenidos NO se borran: quedan en la base de datos sin tipo registrado (invisibles en la web y en wp-admin) hasta que se vuelva a crear un tipo con el mismo slug o se restaure el snapshot. Si quieres borrarlos, hazlo antes con spiral-mcp/trash-content / delete-content, o con "delete_content": true (los manda a la papelera, de donde se pueden recuperar al recrear el tipo). Exige "confirm": true. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_post_type', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name', 'confirm' ),
			'properties' => array(
				'name'           => array( 'type' => 'string' ),
				'confirm'        => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
				'delete_content' => array( 'type' => 'boolean', 'description' => 'true = mandar sus contenidos a la papelera antes de quitar el tipo (false por defecto).' ),
			),
		),
		'permission_callback' => function ( $input ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return false;
			}
			return empty( $input['delete_content'] ) || current_user_can( 'delete_others_posts' );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all   = spiral_mcp_cptui_post_types();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene el tipo "%s" (ver spiral-mcp/list-custom-post-types).', $slug ) );
			}
			$count   = spiral_mcp_cptui_post_count( $slug );
			$confirm = spiral_mcp_require_confirm( $input, sprintf( 'borrar el tipo "%s" (%d contenido(s) quedarían sin tipo)', $slug, $count ) );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'cptui_post_type', $slug, 'delete-custom-post-type' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$trashed = 0;
			if ( ! empty( $input['delete_content'] ) && post_type_exists( $slug ) ) {
				global $wpdb;
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", $slug ) );
				foreach ( $ids as $id ) {
					if ( wp_trash_post( (int) $id ) ) {
						++$trashed;
					}
				}
			}
			spiral_mcp_cptui_post_types_write( $slug, null );
			if ( post_type_exists( $slug ) ) {
				unregister_post_type( $slug );
			}
			spiral_mcp_cptui_flush();
			$warnings = array();
			if ( $count - $trashed > 0 ) {
				$warnings[] = sprintf( '%d contenido(s) de tipo "%s" siguen en la base de datos sin tipo registrado. Se recuperan restaurando el snapshot o creando de nuevo el tipo con el mismo slug.', $count - $trashed, $slug );
			}
			$orphan_taxes = array();
			foreach ( spiral_mcp_cptui_taxonomies() as $tax_slug => $tax ) {
				if ( in_array( $slug, (array) ( $tax['object_types'] ?? array() ), true ) ) {
					$orphan_taxes[] = (string) $tax_slug;
				}
			}
			if ( $orphan_taxes ) {
				$warnings[] = sprintf( 'Las taxonomías de CPTUI %s seguían asignadas a este tipo: edítalas con spiral-mcp/update-custom-taxonomy ("object_types") o bórralas.', implode( ', ', $orphan_taxes ) );
			}
			return array( 'deleted' => true, 'name' => $slug, 'count' => $count, 'trashed' => $trashed, 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	/* 6. Taxonomías: listar ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-custom-taxonomies', array(
		'label'               => 'Listar taxonomías de CPTUI',
		'description'         => 'Lista las taxonomías personalizadas creadas con Custom Post Type UI: slug, etiquetas, tipos de contenido a los que se asignan ("object_types"), si es jerárquica, cuántos términos tiene y si está registrada. Las taxonomías nativas (category, post_tag) y las de otros plugins están en spiral-mcp/list-taxonomies; los términos de cualquiera se gestionan con spiral-mcp/list-terms y create-term.',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$items = array();
			foreach ( spiral_mcp_cptui_taxonomies() as $slug => $entry ) {
				$items[] = spiral_mcp_cptui_taxonomy_out( (string) $slug, (array) $entry );
			}
			return array(
				'plugin'     => 'custom-post-type-ui',
				'version'    => CPTUI_VERSION,
				'total'      => count( $items ),
				'taxonomies' => $items,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 7. Taxonomías: leer -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-custom-taxonomy', array(
		'label'               => 'Leer una taxonomía de CPTUI',
		'description'         => 'Devuelve una taxonomía de Custom Post Type UI completa: "settings" (lo guardado en CPTUI, con los mismos nombres que acepta spiral-mcp/update-custom-taxonomy) y "registered_args" (cómo la ha registrado WordPress). Léela antes de editarla.',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array( 'name' => array( 'type' => 'string', 'description' => 'Slug de la taxonomía (ver spiral-mcp/list-custom-taxonomies).' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$slug = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all  = spiral_mcp_cptui_taxonomies();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene la taxonomía "%s" (ver spiral-mcp/list-custom-taxonomies).%s', $slug, taxonomy_exists( $slug ) ? ' Existe, pero la registra el tema u otro plugin: no se edita desde aquí.' : '' ) );
			}
			return spiral_mcp_cptui_taxonomy_out( $slug, (array) $all[ $slug ], true );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 8. Taxonomías: crear ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/create-custom-taxonomy', array(
		'label'               => 'Crear una taxonomía (CPTUI)',
		'description'         => 'Crea una taxonomía personalizada con Custom Post Type UI (p. ej. "sector" para los proyectos, "tipo_servicio") y la registra al momento: en la siguiente llamada ya se pueden crear términos con spiral-mcp/create-term y asignarlos con "terms" en create-content / update-content. Obligatorios "name" (slug, minúsculas, 32 caracteres como mucho) y "object_types" (tipos de contenido a los que se asigna). Por defecto: pública, no jerárquica (como las etiquetas; "hierarchical": true para que funcione como las categorías), sin columna en wp-admin y en la API REST. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_taxonomy', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name', 'object_types' ),
			'properties' => array( 'name' => array( 'type' => 'string', 'description' => 'Slug interno: minúsculas, dígitos, guiones o guiones bajos, máximo 32 caracteres. No puede ser uno reservado (category, tag, author, year…).' ) ) + $taxonomy_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = spiral_mcp_cptui_check_slug( $input['name'] ?? '', 'taxonomy' );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			$free = spiral_mcp_cptui_taxonomy_slug_free( $slug );
			if ( is_wp_error( $free ) ) {
				return $free;
			}
			$warnings = array();
			$entry    = spiral_mcp_cptui_taxonomy_prepare( array( 'name' => $slug ) + $input, null, $warnings );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			$entry['name'] = $slug;
			$snapshot      = spiral_mcp_snapshot_capture( 'cptui_taxonomy', $slug, 'create-custom-taxonomy' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			spiral_mcp_cptui_taxonomies_write( $slug, $entry );
			spiral_mcp_cptui_register_taxonomy_now( $entry );
			$out = spiral_mcp_cptui_taxonomy_out( $slug, $entry, true );
			if ( ! $out['registered'] ) {
				$warnings[] = 'WordPress no ha registrado la taxonomía en esta petición; en la siguiente debería estar.';
			}
			return $out + array( 'created' => true, 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 9. Taxonomías: editar ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/update-custom-taxonomy', array(
		'label'               => 'Editar una taxonomía (CPTUI)',
		'description'         => 'Cambia los ajustes enviados de una taxonomía de Custom Post Type UI (los demás se conservan): etiquetas, "object_types", jerarquía, URLs, columna en wp-admin, "labels"… Primero léela con spiral-mcp/get-custom-taxonomy. Para cambiar el slug envía "new_name" y "confirm": true: los términos pasan a la taxonomía nueva (las URLs cambian). Cambiar "hierarchical" en una taxonomía con términos no reorganiza los existentes. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_taxonomy', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'name'     => array( 'type' => 'string', 'description' => 'Slug actual de la taxonomía.' ),
				'new_name' => array( 'type' => 'string', 'description' => 'Slug nuevo (renombrar). Exige "confirm": true; los términos pasan al slug nuevo.' ),
				'confirm'  => array( 'type' => 'boolean', 'description' => 'true para renombrar ("new_name").' ),
			) + $taxonomy_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all   = spiral_mcp_cptui_taxonomies();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene la taxonomía "%s" (ver spiral-mcp/list-custom-taxonomies).', $slug ) );
			}
			$changes = array_diff_key( $input, array_flip( array( 'name', 'new_name', 'confirm' ) ) );
			$new     = isset( $input['new_name'] ) ? spiral_mcp_cptui_check_slug( $input['new_name'], 'taxonomy' ) : $slug;
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			if ( $new !== $slug ) {
				$confirm = spiral_mcp_require_confirm( $input, sprintf( 'renombrar la taxonomía "%s" a "%s" (mueve %d término(s) y cambia sus URLs)', $slug, $new, spiral_mcp_cptui_term_count( $slug ) ) );
				if ( is_wp_error( $confirm ) ) {
					return $confirm;
				}
				$free = spiral_mcp_cptui_taxonomy_slug_free( $new );
				if ( is_wp_error( $free ) ) {
					return $free;
				}
			} elseif ( ! $changes ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos un ajuste que cambiar (ver spiral-mcp/get-custom-taxonomy) o "new_name" para renombrar.' );
			}
			$warnings = array();
			$entry    = spiral_mcp_cptui_taxonomy_prepare( $changes, (array) $all[ $slug ], $warnings );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			$entry['name'] = $new;
			$snapshot      = spiral_mcp_snapshot_capture( 'cptui_taxonomy', $slug, 'update-custom-taxonomy' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$moved = 0;
			if ( $new !== $slug ) {
				// Como cptui_convert_taxonomy_terms(): cambiar la taxonomía de los términos y borrar la entrada antigua.
				global $wpdb;
				$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $slug ) ) );
				$moved = (int) $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $new ), array( 'taxonomy' => $slug ), array( '%s' ), array( '%s' ) );
				clean_term_cache( $ids, $slug );
				clean_term_cache( $ids, $new );
				spiral_mcp_cptui_taxonomies_write( $slug, null );
				if ( taxonomy_exists( $slug ) ) {
					unregister_taxonomy( $slug );
				}
				// Los tipos de CPTUI que la tenían asignada pasan a la nueva.
				foreach ( spiral_mcp_cptui_post_types() as $pt_slug => $pt ) {
					$taxes = (array) ( $pt['taxonomies'] ?? array() );
					if ( in_array( $slug, $taxes, true ) ) {
						$pt['taxonomies'] = array_values( array_unique( array_map( function ( $t ) use ( $slug, $new ) {
							return $t === $slug ? $new : $t;
						}, $taxes ) ) );
						spiral_mcp_cptui_post_types_write( (string) $pt_slug, $pt );
					}
				}
				$snapshot_new = spiral_mcp_snapshot_capture( 'cptui_taxonomy', $new, 'update-custom-taxonomy (renombrada desde ' . $slug . ')' );
				$warnings[]   = sprintf( 'Taxonomía renombrada de "%s" a "%s": %d término(s) movido(s). Las URLs antiguas dejan de funcionar. Para deshacerlo, spiral-mcp/update-custom-taxonomy con "new_name": "%s".', $slug, $new, $moved, $slug );
			}
			spiral_mcp_cptui_taxonomies_write( $new, $entry );
			spiral_mcp_cptui_register_taxonomy_now( $entry );
			clean_taxonomy_cache( $new );
			$out = spiral_mcp_cptui_taxonomy_out( $new, $entry, true );
			return $out + array( 'updated' => true, 'renamed_from' => $new !== $slug ? $slug : null, 'moved' => $moved, 'snapshot_id' => $snapshot, 'snapshot_id_new' => $snapshot_new ?? null, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 10. Taxonomías: borrar ----------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-custom-taxonomy', array(
		'label'               => 'Borrar una taxonomía (CPTUI)',
		'description'         => 'Quita una taxonomía de Custom Post Type UI. Sus términos y las asignaciones a los contenidos NO se borran: quedan en la base de datos sin taxonomía registrada hasta que se vuelva a crear con el mismo slug o se restaure el snapshot. Exige "confirm": true. Guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-cptui',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'cptui_taxonomy', 'id' => 'name' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name', 'confirm' ),
			'properties' => array(
				'name'    => array( 'type' => 'string' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$slug  = strtolower( trim( (string) ( $input['name'] ?? '' ) ) );
			$all   = spiral_mcp_cptui_taxonomies();
			if ( ! isset( $all[ $slug ] ) ) {
				return new WP_Error( 'spiral_mcp_cptui_not_found', sprintf( 'CPTUI no tiene la taxonomía "%s" (ver spiral-mcp/list-custom-taxonomies).', $slug ) );
			}
			$count   = spiral_mcp_cptui_term_count( $slug );
			$confirm = spiral_mcp_require_confirm( $input, sprintf( 'borrar la taxonomía "%s" (%d término(s) quedarían sin taxonomía)', $slug, $count ) );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'cptui_taxonomy', $slug, 'delete-custom-taxonomy' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			spiral_mcp_cptui_taxonomies_write( $slug, null );
			if ( taxonomy_exists( $slug ) ) {
				unregister_taxonomy( $slug );
			}
			spiral_mcp_cptui_flush();
			$warnings = array();
			if ( $count > 0 ) {
				$warnings[] = sprintf( '%d término(s) de "%s" siguen en la base de datos sin taxonomía registrada. Se recuperan restaurando el snapshot o creando de nuevo la taxonomía con el mismo slug.', $count, $slug );
			}
			return array( 'deleted' => true, 'name' => $slug, 'count' => $count, 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );
} );
