<?php
/**
 * Plugin Name: Spiral-MCP prueba: rompe la web pública
 * Description: SOLO PARA PRUEBAS de spiral-mcp/activate-plugin. Se activa sin errores, pero la web pública da error 500: la comprobación de salud debe desactivarlo.
 * Version:     1.0.0
 *
 * @package Spiral_MCP_Control
 */

add_action( 'template_redirect', function () {
	throw new Error( 'Spiral-MCP prueba: la web pública falla a propósito.' );
} );
