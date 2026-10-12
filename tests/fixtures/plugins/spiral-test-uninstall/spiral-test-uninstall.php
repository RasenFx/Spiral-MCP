<?php
/**
 * Plugin Name: Spiral-MCP prueba: desinstalación
 * Description: SOLO PARA PRUEBAS de spiral-mcp/delete-plugin. Al activarse crea la opción spiral_test_uninstall; su uninstall.php la borra.
 * Version:     1.0.0
 *
 * @package Spiral_MCP_Control
 */

register_activation_hook( __FILE__, function () {
	update_option( 'spiral_test_uninstall', 'sí' );
} );
