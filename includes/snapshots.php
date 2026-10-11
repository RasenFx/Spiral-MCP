<?php
/**
 * Snapshots genéricos: copias del estado de lo que no tiene revisiones
 * (configuración del tema, menús, widgets…) antes de cada cambio, y su
 * restauración.
 *
 * Cada módulo registra sus tipos ("kinds") con el filtro
 * spiral_mcp_snapshot_kinds: kind => array(
 *   'group'   => grupo de permisos (design, settings…),
 *   'cap'     => capacidad nativa necesaria para restaurar,
 *   'capture' => callable( $ref ) que devuelve el estado actual,
 *   'restore' => callable( $ref, $data ) que lo escribe (true|array|WP_Error),
 *   'parts'   => opcional: callable( $ref, $data ) que devuelve las partes (p. ej. secciones de la
 *                configuración) en que el snapshot difiere del estado actual,
 *   'partial' => opcional: callable( $ref, $data, $parts ) que devuelve el estado actual con solo
 *                esas partes tomadas del snapshot (o WP_Error), para restaurar una parte,
 * ).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Tipos de snapshot registrados. */
function spiral_mcp_snapshot_kinds() {
	return (array) apply_filters( 'spiral_mcp_snapshot_kinds', array() );
}

/**
 * Acumula los IDs de snapshot creados durante una ejecución (lo usa el
 * registro de auditoría). $reset = true empieza de cero; false devuelve los
 * IDs acumulados y deja de acumular.
 */
function spiral_mcp_snapshot_collect( $reset = null, $id = null ) {
	static $ids = null;
	if ( true === $reset ) {
		$ids = array();
	} elseif ( false === $reset ) {
		$out = (array) $ids;
		$ids = null;
		return $out;
	} elseif ( null !== $id && is_array( $ids ) ) {
		$ids[] = (int) $id;
	}
	return null;
}

/** Guarda un snapshot. $data puede ser cualquier valor serializable (null = "no existía"). */
function spiral_mcp_snapshot_take( $kind, $ref, $data, $note = '' ) {
	global $wpdb;
	$ok = $wpdb->insert(
		spiral_mcp_table( 'snapshots' ),
		array(
			'created_at' => current_time( 'mysql', true ),
			'user_id'    => get_current_user_id(),
			'kind'       => substr( (string) $kind, 0, 30 ),
			'ref'        => substr( (string) $ref, 0, 191 ),
			'data'       => serialize( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			'note'       => substr( (string) $note, 0, 255 ),
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s' )
	);
	if ( ! $ok ) {
		return new WP_Error( 'spiral_mcp_snapshot_failed', 'No se pudo guardar la copia de seguridad; no se ha hecho ningún cambio. ¿Existe la tabla de snapshots? Desactiva y activa el plugin.' );
	}
	$id = (int) $wpdb->insert_id;
	spiral_mcp_snapshot_collect( null, $id );
	return $id;
}

/** Captura el estado actual con el 'capture' del tipo y lo guarda. */
function spiral_mcp_snapshot_capture( $kind, $ref, $note = '' ) {
	$kinds = spiral_mcp_snapshot_kinds();
	if ( ! isset( $kinds[ $kind ]['capture'] ) ) {
		return new WP_Error( 'spiral_mcp_snapshot_kind', sprintf( 'Tipo de snapshot "%s" no registrado.', $kind ) );
	}
	return spiral_mcp_snapshot_take( $kind, $ref, call_user_func( $kinds[ $kind ]['capture'], $ref ), $note );
}

/** Lee un snapshot (con sus datos) o WP_Error. */
function spiral_mcp_snapshot_get( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . spiral_mcp_table( 'snapshots' ) . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		return new WP_Error( 'spiral_mcp_snapshot_not_found', 'No existe un snapshot con ese ID. Búscalo con spiral-mcp/list-snapshots.' );
	}
	$row->data = unserialize( $row->data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	return $row;
}

/** ¿El usuario actual puede restaurar snapshots de este tipo? */
function spiral_mcp_snapshot_can( $kind ) {
	$kinds = spiral_mcp_snapshot_kinds();
	if ( ! isset( $kinds[ $kind ] ) ) {
		return false;
	}
	return current_user_can( 'spiral_mcp_' . $kinds[ $kind ]['group'] ) && current_user_can( $kinds[ $kind ]['cap'] );
}

/**
 * Restaura un snapshot. Antes guarda otro con el estado actual, para poder
 * deshacer la restauración.
 */
function spiral_mcp_snapshot_restore( $id, array $opts = array() ) {
	global $wpdb;
	$snap = spiral_mcp_snapshot_get( $id );
	if ( is_wp_error( $snap ) ) {
		return $snap;
	}
	$kinds = spiral_mcp_snapshot_kinds();
	if ( ! isset( $kinds[ $snap->kind ]['restore'] ) ) {
		return new WP_Error( 'spiral_mcp_snapshot_kind', sprintf( 'Los snapshots de tipo "%s" no se pueden restaurar en esta versión.', $snap->kind ) );
	}
	$kind  = $kinds[ $snap->kind ];
	$parts = array_values( array_filter( array_map( 'strval', (array) ( $opts['parts'] ?? array() ) ), 'strlen' ) );
	$diff  = isset( $kind['parts'] ) ? array_values( (array) call_user_func( $kind['parts'], $snap->ref, $snap->data ) ) : null;

	// Un snapshot guarda el objeto entero: si después hubo otros cambios en el mismo objeto,
	// restaurarlo también los desharía. Se exige elegir partes o confirmarlo con include_later.
	$later = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, note FROM ' . spiral_mcp_table( 'snapshots' ) . ' WHERE kind = %s AND ref = %s AND id > %d ORDER BY id ASC LIMIT 11', $snap->kind, $snap->ref, (int) $snap->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $later && ! $parts && empty( $opts['include_later'] ) ) {
		$list = array();
		foreach ( array_slice( $later, 0, 10 ) as $row ) {
			$list[] = sprintf( '#%d %s (%s)', $row->id, spiral_mcp_utc_date( $row->created_at ), $row->note );
		}
		$hint = null !== $diff
			? sprintf( ' El snapshot difiere del estado actual en: %s. Para deshacer solo una parte, envía "parts" con esas claves (p. ej. ["%s"]), o revierte el valor con la herramienta que lo cambió.', $diff ? implode( ', ', $diff ) : '(nada)', $diff ? $diff[0] : 'less' )
			: ' Para deshacer solo un cambio concreto, revierte el valor con la herramienta que lo cambió.';
		return new WP_Error(
			'spiral_mcp_snapshot_later',
			sprintf( 'Después de este snapshot hubo %s cambio(s) en el mismo objeto (%s) que también se desharían: %s.%s Si de verdad quieres volver al estado completo, repite con "include_later": true.', count( $later ) > 10 ? 'más de 10' : count( $later ), $snap->kind, implode( '; ', $list ), $hint ),
			array( 'status' => 409, 'later' => array_map( 'intval', wp_list_pluck( $later, 'id' ) ), 'differs_in' => $diff )
		);
	}
	$data = $snap->data;
	if ( $parts ) {
		if ( ! isset( $kind['partial'] ) ) {
			return new WP_Error( 'spiral_mcp_snapshot_parts', sprintf( 'Los snapshots de tipo "%s" solo se restauran enteros: quita "parts".', $snap->kind ) );
		}
		$data = call_user_func( $kind['partial'], $snap->ref, $snap->data, $parts );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
	}

	$previous = spiral_mcp_snapshot_capture( $snap->kind, $snap->ref, sprintf( 'Antes de restaurar el snapshot #%d', $snap->id ) . ( $parts ? ' (' . implode( ', ', $parts ) . ')' : '' ) );
	if ( is_wp_error( $previous ) ) {
		return $previous;
	}
	$res = call_user_func( $kind['restore'], $snap->ref, $data );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array_merge(
		array(
			'restored'             => (int) $snap->id,
			'kind'                 => $snap->kind,
			'ref'                  => $snap->ref,
			'parts'                => $parts ? $parts : null,
			'differed_in'          => $diff,
			'snapshot_of_previous' => $previous,
		),
		is_array( $res ) ? $res : array()
	);
}

/** Snapshot en formato de listado (sin los datos). */
function spiral_mcp_snapshot_out( $row ) {
	return array(
		'id'         => (int) $row->id,
		'created_at' => spiral_mcp_utc_date( $row->created_at ),
		'user_id'    => (int) $row->user_id,
		'kind'       => $row->kind,
		'ref'        => $row->ref,
		'note'       => $row->note,
		'size'       => (int) $row->size,
	);
}

/** Lista snapshots (sin datos), más reciente primero. */
function spiral_mcp_snapshot_list( array $filters, $per_page = 20, $page = 1 ) {
	global $wpdb;
	$where  = array( '1=1' );
	$values = array();
	foreach ( array( 'kind', 'ref' ) as $col ) {
		if ( isset( $filters[ $col ] ) && '' !== $filters[ $col ] ) {
			$where[]  = "$col = %s";
			$values[] = (string) $filters[ $col ];
		}
	}
	// Solo los tipos que el usuario puede restaurar.
	$kinds = array_values( array_filter( array_keys( spiral_mcp_snapshot_kinds() ), 'spiral_mcp_snapshot_can' ) );
	if ( ! $kinds ) {
		return array( 'total' => 0, 'items' => array() );
	}
	$where[] = 'kind IN (' . implode( ',', array_fill( 0, count( $kinds ), '%s' ) ) . ')';
	$values  = array_merge( $values, $kinds );

	$table     = spiral_mcp_table( 'snapshots' );
	$sql_where = implode( ' AND ', $where );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql_where", $values ) );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, created_at, user_id, kind, ref, note, LENGTH(data) AS size FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d",
		array_merge( $values, array( (int) $per_page, ( max( 1, (int) $page ) - 1 ) * (int) $per_page ) )
	) );
	// phpcs:enable
	return array(
		'total' => $total,
		'items' => array_map( 'spiral_mcp_snapshot_out', (array) $rows ),
	);
}

/**
 * Pasa a la tabla las copias de la configuración de YOOtheme que la 1.3.0
 * guardaba en una opción, y borra la opción. El ID antiguo queda en la nota
 * como [legacy:ID] para el alias restore-theme-backup.
 */
function spiral_mcp_snapshot_migrate_legacy() {
	global $wpdb;
	foreach ( array( 'rasenfx_ca_yootheme_config_backups', 'spiral_mcp_yootheme_config_backups' ) as $option ) {
		$backups = get_option( $option, null );
		if ( null === $backups ) {
			continue;
		}
		foreach ( (array) $backups as $b ) {
			if ( ! is_array( $b ) || ! array_key_exists( 'raw', $b ) ) {
				continue;
			}
			$ts = strtotime( (string) ( $b['date'] ?? '' ) );
			$wpdb->insert(
				spiral_mcp_table( 'snapshots' ),
				array(
					'created_at' => gmdate( 'Y-m-d H:i:s', $ts ? $ts : time() ),
					'user_id'    => (int) ( $b['user'] ?? 0 ),
					'kind'       => 'theme_config',
					'ref'        => 'config',
					'data'       => serialize( $b['raw'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'note'       => substr( trim( ( $b['reason'] ?? '' ) . ' [legacy:' . ( $b['id'] ?? '' ) . ']' ), 0, 255 ),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
		delete_option( $option );
	}
}

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-snapshots', array(
		'label'       => 'Snapshots Spiral-MCP',
		'description' => 'Copias automáticas del estado anterior a cada cambio (tema, menús, widgets, medios) y su restauración.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	/* 1. Listar snapshots ------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/list-snapshots', array(
		'label'               => 'Listar snapshots',
		'description'         => 'Lista las copias automáticas que se guardan antes de cada cambio en la configuración del tema (kind theme_config), un menú (kind menu, ref = ID del menú), un widget (kind widgets, ref = ID del widget), los textos de un adjunto (kind media_meta, ref = ID del adjunto) o un meta de un post como la imagen destacada (kind post_meta, ref = "ID:clave"). Más reciente primero; no incluye los datos. Para deshacer un cambio pasa el "id" a spiral-mcp/restore-snapshot; spiral-mcp/list-activity indica qué snapshot generó cada cambio.',
		'category'            => 'spiral-mcp-snapshots',
		'group'               => spiral_mcp_groups(),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'kind'     => array( 'type' => 'string', 'description' => 'theme_config, menu, widgets, media_meta o post_meta.' ),
				'ref'      => array( 'type' => 'string', 'description' => 'Objeto concreto: "config", ID de menú, ID de widget (p. ej. nav_menu-4), ID de adjunto o "ID:clave" (p. ej. 12:_thumbnail_id).' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			return spiral_mcp_snapshot_list( $input, $input['per_page'] ?? 20, $input['page'] ?? 1 );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Restaurar snapshot ----------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/restore-snapshot', array(
		'label'               => 'Restaurar snapshot',
		'description'         => 'Devuelve un objeto (toda la configuración del tema, un menú completo o un widget y las áreas de widgets) al estado guardado en un snapshot. Un snapshot guarda el objeto entero: si después hubo otros cambios en el mismo objeto, devuelve un error que los lista (y, en theme_config, en qué secciones difiere) en lugar de deshacerlos sin avisar; entonces restaura solo una parte con "parts" (solo theme_config, p. ej. ["less"] para el estilo o ["footer"]), revierte el valor con la herramienta que lo cambió, o envía "include_later": true si de verdad quieres el estado completo. Antes guarda otro snapshot con el estado actual ("snapshot_of_previous"), así que se puede deshacer. En menús, los elementos que hubo que recrear reciben IDs nuevos; si el snapshot es de un menú recién creado, restaurarlo borra el menú. Requiere "confirm": true.',
		'category'            => 'spiral-mcp-snapshots',
		'group'               => spiral_mcp_groups(),
		'log'                 => array( 'type' => 'snapshot', 'id' => 'id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'            => array( 'type' => 'integer', 'description' => 'ID del snapshot (ver spiral-mcp/list-snapshots).' ),
				'confirm'       => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
				'parts'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Solo theme_config: secciones de primer nivel que se restauran (p. ej. ["less"], ["footer"]); el resto queda como está.' ),
				'include_later' => array( 'type' => 'boolean', 'description' => 'true = restaurar el objeto entero aunque haya cambios posteriores (se deshacen también).' ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			$snap = spiral_mcp_snapshot_get( ( (array) $input )['id'] ?? 0 );
			// Si no existe, se deja pasar para que execute devuelva un error útil.
			return is_wp_error( $snap ) ? current_user_can( 'edit_theme_options' ) : spiral_mcp_snapshot_can( $snap->kind );
		},
		'execute_callback'    => function ( $input ) {
			$ok = spiral_mcp_require_confirm( $input, 'restaurar el snapshot' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			return spiral_mcp_snapshot_restore( $input['id'] ?? 0, array(
				'parts'         => $input['parts'] ?? array(),
				'include_later' => ! empty( $input['include_later'] ),
			) );
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );
} );
