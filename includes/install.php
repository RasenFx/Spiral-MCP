<?php
/**
 * Instalación y actualización: tablas, rol y capacidades, migraciones y
 * tareas programadas. Se ejecuta en la activación y cada vez que cambia
 * SPIRAL_MCP_VERSION.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Crea o actualiza las tablas con dbDelta. */
function spiral_mcp_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$log     = spiral_mcp_table( 'log' );
	$snaps   = spiral_mcp_table( 'snapshots' );

	// dbDelta exige dos espacios tras PRIMARY KEY y una columna por línea.
	dbDelta( "CREATE TABLE $log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ability varchar(100) NOT NULL DEFAULT '',
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id varchar(100) NOT NULL DEFAULT '',
  input_json text NULL,
  result varchar(100) NOT NULL DEFAULT 'ok',
  snapshot_id bigint(20) unsigned NULL,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY ability (ability),
  KEY object (object_type,object_id),
  KEY user_id (user_id)
) $charset;" );

	dbDelta( "CREATE TABLE $snaps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(30) NOT NULL DEFAULT '',
  ref varchar(191) NOT NULL DEFAULT '',
  data longtext NULL,
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY kind_ref (kind,ref),
  KEY created_at (created_at)
) $charset;" );
}

/** Instala o actualiza todo lo necesario. Es idempotente. */
function spiral_mcp_install() {
	spiral_mcp_install_tables();
	spiral_mcp_install_caps();
	spiral_mcp_snapshot_migrate_legacy();
	if ( ! wp_next_scheduled( 'spiral_mcp_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'spiral_mcp_daily_cleanup' );
	}
	update_option( 'spiral_mcp_db_version', SPIRAL_MCP_VERSION );
}

/** Desactivación: solo quita la tarea programada (el rol y los datos se quedan). */
function spiral_mcp_deactivate() {
	wp_clear_scheduled_hook( 'spiral_mcp_daily_cleanup' );
}

// Actualización sin reactivar (p. ej. al subir un ZIP nuevo).
add_action( 'plugins_loaded', function () {
	if ( get_option( 'spiral_mcp_db_version' ) !== SPIRAL_MCP_VERSION ) {
		spiral_mcp_install();
	}
} );
