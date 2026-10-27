<?php
/**
 * Página Ajustes > Spiral-MCP (solo administradores): qué abilities se publican
 * para el agente y qué capacidades propias tiene su rol. El agente no tiene
 * ninguna ability para cambiar esto.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Lista de nombres como <code>a</code>, <code>b</code> (cada nombre escapado). */
function spiral_mcp_admin_code_list( array $names ) {
	return implode( ', ', array_map( function ( $n ) {
		return '<code>' . esc_html( $n ) . '</code>';
	}, $names ) );
}

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
		if ( empty( $info['available'] ) ) {
			// Sin su requisito (p. ej. YOOtheme) no se muestra: conserva lo que tenía.
			if ( ! $info['enabled'] ) {
				$disabled[] = $name;
			}
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

add_action( 'admin_post_spiral_mcp_save_behavior', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tienes permiso para cambiar esto.', 403 );
	}
	check_admin_referer( 'spiral_mcp_save_behavior' );
	$before = array();
	foreach ( array_keys( spiral_mcp_settings_defaults() ) as $key ) {
		$before[ $key ] = spiral_mcp_setting( $key );
	}
	$days = function ( $field ) {
		$v = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return min( 3650, $v );
	};
	$new = array(
		'auto_purge'               => ! empty( $_POST['auto_purge'] ),
		'log_retention_days'       => $days( 'log_retention_days' ),
		'snapshot_retention_days'  => $days( 'snapshot_retention_days' ),
		'online_download'          => ! empty( $_POST['online_download'] ),
		'drop_tables_on_uninstall' => ! empty( $_POST['drop_tables_on_uninstall'] ),
		'plugins_management'       => ! empty( $_POST['plugins_management'] ),
		'upload_link_max_mb'       => min( 10240, isset( $_POST['upload_link_max_mb'] ) ? absint( wp_unslash( $_POST['upload_link_max_mb'] ) ) : 20 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
	);
	update_option( 'spiral_mcp_settings', $new, true );
	$changed = array();
	foreach ( $new as $key => $value ) {
		if ( $before[ $key ] !== $value ) {
			$changed[ $key ] = array( 'from' => $before[ $key ], 'to' => $value );
		}
	}
	spiral_mcp_admin_log( 'settings', array( 'changed' => $changed ) );
	wp_safe_redirect( spiral_mcp_admin_url( 'behavior', array( 'updated' => 1 ) ) );
	exit;
} );

/** Aviso para la siguiente carga de la página. $extra: HTML ya escapado. */
function spiral_mcp_admin_notice( $type, $message, $extra = '' ) {
	set_transient( 'spiral_mcp_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message, 'extra' => $extra ), 5 * MINUTE_IN_SECONDS );
}

/** Formulario (botón) para restaurar un snapshot desde la pestaña Actividad. */
function spiral_mcp_admin_restore_button( $snapshot_id, $label, $include_later = false, $class = 'button button-small' ) {
	$confirm = $include_later
		? 'Se restaurará el estado completo de este snapshot, deshaciendo también los cambios posteriores sobre el mismo objeto. ¿Seguir?'
		: '¿Restaurar este snapshot? Antes se guarda una copia del estado actual, así que se puede deshacer.';
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">'
		. '<input type="hidden" name="action" value="spiral_mcp_restore">'
		. '<input type="hidden" name="snapshot_id" value="' . (int) $snapshot_id . '">'
		. ( $include_later ? '<input type="hidden" name="include_later" value="1">' : '' )
		. wp_nonce_field( 'spiral_mcp_restore_' . (int) $snapshot_id, '_wpnonce', true, false )
		. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

add_action( 'admin_post_spiral_mcp_restore', function () {
	$id = isset( $_POST['snapshot_id'] ) ? absint( wp_unslash( $_POST['snapshot_id'] ) ) : 0;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tienes permiso para cambiar esto.', 403 );
	}
	check_admin_referer( 'spiral_mcp_restore_' . $id );
	$include_later = ! empty( $_POST['include_later'] );
	$res           = spiral_mcp_snapshot_restore( $id, array( 'include_later' => $include_later ) );
	if ( is_wp_error( $res ) ) {
		$extra = '';
		if ( 'spiral_mcp_snapshot_later' === $res->get_error_code() ) {
			$extra = '<p>' . spiral_mcp_admin_restore_button( $id, 'Restaurar igualmente (deshace también los posteriores)', true, 'button' ) . '</p>';
		}
		spiral_mcp_admin_notice( 'error', $res->get_error_message(), $extra );
	} else {
		if ( spiral_mcp_setting( 'auto_purge' ) && function_exists( 'spiral_mcp_cache_purge' ) ) {
			spiral_mcp_cache_purge( 'all' );
		}
		spiral_mcp_admin_notice( 'success', sprintf( 'Snapshot #%d restaurado. El estado anterior quedó guardado como snapshot #%d, por si quieres deshacer la restauración.', $id, (int) ( $res['snapshot_of_previous'] ?? 0 ) ) );
	}
	spiral_mcp_admin_log( 'restore-snapshot', array( 'id' => $id, 'include_later' => $include_later, 'result' => is_wp_error( $res ) ? $res->get_error_code() : 'ok' ) );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : spiral_mcp_admin_url( 'activity' ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * Pintar
 * ---------------------------------------------------------------------- */

function spiral_mcp_admin_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'abilities'   => 'Abilities',
		'permissions' => 'Permisos del agente',
		'behavior'    => 'Comportamiento',
		'activity'    => 'Actividad',
	);
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'abilities'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab  = isset( $tabs[ $tab ] ) ? $tab : 'abilities';
	echo '<div class="wrap spiral-mcp-admin"><h1>Spiral-MCP</h1>';
	if ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>Cambios guardados. Los clientes MCP conectados verán la lista nueva de herramientas al volver a pedirla (en algunos hay que reconectar).</p></div>';
	}
	$notice = get_transient( 'spiral_mcp_admin_notice_' . get_current_user_id() );
	if ( is_array( $notice ) ) {
		delete_transient( 'spiral_mcp_admin_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>%s</div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ), $notice['extra'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- extra se construye con funciones de escape.
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $id => $label ) {
		printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( spiral_mcp_admin_url( $id ) ), $tab === $id ? ' nav-tab-active' : '', esc_html( $label ) );
	}
	echo '</nav>';
	if ( 'permissions' === $tab ) {
		spiral_mcp_admin_render_permissions();
	} elseif ( 'behavior' === $tab ) {
		spiral_mcp_admin_render_behavior();
	} elseif ( 'activity' === $tab ) {
		spiral_mcp_admin_render_activity();
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
	$missing   = array(); // categoría => requisito => nombres: abilities cuyo requisito (YOOtheme…) no cumple el sitio.
	$external  = array(); // categorías de otro plugin (Elementor) que Spiral envuelve.
	foreach ( $catalog as $name => $info ) {
		if ( empty( $info['available'] ) ) {
			$missing[ $info['category'] ][ (string) $info['requires'] ][] = $name;
			continue;
		}
		$by_cat[ $info['category'] ][ $name ] = $info;
		if ( ! empty( $info['external'] ) ) {
			$external[ $info['category'] ] = true;
		}
	}
	// Las categorías de otro plugin, al final: primero las de Spiral.
	foreach ( array_keys( $external ) as $cat ) {
		$items = $by_cat[ $cat ];
		unset( $by_cat[ $cat ] );
		$by_cat[ $cat ] = $items;
	}
	$available = array_filter( $catalog, function ( $i ) {
		return ! empty( $i['available'] );
	} );
	$off       = count( array_filter( $available, function ( $i ) {
		return ! $i['enabled'];
	} ) );
	$strip     = function ( $n ) {
		return preg_replace( '#^spiral-mcp/#', '', $n );
	};
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
	<p>Decide qué herramientas ve el agente. Una ability desactivada no se publica en el MCP: el agente no la ve ni puede usarla. <strong><?php echo (int) ( count( $available ) - $off ); ?></strong> de <?php echo (int) count( $available ); ?> activas.
	<?php echo $protected ? ' ' . spiral_mcp_admin_code_list( array_map( function ( $n ) { // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en la función.
		return preg_replace( '#^spiral-mcp/#', '', $n );
	}, $protected ) ) . ' están siempre activas: sin ellas el agente no podría ver ni deshacer sus cambios.' : ''; ?></p>
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
			<?php if ( ! empty( $external[ $cat ] ) ) : ?>
				<p class="description">Herramientas del MCP de Elementor (wp-admin > Elementor > Elementor MCP), que Elementor registra y publica por su cuenta. Spiral-MCP les añade sus permisos por grupo (content, design, media), el registro de actividad y la purga automática de caché. Una desactivada aquí deja de publicarse en el MCP y responde 403 si se llama igualmente.</p>
			<?php endif; ?>
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
			<?php foreach ( $missing[ $cat ] ?? array() as $req => $names ) : ?>
				<?php $r = spiral_mcp_requirements()[ $req ] ?? array(); ?>
				<p class="description"><strong>No disponibles en este sitio</strong> (<?php echo esc_html( $r['reason'] ?? $req ); ?>): <?php echo spiral_mcp_admin_code_list( array_map( $strip, $names ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en la función. ?></p>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<?php foreach ( array_diff_key( $missing, $by_cat ) as $cat => $by_req ) : ?>
			<h2><?php echo esc_html( spiral_mcp_admin_category_label( $cat ) ); ?></h2>
			<?php foreach ( $by_req as $req => $names ) : ?>
				<?php $r = spiral_mcp_requirements()[ $req ] ?? array(); ?>
				<p class="description"><strong>No disponibles en este sitio</strong> (<?php echo esc_html( $r['reason'] ?? $req ); ?>): <?php echo spiral_mcp_admin_code_list( array_map( $strip, $names ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en la función. ?></p>
			<?php endforeach; ?>
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
	<p>El rol del agente nunca recibe estas capacidades nativas de WordPress, aunque un filtro las añada: <?php echo spiral_mcp_admin_code_list( spiral_mcp_never_granted_caps() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en la función. ?>.</p>
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

function spiral_mcp_admin_render_behavior() {
	$v       = function ( $key ) {
		return spiral_mcp_setting( $key );
	};
	$filters = array();
	foreach ( array( 'spiral_mcp_auto_clear_cache', 'spiral_mcp_log_retention_days', 'spiral_mcp_snapshot_retention_days', 'spiral_mcp_uninstall_drop_tables' ) as $hook ) {
		if ( has_filter( $hook ) ) {
			$filters[] = $hook;
		}
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="spiral_mcp_save_behavior">
		<?php wp_nonce_field( 'spiral_mcp_save_behavior' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Purga automática de la caché</th>
				<td><label><input type="checkbox" name="auto_purge" value="1" <?php checked( $v( 'auto_purge' ) ); ?>> Purgar la caché de páginas de lo que cambia después de cada escritura del agente</label>
				<p class="description">Si un contenido cambia, se purga ese contenido, la portada y sus listados; si es un cambio de diseño global, todo. Desactívalo solo si prefieres purgar a mano (el agente puede seguir usando <code>purge-cache</code>).</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="log_retention_days">Conservar el registro de actividad</label></th>
				<td><input type="number" min="0" max="3650" id="log_retention_days" name="log_retention_days" value="<?php echo (int) $v( 'log_retention_days' ); ?>" class="small-text"> días
				<p class="description">0 = no borrar nunca. La limpieza se hace una vez al día.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="snapshot_retention_days">Conservar los snapshots</label></th>
				<td><input type="number" min="0" max="3650" id="snapshot_retention_days" name="snapshot_retention_days" value="<?php echo (int) $v( 'snapshot_retention_days' ); ?>" class="small-text"> días
				<p class="description">Las copias que permiten deshacer cambios de configuración, menús, widgets, medios… Pasado este plazo ya no se pueden restaurar. 0 = no borrar nunca.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="upload_link_max_mb">Tamaño máximo de las subidas directas</label></th>
				<td><input type="number" min="0" max="10240" id="upload_link_max_mb" name="upload_link_max_mb" value="<?php echo (int) $v( 'upload_link_max_mb' ); ?>" class="small-text"> MB
				<p class="description">Límite de cada archivo enviado por un enlace de <code>create-upload-url</code>. Nunca supera el del servidor (ahora <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>). 0 = el del servidor.</p></td>
			</tr>
			<tr>
				<th scope="row">Librería online de YOOtheme</th>
				<td><label><input type="checkbox" name="online_download" value="1" <?php checked( $v( 'online_download' ) ); ?>> Permitir que el agente descargue e importe diseños usando la clave de API de YOOtheme del sitio</label>
				<p class="description">El catálogo se puede consultar siempre (es público). La clave nunca se muestra al agente.</p></td>
			</tr>
			<tr>
				<th scope="row">Plugins</th>
				<td><label><input type="checkbox" name="plugins_management" value="1" <?php checked( $v( 'plugins_management' ) ); ?>> Permitir que el agente gestione plugins de wordpress.org</label>
				<p class="description">Es el segundo cerrojo: además, el rol del agente necesita la capacidad <code>spiral_mcp_plugins</code> (pestaña Permisos del agente). Con esto activo, el agente podrá instalar, activar, desactivar, actualizar y borrar plugins; Spiral-MCP-Control y MCP Adapter están protegidos. <?php echo wp_is_file_mod_allowed( 'spiral_mcp_plugins' ) ? '' : '<strong>Este sitio no permite modificar archivos (DISALLOW_FILE_MODS): no funcionará.</strong>'; ?></p></td>
			</tr>
			<tr>
				<th scope="row">Al desinstalar</th>
				<td><label><input type="checkbox" name="drop_tables_on_uninstall" value="1" <?php checked( $v( 'drop_tables_on_uninstall' ) ); ?>> Borrar también el registro de actividad y los snapshots</label>
				<p class="description">Si no se marca, las tablas se conservan por si se vuelve a instalar el plugin.</p></td>
			</tr>
		</table>
		<?php if ( $filters ) : ?>
			<p><strong>Atención:</strong> hay código que modifica estos ajustes con filtros (<?php echo spiral_mcp_admin_code_list( $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en la función. ?>); tienen la última palabra sobre lo que se guarde aquí.</p>
		<?php endif; ?>
		<?php submit_button( 'Guardar' ); ?>
	</form>
	<?php
}

function spiral_mcp_admin_render_activity() {
	global $wpdb;
	$table = spiral_mcp_table( 'log' );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtros de lectura.
	$f_user    = isset( $_GET['f_user'] ) ? absint( $_GET['f_user'] ) : 0;
	$f_result  = isset( $_GET['f_result'] ) ? sanitize_key( wp_unslash( $_GET['f_result'] ) ) : '';
	$f_ability = isset( $_GET['f_ability'] ) ? sanitize_text_field( wp_unslash( $_GET['f_ability'] ) ) : '';
	$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable
	$per    = 50;
	$where  = array( '1=1' );
	$values = array();
	if ( $f_user ) {
		$where[]  = 'user_id = %d';
		$values[] = $f_user;
	}
	if ( 'ok' === $f_result ) {
		$where[] = "result = 'ok'";
	} elseif ( 'error' === $f_result ) {
		$where[] = "result <> 'ok'";
	}
	if ( '' !== $f_ability ) {
		$where[]  = 'ability = %s';
		$values[] = $f_ability;
	}
	$sql_where = implode( ' AND ', $where );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql_where", $values ) : "SELECT COUNT(*) FROM $table WHERE $sql_where" );
	$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per, ( $paged - 1 ) * $per ) ) ) );
	$users     = $wpdb->get_col( "SELECT DISTINCT user_id FROM $table ORDER BY user_id" );
	$abilities = $wpdb->get_col( "SELECT DISTINCT ability FROM $table ORDER BY ability" );
	// phpcs:enable
	$name = function ( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return $u ? $u->display_name . ' (' . $u->user_login . ')' : ( $uid ? '#' . $uid : 'Sistema' );
	};
	?>
	<p>Cambios hechos por el agente (y por esta página), del más reciente al más antiguo. Los cambios de contenido se deshacen con las revisiones de WordPress; los demás, restaurando su snapshot.</p>
	<form method="get" style="margin:1em 0">
		<input type="hidden" name="page" value="spiral-mcp"><input type="hidden" name="tab" value="activity">
		<select name="f_user"><option value="0">Todos los usuarios</option>
			<?php foreach ( $users as $uid ) : ?>
				<option value="<?php echo (int) $uid; ?>" <?php selected( $f_user, (int) $uid ); ?>><?php echo esc_html( $name( $uid ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="f_result">
			<option value="">Todos los resultados</option>
			<option value="ok" <?php selected( $f_result, 'ok' ); ?>>Correctos</option>
			<option value="error" <?php selected( $f_result, 'error' ); ?>>Con error</option>
		</select>
		<select name="f_ability"><option value="">Todas las acciones</option>
			<?php foreach ( $abilities as $ab ) : ?>
				<option value="<?php echo esc_attr( $ab ); ?>" <?php selected( $f_ability, $ab ); ?>><?php echo esc_html( preg_replace( '#^spiral-mcp/#', '', $ab ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button">Filtrar</button>
		<span class="description"><?php echo (int) $total; ?> registros</span>
	</form>
	<?php if ( ! $rows ) : ?>
		<p>No hay actividad registrada<?php echo $f_user || $f_result || $f_ability ? ' con estos filtros' : ''; ?>.</p>
		<?php
		return;
	endif;
	?>
	<table class="widefat striped">
		<thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Objeto</th><th>Resultado</th><th>Deshacer</th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$time   = strtotime( $row->created_at . ' UTC' );
			$object = trim( $row->object_type . ( '' !== (string) $row->object_id ? ' ' . $row->object_id : '' ) );
			$link   = '';
			if ( in_array( $row->object_type, array( 'post', 'seo' ), true ) && ctype_digit( (string) $row->object_id ) && get_post( (int) $row->object_id ) ) {
				$link = get_edit_post_link( (int) $row->object_id );
			} elseif ( 'layout' === $row->object_type && preg_match( '/^post:(\d+)$/', (string) $row->object_id, $m ) && get_post( (int) $m[1] ) ) {
				$link = get_edit_post_link( (int) $m[1] );
			}
			$input = json_decode( (string) $row->input_json, true );
			?>
			<tr>
				<td title="<?php echo esc_attr( $row->created_at ); ?> UTC"><?php echo esc_html( wp_date( 'Y-m-d H:i', $time ) ); ?><br><span class="description"><?php echo esc_html( sprintf( 'hace %s', human_time_diff( $time ) ) ); ?></span></td>
				<td><?php echo esc_html( $name( $row->user_id ) ); ?></td>
				<td><code><?php echo esc_html( preg_replace( '#^spiral-mcp/#', '', $row->ability ) ); ?></code>
					<?php if ( null !== $input && array() !== $input ) : ?>
						<details><summary class="description">Entrada</summary><pre style="white-space:pre-wrap;max-width:60ch;max-height:20em;overflow:auto;font-size:11px"><?php echo esc_html( wp_json_encode( $input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
					<?php endif; ?>
				</td>
				<td><?php echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $object ) . '</a>' : esc_html( $object ); ?></td>
				<td><?php echo 'ok' === $row->result ? '<span style="color:#00a32a">ok</span>' : '<span style="color:#b32d2e">' . esc_html( $row->result ) . '</span>'; ?><br><span class="description"><?php echo (int) $row->duration_ms; ?> ms</span></td>
				<td>
					<?php if ( $row->snapshot_id && 'ok' === $row->result ) : ?>
						<span class="description">snapshot #<?php echo (int) $row->snapshot_id; ?></span><br>
						<?php echo spiral_mcp_admin_restore_button( (int) $row->snapshot_id, 'Restaurar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construido con funciones de escape. ?>
					<?php elseif ( $link && 'ok' === $row->result ) : ?>
						<span class="description">Revisiones del contenido</span>
					<?php else : ?>
						<span class="description">—</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$pages = (int) ceil( $total / $per );
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => $pages,
		) );
		echo '</div></div>';
	}
}
