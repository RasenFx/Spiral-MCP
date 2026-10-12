<?php
/**
 * SOLO PARA PRUEBAS de spiral-mcp/update-plugin, como mu-plugin en un sitio local.
 * Anuncia la versión 2.0.0 de spiral-test-update y acepta su ZIP desde el propio sitio
 * (genera el ZIP de spiral-test-update-v2/ y déjalo en la raíz como spiral-test-update-2.0.0.zip).
 *
 * @package Spiral_MCP_Control
 */

add_filter( 'site_transient_update_plugins', function ( $value ) {
	if ( ! is_object( $value ) ) {
		$value = new stdClass();
	}
	$value->response['spiral-test-update/spiral-test-update.php'] = (object) array(
		'slug'        => 'spiral-test-update',
		'plugin'      => 'spiral-test-update/spiral-test-update.php',
		'new_version' => '2.0.0',
		'package'     => home_url( '/spiral-test-update-2.0.0.zip', 'http' ), // http: el certificado local es autofirmado.
	);
	return $value;
} );

add_filter( 'spiral_mcp_plugins_allowed_hosts', function ( $hosts ) {
	$hosts[] = wp_parse_url( home_url(), PHP_URL_HOST );
	return $hosts;
} );
