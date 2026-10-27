<?php
/**
 * Plantillas del builder de YOOtheme (entradas individuales, archivos,
 * búsqueda, 404…) y biblioteca de layouts y elementos guardados.
 *
 * YOOtheme guarda ambas en la opción "yootheme" (JSON) bajo "templates.<id>"
 * y "library.<id>", a través de su servicio Storage, que escribe la opción
 * entera al final de la petición. Por eso aquí se escribe siempre con ese
 * servicio y nunca con update_option().
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Servicio Storage de YOOtheme, o WP_Error si YOOtheme no está activo. */
function spiral_mcp_yootheme_storage() {
	if ( function_exists( 'YOOtheme\app' ) && class_exists( 'YOOtheme\Storage' ) ) {
		try {
			return \YOOtheme\app( \YOOtheme\Storage::class );
		} catch ( Throwable $e ) {
			// Se devuelve el error de abajo.
		}
	}
	return new WP_Error( 'spiral_mcp_no_yootheme', 'YOOtheme Pro no está activo en este sitio.' );
}

/** ID nuevo al estilo de YOOtheme (8 caracteres). */
function spiral_mcp_yootheme_id( $prefix ) {
	$storage = spiral_mcp_yootheme_storage();
	do {
		$id = wp_generate_password( 8, false, false );
	} while ( ! is_wp_error( $storage ) && $storage->has( $prefix . '.' . $id ) );
	return $id;
}

/**
 * Pasa un nodo por el pipeline de guardado de YOOtheme (migraciones,
 * valores por defecto, limpieza de props vacíos), como hace el
 * Personalizador con plantillas y biblioteca.
 */
function spiral_mcp_yootheme_normalize( array $node ) {
	$builder = spiral_mcp_yootheme_builder();
	if ( ! $builder ) {
		return $node;
	}
	try {
		$loaded = $builder->withParams( array( 'context' => 'save' ) )->load( wp_json_encode( spiral_mcp_layout_stamp_version( $node ) ) );
		$out    = json_decode( wp_json_encode( $loaded ), true );
		return is_array( $out ) ? $out : $node;
	} catch ( Throwable $e ) {
		return $node;
	}
}

/** Catálogo de tipos de plantilla del sitio: type => [ label, query, params ]. */
function spiral_mcp_template_types() {
	if ( ! class_exists( 'YOOtheme\Builder\BuilderConfig' ) ) {
		return array();
	}
	try {
		$config = json_decode( wp_json_encode( \YOOtheme\app( \YOOtheme\Builder\BuilderConfig::class ) ), true );
	} catch ( Throwable $e ) {
		return array();
	}
	$fields = function ( $list ) {
		$out = array();
		foreach ( (array) $list as $name => $f ) {
			$row = array( 'label' => $f['label'] ?? $name, 'type' => $f['type'] ?? 'text' );
			if ( isset( $f['options'] ) && is_array( $f['options'] ) ) {
				$row['options'] = spiral_mcp_field_option_values( $f['options'] );
			}
			$out[ $name ] = $row;
		}
		return (object) $out;
	};
	$types = array();
	foreach ( (array) ( $config['templates'] ?? array() ) as $type => $def ) {
		$types[ $type ] = array(
			'label'  => $def['label'] ?? $type,
			'query'  => $fields( $def['fieldset']['default']['fields'] ?? array() ),
			'params' => $fields( $def['fieldset']['params']['fields'] ?? array() ),
		);
	}
	return $types;
}

/** Plantilla por ID o WP_Error. */
function spiral_mcp_template_get( $id ) {
	$storage = spiral_mcp_yootheme_storage();
	if ( is_wp_error( $storage ) ) {
		return $storage;
	}
	$tpl = $storage->get( 'templates.' . $id );
	if ( ! is_array( $tpl ) ) {
		return new WP_Error( 'spiral_mcp_template_not_found', 'No existe una plantilla con ese ID. Búscala con spiral-mcp/list-templates.' );
	}
	return json_decode( wp_json_encode( $tpl ), true );
}

/** Resumen de una plantilla. */
function spiral_mcp_template_out( $id, array $tpl, $priority = null, $full = false ) {
	$out = array(
		'id'       => (string) $id,
		'name'     => (string) ( $tpl['name'] ?? '' ),
		'type'     => (string) ( $tpl['type'] ?? '' ),
		'status'   => ( $tpl['status'] ?? '' ) === 'disabled' ? 'disabled' : 'enabled',
		'query'    => (object) ( $tpl['query'] ?? array() ),
		'params'   => (object) ( $tpl['params'] ?? array() ),
		'priority' => $priority,
	);
	if ( $full ) {
		$out['layout'] = $tpl['layout'] ?? null;
	} else {
		$out['nodes'] = is_array( $tpl['layout'] ?? null ) ? count( spiral_mcp_layout_outline( $tpl['layout'] ) ) : 0;
	}
	return $out;
}

/** Guarda una plantilla (con snapshot de todas). Devuelve el ID del snapshot. */
function spiral_mcp_template_put( $id, $tpl, $note ) {
	$storage = spiral_mcp_yootheme_storage();
	if ( is_wp_error( $storage ) ) {
		return $storage;
	}
	$snapshot = spiral_mcp_snapshot_capture( 'yootheme_storage', 'templates', $note );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}
	if ( null === $tpl ) {
		$storage->del( 'templates.' . $id );
	} else {
		unset( $tpl['id'], $tpl['url'] );
		$storage->set( 'templates.' . $id, $tpl );
	}
	return $snapshot;
}

/** Valida y normaliza los campos de una plantilla (type, status, query, params, layout). */
function spiral_mcp_template_prepare( array $in, array $tpl = array() ) {
	$types = spiral_mcp_template_types();
	if ( isset( $in['name'] ) ) {
		$tpl['name'] = (string) $in['name'];
	}
	if ( isset( $in['type'] ) ) {
		if ( $types && ! isset( $types[ $in['type'] ] ) ) {
			return new WP_Error( 'spiral_mcp_bad_template_type', sprintf( 'Tipo de plantilla "%s" no válido. Disponibles: %s.', $in['type'], implode( ', ', array_keys( $types ) ) ) );
		}
		$tpl['type'] = (string) $in['type'];
	}
	if ( isset( $in['status'] ) ) {
		$tpl['status'] = 'disabled' === $in['status'] ? 'disabled' : '';
	}
	foreach ( array( 'query', 'params' ) as $key ) {
		if ( isset( $in[ $key ] ) ) {
			$tpl[ $key ] = (array) $in[ $key ];
		}
	}
	$warnings = array();
	if ( isset( $in['layout'] ) ) {
		if ( ! is_array( $in['layout'] ) || ( $in['layout']['type'] ?? '' ) !== 'layout' ) {
			return new WP_Error( 'spiral_mcp_bad_layout', 'El layout de una plantilla debe tener "type": "layout" en la raíz.' );
		}
		$warnings = spiral_mcp_layout_check( $in['layout'] );
		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}
		$tpl['layout'] = spiral_mcp_yootheme_normalize( $in['layout'] );
	}
	if ( empty( $tpl['type'] ) || ! isset( $tpl['name'] ) || '' === $tpl['name'] ) {
		return new WP_Error( 'spiral_mcp_bad_template', 'Una plantilla necesita "name" y "type".' );
	}
	if ( empty( $tpl['layout'] ) ) {
		$tpl['layout'] = spiral_mcp_layout_stamp_version( array( 'type' => 'layout', 'children' => array() ) );
	}
	// Avisa de claves de query/params que el tipo no tiene.
	if ( isset( $types[ $tpl['type'] ] ) ) {
		foreach ( array( 'query', 'params' ) as $key ) {
			$known = (array) $types[ $tpl['type'] ][ $key ];
			foreach ( array_keys( (array) ( $tpl[ $key ] ?? array() ) ) as $field ) {
				if ( ! isset( $known[ $field ] ) ) {
					$warnings[] = sprintf( '"%s.%s" no es un campo de las plantillas %s (campos: %s).', $key, $field, $tpl['type'], implode( ', ', array_keys( $known ) ) ?: 'ninguno' );
				}
			}
		}
	}
	return array( $tpl, $warnings );
}

/**
 * Inserta un elemento de biblioteca (layout guardado o elemento) en un layout ("id" o "area")
 * en la posición "path" de una lista children. Un layout aporta sus secciones (o sus filas, en
 * raíces "fragment"); un elemento, el propio elemento. Guarda como patch-layout.
 */
function spiral_mcp_library_insert( array $input, array $item, $note ) {
	unset( $item['name'], $item['modified'], $item['version'], $item['id'], $item['path'], $item['meta'], $item['images'] );
	$path = (string) ( $input['path'] ?? '' );
	if ( false === strpos( $path, '/' ) ) {
		return new WP_Error( 'spiral_mcp_bad_path', '"path" debe ser una posición en una lista children, p. ej. /children/1 o /children/0/children/0/children/0/children/-.' );
	}
	$nodes = array( $item );
	// Una página que aún no usa el builder empieza con el layout del diseño.
	if ( 'layout' === ( $item['type'] ?? '' ) && ! empty( $input['id'] ) ) {
		$loaded = spiral_mcp_layout_target_load( $input );
		if ( is_wp_error( $loaded ) && 'spiral_mcp_no_layout' === $loaded->get_error_code() ) {
			$post = spiral_mcp_get_post( $input['id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$warnings = spiral_mcp_layout_check( $item );
			if ( is_wp_error( $warnings ) ) {
				return $warnings;
			}
			$res = spiral_mcp_layout_save_post( $post, $item );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array( 'id' => (int) $post->ID ) + spiral_mcp_revision_info( $post->ID ) + array(
				'updated'  => true,
				'created'  => true,
				'warnings' => $warnings,
			);
		}
	}
	if ( 'layout' === ( $item['type'] ?? '' ) ) {
		$nodes = (array) ( $item['children'] ?? array() );
		// En raíces fragment (mega menú, widget) se insertan las filas.
		$loaded = spiral_mcp_layout_target_load( $input );
		if ( ! is_wp_error( $loaded ) && 'fragment' === ( $loaded['layout']['type'] ?? '' ) && preg_match( '#^/children/[^/]+$#', $path ) ) {
			$rows = array();
			foreach ( $nodes as $section ) {
				$rows = array_merge( $rows, (array) ( $section['children'] ?? array() ) );
			}
			$nodes = $rows;
		}
	}
	// Se insertan en orden: cada uno detrás del anterior.
	$ops   = array();
	$base  = substr( $path, 0, strrpos( $path, '/' ) + 1 );
	$index = substr( $path, strrpos( $path, '/' ) + 1 );
	foreach ( array_values( $nodes ) as $i => $node ) {
		$ops[] = array(
			'op'    => 'add',
			'path'  => $base . ( '-' === $index ? '-' : ( (int) $index + $i ) ),
			'value' => $node,
		);
	}
	return spiral_mcp_layout_patch_target( $input, $ops, $note );
}

/** Elemento de la biblioteca en formato de salida. */
function spiral_mcp_library_out( $id, array $item, $full = false ) {
	$out = array(
		'id'       => (string) $id,
		'name'     => (string) ( $item['name'] ?? '' ),
		'type'     => (string) ( $item['type'] ?? '' ),
		'kind'     => 'layout' === ( $item['type'] ?? '' ) ? 'layout' : 'element',
		'modified' => (string) ( $item['modified'] ?? '' ),
	);
	if ( $full ) {
		$out['node'] = $item;
	} else {
		$out['nodes'] = count( spiral_mcp_layout_outline( $item ) );
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Snapshots
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['yootheme_storage'] = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $ref ) {
			$storage = spiral_mcp_yootheme_storage();
			return is_wp_error( $storage ) ? null : json_decode( wp_json_encode( $storage->get( $ref ) ), true );
		},
		'restore' => function ( $ref, $data ) {
			$storage = spiral_mcp_yootheme_storage();
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}
			if ( null === $data ) {
				$storage->del( $ref );
			} else {
				$storage->set( $ref, $data );
			}
			return true;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	$tpl_props = array(
		'name'   => array( 'type' => 'string' ),
		'type'   => array( 'type' => 'string', 'description' => 'single-post, single-page, archive-post, taxonomy-category, author-archive, date-archive, search, error-404… (ver "types" en spiral-mcp/list-templates).' ),
		'status' => array( 'type' => 'string', 'enum' => array( 'enabled', 'disabled' ) ),
		'query'  => array( 'type' => 'object', 'description' => 'Condiciones de uso según el tipo (p. ej. {"terms":[12]} para limitar a una categoría). Vacío = todas.' ),
		'params' => array( 'type' => 'object', 'description' => 'Parámetros de la consulta en archivos (posts_per_page, order_by…).' ),
		'layout' => array( 'type' => 'object', 'description' => 'Layout con raíz "layout" (sección > fila > columna > elementos). Usa fuentes dinámicas ("source") para mostrar el título, contenido, etc. del post: la consulta es "posts.singlePost" en single-post y "posts.archivePost" (una lista: ponla en el item de un grid y se repite por entrada) en archive-post.' ),
	);

	/* 1. Listar plantillas ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-templates', array(
		'requires'            => 'yootheme',
		'label'               => 'Listar plantillas de YOOtheme',
		'description'         => 'Lista las plantillas del builder de YOOtheme (Personalizar > Templates): id, nombre, tipo, estado, condiciones (query), parámetros y prioridad. YOOtheme aplica la primera plantilla activa (menor "priority") cuyo tipo y condiciones encajan; una página con su propio layout de builder ignora las plantillas. Incluye "types": los tipos de plantilla de este sitio con los campos de "query" y "params" de cada uno.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$storage = spiral_mcp_yootheme_storage();
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}
			$out = array();
			$i   = 0;
			foreach ( (array) $storage->get( 'templates', array() ) as $id => $tpl ) {
				$out[] = spiral_mcp_template_out( $id, json_decode( wp_json_encode( $tpl ), true ), ++$i );
			}
			return array( 'templates' => $out, 'types' => (object) spiral_mcp_template_types() );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Obtener plantilla ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/get-template', array(
		'requires'            => 'yootheme',
		'label'               => 'Obtener plantilla de YOOtheme',
		'description'         => 'Devuelve una plantilla completa, con su layout. Para editar el layout por partes usa spiral-mcp/get-layout-outline y spiral-mcp/patch-layout con "area": "template:<id>".',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'string' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$tpl = spiral_mcp_template_get( $input['id'] ?? '' );
			return is_wp_error( $tpl ) ? $tpl : spiral_mcp_template_out( $input['id'], $tpl, null, true );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Crear plantilla -------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/create-template', array(
		'requires'            => 'yootheme',
		'label'               => 'Crear plantilla de YOOtheme',
		'description'         => 'Crea una plantilla del builder (p. ej. para todas las entradas individuales: "type": "single-post"). Se añade al final, con la prioridad más baja. Sin "layout" se crea vacía. Valida el layout con el catálogo de elementos y lo normaliza como el Personalizador. Antes guarda un snapshot de todas las plantillas ("snapshot_id"). Para comprobar el resultado, abre una URL a la que se aplique.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'template', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name', 'type' ),
			'properties' => $tpl_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$prep = spiral_mcp_template_prepare( (array) $input );
			if ( is_wp_error( $prep ) ) {
				return $prep;
			}
			list( $tpl, $warnings ) = $prep;
			$id                     = spiral_mcp_yootheme_id( 'templates' );
			$snapshot               = spiral_mcp_template_put( $id, $tpl, 'create-template' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			return spiral_mcp_template_out( $id, $tpl ) + array( 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Editar plantilla ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/update-template', array(
		'requires'            => 'yootheme',
		'label'               => 'Editar plantilla de YOOtheme',
		'description'         => 'Cambia los campos enviados de una plantilla (name, type, status, query, params o layout completo). "query" y "params" se reemplazan enteros. Antes guarda un snapshot de todas las plantillas ("snapshot_id").',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'template', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'string' ) ) + $tpl_props,
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$tpl = spiral_mcp_template_get( $input['id'] ?? '' );
			if ( is_wp_error( $tpl ) ) {
				return $tpl;
			}
			$prep = spiral_mcp_template_prepare( (array) $input, $tpl );
			if ( is_wp_error( $prep ) ) {
				return $prep;
			}
			list( $tpl, $warnings ) = $prep;
			$snapshot               = spiral_mcp_template_put( $input['id'], $tpl, 'update-template' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			return spiral_mcp_template_out( $input['id'], $tpl ) + array( 'snapshot_id' => $snapshot, 'warnings' => $warnings );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 5. Borrar plantilla ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/delete-template', array(
		'requires'            => 'yootheme',
		'label'               => 'Borrar plantilla de YOOtheme',
		'description'         => 'Borra una plantilla. Las páginas a las que se aplicaba pasan a usar la siguiente plantilla que encaje o el diseño por defecto del tema. Antes guarda un snapshot de todas las plantillas ("snapshot_id"; spiral-mcp/restore-snapshot la recupera). Requiere "confirm": true.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'template', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => array( 'type' => 'string' ),
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'borrar la plantilla' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$tpl = spiral_mcp_template_get( $input['id'] ?? '' );
			if ( is_wp_error( $tpl ) ) {
				return $tpl;
			}
			$snapshot = spiral_mcp_template_put( $input['id'], null, 'delete-template' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			return array( 'deleted' => true, 'template' => spiral_mcp_template_out( $input['id'], $tpl ), 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, true, true ),
	) );

	/* 6. Listar biblioteca ------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-library', array(
		'requires'            => 'yootheme',
		'label'               => 'Listar la biblioteca de layouts',
		'description'         => 'Lista la biblioteca del builder de YOOtheme ("Mis layouts" y "Mis elementos"): id, nombre, tipo ("layout" para layouts completos o el tipo de elemento para elementos guardados) y fecha.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			$storage = spiral_mcp_yootheme_storage();
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}
			$out = array();
			foreach ( (array) $storage->get( 'library', array() ) as $id => $item ) {
				$out[] = spiral_mcp_library_out( $id, json_decode( wp_json_encode( $item ), true ) );
			}
			return array( 'items' => $out );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 7. Obtener elemento de la biblioteca -------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-library-item', array(
		'requires'            => 'yootheme',
		'label'               => 'Obtener elemento de la biblioteca',
		'description'         => 'Devuelve un layout o elemento guardado en la biblioteca, con su árbol JSON completo ("node").',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'string' ) ),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$storage = spiral_mcp_yootheme_storage();
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}
			$item = $storage->get( 'library.' . ( $input['id'] ?? '' ) );
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'spiral_mcp_library_not_found', 'No existe ese elemento en la biblioteca (ver spiral-mcp/list-library).' );
			}
			return spiral_mcp_library_out( $input['id'], json_decode( wp_json_encode( $item ), true ), true );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	$source_props = array(
		'id'   => array( 'type' => 'integer', 'description' => 'ID de la página de origen.' ),
		'area' => array( 'type' => 'string', 'description' => 'En lugar de "id": área del tema o "template:<id>".' ),
		'path' => array( 'type' => 'string', 'description' => 'Ruta JSON Pointer del nodo (ver spiral-mcp/get-layout-outline).' ),
	);

	/* 8. Guardar en la biblioteca ----------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/save-to-library', array(
		'requires'            => 'yootheme',
		'label'               => 'Guardar en la biblioteca',
		'description'         => 'Guarda en la biblioteca de YOOtheme un nodo de un layout, tomado por su ruta: una sección, fila o columna se guarda como layout ("Mis layouts", envuelta en layout > sección); un elemento, como elemento guardado ("Mis elementos"); "path": "" guarda el layout entero. Devuelve el id del nuevo elemento de la biblioteca.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'library', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name', 'path' ),
			'properties' => $source_props + array( 'name' => array( 'type' => 'string', 'description' => 'Nombre en la biblioteca.' ) ),
		),
		'permission_callback' => function ( $input = array() ) {
			return current_user_can( 'edit_theme_options' ) && spiral_mcp_layout_target_can( $input );
		},
		'execute_callback'    => function ( $input ) {
			$loaded = spiral_mcp_layout_target_load( (array) $input );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$node = spiral_mcp_pointer_get( $loaded['layout'], $input['path'] ?? '' );
			if ( is_wp_error( $node ) || ! is_array( $node ) || empty( $node['type'] ) ) {
				return is_wp_error( $node ) ? $node : new WP_Error( 'spiral_mcp_bad_path', 'La ruta no apunta a un nodo del layout.' );
			}
			// Las piezas de estructura se guardan como layout completo.
			switch ( $node['type'] ) {
				case 'fragment':
					$node = array( 'type' => 'layout', 'children' => array( array( 'type' => 'section', 'children' => (array) ( $node['children'] ?? array() ) ) ) );
					break;
				case 'section':
					$node = array( 'type' => 'layout', 'children' => array( $node ) );
					break;
				case 'row':
					$node = array( 'type' => 'layout', 'children' => array( array( 'type' => 'section', 'children' => array( $node ) ) ) );
					break;
				case 'column':
					$node = array( 'type' => 'layout', 'children' => array( array( 'type' => 'section', 'children' => array( array( 'type' => 'row', 'children' => array( $node ) ) ) ) ) );
					break;
			}
			$item             = spiral_mcp_yootheme_normalize( $node );
			$item['name']     = (string) $input['name'];
			$item['modified'] = gmdate( 'Y-m-d\TH:i:s.v\Z' );
			$storage          = spiral_mcp_yootheme_storage();
			$snapshot         = spiral_mcp_snapshot_capture( 'yootheme_storage', 'library', 'save-to-library' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$id = spiral_mcp_yootheme_id( 'library' );
			$storage->set( 'library.' . $id, $item );
			return spiral_mcp_library_out( $id, $item ) + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 9. Insertar desde la biblioteca ------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/insert-from-library', array(
		'requires'            => 'yootheme',
		'label'               => 'Insertar desde la biblioteca',
		'description'         => 'Inserta un elemento de la biblioteca en un layout, en la posición "path" de una lista children (como "add" de spiral-mcp/patch-layout; "-" al final). Un layout guardado inserta sus secciones (path dentro de los children de la raíz de una página, p. ej. /children/2; en raíces "fragment", sus filas); un elemento guardado, el elemento (path dentro de los children de una columna). Valida el resultado y guarda como patch-layout (revisión o snapshot).',
		'category'            => 'spiral-mcp-layout',
		'group'               => array( 'content', 'design' ),
		'log'                 => array(
			'type' => 'layout',
			'id'   => function ( $in ) {
				return ! empty( $in['id'] ) ? 'post:' . $in['id'] : 'area:' . ( $in['area'] ?? '' );
			},
		),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'item_id', 'path' ),
			'properties' => array(
				'item_id' => array( 'type' => 'string', 'description' => 'ID del elemento de la biblioteca.' ),
				'id'      => array( 'type' => 'integer', 'description' => 'ID de la página de destino.' ),
				'area'    => array( 'type' => 'string', 'description' => 'En lugar de "id": área del tema o "template:<id>".' ),
				'path'    => array( 'type' => 'string', 'description' => 'Posición en una lista children, p. ej. /children/1 o /children/0/children/0/children/0/children/-.' ),
			),
		),
		'permission_callback' => 'spiral_mcp_layout_target_can',
		'execute_callback'    => function ( $input ) {
			$storage = spiral_mcp_yootheme_storage();
			if ( is_wp_error( $storage ) ) {
				return $storage;
			}
			$item = $storage->get( 'library.' . ( $input['item_id'] ?? '' ) );
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'spiral_mcp_library_not_found', 'No existe ese elemento en la biblioteca (ver spiral-mcp/list-library).' );
			}
			return spiral_mcp_library_insert( (array) $input, json_decode( wp_json_encode( $item ), true ), 'insert-from-library' );
		},
		'meta'                => spiral_mcp_meta( false ),
	) );
} );
