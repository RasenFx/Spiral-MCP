<?php
/**
 * Prepara un WordPress LOCAL para tests/security.sh: crea los usuarios y las páginas de prueba
 * y guarda sus Application Passwords en un archivo .env (fuera del repo; no lo versiones).
 *
 * Uso: php tests/security-setup.php /ruta/al/wordpress https://wordpress-dev.test /ruta/creds.env
 *
 * Se puede repetir: regenera las Application Passwords y reutiliza usuarios y páginas.
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "Uso: php tests/security-setup.php /ruta/al/wordpress https://sitio.test /ruta/creds.env\n" );
	exit( 1 );
}
list( , $wp_path, $url, $out ) = $argv;
$host = (string) parse_url( $url, PHP_URL_HOST );

$_SERVER['HTTP_HOST']      = $host;
$_SERVER['SERVER_NAME']    = $host;
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require rtrim( $wp_path, '/' . chr( 92 ) ) . '/wp-load.php';

if ( ! function_exists( 'spiral_mcp_install_caps' ) ) {
	fwrite( STDERR, "Spiral-MCP-Control no está activo en ese WordPress.\n" );
	exit( 1 );
}
if ( 'production' === wp_get_environment_type() && false === strpos( $host, '.test' ) && false === strpos( $host, 'localhost' ) ) {
	fwrite( STDERR, "Esto parece un sitio de producción ($host): las pruebas de seguridad solo se ejecutan en local.\n" );
	exit( 1 );
}

$env = array( 'WP_URL=' . rtrim( $url, '/' ) );

// Rol "mínimo": las capacidades nativas del rol del agente sin ninguna spiral_mcp_*.
$agent = get_role( 'spiral_mcp_agent' );
$caps  = array_keys( array_filter( $agent->capabilities ) );
$caps  = array_values( array_filter( $caps, function ( $c ) {
	return 0 !== strpos( $c, 'spiral_mcp_' );
} ) );
if ( ! get_role( 'spiral_sec_min' ) ) {
	add_role( 'spiral_sec_min', 'SEC agente sin caps propias', array_fill_keys( $caps, true ) );
}

$users = array(
	'sec-agente'     => 'spiral_mcp_agent',
	'sec-agente-min' => 'spiral_sec_min',
	'sec-suscriptor' => 'subscriber',
);
if ( get_role( 'customer' ) ) {
	$users['sec-cliente'] = 'customer';
}
foreach ( $users as $login => $role ) {
	$u = get_user_by( 'login', $login );
	if ( ! $u ) {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => $role ) );
		if ( is_wp_error( $id ) ) {
			fwrite( STDERR, "$login: " . $id->get_error_message() . "\n" );
			exit( 1 );
		}
		$u = get_user_by( 'id', $id );
	}
	$u->set_role( $role );
	WP_Application_Passwords::delete_all_application_passwords( $u->ID );
	$ap = WP_Application_Passwords::create_new_application_password( $u->ID, array( 'name' => 'security.sh' ) );
	if ( is_wp_error( $ap ) ) {
		fwrite( STDERR, "$login: " . $ap->get_error_message() . "\n" );
		exit( 1 );
	}
	$key   = strtoupper( str_replace( '-', '_', substr( $login, 4 ) ) );
	$env[] = "{$key}_USER=$login";
	$env[] = "{$key}_PASS='" . $ap[0] . "'";
	echo "$login: rol $role, ID {$u->ID}\n";
}

// Páginas del administrador en cada estado, para las pruebas de vista previa.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
$admin  = $admins ? $admins[0]->ID : 1;
$pages  = array(
	'draft'    => array( 'SEC borrador', array() ),
	'private'  => array( 'SEC privada', array() ),
	'publish'  => array( 'SEC publicada', array() ),
	'password' => array( 'SEC con clave', array( 'post_status' => 'publish', 'post_password' => 'secreto', 'post_content' => 'TEXTO-PROTEGIDO' ) ),
);
foreach ( $pages as $key => $def ) {
	list( $title, $extra ) = $def;
	$found = get_posts( array( 'post_type' => 'page', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1 ) );
	if ( $found ) {
		$pid = $found[0]->ID;
	} else {
		$pid = wp_insert_post( $extra + array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => $key, 'post_author' => $admin, 'post_content' => 'Contenido ' . $key ) );
	}
	$env[] = 'PAGE_' . strtoupper( $key ) . "=$pid";
	echo "página $key: ID $pid\n";
}

file_put_contents( $out, implode( "\n", $env ) . "\n" );
echo "Credenciales guardadas en $out (no lo versiones).\n";
echo "Ejecuta: SEC_ENV=$out WP_PATH=$wp_path bash tests/security.sh\n";
