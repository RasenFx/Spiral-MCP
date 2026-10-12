<?php
/**
 * Desinstalación del plugin de prueba: borra su opción.
 *
 * @package Spiral_MCP_Control
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'spiral_test_uninstall' );
