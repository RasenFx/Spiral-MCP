<?php
/**
 * Plugin Name: Spiral-MCP prueba: actualización (2.0.0)
 * Description: SOLO PARA PRUEBAS de spiral-mcp/update-plugin. Versión nueva que rompe la web pública: update-plugin debe volver a la 1.0.0.
 * Version:     2.0.0
 *
 * @package Spiral_MCP_Control
 */

add_action( 'template_redirect', function () {
	throw new Error( 'Spiral-MCP prueba: la versión 2.0.0 rompe la web pública a propósito.' );
} );
