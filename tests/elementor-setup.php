<?php
/**
 * Prepara lo que tests/smoke.sh no puede crear por REST para la sección de Elementor:
 * una plantilla de la biblioteca (elementor_library) de tipo "header", que en un sitio sin
 * Elementor Pro no tiene tipo de documento registrado y update-elementor-data debe rechazar
 * (spiral_mcp_elementor_type_unsupported). Elementor bloquea la REST de ese CPT a quien no es
 * administrador, por eso se crea aquí.
 *
 * Uso: php tests/elementor-setup.php /ruta/al/wordpress
 * Imprime la variable para la prueba de humo: SMOKE_ELEMENTOR_HEADER_ID=<id>.
 * Con --delete borra la plantilla. Solo en local.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$wp_path = $argv[1] ?? '';
if ( '' === $wp_path || ! file_exists( rtrim( $wp_path, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Uso: php tests/elementor-setup.php /ruta/al/wordpress [--delete]\n" );
	exit( 1 );
}
define( 'REST_REQUEST', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! preg_match( '/(\.test|\.local|localhost)$/', (string) $host ) ) {
	fwrite( STDERR, "Esto parece un sitio de producción ($host): solo en local.\n" );
	exit( 1 );
}
if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	fwrite( STDERR, "Elementor no está activo.\n" );
	exit( 1 );
}
wp_set_current_user( 1 );

$title    = 'SEC header sin Pro (smoke)';
$existing = get_posts( array( 'post_type' => 'elementor_library', 'title' => $title, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
if ( in_array( '--delete', $argv, true ) ) {
	foreach ( $existing as $id ) {
		wp_delete_post( $id, true );
		echo "Plantilla $id borrada.\n";
	}
	exit( 0 );
}
$id = $existing ? (int) $existing[0] : 0;
if ( ! $id ) {
	$id = wp_insert_post( array(
		'post_type'   => 'elementor_library',
		'post_status' => 'publish',
		'post_title'  => $title,
		'post_content' => '',
	), true );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
}
update_post_meta( $id, '_elementor_edit_mode', 'builder' );
update_post_meta( $id, '_elementor_template_type', 'header' );
update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
update_metadata( 'post', $id, '_elementor_data', wp_slash( '[]' ) );
if ( taxonomy_exists( 'elementor_library_type' ) ) {
	wp_set_object_terms( $id, 'header', 'elementor_library_type' );
}
echo "Plantilla header $id lista (tipo header; sin Elementor Pro no tiene documento registrado).\n";
echo "SMOKE_ELEMENTOR_HEADER_ID=$id\n";
