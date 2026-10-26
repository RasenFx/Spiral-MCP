<?php
/**
 * Capacidades propias (spiral_mcp_*) y rol spiral_mcp_agent.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grupos de permisos. Cada uno tiene su capacidad spiral_mcp_<grupo>. "cache", "plugins" y
 * "orders" no se dan al rol del agente por defecto.
 */
function spiral_mcp_groups() {
	return array( 'content', 'design', 'media', 'settings', 'code', 'cache', 'plugins', 'shop', 'orders' );
}

/** Capacidades propias que el rol del agente no recibe salvo que se añadan con spiral_mcp_agent_caps. */
function spiral_mcp_agent_excluded_caps() {
	return array( 'spiral_mcp_cache', 'spiral_mcp_plugins', 'spiral_mcp_orders' );
}

/**
 * Capacidades nativas que el rol del agente solo recibe junto con una propia: cap propia => nativas.
 * Las de términos de producto hacen que las abilities de taxonomías sirvan para las categorías,
 * etiquetas y atributos de WooCommerce. Se retiran si se quita la propia.
 */
function spiral_mcp_conditional_caps() {
	return array(
		'spiral_mcp_shop' => array( 'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms' ),
	);
}

/**
 * Capacidades nativas que el rol del agente nunca recibe, aunque un filtro las añada. Las abilities
 * de plugins no las necesitan: usan las APIs de WordPress con su propia comprobación.
 */
function spiral_mcp_never_granted_caps() {
	return array(
		'manage_options',
		'promote_users',
		'delete_users',
		'edit_users',
		'create_users',
		'install_plugins',
		'activate_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'switch_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'update_core',
		'edit_files',
		'unfiltered_upload',
		// WooCommerce: las abilities de la tienda usan su API con su propia comprobación.
		'manage_woocommerce',
	);
}

/** Para qué sirve cada capacidad propia (página de ajustes). */
function spiral_mcp_cap_descriptions() {
	return array(
		'spiral_mcp_content'  => 'Contenido: páginas, entradas, taxonomías, SEO y sus layouts.',
		'spiral_mcp_design'   => 'Diseño: builder, configuración del tema, menús, widgets, plantillas, estilos y cachés (purga y precarga).',
		'spiral_mcp_media'    => 'Medios: subir, editar y borrar archivos de la biblioteca.',
		'spiral_mcp_settings' => 'Ajustes del sitio: identidad, portada y zona horaria.',
		'spiral_mcp_code'     => 'Código: JavaScript personalizado (sección "scripts" de YOOtheme) y HTML sin filtrar en los layouts (elemento "html", script, iframe, atributos on*…); sin él se aplica wp_kses_post como a cualquier usuario sin unfiltered_html. Es el permiso de más riesgo.',
		'spiral_mcp_cache'    => 'Cambiar los ajustes de la herramienta de caché (activarla, caducidad, exclusiones…).',
		'spiral_mcp_plugins'  => 'Gestionar plugins de wordpress.org (instalar, activar, desactivar, actualizar, borrar). Además hay que activar "Permitir que el agente gestione plugins" en Comportamiento. Es el permiso de más riesgo: instalar código es tener el control del sitio.',
		'spiral_mcp_shop'     => 'Tienda (WooCommerce): productos, variaciones, atributos, categorías de producto, cupones, ajustes de la tienda, envíos e impuestos. Las pasarelas de pago, solo en lectura.',
		'spiral_mcp_orders'   => 'Pedidos y clientes de WooCommerce: leerlos, cambiar el estado de un pedido, notas y reembolsos. Incluye datos personales de los clientes.',
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
	foreach ( spiral_mcp_conditional_caps() as $cap => $extra ) {
		if ( in_array( $cap, $own, true ) ) {
			$native = array_merge( $native, $extra );
		}
	}
	$caps = array_merge( $native, array_values( $own ) );
	$caps = (array) apply_filters( 'spiral_mcp_agent_caps', $caps );

	// Nunca se conceden, aunque un filtro los añada.
	return array_values( array_diff( $caps, spiral_mcp_never_granted_caps() ) );
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
		// Las capacidades propias que ya no tocan (quitadas en los ajustes o excluidas) se retiran,
		// y también las nativas prohibidas si alguien las añadió al rol por otra vía.
		$conditional = call_user_func_array( 'array_merge', array_values( spiral_mcp_conditional_caps() ) );
		foreach ( array_merge( spiral_mcp_caps(), spiral_mcp_never_granted_caps(), $conditional ) as $cap ) {
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
