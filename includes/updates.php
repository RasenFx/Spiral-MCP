<?php
/**
 * Actualizaciones desde SPUpdater (https://plugins.rasenfx.com). El plugin es
 * gratuito: no hay clave de licencia, pero cada chequeo registra el dominio.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

const SPIRAL_MCP_UPDATE_SERVER = 'https://plugins.rasenfx.com';

require_once SPIRAL_MCP_DIR . 'includes/spupdater/autoload.php';

add_action(
	'init',
	function () {
		\SpiralMcp\SPUpdater\WpClient\WordPress\Plugin::register(
			new \SpiralMcp\SPUpdater\WpClient\Config(
				serverUrl: SPIRAL_MCP_UPDATE_SERVER,
				productSlug: 'spiral-mcp-control',
				pluginFile: plugin_basename( SPIRAL_MCP_FILE ),
				pluginVersion: SPIRAL_MCP_VERSION,
				isFree: true,
			)
		);
	}
);
