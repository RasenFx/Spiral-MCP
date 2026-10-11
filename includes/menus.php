<?php
/**
 * Abilities de menús de navegación: listar, leer, crear, editar y borrar
 * menús y sus elementos.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Obtiene un menú por ID, slug o nombre. */
function spiral_mcp_menu_get( $menu ) {
	$obj = wp_get_nav_menu_object( is_numeric( $menu ) ? (int) $menu : (string) $menu );
	if ( ! $obj ) {
		return new WP_Error( 'spiral_mcp_menu_not_found', 'No existe un menú con ese ID, slug o nombre.' );
	}
	return $obj;
}

/** Ubicaciones del tema donde está asignado un menú. */
function spiral_mcp_menu_locations_of( $menu_id ) {
	$out = array();
	foreach ( (array) get_nav_menu_locations() as $location => $id ) {
		if ( (int) $id === (int) $menu_id ) {
			$out[] = $location;
		}
	}
	return $out;
}

/** Elementos de un menú (incluye borradores), ordenados. */
function spiral_mcp_menu_items( $menu_id ) {
	$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any', 'update_post_term_cache' => false ) );
	return is_array( $items ) ? $items : array();
}

/** Formato de salida de un elemento. */
function spiral_mcp_menu_item_out( $item ) {
	$out = array(
		'id'           => (int) $item->ID,
		'title'        => $item->title,
		'url'          => $item->url,
		'type'         => $item->object,
		'object_id'    => (int) $item->object_id,
		'parent_id'    => (int) $item->menu_item_parent,
		'position'     => (int) $item->menu_order,
		'target_blank' => '_blank' === $item->target,
		'classes'      => trim( implode( ' ', array_filter( (array) $item->classes ) ) ),
	);
	if ( ! empty( $item->_invalid ) ) {
		$out['invalid'] = true; // El contenido enlazado ya no existe.
	}
	return $out;
}

/** Árbol anidado de elementos. */
function spiral_mcp_menu_tree( $menu_id ) {
	$nodes = array();
	foreach ( spiral_mcp_menu_items( $menu_id ) as $item ) {
		$nodes[ (int) $item->ID ] = spiral_mcp_menu_item_out( $item );
	}
	$kids = array();
	foreach ( $nodes as $id => $node ) {
		$p            = isset( $nodes[ $node['parent_id'] ] ) ? $node['parent_id'] : 0; // Padre huérfano = raíz.
		$kids[ $p ][] = $id;
	}
	$build = function ( $p, $depth = 0 ) use ( &$build, $nodes, $kids ) {
		$out = array();
		foreach ( $kids[ $p ] ?? array() as $id ) {
			$out[] = $nodes[ $id ] + array( 'children' => $depth < 20 ? $build( $id, $depth + 1 ) : array() );
		}
		return $out;
	};
	return $build( 0 );
}

/** Datos completos de un menú. */
function spiral_mcp_menu_out( $menu ) {
	return array(
		'id'        => (int) $menu->term_id,
		'name'      => $menu->name,
		'slug'      => $menu->slug,
		'count'     => (int) $menu->count,
		'locations' => spiral_mcp_menu_locations_of( $menu->term_id ),
		'items'     => spiral_mcp_menu_tree( $menu->term_id ),
	);
}

/** ¿El elemento pertenece al menú? */
function spiral_mcp_menu_has_item( $menu_id, $item_id ) {
	return in_array( (int) $item_id, array_map( 'intval', wp_list_pluck( spiral_mcp_menu_items( $menu_id ), 'ID' ) ), true );
}

/** Menú al que pertenece un elemento. */
function spiral_mcp_menu_of_item( $item_id ) {
	if ( ! is_nav_menu_item( (int) $item_id ) ) {
		return new WP_Error( 'spiral_mcp_item_not_found', 'No existe un elemento de menú con ese ID.' );
	}
	$terms = wp_get_object_terms( (int) $item_id, 'nav_menu' );
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return new WP_Error( 'spiral_mcp_item_orphan', 'El elemento no pertenece a ningún menú.' );
	}
	return $terms[0];
}

/** Valida que $parent_id sea un padre válido para $item_id en $menu_id. */
function spiral_mcp_menu_check_parent( $menu_id, $parent_id, $item_id = 0 ) {
	$parent_id = (int) $parent_id;
	if ( 0 === $parent_id ) {
		return true;
	}
	if ( ! spiral_mcp_menu_has_item( $menu_id, $parent_id ) ) {
		return new WP_Error( 'spiral_mcp_bad_parent', 'El elemento padre no pertenece a este menú.' );
	}
	if ( $item_id ) {
		// Evita ciclos: el padre no puede ser el propio elemento ni un descendiente.
		$parents = wp_list_pluck( spiral_mcp_menu_items( $menu_id ), 'menu_item_parent', 'ID' );
		$cursor  = $parent_id;
		$guard   = 0;
		while ( $cursor && $guard++ < 100 ) {
			if ( (int) $cursor === (int) $item_id ) {
				return new WP_Error( 'spiral_mcp_bad_parent', 'Un elemento no puede ser hijo de sí mismo ni de sus descendientes.' );
			}
			$cursor = (int) ( $parents[ $cursor ] ?? 0 );
		}
	}
	return true;
}

/**
 * Convierte la entrada de la ability en los argumentos de wp_update_nav_menu_item().
 * $base son los valores actuales (al editar) para no perder campos no enviados.
 */
function spiral_mcp_menu_item_args( array $in, array $base = array() ) {
	$args = wp_parse_args( $base, array(
		'menu-item-title'     => '',
		'menu-item-url'       => '',
		'menu-item-type'      => 'custom',
		'menu-item-object'    => 'custom',
		'menu-item-object-id' => 0,
		'menu-item-parent-id' => 0,
		'menu-item-position'  => 0,
		'menu-item-target'    => '',
		'menu-item-classes'   => '',
		'menu-item-attr-title' => '',
		'menu-item-description' => '',
		'menu-item-xfn'       => '',
		'menu-item-status'    => 'publish',
	) );

	if ( isset( $in['type'] ) ) {
		$type = sanitize_key( $in['type'] );
		if ( 'custom' === $type ) {
			$args['menu-item-type']      = 'custom';
			$args['menu-item-object']    = 'custom';
			$args['menu-item-object-id'] = 0;
		} elseif ( post_type_exists( $type ) ) {
			$args['menu-item-type']   = 'post_type';
			$args['menu-item-object'] = $type;
			$args['menu-item-url']    = '';
		} elseif ( taxonomy_exists( $type ) ) {
			$args['menu-item-type']   = 'taxonomy';
			$args['menu-item-object'] = $type;
			$args['menu-item-url']    = '';
		} else {
			return new WP_Error( 'spiral_mcp_bad_type', sprintf( 'Tipo "%s" no válido. Usa custom, page, post, category, post_tag u otro tipo de contenido o taxonomía registrada.', $type ) );
		}
	}
	if ( isset( $in['object_id'] ) ) {
		$args['menu-item-object-id'] = (int) $in['object_id'];
	}
	if ( isset( $in['title'] ) ) {
		$args['menu-item-title'] = (string) $in['title'];
	}
	if ( isset( $in['url'] ) ) {
		$args['menu-item-url'] = (string) $in['url'];
	}
	if ( isset( $in['target_blank'] ) ) {
		$args['menu-item-target'] = $in['target_blank'] ? '_blank' : '';
	}
	if ( isset( $in['classes'] ) ) {
		$args['menu-item-classes'] = (string) $in['classes'];
	}
	if ( isset( $in['parent_id'] ) ) {
		$args['menu-item-parent-id'] = (int) $in['parent_id'];
	}

	// Validaciones según el tipo final.
	if ( 'custom' === $args['menu-item-type'] ) {
		if ( '' === $args['menu-item-url'] || '' === $args['menu-item-title'] ) {
			return new WP_Error( 'spiral_mcp_bad_item', 'Un enlace personalizado (type=custom) necesita "title" y "url".' );
		}
	} elseif ( 'post_type' === $args['menu-item-type'] ) {
		$post = get_post( (int) $args['menu-item-object-id'] );
		if ( ! $post || $post->post_type !== $args['menu-item-object'] ) {
			return new WP_Error( 'spiral_mcp_bad_item', sprintf( 'No existe un "%s" con object_id %d.', $args['menu-item-object'], $args['menu-item-object-id'] ) );
		}
	} elseif ( 'taxonomy' === $args['menu-item-type'] ) {
		$term = get_term( (int) $args['menu-item-object-id'], $args['menu-item-object'] );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'spiral_mcp_bad_item', sprintf( 'No existe un término de "%s" con object_id %d.', $args['menu-item-object'], $args['menu-item-object-id'] ) );
		}
	}
	return $args;
}

/** Valores actuales de un elemento, en formato de wp_update_nav_menu_item(). */
function spiral_mcp_menu_item_base( $item_id ) {
	$post = get_post( (int) $item_id );
	$item = wp_setup_nav_menu_item( $post );
	return array(
		'menu-item-title'       => $post->post_title, // Vacío = usa el título original del contenido.
		'menu-item-url'         => 'custom' === $item->type ? $item->url : '',
		'menu-item-type'        => $item->type,
		'menu-item-object'      => $item->object,
		'menu-item-object-id'   => (int) $item->object_id,
		'menu-item-parent-id'   => (int) $item->menu_item_parent,
		'menu-item-position'    => (int) $item->menu_order,
		'menu-item-target'      => $item->target,
		'menu-item-classes'     => trim( implode( ' ', array_filter( (array) $item->classes ) ) ),
		'menu-item-attr-title'  => $item->attr_title,
		'menu-item-description' => $item->description,
		'menu-item-xfn'         => $item->xfn,
		'menu-item-status'      => 'publish',
	);
}

/**
 * Renumera menu_order recorriendo el árbol. Si se indica $move_id y $index
 * (1 = primero), coloca ese elemento en esa posición entre sus hermanos.
 */
function spiral_mcp_menu_reorder( $menu_id, $move_id = 0, $index = null ) {
	$items  = spiral_mcp_menu_items( $menu_id );
	$ids    = array_map( 'intval', wp_list_pluck( $items, 'ID' ) );
	$kids   = array( 0 => array() );
	$parent = array();
	foreach ( $items as $item ) {
		$p = (int) $item->menu_item_parent;
		if ( $p && ! in_array( $p, $ids, true ) ) {
			$p = 0; // Padre huérfano: se trata como raíz.
		}
		$parent[ (int) $item->ID ] = $p;
		$kids[ $p ][]              = (int) $item->ID;
	}
	if ( $move_id && null !== $index && isset( $parent[ $move_id ] ) ) {
		$p          = $parent[ $move_id ];
		$kids[ $p ] = array_values( array_diff( $kids[ $p ], array( $move_id ) ) );
		$pos        = max( 0, min( (int) $index - 1, count( $kids[ $p ] ) ) );
		array_splice( $kids[ $p ], $pos, 0, array( $move_id ) );
	}
	$order  = 0;
	$orders = array();
	$walk   = function ( $p ) use ( &$walk, &$kids, &$order, &$orders ) {
		foreach ( $kids[ $p ] ?? array() as $id ) {
			if ( isset( $orders[ $id ] ) ) {
				continue;
			}
			$orders[ $id ] = ++$order;
			$walk( $id );
		}
	};
	$walk( 0 );
	foreach ( $items as $item ) {
		$id = (int) $item->ID;
		if ( isset( $orders[ $id ] ) && (int) $item->menu_order !== $orders[ $id ] ) {
			wp_update_post( array( 'ID' => $id, 'menu_order' => $orders[ $id ] ) );
		}
	}
}

/** Agrega elementos (con hijos anidados) a un menú. Devuelve IDs creados o WP_Error. */
function spiral_mcp_menu_add_items( $menu_id, array $items, $parent_id = 0 ) {
	$created = array();
	foreach ( $items as $in ) {
		$in              = (array) $in;
		$in['parent_id'] = $parent_id;
		$args            = spiral_mcp_menu_item_args( $in );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$created[] = (int) $id;
		if ( ! empty( $in['children'] ) && is_array( $in['children'] ) ) {
			$sub = spiral_mcp_menu_add_items( $menu_id, $in['children'], (int) $id );
			if ( is_wp_error( $sub ) ) {
				return $sub;
			}
			$created = array_merge( $created, $sub );
		}
	}
	return $created;
}

/** Asigna exactamente estas ubicaciones al menú (quita las demás que tenía). */
function spiral_mcp_menu_set_locations( $menu_id, array $locations ) {
	$registered = get_registered_nav_menus();
	foreach ( $locations as $loc ) {
		if ( ! isset( $registered[ $loc ] ) ) {
			return new WP_Error( 'spiral_mcp_bad_location', sprintf( 'La ubicación "%s" no existe en el tema. Disponibles: %s', $loc, implode( ', ', array_keys( $registered ) ) ) );
		}
	}
	$current = (array) get_theme_mod( 'nav_menu_locations', array() );
	foreach ( $current as $loc => $id ) {
		if ( (int) $id === (int) $menu_id && ! in_array( $loc, $locations, true ) ) {
			unset( $current[ $loc ] );
		}
	}
	foreach ( $locations as $loc ) {
		$current[ $loc ] = (int) $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $current );
	return true;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

/** Estado completo de un menú (nombre, ubicaciones y elementos), o null si no existe. */
function spiral_mcp_menu_capture( $menu_id ) {
	$menu = wp_get_nav_menu_object( (int) $menu_id );
	if ( ! $menu ) {
		return null;
	}
	$items = array();
	foreach ( spiral_mcp_menu_items( $menu->term_id ) as $item ) {
		$items[ (int) $item->ID ] = spiral_mcp_menu_item_base( $item->ID );
	}
	return array(
		'name'      => $menu->name,
		'locations' => spiral_mcp_menu_locations_of( $menu->term_id ),
		'items'     => $items,
	);
}

/**
 * Devuelve un menú al estado capturado. Los elementos que siguen existiendo
 * conservan su ID; los borrados se recrean con IDs nuevos. $data = null
 * significa que el menú no existía: se borra.
 */
function spiral_mcp_menu_restore( $menu_id, $data ) {
	$menu = wp_get_nav_menu_object( (int) $menu_id );
	if ( null === $data ) {
		if ( $menu ) {
			spiral_mcp_menu_set_locations( $menu->term_id, array() );
			wp_delete_nav_menu( $menu->term_id );
		}
		return array( 'menu_id' => 0, 'deleted' => (bool) $menu );
	}
	if ( ! is_array( $data ) || ! isset( $data['name'], $data['items'] ) ) {
		return new WP_Error( 'spiral_mcp_bad_snapshot', 'El snapshot del menú está incompleto.' );
	}

	if ( $menu ) {
		$id = (int) $menu->term_id;
		if ( $menu->name !== $data['name'] ) {
			$res = wp_update_nav_menu_object( $id, array( 'menu-name' => $data['name'] ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
	} else {
		$id = wp_create_nav_menu( $data['name'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
	}

	// Primero todos en la raíz; los padres se asignan después con los IDs ya conocidos.
	$current = array_map( 'intval', wp_list_pluck( spiral_mcp_menu_items( $id ), 'ID' ) );
	$map     = array();
	foreach ( (array) $data['items'] as $old_id => $args ) {
		$args['menu-item-parent-id'] = 0;
		$new                         = wp_update_nav_menu_item( $id, in_array( (int) $old_id, $current, true ) ? (int) $old_id : 0, $args );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$map[ (int) $old_id ] = (int) $new;
	}
	foreach ( $current as $item_id ) {
		if ( ! isset( $map[ $item_id ] ) ) {
			wp_delete_post( $item_id, true );
		}
	}
	foreach ( (array) $data['items'] as $old_id => $args ) {
		$parent = (int) $args['menu-item-parent-id'];
		update_post_meta( $map[ (int) $old_id ], '_menu_item_menu_item_parent', (string) ( $map[ $parent ] ?? 0 ) );
		wp_update_post( array( 'ID' => $map[ (int) $old_id ], 'menu_order' => (int) $args['menu-item-position'] ) );
	}
	spiral_mcp_menu_reorder( $id );

	// Solo las ubicaciones que el tema actual sigue registrando.
	$locations = array_values( array_intersect( (array) $data['locations'], array_keys( get_registered_nav_menus() ) ) );
	spiral_mcp_menu_set_locations( $id, $locations );
	return array( 'menu_id' => (int) $id );
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['menu'] = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => 'spiral_mcp_menu_capture',
		'restore' => 'spiral_mcp_menu_restore',
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-menus', array(
		'label'       => 'Menús Spiral-MCP',
		'description' => 'Leer, crear, editar y borrar menús de navegación y sus elementos.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	// ID del menú afectado, para el registro de auditoría.
	$log_menu = array(
		'type' => 'menu',
		'id'   => function ( $in, $res ) {
			return $res['id'] ?? ( $res['menu']['id'] ?? ( $in['menu'] ?? '' ) );
		},
	);

	$menu_ref = array(
		'type'        => array( 'integer', 'string' ),
		'description' => 'ID, slug o nombre del menú.',
	);

	$item_props = array(
		'title'        => array( 'type' => 'string', 'description' => 'Texto del enlace. En páginas/entradas/categorías es opcional (usa el título original).' ),
		'type'         => array( 'type' => 'string', 'description' => 'custom (enlace libre), page, post, category, post_tag u otro tipo de contenido o taxonomía registrada.' ),
		'url'          => array( 'type' => 'string', 'description' => 'Solo para type=custom. Para enlaces internos usa rutas relativas, p. ej. /perfil/.' ),
		'object_id'    => array( 'type' => 'integer', 'description' => 'ID de la página, entrada o término cuando type no es custom.' ),
		'target_blank' => array( 'type' => 'boolean', 'description' => 'Abrir en pestaña nueva.' ),
		'classes'      => array( 'type' => 'string', 'description' => 'Clases CSS separadas por espacio.' ),
	);

	/* 1. Listar menús ---------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-menus', array(
		'label'               => 'Listar menús',
		'description'         => 'Lista los menús de navegación (ID, nombre, nº de elementos, ubicaciones asignadas) y las ubicaciones de menú que registra el tema.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$menus = array();
			foreach ( wp_get_nav_menus() as $m ) {
				$menus[] = array(
					'id'        => (int) $m->term_id,
					'name'      => $m->name,
					'slug'      => $m->slug,
					'count'     => (int) $m->count,
					'locations' => spiral_mcp_menu_locations_of( $m->term_id ),
				);
			}
			$assigned  = (array) get_nav_menu_locations();
			$locations = array();
			foreach ( get_registered_nav_menus() as $loc => $label ) {
				$locations[] = array(
					'location' => $loc,
					'label'    => $label,
					'menu_id'  => (int) ( $assigned[ $loc ] ?? 0 ),
				);
			}
			return array( 'menus' => $menus, 'locations' => $locations );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Obtener menú ---------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-menu', array(
		'label'               => 'Obtener menú',
		'description'         => 'Devuelve un menú con sus elementos en árbol (id, título, url, tipo, padre, posición, hijos).',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'menu' ),
			'properties' => array( 'menu' => $menu_ref ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$menu = spiral_mcp_menu_get( $input['menu'] ?? 0 );
			return is_wp_error( $menu ) ? $menu : spiral_mcp_menu_out( $menu );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Crear menú ------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/create-menu', array(
		'label'               => 'Crear menú',
		'description'         => 'Crea un menú nuevo. Opcionalmente le agrega elementos (con "children" para submenús) y lo asigna a ubicaciones del tema. Guarda un snapshot que, al restaurarlo con spiral-mcp/restore-snapshot, borra el menú.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => $log_menu,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'name'      => array( 'type' => 'string' ),
				'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Ubicaciones del tema (ver spiral-mcp/list-menus).' ),
				'items'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => $item_props + array(
							'children' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Subelementos, con la misma estructura.' ),
						),
					),
				),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$id = wp_create_nav_menu( (string) $input['name'] );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$snapshot = spiral_mcp_snapshot_take( 'menu', $id, null, 'create-menu: el menú no existía' );
			if ( is_wp_error( $snapshot ) ) {
				wp_delete_nav_menu( $id );
				return $snapshot;
			}
			if ( ! empty( $input['items'] ) ) {
				$res = spiral_mcp_menu_add_items( $id, (array) $input['items'] );
				if ( is_wp_error( $res ) ) {
					wp_delete_nav_menu( $id ); // No dejar un menú a medias.
					return $res;
				}
				spiral_mcp_menu_reorder( $id );
			}
			if ( ! empty( $input['locations'] ) ) {
				$res = spiral_mcp_menu_set_locations( $id, (array) $input['locations'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return spiral_mcp_menu_out( wp_get_nav_menu_object( $id ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Editar menú ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-menu', array(
		'label'               => 'Editar menú',
		'description'         => 'Renombra un menú y/o cambia sus ubicaciones. "locations" reemplaza la lista completa de ubicaciones de este menú (envía [] para quitarlo de todas). Antes guarda un snapshot del menú.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => $log_menu,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'menu' ),
			'properties' => array(
				'menu'      => $menu_ref,
				'name'      => array( 'type' => 'string' ),
				'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$menu = spiral_mcp_menu_get( $input['menu'] ?? 0 );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			if ( ! isset( $input['name'] ) && ! isset( $input['locations'] ) ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "name" y/o "locations".' );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'update-menu' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if ( isset( $input['name'] ) && $input['name'] !== $menu->name ) {
				$res = wp_update_nav_menu_object( $menu->term_id, array( 'menu-name' => (string) $input['name'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			if ( isset( $input['locations'] ) ) {
				$res = spiral_mcp_menu_set_locations( $menu->term_id, (array) $input['locations'] );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			return spiral_mcp_menu_out( wp_get_nav_menu_object( $menu->term_id ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 5. Borrar menú ----------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-menu', array(
		'label'               => 'Borrar menú',
		'description'         => 'Borra un menú completo con todos sus elementos y lo quita de sus ubicaciones. Antes guarda un snapshot ("snapshot_id"): spiral-mcp/restore-snapshot lo recrea, con IDs nuevos para el menú y sus elementos. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => $log_menu,
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'menu', 'confirm' ),
			'properties' => array(
				'menu'    => $menu_ref,
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar el menú' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$menu = spiral_mcp_menu_get( $input['menu'] ?? 0 );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$snapshot_id = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'delete-menu' );
			if ( is_wp_error( $snapshot_id ) ) {
				return $snapshot_id;
			}
			$snapshot = spiral_mcp_menu_out( $menu ); // Se devuelve para poder recrearlo si hace falta.
			spiral_mcp_menu_set_locations( $menu->term_id, array() );
			$res = wp_delete_nav_menu( $menu->term_id );
			if ( is_wp_error( $res ) || ! $res ) {
				return is_wp_error( $res ) ? $res : new WP_Error( 'spiral_mcp_delete_failed', 'No se pudo borrar el menú.' );
			}
			return array( 'deleted' => true, 'menu' => $snapshot, 'snapshot_id' => $snapshot_id );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* 6. Agregar elemento ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/add-menu-item', array(
		'label'               => 'Agregar elemento a un menú',
		'description'         => 'Agrega un enlace a un menú: personalizado (type=custom + url) o a una página/entrada/categoría (type + object_id). "parent_id" lo convierte en subelemento y "position" (1 = primero) lo ubica entre sus hermanos; por defecto va al final. Antes guarda un snapshot del menú.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => array( 'type' => 'menu_item', 'id' => 'item_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'menu', 'type' ),
			'properties' => array(
				'menu'      => $menu_ref,
				'parent_id' => array( 'type' => 'integer', 'description' => 'ID del elemento padre (0 = nivel superior).' ),
				'position'  => array( 'type' => 'integer', 'minimum' => 1 ),
			) + $item_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$menu = spiral_mcp_menu_get( $input['menu'] ?? 0 );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$check = spiral_mcp_menu_check_parent( $menu->term_id, $input['parent_id'] ?? 0 );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$args = spiral_mcp_menu_item_args( $input );
			if ( is_wp_error( $args ) ) {
				return $args;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'add-menu-item' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			spiral_mcp_menu_reorder( $menu->term_id, (int) $id, $input['position'] ?? null );
			return array( 'item_id' => (int) $id ) + spiral_mcp_menu_out( wp_get_nav_menu_object( $menu->term_id ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 7. Editar elemento ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-menu-item', array(
		'label'               => 'Editar elemento de menú',
		'description'         => 'Cambia texto, enlace, destino, clases, padre o posición de un elemento. Solo se modifican los campos enviados. "position" (1 = primero) lo reubica entre sus hermanos. Antes guarda un snapshot del menú.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => array( 'type' => 'menu_item', 'id' => 'item_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'item_id' ),
			'properties' => array(
				'item_id'   => array( 'type' => 'integer' ),
				'parent_id' => array( 'type' => 'integer', 'description' => 'Nuevo padre (0 = nivel superior).' ),
				'position'  => array( 'type' => 'integer', 'minimum' => 1 ),
			) + $item_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$item_id = (int) ( $input['item_id'] ?? 0 );
			$menu    = spiral_mcp_menu_of_item( $item_id );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			if ( isset( $input['parent_id'] ) ) {
				$check = spiral_mcp_menu_check_parent( $menu->term_id, $input['parent_id'], $item_id );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
			$args = spiral_mcp_menu_item_args( $input, spiral_mcp_menu_item_base( $item_id ) );
			if ( is_wp_error( $args ) ) {
				return $args;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'update-menu-item' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = wp_update_nav_menu_item( $menu->term_id, $item_id, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_menu_reorder( $menu->term_id, $item_id, $input['position'] ?? null );
			return spiral_mcp_menu_out( wp_get_nav_menu_object( $menu->term_id ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 8. Borrar elemento ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-menu-item', array(
		'label'               => 'Borrar elemento de menú',
		'description'         => 'Borra un elemento de un menú. Sus subelementos suben un nivel (pasan a su padre) salvo que envíes "delete_children": true. Antes guarda un snapshot del menú ("snapshot_id"); restaurarlo con spiral-mcp/restore-snapshot recrea los elementos borrados con IDs nuevos.',
		'category'            => 'spiral-mcp-menus',
		'group'               => 'design',
		'log'                 => array( 'type' => 'menu_item', 'id' => 'item_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'item_id' ),
			'properties' => array(
				'item_id'         => array( 'type' => 'integer' ),
				'delete_children' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$item_id = (int) ( $input['item_id'] ?? 0 );
			$menu    = spiral_mcp_menu_of_item( $item_id );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'menu', $menu->term_id, 'delete-menu-item' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$items   = spiral_mcp_menu_items( $menu->term_id );
			$parents = array_map( 'intval', wp_list_pluck( $items, 'menu_item_parent', 'ID' ) );
			$up      = (int) ( $parents[ $item_id ] ?? 0 );

			// Descendientes del elemento.
			$desc  = array();
			$queue = array( $item_id );
			while ( $queue ) {
				$p = array_shift( $queue );
				foreach ( $parents as $id => $parent ) {
					if ( $parent === $p && ! in_array( $id, $desc, true ) ) {
						$desc[]  = $id;
						$queue[] = $id;
					}
				}
			}

			$deleted = array( $item_id );
			if ( ! empty( $input['delete_children'] ) ) {
				foreach ( $desc as $id ) {
					wp_delete_post( $id, true );
					$deleted[] = $id;
				}
			} else {
				foreach ( $parents as $id => $parent ) {
					if ( $parent === $item_id ) {
						update_post_meta( $id, '_menu_item_menu_item_parent', (string) $up );
					}
				}
			}
			if ( ! wp_delete_post( $item_id, true ) ) {
				return new WP_Error( 'spiral_mcp_delete_failed', 'No se pudo borrar el elemento.' );
			}
			spiral_mcp_menu_reorder( $menu->term_id );
			return array( 'deleted' => $deleted ) + spiral_mcp_menu_out( wp_get_nav_menu_object( $menu->term_id ) ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );
} );
