<?php
/**
 * WooCommerce: utilidades comunes, catálogo (productos, variaciones, atributos) y cupones.
 * Solo se registra si WooCommerce está activo. Los ajustes, envíos, impuestos y pasarelas
 * están en woocommerce-store.php; los pedidos y clientes, en woocommerce-orders.php.
 *
 * Todo pasa por la API REST de WooCommerce (wc/v3) llamada por dentro con rest_do_request():
 * así valida como en wp-admin y dispara sus hooks. Sus comprobaciones de permisos (todas pasan
 * por el filtro woocommerce_rest_check_permissions) se conceden solo durante esa llamada: el rol
 * del agente no recibe manage_woocommerce ni las capacidades de productos o pedidos, y lo que
 * puede hacer lo deciden las abilities (spiral_mcp_shop / spiral_mcp_orders y sus listas blancas).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers comunes
 * ---------------------------------------------------------------------- */

/** ¿WooCommerce está activo? */
function spiral_mcp_wc_active() {
	return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
}

/** Total de elementos (cabecera X-WP-Total) de la última llamada a spiral_mcp_wc_rest(). */
function spiral_mcp_wc_total( $set = null ) {
	static $total = null;
	if ( null !== $set ) {
		$total = (int) $set;
	}
	return $total;
}

/**
 * Llama a la API REST de WooCommerce por dentro (ruta relativa a /wc/v3/) concediendo sus
 * permisos solo durante la llamada. Devuelve los datos o WP_Error con el mensaje de WooCommerce.
 */
function spiral_mcp_wc_rest( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/wc/v3/' . ltrim( (string) $route, '/' ) );
	if ( in_array( $method, array( 'GET', 'DELETE', 'OPTIONS' ), true ) ) {
		$request->set_query_params( (array) $params );
	} else {
		// JSON: algunas rutas (p. ej. las ubicaciones de una zona de envío) esperan una lista en el cuerpo.
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	$grant = function () {
		return true;
	};
	add_filter( 'woocommerce_rest_check_permissions', $grant, 99 );
	try {
		$response = rest_do_request( $request );
	} finally {
		remove_filter( 'woocommerce_rest_check_permissions', $grant, 99 );
	}
	$headers = $response->get_headers();
	spiral_mcp_wc_total( $headers['X-WP-Total'] ?? 0 );
	$data = rest_get_server()->response_to_data( $response, false );
	if ( $response->is_error() || $response->get_status() >= 400 ) {
		$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Error de WooCommerce.';
		if ( ! empty( $data['data']['params'] ) && is_array( $data['data']['params'] ) ) {
			$message .= ' ' . implode( ' ', array_map( 'strval', $data['data']['params'] ) );
		}
		return new WP_Error( 'spiral_mcp_wc_error', 'WooCommerce: ' . $message, array( 'status' => $response->get_status(), 'wc_code' => $data['code'] ?? '' ) );
	}
	return $data;
}

/** ¿Es un WP_Error de "no existe" (404) de WooCommerce? */
function spiral_mcp_wc_not_found( $error ) {
	return is_wp_error( $error ) && 404 === (int) ( $error->get_error_data()['status'] ?? 0 );
}

/** Todas las páginas de una colección (máx. 1000 elementos). */
function spiral_mcp_wc_all( $route, array $params = array() ) {
	$out = array();
	for ( $page = 1; $page <= 10; $page++ ) {
		$data = spiral_mcp_wc_rest( 'GET', $route, $params + array( 'per_page' => 100, 'page' => $page ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array_merge( $out, (array) $data );
		if ( count( (array) $data ) < 100 ) {
			break;
		}
	}
	return $out;
}

/** URL del sitio → ruta relativa (/tienda/producto/). */
function spiral_mcp_wc_path( $url ) {
	return '' === (string) $url ? '' : wp_make_link_relative( (string) $url );
}

/** Quita de una respuesta de WooCommerce lo que no sirve al agente y añade path_relative a las imágenes. */
function spiral_mcp_wc_clean( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$out = array();
	foreach ( $data as $key => $value ) {
		if ( in_array( $key, array( '_links', '_embedded', 'yoast_head', 'yoast_head_json', 'price_html', 'related_ids' ), true ) || ( is_string( $key ) && '_gmt' === substr( $key, -4 ) ) ) {
			continue;
		}
		if ( 'meta_data' === $key && is_array( $value ) ) {
			// Los metadatos protegidos (_clave) son internos de WooCommerce o de otros plugins.
			$value = array_values( array_filter( $value, function ( $m ) {
				return is_array( $m ) && '_' !== substr( (string) ( $m['key'] ?? '_' ), 0, 1 );
			} ) );
		}
		$value = spiral_mcp_wc_clean( $value );
		if ( 'permalink' === $key && is_string( $value ) ) {
			$value = spiral_mcp_wc_path( $value );
		}
		$out[ $key ] = $value;
		if ( 'src' === $key && is_string( $value ) && '' !== $value ) {
			$out['path_relative'] = spiral_mcp_media_relative( $value );
		}
	}
	return $out;
}

/** Campos escribibles de los objetos de una colección (products, products/12/variations…), según su esquema. */
function spiral_mcp_wc_writable( $collection ) {
	static $cache = array();
	$key = preg_replace( '#/\d+#', '/N', (string) $collection );
	if ( ! isset( $cache[ $key ] ) ) {
		$data          = spiral_mcp_wc_rest( 'OPTIONS', $collection );
		$props         = is_array( $data ) ? (array) ( $data['schema']['properties'] ?? array() ) : array();
		$cache[ $key ] = array_keys( array_filter( $props, function ( $p ) {
			return empty( $p['readonly'] );
		} ) );
	}
	return $cache[ $key ];
}

/**
 * Comprueba los campos que el agente manda para un producto, variación o cupón: que existan en el
 * esquema, imágenes solo por ID de la biblioteca y sin metadatos protegidos.
 */
function spiral_mcp_wc_check_fields( array $fields, $collection ) {
	$writable = spiral_mcp_wc_writable( $collection );
	$unknown  = array_diff( array_keys( $fields ), $writable, array( 'id' ) );
	if ( $writable && $unknown ) {
		return new WP_Error( 'spiral_mcp_wc_bad_field', sprintf( 'Campos desconocidos o de solo lectura: %s. Escribibles: %s.', implode( ', ', $unknown ), implode( ', ', $writable ) ) );
	}
	$image = function ( $img, $where ) {
		if ( ! is_array( $img ) ) {
			return new WP_Error( 'spiral_mcp_wc_bad_image', sprintf( '"%s" debe ser {"id": <ID de imagen>}.', $where ) );
		}
		if ( empty( $img['id'] ) ) {
			return new WP_Error( 'spiral_mcp_wc_bad_image', sprintf( '"%s": las imágenes van por ID de la biblioteca ({"id": 12}). Súbela antes con spiral-mcp/upload-media; no se descargan URLs.', $where ) );
		}
		if ( ! wp_attachment_is_image( (int) $img['id'] ) ) {
			return new WP_Error( 'spiral_mcp_not_image', sprintf( '"%s": %d no es una imagen de la biblioteca.', $where, (int) $img['id'] ) );
		}
		return array_intersect_key( $img, array_flip( array( 'id', 'alt', 'name', 'position' ) ) );
	};
	if ( isset( $fields['images'] ) ) {
		$images = array();
		foreach ( (array) $fields['images'] as $i => $img ) {
			$img = $image( $img, "images[$i]" );
			if ( is_wp_error( $img ) ) {
				return $img;
			}
			$images[] = $img;
		}
		$fields['images'] = $images;
	}
	if ( isset( $fields['image'] ) && null !== $fields['image'] && array() !== $fields['image'] ) {
		$img = $image( $fields['image'], 'image' );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		$fields['image'] = $img;
	}
	if ( isset( $fields['meta_data'] ) ) {
		foreach ( (array) $fields['meta_data'] as $meta ) {
			if ( '_' === substr( (string) ( $meta['key'] ?? '_' ), 0, 1 ) ) {
				return new WP_Error( 'spiral_mcp_wc_bad_meta', 'meta_data: las claves que empiezan por "_" son internas y no se pueden escribir.' );
			}
		}
	}
	return $fields;
}

/** Datos guardados en un snapshot → cuerpo para volver a escribirlos (solo campos escribibles, imágenes por ID). */
function spiral_mcp_wc_restore_body( array $data, $route, $fields = null ) {
	$keys = $fields ? $fields : spiral_mcp_wc_writable( spiral_mcp_wc_collection( $route ) );
	$body = array_intersect_key( $data, array_flip( $keys ) );
	if ( isset( $body['images'] ) ) {
		$body['images'] = array_values( array_map( function ( $img ) {
			return array_intersect_key( (array) $img, array_flip( array( 'id', 'alt', 'name' ) ) );
		}, (array) $body['images'] ) );
	}
	if ( isset( $body['image'] ) ) {
		$body['image'] = empty( $body['image']['id'] ) ? null : array( 'id' => $body['image']['id'] );
	}
	if ( isset( $body['meta_data'] ) ) {
		$body['meta_data'] = array_values( array_filter( (array) $body['meta_data'], function ( $m ) {
			return '_' !== substr( (string) ( $m['key'] ?? '_' ), 0, 1 );
		} ) );
	}
	unset( $body['id'] );
	return $body;
}

/** Colección de una ruta de objeto: products/12 → products. */
function spiral_mcp_wc_collection( $ref ) {
	return preg_replace( '#/\d+$#', '', (string) $ref );
}

/** Captura genérica de un objeto de WooCommerce (null si no existe). */
function spiral_mcp_wc_capture( $ref, $fields = null ) {
	$data = spiral_mcp_wc_rest( 'GET', $ref, array( 'context' => 'edit' ) );
	if ( spiral_mcp_wc_not_found( $data ) ) {
		return null;
	}
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return $fields ? array_intersect_key( $data, array_flip( array_merge( $fields, array( 'id' ) ) ) ) : $data;
}

/**
 * Restauración genérica: si el snapshot es "no existía", borra el objeto; si el objeto ya no
 * existe, lo vuelve a crear (con otro ID); si existe, reescribe sus campos.
 */
function spiral_mcp_wc_restore( $ref, $data, $fields = null ) {
	$current = spiral_mcp_wc_rest( 'GET', $ref, array( 'context' => 'edit' ) );
	if ( null === $data ) {
		if ( spiral_mcp_wc_not_found( $current ) ) {
			return true;
		}
		$res = spiral_mcp_wc_rest( 'DELETE', $ref, array( 'force' => true ) );
		return is_wp_error( $res ) ? $res : array( 'deleted' => $ref );
	}
	$body = spiral_mcp_wc_restore_body( (array) $data, $ref, $fields );
	if ( spiral_mcp_wc_not_found( $current ) ) {
		$res = spiral_mcp_wc_rest( 'POST', spiral_mcp_wc_collection( $ref ), $body );
		return is_wp_error( $res ) ? $res : array( 'recreated_id' => (int) $res['id'], 'note' => 'El objeto ya no existía: se ha creado de nuevo con otro ID.' );
	}
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	$res = spiral_mcp_wc_rest( 'PUT', $ref, $body );
	return is_wp_error( $res ) ? $res : true;
}

/** Purga la caché tras un cambio de la tienda: un producto (y la página de la tienda) o todo. */
function spiral_mcp_wc_purge( $post_id = 0 ) {
	if ( ! spiral_mcp_setting( 'auto_purge' ) || ! function_exists( 'spiral_mcp_cache_purge' ) ) {
		return;
	}
	if ( $post_id ) {
		spiral_mcp_cache_purge( 'post', (int) $post_id );
		$shop = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $shop > 0 ) {
			spiral_mcp_cache_purge( 'post', $shop );
		}
	} else {
		spiral_mcp_cache_purge( 'all' );
	}
}

/** Resumen de un producto para los listados. */
function spiral_mcp_wc_product_row( array $p ) {
	return array(
		'id'             => (int) $p['id'],
		'name'           => (string) $p['name'],
		'type'           => (string) $p['type'],
		'status'         => (string) $p['status'],
		'sku'            => (string) $p['sku'],
		'price'          => (string) $p['price'],
		'regular_price'  => (string) $p['regular_price'],
		'sale_price'     => (string) $p['sale_price'],
		'stock_status'   => (string) $p['stock_status'],
		'stock_quantity' => $p['stock_quantity'],
		'categories'     => array_column( (array) $p['categories'], 'name' ),
		'image'          => empty( $p['images'][0]['src'] ) ? null : spiral_mcp_media_relative( $p['images'][0]['src'] ),
		'variations'     => count( (array) ( $p['variations'] ?? array() ) ),
		'path'           => spiral_mcp_wc_path( $p['permalink'] ?? '' ),
	);
}

/** Resumen de una variación. */
function spiral_mcp_wc_variation_row( array $v ) {
	$attrs = array();
	foreach ( (array) $v['attributes'] as $a ) {
		$attrs[ (string) $a['name'] ] = (string) $a['option'];
	}
	return array(
		'id'             => (int) $v['id'],
		'attributes'     => $attrs,
		'status'         => (string) $v['status'],
		'sku'            => (string) $v['sku'],
		'regular_price'  => (string) $v['regular_price'],
		'sale_price'     => (string) $v['sale_price'],
		'stock_status'   => (string) $v['stock_status'],
		'manage_stock'   => $v['manage_stock'],
		'stock_quantity' => $v['stock_quantity'],
		'image'          => empty( $v['image']['id'] ) ? null : array( 'id' => (int) $v['image']['id'], 'path_relative' => spiral_mcp_media_relative( (string) $v['image']['src'] ) ),
	);
}

/** Producto completo (limpio) con el resumen de sus variaciones. */
function spiral_mcp_wc_product_out( $id ) {
	$p = spiral_mcp_wc_rest( 'GET', 'products/' . (int) $id, array( 'context' => 'edit' ) );
	if ( is_wp_error( $p ) ) {
		return spiral_mcp_wc_not_found( $p ) ? new WP_Error( 'spiral_mcp_wc_not_found', 'No existe un producto con ese ID. Búscalo con spiral-mcp/list-products.' ) : $p;
	}
	$out = spiral_mcp_wc_clean( $p );
	if ( 'variable' === $p['type'] ) {
		$vars = spiral_mcp_wc_all( 'products/' . (int) $id . '/variations', array( 'context' => 'edit' ) );
		$out['variations'] = is_wp_error( $vars ) ? array() : array_map( 'spiral_mcp_wc_variation_row', $vars );
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	if ( ! spiral_mcp_wc_active() ) {
		return $kinds;
	}
	// Producto con sus variaciones (ref = products/<id>).
	$kinds['wc_product'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_others_posts',
		'capture' => function ( $ref ) {
			$data = spiral_mcp_wc_capture( $ref );
			if ( is_array( $data ) && 'variable' === ( $data['type'] ?? '' ) ) {
				$vars = spiral_mcp_wc_all( $ref . '/variations', array( 'context' => 'edit' ) );
				$data['_variations'] = is_wp_error( $vars ) ? array() : $vars;
			}
			return $data;
		},
		'restore' => function ( $ref, $data ) {
			$variations = is_array( $data ) ? ( $data['_variations'] ?? null ) : null;
			$res        = spiral_mcp_wc_restore( $ref, $data );
			if ( is_wp_error( $res ) || null === $variations ) {
				return $res;
			}
			$id    = is_array( $res ) && isset( $res['recreated_id'] ) ? (int) $res['recreated_id'] : (int) $data['id'];
			$now   = spiral_mcp_wc_all( 'products/' . $id . '/variations', array( 'context' => 'edit' ) );
			$now   = is_wp_error( $now ) ? array() : array_column( $now, null, 'id' );
			$batch = array( 'create' => array(), 'update' => array(), 'delete' => array() );
			$route = 'products/' . $id . '/variations';
			foreach ( $variations as $v ) {
				$body = spiral_mcp_wc_restore_body( $v, $route );
				if ( isset( $now[ $v['id'] ] ) ) {
					$batch['update'][] = array( 'id' => (int) $v['id'] ) + $body;
					unset( $now[ $v['id'] ] );
				} else {
					$batch['create'][] = $body;
				}
			}
			$batch['delete'] = array_map( 'intval', array_keys( $now ) );
			$out             = spiral_mcp_wc_rest( 'POST', $route . '/batch', array_filter( $batch ) );
			return is_wp_error( $out ) ? $out : ( is_array( $res ) ? $res : true );
		},
	);
	$kinds['wc_coupon'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_others_posts',
		'capture' => 'spiral_mcp_wc_capture',
		'restore' => function ( $ref, $data ) {
			return spiral_mcp_wc_restore( $ref, $data );
		},
	);
	$kinds['wc_attribute'] = array(
		'group'   => 'shop',
		'cap'     => 'edit_others_posts',
		'capture' => 'spiral_mcp_wc_capture',
		'restore' => function ( $ref, $data ) {
			return spiral_mcp_wc_restore( $ref, $data );
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categorías
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! spiral_mcp_wc_active() ) {
		return;
	}
	wp_register_ability_category( 'spiral-mcp-shop', array(
		'label'       => 'Tienda Spiral-MCP',
		'description' => 'WooCommerce: productos, variaciones, atributos, cupones, ajustes de la tienda, envíos, impuestos y pasarelas de pago (lectura).',
	) );
	wp_register_ability_category( 'spiral-mcp-orders', array(
		'label'       => 'Pedidos Spiral-MCP',
		'description' => 'WooCommerce: pedidos (estado, notas, reembolsos) y clientes.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities: productos, variaciones y atributos
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_wc_active() ) {
		return;
	}

	// El rol del agente no tiene las capacidades de productos de WooCommerce: se exige la de editar contenido de otros.
	$can = function () {
		return current_user_can( 'edit_others_posts' );
	};

	$fields_desc = 'Campos de la API REST v3 de WooCommerce: name, type (simple, variable, grouped, external), status (draft, pending, private, publish), featured, catalog_visibility, description, short_description, sku, regular_price, sale_price (texto, p. ej. "19.90"), date_on_sale_from/to, tax_status, tax_class, manage_stock, stock_quantity, stock_status, backorders, sold_individually, weight, dimensions {length, width, height}, shipping_class, reviews_allowed, upsell_ids, cross_sell_ids, purchase_note, categories [{"id": 12}], tags [{"id": 3}], images [{"id": <ID de la biblioteca>}] (la primera es la principal; súbelas antes con spiral-mcp/upload-media), attributes [{"id": <atributo global> o "name": "<atributo propio>", "options": ["Rojo","Azul"], "visible": true, "variation": true}], default_attributes, menu_order, virtual, downloadable, external_url, button_text, meta_data [{"key","value"}].';

	spiral_mcp_register_ability( 'spiral-mcp/list-products', array(
		'label'               => 'Listar productos',
		'description'         => 'Productos de WooCommerce con un resumen (tipo, estado, SKU, precios, stock, categorías, imagen principal, número de variaciones y ruta). Filtros: search, status (any por defecto), type, category y tag (IDs; búscalos con spiral-mcp/list-terms y taxonomy product_cat / product_tag), sku, stock_status, on_sale, featured. Para el detalle, spiral-mcp/get-product.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'       => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'pending', 'private', 'publish', 'trash' ) ),
				'type'         => array( 'type' => 'string', 'enum' => array( 'simple', 'variable', 'grouped', 'external' ) ),
				'category'     => array( 'type' => 'integer' ),
				'tag'          => array( 'type' => 'integer' ),
				'sku'          => array( 'type' => 'string' ),
				'stock_status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'on_sale'      => array( 'type' => 'boolean' ),
				'featured'     => array( 'type' => 'boolean' ),
				'orderby'      => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'price', 'popularity', 'rating', 'menu_order', 'id' ) ),
				'order'        => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'page'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$in     = (array) $input;
			$params = array_intersect_key( $in, array_flip( array( 'search', 'status', 'type', 'category', 'tag', 'sku', 'stock_status', 'on_sale', 'featured', 'orderby', 'order', 'page', 'per_page' ) ) );
			$params += array( 'status' => 'any', 'per_page' => 20 );
			$data = spiral_mcp_wc_rest( 'GET', 'products', $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return array( 'total' => spiral_mcp_wc_total(), 'items' => array_map( 'spiral_mcp_wc_product_row', (array) $data ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-product', array(
		'label'               => 'Leer un producto',
		'description'         => 'Un producto completo con los campos de la API de WooCommerce (precios, stock, envío, categorías, imágenes con path_relative, atributos, metadatos no internos…) y, si es variable, el resumen de sus variaciones (id, atributos, SKU, precios, stock, imagen). Léelo antes de cambiarlo con spiral-mcp/update-product o spiral-mcp/update-variations.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			return spiral_mcp_wc_product_out( (int) ( $input['id'] ?? 0 ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-product', array(
		'label'               => 'Crear un producto',
		'description'         => 'Crea un producto de WooCommerce, en borrador salvo que indiques "status". ' . $fields_desc . ' Un producto variable se crea con sus atributos (variation: true) y después se le añaden las variaciones con spiral-mcp/update-variations. Devuelve el producto y "snapshot_id" (restaurarlo borra el producto).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product', 'id' => function ( $in, $result ) {
			return is_array( $result ) ? ( $result['id'] ?? '' ) : '';
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'fields' ),
			'properties' => array(
				'fields' => array( 'type' => 'object', 'description' => 'Campos del producto (ver la descripción). "name" es obligatorio.', 'additionalProperties' => true ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$fields = spiral_mcp_wc_check_fields( (array) ( $input['fields'] ?? array() ), 'products' );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			if ( empty( $fields['name'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "name" en "fields".' );
			}
			$fields += array( 'status' => 'draft' );
			$created = spiral_mcp_wc_rest( 'POST', 'products', $fields );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$snapshot = spiral_mcp_snapshot_take( 'wc_product', 'products/' . (int) $created['id'], null, 'create-product' );
			spiral_mcp_wc_purge( (int) $created['id'] );
			return spiral_mcp_wc_product_out( (int) $created['id'] ) + array( 'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-product', array(
		'label'               => 'Cambiar un producto',
		'description'         => 'Cambia los campos enviados en "fields" de un producto (los demás no se tocan). Primero léelo con spiral-mcp/get-product. ' . $fields_desc . ' Las listas (images, categories, tags, attributes) se reemplazan enteras: envía la lista completa. Las variaciones se cambian con spiral-mcp/update-variations. Antes guarda un snapshot del producto y sus variaciones ("snapshot_id"; restore-snapshot lo deshace).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'fields' ),
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'fields' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$id     = (int) ( $input['id'] ?? 0 );
			$fields = spiral_mcp_wc_check_fields( (array) ( $input['fields'] ?? array() ), 'products' );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			if ( ! $fields ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "fields" al menos un campo que cambiar.' );
			}
			$exists = spiral_mcp_wc_product_out( $id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_product', 'products/' . $id, 'update-product' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'PUT', 'products/' . $id, $fields );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_wc_purge( $id );
			return spiral_mcp_wc_product_out( $id ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-product', array(
		'label'               => 'Borrar un producto',
		'description'         => 'Mueve un producto (con sus variaciones) a la papelera. Con "force": true lo borra definitivamente. Exige "confirm": true. Antes guarda un snapshot: restore-snapshot lo saca de la papelera o, si se borró del todo, lo vuelve a crear con otro ID (los pedidos antiguos no se reenlazan).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'force'   => array( 'type' => 'boolean' ),
				'confirm' => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar el producto' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$id     = (int) ( $input['id'] ?? 0 );
			$exists = spiral_mcp_wc_product_out( $id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_product', 'products/' . $id, 'delete-product' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$force = ! empty( $input['force'] );
			$res   = spiral_mcp_wc_rest( 'DELETE', 'products/' . $id, array( 'force' => $force ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_wc_purge( $id );
			return array( 'deleted' => true, 'id' => $id, 'trashed' => ! $force, 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-variations', array(
		'label'               => 'Crear, cambiar o borrar variaciones',
		'description'         => 'Variaciones de un producto variable, en un solo paso: "create" (lista de variaciones nuevas), "update" (lista con "id" y los campos que cambian) y "delete" (IDs; exige "confirm": true). Campos: attributes [{"id": <atributo global> o "name": "<atributo propio>", "option": "Rojo"}] (una opción por atributo de variación del producto), regular_price, sale_price, sku, manage_stock, stock_quantity, stock_status, status, image {"id": <ID de la biblioteca>}, weight, dimensions, description, shipping_class, virtual, downloadable. El producto debe tener los atributos con "variation": true (spiral-mcp/update-product). Antes guarda un snapshot del producto y sus variaciones. Devuelve las variaciones resultantes y los errores de cada una.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product', 'id' => 'product_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'product_id' ),
			'properties' => array(
				'product_id' => array( 'type' => 'integer' ),
				'create'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'update'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'delete'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'confirm'    => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$id      = (int) ( $input['product_id'] ?? 0 );
			$product = spiral_mcp_wc_rest( 'GET', 'products/' . $id, array( 'context' => 'edit' ) );
			if ( is_wp_error( $product ) ) {
				return spiral_mcp_wc_not_found( $product ) ? new WP_Error( 'spiral_mcp_wc_not_found', 'No existe un producto con ese ID.' ) : $product;
			}
			if ( 'variable' !== $product['type'] ) {
				return new WP_Error( 'spiral_mcp_wc_not_variable', sprintf( 'El producto %d es de tipo "%s". Cámbialo antes a "type": "variable" con atributos de variación (spiral-mcp/update-product).', $id, $product['type'] ) );
			}
			$route = 'products/' . $id . '/variations';
			$batch = array();
			foreach ( array( 'create', 'update' ) as $op ) {
				foreach ( (array) ( $input[ $op ] ?? array() ) as $i => $v ) {
					$v = spiral_mcp_wc_check_fields( (array) $v, $route );
					if ( is_wp_error( $v ) ) {
						return new WP_Error( $v->get_error_code(), sprintf( '%s[%d]: %s', $op, $i, $v->get_error_message() ) );
					}
					if ( 'update' === $op && empty( $v['id'] ) ) {
						return new WP_Error( 'spiral_mcp_bad_input', sprintf( 'update[%d]: falta "id".', $i ) );
					}
					$batch[ $op ][] = $v;
				}
			}
			if ( ! empty( $input['delete'] ) ) {
				$ok = spiral_mcp_require_confirm( $input, 'borrar variaciones' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$batch['delete'] = array_map( 'intval', (array) $input['delete'] );
			}
			if ( ! $batch ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "create", "update" o "delete".' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_product', 'products/' . $id, 'update-variations' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'POST', $route . '/batch', $batch );
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
			spiral_mcp_wc_purge( $id );
			$now = spiral_mcp_wc_all( $route, array( 'context' => 'edit' ) );
			return array(
				'product_id'  => $id,
				'variations'  => is_wp_error( $now ) ? array() : array_map( 'spiral_mcp_wc_variation_row', $now ),
				'errors'      => $errors,
				'snapshot_id' => $snapshot,
			);
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/list-product-attributes', array(
		'label'               => 'Listar atributos de producto',
		'description'         => 'Atributos globales de WooCommerce (talla, color…) con su taxonomía (pa_<slug>) y sus términos. Los términos se gestionan también con spiral-mcp/create-term, update-term y delete-term usando esa taxonomía. Un producto los usa en "attributes" con su "id".',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$attrs = spiral_mcp_wc_rest( 'GET', 'products/attributes' );
			if ( is_wp_error( $attrs ) ) {
				return $attrs;
			}
			$out = array();
			foreach ( (array) $attrs as $a ) {
				$terms = spiral_mcp_wc_all( 'products/attributes/' . (int) $a['id'] . '/terms' );
				$out[] = array(
					'id'           => (int) $a['id'],
					'name'         => (string) $a['name'],
					'slug'         => (string) $a['slug'],
					'taxonomy'     => (string) $a['slug'],
					'type'         => (string) $a['type'],
					'order_by'     => (string) $a['order_by'],
					'has_archives' => (bool) $a['has_archives'],
					'terms'        => is_wp_error( $terms ) ? array() : array_map( function ( $t ) {
						return array( 'id' => (int) $t['id'], 'name' => (string) $t['name'], 'slug' => (string) $t['slug'], 'count' => (int) $t['count'] );
					}, $terms ),
				);
			}
			return array( 'attributes' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	$attr_props = array(
		'name'         => array( 'type' => 'string' ),
		'slug'         => array( 'type' => 'string', 'description' => 'Sin el prefijo pa_.' ),
		'order_by'     => array( 'type' => 'string', 'enum' => array( 'menu_order', 'name', 'name_num', 'id' ) ),
		'has_archives' => array( 'type' => 'boolean' ),
		'terms'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Nombres de términos que añadir (los que ya existen se ignoran).' ),
	);
	// Devuelve los avisos de los términos que no se pudieron crear.
	$add_terms  = function ( $attr_id, $names ) {
		$attr = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( (int) $attr_id ) : null;
		// Un atributo recién creado no tiene su taxonomía registrada hasta la siguiente petición.
		if ( $attr && ! taxonomy_exists( $attr->slug ) ) {
			register_taxonomy( $attr->slug, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false ) );
		}
		$existing = spiral_mcp_wc_all( 'products/attributes/' . (int) $attr_id . '/terms' );
		$have     = is_wp_error( $existing ) ? array() : array_map( 'strtolower', array_column( $existing, 'name' ) );
		$create   = array();
		foreach ( (array) $names as $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name && ! in_array( strtolower( $name ), $have, true ) ) {
				$create[] = array( 'name' => $name );
			}
		}
		$warnings = array();
		if ( $create ) {
			$res = spiral_mcp_wc_rest( 'POST', 'products/attributes/' . (int) $attr_id . '/terms/batch', array( 'create' => $create ) );
			if ( is_wp_error( $res ) ) {
				return array( 'No se pudieron crear los términos: ' . $res->get_error_message() );
			}
			foreach ( (array) ( $res['create'] ?? array() ) as $i => $item ) {
				if ( ! empty( $item['error'] ) ) {
					$warnings[] = sprintf( 'Término "%s": %s', $create[ $i ]['name'], $item['error']['message'] ?? 'error' );
				}
			}
		}
		return $warnings;
	};

	spiral_mcp_register_ability( 'spiral-mcp/create-product-attribute', array(
		'label'               => 'Crear un atributo de producto',
		'description'         => 'Crea un atributo global (p. ej. "Talla") y, opcionalmente, sus términos ("terms": ["S","M","L"]). Devuelve su "id" para usarlo en los productos y "snapshot_id" (restaurarlo borra el atributo).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product_attribute', 'id' => function ( $in, $result ) {
			return is_array( $result ) ? ( $result['id'] ?? '' ) : '';
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => $attr_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $add_terms ) {
			$body = array_intersect_key( (array) $input, array_flip( array( 'name', 'slug', 'order_by', 'has_archives' ) ) );
			$res  = spiral_mcp_wc_rest( 'POST', 'products/attributes', $body );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$warnings = empty( $input['terms'] ) ? array() : $add_terms( (int) $res['id'], $input['terms'] );
			$snapshot = spiral_mcp_snapshot_take( 'wc_attribute', 'products/attributes/' . (int) $res['id'], null, 'create-product-attribute' );
			$terms    = spiral_mcp_wc_all( 'products/attributes/' . (int) $res['id'] . '/terms' );
			return spiral_mcp_wc_clean( $res ) + array(
				'taxonomy'    => (string) $res['slug'],
				'terms'       => is_wp_error( $terms ) ? array() : array_column( $terms, 'name', 'id' ),
				'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot,
				'warnings'    => $warnings,
			);
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-product-attribute', array(
		'label'               => 'Cambiar un atributo de producto',
		'description'         => 'Cambia el nombre, slug, orden o archivos de un atributo global y añade términos ("terms"). Cambiar el slug cambia su taxonomía (pa_<slug>). Para renombrar o borrar términos usa spiral-mcp/update-term y delete-term con esa taxonomía. Antes guarda un snapshot del atributo (sin sus términos).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product_attribute', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ) + $attr_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) use ( $add_terms ) {
			$id   = (int) ( $input['id'] ?? 0 );
			$ref  = 'products/attributes/' . $id;
			$body = array_intersect_key( (array) $input, array_flip( array( 'name', 'slug', 'order_by', 'has_archives' ) ) );
			if ( ! $body && empty( $input['terms'] ) ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía algún campo que cambiar o "terms".' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_attribute', $ref, 'update-product-attribute' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = $body ? spiral_mcp_wc_rest( 'PUT', $ref, $body ) : spiral_mcp_wc_rest( 'GET', $ref );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$warnings = empty( $input['terms'] ) ? array() : $add_terms( $id, $input['terms'] );
			spiral_mcp_wc_purge();
			return spiral_mcp_wc_clean( $res ) + array( 'taxonomy' => (string) $res['slug'], 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-product-attribute', array(
		'label'               => 'Borrar un atributo de producto',
		'description'         => 'Borra un atributo global y todos sus términos; los productos que lo usan lo pierden (y sus variaciones dejan de distinguirse por él). Exige "confirm": true. Irreversible en la práctica: el snapshot solo vuelve a crear el atributo, sin términos ni su uso en los productos. Antes mira en qué productos se usa con spiral-mcp/list-products.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'product_attribute', 'id' => 'id' ),
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
			$ok = spiral_mcp_require_confirm( $input, 'borrar el atributo y sus términos' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$ref      = 'products/attributes/' . (int) ( $input['id'] ?? 0 );
			$snapshot = spiral_mcp_snapshot_capture( 'wc_attribute', $ref, 'delete-product-attribute' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'DELETE', $ref, array( 'force' => true ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_wc_purge();
			return array( 'deleted' => true, 'id' => (int) $input['id'], 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* ---------------------------------------------------------------------
	 * Cupones
	 * ------------------------------------------------------------------ */

	$coupon_desc = 'Campos: code, discount_type (percent, fixed_cart, fixed_product), amount (texto, p. ej. "10"), description, date_expires (fecha local "2026-12-31T23:59:59" o null), individual_use, product_ids, excluded_product_ids, product_categories, excluded_product_categories (IDs), usage_limit, usage_limit_per_user, limit_usage_to_x_items, free_shipping, exclude_sale_items, minimum_amount, maximum_amount, email_restrictions (lista de correos o patrones *@dominio.com).';

	$coupon_row = function ( array $c ) {
		return array(
			'id'            => (int) $c['id'],
			'code'          => (string) $c['code'],
			'discount_type' => (string) $c['discount_type'],
			'amount'        => (string) $c['amount'],
			'date_expires'  => $c['date_expires'],
			'usage_count'   => (int) $c['usage_count'],
			'usage_limit'   => $c['usage_limit'],
			'free_shipping' => (bool) $c['free_shipping'],
			'status'        => (string) ( $c['status'] ?? '' ),
		);
	};

	spiral_mcp_register_ability( 'spiral-mcp/list-coupons', array(
		'label'               => 'Listar cupones',
		'description'         => 'Cupones de descuento con su código, tipo, importe, caducidad y usos. "search" busca por código. Para el detalle, spiral-mcp/get-coupon.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string' ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) use ( $coupon_row ) {
			$params = array_intersect_key( (array) $input, array_flip( array( 'search', 'page', 'per_page' ) ) ) + array( 'per_page' => 20 );
			$data   = spiral_mcp_wc_rest( 'GET', 'coupons', $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return array( 'total' => spiral_mcp_wc_total(), 'items' => array_map( $coupon_row, (array) $data ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-coupon', array(
		'label'               => 'Leer un cupón',
		'description'         => 'Un cupón completo: restricciones de productos y categorías, límites de uso, importes mínimo y máximo, correos permitidos y quién lo ha usado.',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$c = spiral_mcp_wc_rest( 'GET', 'coupons/' . (int) ( $input['id'] ?? 0 ), array( 'context' => 'edit' ) );
			return is_wp_error( $c ) ? $c : spiral_mcp_wc_clean( $c );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-coupon', array(
		'label'               => 'Crear un cupón',
		'description'         => 'Crea un cupón activo (se puede usar en cuanto existe). ' . $coupon_desc . ' "code" es obligatorio y único. Devuelve el cupón y "snapshot_id" (restaurarlo borra el cupón).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'coupon', 'id' => function ( $in, $result ) {
			return is_array( $result ) ? ( $result['id'] ?? '' ) : '';
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'fields' ),
			'properties' => array( 'fields' => array( 'type' => 'object', 'additionalProperties' => true ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$fields = spiral_mcp_wc_check_fields( (array) ( $input['fields'] ?? array() ), 'coupons' );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			if ( empty( $fields['code'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'Indica "code" en "fields".' );
			}
			$res = spiral_mcp_wc_rest( 'POST', 'coupons', $fields );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$snapshot = spiral_mcp_snapshot_take( 'wc_coupon', 'coupons/' . (int) $res['id'], null, 'create-coupon' );
			return spiral_mcp_wc_clean( $res ) + array( 'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-coupon', array(
		'label'               => 'Cambiar un cupón',
		'description'         => 'Cambia los campos enviados en "fields" de un cupón. Primero léelo con spiral-mcp/get-coupon. ' . $coupon_desc . ' Las listas se reemplazan enteras. Antes guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'coupon', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'fields' ),
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'fields' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ref    = 'coupons/' . (int) ( $input['id'] ?? 0 );
			$fields = spiral_mcp_wc_check_fields( (array) ( $input['fields'] ?? array() ), 'coupons' );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			if ( ! $fields ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía en "fields" al menos un campo que cambiar.' );
			}
			$current = spiral_mcp_wc_rest( 'GET', $ref );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_coupon', $ref, 'update-coupon' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_wc_rest( 'PUT', $ref, $fields );
			return is_wp_error( $res ) ? $res : spiral_mcp_wc_clean( $res ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-coupon', array(
		'label'               => 'Borrar un cupón',
		'description'         => 'Mueve un cupón a la papelera (deja de funcionar); con "force": true lo borra definitivamente. Exige "confirm": true. Antes guarda un snapshot: restore-snapshot lo recupera (si se borró del todo, con otro ID y el mismo código).',
		'category'            => 'spiral-mcp-shop',
		'group'               => 'shop',
		'log'                 => array( 'type' => 'coupon', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'force'   => array( 'type' => 'boolean' ),
				'confirm' => array( 'type' => 'boolean' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar el cupón' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$ref     = 'coupons/' . (int) ( $input['id'] ?? 0 );
			$current = spiral_mcp_wc_rest( 'GET', $ref );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'wc_coupon', $ref, 'delete-coupon' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$force = ! empty( $input['force'] );
			$res   = spiral_mcp_wc_rest( 'DELETE', $ref, array( 'force' => $force ) );
			return is_wp_error( $res ) ? $res : array( 'deleted' => true, 'id' => (int) $input['id'], 'code' => (string) $current['code'], 'trashed' => ! $force, 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );
} );
