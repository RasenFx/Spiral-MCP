<?php
/**
 * Abilities de taxonomías y términos: listar, crear, editar y borrar
 * categorías, etiquetas y taxonomías personalizadas.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Taxonomía editable (con interfaz en wp-admin) o WP_Error. */
function spiral_mcp_taxonomy_get( $taxonomy ) {
	$tax = get_taxonomy( (string) $taxonomy );
	if ( ! $tax || ! $tax->show_ui ) {
		$names = array_keys( get_taxonomies( array( 'show_ui' => true ) ) );
		return new WP_Error( 'spiral_mcp_bad_taxonomy', sprintf( 'La taxonomía "%s" no existe o no es editable. Disponibles: %s.', $taxonomy, implode( ', ', $names ) ) );
	}
	return $tax;
}

/** Término por ID (de cualquier taxonomía editable) o WP_Error. */
function spiral_mcp_term_get( $term_id ) {
	$term = get_term( (int) $term_id );
	if ( ! $term || is_wp_error( $term ) ) {
		return new WP_Error( 'spiral_mcp_term_not_found', 'No existe un término con ese ID (ver spiral-mcp/list-terms).' );
	}
	$tax = spiral_mcp_taxonomy_get( $term->taxonomy );
	return is_wp_error( $tax ) ? $tax : $term;
}

/** Formato de salida de un término. */
function spiral_mcp_term_out( $term ) {
	$link = get_term_link( $term );
	return array(
		'id'          => (int) $term->term_id,
		'taxonomy'    => $term->taxonomy,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => $term->description,
		'parent'      => (int) $term->parent,
		'count'       => (int) $term->count,
		'url'         => is_wp_error( $link ) ? null : wp_make_link_relative( $link ),
	);
}

/** Valida el padre de un término (taxonomía jerárquica, existente, sin ciclos). */
function spiral_mcp_term_check_parent( $tax, $parent, $term_id = 0 ) {
	$parent = (int) $parent;
	if ( ! $parent ) {
		return true;
	}
	if ( ! $tax->hierarchical ) {
		return new WP_Error( 'spiral_mcp_bad_parent', sprintf( 'La taxonomía "%s" no es jerárquica: no admite "parent".', $tax->name ) );
	}
	$p = get_term( $parent, $tax->name );
	if ( ! $p || is_wp_error( $p ) ) {
		return new WP_Error( 'spiral_mcp_bad_parent', 'El término padre no existe en esa taxonomía.' );
	}
	if ( $term_id && ( $parent === (int) $term_id || in_array( (int) $term_id, array_map( 'intval', get_ancestors( $parent, $tax->name, 'taxonomy' ) ), true ) ) ) {
		return new WP_Error( 'spiral_mcp_bad_parent', 'Un término no puede ser hijo de sí mismo ni de sus descendientes.' );
	}
	return true;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Estado de un término, con los contenidos a los que está asignado. ref = "taxonomía:ID". */
function spiral_mcp_term_capture( $ref ) {
	list( $taxonomy, $id ) = array_pad( explode( ':', (string) $ref, 2 ), 2, 0 );
	$term                  = get_term( (int) $id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	return array(
		'taxonomy'    => $taxonomy,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => $term->description,
		'parent'      => (int) $term->parent,
		'objects'     => array_map( 'intval', (array) get_objects_in_term( $term->term_id, $taxonomy ) ),
	);
}

/** Restaura un término (lo recrea si se borró y le devuelve sus contenidos). null = no existía. */
function spiral_mcp_term_restore( $ref, $data ) {
	list( $taxonomy, $id ) = array_pad( explode( ':', (string) $ref, 2 ), 2, 0 );
	$term                  = get_term( (int) $id, $taxonomy );
	$exists                = $term && ! is_wp_error( $term );
	if ( null === $data ) {
		if ( $exists ) {
			wp_delete_term( $term->term_id, $taxonomy );
		}
		return array( 'term_id' => 0 );
	}
	$args = array( 'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'], 'parent' => $data['parent'] );
	$res  = $exists ? wp_update_term( $term->term_id, $taxonomy, $args ) : wp_insert_term( $data['name'], $taxonomy, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$term_id = (int) $res['term_id'];
	foreach ( (array) $data['objects'] as $object_id ) {
		wp_add_object_terms( (int) $object_id, $term_id, $taxonomy );
	}
	return array( 'term_id' => $term_id );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['term'] = array(
		'group'   => 'content',
		'cap'     => 'manage_categories',
		'capture' => 'spiral_mcp_term_capture',
		'restore' => 'spiral_mcp_term_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$term_fields = array(
		'name'        => array( 'type' => 'string' ),
		'slug'        => array( 'type' => 'string' ),
		'description' => array( 'type' => 'string' ),
		'parent'      => array( 'type' => 'integer', 'description' => 'ID del término padre (solo taxonomías jerárquicas, como category; 0 = sin padre).' ),
	);
	$log_term    = array(
		'type' => 'term',
		'id'   => function ( $in, $res ) {
			return $res['id'] ?? ( $res['term']['id'] ?? ( $in['term_id'] ?? '' ) );
		},
	);

	spiral_mcp_register_ability( 'spiral-mcp/list-taxonomies', array(
		'label'               => 'Listar taxonomías',
		'description'         => 'Lista las taxonomías editables (category, post_tag y personalizadas): nombre, etiqueta, si es jerárquica (admite padres), a qué tipos de contenido se aplica y si puedes gestionarla o asignarla.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function () {
			$out = array();
			foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
				$out[] = array(
					'name'         => $tax->name,
					'label'        => $tax->labels->singular_name,
					'hierarchical' => (bool) $tax->hierarchical,
					'post_types'   => array_values( (array) $tax->object_type ),
					'can_manage'   => current_user_can( $tax->cap->manage_terms ),
					'can_assign'   => current_user_can( $tax->cap->assign_terms ),
				);
			}
			return array( 'taxonomies' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/list-terms', array(
		'label'               => 'Listar términos',
		'description'         => 'Lista los términos de una taxonomía (incluidos los que no tienen contenido): id, nombre, slug, descripción, padre, número de contenidos y URL relativa. Para asignarlos a un contenido usa "terms" en spiral-mcp/create-content o update-content.',
		'category'            => 'spiral-mcp-content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'taxonomy' ),
			'properties' => array(
				'taxonomy' => array( 'type' => 'string', 'description' => 'p. ej. category o post_tag.' ),
				'search'   => array( 'type' => 'string' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'Solo hijos directos de este término (0 = primer nivel).' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$tax   = spiral_mcp_taxonomy_get( $input['taxonomy'] ?? '' );
			if ( is_wp_error( $tax ) ) {
				return $tax;
			}
			$per  = (int) ( $input['per_page'] ?? 50 );
			$args = array(
				'taxonomy'   => $tax->name,
				'hide_empty' => false,
				'orderby'    => 'name',
			);
			if ( ! empty( $input['search'] ) ) {
				$args['search'] = (string) $input['search'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['parent'] = (int) $input['parent'];
			}
			$total = (int) wp_count_terms( $args );
			$terms = get_terms( $args + array( 'number' => $per, 'offset' => ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per ) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			return array( 'total' => $total, 'items' => array_map( 'spiral_mcp_term_out', $terms ) );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/create-term', array(
		'label'               => 'Crear término',
		'description'         => 'Crea un término (p. ej. una categoría o etiqueta). Si ya existe uno con el mismo nombre en el mismo nivel, devuelve error con su ID. Guarda un snapshot que, al restaurarlo, lo borra.',
		'category'            => 'spiral-mcp-content',
		'log'                 => $log_term,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'taxonomy', 'name' ),
			'properties' => array( 'taxonomy' => array( 'type' => 'string' ) ) + $term_fields,
		),
		'permission_callback' => function ( $input = array() ) {
			$tax = get_taxonomy( (string) ( ( (array) $input )['taxonomy'] ?? '' ) );
			return $tax ? current_user_can( $tax->cap->edit_terms ) : current_user_can( 'manage_categories' );
		},
		'execute_callback'    => function ( $input ) {
			$tax = spiral_mcp_taxonomy_get( $input['taxonomy'] ?? '' );
			if ( is_wp_error( $tax ) ) {
				return $tax;
			}
			$check = spiral_mcp_term_check_parent( $tax, $input['parent'] ?? 0 );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$res = wp_insert_term( (string) $input['name'], $tax->name, array_intersect_key( (array) $input, array_flip( array( 'slug', 'description', 'parent' ) ) ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$snapshot = spiral_mcp_snapshot_take( 'term', $tax->name . ':' . $res['term_id'], null, 'create-term: el término no existía' );
			return spiral_mcp_term_out( get_term( $res['term_id'], $tax->name ) ) + array( 'snapshot_id' => is_wp_error( $snapshot ) ? null : $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-term', array(
		'label'               => 'Editar término',
		'description'         => 'Cambia el nombre, slug, descripción o padre de un término (solo los campos enviados). Cambiar el slug cambia su URL. Antes guarda un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-content',
		'log'                 => $log_term,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'term_id' ),
			'properties' => array( 'term_id' => array( 'type' => 'integer' ) ) + $term_fields,
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_term', (int) ( ( (array) $input )['term_id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$term = spiral_mcp_term_get( $input['term_id'] ?? 0 );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$args = array_intersect_key( (array) $input, array_flip( array( 'name', 'slug', 'description', 'parent' ) ) );
			if ( ! $args ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía al menos uno de: name, slug, description, parent.' );
			}
			if ( isset( $args['parent'] ) ) {
				$check = spiral_mcp_term_check_parent( get_taxonomy( $term->taxonomy ), $args['parent'], $term->term_id );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
			$snapshot = spiral_mcp_snapshot_capture( 'term', $term->taxonomy . ':' . $term->term_id, 'update-term' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = wp_update_term( $term->term_id, $term->taxonomy, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_term_out( get_term( $term->term_id, $term->taxonomy ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/delete-term', array(
		'label'               => 'Borrar término',
		'description'         => 'Borra un término. Los contenidos que lo tenían lo pierden (en categorías, si una entrada se queda sin ninguna, pasa a la categoría por defecto); los términos hijos suben un nivel. La categoría por defecto no se puede borrar. Antes guarda un snapshot ("snapshot_id"): restaurarlo lo recrea (con ID nuevo) y se lo vuelve a asignar a sus contenidos. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-content',
		'log'                 => $log_term,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'term_id', 'confirm' ),
			'properties' => array(
				'term_id' => array( 'type' => 'integer' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'delete_term', (int) ( ( (array) $input )['term_id'] ?? 0 ) );
		},
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar el término' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$term = spiral_mcp_term_get( $input['term_id'] ?? 0 );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			if ( 'category' === $term->taxonomy && (int) get_option( 'default_category' ) === (int) $term->term_id ) {
				return new WP_Error( 'spiral_mcp_default_term', 'No se puede borrar la categoría por defecto.' );
			}
			$before   = spiral_mcp_term_out( $term );
			$snapshot = spiral_mcp_snapshot_capture( 'term', $term->taxonomy . ':' . $term->term_id, 'delete-term' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = wp_delete_term( $term->term_id, $term->taxonomy );
			if ( is_wp_error( $res ) || ! $res ) {
				return is_wp_error( $res ) ? $res : new WP_Error( 'spiral_mcp_delete_failed', 'No se pudo borrar el término.' );
			}
			return array( 'deleted' => true, 'term' => $before, 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );
} );
