<?php
/**
 * Plugin Name:       Spiral-MCP-Control
 * Description:       Registra abilities (Abilities API) para leer y editar páginas/entradas, layouts de YOOtheme, medios, menús, el footer de YOOtheme y widgets, expuestas al MCP Adapter. Con registro de auditoría, snapshots y rol propio para el agente.
 * Version:           1.31.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  mcp-adapter
 * Author:            RasenFx
 * License:           GPL-2.0-or-later
 * Text Domain:       spiral-mcp-control
 */

defined( 'ABSPATH' ) || exit;

const SPIRAL_MCP_VERSION = '1.31.0';
const SPIRAL_MCP_FILE    = __FILE__;
define( 'SPIRAL_MCP_DIR', plugin_dir_path( __FILE__ ) );

// Antes de comprobar los requisitos: así un sitio que no los cumple sigue
// recibiendo actualizaciones. La librería de SPUpdater necesita PHP 8.1.
if ( PHP_VERSION_ID >= 80100 ) {
	require_once SPIRAL_MCP_DIR . 'includes/updates.php';
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Spiral-MCP-Control:</strong> requiere WordPress 6.9+ (Abilities API).</p></div>';
	} );
	return;
}

require_once SPIRAL_MCP_DIR . 'includes/core.php';
require_once SPIRAL_MCP_DIR . 'includes/permissions.php';
require_once SPIRAL_MCP_DIR . 'includes/activity.php';
require_once SPIRAL_MCP_DIR . 'includes/snapshots.php';
require_once SPIRAL_MCP_DIR . 'includes/install.php';
require_once SPIRAL_MCP_DIR . 'includes/content.php';
require_once SPIRAL_MCP_DIR . 'includes/menus.php';
require_once SPIRAL_MCP_DIR . 'includes/theme.php';
require_once SPIRAL_MCP_DIR . 'includes/media.php';
require_once SPIRAL_MCP_DIR . 'includes/elements.php';
require_once SPIRAL_MCP_DIR . 'includes/layout.php';
require_once SPIRAL_MCP_DIR . 'includes/theme-config.php';
require_once SPIRAL_MCP_DIR . 'includes/templates.php';
require_once SPIRAL_MCP_DIR . 'includes/online-library.php';
require_once SPIRAL_MCP_DIR . 'includes/cache-providers.php';
require_once SPIRAL_MCP_DIR . 'includes/cache.php';
require_once SPIRAL_MCP_DIR . 'includes/taxonomies.php';
require_once SPIRAL_MCP_DIR . 'includes/seo.php';
require_once SPIRAL_MCP_DIR . 'includes/seo-settings.php';
require_once SPIRAL_MCP_DIR . 'includes/settings.php';
require_once SPIRAL_MCP_DIR . 'includes/styles.php';
require_once SPIRAL_MCP_DIR . 'includes/preview.php';
require_once SPIRAL_MCP_DIR . 'includes/plugins.php';
require_once SPIRAL_MCP_DIR . 'includes/forms.php';
require_once SPIRAL_MCP_DIR . 'includes/woocommerce.php';
require_once SPIRAL_MCP_DIR . 'includes/woocommerce-store.php';
require_once SPIRAL_MCP_DIR . 'includes/woocommerce-orders.php';
require_once SPIRAL_MCP_DIR . 'includes/polylang.php';
if ( is_admin() ) {
	require_once SPIRAL_MCP_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'spiral_mcp_install' );
register_deactivation_hook( __FILE__, 'spiral_mcp_deactivate' );
