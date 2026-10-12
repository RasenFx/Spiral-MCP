<?php
/**
 * Página Ajustes > Spiral-MCP (solo administradores): qué abilities se publican
 * para el agente y qué capacidades propias tiene su rol. El agente no tiene
 * ninguna ability para cambiar esto.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** URL de la página (con pestaña opcional). */
function spiral_mcp_admin_url( $tab = 'abilities', array $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'spiral-mcp', 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
}

/** Catálogo completo de abilities (fuerza su registro si aún no se ha hecho). */
function spiral_mcp_admin_catalog() {
	if ( function_exists( 'wp_get_abilities' ) ) {
		wp_get_abilities(); // Dispara wp_abilities_api_init: el wrapper rellena el catálogo.
	}
	$catalog = spiral_mcp_ability_catalog();
	ksort( $catalog );
	return $catalog;
}

/** Nombre visible de una categoría de abilities. */
function spiral_mcp_admin_category_label( $slug ) {
	if ( function_exists( 'wp_get_ability_category' ) ) {
		$cat = wp_get_ability_category( $slug );
		if ( $cat && method_exists( $cat, 'get_label' ) ) {
			return (string) $cat->get_label();
		}
	}
	return (string) $slug;
}

/** Registra en la auditoría un cambio hecho en la página. */
function spiral_mcp_admin_log( $what, array $details ) {
	if ( function_exists( 'spiral_mcp_log_write' ) ) {
		spiral_mcp_log_write( array(
			'ability'     => 'admin/' . $what,
			'object_type' => 'settings',
			'object_id'   => $what,
			'input_json'  => spiral_mcp_json( $details ),
			'result'      => 'ok',
		) );
	}
}

add_action( 'admin_menu', function () {
	add_options_page( 'Spiral-MCP', 'Spiral-MCP', 'manage_options', 'spiral-mcp', 'spiral_mcp_admin_render' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( SPIRAL_MCP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( spiral_mcp_admin_url() ) . '">Ajustes</a>' );
	return $links;
} );

/* -------------------------------------------------------------------------
 * Guardar
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_spiral_mcp_save_abilities', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tienes permiso para cambiar esto.', 403 );
	}
	check_admin_referer( 'spiral_mcp_save_abilities' );

	$catalog   = spiral_mcp_admin_catalog();
	$protected = spiral_mcp_protected_abilities();
	$preset    = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
	$posted    = isset( $_POST['enabled'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled'] ) ) : array();

	$disabled = array();
	foreach ( $catalog as $name => $info ) {
		if ( in_array( $name, $protected, true ) ) {
			continue;
		}
		if ( 'all' === $preset ) {
			$on = true;
		} elseif ( 'readonly' === $preset ) {
			$on = 'read' === $info['kind'];
		} elseif ( 'no_destructive' === $preset ) {
			$on = 'destructive' !== $info['kind'] && $info['enabled'];
		} else {
			$on = in_array( $name, $posted, true );
		}
		if ( ! $on ) {
			$disabled[] = $name;
		}
	}
	sort( $disabled );
	$before = spiral_mcp_disabled_abilities();
	update_option( 'spiral_mcp_disabled_abilities', $disabled, true );
	spiral_mcp_admin_log( 'abilities', array(
		'preset'      => $preset ? $preset : null,
		'disabled'    => array_values( array_diff( $disabled, $before ) ),
		'enabled'     => array_values( array_diff( $before, $disabled ) ),
		'total_off'   => count( $disabled ),
	) );
	wp_safe_redirect( spiral_mcp_admin_url( 'abilities', array( 'updated' => 1 ) ) );
	exit;
} );

add_action( 'admin_post_spiral_mcp_save_caps', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tienes permiso para cambiar esto.', 403 );
	}
	check_admin_referer( 'spiral_mcp_save_caps' );
	$posted = isset( $_POST['caps'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['caps'] ) ) : array();
	$before = spiral_mcp_agent_caps();
	$choice = array();
	foreach ( spiral_mcp_caps() as $cap ) {
		$choice[ $cap ] = in_array( $cap, $posted, true );
	}
	update_option( 'spiral_mcp_agent_caps', $choice, true );
	spiral_mcp_install_caps(); // Aplica al rol ya, y se respeta en las actualizaciones.
	$after = spiral_mcp_agent_caps();
	spiral_mcp_admin_log( 'agent_caps', array(
		'granted' => array_values( array_diff( $after, $before ) ),
		'revoked' => array_values( array_diff( $before, $after ) ),
	) );
	wp_safe_redirect( spiral_mcp_admin_url( 'permissions', array( 'updated' => 1 ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * Pintar
 * ---------------------------------------------------------------------- */

function spiral_mcp_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tab  = isset( $_GET['tab'] ) && 'permissions' === $_GET['tab'] ? 'permissions' : 'abilities'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tabs = array(
		'abilities'   => 'Abilities',
		'permissions' => 'Permisos del agente',
	);
	echo '<div class="wrap spiral-mcp-admin"><h1>Spiral-MCP</h1>';
	if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>Cambios guardados. Los clientes MCP conectados verán la lista nueva de herramientas al volver a pedirla (en algunos hay que reconectar).</p></div>';
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $id => $label ) {
		printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( spiral_mcp_admin_url( $id ) ), $tab === $id ? ' nav-tab-active' : '', esc_html( $label ) );
	}
	echo '</nav>';
	if ( 'permissions' === $tab ) {
		spiral_mcp_admin_render_permissions();
	} else {
		spiral_mcp_admin_render_abilities();
	}
	echo '</div>';
}

function spiral_mcp_admin_render_abilities() {
	$catalog = spiral_mcp_admin_catalog();
	if ( ! $catalog ) {
		echo '<p>No se han encontrado abilities: ¿está disponible la Abilities API (WordPress 6.9 o superior)?</p>';
		return;
	}
	$protected = spiral_mcp_protected_abilities();
	$by_cat    = array();
	foreach ( $catalog as $name => $info ) {
		$by_cat[ $info['category'] ][ $name ] = $info;
	}
	$off   = count( array_filter( $catalog, function ( $i ) {
		return ! $i['enabled'];
	} ) );
	$kinds = array(
		'read'        => array( 'Lectura', '#2271b1' ),
		'write'       => array( 'Escritura', '#996800' ),
		'destructive' => array( 'Destructiva', '#b32d2e' ),
	);
	?>
	<style>
		.spiral-mcp-admin .spiral-mcp-kind { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; color:#fff; white-space:nowrap; }
		.spiral-mcp-admin table.widefat td, .spiral-mcp-admin table.widefat th { vertical-align: top; }
		.spiral-mcp-admin .spiral-mcp-desc { color:#50575e; max-width:70ch; }
		.spiral-mcp-admin h2 label { font-weight:600; }
		.spiral-mcp-admin .spiral-mcp-presets .button { margin-right:6px; }
		.spiral-mcp-admin code { font-size:12px; }
	</style>
	<p>Decide qué herramientas ve el agente. Una ability desactivada no se publica en el MCP: el agente no la ve ni puede usarla. <strong><?php echo (int) ( count( $catalog ) - $off ); ?></strong> de <?php echo (int) count( $catalog ); ?> activas.
	<?php echo $protected ? ' <code>' . esc_html( implode( '</code>, <code>', array_map( function ( $n ) {
		return preg_replace( '#^spiral-mcp/#', '', $n );
	}, $protected ) ) ) . '</code> están siempre activas: sin ellas el agente no podría ver ni deshacer sus cambios.' : ''; ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="spiral_mcp_save_abilities">
		<?php wp_nonce_field( 'spiral_mcp_save_abilities' ); ?>
		<p class="spiral-mcp-presets">
			<button type="submit" name="preset" value="all" class="button">Activar todas</button>
			<button type="submit" name="preset" value="readonly" class="button">Solo lectura</button>
			<button type="submit" name="preset" value="no_destructive" class="button">Desactivar las destructivas</button>
		</p>
		<?php foreach ( $by_cat as $cat => $items ) : ?>
			<?php
			$cat_id = 'spiral-mcp-cat-' . sanitize_html_class( $cat );
			$all_on = ! in_array( false, wp_list_pluck( $items, 'enabled' ), true );
			?>
			<h2><label><input type="checkbox" class="spiral-mcp-cat-toggle" data-target="<?php echo esc_attr( $cat_id ); ?>" <?php checked( $all_on ); ?>> <?php echo esc_html( spiral_mcp_admin_category_label( $cat ) ); ?></label> <span class="description">(<?php echo (int) count( $items ); ?>)</span></h2>
			<table class="widefat striped" id="<?php echo esc_attr( $cat_id ); ?>">
				<tbody>
				<?php foreach ( $items as $name => $info ) : ?>
					<?php $locked = in_array( $name, $protected, true ); ?>
					<tr>
						<td style="width:2em"><input type="checkbox" name="enabled[]" value="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( 'spiral-mcp-' . sanitize_html_class( $name ) ); ?>" <?php checked( $info['enabled'] || $locked ); ?> <?php disabled( $locked ); ?>></td>
						<td style="width:28%"><label for="<?php echo esc_attr( 'spiral-mcp-' . sanitize_html_class( $name ) ); ?>"><strong><?php echo esc_html( $info['label'] ); ?></strong><br><code><?php echo esc_html( preg_replace( '#^spiral-mcp/#', '', $name ) ); ?></code></label></td>
						<td style="width:8em"><span class="spiral-mcp-kind" style="background:<?php echo esc_attr( $kinds[ $info['kind'] ][1] ); ?>"><?php echo esc_html( $kinds[ $info['kind'] ][0] ); ?></span></td>
						<td class="spiral-mcp-desc"><?php echo esc_html( wp_trim_words( $info['description'], 40 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		<?php submit_button( 'Guardar cambios' ); ?>
	</form>
	<script>
	document.querySelectorAll( '.spiral-mcp-cat-toggle' ).forEach( function ( toggle ) {
		toggle.addEventListener( 'change', function () {
			document.querySelectorAll( '#' + toggle.dataset.target + ' input[name="enabled[]"]:not(:disabled)' ).forEach( function ( box ) {
				box.checked = toggle.checked;
			} );
		} );
	} );
	</script>
	<?php
}

function spiral_mcp_admin_render_permissions() {
	$role    = get_role( 'spiral_mcp_agent' );
	$current = $role ? (array) $role->capabilities : array();
	$descs   = spiral_mcp_cap_descriptions();
	$default = array_diff( spiral_mcp_caps(), spiral_mcp_agent_excluded_caps() );
	?>
	<p>Capacidades propias del rol <strong>Agente Spiral-MCP</strong> (<code>spiral_mcp_agent</code>). Cada ability exige la capacidad de su grupo y, además, el permiso nativo de WordPress sobre el objeto. Estos cambios se mantienen al actualizar el plugin.</p>
	<p>El rol del agente nunca recibe <code>install_plugins</code>, <code>edit_plugins</code>, <code>manage_options</code>, <code>promote_users</code> ni <code>delete_users</code>.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="spiral_mcp_save_caps">
		<?php wp_nonce_field( 'spiral_mcp_save_caps' ); ?>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th style="width:2em"></th><th>Capacidad</th><th>Para qué</th><th>Por defecto</th></tr></thead>
			<tbody>
			<?php foreach ( spiral_mcp_caps() as $cap ) : ?>
				<tr>
					<td><input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>" id="<?php echo esc_attr( $cap ); ?>" <?php checked( ! empty( $current[ $cap ] ) ); ?>></td>
					<td><label for="<?php echo esc_attr( $cap ); ?>"><code><?php echo esc_html( $cap ); ?></code></label></td>
					<td><?php echo esc_html( $descs[ $cap ] ?? '' ); ?></td>
					<td><?php echo in_array( $cap, $default, true ) ? 'Sí' : 'No'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $role ) : ?>
			<p><strong>El rol del agente no existe.</strong> Desactiva y activa el plugin para crearlo.</p>
		<?php endif; ?>
		<?php submit_button( 'Guardar permisos' ); ?>
	</form>
	<?php
}
