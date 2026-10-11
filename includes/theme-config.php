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

/**
 * Claves conocidas de las secciones que no tienen valores por defecto en YOOtheme
 * (sacadas de los campos del Personalizador), con su descripción para el agente.
 */
function spiral_mcp_theme_config_known_keys() {
	return array(
		'logo' => array(
			'text'                 => 'Texto del logo. Se muestra si no hay imagen y es el alt de las imágenes del logo: rellénalo siempre.',
			'image'                => 'Imagen del logo (ruta relativa, p. ej. wp-content/uploads/2026/10/logo.png).',
			'image_width'          => 'Ancho en px; YOOtheme genera una copia redimensionada. Indica ancho y alto (o uno solo para mantener la proporción) si la imagen original es grande.',
			'image_height'         => 'Alto en px.',
			'image_svg_inline'     => 'true = incrusta el SVG en el HTML (solo para logos SVG).',
			'image_inverse'        => 'Variante para fondos oscuros o cabeceras transparentes.',
			'image_mobile'         => 'Logo para la cabecera móvil (si falta, se usa "image").',
			'image_mobile_width'   => 'Ancho en px del logo móvil.',
			'image_mobile_height'  => 'Alto en px del logo móvil.',
			'image_mobile_inverse' => 'Variante del logo móvil para fondos oscuros.',
			'image_dialog'         => 'Logo dentro del diálogo o menú desplegable.',
			'image_dialog_width'   => 'Ancho en px del logo del diálogo.',
			'image_dialog_height'  => 'Alto en px del logo del diálogo.',
		),
	);
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
			$out[] = sprintf( '"%s.%s" no es un ajuste conocido de YOOtheme (puede que se ignore). Revisa "defaults" (o "known_keys") en spiral-mcp/get-theme-config.', $prefix, $k );
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
		'description'         => 'Lee los ajustes de YOOtheme (Personalizar). Sin "section" devuelve la lista de secciones (site, header, navbar, dialog, mobile, top, bottom, footer, logo, post, blog, consent, scripts…) con su tipo y si se pueden editar. Con "section" devuelve su valor actual y "defaults" (los ajustes que admite y sus valores por defecto); en las secciones sin valores por defecto (p. ej. logo) devuelve "known_keys" con las claves y qué hace cada una. Las claves api y otros secretos salen ocultos. Las claves con punto (p. ej. "search_dropdown.stretch") son nombres literales, no rutas. Los estilos (less, custom_less, style) se tratan en otra fase: cambiarlos no recompila el CSS.',
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
			$known    = spiral_mcp_theme_config_known_keys();
			return spiral_mcp_theme_config_summary( $section, $config[ $section ] ) + array(
				'value'    => spiral_mcp_mask_secrets( $config[ $section ], $section ),
				'defaults' => $defaults[ $section ] ?? null,
			) + ( ! isset( $defaults[ $section ] ) && isset( $known[ $section ] ) ? array( 'known_keys' => $known[ $section ] ) : array() );
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-theme-config', array(
		'label'               => 'Cambiar la configuración de YOOtheme',
		'description'         => 'Cambia una sección de los ajustes de YOOtheme. "values" se mezcla con lo que hay (merge profundo: solo cambian las claves enviadas; las listas se reemplazan enteras); con "replace": true sustituye la sección completa. Primero lee la sección con spiral-mcp/get-theme-config. Secciones editables: ' . implode( ', ', spiral_mcp_theme_config_sections() ) . '; "scripts" (JS personalizado) requiere además el permiso de código. No se pueden tocar yootheme_apikey, version, minimum_stability ni site_url. Las rutas de imagen (logo, favicon…) van relativas, p. ej. wp-content/uploads/2024/11/logo.webp (usa path_relative de spiral-mcp/upload-media). En "logo", el alt de la imagen es "text" (no el alt del medio) y conviene dar "image_width"/"image_height" si la imagen es grande. Avisa en "warnings" de claves que YOOtheme no conoce. Antes guarda un snapshot de toda la configuración ("snapshot_id"; se deshace con spiral-mcp/restore-snapshot).',
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
			$known    = spiral_mcp_theme_config_known_keys();
			if ( ! isset( $defaults[ $section ] ) && isset( $known[ $section ] ) ) {
				$defaults[ $section ] = array_fill_keys( array_keys( $known[ $section ] ), null );
			}
			$warnings = spiral_mcp_theme_config_unknown( $values, $defaults[ $section ] ?? null, $section );
			if ( 'logo' === $section && is_array( $values ) && ! empty( $values['image'] ) && '' === (string) ( $values['text'] ?? ( is_array( $current ) ? ( $current['text'] ?? '' ) : '' ) ) ) {
				$warnings[] = 'El logo no tiene "text": YOOtheme lo usa como alt de la imagen. Ponle el nombre del sitio o de la marca.';
			}

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
