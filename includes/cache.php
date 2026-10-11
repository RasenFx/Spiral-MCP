<?php
/**
 * Caché: ability clear-cache y purga automática tras cada escritura de
 * contenido, diseño o medios.
 *
 * YOOtheme no cachea el HTML renderizado (layouts, plantillas y footer se
 * leen en cada petición), así que lo que hay que vaciar son las cachés de
 * página de otros plugins.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vacía las cachés de página de los plugins más comunes que estén activos
 * y, si $object_cache, también la caché de objetos. Devuelve lo que limpió.
 */
function spiral_mcp_clear_cache( $object_cache = false ) {
	$done = array();
	if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		$done[] = 'litespeed';
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$done[] = 'wp-rocket';
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$done[] = 'w3-total-cache';
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$done[] = 'wp-super-cache';
	}
	if ( $object_cache && wp_cache_flush() ) {
		$done[] = 'object-cache';
	}
	/**
	 * Para añadir otras cachés. Recibe y devuelve la lista de lo limpiado.
	 */
	return (array) apply_filters( 'spiral_mcp_clear_cache', $done, $object_cache );
}

/** Purga automática tras una escritura correcta (filtro spiral_mcp_auto_clear_cache). */
function spiral_mcp_maybe_auto_clear_cache( $ability, array $groups ) {
	if ( ! array_intersect( $groups, array( 'content', 'design', 'media' ) ) ) {
		return;
	}
	if ( apply_filters( 'spiral_mcp_auto_clear_cache', true, $ability ) ) {
		spiral_mcp_clear_cache( false );
	}
}

add_action( 'wp_abilities_api_init', function () {
	spiral_mcp_register_ability( 'spiral-mcp/clear-cache', array(
		'label'               => 'Vaciar cachés',
		'description'         => 'Vacía las cachés de página de los plugins de caché activos (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache) y la caché de objetos de WordPress. Devuelve qué limpió ("cleared"). Las escrituras de las abilities ya purgan las cachés de página automáticamente; úsala si un cambio no se ve. YOOtheme no cachea el HTML; los cambios de estilos (LESS) no se ven hasta recompilar el CSS en el Personalizador.',
		'category'            => 'spiral-mcp-theme',
		'group'               => array( 'content', 'design', 'media' ),
		'log'                 => array( 'type' => 'cache', 'id' => function () {
			return 'all';
		} ),
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function () {
			return array( 'cleared' => spiral_mcp_clear_cache( true ) );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
