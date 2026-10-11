#!/usr/bin/env bash
# Prueba de humo: llama por REST a cada ability con una Application Password
# de un usuario con el rol spiral_mcp_agent, contra un WordPress LOCAL.
#
# Uso:
#   WP_URL=https://spiral-mcp-control.test WP_USER=agente WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' tests/smoke.sh
#
# Requisitos: bash, curl y php (para leer el JSON). Usa ?rest_route=, así que
# funciona también con los enlaces permanentes "simples". curl va con -k
# (certificados autofirmados del entorno local): nunca lo apuntes a producción.
#
# SMOKE_MEDIA_URL: imagen https para probar upload-media desde URL. Por defecto,
# un logo de s.w.org; vacía (SMOKE_MEDIA_URL=) omite la prueba.
#
# Deja el sitio como estaba: borra la página y los menús de prueba y
# restaura los snapshots del footer y del widget que toca.

set -u
: "${WP_URL:?Falta WP_URL}"
: "${WP_USER:?Falta WP_USER}"
: "${WP_APP_PASSWORD:?Falta WP_APP_PASSWORD}"

DIR="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp)"
TMP_BODY="$(mktemp)"
trap 'rm -f "$TMP" "$TMP_BODY"' EXIT
PASS=0
FAIL=0
STATUS=''
BODY=''

# rest METHOD RUTA [JSON_BODY] -> STATUS, BODY
rest() {
	local method=$1 route=$2 body=${3:-}
	local args=(-sk -u "$WP_USER:$WP_APP_PASSWORD" -o "$TMP" -w '%{http_code}' -X "$method")
	if [ -n "$body" ]; then
		# Desde archivo: en Windows, pasarlo como argumento a curl estropea los acentos.
		printf '%s' "$body" > "$TMP_BODY"
		args+=(-H 'Content-Type: application/json' --data-binary "@$TMP_BODY")
	fi
	STATUS=$(curl "${args[@]}" "${WP_URL%/}/?rest_route=$route")
	BODY=$(cat "$TMP")
}

# call METHOD ABILITY [JSON_INPUT] -> STATUS, BODY
# GET y DELETE mandan la entrada como input[...] en la query; POST, en el cuerpo JSON.
call() {
	local method=$1 name=$2 input=${3:-}
	local route="/wp-abilities/v1/abilities/$name/run"
	if [ "$method" = POST ]; then
		[ -z "$input" ] && input='{}'
		rest POST "$route" "{\"input\":$input}"
	else
		if [ -n "$input" ]; then
			route="$route&$(printf '%s' "$input" | php -r 'echo http_build_query( array( "input" => json_decode( stream_get_contents( STDIN ), true ) ) );')"
		fi
		rest "$method" "$route"
	fi
}

# jget RUTA: valor de BODY en la ruta con puntos (a.b.0.c). "#" al final = número de elementos.
jget() {
	printf '%s' "$BODY" | php -r '
		$d = json_decode( stream_get_contents( STDIN ), true );
		foreach ( explode( ".", $argv[1] ) as $k ) {
			if ( "" === $k ) { continue; }
			if ( "#" === $k ) { echo is_array( $d ) ? count( $d ) : 0; exit; }
			if ( ! is_array( $d ) || ! array_key_exists( $k, $d ) ) { exit( 1 ); }
			$d = $d[ $k ];
		}
		echo is_bool( $d ) ? ( $d ? "true" : "false" ) : ( is_scalar( $d ) ? $d : json_encode( $d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	' "$1"
}

ok() { PASS=$((PASS + 1)); printf 'OK    %s\n' "$1"; }
ko() { FAIL=$((FAIL + 1)); printf 'FALLO %s (HTTP %s) %s\n' "$1" "$STATUS" "$(printf '%s' "$BODY" | head -c 300)"; }

# check DESCRIPCIÓN STATUS_ESPERADO [RUTA_QUE_DEBE_EXISTIR...]
check() {
	local desc=$1 want=$2
	shift 2
	if [ "$STATUS" != "$want" ]; then ko "$desc"; return 1; fi
	for path in "$@"; do
		if ! jget "$path" > /dev/null; then ko "$desc: falta \"$path\""; return 1; fi
	done
	ok "$desc"
}

# eq DESCRIPCIÓN RUTA VALOR
eq() {
	local got
	got=$(jget "$2")
	if [ "$got" = "$3" ]; then ok "$1"; else ko "$1: \"$2\" = \"$got\", se esperaba \"$3\""; fi
}

echo "== Spiral-MCP smoke contra $WP_URL como $WP_USER"

# --- Usuario y rol -------------------------------------------------------
rest GET '/wp/v2/users/me&context=edit'
check 'autenticación con Application Password' 200 roles || exit 1
case "$(jget roles)" in
	*spiral_mcp_agent*) ok 'el usuario tiene el rol spiral_mcp_agent' ;;
	*) ko 'el usuario debe tener el rol spiral_mcp_agent' ;;
esac

# --- Registro ------------------------------------------------------------
rest GET '/wp-abilities/v1/abilities&per_page=100'
check 'listado de abilities' 200
for name in list-content get-content create-content update-content get-yootheme-layout update-yootheme-layout \
	list-menus get-menu create-menu update-menu delete-menu add-menu-item update-menu-item delete-menu-item \
	get-theme-layout update-theme-layout list-theme-backups restore-theme-backup list-widgets update-widget \
	list-activity list-snapshots restore-snapshot upload-media list-media get-media update-media delete-media \
	set-featured-image find-media-usage list-elements get-element-schema get-layout-outline get-layout-node \
	patch-layout duplicate-content get-theme-config update-theme-config list-templates get-template \
	create-template update-template delete-template list-library get-library-item save-to-library \
	insert-from-library clear-cache list-post-types trash-content restore-content delete-content \
	list-revisions restore-revision list-taxonomies list-terms create-term update-term delete-term \
	get-seo update-seo get-site-settings update-site-settings get-custom-css update-custom-css \
	get-theme-style list-style-variables update-theme-style get-style-status mark-style-compiled \
	get-preview-url revoke-preview-url inspect-page; do
	case "$BODY" in
		*"\"spiral-mcp\\/$name\""* | *"\"spiral-mcp/$name\""*) ;;
		*) ko "registrada spiral-mcp/$name" ;;
	esac
done
ok 'todas las abilities esperadas están registradas'

# --- Contenido -----------------------------------------------------------
call GET spiral-mcp/list-content '{"per_page":2}'
check 'list-content' 200 total items

call POST spiral-mcp/create-content '{"title":"Smoke test Spiral-MCP","status":"draft"}'
check 'create-content (borrador)' 200 id
PAGE_ID=$(jget id)

call GET spiral-mcp/get-content "{\"id\":$PAGE_ID}"
check 'get-content' 200 content has_yootheme

call POST spiral-mcp/update-content "{\"id\":$PAGE_ID,\"title\":\"Smoke test Spiral-MCP (editada)\"}"
check 'update-content' 200
eq 'update-content cambia el título' title 'Smoke test Spiral-MCP (editada)'

for fx in page-simple page-dynamic; do
	call POST spiral-mcp/update-yootheme-layout "{\"id\":$PAGE_ID,\"layout\":$(cat "$DIR/fixtures/$fx.json")}"
	check "update-yootheme-layout ($fx)" 200 updated
	call GET spiral-mcp/get-yootheme-layout "{\"id\":$PAGE_ID}"
	check "get-yootheme-layout ($fx)" 200 layout.children
	expected=$(php -r 'echo md5( json_encode( json_decode( file_get_contents( $argv[1] ), true ) ) );' "$DIR/fixtures/$fx.json")
	got=$(jget layout | php -r 'echo md5( json_encode( json_decode( stream_get_contents( STDIN ), true ) ) );')
	if [ "$expected" = "$got" ]; then ok "el layout ($fx) vuelve idéntico (barras, acentos, fuentes dinámicas)"; else ko "el layout ($fx) cambió al guardarlo"; fi
done

call GET spiral-mcp/get-yootheme-layout '{"id":999999999}'
[ "$STATUS" -ge 400 ] && ok 'get-yootheme-layout con ID inexistente devuelve error' || ko 'get-yootheme-layout con ID inexistente'

# --- Menús ---------------------------------------------------------------
call GET spiral-mcp/list-menus
check 'list-menus (sin input)' 200 menus locations

call POST spiral-mcp/create-menu "{\"name\":\"Smoke $(date +%s)\",\"items\":[{\"type\":\"custom\",\"title\":\"Inicio\",\"url\":\"/\"}]}"
check 'create-menu' 200 id snapshot_id
MENU_ID=$(jget id)

call GET spiral-mcp/get-menu "{\"menu\":$MENU_ID}"
check 'get-menu' 200 items
eq 'get-menu: 1 elemento' 'items.#' 1

call POST spiral-mcp/update-menu "{\"menu\":$MENU_ID,\"name\":\"Smoke renombrado $(date +%s)\"}"
check 'update-menu' 200 snapshot_id

call POST spiral-mcp/add-menu-item "{\"menu\":$MENU_ID,\"type\":\"custom\",\"title\":\"Contacto\",\"url\":\"/contacto/\"}"
check 'add-menu-item' 200 item_id snapshot_id
ITEM_ID=$(jget item_id)
SNAP_BEFORE_ADD=$(jget snapshot_id)

call POST spiral-mcp/update-menu-item "{\"item_id\":$ITEM_ID,\"title\":\"Contáctanos\",\"position\":1}"
check 'update-menu-item' 200 snapshot_id
eq 'update-menu-item lo pone primero' 'items.0.title' 'Contáctanos'

call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_BEFORE_ADD}"
[ "$STATUS" -ge 400 ] && ok 'restore-snapshot sin confirm se rechaza' || ko 'restore-snapshot sin confirm'

call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_BEFORE_ADD,\"confirm\":true}"
check 'restore-snapshot con cambios posteriores se rechaza (409)' 409 data.later.0
call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_BEFORE_ADD,\"confirm\":true,\"include_later\":true}"
check 'restore-snapshot (menú, include_later)' 200 snapshot_of_previous
call GET spiral-mcp/get-menu "{\"menu\":$MENU_ID}"
eq 'el menú vuelve a 1 elemento' 'items.#' 1

call POST spiral-mcp/add-menu-item "{\"menu\":$MENU_ID,\"type\":\"custom\",\"title\":\"Temporal\",\"url\":\"/temporal/\"}"
ITEM_ID=$(jget item_id)
call DELETE spiral-mcp/delete-menu-item "{\"item_id\":$ITEM_ID}"
check 'delete-menu-item' 200 deleted snapshot_id

call DELETE spiral-mcp/delete-menu "{\"menu\":$MENU_ID,\"confirm\":true}"
check 'delete-menu' 200 deleted snapshot_id
SNAP_DELETE=$(jget snapshot_id)

call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_DELETE,\"confirm\":true}"
check 'restore-snapshot recrea el menú borrado' 200 menu_id
MENU_ID=$(jget menu_id)
call DELETE spiral-mcp/delete-menu "{\"menu\":$MENU_ID,\"confirm\":true}"
check 'limpieza: borrar el menú recreado' 200 deleted

# --- Tema y widgets ------------------------------------------------------
call GET spiral-mcp/get-theme-layout
if [ "$STATUS" = 200 ]; then
	ok 'get-theme-layout'
	if [ "$(jget layout)" != '' ] && [ "$(jget layout)" != 'null' ]; then
		call POST spiral-mcp/update-theme-layout "{\"layout\":$(jget layout)}"
		check 'update-theme-layout (mismo layout)' 200 snapshot_id backup_id
		call POST spiral-mcp/restore-snapshot "{\"id\":$(jget snapshot_id),\"confirm\":true}"
		check 'restore-snapshot (theme_config)' 200 snapshot_of_previous
	else
		echo 'AVISO el footer no tiene layout: se omite update-theme-layout'
	fi
else
	echo "AVISO get-theme-layout devolvió $STATUS ($(jget code)): ¿YOOtheme activo? Se omite el footer."
fi

call GET spiral-mcp/list-theme-backups
check 'list-theme-backups (alias)' 200 backups

call GET spiral-mcp/list-widgets
check 'list-widgets' 200 areas
WIDGET_ID=$(printf '%s' "$BODY" | php -r '
	$d = json_decode( stream_get_contents( STDIN ), true );
	foreach ( $d["areas"] as $a ) { foreach ( $a["widgets"] as $w ) { if ( isset( $w["instance"]["title"] ) ) { echo $w["id"]; exit; } } }
')
if [ -n "$WIDGET_ID" ]; then
	call POST spiral-mcp/update-widget "{\"widget_id\":\"$WIDGET_ID\",\"instance\":{\"title\":\"Smoke\"}}"
	check "update-widget ($WIDGET_ID)" 200 snapshot_id
	call POST spiral-mcp/restore-snapshot "{\"id\":$(jget snapshot_id),\"confirm\":true}"
	check 'restore-snapshot (widgets)' 200
else
	echo 'AVISO no hay widgets con título: se omite update-widget'
fi

# --- Contenido completo: tipos, jerarquía, términos, revisiones y SEO ----
call GET spiral-mcp/list-post-types
check 'list-post-types' 200 post_types.0.name
call GET spiral-mcp/list-taxonomies
check 'list-taxonomies' 200 taxonomies.0.name
call POST spiral-mcp/create-term "{\"taxonomy\":\"category\",\"name\":\"Smoke $(date +%s)\"}"
check 'create-term' 200 id snapshot_id
TERM_ID=$(jget id)
call POST spiral-mcp/update-term "{\"term_id\":$TERM_ID,\"description\":\"Categoría de prueba\"}"
check 'update-term' 200 snapshot_id
call GET spiral-mcp/list-terms '{"taxonomy":"category","search":"Smoke"}'
check 'list-terms' 200 total items.0.id

FUTURE=$(php -r 'echo gmdate( "Y-m-d\TH:i:s\Z", time() + 7 * 86400 );')
call POST spiral-mcp/create-content "{\"title\":\"Smoke hija\",\"parent\":$PAGE_ID,\"status\":\"publish\",\"date\":\"$FUTURE\",\"menu_order\":2}"
check 'create-content (página hija programada)' 200 id parent template date_gmt
eq 'la página hija queda programada' status future
eq 'con su padre' parent "$PAGE_ID"
CHILD_ID=$(jget id)
call POST spiral-mcp/create-content "{\"post_type\":\"post\",\"title\":\"Smoke entrada\",\"status\":\"draft\",\"terms\":{\"category\":[$TERM_ID]}}"
check 'create-content (entrada con categoría)' 200 terms.category.0.id
POST_ID=$(jget id)

call POST spiral-mcp/update-content "{\"id\":$CHILD_ID,\"title\":\"Smoke hija v2\"}"
call POST spiral-mcp/update-content "{\"id\":$CHILD_ID,\"title\":\"Smoke hija v3\"}"
call GET spiral-mcp/list-revisions "{\"id\":$CHILD_ID}"
check 'list-revisions' 200 revisions.0.id
REV_ID=$(printf '%s' "$BODY" | php -r '$d = json_decode( stream_get_contents( STDIN ), true ); foreach ( $d["revisions"] as $r ) { if ( "Smoke hija v2" === $r["title"] ) { echo $r["id"]; break; } }')
call POST spiral-mcp/restore-revision "{\"revision_id\":${REV_ID:-0},\"confirm\":true}"
check 'restore-revision' 200 current_revision_id
eq 'vuelve a la v2' title 'Smoke hija v2'

call GET spiral-mcp/get-seo "{\"id\":$CHILD_ID}"
check 'get-seo' 200 provider title
call POST spiral-mcp/update-seo "{\"id\":$CHILD_ID,\"title\":\"Smoke | SEO\",\"description\":\"Descripción de prueba\",\"canonical\":\"/smoke/\"}"
check 'update-seo' 200 snapshot_id canonical
eq 'update-seo: título' title 'Smoke | SEO'

call POST spiral-mcp/trash-content "{\"id\":$POST_ID}"
eq 'trash-content' status trash
call POST spiral-mcp/restore-content "{\"id\":$POST_ID}"
eq 'restore-content (vuelve a borrador)' status draft
call POST spiral-mcp/trash-content "{\"id\":$POST_ID}"
call DELETE spiral-mcp/delete-content "{\"id\":$POST_ID,\"confirm\":true}"
check 'delete-content (desde la papelera)' 200 deleted
call POST spiral-mcp/trash-content "{\"id\":$CHILD_ID}"
call DELETE spiral-mcp/delete-content "{\"id\":$CHILD_ID,\"confirm\":true}"
check 'limpieza: borrar la página hija' 200 deleted
call DELETE spiral-mcp/delete-term "{\"term_id\":$TERM_ID,\"confirm\":true}"
check 'delete-term' 200 deleted snapshot_id

call GET spiral-mcp/get-site-settings
check 'get-site-settings' 200 blogname show_on_front
call POST spiral-mcp/update-site-settings '{"blogdescription":"Lema de prueba smoke"}'
check 'update-site-settings' 200 snapshot_id
eq 'update-site-settings: lema' blogdescription 'Lema de prueba smoke'
call POST spiral-mcp/restore-snapshot "{\"id\":$(jget snapshot_id),\"confirm\":true}"
check 'restore-snapshot (ajustes del sitio)' 200

# --- Catálogo de elementos y edición por partes --------------------------
call GET spiral-mcp/list-elements
check 'list-elements' 200 total elements.0.name version
call GET spiral-mcp/get-element-schema '{"type":"headline"}'
check 'get-element-schema (headline)' 200 props.content props.title_element.options

call POST spiral-mcp/update-yootheme-layout "{\"id\":$PAGE_ID,\"layout\":$(cat "$DIR/fixtures/page-simple.json")}"
check 'layout de partida para patch-layout' 200 warnings
call POST spiral-mcp/update-yootheme-layout "{\"id\":$PAGE_ID,\"layout\":{\"type\":\"layout\",\"children\":[{\"type\":\"row\"}]}}"
eq 'update-yootheme-layout rechaza un row fuera de section' code spiral_mcp_invalid_layout

call GET spiral-mcp/get-layout-outline "{\"id\":$PAGE_ID}"
check 'get-layout-outline' 200 nodes outline.0
HEAD='/children/0/children/0/children/0/children/0'
call GET spiral-mcp/get-layout-node "{\"id\":$PAGE_ID,\"path\":\"$HEAD\"}"
eq 'get-layout-node: headline' node.type headline

call POST spiral-mcp/patch-layout "{\"id\":$PAGE_ID,\"operations\":[{\"op\":\"test\",\"path\":\"$HEAD/props/content\",\"value\":\"Página de prueba\"},{\"op\":\"replace\",\"path\":\"$HEAD/props/content\",\"value\":\"Título con patch\"},{\"op\":\"copy\",\"from\":\"/children/0\",\"path\":\"/children/-\"}]}"
check 'patch-layout (test + replace + copy)' 200 updated warnings
call GET spiral-mcp/get-layout-node "{\"id\":$PAGE_ID,\"path\":\"$HEAD/props/content\"}"
eq 'patch-layout cambió el título' node 'Título con patch'
call POST spiral-mcp/patch-layout "{\"id\":$PAGE_ID,\"operations\":[{\"op\":\"replace\",\"path\":\"$HEAD/props/content\",\"value\":\"NO\"},{\"op\":\"remove\",\"path\":\"/children/99\"}]}"
[ "$STATUS" -ge 400 ] && ok 'patch-layout es atómico (una op falla, nada se guarda)' || ko 'patch-layout debería fallar'
call GET spiral-mcp/get-layout-node "{\"id\":$PAGE_ID,\"path\":\"$HEAD/props/content\"}"
eq 'el título no cambió tras el patch fallido' node 'Título con patch'

call POST spiral-mcp/duplicate-content "{\"id\":$PAGE_ID}"
check 'duplicate-content' 200 id source_id
eq 'duplicate-content: la copia es un borrador' status draft
DUP_ID=$(jget id)
rest DELETE "/wp/v2/pages/$DUP_ID&force=true"
check 'limpieza: borrar la copia' 200

# --- Configuración de YOOtheme, footer, plantillas y biblioteca ----------
call GET spiral-mcp/get-theme-config
YOOTHEME=$STATUS
if [ "$YOOTHEME" = 200 ]; then
	ok 'get-theme-config (secciones)'
	LEAK=$(printf '%s' "$BODY" | php -r '$d = json_decode( stream_get_contents( STDIN ), true ); foreach ( $d["sections"] as $x ) { if ( "yootheme_apikey" === $x["section"] && isset( $x["value"] ) ) { echo "si"; } }')
	[ -z "$LEAK" ] && ok 'la apikey no sale en el listado' || ko 'la apikey no debe salir en el listado'
	call GET spiral-mcp/get-theme-config '{"section":"header"}'
	check 'get-theme-config (header)' 200 value defaults
	ORIG_LAYOUT=$(jget value.layout)
	call POST spiral-mcp/update-theme-config '{"section":"header","values":{"layout":"stacked-center-a"}}'
	check 'update-theme-config (header, merge)' 200 snapshot_id
	eq 'header.layout cambiado' value.layout stacked-center-a
	SNAP_CFG=$(jget snapshot_id)
	call POST spiral-mcp/update-theme-config '{"section":"yootheme_apikey","values":"x"}'
	[ "$STATUS" -ge 400 ] && ok 'yootheme_apikey no es editable' || ko 'yootheme_apikey no debería ser editable'

	call POST spiral-mcp/update-theme-layout "{\"area\":\"footer\",\"layout\":$(cat "$DIR/fixtures/footer.json")}"
	check 'update-theme-layout (footer)' 200 snapshot_id
	call POST spiral-mcp/patch-layout '{"area":"footer","operations":[{"op":"replace","path":"/children/0/children/0/children/0/children/0/props/content","value":"<p>Footer con patch</p>"}]}'
	check 'patch-layout (footer)' 200 snapshot_id
	call GET spiral-mcp/get-theme-layout '{"area":"footer"}'
	check 'get-theme-layout (footer, con áreas)' 200 layout.children areas.0.area

	call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_CFG,\"confirm\":true,\"parts\":[\"header\"]}"
	check 'restore-snapshot parcial (solo header)' 200 parts.0
	call GET spiral-mcp/get-layout-outline '{"area":"footer"}'
	check 'restore parcial: el footer de la prueba sigue' 200 outline.0
	call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_CFG,\"confirm\":true,\"include_later\":true}"
	check 'restore-snapshot (configuración antes de la prueba)' 200
	call GET spiral-mcp/get-theme-config '{"section":"header"}'
	eq 'header.layout restaurado' value.layout "$ORIG_LAYOUT"
else
	echo "AVISO get-theme-config devolvió $STATUS: ¿YOOtheme activo? Se omiten configuración, footer, plantillas y biblioteca."
fi

if [ "$YOOTHEME" = 200 ]; then
	call GET spiral-mcp/list-templates
	check 'list-templates (con tipos)' 200 templates types.single-post
	call POST spiral-mcp/create-template "{\"name\":\"Smoke\",\"type\":\"single-post\",\"status\":\"disabled\",\"layout\":$(cat "$DIR/fixtures/page-simple.json")}"
	check 'create-template (desactivada)' 200 id snapshot_id
	TPL_ID=$(jget id)
	call POST spiral-mcp/patch-layout "{\"area\":\"template:$TPL_ID\",\"operations\":[{\"op\":\"replace\",\"path\":\"$HEAD/props/content\",\"value\":\"Plantilla con patch\"}]}"
	check 'patch-layout (plantilla)' 200
	call GET spiral-mcp/get-template "{\"id\":\"$TPL_ID\"}"
	eq 'get-template refleja el patch' "layout.children.0.children.0.children.0.children.0.props.content" 'Plantilla con patch'
	call POST spiral-mcp/create-template '{"name":"x","type":"tipo-inventado"}'
	[ "$STATUS" -ge 400 ] && ok 'create-template rechaza tipos inexistentes' || ko 'create-template debería rechazar el tipo'
	call DELETE spiral-mcp/delete-template "{\"id\":\"$TPL_ID\",\"confirm\":true}"
	check 'delete-template' 200 deleted

	call POST spiral-mcp/save-to-library "{\"id\":$PAGE_ID,\"path\":\"/children/0\",\"name\":\"Smoke sección\"}"
	check 'save-to-library (sección)' 200 id snapshot_id
	LIB_ID=$(jget id)
	SNAP_LIB=$(jget snapshot_id)
	call GET spiral-mcp/list-library
	check 'list-library' 200 items.0.id
	call POST spiral-mcp/insert-from-library "{\"item_id\":\"$LIB_ID\",\"id\":$PAGE_ID,\"path\":\"/children/-\"}"
	check 'insert-from-library' 200 updated
	call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_LIB,\"confirm\":true}"
	check 'limpieza: restaurar la biblioteca' 200
fi

call POST spiral-mcp/clear-cache
check 'clear-cache' 200 cleared

# --- Estilos -------------------------------------------------------------
call GET spiral-mcp/get-custom-css
check 'get-custom-css' 200 css stylesheet
call POST spiral-mcp/update-custom-css '{"css":".smoke-test{color:red}","mode":"append"}'
check 'update-custom-css (append)' 200 snapshot_id
SNAP_CSS=$(jget snapshot_id)
call POST spiral-mcp/update-custom-css '{"css":"</style><script>x</script>"}'
[ "$STATUS" -ge 400 ] && ok 'update-custom-css rechaza HTML' || ko 'update-custom-css debería rechazar HTML'
call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_CSS,\"confirm\":true}"
check 'restore-snapshot (CSS adicional)' 200

if [ "$YOOTHEME" = 200 ]; then
	call GET spiral-mcp/get-theme-style '{"include_styles":true}'
	check 'get-theme-style' 200 style styles status.css
	call GET spiral-mcp/list-style-variables '{"component":"global","search":"primary-background"}'
	check 'list-style-variables' 200 total items.0.default
	call POST spiral-mcp/update-theme-style '{"less":{"@global-primary-background":"#e63946"}}'
	check 'update-theme-style' 200 snapshot_id recompile_url
	eq 'update-theme-style: pendiente de recompilar' needs_recompile true
	SNAP_STYLE=$(jget snapshot_id)
	call POST spiral-mcp/update-theme-style '{"less":{"@global-primary-background":"red; } body { x"}}'
	[ "$STATUS" -ge 400 ] && ok 'update-theme-style rechaza valores con ; o llaves' || ko 'update-theme-style debería rechazar el valor'
	call POST spiral-mcp/restore-snapshot "{\"id\":$SNAP_STYLE,\"confirm\":true}"
	check 'restore-snapshot (estilo)' 200
	call POST spiral-mcp/mark-style-compiled '{}'
	[ "$STATUS" -ge 400 ] && ok 'mark-style-compiled exige force si el CSS es anterior' || ko 'mark-style-compiled debería exigir force'
	# El CSS no se tocó: el estilo restaurado coincide con el CSS compilado.
	call POST spiral-mcp/mark-style-compiled '{"force":true}'
	eq 'mark-style-compiled (force)' needs_recompile false
	call GET spiral-mcp/get-style-status
	check 'get-style-status' 200 css.version recompile_url
fi

# --- Verificación: vista previa sin sesión e inspección ------------------
call POST spiral-mcp/create-content '{"title":"Smoke vista previa","content":"<h1>SMOKE-PREVIEW</h1>","status":"draft"}'
check 'borrador para la vista previa' 200 id
PREV_ID=$(jget id)
call POST spiral-mcp/get-preview-url "{\"id\":$PREV_ID,\"hours\":1}"
check 'get-preview-url' 200 url token_id expires_at
# La URL usa el dominio del sitio: se pide a WP_URL con la misma query.
PREV_URL="${WP_URL%/}/?$(jget url | php -r 'echo parse_url( stream_get_contents( STDIN ), PHP_URL_QUERY );')"
ANON=$(curl -sk "$PREV_URL")
case "$ANON" in *SMOKE-PREVIEW*) ok 'la vista previa se ve sin sesión' ;; *) ko 'la vista previa debería verse sin sesión' ;; esac
ANON=$(curl -sk "$(printf '%s' "$PREV_URL" | sed 's/spiral_preview=[^&]*/spiral_preview=1.9999999999.x.y/')")
case "$ANON" in *SMOKE-PREVIEW*) ko 'un token falso no debería abrir el borrador' ;; *) ok 'un token falso no abre el borrador' ;; esac
call POST spiral-mcp/revoke-preview-url "{\"id\":$PREV_ID}"
eq 'revoke-preview-url' revoked 1
ANON=$(curl -sk "$PREV_URL")
case "$ANON" in *SMOKE-PREVIEW*) ko 'el token revocado no debería servir' ;; *) ok 'el token revocado ya no sirve' ;; esac
if [ "${SMOKE_INSPECT:-0}" = 1 ]; then
	# inspect-page pide la página al propio servidor: no funciona con servidores de un solo hilo (php -S).
	call GET spiral-mcp/inspect-page "{\"id\":$PREV_ID,\"check_links\":false}"
	check 'inspect-page' 200 status headings seo warnings
else
	echo 'AVISO inspect-page omitido (SMOKE_INSPECT=1 para probarlo; necesita un servidor que se alcance a sí mismo)'
fi
call POST spiral-mcp/trash-content "{\"id\":$PREV_ID}"
call DELETE spiral-mcp/delete-content "{\"id\":$PREV_ID,\"confirm\":true}"
check 'limpieza: borrar el borrador de la vista previa' 200 deleted

# --- Medios --------------------------------------------------------------
PIXEL='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
call POST spiral-mcp/upload-media "{\"data_base64\":\"$PIXEL\",\"filename\":\"smoke-pixel.png\",\"alt\":\"Píxel de prueba\",\"attach_to\":$PAGE_ID}"
check 'upload-media (base64)' 200 id path_relative sizes
MEDIA_ID=$(jget id)
eq 'upload-media: alt' alt 'Píxel de prueba'

call POST spiral-mcp/upload-media '{"url":"https://127.0.0.1/x.png"}'
[ "$(jget code)" = spiral_mcp_bad_url ] && ok 'upload-media rechaza URLs locales (SSRF)' || ko 'upload-media debería rechazar https://127.0.0.1'
call POST spiral-mcp/upload-media "{\"data_base64\":\"$(printf '<svg xmlns="http://www.w3.org/2000/svg"/>' | base64 | tr -d '\n')\",\"filename\":\"x.svg\"}"
[ "$STATUS" -ge 400 ] && ok 'upload-media rechaza SVG' || ko 'upload-media debería rechazar SVG'

if [ -n "${SMOKE_MEDIA_URL-https://s.w.org/style/images/about/WordPress-logotype-wmark.png}" ]; then
	call POST spiral-mcp/upload-media "{\"url\":\"${SMOKE_MEDIA_URL-https://s.w.org/style/images/about/WordPress-logotype-wmark.png}\",\"alt\":\"Prueba URL\"}"
	if check 'upload-media (URL https)' 200 id width; then
		URL_MEDIA_ID=$(jget id)
		call DELETE spiral-mcp/delete-media "{\"id\":$URL_MEDIA_ID,\"confirm\":true}"
		check 'limpieza: borrar la imagen descargada' 200 deleted
	fi
fi

call GET spiral-mcp/list-media "{\"parent\":$PAGE_ID}"
check 'list-media (por post)' 200 total items.0.id
call GET spiral-mcp/get-media "{\"id\":$MEDIA_ID}"
check 'get-media' 200 path_relative

call POST spiral-mcp/update-media "{\"id\":$MEDIA_ID,\"alt\":\"Alt cambiado\"}"
check 'update-media' 200 snapshot_id
eq 'update-media: alt nuevo' alt 'Alt cambiado'
call POST spiral-mcp/restore-snapshot "{\"id\":$(jget snapshot_id),\"confirm\":true}"
check 'restore-snapshot (media_meta)' 200
call GET spiral-mcp/get-media "{\"id\":$MEDIA_ID}"
eq 'el alt vuelve al original' alt 'Píxel de prueba'

call POST spiral-mcp/set-featured-image "{\"post_id\":$PAGE_ID,\"media_id\":$MEDIA_ID}"
check 'set-featured-image' 200 snapshot_id media.id
call GET spiral-mcp/find-media-usage "{\"id\":$MEDIA_ID}"
check 'find-media-usage' 200 used posts
eq 'find-media-usage: la página la usa como destacada' 'posts.0.where.0' featured_image

call DELETE spiral-mcp/delete-media "{\"id\":$MEDIA_ID}"
[ "$STATUS" -ge 400 ] && ok 'delete-media sin confirm se rechaza' || ko 'delete-media sin confirm'
call DELETE spiral-mcp/delete-media "{\"id\":$MEDIA_ID,\"confirm\":true}"
check 'delete-media' 200 deleted

# --- Auditoría y snapshots -----------------------------------------------
call GET spiral-mcp/list-snapshots '{"kind":"menu","per_page":5}'
check 'list-snapshots' 200 total items.0.id

call GET spiral-mcp/list-activity "{\"ability\":\"spiral-mcp/update-content\",\"object_id\":\"$PAGE_ID\"}"
check 'list-activity registra update-content' 200 items.0.id
eq 'list-activity: resultado ok' 'items.0.result' ok

call GET spiral-mcp/list-activity '{"ability":"spiral-mcp/get-menu"}'
eq 'list-activity no registra lecturas' total 0

# --- Permisos ------------------------------------------------------------
rest GET '/wp/v2/plugins'
if [ "$STATUS" = 401 ] || [ "$STATUS" = 403 ]; then ok 'el agente no puede gestionar plugins'; else ko 'el agente no debería ver /wp/v2/plugins'; fi
rest GET '/wp/v2/settings'
if [ "$STATUS" = 401 ] || [ "$STATUS" = 403 ]; then ok 'el agente no puede cambiar los ajustes generales'; else ko 'el agente no debería ver /wp/v2/settings'; fi

# --- Limpieza ------------------------------------------------------------
rest DELETE "/wp/v2/pages/$PAGE_ID&force=true"
check 'limpieza: borrar la página de prueba' 200

echo
echo "== $PASS correctas, $FAIL fallidas"
[ "$FAIL" -eq 0 ]
