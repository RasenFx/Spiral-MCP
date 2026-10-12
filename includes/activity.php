<?php
/**
 * Registro de auditoría: cada ejecución de una ability que escribe queda en
 * la tabla {prefix}spiral_mcp_log. Incluye la ability list-activity y la
 * limpieza diaria por antigüedad.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** Tamaño máximo de input_json, en bytes. */
const SPIRAL_MCP_LOG_INPUT_MAX = 8192;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Claves cuyo valor no se guarda nunca en el registro. */
function spiral_mcp_log_redact_keys() {
	return (array) apply_filters( 'spiral_mcp_log_redact_keys', array( 'password', 'token', 'apikey', 'yootheme_apikey', 'key' ) );
}

/** Sustituye por "***" los valores de las claves sensibles, a cualquier profundidad. */
function spiral_mcp_log_redact( $data, $keys = null ) {
	$keys = null === $keys ? array_map( 'strtolower', spiral_mcp_log_redact_keys() ) : $keys;
	if ( is_object( $data ) ) {
		$data = get_object_vars( $data );
	}
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( $data as $k => $v ) {
		if ( is_string( $k ) && in_array( strtolower( $k ), $keys, true ) ) {
			$data[ $k ] = '***';
		} elseif ( is_array( $v ) || is_object( $v ) ) {
			$data[ $k ] = spiral_mcp_log_redact( $v, $keys );
		}
	}
	return $data;
}

/** Entrada redactada y truncada a SPIRAL_MCP_LOG_INPUT_MAX bytes. */
function spiral_mcp_log_input_json( $input ) {
	$json = spiral_mcp_json( spiral_mcp_log_redact( $input ) );
	if ( false === $json ) {
		return '';
	}
	if ( strlen( $json ) > SPIRAL_MCP_LOG_INPUT_MAX ) {
		$cut  = function_exists( 'mb_strcut' ) ? mb_strcut( $json, 0, SPIRAL_MCP_LOG_INPUT_MAX, 'UTF-8' ) : substr( $json, 0, SPIRAL_MCP_LOG_INPUT_MAX );
		$json = $cut . '…[truncado]';
	}
	return $json;
}

/** Resuelve [ object_type, object_id ] a partir de la configuración 'log' de la ability. */
function spiral_mcp_log_object( array $log, $input, $result ) {
	$input  = (array) $input;
	$result = is_array( $result ) ? $result : array();
	$id     = $log['id'] ?? 'id';
	if ( is_callable( $id ) ) {
		$id = call_user_func( $id, $input, $result );
	} else {
		$id = $input[ $id ] ?? ( $result[ $id ] ?? '' );
	}
	return array( (string) ( $log['type'] ?? '' ), is_scalar( $id ) ? (string) $id : '' );
}

/** Inserta una fila en el registro. Un fallo aquí nunca rompe la ability. */
function spiral_mcp_log_write( array $row ) {
	global $wpdb;
	$wpdb->insert(
		spiral_mcp_table( 'log' ),
		array(
			'created_at'  => current_time( 'mysql', true ),
			'user_id'     => get_current_user_id(),
			'ability'     => substr( (string) $row['ability'], 0, 100 ),
			'object_type' => substr( (string) $row['object_type'], 0, 40 ),
			'object_id'   => substr( (string) $row['object_id'], 0, 100 ),
			'input_json'  => (string) $row['input_json'],
			'result'      => substr( (string) $row['result'], 0, 100 ),
			'snapshot_id' => $row['snapshot_id'] ? (int) $row['snapshot_id'] : null,
			'duration_ms' => (int) $row['duration_ms'],
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
	);
}

/**
 * Ejecuta el callback de una ability que escribe y lo registra. Las
 * excepciones se convierten en WP_Error (y también quedan registradas).
 */
function spiral_mcp_log_run( $name, $execute, $input, array $log ) {
	$start = microtime( true );
	spiral_mcp_snapshot_collect( true );
	try {
		$result = call_user_func( $execute, $input );
	} catch ( Throwable $e ) {
		$result = new WP_Error( 'spiral_mcp_exception', 'Error interno al ejecutar la ability: ' . $e->getMessage() );
	}
	$snapshots = spiral_mcp_snapshot_collect( false );

	list( $type, $id ) = spiral_mcp_log_object( $log, $input, $result );
	spiral_mcp_log_write( array(
		'ability'     => $name,
		'object_type' => $type,
		'object_id'   => $id,
		'input_json'  => spiral_mcp_log_input_json( $input ),
		'result'      => is_wp_error( $result ) ? $result->get_error_code() : 'ok',
		'snapshot_id' => $snapshots ? $snapshots[0] : null,
		'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
	) );
	return $result;
}

/** Fila del registro en formato de salida. */
function spiral_mcp_log_out( $row ) {
	$input = json_decode( (string) $row->input_json, true );
	return array(
		'id'          => (int) $row->id,
		'created_at'  => spiral_mcp_utc_date( $row->created_at ),
		'user_id'     => (int) $row->user_id,
		'ability'     => $row->ability,
		'object_type' => $row->object_type,
		'object_id'   => $row->object_id,
		'input'       => null === $input ? $row->input_json : $input,
		'result'      => $row->result,
		'snapshot_id' => $row->snapshot_id ? (int) $row->snapshot_id : null,
		'duration_ms' => (int) $row->duration_ms,
	);
}

/** Convierte una fecha (ISO 8601 u otra que entienda strtotime) a MySQL UTC, o null. */
function spiral_mcp_log_date( $value ) {
	$ts = strtotime( (string) $value );
	return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
}

/* -------------------------------------------------------------------------
 * Limpieza diaria
 * ---------------------------------------------------------------------- */

add_action( 'spiral_mcp_daily_cleanup', function () {
	global $wpdb;
	$days = (int) apply_filters( 'spiral_mcp_log_retention_days', spiral_mcp_setting( 'log_retention_days' ) );
	if ( $days > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . spiral_mcp_table( 'log' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$days = (int) apply_filters( 'spiral_mcp_snapshot_retention_days', spiral_mcp_setting( 'snapshot_retention_days' ) );
	if ( $days > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . spiral_mcp_table( 'snapshots' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
} );

/* -------------------------------------------------------------------------
 * Categoría
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-activity', array(
		'label'       => 'Actividad Spiral-MCP',
		'description' => 'Registro de auditoría de los cambios hechos por las abilities.',
	) );
} );

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	spiral_mcp_register_ability( 'spiral-mcp/list-activity', array(
		'label'               => 'Listar actividad',
		'description'         => 'Lista el registro de auditoría: cada ejecución de una ability que escribe (fecha UTC, usuario, ability, objeto afectado, entrada con claves sensibles ocultas, resultado "ok" o código de error, snapshot_id del estado anterior y duración). Más reciente primero. Para deshacer un cambio, pasa su snapshot_id a spiral-mcp/restore-snapshot.',
		'category'            => 'spiral-mcp-activity',
		'group'               => spiral_mcp_groups(),
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'ability'     => array( 'type' => 'string', 'description' => 'Nombre exacto, p. ej. spiral-mcp/update-menu.' ),
				'object_type' => array( 'type' => 'string', 'description' => 'post, menu, menu_item, theme_area, widget o snapshot.' ),
				'object_id'   => array( 'type' => 'string' ),
				'user_id'     => array( 'type' => 'integer' ),
				'result'      => array( 'type' => 'string', 'description' => '"ok", "error" (cualquier error) o un código de error concreto.' ),
				'since'       => array( 'type' => 'string', 'description' => 'Fecha ISO 8601 (UTC si no lleva zona), incluida.' ),
				'until'       => array( 'type' => 'string', 'description' => 'Fecha ISO 8601 (UTC si no lleva zona), incluida.' ),
				'per_page'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'        => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback'    => function ( $input = array() ) {
			global $wpdb;
			$input  = (array) $input;
			$where  = array( '1=1' );
			$values = array();
			foreach ( array( 'ability', 'object_type', 'object_id' ) as $col ) {
				if ( isset( $input[ $col ] ) && '' !== $input[ $col ] ) {
					$where[]  = "$col = %s";
					$values[] = (string) $input[ $col ];
				}
			}
			if ( ! empty( $input['user_id'] ) ) {
				$where[]  = 'user_id = %d';
				$values[] = (int) $input['user_id'];
			}
			if ( isset( $input['result'] ) && '' !== $input['result'] ) {
				if ( 'error' === $input['result'] ) {
					$where[] = "result <> 'ok'";
				} else {
					$where[]  = 'result = %s';
					$values[] = (string) $input['result'];
				}
			}
			foreach ( array( 'since' => '>=', 'until' => '<=' ) as $key => $op ) {
				if ( ! empty( $input[ $key ] ) ) {
					$date = spiral_mcp_log_date( $input[ $key ] );
					if ( null === $date ) {
						return new WP_Error( 'spiral_mcp_bad_date', sprintf( '"%s" no es una fecha válida. Usa ISO 8601, p. ej. 2026-10-01T00:00:00Z.', $key ) );
					}
					$where[]  = "created_at $op %s";
					$values[] = $date;
				}
			}
			$per_page = (int) ( $input['per_page'] ?? 20 );
			$offset   = ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page;
			$table    = spiral_mcp_table( 'log' );
			$sql_where = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
			$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, $values ) : $count_sql );
			$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql_where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, $offset ) ) ) );
			// phpcs:enable

			return array(
				'total' => $total,
				'items' => array_map( 'spiral_mcp_log_out', (array) $rows ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );
} );
