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
	set-featured-image find-media-usage; do
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
check 'restore-snapshot (menú)' 200 snapshot_of_previous
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
