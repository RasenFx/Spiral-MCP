<?php
/**
 * Estilos: CSS adicional de WordPress (se aplica al instante) y estilo de
 * YOOtheme (estilo base, variables LESS y LESS personalizado).
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * CSS adicional
 * ---------------------------------------------------------------------- */

/**
 * Valida CSS como el Personalizador: sin marcado HTML (evita cerrar la
 * etiqueta <style> e inyectar HTML o JS) y con llaves y comentarios
 * equilibrados.
 */
function spiral_mcp_css_validate( $css ) {
	if ( preg_match( '#</?\w+#', $css ) ) {
		return new WP_Error( 'spiral_mcp_bad_css', 'El CSS no puede contener etiquetas HTML (p. ej. </style>).' );
	}
	$plain = preg_replace( '#/\*.*?\*/#s', '', $css );
	if ( false !== strpos( $plain, '/*' ) ) {
		return new WP_Error( 'spiral_mcp_bad_css', 'Hay un comentario /* sin cerrar.' );
	}
	$plain = preg_replace( '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $plain );
	if ( substr_count( $plain, '{' ) !== substr_count( $plain, '}' ) ) {
		return new WP_Error( 'spiral_mcp_bad_css', sprintf( 'Las llaves no están equilibradas (%d "{" y %d "}").', substr_count( $plain, '{' ), substr_count( $plain, '}' ) ) );
	}
	return true;
}

/** CSS adicional del tema activo en formato de salida. */
function spiral_mcp_custom_css_out() {
	$post = wp_get_custom_css_post();
	return array(
		'stylesheet' => get_stylesheet(),
		'css'        => wp_get_custom_css(),
		'post_id'    => $post ? (int) $post->ID : null,
		'revisions'  => $post ? count( wp_get_post_revisions( $post->ID ) ) : 0,
	);
}

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['custom_css'] = array(
		'group'   => 'design',
		'cap'     => 'edit_theme_options',
		'capture' => function ( $stylesheet ) {
			return wp_get_custom_css( $stylesheet );
		},
		'restore' => function ( $stylesheet, $data ) {
			$res = wp_update_custom_css_post( (string) $data, array( 'stylesheet' => $stylesheet ) );
			return is_wp_error( $res ) ? $res : true;
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría y abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'spiral-mcp-styles', array(
		'label'       => 'Estilos Spiral-MCP',
		'description' => 'CSS adicional y estilo de YOOtheme (estilo base, variables LESS y LESS personalizado).',
	) );
} );

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};

	spiral_mcp_register_ability( 'spiral-mcp/get-custom-css', array(
		'label'               => 'Leer el CSS adicional',
		'description'         => 'Devuelve el "CSS adicional" de WordPress del tema activo (Personalizar > CSS adicional). Se carga después del CSS del tema, así que sirve para retoques que se ven al instante, sin recompilar el estilo de YOOtheme.',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			return spiral_mcp_custom_css_out();
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-custom-css', array(
		'label'               => 'Cambiar el CSS adicional',
		'description'         => 'Cambia el "CSS adicional" de WordPress: "css" reemplaza todo (lee antes con spiral-mcp/get-custom-css) o, con "mode": "append", se añade al final. Se aplica al instante en todo el sitio. Para cambios de marca (colores, tipografía) es mejor spiral-mcp/update-theme-style; esto es para retoques puntuales. Sin etiquetas HTML y con las llaves equilibradas. Guarda una revisión de WordPress y un snapshot ("snapshot_id").',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'log'                 => array( 'type' => 'custom_css', 'id' => function () {
			return get_stylesheet();
		} ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'css' ),
			'properties' => array(
				'css'  => array( 'type' => 'string' ),
				'mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ), 'default' => 'replace' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input ) {
			$css = (string) $input['css'];
			if ( 'append' === ( $input['mode'] ?? 'replace' ) ) {
				$current = wp_get_custom_css();
				$css     = '' === trim( $current ) ? $css : rtrim( $current ) . "\n\n" . $css;
			}
			$ok = spiral_mcp_css_validate( $css );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$snapshot = spiral_mcp_snapshot_capture( 'custom_css', get_stylesheet(), 'update-custom-css' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = wp_update_custom_css_post( $css );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return spiral_mcp_custom_css_out() + array( 'snapshot_id' => $snapshot );
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );

/* -------------------------------------------------------------------------
 * Estilo de YOOtheme
 *
 * YOOtheme compila el LESS en el navegador (less.js en un Web Worker del
 * Personalizador) y no hay compilador en el servidor. Pero al abrir el
 * Personalizador, si StylerConfig['update'] es true, recompila y guarda el
 * CSS solo (sin pulsar Guardar). Mientras haya cambios de estilo pendientes,
 * este plugin pone ese flag a true: basta con abrir la URL de recompilación
 * en un navegador con sesión iniciada.
 * ---------------------------------------------------------------------- */

/** Servicio Styler de YOOtheme o WP_Error. */
function spiral_mcp_styler() {
	if ( function_exists( 'YOOtheme\app' ) && class_exists( 'YOOtheme\Theme\Styler\Styler' ) ) {
		try {
			return \YOOtheme\app( \YOOtheme\Theme\Styler\Styler::class );
		} catch ( Throwable $e ) {
			// Se devuelve el error de abajo.
		}
	}
	return new WP_Error( 'spiral_mcp_no_yootheme', 'YOOtheme Pro no está activo en este sitio.' );
}

/** Configuración del Styler (componentes, grupos y tipos de variables). */
function spiral_mcp_styler_config() {
	try {
		return \YOOtheme\app( \YOOtheme\Theme\Styler\StylerConfig::class )->getArrayCopy();
	} catch ( Throwable $e ) {
		return array();
	}
}

/** "fuse:dark-yellow" => [ 'fuse', 'dark-yellow' ]. */
function spiral_mcp_style_split( $style ) {
	return array_pad( explode( ':', (string) $style, 2 ), 2, '' );
}

/** Archivo de CSS compilado del sitio y sus datos (cabecera, fecha). */
function spiral_mcp_style_css_file() {
	$file   = get_template_directory() . '/css/theme.' . get_current_blog_id() . '.css';
	$exists = is_file( $file );
	clearstatcache( true, $file );
	$header = $exists ? (string) file_get_contents( $file, false, null, 0, 80 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return array(
		'file'        => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) ),
		'exists'      => $exists,
		'version'     => preg_match( '/\sv([\w\d.\-]+)\s/', $header, $m ) ? $m[1] : null,
		'compiled_at' => preg_match( '/compiled on (\S+)/', $header, $m ) ? $m[1] : null,
		'modified_ts' => $exists ? (int) filemtime( $file ) : 0,
	);
}

/** URL que, abierta en un navegador con sesión iniciada, recompila y guarda el CSS. */
function spiral_mcp_style_recompile_url() {
	if ( class_exists( 'YOOtheme\Url' ) ) {
		try {
			return (string) \YOOtheme\Url::route( 'customizer' );
		} catch ( Throwable $e ) {
			// Se usa la ruta conocida.
		}
	}
	return admin_url( 'admin-ajax.php?action=yootheme&yootheme=customizer' );
}

/** Estado de compilación. Si el CSS es posterior al último cambio, el pendiente se da por resuelto. */
function spiral_mcp_style_status() {
	$dirty = (int) get_option( 'spiral_mcp_style_dirty', 0 );
	$css   = spiral_mcp_style_css_file();
	if ( $dirty && $css['modified_ts'] >= $dirty ) {
		delete_option( 'spiral_mcp_style_dirty' );
		$dirty = 0;
	}
	$out = array(
		'needs_recompile' => (bool) $dirty,
		'dirty_since'     => $dirty ? gmdate( 'Y-m-d\TH:i:s\Z', $dirty ) : null,
		'css'             => array_diff_key( $css, array( 'modified_ts' => 1 ) ) + array( 'modified' => $css['modified_ts'] ? gmdate( 'Y-m-d\TH:i:s\Z', $css['modified_ts'] ) : null ),
		'recompile_url'   => spiral_mcp_style_recompile_url(),
	);
	if ( $dirty ) {
		$out['instructions'] = 'Abre recompile_url en un navegador con sesión iniciada como un usuario con permiso para personalizar el tema y espera unos 10-20 segundos: el Personalizador de YOOtheme recompila el estilo y guarda el CSS solo, sin pulsar nada. Después vuelve a llamar a spiral-mcp/get-style-status: needs_recompile pasará a false. Si el LESS tiene errores, el CSS no se guarda: revisa los últimos valores cambiados.';
	}
	return $out;
}

/** Marca el estilo como pendiente de recompilar. */
function spiral_mcp_style_mark_dirty() {
	// +1 s: el CSS debe ser posterior al cambio.
	update_option( 'spiral_mcp_style_dirty', time() + 1, false );
}

// Mientras haya cambios pendientes, el Personalizador recompila y guarda al abrirse.
add_action( 'after_setup_theme', function () {
	if ( ! class_exists( 'YOOtheme\Event' ) || ! class_exists( 'YOOtheme\Theme\Styler\StylerConfig' ) ) {
		return;
	}
	\YOOtheme\Event::on( \YOOtheme\Theme\Styler\StylerConfig::class, function ( $config ) {
		if ( get_option( 'spiral_mcp_style_dirty' ) ) {
			$config['update'] = true;
		}
	} );
}, 20 );

/** ¿El nombre de variable encaja con alguno de los patrones del Styler ("@global-*-color", "@global(-*)?-font-*")? */
function spiral_mcp_style_match( $patterns, $name ) {
	$parts = array();
	foreach ( (array) $patterns as $p ) {
		$parts[] = str_replace( array( '.', '*' ), array( '\.', '.*' ), (string) $p );
	}
	return $parts && preg_match( '/^(' . implode( '|', $parts ) . ')$/', $name );
}

/**
 * Catálogo de variables LESS de un estilo: nombre => [ default (expresión
 * LESS sin evaluar), file, component, group, type, options ]. Se cachea por
 * estilo y versión de YOOtheme.
 */
function spiral_mcp_style_catalog( $style ) {
	list( $id, $sub ) = spiral_mcp_style_split( $style );
	$key              = 'spiral_mcp_style_vars_' . md5( $style . '|' . spiral_mcp_yootheme_version() );
	$cached           = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$styler = spiral_mcp_styler();
	if ( is_wp_error( $styler ) ) {
		return $styler;
	}
	if ( ! $styler->getTheme( $id ) ) {
		return new WP_Error( 'spiral_mcp_bad_style', sprintf( 'El estilo "%s" no existe.', $id ) );
	}
	$imports = (array) \YOOtheme\Event::emit( 'styler.imports|filter', array(), $id );

	// Orden de declaración: base, después el archivo del estilo y al final su variación.
	$entry     = array();
	$variation = array();
	$base      = array();
	foreach ( $imports as $url => $less ) {
		if ( '.less' !== substr( $url, -5 ) ) {
			continue;
		}
		if ( preg_match( '#/styles/([^/]+)\.less$#', $url, $m ) ) {
			if ( $m[1] === $sub ) {
				$variation[ $url ] = $less;
			}
		} elseif ( preg_match( '#/less/theme\.' . preg_quote( $id, '#' ) . '\.less$#', $url ) ) {
			$entry[ $url ] = $less;
		} else {
			$base[ $url ] = $less;
		}
	}
	$vars = array();
	foreach ( array_merge( $base, $entry, $variation ) as $url => $less ) {
		// Solo declaraciones en la columna 0 (las de dentro de mixins van sangradas).
		if ( preg_match_all( '/^(@[a-z0-9-]+)\s*:\s*(.+?);\s*$/m', (string) $less, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $m ) {
				$vars[ $m[1] ] = array( 'default' => trim( $m[2] ), 'file' => basename( $url, '.less' ) );
			}
		}
	}

	$config = spiral_mcp_styler_config();
	$out    = array();
	foreach ( $vars as $name => $data ) {
		if ( spiral_mcp_style_match( $config['ignore'] ?? array(), $name ) ) {
			continue;
		}
		$component = null;
		$group     = '';
		foreach ( (array) ( $config['components'] ?? array() ) as $cid => $comp ) {
			$match = '';
			foreach ( (array) ( $comp['groups'] ?? array() ) as $gname => $patterns ) {
				if ( spiral_mcp_style_match( $patterns, $name ) ) {
					$match = $gname;
				}
			}
			if ( '' !== $match ) {
				$component = $cid;
				$group     = $match;
				break;
			}
		}
		$type    = 'text';
		$options = null;
		foreach ( (array) ( $config['types'] ?? array() ) as $t ) {
			if ( spiral_mcp_style_match( $t['vars'] ?? array(), $name ) ) {
				$type    = $t['type'] ?? 'text';
				$options = isset( $t['options'] ) && is_array( $t['options'] ) ? spiral_mcp_field_option_values( $t['options'] ) : null;
				break;
			}
		}
		$out[ $name ] = array(
			'default'   => $data['default'],
			'file'      => $data['file'],
			'component' => $component ? $component : $data['file'],
			'group'     => $group,
			'type'      => $type,
			'options'   => $options,
		);
	}
	set_transient( $key, $out, DAY_IN_SECONDS );
	return $out;
}

/** Estilos disponibles: id => [ name, variations ]. */
function spiral_mcp_style_list() {
	$styler = spiral_mcp_styler();
	if ( is_wp_error( $styler ) ) {
		return $styler;
	}
	$out = array();
	foreach ( (array) $styler->getThemes() as $id => $theme ) {
		$out[ $id ] = array( 'name' => $theme['name'] ?? $id, 'variations' => array_keys( (array) ( $theme['styles'] ?? array() ) ) );
	}
	return $out;
}

/** Valida un valor LESS: una sola expresión, sin ";", llaves ni saltos de línea. */
function spiral_mcp_less_value_check( $name, $value ) {
	if ( ! preg_match( '/^@[a-z0-9-]+$/', $name ) ) {
		return new WP_Error( 'spiral_mcp_bad_less', sprintf( '"%s" no es un nombre de variable LESS válido (p. ej. @global-primary-background).', $name ) );
	}
	if ( null === $value ) {
		return true;
	}
	if ( ! is_scalar( $value ) || preg_match( '/[;{}\r\n]/', (string) $value ) ) {
		return new WP_Error( 'spiral_mcp_bad_less', sprintf( 'El valor de %s debe ser una sola expresión LESS, sin ";", llaves ni saltos de línea.', $name ) );
	}
	return true;
}

add_action( 'wp_abilities_api_init', function () {

	$can = function () {
		return current_user_can( 'edit_theme_options' );
	};
	$log = array(
		'type' => 'theme_style',
		'id'   => function () {
			return 'style';
		},
	);

	spiral_mcp_register_ability( 'spiral-mcp/get-theme-style', array(
		'label'               => 'Leer el estilo de YOOtheme',
		'description'         => 'Devuelve el estilo de YOOtheme: "style" (estilo base y variación, p. ej. "fuse" o "fuse:dark-yellow"), "less" (las variables LESS cambiadas respecto al estilo, p. ej. {"@global-primary-background": "#1e87f0"}), "custom_less" (LESS propio que se añade al final), la lista de estilos disponibles con sus variaciones y el estado de compilación. Para ver todas las variables con su valor por defecto usa spiral-mcp/list-style-variables.',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'include_styles' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Incluir la lista de estilos disponibles.' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$c   = $read['config'];
			$out = array(
				'style'       => (string) ( $c['style'] ?? '' ),
				'less'        => (object) ( is_array( $c['less'] ?? null ) ? $c['less'] : array() ),
				'custom_less' => (string) ( $c['custom_less'] ?? '' ),
				'status'      => spiral_mcp_style_status(),
			);
			if ( ( (array) $input )['include_styles'] ?? true ) {
				$styles = spiral_mcp_style_list();
				if ( ! is_wp_error( $styles ) ) {
					$out['styles'] = $styles;
				}
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/list-style-variables', array(
		'label'               => 'Listar variables de estilo',
		'description'         => 'Lista las variables LESS del estilo activo de YOOtheme, agrupadas como en el Personalizador (component: global, base, button, navbar…; group: primary, typography, colors…). Para cada una: valor por defecto (expresión LESS sin evaluar, p. ej. "darken(@global-primary-background, 5%)" o "#1e87f0"), valor actual, tipo de campo (color, font, select-custom con sus opciones, boxshadow, text) y si está modificada. Para la paleta de marca empieza por component "global": los colores están en los grupos "colors" y "backgrounds" (el color principal es @global-primary-background); los grupos "primary" y "secondary" son las fuentes primaria y secundaria, no colores. Filtra para no recibir cientos de variables.',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'component'     => array( 'type' => 'string', 'description' => 'p. ej. global, base, button, navbar, heading.' ),
				'group'         => array( 'type' => 'string', 'description' => 'p. ej. primary, typography, colors.' ),
				'search'        => array( 'type' => 'string', 'description' => 'Texto en el nombre, p. ej. font-family.' ),
				'modified_only' => array( 'type' => 'boolean', 'default' => false ),
				'per_page'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'page'          => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$read  = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$style   = (string) ( $read['config']['style'] ?? 'fuse' );
			$catalog = spiral_mcp_style_catalog( $style );
			if ( is_wp_error( $catalog ) ) {
				return $catalog;
			}
			$less       = is_array( $read['config']['less'] ?? null ) ? $read['config']['less'] : array();
			$components = array();
			$items      = array();
			foreach ( $catalog as $name => $v ) {
				$components[ $v['component'] ] = ( $components[ $v['component'] ] ?? 0 ) + 1;
				if ( ! empty( $input['component'] ) && $input['component'] !== $v['component'] ) {
					continue;
				}
				if ( ! empty( $input['group'] ) && $input['group'] !== $v['group'] ) {
					continue;
				}
				if ( ! empty( $input['search'] ) && false === stripos( $name, (string) $input['search'] ) ) {
					continue;
				}
				$modified = array_key_exists( $name, $less );
				if ( ! empty( $input['modified_only'] ) && ! $modified ) {
					continue;
				}
				$row = array(
					'name'      => $name,
					'default'   => $v['default'],
					'value'     => $modified ? $less[ $name ] : $v['default'],
					'modified'  => $modified,
					'component' => $v['component'],
					'group'     => $v['group'],
					'type'      => $v['type'],
				);
				if ( $v['options'] ) {
					$row['options'] = $v['options'];
				}
				$items[] = $row;
			}
			$per = (int) ( $input['per_page'] ?? 100 );
			return array(
				'style'      => $style,
				'total'      => count( $items ),
				'items'      => array_slice( $items, ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per, $per ),
				'components' => (object) $components,
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/update-theme-style', array(
		'label'               => 'Cambiar el estilo de YOOtheme',
		'description'         => 'Cambia el estilo de YOOtheme: "style" (estilo base o "estilo:variación", ver spiral-mcp/get-theme-style), "less" (variables a cambiar, merge, p. ej. {"@global-primary-background": "#e63946", "@global-font-family": "\'Roboto\'"}; null devuelve una variable a su valor por defecto; las fuentes de Google van entre comillas simples y YOOtheme las descarga sola) y/o "custom_less" (reemplaza el LESS propio). IMPORTANTE: el CSS NO cambia hasta recompilar. La respuesta trae needs_recompile, recompile_url e instrucciones: abre esa URL en un navegador con sesión iniciada y espera a que spiral-mcp/get-style-status diga needs_recompile=false. Para un retoque que se vea al instante usa spiral-mcp/update-custom-css. Antes guarda un snapshot de la configuración ("snapshot_id").',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'log'                 => $log,
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'style'       => array( 'type' => 'string' ),
				'less'        => array( 'type' => 'object', 'description' => 'Variables a cambiar; null = valor por defecto.' ),
				'custom_less' => array( 'type' => 'string' ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			if ( ! isset( $input['style'] ) && ! isset( $input['less'] ) && ! isset( $input['custom_less'] ) ) {
				return new WP_Error( 'spiral_mcp_nothing', 'Envía "style", "less" y/o "custom_less".' );
			}
			$read = spiral_mcp_theme_config_read();
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			$config   = $read['config'];
			$warnings = array();

			if ( isset( $input['style'] ) ) {
				$styles = spiral_mcp_style_list();
				if ( is_wp_error( $styles ) ) {
					return $styles;
				}
				list( $id, $sub ) = spiral_mcp_style_split( $input['style'] );
				if ( ! isset( $styles[ $id ] ) ) {
					return new WP_Error( 'spiral_mcp_bad_style', sprintf( 'El estilo "%s" no existe. Disponibles: %s.', $id, implode( ', ', array_keys( $styles ) ) ) );
				}
				if ( '' !== $sub && ! in_array( $sub, $styles[ $id ]['variations'], true ) ) {
					return new WP_Error( 'spiral_mcp_bad_style', sprintf( 'La variación "%s" no existe en %s. Disponibles: %s.', $sub, $id, $styles[ $id ]['variations'] ? implode( ', ', $styles[ $id ]['variations'] ) : 'ninguna' ) );
				}
				$config['style'] = (string) $input['style'];
			}

			if ( isset( $input['less'] ) ) {
				if ( ! is_array( $input['less'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_less', '"less" debe ser un objeto {"@variable": "valor"}.' );
				}
				$catalog = spiral_mcp_style_catalog( (string) ( $config['style'] ?? 'fuse' ) );
				$less    = is_array( $config['less'] ?? null ) ? $config['less'] : array();
				foreach ( $input['less'] as $name => $value ) {
					$name = '@' === substr( (string) $name, 0, 1 ) ? (string) $name : '@' . $name;
					$ok   = spiral_mcp_less_value_check( $name, $value );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					if ( null === $value ) {
						unset( $less[ $name ] );
						continue;
					}
					$value = (string) $value;
					if ( is_array( $catalog ) && ! isset( $catalog[ $name ] ) && '@internal-fonts' !== $name ) {
						$warnings[] = sprintf( '%s no existe en el estilo activo: YOOtheme la ignorará (busca el nombre con spiral-mcp/list-style-variables).', $name );
					}
					if ( '-font-family' === substr( $name, -12 ) && preg_match( '/^[A-Z][\w ]+$/', $value ) ) {
						$warnings[] = sprintf( 'Para una fuente de Google en %1$s usa comillas simples: "\'%2$s\'".', $name, $value );
					}
					$less[ $name ] = $value;
				}
				$config['less'] = $less;
			}

			if ( isset( $input['custom_less'] ) ) {
				if ( preg_match( '#</?\w+#', (string) $input['custom_less'] ) ) {
					return new WP_Error( 'spiral_mcp_bad_less', '"custom_less" no puede contener etiquetas HTML.' );
				}
				$config['custom_less'] = (string) $input['custom_less'];
			}

			$snapshot = spiral_mcp_snapshot_take( 'theme_config', 'config', $read['raw'], 'update-theme-style' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$res = spiral_mcp_theme_config_write( $config, $read['raw'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			spiral_mcp_style_mark_dirty();
			return array(
				'style'       => (string) ( $config['style'] ?? '' ),
				'less'        => (object) ( $config['less'] ?? array() ),
				'custom_less' => (string) ( $config['custom_less'] ?? '' ),
				'snapshot_id' => $snapshot,
				'warnings'    => $warnings,
			) + spiral_mcp_style_status();
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/get-style-status', array(
		'label'               => 'Estado de compilación del estilo',
		'description'         => 'Indica si hay cambios de estilo de YOOtheme pendientes de compilar (needs_recompile, desde cuándo), la versión y fecha del CSS compilado actual y la URL de recompilación con instrucciones. Cuando el CSS se ha recompilado después del último cambio, needs_recompile pasa a false sola.',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'input_schema'        => array( 'type' => 'object', 'properties' => new stdClass() ),
		'permission_callback' => $can,
		'execute_callback'    => function () {
			return spiral_mcp_style_status();
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	spiral_mcp_register_ability( 'spiral-mcp/mark-style-compiled', array(
		'label'               => 'Marcar el estilo como compilado',
		'description'         => 'Quita la marca de cambios de estilo pendientes. Normalmente no hace falta: spiral-mcp/get-style-status la quita sola al detectar el CSS nuevo. Úsala solo si se recompiló por otra vía. Si el CSS no es posterior al último cambio, exige "force": true (el sitio seguiría mostrando el estilo anterior).',
		'category'            => 'spiral-mcp-styles',
		'group'               => 'design',
		'log'                 => $log,
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'force' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'permission_callback' => $can,
		'execute_callback'    => function ( $input = array() ) {
			$status = spiral_mcp_style_status();
			if ( $status['needs_recompile'] && empty( ( (array) $input )['force'] ) ) {
				return new WP_Error( 'spiral_mcp_not_compiled', 'El CSS compilado es anterior al último cambio de estilo. Abre recompile_url (ver spiral-mcp/get-style-status) o envía "force": true.' );
			}
			delete_option( 'spiral_mcp_style_dirty' );
			return spiral_mcp_style_status();
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );
} );
