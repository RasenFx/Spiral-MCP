<?php
/**
 * Capacidades propias (spiral_mcp_*) y rol spiral_mcp_agent.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Grupos de permisos. Cada uno tiene su capacidad spiral_mcp_<grupo>. */
function spiral_mcp_groups() {
	return array( 'content', 'design', 'media', 'settings', 'code' );
}

/** Capacidades propias del plugin. */
function spiral_mcp_caps() {
	return array_map(
		function ( $group ) {
			return 'spiral_mcp_' . $group;
		},
		spiral_mcp_groups()
	);
}

/** ¿El usuario actual tiene la capacidad de al menos uno de estos grupos? */
function spiral_mcp_user_can_any( array $groups ) {
	foreach ( $groups as $group ) {
		if ( current_user_can( 'spiral_mcp_' . $group ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Capacidades del rol spiral_mcp_agent: las propias más las nativas que
 * necesitan las abilities. Sin install_plugins, edit_plugins, manage_options,
 * promote_users ni delete_users. Ajustable con el filtro spiral_mcp_agent_caps.
 */
function spiral_mcp_agent_caps() {
	$native = array(
		'read',
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'publish_posts',
		'delete_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'edit_private_pages',
		'read_private_pages',
		'publish_pages',
		'delete_pages',
		'upload_files',
		'edit_theme_options',
		'manage_categories',
	);
	$caps = array_merge( $native, spiral_mcp_caps() );
	$caps = (array) apply_filters( 'spiral_mcp_agent_caps', $caps );

	// Nunca se conceden, aunque un filtro los añada.
	return array_values( array_diff( $caps, array( 'install_plugins', 'edit_plugins', 'manage_options', 'promote_users', 'delete_users' ) ) );
}

/** Crea o sincroniza el rol del agente y da todas las capacidades a los administradores. */
function spiral_mcp_install_caps() {
	$caps = spiral_mcp_agent_caps();
	$role = get_role( 'spiral_mcp_agent' );
	if ( ! $role ) {
		add_role( 'spiral_mcp_agent', 'Agente Spiral-MCP', array_fill_keys( $caps, true ) );
	} else {
		foreach ( $caps as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( spiral_mcp_caps() as $cap ) {
			if ( empty( $admin->capabilities[ $cap ] ) ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
