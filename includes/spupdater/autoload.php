<?php
/**
 * Autoloader de la librería cliente de SPUpdater incluida en el plugin.
 *
 * Copia de spupdater/packages/spupdater-wp-client/src con el namespace
 * SPUpdater\WpClient cambiado a SpiralMcp\SPUpdater\WpClient: otros plugins
 * (nktools…) traen su propia copia y, con el mismo namespace, solo se
 * cargaría la del primero que se active. Para sincronizar mejoras, vuelve a
 * copiar src/ y repite el cambio de namespace.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	function ( $class ) {
		$prefix = 'SpiralMcp\\SPUpdater\\WpClient\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
