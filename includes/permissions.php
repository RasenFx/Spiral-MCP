<?php
/**
 * Capacidades propias (spiral_mcp_*) y rol spiral_mcp_agent.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grupos de permisos. Cada uno tiene su capacidad spiral_mcp_<grupo>. "cache" (cambiar los
 * ajustes de las herramientas de caché) no se da al rol del agente por defecto.
 */
function spiral_mcp_groups() {
	return array( 'content', 'design', 'media', 'settings', 'code', 'cache' );
}

/** Capacidades propias que el rol del agente no recibe salvo que se añadan con spiral_mcp_agent_caps. */
function spiral_mcp_agent_excluded_caps() {
	return array( 'spiral_mcp_cache' );
}

/** Para qué sirve cada capacidad propia (página de ajustes). */
function spiral_mcp_cap_descriptions() {
	return array(
		'spiral_mcp_content'  => 'Contenido: páginas, entradas, taxonomías, SEO y sus layouts.',
		'spiral_mcp_design'   => 'Diseño: builder, configuración del tema, menús, widgets, plantillas, estilos y cachés (purga y precarga).',
		'spiral_mcp_media'    => 'Medios: subir, editar y borrar archivos de la biblioteca.',
		'spiral_mcp_settings' => 'Ajustes del sitio: identidad, portada y zona horaria.',
		'spiral_mcp_code'     => 'Código: JavaScript personalizado (sección "scripts" de YOOtheme). Es el permiso de más riesgo.',
		'spiral_mcp_cache'    => 'Cambiar los ajustes de la herramienta de caché (activarla, caducidad, exclusiones…).',
	);
}

/**
 * Elección del administrador sobre las capacidades propias del rol del agente (página de
 * ajustes): cap => true (darla) / false (quitarla). Lo que no está, sigue el valor por defecto.
 */
function spiral_mcp_agent_caps_override() {
	$saved = get_option( 'spiral_mcp_agent_caps', array() );
	$out   = array();
	foreach ( (array) $saved as $cap => $on ) {
		if ( in_array( $cap, spiral_mcp_caps(), true ) ) {
			$out[ $cap ] = (bool) $on;
		}
	}
	return $out;
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
		'edit_private_posts',
		'read_private_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'edit_private_pages',
		'read_private_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'delete_private_pages',
		'upload_files',
		'edit_theme_options',
		'manage_categories',
	);
	$own = array_diff( spiral_mcp_caps(), spiral_mcp_agent_excluded_caps() );
	foreach ( spiral_mcp_agent_caps_override() as $cap => $on ) {
		$own = $on ? array_unique( array_merge( $own, array( $cap ) ) ) : array_diff( $own, array( $cap ) );
	}
	$caps = array_merge( $native, array_values( $own ) );
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
		// Las capacidades propias que ya no tocan (quitadas en los ajustes o excluidas) se retiran.
		foreach ( spiral_mcp_caps() as $cap ) {
			if ( ! in_array( $cap, $caps, true ) && ! empty( $role->capabilities[ $cap ] ) ) {
				$role->remove_cap( $cap );
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
