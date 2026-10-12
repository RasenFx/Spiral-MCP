<?php
/**
 * Desinstalación: elimina el rol spiral_mcp_agent, las capacidades
 * spiral_mcp_* y las opciones del plugin. Las tablas de auditoría y
 * snapshots solo se borran si el filtro spiral_mcp_uninstall_drop_tables
 * devuelve true (definido, por ejemplo, en un mu-plugin).
 *
 * @package Spiral_MCP_Control
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$spiral_mcp_caps = array( 'spiral_mcp_content', 'spiral_mcp_design', 'spiral_mcp_media', 'spiral_mcp_settings', 'spiral_mcp_code', 'spiral_mcp_cache' );

remove_role( 'spiral_mcp_agent' );
foreach ( wp_roles()->role_objects as $spiral_mcp_role ) {
	foreach ( $spiral_mcp_caps as $spiral_mcp_cap ) {
		$spiral_mcp_role->remove_cap( $spiral_mcp_cap );
	}
}

wp_clear_scheduled_hook( 'spiral_mcp_daily_cleanup' );
delete_option( 'spiral_mcp_db_version' );
delete_option( 'spiral_mcp_style_dirty' );

if ( apply_filters( 'spiral_mcp_uninstall_drop_tables', false ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spiral_mcp_log, {$wpdb->prefix}spiral_mcp_snapshots" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
