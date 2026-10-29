<?php
/**
 * Elementor: detección de páginas construidas con Elementor y envoltorio de las abilities
 * elementor/* (el MCP nativo de Elementor) con los permisos por grupos, la auditoría, la purga
 * de caché y el interruptor de Ajustes > Spiral-MCP.
 *
 * Spiral no reimplementa el builder de Elementor. Elementor (4.3+) registra sus propias
 * abilities en la Abilities API cuando el administrador activa su MCP (opción
 * elementor_mcp_enabled, menú Elementor en wp-admin), y el MCP Adapter las publica en el mismo
 * servidor que las de Spiral. Aquí solo se les añade lo que el administrador espera de
 * cualquier herramienta del agente: la capacidad spiral_mcp_<grupo>, el registro en la
 * auditoría, la purga automática de caché y poder desactivarlas desde la página de ajustes.
 * Ese MCP solo edita elementos V4 (atómicos); los widgets clásicos se leen y editan con las
 * abilities de includes/elementor-data.php, a través de la Document API de Elementor.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/** ¿Elementor está activo? */
function spiral_mcp_elementor_active() {
	return defined( 'ELEMENTOR_VERSION' ) && did_action( 'elementor/loaded' ) > 0;
}

/**
 * ¿El post está construido con Elementor? Mismo criterio que Document::is_built_with_elementor():
 * meta _elementor_edit_mode = "builder". Elementor renderiza entonces sus datos en the_content y
 * el post_content de WordPress no se muestra.
 */
function spiral_mcp_elementor_built( $post_id ) {
	return spiral_mcp_elementor_active() && 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
}

/**
 * Qué constructor maqueta un post: "elementor" (datos de Elementor; manda sobre el contenido),
 * "yootheme" (layout de YOOtheme en post_content) o "none" (HTML o bloques de Gutenberg).
 */
function spiral_mcp_builder_of( WP_Post $post ) {
	if ( spiral_mcp_elementor_built( $post->ID ) ) {
		return 'elementor';
	}
	if ( spiral_mcp_has_layout( $post->post_content ) ) {
		return 'yootheme';
	}
	return 'none';
}

/** Error al enviar "content" a una página de Elementor: lo que se vería en la web no cambiaría. */
function spiral_mcp_elementor_content_error( $post_id ) {
	return new WP_Error(
		'spiral_mcp_elementor_content',
		sprintf( 'El contenido %d está construido con Elementor: su diseño está en los datos de Elementor y lo que envíes en "content" no se mostraría en la web. Edítalo con spiral-mcp/get-elementor-outline y spiral-mcp/patch-elementor-data (widgets clásicos); los elementos V4 (atómicos) van por las herramientas elementor/* de su MCP. Los demás campos (title, status, slug, parent, terms, featured_media…) sí se pueden cambiar con esta ability.', (int) $post_id )
	);
}

/**
 * Grupo de permisos y objeto de auditoría de cada ability de Elementor. Las que no están aquí
 * reciben el grupo "design". "read" marca las lecturas que Elementor no anota como readonly
 * (guías, recursos, esquemas, enlaces de vista previa): no se registran en la auditoría.
 */
function spiral_mcp_elementor_ability_map() {
	$post = array( 'type' => 'post', 'id' => 'post_id' );
	$kit  = array(
		'type' => 'elementor_kit',
		'id'   => function () {
			return 'kit';
		},
	);
	return array(
		// Documentos (páginas, entradas): grupo content, como el resto del contenido.
		'elementor/list-posts'                 => array( 'group' => 'content', 'read' => true ),
		'elementor/get-page-structure'         => array( 'group' => 'content', 'read' => true ),
		'elementor/create-page'                => array(
			'group' => 'content',
			'log'   => array(
				'type' => 'post',
				'id'   => function ( $input, $result ) {
					return $result['id'] ?? ( $result['post_id'] ?? '' );
				},
			),
		),
		'elementor/update-page-settings'       => array( 'group' => 'content', 'log' => $post ),
		'elementor/publish-document'           => array( 'group' => 'content', 'log' => $post ),
		'elementor/create-preview-link'        => array( 'group' => array( 'content', 'design' ), 'read' => true ),
		// Diseño de un documento.
		'elementor/build-composition'          => array( 'group' => 'design', 'log' => $post ),
		'elementor/manage-elements'            => array( 'group' => 'design', 'log' => $post ),
		// Kit activo (variables, clases y estilos por defecto) y componentes: diseño global del sitio.
		'elementor/manage-global-variable'     => array( 'group' => 'design', 'log' => $kit ),
		'elementor/manage-classes'             => array( 'group' => 'design', 'log' => $kit ),
		'elementor/reorder-classes'            => array( 'group' => 'design', 'log' => $kit ),
		'elementor/manage-default-styles'      => array( 'group' => 'design', 'log' => $kit ),
		'elementor/manage-component'           => array(
			'group' => 'design',
			'log'   => array(
				'type' => 'elementor_component',
				'id'   => function ( $input, $result ) {
					return $input['component_id'] ?? ( $result['component_id'] ?? ( $result['id'] ?? ( $input['action'] ?? '' ) ) );
				},
			),
		),
		// Medios.
		'elementor/list-assets'                => array( 'group' => 'media', 'read' => true ),
		// Lecturas: esquemas, recursos y guías.
		'elementor/get-default-styles'         => array( 'group' => 'design', 'read' => true ),
		'elementor/get-widget-schema'          => array( 'group' => 'design', 'read' => true ),
		'elementor/list-widget-schemas'        => array( 'group' => 'design', 'read' => true ),
		'elementor/list-dynamic-tags'          => array( 'group' => 'design', 'read' => true ),
		'elementor/list-resources'             => array( 'group' => 'design', 'read' => true ),
		'elementor/read-resource'              => array( 'group' => 'design', 'read' => true ),
		'elementor/list-components'            => array( 'group' => 'design', 'read' => true ),
		'elementor/global-classes-resource'    => array( 'group' => 'design', 'read' => true ),
		'elementor/global-variables-resource'  => array( 'group' => 'design', 'read' => true ),
		'elementor/interactions-schema-resource' => array( 'group' => 'design', 'read' => true ),
		'elementor/style-best-practices'       => array( 'group' => 'design', 'read' => true ),
		'elementor/wordpress-best-practices'   => array( 'group' => 'design', 'read' => true ),
		'elementor/manage-global-variable-guide' => array( 'group' => 'design', 'read' => true ),
	);
}

/**
 * Envuelve cada ability elementor/* al registrarse (filtro del core wp_register_ability_args):
 * - permission_callback: exige la capacidad spiral_mcp_<grupo> y después la comprobación propia
 *   de Elementor (edit_posts, edit_post, manage_options…).
 * - execute_callback: las escrituras pasan por spiral_mcp_log_run() (auditoría, excepciones a
 *   WP_Error) y la purga automática de caché; los WP_Error reciben su código HTTP. Una llamada
 *   con "dry_run": true no guarda nada y cuenta como lectura.
 * - catálogo: aparecen en Ajustes > Spiral-MCP > Abilities. Una desactivada no se puede dejar
 *   sin registrar desde un filtro, así que se oculta al MCP y a REST y su permiso devuelve false.
 */
add_filter( 'wp_register_ability_args', function ( $args, $name ) {
	if ( ! is_array( $args ) || 0 !== strpos( (string) $name, 'elementor/' ) ) {
		return $args;
	}
	$map         = spiral_mcp_elementor_ability_map();
	$info        = $map[ $name ] ?? array( 'group' => 'design' );
	$groups      = (array) $info['group'];
	$annotations = (array) ( $args['meta']['annotations'] ?? array() );
	$read        = ! empty( $info['read'] ) || ! empty( $annotations['readonly'] );
	$log         = (array) ( $info['log'] ?? array( 'type' => 'elementor' ) );
	$enabled     = ! in_array( $name, spiral_mcp_disabled_abilities(), true );

	spiral_mcp_ability_catalog( $name, array(
		'label'       => (string) ( $args['label'] ?? $name ),
		'description' => (string) ( $args['description'] ?? '' ),
		'category'    => (string) ( $args['category'] ?? 'elementor' ),
		'groups'      => $groups,
		'kind'        => $read ? 'read' : ( ! empty( $annotations['destructive'] ) ? 'destructive' : 'write' ),
		'enabled'     => $enabled,
		'available'   => true,
		'requires'    => '',
		'external'    => true,
	) );

	$permission                  = $args['permission_callback'] ?? null;
	$args['permission_callback'] = function ( $input = null ) use ( $groups, $permission, $enabled ) {
		if ( ! $enabled || ! spiral_mcp_user_can_any( $groups ) || ! is_callable( $permission ) ) {
			return false;
		}
		return call_user_func( $permission, $input );
	};
	if ( ! $enabled ) {
		$args['meta']['show_in_rest']  = false;
		$args['meta']['mcp']['public'] = false;
	}

	if ( isset( $args['execute_callback'] ) ) {
		$execute                  = $args['execute_callback'];
		$args['execute_callback'] = function ( $input = null ) use ( $name, $execute, $log, $groups, $read ) {
			// "dry_run": true (build-composition) solo valida, no guarda: no se registra como escritura.
			if ( $read || ! empty( ( (array) $input )['dry_run'] ) ) {
				$result = call_user_func( $execute, $input );
			} else {
				$result = spiral_mcp_log_run( $name, $execute, $input, $log );
				if ( ! is_wp_error( $result ) ) {
					spiral_mcp_maybe_auto_clear_cache( $name, $groups, $input, $result, $log );
				}
			}
			return is_wp_error( $result ) ? spiral_mcp_error_status( $result ) : $result;
		};
	}
	return $args;
}, 10, 2 );
