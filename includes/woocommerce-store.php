<?php
/**
 * WooCommerce: ajustes de la tienda (por grupos, con lista blanca), zonas y métodos de envío,
 * tasas de impuestos y pasarelas de pago (solo lectura: sus ajustes llevan claves secretas).
 * Grupo de permisos "shop" + edit_theme_options, como los ajustes del sitio. Usa los helpers
 * de woocommerce.php.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Grupos de ajustes de WooCommerce que el agente puede leer y cambiar, además de los de cada
 * correo (email_*). Fuera: pagos (checkout), integraciones, wc_admin y punto de venta.
 */
function spiral_mcp_wc_settings_groups() {
	return (array) apply_filters( 'spiral_mcp_wc_settings_groups', array( 'general', 'products', 'tax', 'shipping', 'account', 'email', 'advanced', 'site-visibility' ) );
}

/** ¿Grupo de ajustes permitido? */
function spiral_mcp_wc_settings_allowed( $group ) {
	return in_array( $group, spiral_mcp_wc_settings_groups(), true ) || 0 === strpos( (string) $group, 'email_' );
}

/**
 * Grupos que la API REST de WooCommerce lista pero sin campos (son pantallas de React): se leen
 * y escriben como opciones. id => [ label, options ].
 */
function spiral_mcp_wc_virtual_settings() {
	return array(
		'site-visibility' => array(
			'woocommerce_coming_soon'      => array( 'label' => 'Modo "próximamente": los visitantes sin sesión ven una página de espera en vez de la tienda (yes) o la tienda está abierta (no).', 'options' => array( 'yes', 'no' ) ),
			'woocommerce_store_pages_only' => array( 'label' => 'Con "próximamente", ocultar solo las páginas de la tienda (yes) o todo el sitio (no).', 'options' => array( 'yes', 'no' ) ),
		),
	);
}

/**
 * Campos que no se leen ni cambian desde aquí aunque estén en un grupo permitido: funciones
 * experimentales, el almacenamiento de pedidos (cambiarlo migra datos), seguimiento y analítica.
 */
function spiral_mcp_wc_settings_hidden( $id ) {
	return (bool) preg_match( '/^woocommerce_(feature_|custom_orders_table|hpos_|allow_tracking$|show_marketplace_suggestions$|analytics_enabled$|cart_save_for_later|product_wishlist)/', (string) $id );
}

/** Campos de un grupo (de la API o virtual), sin los ocultos. */
function spiral_mcp_wc_settings_fields( $group ) {
	$virtual = spiral_mcp_wc_virtual_settings();
	if ( isset( $virtual[ $group ] ) ) {
		$out = array();
		foreach ( $virtual[ $group ] as $id => $def ) {
			$out[] = array( 'id' => $id, 'label' => $def['label'], 'type' => 'select', 'value' => get_option( $id, 'no' ), 'options' => array_combine( $def['options'], $def['options'] ) );
		}
		return $out;
	}
	$fields = spiral_mcp_wc_rest( 'GET', 'settings/' . $group );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	return array_values( array_filter( (array) $fields, function ( $f ) {
		return ! spiral_mcp_wc_settings_hidden( $f['id'] ?? '' );
	} ) );
}

/** Escribe ajustes ya comprobados de un grupo: id => valor guardado o WP_Error si WooCommerce lo rechazó. */
function spiral_mcp_wc_settings_write( $group, array $values ) {
	$virtual = spiral_mcp_wc_virtual_settings();
	$out     = array();
	if ( isset( $virtual[ $group ] ) ) {
		foreach ( $values as $id => $value ) {
			$allowed = $virtual[ $group ][ $id ]['options'];
			if ( ! in_array( $value, $allowed, true ) ) {
				$out[ $id ] = new WP_Error( 'spiral_mcp_bad_value', sprintf( 'Debe ser uno de: %s.', implode( ', ', $allowed ) ) );
				continue;
			}
			update_option( $id, $value );
			$out[ $id ] = get_option( $id );
		}
		return $out;
	}
	$update = array();
	foreach ( $values as $id => $value ) {
		$update[] = array( 'id' => (string) $id, 'value' => $value );
	}
	$res = spiral_mcp_wc_rest( 'POST', 'settings/' . $group . '/batch', array( 'update' => $update ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	foreach ( (array) ( $res['update'] ?? array() ) as $i => $item ) {
		$id         = (string) ( $item['id'] ?? $update[ $i ]['id'] );
		$out[ $id ] = empty( $item['error'] ) ? ( $item['value'] ?? null ) : new WP_Error( 'spiral_mcp_wc_setting', (string) ( $item['error']['message'] ?? 'error' ) );
	}
	return $out;
}

/** Campos de un correo que deciden a quién llega: cambiarlos exige confirm (como en los formularios). */
function spiral_mcp_wc_settings_recipient_fields() {
	return array( 'recipient', 'cc', 'bcc' );
}

/** Campo de ajustes resumido. */
function spiral_mcp_wc_setting_row( array $f ) {
	$row = array(
		'id'    => (string) $f['id'],
		'label' => (string) ( $f['label'] ?? '' ),
		'type'  => (string) ( $f['type'] ?? '' ),
		'value' => $f['value'] ?? null,
	);
	if ( isset( $f['default'] ) && '' !== $f['default'] ) {
		$row['default'] = $f['default'];
	}
	if ( ! empty( $f['options'] ) ) {
		$row['options'] = $f['options'];
	}
	if ( ! empty( $f['description'] ) ) {
		$row['description'] = wp_strip_all_tags( (string) $f['description'] );
	}
	return $row;
}

/** Zona de envío con sus ubicaciones y métodos (valores de los ajustes de cada método). */
function spiral_mcp_wc_zone_out( $id ) {
	$zone = spiral_mcp_wc_rest( 'GET', 'shipping/zones/' . (int) $id );
	if ( is_wp_error( $zone ) ) {
		return spiral_mcp_wc_not_found( $zone ) ? new WP_Error( 'spiral_mcp_wc_not_found', 'No existe esa zona de envío. Búscala con spiral-mcp/list-shipping-zones.' ) : $zone;
	}
	$locations = (int) $id ? spiral_mcp_wc_rest( 'GET', 'shipping/zones/' . (int) $id . '/locations' ) : array();
	$methods   = spiral_mcp_wc_rest( 'GET', 'shipping/zones/' . (int) $id . '/methods' );
	$out       = array(
		'id'        => (int) $zone['id'],
		'name'      => (string) $zone['name'],
		'order'     => (int) $zone['order'],
		'locations' => is_wp_error( $locations ) ? array() : array_map( function ( $l ) {
			return array( 'code' => (string) $l['code'], 'type' => (string) $l['type'] );
		}, (array) $locations ),
		'methods'   => array(),
	);
	foreach ( is_wp_error( $methods ) ? array() : (array) $methods as $m ) {
		$settings = array();
		foreach ( (array) ( $m['settings'] ?? array() ) as $key => $s ) {
			$settings[ $key ] = $s['value'] ?? null;
		}
		$out['methods'][] = array(
			'instance_id' => (int) $m['instance_id'],
			'method_id'   => (string) $m['method_id'],
			'title'       => (string) $m['title'],
			'enabled'     => (bool) $m['enabled'],
			'order'       => (int) $m['order'],
			'settings'    => $settings,
		);
	}
	return $out;
}

/** Tasa de impuesto resumida. */
function spiral_mcp_wc_tax_row( array $r ) {
	return array_intersect_key( $r, array_flip( array( 'id', 'country', 'state', 'postcodes', 'cities', 'rate', 'name', 'priority', 'compound', 'shipping', 'order', 'class' ) ) );
}

/** Sincroniza una colección por lotes con una lista guardada: actualiza, crea lo que falta y borra lo que sobra. */
function spiral_mcp_wc_sync_collection( $collection, array $saved, array $now ) {
	$now   = array_column( $now, null, 'id' );
	$batch = array( 'create' => array(), 'update' => array(), 'delete' => array() );
	foreach ( $saved as $item ) {
		$body = spiral_mcp_wc_restore_body( (array) $item, $collection . '/0' );
		if ( isset( $now[ $item['id'] ] ) ) {
			$batch['update'][] = array( 'id' => (int) $item['id'] ) + $body;
			unset( $now[ $item['id'] ] );
		} else {
			$batch['create'][] = $body;
		}
	}
	$batch['delete'] = array_map( 'intval', array_keys( $now ) );
	$batch           = array_filter( $batch );
	if ( ! $batch ) {
		return true;
	}
	$res = spiral_mcp_wc_rest( 'POST', $collection . '/batch', $batch );
	return is_wp_error( $res ) ? $res : true;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_wc_active() ) {
		return $kinds;
	}
	// Valores de un grupo de ajustes (ref = settings/<grupo>).
	$kinds['wc_settings'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$fields = spiral_mcp_wc_settings_fields( substr( (string) $ref, strlen( 'settings/' ) ) );
			return is_wp_error( $fields ) ? $fields : array_column( $fields, 'value', 'id' );
		},
		'restore' => function ( $ref, $data ) {
			$group = substr( (string) $ref, strlen( 'settings/' ) );
			if ( ! spiral_mcp_wc_settings_allowed( $group ) ) {
				return new WP_Error( 'spiral_mcp_wc_bad_group', 'Ese grupo de ajustes ya no se puede cambiar desde aquí.' );
			}
			$data = array_filter( (array) $data, function ( $id ) {
				return ! spiral_mcp_wc_settings_hidden( $id );
			}, ARRAY_FILTER_USE_KEY );
			$res  = $data ? spiral_mcp_wc_settings_write( $group, $data ) : array();
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( $res as $id => $value ) {
				if ( is_wp_error( $value ) ) {
					return new WP_Error( 'spiral_mcp_wc_setting', sprintf( '"%s": %s', $id, $value->get_error_message() ) );
				}
			}
			return true;
		},
	);
	// Zona de envío con ubicaciones y métodos (ref = shipping/zones/<id>).
	$kinds['wc_shipping_zone'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$zone = spiral_mcp_wc_zone_out( (int) basename( (string) $ref ) );
			return is_wp_error( $zone ) ? null : $zone;
		},
		'restore' => function ( $ref, $data ) {
			$id      = (int) basename( (string) $ref );
			$current = spiral_mcp_wc_zone_out( $id );
			if ( null === $data ) {
				if ( is_wp_error( $current ) ) {
					return true;
				}
				$res = spiral_mcp_wc_rest( 'DELETE', $ref, array( 'force' => true ) );
				return is_wp_error( $res ) ? $res : array( 'deleted' => $ref );
			}
			$out = true;
			if ( is_wp_error( $current ) ) {
				$res = spiral_mcp_wc_rest( 'POST', 'shipping/zones', array( 'name' => $data['name'], 'order' => $data['order'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$id      = (int) $res['id'];
				$out     = array( 'recreated_id' => $id, 'note' => 'La zona ya no existía: se ha creado de nuevo con otro ID.' );
				$current = array( 'methods' => array() );
			} elseif ( $id ) {
				$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $id, array( 'name' => $data['name'], 'order' => $data['order'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			if ( $id ) {
				$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $id . '/locations', array_values( (array) $data['locations'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			$now = array_column( (array) $current['methods'], null, 'instance_id' );
			foreach ( (array) $data['methods'] as $m ) {
				$body = array( 'enabled' => $m['enabled'], 'order' => $m['order'], 'settings' => $m['settings'] );
				if ( isset( $now[ $m['instance_id'] ] ) ) {
					$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $id . '/methods/' . $m['instance_id'], $body );
					unset( $now[ $m['instance_id'] ] );
				} else {
					$res = spiral_mcp_wc_rest( 'POST', 'shipping/zones/' . $id . '/methods', array( 'method_id' => $m['method_id'] ) + $body );
				}
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			foreach ( array_keys( $now ) as $instance ) {
				spiral_mcp_wc_rest( 'DELETE', 'shipping/zones/' . $id . '/methods/' . $instance, array( 'force' => true ) );
			}
			return $out;
		},
	);
	// Todas las tasas de impuestos (ref = taxes).
	$kinds['wc_tax_rates'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_theme_options',
		'capture' => function () {
			$rates = spiral_mcp_wc_all( 'taxes' );
			return is_wp_error( $rates ) ? $rates : array_map( 'spiral_mcp_wc_tax_row', $rates );
		},
		'restore' => function ( $ref, $data ) {
			$now = spiral_mcp_wc_all( 'taxes' );
			return is_wp_error( $now ) ? $now : spiral_mcp_wc_sync_collection( 'taxes', (array) $data, $now );
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_wc_active() ) {
		return;
	}

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-shop-settings', array(
		'label'               => 'Leer los ajustes de la tienda',
		'description'         => 'Ajustes de WooCommerce por grupos (los de WooCommerce > Ajustes). Sin "group" devuelve los grupos disponibles: general (dirección, países de venta y envío, impuestos y cupones activados, moneda y formato de precio), products (página de la tienda, unidades, valoraciones, inventario), tax (precios con o sin impuestos, cálculo y visualización), shipping (calculadora, destino), account (compra como invitado, registro, privacidad y retención de datos), email (remitente y diseño de los correos), email_<correo> (cada correo: activado, destinatario, asunto…), advanced (páginas de carrito, pago, mi cuenta y términos; endpoints; sin las funciones experimentales ni el almacenamiento de pedidos) y site-visibility (modo "próximamente": mientras esté en "yes" los visitantes no ven la tienda). Con "group" devuelve sus campos: id, etiqueta, tipo, valor, valor por defecto y opciones posibles. Las pasarelas de pago no están aquí: spiral-mcp/list-payment-gateways.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'group' => array( 'type' => 'string' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$group = (string) ( ( (array) $input )['group'] ?? '' );
			if ( '' === $group ) {
				$groups = spiral_mcp_wc_rest( 'GET', 'settings' );
				if ( is_wp_error( $groups ) ) {
					return $groups;
				}
				$out = array();
				foreach ( (array) $groups as $g ) {
					if ( spiral_mcp_wc_settings_allowed( $g['id'] ) ) {
						$out[] = array( 'group' => (string) $g['id'], 'label' => (string) $g['label'], 'description' => wp_strip_all_tags( (string) $g['description'] ) );
					}
				}
				return array( 'groups' => $out );
			}
			if ( ! spiral_mcp_wc_settings_allowed( $group ) ) {
				return new WP_Error( 'spiral_mcp_wc_bad_group', sprintf( 'El grupo "%s" no existe o no se puede leer desde aquí (pagos, integraciones). Lista los grupos sin "group".', $group ) );
			}
			$fields = spiral_mcp_wc_settings_fields( $group );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			return array( 'group' => $group, 'fields' => array_map( 'spiral_mcp_wc_setting_row', $fields ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-shop-settings', array(
		'label'               => 'Cambiar los ajustes de la tienda',
		'description'         => 'Cambia ajustes de un grupo de WooCommerce: "group" y "values" ({"<id del campo>": valor}). Primero lee el grupo con spiral-mcp/get-shop-settings y usa sus ids, tipos y opciones (las casillas son "yes"/"no"; las páginas, su ID). WooCommerce valida cada valor; los que rechaza vuelven en "errors". Cambiar a quién llega un correo (recipient, cc, bcc de un grupo email_*) exige "confirm": true. Los cambios afectan a toda la tienda (precios, impuestos, quién puede comprar): confírmalos con el usuario. Antes guarda un snapshot del grupo ("snapshot_id") y después purga la caché.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'shop_settings', 'id' => 'group' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'group', 'values' ),
			'properties' => array(
				'group'   => array( 'type' => 'string' ),
				'values'  => array( 'type' => 'object', 'additionalProperties' => true ),
				'confirm' => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$group  = (string) ( $input['group'] ?? '' );
			$values = (array) ( $input['values'] ?? array() );
			if ( ! spiral_mcp_wc_settings_allowed( $group ) ) {
				return new WP_Error( 'spiral_mcp_wc_bad_group', sprintf( 'El grupo "%s" no se puede cambiar desde aquí.', $group ) );
			}
			if ( ! $values ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "values" al menos un ajuste.' );
			}
			$fields = spiral_mcp_wc_settings_fields( $group );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$current = array_column( $fields, 'value', 'id' );
			$unknown = array_diff( array_keys( $values ), array_keys( $current ) );
			if ( $unknown ) {
				return new WP_Error( 'spiral_mcp_bad_key', sprintf( 'Ajustes que no existen en "%s": %s. Disponibles: %s.', $group, implode( ', ', $unknown ), implode( ', ', array_keys( $current ) ) ) );
			}
			if ( 0 === strpos( $group, 'email_' ) && array_intersect( array_keys( $values ), spiral_mcp_wc_settings_recipient_fields() ) ) {
				$ok = spiral_mcp_require_confirm( $input, 'cambiar a quién llega este correo' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_settings', 'settings/' . $group, 'update-shop-settings' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_settings_write( $group, $values );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$errors  = array();
			$changed = array();
			foreach ( $res as $id => $value ) {
				if ( is_wp_error( $value ) ) {
					$errors[ $id ] = $value->get_error_message();
				} elseif ( ( $current[ $id ] ?? null ) !== $value ) {
					$changed[ $id ] = array( 'before' => $current[ $id ] ?? null, 'after' => $value );
				}
			}
			if ( $changed ) {
				spiral_mcp_wc_purge();
			}
			return array( 'group' => $group, 'changed' => $changed, 'errors' => $errors, 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* ---------------------------------------------------------------------
	 * Envíos
	 * ------------------------------------------------------------------ */

	spiral_mcp_register_ability( 'spiral-mcp/list-shipping-zones', array(
		'label'               => 'Listar zonas de envío',
		'description'         => 'Zonas de envío en orden de prioridad (un pedido usa la primera cuya ubicación encaja), cada una con sus ubicaciones (code + type: country "ES", state "ES:M", postcode "28001" o "280*", continent "EU") y sus métodos (instance_id, method_id, título, activado, ajustes como cost o min_amount). La zona 0 es "el resto del mundo". Los tipos de método disponibles están en "method_types".',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$zones = spiral_mcp_wc_rest( 'GET', 'shipping/zones' );
			if ( is_wp_error( $zones ) ) {
				return $zones;
			}
			$out = array();
			foreach ( (array) $zones as $z ) {
				$zone = spiral_mcp_wc_zone_out( (int) $z['id'] );
				if ( ! is_wp_error( $zone ) ) {
					$out[] = $zone;
				}
			}
			$types = spiral_mcp_wc_rest( 'GET', 'shipping_methods' );
			return array(
				'zones'        => $out,
				'method_types' => is_wp_error( $types ) ? array() : array_map( function ( $t ) {
					return array( 'id' => (string) $t['id'], 'title' => (string) $t['title'], 'description' => wp_strip_all_tags( (string) $t['description'] ) );
				}, (array) $types ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/save-shipping-zone', array(
		'label'               => 'Crear o cambiar una zona de envío',
		'description'         => 'Sin "id" crea una zona; con "id" cambia la indicada. "name", "order" (prioridad: menor = antes) y "locations" (lista completa, reemplaza la actual: [{"code": "ES", "type": "country"}, {"code": "ES:PM", "type": "state"}, {"code": "28*", "type": "postcode"}]). La zona 0 (resto del mundo) no admite nombre ni ubicaciones. Después añade métodos con spiral-mcp/save-shipping-method. Antes guarda un snapshot ("snapshot_id"; en una zona nueva, restaurarlo la borra).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'shipping_zone', 'id' => function ( $in, $result ) {
			return is_array( $result ) ? ( $result['id'] ?? '' ) : ( ( (array) $in )['id'] ?? '' );
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'order'     => array( 'type' => 'integer' ),
				'locations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code' => array( 'type' => 'string' ),
							'type' => array( 'type' => 'string', 'enum' => array( 'country', 'state', 'postcode', 'continent' ) ),
						),
					),
				),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$in   = (array) $input;
			$body = array_intersect_key( $in, array_flip( array( 'name', 'order' ) ) );
			if ( isset( $in['id'] ) ) {
				$id      = (int) $in['id'];
				$current = spiral_mcp_wc_zone_out( $id );
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if ( 0 === $id && ( $body || isset( $in['locations'] ) ) ) {
					return new WP_Error( 'spiral_mcp_wc_zone_zero', 'La zona 0 (resto del mundo) no admite nombre, orden ni ubicaciones: solo métodos.' );
				}
				$snapshot = spiral_mcp_snapshot_capture( 'wc_shipping_zone', 'shipping/zones/' . $id, 'save-shipping-zone' );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				if ( $body ) {
					$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $id, $body );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
				}
			} else {
				if ( empty( $body['name'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_input', 'Para crear una zona indica "name".' );
				}
				$res = spiral_mcp_wc_rest( 'POST', 'shipping/zones', $body );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$id       = (int) $res['id'];
				$snapshot = spiral_mcp_snapshot_take( 'wc_shipping_zone', 'shipping/zones/' . $id, null, 'save-shipping-zone' );
			}
			if ( isset( $in['locations'] ) ) {
				$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $id . '/locations', array_values( (array) $in['locations'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return spiral_mcp_wc_zone_out( $id ) + array( 'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-shipping-zone', array(
		'label'               => 'Borrar una zona de envío',
		'description'         => 'Borra una zona de envío con sus métodos; sus clientes pasan a la siguiente zona que encaje o a la zona 0. Exige "confirm": true. Antes guarda un snapshot (restaurarlo la vuelve a crear con otro ID).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'shipping_zone', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'confirm' => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar la zona de envío' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$id = (int) ( $input['id'] ?? 0 );
			if ( 0 === $id ) {
				return new WP_Error( 'spiral_mcp_wc_zone_zero', 'La zona 0 (resto del mundo) no se puede borrar.' );
			}
			$current = spiral_mcp_wc_zone_out( $id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_shipping_zone', 'shipping/zones/' . $id, 'delete-shipping-zone' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'DELETE', 'shipping/zones/' . $id, array( 'force' => true ) );
			return is_wp_error( $res ) ? $res : array( 'deleted' => true, 'id' => $id, 'name' => $current['name'], 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/save-shipping-method', array(
		'label'               => 'Añadir o cambiar un método de envío',
		'description'         => 'En la zona "zone_id": sin "instance_id" añade un método de tipo "method_id" (flat_rate = tarifa plana, free_shipping = envío gratuito, local_pickup = recogida; ver method_types en spiral-mcp/list-shipping-zones); con "instance_id" cambia ese método. "enabled", "order" y "settings" ({"title": "Envío estándar", "cost": "4.95"}; en free_shipping, "requires": "min_amount" y "min_amount": "50"; en flat_rate, "cost" admite [qty] y [fee percent="10"]). Antes guarda un snapshot de la zona.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'shipping_zone', 'id' => 'zone_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'zone_id' ),
			'properties' => array(
				'zone_id'     => array( 'type' => 'integer' ),
				'instance_id' => array( 'type' => 'integer' ),
				'method_id'   => array( 'type' => 'string' ),
				'enabled'     => array( 'type' => 'boolean' ),
				'order'       => array( 'type' => 'integer' ),
				'settings'    => array( 'type' => 'object', 'additionalProperties' => true ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$zone_id = (int) ( $input['zone_id'] ?? 0 );
			$current = spiral_mcp_wc_zone_out( $zone_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$body = array_intersect_key( (array) $input, array_flip( array( 'enabled', 'order', 'settings' ) ) );
			if ( empty( $input['instance_id'] ) && empty( $input['method_id'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "instance_id" (cambiar un método) o "method_id" (añadir uno).' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_shipping_zone', 'shipping/zones/' . $zone_id, 'save-shipping-method' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if ( ! empty( $input['instance_id'] ) ) {
				$res = spiral_mcp_wc_rest( 'PUT', 'shipping/zones/' . $zone_id . '/methods/' . (int) $input['instance_id'], $body );
			} else {
				$res = spiral_mcp_wc_rest( 'POST', 'shipping/zones/' . $zone_id . '/methods', array( 'method_id' => (string) $input['method_id'] ) + $body );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_wc_zone_out( $zone_id ) + array( 'instance_id' => (int) $res['instance_id'], 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-shipping-method', array(
		'label'               => 'Quitar un método de envío',
		'description'         => 'Quita un método ("instance_id") de una zona de envío. Exige "confirm": true. Para dejarlo sin borrarlo, mejor desactívalo con spiral-mcp/save-shipping-method y "enabled": false. Antes guarda un snapshot de la zona.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'shipping_zone', 'id' => 'zone_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'zone_id', 'instance_id', 'confirm' ),
			'properties' => array(
				'zone_id'     => array( 'type' => 'integer' ),
				'instance_id' => array( 'type' => 'integer' ),
				'confirm'     => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'quitar el método de envío' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$zone_id  = (int) ( $input['zone_id'] ?? 0 );
			$snapshot = spiral_mcp_snapshot_capture( 'wc_shipping_zone', 'shipping/zones/' . $zone_id, 'delete-shipping-method' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'DELETE', 'shipping/zones/' . $zone_id . '/methods/' . (int) ( $input['instance_id'] ?? 0 ), array( 'force' => true ) );
			return is_wp_error( $res ) ? $res : spiral_mcp_wc_zone_out( $zone_id ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* ---------------------------------------------------------------------
	 * Impuestos
	 * ------------------------------------------------------------------ */

	spiral_mcp_register_ability( 'spiral-mcp/list-tax-rates', array(
		'label'               => 'Listar impuestos',
		'description'         => 'Tasas de impuestos (país, provincia, códigos postales, ciudades, porcentaje, nombre, prioridad, compuesto, si se aplica al envío y clase) y las clases de impuesto ("standard" es la estándar; reduced-rate, zero-rate…). "class" filtra por clase. Para que se apliquen, el ajuste woocommerce_calc_taxes (grupo general de spiral-mcp/get-shop-settings) tiene que estar en "yes".',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array( 'class' => array( 'type' => 'string' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$class  = (string) ( ( (array) $input )['class'] ?? '' );
			$rates  = spiral_mcp_wc_all( 'taxes', '' === $class ? array() : array( 'class' => $class ) );
			$classes = spiral_mcp_wc_rest( 'GET', 'taxes/classes' );
			if ( is_wp_error( $rates ) ) {
				return $rates;
			}
			return array(
				'rates'   => array_map( 'spiral_mcp_wc_tax_row', $rates ),
				'classes' => is_wp_error( $classes ) ? array() : array_map( function ( $c ) {
					return array( 'slug' => (string) $c['slug'], 'name' => (string) $c['name'] );
				}, (array) $classes ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-tax-rates', array(
		'label'               => 'Crear, cambiar o borrar impuestos',
		'description'         => 'Tasas de impuestos en un solo paso: "create" (lista de tasas nuevas), "update" (lista con "id" y los campos que cambian) y "delete" (IDs; exige "confirm": true). Campos: country (código ISO, p. ej. "ES"; vacío = todos), state, postcodes (lista), cities (lista), rate (texto, "21.0000"), name ("IVA"), priority, compound, shipping (si grava el envío), order, class ("standard" o el slug de la clase). Afecta a los precios de todos los pedidos nuevos: confírmalo con el usuario. Antes guarda un snapshot de todas las tasas ("snapshot_id").',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'tax_rates', 'id' => function () {
			return 'taxes';
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'create'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'update'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'delete'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'confirm' => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$in    = (array) $input;
			$batch = array_filter( array_intersect_key( $in, array_flip( array( 'create', 'update', 'delete' ) ) ) );
			if ( ! $batch ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "create", "update" o "delete".' );
			}
			if ( ! empty( $batch['delete'] ) ) {
				$ok = spiral_mcp_require_confirm( $in, 'borrar tasas de impuestos' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			foreach ( (array) ( $batch['update'] ?? array() ) as $i => $r ) {
				if ( empty( $r['id'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'update[%d]: falta "id".', $i ) );
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_tax_rates', 'taxes', 'update-tax-rates' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'POST', 'taxes/batch', $batch );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$errors = array();
			foreach ( array( 'create', 'update', 'delete' ) as $op ) {
				foreach ( (array) ( $res[ $op ] ?? array() ) as $i => $item ) {
					if ( ! empty( $item['error'] ) ) {
						$errors[] = sprintf( '%s[%d]: %s', $op, $i, $item['error']['message'] ?? 'error' );
					}
				}
			}
			spiral_mcp_wc_purge();
			$rates = spiral_mcp_wc_all( 'taxes' );
			return array( 'rates' => is_wp_error( $rates ) ? array() : array_map( 'spiral_mcp_wc_tax_row', $rates ), 'errors' => $errors, 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* ---------------------------------------------------------------------
	 * Pasarelas de pago (solo lectura)
	 * ------------------------------------------------------------------ */

	spiral_mcp_register_ability( 'spiral-mcp/list-payment-gateways', array(
		'label'               => 'Listar pasarelas de pago',
		'description'         => 'Métodos de pago instalados (transferencia, contra reembolso, Stripe, PayPal…) con su título, descripción, orden y si están activados. Solo lectura: activarlos o configurarlos implica claves y cuentas de pago, y se hace en wp-admin (WooCommerce > Ajustes > Pagos). Si el usuario lo pide, explícale dónde.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$gateways = spiral_mcp_wc_rest( 'GET', 'payment_gateways' );
			if ( is_wp_error( $gateways ) ) {
				return $gateways;
			}
			return array(
				'gateways' => array_map( function ( $g ) {
					return array(
						'id'           => (string) $g['id'],
						'title'        => wp_strip_all_tags( (string) $g['title'] ),
						'description'  => wp_strip_all_tags( (string) $g['description'] ),
						'enabled'      => (bool) $g['enabled'],
						'order'        => is_numeric( $g['order'] ) ? (int) $g['order'] : null,
						'method_title' => wp_strip_all_tags( (string) $g['method_title'] ),
					);
				}, (array) $gateways ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
