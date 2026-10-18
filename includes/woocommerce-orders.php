<?php
/**
 * WooCommerce: pedidos (leer, cambiar estado y direcciones, notas, reembolsos) y clientes (solo
 * lectura). Grupo de permisos "orders": la capacidad spiral_mcp_orders no la tiene el rol del
 * agente por defecto, porque hay datos personales y dinero de por medio. Usa los helpers de
 * woocommerce.php; funciona igual con la tabla de pedidos de alto rendimiento (HPOS).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Campos de un pedido que cambia update-order (y restaura su snapshot). */
function spiral_mcp_wc_order_fields() {
	return array( 'status', 'customer_note', 'billing', 'shipping' );
}

/** Estados a los que pasar un pedido exige confirm: avisan al cliente o devuelven stock y dinero. */
function spiral_mcp_wc_order_confirm_statuses() {
	return array( 'cancelled', 'refunded', 'failed' );
}

/** Resumen de un pedido para los listados. */
function spiral_mcp_wc_order_row( array $o ) {
	return array(
		'id'             => (int) $o['id'],
		'number'         => (string) $o['number'],
		'status'         => (string) $o['status'],
		'date_created'   => (string) $o['date_created'],
		'total'          => (string) $o['total'],
		'currency'       => (string) $o['currency'],
		'customer_id'    => (int) $o['customer_id'],
		'customer'       => trim( $o['billing']['first_name'] . ' ' . $o['billing']['last_name'] ),
		'email'          => (string) $o['billing']['email'],
		'items'          => count( (array) $o['line_items'] ),
		'payment_method' => (string) $o['payment_method_title'],
	);
}

/** Pedido completo: limpio, con líneas resumidas, notas y reembolsos. */
function spiral_mcp_wc_order_out( $id ) {
	$o = spiral_mcp_wc_rest( 'GET', 'orders/' . (int) $id, array( 'context' => 'edit' ) );
	if ( is_wp_error( $o ) ) {
		return spiral_mcp_wc_not_found( $o ) ? new WP_Error( 'spiral_mcp_wc_not_found', 'No existe un pedido con ese ID. Búscalo con spiral-mcp/list-orders.' ) : $o;
	}
	$out               = spiral_mcp_wc_clean( $o );
	$out['line_items'] = array_map( function ( $l ) {
		return array(
			'id'           => (int) $l['id'],
			'name'         => (string) $l['name'],
			'product_id'   => (int) $l['product_id'],
			'variation_id' => (int) $l['variation_id'],
			'sku'          => (string) $l['sku'],
			'quantity'     => $l['quantity'],
			'subtotal'     => (string) $l['subtotal'],
			'total'        => (string) $l['total'],
			'total_tax'    => (string) $l['total_tax'],
		);
	}, (array) $o['line_items'] );
	$notes        = spiral_mcp_wc_rest( 'GET', 'orders/' . (int) $id . '/notes' );
	$out['notes'] = is_wp_error( $notes ) ? array() : array_map( function ( $n ) {
		return array( 'id' => (int) $n['id'], 'date' => (string) $n['date_created'], 'note' => wp_strip_all_tags( (string) $n['note'] ), 'customer_note' => (bool) $n['customer_note'], 'author' => (string) ( $n['author'] ?? '' ) );
	}, (array) $notes );
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots: estado, nota del cliente y direcciones de un pedido (ref = orders/<id>)
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_wc_active() ) {
		return $kinds;
	}
	$kinds['wc_order'] = array(
		'group'   => 'orders',
		'cap'     => 'edit_others_posts',
		'capture' => function ( $ref ) {
			return spiral_mcp_wc_capture( $ref, spiral_mcp_wc_order_fields() );
		},
		'restore' => function ( $ref, $data ) {
			if ( null === $data ) {
				return new WP_Error( 'spiral_mcp_bad_snapshot', 'Este snapshot no tiene datos del pedido.' );
			}
			$res = spiral_mcp_wc_rest( 'PUT', $ref, spiral_mcp_wc_restore_body( (array) $data, $ref, spiral_mcp_wc_order_fields() ) );
			return is_wp_error( $res ) ? $res : true;
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
		return current_user_can( 'edit_others_posts' );
	};

	$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' );

	spiral_mcp_register_ability( 'spiral-mcp/list-orders', array(
		'label'               => 'Listar pedidos',
		'description'         => 'Pedidos de WooCommerce, los más recientes primero, con número, estado, fecha, total, cliente, correo, número de líneas y método de pago. Filtros: status (any por defecto; pending = pendiente de pago, processing = pagado y por preparar, on-hold = en espera, completed, cancelled, refunded, failed), customer (ID de usuario), search (número, nombre, correo…), after y before (fechas ISO 8601). Contiene datos personales: no los copies fuera de lo que el usuario necesite. Para el detalle, spiral-mcp/get-order.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'status'   => array( 'type' => 'string', 'enum' => array_merge( array( 'any' ), $statuses ) ),
				'customer' => array( 'type' => 'integer' ),
				'search'   => array( 'type' => 'string' ),
				'after'    => array( 'type' => 'string' ),
				'before'   => array( 'type' => 'string' ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$params = array_intersect_key( (array) $input, array_flip( array( 'status', 'customer', 'search', 'after', 'before', 'page', 'per_page' ) ) );
			$params += array( 'per_page' => 20 );
			$data = spiral_mcp_wc_rest( 'GET', 'orders', $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return array( 'total' => spiral_mcp_wc_total(), 'items' => array_map( 'spiral_mcp_wc_order_row', (array) $data ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-order', array(
		'label'               => 'Leer un pedido',
		'description'         => 'Un pedido completo: estado, fechas, totales e impuestos, direcciones de facturación y envío, método de pago, líneas (producto, variación, cantidad, importes), envíos, cupones, reembolsos y notas (las privadas y las enviadas al cliente). Contiene datos personales.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			return spiral_mcp_wc_order_out( (int) ( $input['id'] ?? 0 ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-order', array(
		'label'               => 'Cambiar un pedido',
		'description'         => 'Cambia el estado ("status"), la nota del cliente ("customer_note") o las direcciones ("billing", "shipping": solo los campos enviados) de un pedido. Ojo: los cambios de estado disparan los correos de WooCommerce al cliente (processing, completed…) y mueven stock; pasar a cancelled, refunded o failed exige "confirm": true (refunded registra un reembolso completo sin devolver el dinero: para devolverlo usa spiral-mcp/create-refund). Las líneas y los importes no se cambian aquí. Antes guarda un snapshot del estado y las direcciones ("snapshot_id"); restaurarlo no deshace los correos ya enviados.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'log'                 => array( 'type' => 'order', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'status'        => array( 'type' => 'string', 'enum' => $statuses ),
				'customer_note' => array( 'type' => 'string' ),
				'billing'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'shipping'      => array( 'type' => 'object', 'additionalProperties' => true ),
				'confirm'       => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$id   = (int) ( $input['id'] ?? 0 );
			$body = array_intersect_key( (array) $input, array_flip( spiral_mcp_wc_order_fields() ) );
			if ( ! $body ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos uno de: ' . implode( ', ', spiral_mcp_wc_order_fields() ) . '.' );
			}
			$order = spiral_mcp_wc_rest( 'GET', 'orders/' . $id );
			if ( is_wp_error( $order ) ) {
				return spiral_mcp_wc_not_found( $order ) ? new WP_Error( 'spiral_mcp_wc_not_found', 'No existe un pedido con ese ID.' ) : $order;
			}
			if ( isset( $body['status'] ) && $body['status'] !== $order['status'] && in_array( $body['status'], spiral_mcp_wc_order_confirm_statuses(), true ) ) {
				$ok = spiral_mcp_require_confirm( $input, sprintf( 'pasar el pedido a "%s"', $body['status'] ) );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_order', 'orders/' . $id, 'update-order' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'PUT', 'orders/' . $id, $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'order' => spiral_mcp_wc_order_row( $res ), 'status_before' => (string) $order['status'], 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/add-order-note', array(
		'label'               => 'Añadir una nota a un pedido',
		'description'         => 'Añade una nota a un pedido. Por defecto es privada (solo la ve la tienda). Con "customer_note": true se envía por correo al cliente y exige "confirm": true: enséñale antes el texto al usuario. Las notas no se pueden borrar desde aquí.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'log'                 => array( 'type' => 'order', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'note' ),
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'note'          => array( 'type' => 'string' ),
				'customer_note' => array( 'type' => 'boolean' ),
				'confirm'       => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$customer = ! empty( $input['customer_note'] );
			if ( $customer ) {
				$ok = spiral_mcp_require_confirm( $input, 'enviar la nota al cliente por correo' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
			$note = trim( (string) ( $input['note'] ?? '' ) );
			if ( '' === $note ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'La nota está vacía.' );
			}
			$res = spiral_mcp_wc_rest( 'POST', 'orders/' . (int) ( $input['id'] ?? 0 ) . '/notes', array( 'note' => $note, 'customer_note' => $customer ) );
			return is_wp_error( $res ) ? $res : array( 'id' => (int) $res['id'], 'order_id' => (int) $input['id'], 'customer_note' => $customer, 'note' => wp_strip_all_tags( (string) $res['note'] ) );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-refund', array(
		'label'               => 'Reembolsar un pedido',
		'description'         => 'Registra un reembolso de un pedido: "amount" (texto, p. ej. "15.00"; si no se indica, la suma de "line_items"), "reason" y opcionalmente "line_items" ([{"id": <id de la línea en get-order>, "quantity": 1, "refund_total": "15.00"}]; esas unidades vuelven al stock salvo con "restock": false). Por defecto solo lo anota en la tienda y el dinero se devuelve a mano; con "api_refund": true pide a la pasarela de pago que devuelva el dinero de verdad (si lo admite). Exige "confirm": true. Irreversible: un reembolso no se puede deshacer ni borrar desde aquí. Enseña antes al usuario el importe y el motivo.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'log'                 => array( 'type' => 'order', 'id' => 'order_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'order_id', 'confirm' ),
			'properties' => array(
				'order_id'   => array( 'type' => 'integer' ),
				'amount'     => array( 'type' => 'string' ),
				'reason'     => array( 'type' => 'string' ),
				'line_items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'api_refund' => array( 'type' => 'boolean' ),
				'restock'    => array( 'type' => 'boolean' ),
				'confirm'    => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'registrar el reembolso (irreversible)' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$id = (int) ( $input['order_id'] ?? 0 );
			if ( empty( $input['amount'] ) && empty( $input['line_items'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "amount" o "line_items".' );
			}
			$body = array_intersect_key( (array) $input, array_flip( array( 'amount', 'reason', 'line_items' ) ) );
			// Siempre explícitos: sin ellos, una de las rutas de WooCommerce devuelve el dinero por la pasarela.
			$body['api_refund']  = ! empty( $input['api_refund'] );
			$body['api_restock'] = ! isset( $input['restock'] ) || ! empty( $input['restock'] );
			$res = spiral_mcp_wc_rest( 'POST', 'orders/' . $id . '/refunds', $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$order = spiral_mcp_wc_rest( 'GET', 'orders/' . $id );
			return array(
				'refund_id'    => (int) $res['id'],
				'amount'       => (string) $res['amount'],
				'reason'       => (string) $res['reason'],
				'refunded_payment' => (bool) ( $res['refunded_payment'] ?? false ),
				'order_status' => is_wp_error( $order ) ? null : (string) $order['status'],
			);
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	$customer_row = function ( array $c ) {
		return array(
			'id'                 => (int) $c['id'],
			'email'              => (string) $c['email'],
			'first_name'         => (string) $c['first_name'],
			'last_name'          => (string) $c['last_name'],
			'username'           => (string) $c['username'],
			'date_created'       => (string) $c['date_created'],
			'is_paying_customer' => (bool) ( $c['is_paying_customer'] ?? false ),
			'city'               => (string) ( $c['billing']['city'] ?? '' ),
			'country'            => (string) ( $c['billing']['country'] ?? '' ),
		);
	};

	spiral_mcp_register_ability( 'spiral-mcp/list-customers', array(
		'label'               => 'Listar clientes',
		'description'         => 'Clientes registrados de la tienda (rol customer) con correo, nombre, fecha de alta, si ha pagado algún pedido, ciudad y país. "search" busca por nombre o correo. Solo lectura. Los pedidos de un cliente: spiral-mcp/list-orders con "customer". Contiene datos personales.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string' ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) use ( $customer_row ) {
			$params = array_intersect_key( (array) $input, array_flip( array( 'search', 'page', 'per_page' ) ) ) + array( 'per_page' => 20, 'role' => 'customer' );
			$data   = spiral_mcp_wc_rest( 'GET', 'customers', $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return array( 'total' => spiral_mcp_wc_total(), 'items' => array_map( $customer_row, (array) $data ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-customer', array(
		'label'               => 'Leer un cliente',
		'description'         => 'Un cliente registrado: datos de la cuenta, direcciones de facturación y envío, y sus últimos pedidos (resumen). Solo lectura. Contiene datos personales.',
		'category'            => 'spiral-mcp-orders',
		'group'               => 'orders',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$id = (int) ( $input['id'] ?? 0 );
			$c  = spiral_mcp_wc_rest( 'GET', 'customers/' . $id );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			if ( ! in_array( 'customer', (array) ( get_userdata( $id )->roles ?? array() ), true ) ) {
				return new WP_Error( 'spiral_mcp_wc_not_customer', 'Ese usuario no es un cliente de la tienda.' );
			}
			$orders = spiral_mcp_wc_rest( 'GET', 'orders', array( 'customer' => $id, 'per_page' => 10 ) );
			$out    = spiral_mcp_wc_clean( $c );
			unset( $out['avatar_url'] );
			$out['orders'] = is_wp_error( $orders ) ? array() : array_map( 'spiral_mcp_wc_order_row', (array) $orders );
			return $out;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
