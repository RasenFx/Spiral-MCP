<?php
/**
 * Ajustes de YOOtheme (theme mod "config"): leer por secciones y cambiar
 * secciones de una lista blanca, con snapshot.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Secciones editables. Ajustable con el filtro spiral_mcp_theme_config_sections. */
function spiral_mcp_theme_config_sections() {
	$sections = array( 'site', 'header', 'navbar', 'dialog', 'mobile', 'top', 'bottom', 'footer', 'logo', 'favicon', 'touchicon', 'post', 'blog', 'consent', 'lazyload', 'webp' );
	$sections = (array) apply_filters( 'spiral_mcp_theme_config_sections', $sections );
	// Nunca editables, aunque un filtro las añada.
	return array_values( array_diff( $sections, array( 'yootheme_apikey', 'version', 'minimum_stability', 'site_url' ) ) );
}

/** Secciones que exigen la capacidad spiral_mcp_code (JS personalizado). */
function spiral_mcp_theme_config_code_sections() {
	return array( 'scripts' );
}

/** Oculta valores secretos (claves api, tokens, contraseñas) a cualquier profundidad. */
function spiral_mcp_mask_secrets( $data, $key = '' ) {
	if ( is_string( $key ) && preg_match( '/apikey|api_key|token|secret|password/i', $key ) ) {
		return is_string( $data ) && '' !== $data ? substr( $data, 0, 4 ) . '…(oculto)' : $data;
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) {
			$data[ $k ] = spiral_mcp_mask_secrets( $v, $k );
		}
	}
	return $data;
}

/** Valores por defecto de YOOtheme para la configuración (theme.defaults). */
function spiral_mcp_theme_config_defaults() {
	if ( function_exists( 'YOOtheme\app' ) && interface_exists( 'YOOtheme\Config' ) ) {
		try {
			return (array) \YOOtheme\app( \YOOtheme\Config::class )->get( 'theme.defaults', array() );
		} catch ( Throwable $e ) {
			return array();
		}
	}
	return array();
}

/** Merge profundo: los objetos se mezclan; las listas y escalares se reemplazan. */
function spiral_mcp_merge_deep( $base, $values ) {
	if ( ! is_array( $base ) || ! is_array( $values ) || spiral_mcp_is_list( $values ) || ( $base && spiral_mcp_is_list( $base ) ) ) {
		return $values;
	}
	foreach ( $values as $k => $v ) {
		$base[ $k ] = array_key_exists( $k, $base ) ? spiral_mcp_merge_deep( $base[ $k ], $v ) : $v;
	}
	return $base;
}

/** Avisos para claves que no existen en los valores por defecto de YOOtheme. */
function spiral_mcp_theme_config_unknown( $values, $defaults, $prefix ) {
	$out = array();
	if ( ! is_array( $values ) || ! is_array( $defaults ) || spiral_mcp_is_list( $values ) || ! $defaults ) {
		return $out;
	}
	foreach ( $values as $k => $v ) {
		if ( ! array_key_exists( $k, $defaults ) ) {
			$out[] = sprintf( '"%s.%s" no es un ajuste conocido de YOOtheme (puede que se ignore). Revisa "defaults" en spiral-mcp/get-theme-config.', $prefix, $k );
		} else {
			$out = array_merge( $out, spiral_mcp_theme_config_unknown( $v, $defaults[ $k ], $prefix . '.' . $k ) );
		}
	}
	return $out;
}

/** Resumen de una sección para el listado. */
function spiral_mcp_theme_config_summary( $key, $value ) {
	$editable = in_array( $key, spiral_mcp_theme_config_sections(), true ) || in_array( $key, spiral_mcp_theme_config_code_sections(), true );
	$row      = array(
		'section'  => $key,
		'editable' => $editable,
		'kind'     => is_array( $value ) ? ( spiral_mcp_is_list( $value ) && $value ? 'list' : 'object' ) : gettype( $value ),
	);
	if ( is_array( $value ) ) {
		$row['keys'] = count( $value );
	} elseif ( ! preg_match( '/apikey/i', $key ) ) {
		$row['value'] = $value;
	}
	if ( in_array( $key, spiral_mcp_theme_config_code_sections(), true ) ) {
		$row['requires'] = 'spiral_mcp_code';
	}
	return $row;
}

/* -------------------------------------------------------------------------
 * Abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_init', function () {

	spiral_mcp_register_ability( 'spiral-mcp/get-theme-config', array(
		'label'               => 'Leer la configuración de YOOtheme',
		'description'         => 'Lee los ajustes de YOOtheme (Personalizar). Sin "section" devuelve la lista de secciones (site, header, navbar, dialog, mobile, top, bottom, footer, logo, post, blog, consent, scripts…) con su tipo y si se pueden editar. Con "section" devuelve su valor actual y "defaults" (los ajustes que admite y sus valores por defecto). Las claves api y otros secretos salen ocultos. Las claves con punto (p. ej. "search_dropdown.stretch") son nombres literales, no rutas. Los estilos (less, custom_less, style) se tratan en otra fase: cambiarlos no recompila el CSS.',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'section' => array( 'type' => 'string', 'description' => 'Clave de primer nivel, p. ej. header.' ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'edit_theme_options' );
		},
		'execute_callback'    => function ( $input = array() ) {
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$config  = $read['config'];
			$section = (string) ( ( (array) $input )['section'] ?? '' );
			if ( '' === $section ) {
				$out = array();
				foreach ( $config as $key => $value ) {
					$out[] = spiral_mcp_theme_config_summary( $key, $value );
				}
				return array( 'sections' => $out );
			}
			if ( ! array_key_exists( $section, $config ) ) {
				return new WP_Error( 'spiral_mcp_bad_section', sprintf( 'La sección "%s" no existe. Secciones: %s.', $section, implode( ', ', array_keys( $config ) ) ) );
			}
			$defaults = spiral_mcp_theme_config_defaults();
			return spiral_mcp_theme_config_summary( $section, $config[ $section ] ) + array(
				'value'    => spiral_mcp_mask_secrets( $config[ $section ], $section ),
				'defaults' => $defaults[ $section ] ?? null,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-theme-config', array(
		'label'               => 'Cambiar la configuración de YOOtheme',
		'description'         => 'Cambia una sección de los ajustes de YOOtheme. "values" se mezcla con lo que hay (merge profundo: solo cambian las claves enviadas; las listas se reemplazan enteras); con "replace": true sustituye la sección completa. Primero lee la sección con spiral-mcp/get-theme-config. Secciones editables: ' . implode( ', ', spiral_mcp_theme_config_sections() ) . '; "scripts" (JS personalizado) requiere además el permiso de código. No se pueden tocar yootheme_apikey, version, minimum_stability ni site_url. Las rutas de imagen (logo, favicon…) van relativas, p. ej. wp-content/uploads/2024/11/logo.webp (usa path_relative de spiral-mcp/upload-media). Avisa en "warnings" de claves que YOOtheme no conoce. Antes guarda un snapshot de toda la configuración ("snapshot_id"; se deshace con spiral-mcp/restore-snapshot).',
		'category'            => 'spiral-mcp-theme',
		'group'               => 'design',
		'log'                 => array( 'type' => 'theme_config', 'id' => 'section' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'section', 'values' ),
			'properties' => array(
				'section' => array( 'type' => 'string' ),
				'values'  => array(
					'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ),
					'description' => 'Objeto con las claves a cambiar (o el valor, en secciones que no son objetos, como webp).',
				),
				'replace' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => function ( $input = array() ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return false;
			}
			$section = (string) ( ( (array) $input )['section'] ?? '' );
			return ! in_array( $section, spiral_mcp_theme_config_code_sections(), true ) || current_user_can( 'spiral_mcp_code' );
		},
		'execute_callback'    => function ( $input ) {
			$section = (string) ( $input['section'] ?? '' );
			if ( ! in_array( $section, spiral_mcp_theme_config_sections(), true ) && ! in_array( $section, spiral_mcp_theme_config_code_sections(), true ) ) {
				return new WP_Error( 'spiral_mcp_section_locked', sprintf( 'La sección "%s" no se puede editar con esta ability. Editables: %s.', $section, implode( ', ', array_merge( spiral_mcp_theme_config_sections(), spiral_mcp_theme_config_code_sections() ) ) ) );
			}
			if ( 'footer' === $section && is_array( $input['values'] ) && array_key_exists( 'content', $input['values'] ) ) {
				return new WP_Error( 'spiral_mcp_section_locked', 'El layout del footer ("content") se cambia con spiral-mcp/update-theme-layout o spiral-mcp/patch-layout, que lo validan.' );
			}
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$config  = $read['config'];
			$values  = $input['values'];
			$current = $config[ $section ] ?? null;
			if ( is_array( $current ) && ! spiral_mcp_is_list( $current ) && ! is_array( $values ) && empty( $input['replace'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_values', sprintf( 'La sección "%s" es un objeto: envía "values" como objeto con las claves a cambiar.', $section ) );
			}
			$defaults = spiral_mcp_theme_config_defaults();
			$warnings = spiral_mcp_theme_config_unknown( $values, $defaults[ $section ] ?? null, $section );

			$snapshot = spiral_mcp_snapshot_take( 'theme_config', 'config', $read['raw'], 'update-theme-config:' . $section );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$config[ $section ] = empty( $input['replace'] ) ? spiral_mcp_merge_deep( $current, $values ) : $values;
			$res                = spiral_mcp_theme_config_write( $config, $read['raw'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return array(
				'section'     => $section,
				'value'       => spiral_mcp_mask_secrets( $config[ $section ], $section ),
				'snapshot_id' => $snapshot,
				'warnings'    => $warnings,
			);
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
