<?php
/**
 * Regenera skills/spiral-mcp-wordpress/references/abilities.md a partir de los
 * esquemas de las abilities registradas. Ejecutar tras añadir o cambiar abilities:
 *
 *   php tests/gen-skill-abilities.php /ruta/al/wordpress
 *
 * @package Spiral_MCP_Control
 */

$spiral_wp_root = empty( $argv[1] ) ? '' : rtrim( str_replace( '\\', '/', $argv[1] ), '/' );
if ( '' === $spiral_wp_root || ! is_file( $spiral_wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Uso: php tests/gen-skill-abilities.php /ruta/al/wordpress\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require $spiral_wp_root . '/wp-load.php';
$output = dirname( __DIR__ ) . '/skills/spiral-mcp-wordpress/references/abilities.md';
// Un administrador, para que las abilities se registren igual que en producción.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins ? (int) $admins[0] : 0 );

$by_cat = array();
foreach ( wp_get_abilities() as $ability ) {
	$name = $ability->get_name();
	if ( 0 !== strpos( $name, 'spiral-mcp/' ) ) {
		continue;
	}
	$by_cat[ $ability->get_category() ][] = $ability;
}
$titles = array(
	'spiral-mcp-content'   => 'Contenido, taxonomías y SEO',
	'spiral-mcp-layout'    => 'Layouts y elementos',
	'spiral-mcp-elementor' => 'Elementor (widgets clásicos)',
	'spiral-mcp-theme'     => 'Tema: áreas, configuración, plantillas, biblioteca, widgets y caché',
	'spiral-mcp-menus'     => 'Menús',
	'spiral-mcp-media'     => 'Medios',
	'spiral-mcp-styles'    => 'Estilos',
	'spiral-mcp-preview'   => 'Verificación',
	'spiral-mcp-settings'  => 'Ajustes del sitio',
	'spiral-mcp-snapshots' => 'Snapshots',
	'spiral-mcp-plugins'   => 'Plugins',
	'spiral-mcp-forms'     => 'Formularios (Contact Form 7)',
	'spiral-mcp-cptui'     => 'Tipos de contenido y taxonomías (Custom Post Type UI)',
	'spiral-mcp-shop'      => 'Tienda (WooCommerce)',
	'spiral-mcp-orders'    => 'Pedidos y clientes (WooCommerce)',
	'spiral-mcp-languages' => 'Idiomas (Polylang)',
	'spiral-mcp-activity'  => 'Auditoría',
);
// Categorías nuevas que aún no tengan título aquí: se incluyen igual (con su slug) para no perder abilities.
foreach ( array_keys( $by_cat ) as $cat ) {
	if ( ! isset( $titles[ $cat ] ) ) {
		$titles[ $cat ] = $cat;
		fwrite( STDERR, "Aviso: la categoría $cat no tiene título en tests/gen-skill-abilities.php
" );
	}
}

$fmt_type = function ( $p ) {
	$t = $p['type'] ?? 'any';
	$t = is_array( $t ) ? implode( ' / ', $t ) : $t;
	if ( isset( $p['enum'] ) ) {
		$t = implode( ' / ', array_map( function ( $v ) {
			return '"' . $v . '"';
		}, $p['enum'] ) );
	}
	return $t;
};

$out  = "# Referencia de abilities spiral-mcp/*\n\n";
$out .= "Generada a partir de los esquemas registrados por el plugin (v" . SPIRAL_MCP_VERSION . "). Cada ability trae además su descripción completa en la lista de herramientas MCP: léela allí para los detalles. Aquí está la vista de conjunto: método implícito, parámetros obligatorios (**negrita**) y opcionales.\n\n";
$out .= "Leyenda: lectura = no cambia nada; escritura = se registra en la auditoría; destructiva = requiere `\"confirm\": true`.\n\n";
$out .= "## Índice\n\n";
foreach ( $titles as $cat => $title ) {
	if ( isset( $by_cat[ $cat ] ) ) {
		$out .= '- [' . $title . '](#' . sanitize_title( $title ) . ")\n";
	}
}
$out .= "\n";
foreach ( $titles as $cat => $title ) {
	if ( empty( $by_cat[ $cat ] ) ) {
		continue;
	}
	$out .= "## $title\n\n| Ability | Tipo | Parámetros |\n|---|---|---|\n";
	foreach ( $by_cat[ $cat ] as $ability ) {
		$meta   = $ability->get_meta();
		$ann    = $meta['annotations'] ?? array();
		$kind   = ! empty( $ann['readonly'] ) ? 'lectura' : ( ! empty( $ann['destructive'] ) ? 'destructiva' : 'escritura' );
		$schema = $ability->get_input_schema();
		$req    = (array) ( $schema['required'] ?? array() );
		$params = array();
		foreach ( (array) ( $schema['properties'] ?? array() ) as $pname => $p ) {
			$p        = (array) $p;
			$label    = in_array( $pname, $req, true ) ? "**$pname**" : $pname;
			$params[] = $label . ' `' . $fmt_type( $p ) . '`';
		}
		$short = preg_replace( '/^spiral-mcp\//', '', $ability->get_name() );
		$out  .= '| `' . $short . '` | ' . $kind . ' | ' . ( $params ? implode( ', ', $params ) : '—' ) . " |\n";
	}
	$out .= "\n";
}
file_put_contents( $output, $out );
echo 'abilities: ', array_sum( array_map( 'count', $by_cat ) ), "\n";
