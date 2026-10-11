<?php
/**
 * Ajustes del sitio: identidad (nombre, descripción corta, icono), portada y zona horaria.
 * Grupo de permisos "settings".
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Opciones que se pueden leer y cambiar. */
function spiral_mcp_site_settings_keys() {
	return array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'site_icon', 'timezone_string' );
}

/** Ajustes actuales, con datos de ayuda (títulos de las páginas, URL del icono). */
function spiral_mcp_site_settings_out() {
	$out = array();
	foreach ( spiral_mcp_site_settings_keys() as $key ) {
		$out[ $key ] = get_option( $key );
	}
	$out['page_on_front']  = (int) $out['page_on_front'];
	$out['page_for_posts'] = (int) $out['page_for_posts'];
	$out['site_icon']      = (int) $out['site_icon'];
	$out['pages']          = array(
		'page_on_front'  => $out['page_on_front'] ? get_the_title( $out['page_on_front'] ) : null,
		'page_for_posts' => $out['page_for_posts'] ? get_the_title( $out['page_for_posts'] ) : null,
	);
	$out['site_icon_url']  = $out['site_icon'] ? get_site_icon_url() : null;
	// Solo lectura: zona efectiva (también si se fijó con un desfase UTC), hora local y visibilidad para buscadores.
	$out['timezone']    = wp_timezone_string();
	$out['local_time']  = current_time( 'Y-m-d\TH:i:sP' );
	$out['blog_public'] = (bool) get_option( 'blog_public' );
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots: opciones (ref = nombres separados por comas)
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['option'] = array(
		'group'   => 'settings',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$out = array();
			foreach ( array_filter( explode( ',', (string) $ref ) ) as $name ) {
				$out[ $name ] = get_option( $name, null );
			}
			return $out;
		},
		'restore' => function ( $ref, $data ) {
			foreach ( (array) $data as $name => $value ) {
				// Solo se restauran las opciones de la lista blanca.
				if ( ! in_array( $name, spiral_mcp_site_settings_keys(), true ) ) {
					continue;
				}
				if ( null === $value ) {
					delete_option( $name );
				} else {
					update_option( $name, $value );
				}
			}
			return true;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-settings', array(
		'label'       => 'Ajustes Spiral-MCP',
		'description' => 'Ajustes generales del sitio: identidad, portada y zona horaria.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	// Son los ajustes de "Identidad del sitio" y "Ajustes de la página de inicio" del Personalizador.
	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-site-settings', array(
		'label'               => 'Leer los ajustes del sitio',
		'description'         => 'Devuelve el nombre del sitio (blogname), la descripción corta (blogdescription), qué muestra la portada (show_on_front: "posts" = últimas entradas, "page" = una página), la página de inicio (page_on_front) y la de entradas (page_for_posts), con sus títulos, el icono del sitio (site_icon, ID de imagen, con su URL) y la zona horaria (timezone_string; "timezone" es la zona efectiva y "local_time", la hora local actual). Las fechas sin zona de las respuestas (date, modified) están en esa hora local. "blog_public": false significa que el sitio pide a los buscadores no indexarlo (solo lectura).',
		'category'            => 'spiral-mcp-settings',
		'group'               => 'settings',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			return spiral_mcp_site_settings_out();
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-site-settings', array(
		'label'               => 'Cambiar los ajustes del sitio',
		'description'         => 'Cambia los ajustes enviados: blogname, blogdescription, show_on_front ("posts" o "page"), page_on_front y page_for_posts (IDs de páginas publicadas; 0 = ninguna), site_icon (ID de una imagen cuadrada de al menos 512×512 px; 0 = quitar) y timezone_string (zona IANA, p. ej. "Europe/Madrid"; cambia la hora con la que se muestran y programan las fechas, así que confírmalo con el usuario). Para usar una página como portada envía "show_on_front": "page" y "page_on_front". Antes guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-settings',
		'group'               => 'settings',
		'log'                 => array( 'type' => 'option', 'id' => function ( $in ) {
			return implode( ',', array_keys( array_intersect_key( (array) $in, array_flip( spiral_mcp_site_settings_keys() ) ) ) );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'blogname'        => array( 'type' => 'string' ),
				'blogdescription' => array( 'type' => 'string' ),
				'show_on_front'   => array( 'type' => 'string', 'enum' => array( 'posts', 'page' ) ),
				'page_on_front'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'page_for_posts'  => array( 'type' => 'integer', 'minimum' => 0 ),
				'site_icon'       => array( 'type' => 'integer', 'minimum' => 0 ),
				'timezone_string' => array( 'type' => 'string', 'description' => 'Zona IANA, p. ej. Europe/Madrid, o UTC.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$values = array_intersect_key( (array) $input, array_flip( spiral_mcp_site_settings_keys() ) );
			if ( ! $values ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos uno de: ' . implode( ', ', spiral_mcp_site_settings_keys() ) . '.' );
			}
			$warnings = array();
			foreach ( array( 'page_on_front', 'page_for_posts' ) as $key ) {
				if ( ! empty( $values[ $key ] ) ) {
					$page = get_post( (int) $values[ $key ] );
					if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
						return new WP_Error( 'spiral_mcp_bad_page', sprintf( '"%s" debe ser el ID de una página publicada.', $key ) );
					}
				}
			}
			$front = (int) ( $values['page_on_front'] ?? get_option( 'page_on_front' ) );
			$posts = (int) ( $values['page_for_posts'] ?? get_option( 'page_for_posts' ) );
			if ( $front && $front === $posts ) {
				return new WP_Error( 'spiral_mcp_bad_page', 'La página de inicio y la de entradas no pueden ser la misma.' );
			}
			if ( 'page' === ( $values['show_on_front'] ?? get_option( 'show_on_front' ) ) && ! $front ) {
				return new WP_Error( 'spiral_mcp_bad_page', 'Con "show_on_front": "page" indica también "page_on_front".' );
			}
			if ( ! empty( $values['site_icon'] ) ) {
				if ( ! wp_attachment_is_image( (int) $values['site_icon'] ) ) {
					return new WP_Error( 'spiral_mcp_not_image', '"site_icon" debe ser el ID de una imagen (ver spiral-mcp/list-media).' );
				}
				$meta = wp_get_attachment_metadata( (int) $values['site_icon'] );
				if ( ( $meta['width'] ?? 0 ) < 512 || ( $meta['height'] ?? 0 ) < 512 || ( $meta['width'] ?? 0 ) !== ( $meta['height'] ?? 0 ) ) {
					$warnings[] = 'El icono del sitio debería ser cuadrado y de al menos 512×512 px.';
				}
			}
			if ( isset( $values['timezone_string'] ) && ! in_array( $values['timezone_string'], timezone_identifiers_list(), true ) ) {
				return new WP_Error( 'spiral_mcp_bad_timezone', '"timezone_string" debe ser una zona IANA, p. ej. Europe/Madrid, America/Mexico_City o UTC.' );
			}
			foreach ( array( 'blogname', 'blogdescription' ) as $key ) {
				if ( isset( $values[ $key ] ) ) {
					$values[ $key ] = sanitize_text_field( $values[ $key ] );
				}
			}

			$snapshot = spiral_mcp_snapshot_capture( 'option', implode( ',', array_keys( $values ) ), 'update-site-settings' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			foreach ( $values as $key => $value ) {
				update_option( $key, $value );
			}
			return spiral_mcp_site_settings_out() + array( 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
