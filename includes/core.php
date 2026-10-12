<?php
/**
 * Helpers compartidos: wrapper de registro de abilities, metadatos,
 * serialización JSON y confirmación de acciones destructivas.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/**
 * Metadatos de una ability: visible en REST, pública para MCP y con sus
 * anotaciones de comportamiento.
 */
function spiral_mcp_meta( $readonly, $destructive = false, $idempotent = false ) {
	return array(
		'show_in_rest' => true,
		'annotations'  => array(
			'readonly'    => (bool) $readonly,
			'destructive' => (bool) $destructive,
			'idempotent'  => (bool) $idempotent,
		),
		'mcp'          => array( 'public' => true ),
	);
}

/** wp_json_encode con los flags del proyecto. */
function spiral_mcp_json( $data ) {
	return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/** Devuelve true si la entrada trae "confirm": true; si no, un WP_Error. */
function spiral_mcp_require_confirm( $input, $action = 'continuar' ) {
	if ( true !== ( ( (array) $input )['confirm'] ?? false ) ) {
		return new WP_Error( 'spiral_mcp_confirm', sprintf( 'Para %s envía "confirm": true.', $action ) );
	}
	return true;
}

/** Fecha MySQL guardada en UTC a ISO 8601 con "Z". */
function spiral_mcp_utc_date( $mysql ) {
	return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $mysql . ' UTC' ) );
}

/** Nombre completo de una tabla del plugin (con el prefijo de WordPress). */
function spiral_mcp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'spiral_mcp_' . $name;
}

/**
 * Registra una ability con las convenciones del plugin.
 *
 * Además de los argumentos de wp_register_ability() acepta:
 * - 'group': grupo de permisos (content, design, media, settings, code) o un
 *   array de grupos (basta con tener uno). Se exige la capacidad
 *   spiral_mcp_<grupo> antes del permission_callback propio.
 * - 'log': array( 'type' => tipo de objeto, 'id' => clave de la entrada o
 *   callable( $input, $result ) ) para el registro de auditoría.
 *
 * Fuerza el prefijo spiral-mcp/, rellena 'meta' con spiral_mcp_meta( false )
 * y, si la ability escribe (readonly = false), registra cada ejecución.
 */
/**
 * Ajustes de comportamiento (Ajustes > Spiral-MCP > Comportamiento), opción spiral_mcp_settings.
 * Los filtros de siempre (spiral_mcp_auto_clear_cache, spiral_mcp_log_retention_days…) reciben
 * este valor y siguen teniendo la última palabra.
 */
function spiral_mcp_settings_defaults() {
	return array(
		'auto_purge'               => true,  // Purgar la caché de lo que cambia tras cada escritura.
		'log_retention_days'       => 90,    // 0 = no borrar nunca.
		'snapshot_retention_days'  => 90,    // 0 = no borrar nunca.
		'online_download'          => true,  // Descargar diseños de la librería online con la clave de API.
		'drop_tables_on_uninstall' => false, // Borrar auditoría y snapshots al desinstalar.
		'plugins_management'       => false, // Segundo cerrojo de la gestión de plugins (el primero es spiral_mcp_plugins).
	);
}

/** Valor de un ajuste de comportamiento (con su valor por defecto). */
function spiral_mcp_setting( $key ) {
	$defaults = spiral_mcp_settings_defaults();
	$saved    = (array) get_option( 'spiral_mcp_settings', array() );
	$value    = array_key_exists( $key, $saved ) ? $saved[ $key ] : ( $defaults[ $key ] ?? null );
	return is_bool( $defaults[ $key ] ?? null ) ? (bool) $value : ( is_int( $defaults[ $key ] ?? null ) ? (int) $value : $value );
}

/** Abilities que no se pueden desactivar: sin ellas el agente no podría ver ni deshacer cambios. */
function spiral_mcp_protected_abilities() {
	return array( 'spiral-mcp/list-activity', 'spiral-mcp/list-snapshots', 'spiral-mcp/restore-snapshot' );
}

/** Abilities desactivadas en la página de ajustes (opción spiral_mcp_disabled_abilities). */
function spiral_mcp_disabled_abilities() {
	return array_values( array_diff( array_map( 'strval', (array) get_option( 'spiral_mcp_disabled_abilities', array() ) ), spiral_mcp_protected_abilities() ) );
}

/**
 * Catálogo de las abilities del plugin, registradas o no: nombre => [ label, description,
 * category, groups, kind (read|write|destructive), enabled ]. Con $name y $info, lo añade.
 */
function spiral_mcp_ability_catalog( $name = null, $info = null ) {
	static $catalog = array();
	if ( null !== $name && is_array( $info ) ) {
		$catalog[ $name ] = $info;
	}
	return $catalog;
}

function spiral_mcp_register_ability( $name, array $args ) {
	if ( 0 !== strpos( $name, 'spiral-mcp/' ) ) {
		$name = 'spiral-mcp/' . $name;
	}
	$groups = (array) ( $args['group'] ?? 'content' );
	$log    = (array) ( $args['log'] ?? array() );
	unset( $args['group'], $args['log'] );

	$args['meta'] = array_replace_recursive( spiral_mcp_meta( false ), (array) ( $args['meta'] ?? array() ) );

	// Registro de todas las abilities (también las desactivadas) para la página de ajustes.
	$annotations = (array) ( $args['meta']['annotations'] ?? array() );
	$enabled     = ! in_array( $name, spiral_mcp_disabled_abilities(), true );
	spiral_mcp_ability_catalog( $name, array(
		'label'       => (string) ( $args['label'] ?? $name ),
		'description' => (string) ( $args['description'] ?? '' ),
		'category'    => (string) ( $args['category'] ?? '' ),
		'groups'      => $groups,
		'kind'        => ! empty( $annotations['readonly'] ) ? 'read' : ( ! empty( $annotations['destructive'] ) ? 'destructive' : 'write' ),
		'enabled'     => $enabled,
	) );
	// Desactivada en Ajustes > Spiral-MCP: no se registra, así el MCP Adapter no la publica.
	if ( ! $enabled ) {
		return null;
	}

	// Sin esto, llamar sin "input" a una ability sin campos obligatorios falla la validación (null no es un objeto).
	$schema = $args['input_schema'] ?? array();
	if ( 'object' === ( $schema['type'] ?? '' ) && empty( $schema['required'] ) && ! array_key_exists( 'default', $schema ) ) {
		$args['input_schema']['default'] = array();
	}

	$permission                  = $args['permission_callback'] ?? null;
	$args['permission_callback'] = function ( $input = null ) use ( $groups, $permission ) {
		if ( ! spiral_mcp_user_can_any( $groups ) || ! is_callable( $permission ) ) {
			return false;
		}
		return call_user_func( $permission, $input );
	};

	if ( empty( $args['meta']['annotations']['readonly'] ) && isset( $args['execute_callback'] ) ) {
		$execute                  = $args['execute_callback'];
		$args['execute_callback'] = function ( $input = null ) use ( $name, $execute, $log, $groups ) {
			$result = spiral_mcp_log_run( $name, $execute, $input, $log );
			if ( ! is_wp_error( $result ) ) {
				spiral_mcp_maybe_auto_clear_cache( $name, $groups, $input, $result, $log );
			}
			return $result;
		};
	}

	return wp_register_ability( $name, $args );
}
