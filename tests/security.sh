#!/usr/bin/env bash
# Pruebas de seguridad (docs/pruebas-seguridad.md): baterías A1 (anónimo) y A2 (cerrojos del
# agente, código, secretos y entradas malformadas). Contra un WordPress LOCAL, nunca en producción.
#
# Uso:
#   SEC_ENV=/ruta/creds.env WP_PATH=D:/web-data/www/wordpress-dev bash tests/security.sh
#
# creds.env (no lo versiones) define:
#   WP_URL, AGENTE_USER/AGENTE_PASS (rol spiral_mcp_agent), AGENTE_MIN_USER/AGENTE_MIN_PASS (rol con las
#   nativas del agente y sin ninguna spiral_mcp_*), SUSCRIPTOR_USER/SUSCRIPTOR_PASS (subscriber),
#   PAGE_DRAFT, PAGE_PRIVATE, PAGE_PUBLISH, PAGE_PASSWORD (IDs de páginas del admin en esos estados;
#   PAGE_PASSWORD con contraseña y el texto TEXTO-PROTEGIDO en el cuerpo).
#
# WP_PATH: raíz del WordPress local; el script ejecuta PHP dentro de WordPress para cambiar los
# cerrojos (opciones spiral_mcp_agent_caps, spiral_mcp_settings, spiral_mcp_disabled_abilities) y los
# restaura al salir. Deja un mu-plugin temporal (sec-sim.php) solo mientras corre.
#
# Resultado por prueba: OK (lo esperado), FALLO (hallazgo) o INFO (comportamiento a decidir).

set -u
: "${SEC_ENV:?Falta SEC_ENV (archivo con las credenciales)}"
: "${WP_PATH:?Falta WP_PATH (raíz del WordPress local)}"
# shellcheck disable=SC1090
. "$SEC_ENV"
: "${WP_URL:?}" "${AGENTE_USER:?}" "${AGENTE_PASS:?}" "${AGENTE_MIN_USER:?}" "${AGENTE_MIN_PASS:?}" "${SUSCRIPTOR_USER:?}" "${SUSCRIPTOR_PASS:?}"
: "${PAGE_DRAFT:?}" "${PAGE_PRIVATE:?}" "${PAGE_PUBLISH:?}" "${PAGE_PASSWORD:?}"

WP_URL=${WP_URL%/}
HOST=$(printf '%s' "$WP_URL" | sed -E 's#^https?://##')
AGENTE="$AGENTE_USER:$AGENTE_PASS"
MIN="$AGENTE_MIN_USER:$AGENTE_MIN_PASS"
SUB="$SUSCRIPTOR_USER:$SUSCRIPTOR_PASS"
TMP="$(mktemp)"; TMP_BODY="$(mktemp)"; TMP_HDR="$(mktemp)"; TMP_STATE="$(mktemp)"; TMP_PNG="$(mktemp).png"
PASS=0; FAIL=0; INFO=0
STATUS=''; BODY=''
MEDIA_IDS=''; POST_IDS=''
MU="$WP_PATH/wp-content/mu-plugins/sec-sim.php"

# 1x1 PNG para las subidas.
printf 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' | base64 -d > "$TMP_PNG"

# --- utilidades --------------------------------------------------------------

# wpeval 'código PHP': lo ejecuta dentro del WordPress local y devuelve su salida.
wpeval() {
	php -r '
		$_SERVER["HTTP_HOST"] = $argv[2]; $_SERVER["SERVER_NAME"] = $argv[2]; $_SERVER["HTTPS"] = "on";
		$_SERVER["REQUEST_URI"] = "/"; $_SERVER["REQUEST_METHOD"] = "GET";
		require $argv[1] . "/wp-load.php";
		eval( $argv[3] );
	' "$WP_PATH" "$HOST" "$1" 2>&1
}

# req USUARIO(anon|user:pass) METODO RUTA_REST [CUERPO_JSON] [args extra de curl...] -> STATUS, BODY
req() {
	local user=$1 method=$2 route=$3 body=${4:-}
	shift 3; [ $# -gt 0 ] && shift
	local args=(-sk -o "$TMP" -D "$TMP_HDR" -w '%{http_code}' -X "$method")
	[ "$user" != anon ] && args+=(-u "$user")
	if [ -n "$body" ]; then
		printf '%s' "$body" > "$TMP_BODY"
		args+=(-H 'Content-Type: application/json' --data-binary "@$TMP_BODY")
	fi
	STATUS=$(curl "${args[@]}" "$@" "$WP_URL/?rest_route=$route")
	BODY=$(cat "$TMP")
}

# call USUARIO METODO ABILITY [JSON_INPUT]: GET/DELETE mandan input[...] en la query; POST en el cuerpo.
call() {
	local user=$1 method=$2 name=$3 input=${4:-}
	local route="/wp-abilities/v1/abilities/$name/run"
	if [ "$method" = POST ]; then
		[ -z "$input" ] && input='{}'
		req "$user" POST "$route" "{\"input\":$input}"
	else
		if [ -n "$input" ]; then
			route="$route&$(printf '%s' "$input" | php -r 'echo http_build_query( array( "input" => json_decode( stream_get_contents( STDIN ), true ) ) );')"
		fi
		req "$user" "$method" "$route"
	fi
}

# mcp USUARIO METODO_JSONRPC [PARAMS_JSON]: una petición al servidor por defecto del MCP Adapter.
MCP_SID=''; MCP_SID_USER=''
mcp() {
	local user=$1 method=$2 params=${3:-\{\}}
	local route="/mcp/mcp-adapter-default-server" accept='Accept: application/json, text/event-stream' proto='MCP-Protocol-Version: 2025-06-18'
	if [ "$method" != initialize ] && [ "$MCP_SID_USER" != "$user" ]; then
		req "$user" POST "$route" '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"sec","version":"1"}}}' -H "$accept" -H "$proto"
		MCP_SID=$(grep -i '^mcp-session-id:' "$TMP_HDR" | head -1 | sed 's/^[^:]*: *//' | tr -d '\r'); MCP_SID_USER=$user
		[ -z "$MCP_SID" ] && { STATUS=$STATUS; return; }
	fi
	req "$user" POST "$route" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$method\",\"params\":$params}" -H "$accept" -H "$proto" ${MCP_SID:+-H "Mcp-Session-Id: $MCP_SID"}
}

# raw USUARIO METODO URL_COMPLETA [args curl...]
raw() {
	local user=$1 method=$2 url=$3; shift 3
	local args=(-sk -o "$TMP" -D "$TMP_HDR" -w '%{http_code}' -X "$method")
	[ "$user" != anon ] && args+=(-u "$user")
	STATUS=$(curl "${args[@]}" "$@" "$url")
	BODY=$(cat "$TMP")
}

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
	' "$1" 2>/dev/null
}

ok()   { PASS=$((PASS + 1)); printf 'OK    %s\n' "$1"; }
ko()   { FAIL=$((FAIL + 1)); printf 'FALLO %s (HTTP %s) %s\n' "$1" "$STATUS" "$(printf '%s' "$BODY" | tr '\n' ' ' | head -c 220)"; }
info() { INFO=$((INFO + 1)); printf 'INFO  %s (HTTP %s) %s\n' "$1" "$STATUS" "$(printf '%s' "$BODY" | tr '\n' ' ' | head -c 220)"; }
skip() { printf 'SALTO %s\n' "$1"; }

# expect ID "descripción" STATUS_ESPERADO[,otro] [CODIGO_ERROR_ESPERADO]
expect() {
	local id=$1 desc=$2 statuses=$3 code=${4:-}
	local match=0 s
	for s in ${statuses//,/ }; do [ "$STATUS" = "$s" ] && match=1; done
	if [ $match -eq 1 ] && { [ -z "$code" ] || [ "$(jget code)" = "$code" ]; }; then ok "$id $desc"
	elif [ "$STATUS" = 500 ] && [ -n "$code" ] && [ "$(jget code)" = "$code" ]; then info "$id $desc: rechazado, pero con HTTP 500 (WP_Error sin status)"
	else ko "$id $desc (esperado HTTP $statuses${code:+ código $code})"; fi
}

# --- estado inicial y restauración ------------------------------------------

wpeval 'echo json_encode( array( "caps" => get_option( "spiral_mcp_agent_caps", array() ), "settings" => get_option( "spiral_mcp_settings", array() ), "disabled" => get_option( "spiral_mcp_disabled_abilities", array() ) ) );' > "$TMP_STATE"
restore_state() {
	local state; state=$(cat "$TMP_STATE")
	SEC_JSON="$state" wpeval '$s = json_decode( (string) getenv( "SEC_JSON" ), true ); update_option( "spiral_mcp_agent_caps", $s["caps"] ); update_option( "spiral_mcp_settings", $s["settings"] ); update_option( "spiral_mcp_disabled_abilities", $s["disabled"] ); spiral_mcp_install_caps(); delete_transient( "spiral_sec_sim" );' > /dev/null
	[ -f "$MU" ] && rm -f "$MU"
	if [ -n "$MEDIA_IDS$POST_IDS" ]; then
		wpeval 'foreach ( array_filter( explode( " ", "'"$MEDIA_IDS"'" ) ) as $id ) { wp_delete_attachment( (int) $id, true ); } foreach ( array_filter( explode( " ", "'"$POST_IDS"'" ) ) as $id ) { wp_delete_post( (int) $id, true ); }' > /dev/null
	fi
	rm -f "$TMP" "$TMP_BODY" "$TMP_HDR" "$TMP_STATE" "$TMP_PNG"
}
trap restore_state EXIT

# set_caps '{"spiral_mcp_cache":false,...}' : fija la elección de caps propias del rol y sincroniza el rol.
set_caps() { SEC_JSON="$1" wpeval 'update_option( "spiral_mcp_agent_caps", (array) json_decode( (string) getenv( "SEC_JSON" ), true ) ); spiral_mcp_install_caps();' > /dev/null; }
# set_setting clave valor_php
set_setting() { wpeval '$s = (array) get_option( "spiral_mcp_settings", array() ); $s["'"$1"'"] = '"$2"'; update_option( "spiral_mcp_settings", $s );' > /dev/null; }

# Mu-plugin de simulación: con el transient spiral_sec_sim = badhost, wordpress.org "devuelve" un ZIP de otro host.
mkdir -p "$WP_PATH/wp-content/mu-plugins"
cat > "$MU" <<'PHP'
<?php
// Temporal: lo crea y borra tests/security.sh de Spiral-MCP-Control.
add_filter( 'plugins_api_result', function ( $res, $action, $args ) {
	if ( 'badhost' === get_transient( 'spiral_sec_sim' ) && is_object( $res ) && isset( $res->download_link ) ) {
		$res->download_link = 'https://evil.example.invalid/x.zip';
	}
	return $res;
}, 10, 3 );
PHP

printf '== Spiral-MCP-Control: pruebas de seguridad A1 y A2 contra %s ==\n' "$WP_URL"

# =============================================================================
# A1. Anónimo
# =============================================================================

req anon GET "/wp-abilities/v1/abilities"
expect A1-01a "listado de abilities sin credenciales" 401
call anon GET spiral-mcp/list-content '{"type":"page"}'
expect A1-01b "ejecutar list-content sin credenciales" 401

mcp anon initialize '{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"sec","version":"1"}}'
expect A1-02a "MCP initialize sin credenciales" 401
mcp anon tools/list
expect A1-02b "MCP tools/list sin credenciales" 401
mcp "$SUB" tools/list
if [ "$STATUS" = 200 ]; then
	info "A1-02c MCP tools/list con un suscriptor: el transporte solo exige 'read' (filtro mcp_adapter_default_transport_permission_user_capability); herramientas: $(jget result.tools.#)"
else
	ok "A1-02c MCP tools/list con un suscriptor rechazado (HTTP $STATUS)"
fi
mcp "$SUB" tools/call '{"name":"mcp-adapter-discover-abilities","arguments":{}}'
if [ "$STATUS" = 200 ] && printf '%s' "$BODY" | grep -q 'spiral-mcp/'; then
	ko "A1-02d discover-abilities como suscriptor lista abilities spiral-mcp/*"
else
	ok "A1-02d discover-abilities como suscriptor no revela abilities spiral-mcp/* (HTTP $STATUS)"
fi

raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload" -F "file=@$TMP_PNG"
expect A1-03a "subida sin token" 403
raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload" -H "X-Spiral-Upload-Token: $(printf 'A%.0s' $(seq 40))" -F "file=@$TMP_PNG"
expect A1-03b "subida con token inventado de 40 caracteres" 403
raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload" -H "X-Spiral-Upload-Token: ../../etc/passwd" -F "file=@$TMP_PNG"
expect A1-03c "subida con token de formato raro" 403,404
raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload/$(printf 'B%.0s' $(seq 39))" -F "file=@$TMP_PNG"
expect A1-03d "subida con token de 39 caracteres en la ruta" 403,404

# A1-04: un solo uso y carrera.
call "$AGENTE" POST spiral-mcp/create-upload-url '{"filename":"sec-a104.png","alt":"sec"}'
TOKEN=$(jget token); UPURL=$(jget upload_url)
if [ -z "$TOKEN" ]; then ko "A1-04 create-upload-url no devolvió token"; else
	raw anon POST "$UPURL" -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG"
	expect A1-04a "primera subida con el token" 201
	MEDIA_IDS="$MEDIA_IDS $(jget id)"
	raw anon POST "$UPURL" -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG"
	expect A1-04b "segunda subida con el mismo token" 403
	call "$AGENTE" POST spiral-mcp/create-upload-url '{"filename":"sec-a104c.png","alt":"sec"}'
	TOKEN=$(jget token); UPURL=$(jget upload_url)
	R1="$(mktemp)"; R2="$(mktemp)"
	curl -sk -o "$R1" -w '%{http_code}\n' -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG" "$UPURL" > "$R1.code" &
	curl -sk -o "$R2" -w '%{http_code}\n' -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG" "$UPURL" > "$R2.code" &
	wait
	C1=$(cat "$R1.code"); C2=$(cat "$R2.code")
	for f in "$R1" "$R2"; do id=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["id"]??"";' "$f"); [ -n "$id" ] && MEDIA_IDS="$MEDIA_IDS $id"; done
	STATUS="$C1/$C2"; BODY=''
	if [ "$C1" = 201 ] && [ "$C2" = 201 ]; then ko "A1-04c dos subidas CONCURRENTES con el mismo token: las dos entraron (carrera entre leer y borrar el transient)"; else ok "A1-04c dos subidas concurrentes con el mismo token: solo una entró ($C1/$C2)"; fi
	rm -f "$R1" "$R2" "$R1.code" "$R2.code"
fi

# A1-05: caducidad (el esquema exige >= 60 s: se crea uno de 1 s desde PHP).
TOKEN=$(wpeval 'list( $t ) = spiral_mcp_upload_create( array( "filename" => "sec-a105.png" ), 0, 1 ); echo $t;')
sleep 2
raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload" -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG"
expect A1-05 "token de subida caducado" 403

# A1-06: el usuario pierde spiral_mcp_media entre crear el enlace y usarlo.
call "$AGENTE" POST spiral-mcp/create-upload-url '{"filename":"sec-a106.png","alt":"sec"}'
TOKEN=$(jget token); UPURL=$(jget upload_url)
set_caps '{"spiral_mcp_media":false}'
raw anon POST "$UPURL" -H "X-Spiral-Upload-Token: $TOKEN" -F "file=@$TMP_PNG"
expect A1-06 "subida con token tras quitar spiral_mcp_media al rol" 403 spiral_mcp_forbidden
[ "$STATUS" = 201 ] && MEDIA_IDS="$MEDIA_IDS $(jget id)"
set_caps "$(php -r '$s=json_decode(stream_get_contents(STDIN),true); echo json_encode((object)$s["caps"]);' < "$TMP_STATE")"

# A1-07: token en la ruta (queda en los logs del servidor).
call "$AGENTE" POST spiral-mcp/create-upload-url '{"filename":"sec-a107.png","alt":"sec"}'
TOKEN=$(jget token)
raw anon POST "$WP_URL/?rest_route=/spiral-mcp/v1/upload/$TOKEN" -F "file=@$TMP_PNG"
[ "$STATUS" = 201 ] && MEDIA_IDS="$MEDIA_IDS $(jget id)"
if [ "$STATUS" = 201 ]; then info "A1-07 el token de subida también vale en la URL (/upload/<token>): queda en los logs de acceso; valorar solo cabecera"; else ok "A1-07 el token solo se acepta en la cabecera (HTTP $STATUS)"; fi

# A1-08..A1-11: vista previa sin sesión.
call "$AGENTE" POST spiral-mcp/get-preview-url "{\"id\":$PAGE_DRAFT}"
PURL=$(jget url); PTOKEN_ID=$(jget token_id)
PTOKEN=$(printf '%s' "$PURL" | sed -n 's/.*spiral_preview=\([^&]*\).*/\1/p' | php -r 'echo rawurldecode( trim( stream_get_contents( STDIN ) ) );')
PBASE=$(printf '%s' "$PURL" | sed 's/[?&]spiral_preview=[^&]*//')
sep='?'; case "$PBASE" in *\?*) sep='&';; esac
if [ -z "$PTOKEN" ]; then ko "A1-08 get-preview-url no devolvió token"; else
	raw anon GET "$PURL"
	expect A1-08a "preview del borrador con token válido" 200
	raw anon GET "$PBASE"
	expect A1-08b "borrador sin token" 404
	raw anon GET "${PBASE}${sep}spiral_preview=${PTOKEN%?}x"
	expect A1-08c "token con la firma alterada" 404
	IFS=. read -r _pid _exp _nonce _sig <<< "$PTOKEN"
	raw anon GET "${PBASE}${sep}spiral_preview=$PAGE_PRIVATE.$_exp.$_nonce.$_sig"
	expect A1-08d "token con el ID de post cambiado" 404
	raw anon GET "${PBASE}${sep}spiral_preview=$_pid.$((_exp + 86400)).$_nonce.$_sig"
	expect A1-08e "token con la caducidad alargada" 404
	ETOKEN=$(wpeval 'list( $t ) = spiral_mcp_preview_create( '"$PAGE_DRAFT"', -10 ); echo $t;')
	raw anon GET "${PBASE}${sep}spiral_preview=$ETOKEN"
	expect A1-08f "token caducado (firma válida)" 404
	# A1-10: cabeceras.
	raw anon GET "$PURL"
	if grep -qi '^cache-control:.*no-cache' "$TMP_HDR" && printf '%s' "$BODY" | grep -qi 'name=.robots.[^>]*noindex'; then ok "A1-10 preview con Cache-Control no-cache y meta robots noindex"; else STATUS=hdr; BODY=$(grep -i 'cache-control' "$TMP_HDR" | tr '\n' ' '); ko "A1-10 falta no-cache o noindex en la preview"; fi
	# A1-11: el token de A no publica B.
	raw anon GET "$WP_URL/?page_id=$PAGE_PRIVATE&spiral_preview=$PTOKEN"
	expect A1-11 "token del borrador A usado en la URL de la página privada B" 404
	# Revocación.
	call "$AGENTE" POST spiral-mcp/revoke-preview-url "{\"id\":$PAGE_DRAFT,\"token_id\":\"$PTOKEN_ID\"}"
	raw anon GET "$PURL"
	expect A1-08g "token revocado" 404
fi

# A1-09: privada y con contraseña.
call "$AGENTE" POST spiral-mcp/get-preview-url "{\"id\":$PAGE_PRIVATE}"
PURL=$(jget url)
if [ -n "$PURL" ]; then
	raw anon GET "$PURL"
	if [ "$STATUS" = 200 ]; then info "A1-09a la preview muestra también una página PRIVADA a un visitante con el token (decidir si es deseable)"; else ok "A1-09a la preview no publica una página privada (HTTP $STATUS)"; fi
	call "$AGENTE" POST spiral-mcp/revoke-preview-url "{\"id\":$PAGE_PRIVATE}"
else
	info "A1-09a get-preview-url de una página privada: HTTP $STATUS"
fi
call "$AGENTE" POST spiral-mcp/get-preview-url "{\"id\":$PAGE_PASSWORD}"
PURL=$(jget url)
if [ -n "$PURL" ]; then
	raw anon GET "$PURL"
	if printf '%s' "$BODY" | grep -q 'TEXTO-PROTEGIDO'; then ko "A1-09b la preview salta la contraseña de una página protegida"; else ok "A1-09b la página con contraseña sigue pidiendo contraseña en la preview"; fi
	call "$AGENTE" POST spiral-mcp/revoke-preview-url "{\"id\":$PAGE_PASSWORD}"
else
	info "A1-09b get-preview-url de una página con contraseña: HTTP $STATUS"
fi

# =============================================================================
# A2. Agente autenticado: cerrojos
# =============================================================================

# A2-01: rol con las nativas del agente pero sin ninguna spiral_mcp_*.
for pair in "GET spiral-mcp/list-content" "GET spiral-mcp/get-theme-config" "GET spiral-mcp/list-media" "GET spiral-mcp/get-site-settings" "GET spiral-mcp/get-cache-status" "GET spiral-mcp/list-plugins" "GET spiral-mcp/list-snapshots" "GET spiral-mcp/list-activity" "GET spiral-mcp/get-custom-css" "GET spiral-mcp/list-menus"; do
	set -- $pair
	call "$MIN" "$1" "$2"
	expect "A2-01" "$2 sin capacidad de grupo" 403
done
call "$MIN" POST spiral-mcp/create-content '{"type":"page","title":"sec-a201"}'
expect A2-01 "create-content sin capacidad de grupo" 403
[ "$STATUS" = 200 ] && POST_IDS="$POST_IDS $(jget id)"

# A2-02: grupos excluidos por defecto (cache, plugins, orders).
set_caps '{"spiral_mcp_cache":false,"spiral_mcp_plugins":false,"spiral_mcp_orders":false}'
call "$AGENTE" POST spiral-mcp/update-cache-settings '{"settings":{}}'
expect A2-02b "update-cache-settings sin spiral_mcp_cache" 403
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"classic-editor"}'
expect A2-02c "install-plugin sin spiral_mcp_plugins" 403
call "$AGENTE" GET spiral-mcp/list-plugins
if [ "$STATUS" = 200 ] && [ "$(jget management.allowed)" != true ]; then ok "A2-02d list-plugins sigue disponible y management no está permitido"; else info "A2-02d list-plugins: management=$(jget management)"; fi
call "$AGENTE" GET spiral-mcp/list-orders
[ "$STATUS" = 404 ] && skip "A2-02e list-orders no registrada (WooCommerce inactivo)" || expect A2-02e "list-orders sin spiral_mcp_orders" 403

# A2-08: restaurar un snapshot de un grupo que no se tiene (plugins_state sin spiral_mcp_plugins).
SNAP=$(wpeval '$r = spiral_mcp_snapshot_capture( "plugins_state", "active_plugins", "sec-a208" ); echo is_wp_error( $r ) ? "ERR:" . $r->get_error_message() : $r;')
case "$SNAP" in
	ERR:*|'') STATUS=php; BODY=$SNAP; info "A2-08 no se pudo crear el snapshot de prueba" ;;
	*) call "$AGENTE" POST spiral-mcp/restore-snapshot "{\"id\":$SNAP,\"confirm\":true,\"include_later\":true}"
	   expect A2-08 "restore-snapshot de plugins_state sin spiral_mcp_plugins" 403 ;;
esac

# A2-03: los dos cerrojos de plugins.
set_caps '{"spiral_mcp_plugins":true}'
set_setting plugins_management false
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"classic-editor"}'
expect A2-03a "install-plugin con la cap pero sin el ajuste" 403 spiral_mcp_plugins_disabled
set_caps '{"spiral_mcp_plugins":false}'
set_setting plugins_management true
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"classic-editor"}'
expect A2-03b "install-plugin con el ajuste pero sin la cap" 403 spiral_mcp_plugins_capability

# A2-04 y A2-05: con los dos cerrojos abiertos.
set_caps '{"spiral_mcp_plugins":true}'
set_setting plugins_management true
for ref in "Spiral-MCP-Control/spiral-mcp-control.php" "spiral-mcp-control" "mcp-adapter" "mcp-adapter/mcp-adapter.php" "MCP-ADAPTER" "./mcp-adapter/mcp-adapter.php" "../plugins/mcp-adapter/mcp-adapter.php"; do
	call "$AGENTE" POST spiral-mcp/deactivate-plugin "{\"plugin\":\"$ref\"}"
	if [ "$STATUS" = 200 ]; then ko "A2-04 deactivate-plugin \"$ref\" ha tenido éxito"; else ok "A2-04 deactivate-plugin \"$ref\" rechazado (HTTP $STATUS, $(jget code))"; fi
done
call "$AGENTE" POST spiral-mcp/update-plugin '{"plugin":"mcp-adapter","confirm":true}'
expect A2-04 "update-plugin de mcp-adapter" 400,403 spiral_mcp_plugin_protected
call "$AGENTE" POST spiral-mcp/delete-plugin '{"plugin":"Spiral-MCP-Control/spiral-mcp-control.php","confirm":true}'
expect A2-04 "delete-plugin de Spiral-MCP-Control" 400,403 spiral_mcp_plugin_protected
ACTIVE=$(wpeval 'echo (int) is_plugin_active( "Spiral-MCP-Control/spiral-mcp-control.php" ), (int) is_plugin_active( "mcp-adapter/mcp-adapter.php" );')
STATUS=php; BODY=$ACTIVE
[ "$ACTIVE" = 11 ] && ok "A2-04 los dos plugins protegidos siguen activos" || ko "A2-04 algún plugin protegido ya no está activo"

call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"../../zzz-no-existe-9f3a"}'
expect A2-05a "install-plugin con slug con rutas" 400,404 spiral_mcp_plugins_api
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"akismet/akismet.php"}'
expect A2-05b "install-plugin con un archivo en vez de slug" 400,404 spiral_mcp_plugins_api
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"https://evil.example.invalid/x.zip"}'
expect A2-05c "install-plugin con una URL" 400,404 spiral_mcp_plugins_api
wpeval 'set_transient( "spiral_sec_sim", "badhost", 300 );' > /dev/null
call "$AGENTE" POST spiral-mcp/install-plugin '{"slug":"hello-dolly"}'
wpeval 'delete_transient( "spiral_sec_sim" );' > /dev/null
expect A2-05d "install-plugin cuyo ZIP no viene de downloads.wordpress.org" 400,403 spiral_mcp_plugin_source
INST=$(wpeval 'echo (int) is_dir( WP_PLUGIN_DIR . "/hello-dolly" );')
STATUS=php; BODY=$INST
[ "$INST" = 0 ] && ok "A2-05d no se ha escrito hello-dolly/ en wp-content/plugins" || ko "A2-05d se ha instalado hello-dolly pese al origen no permitido"

# A2-07: ability desactivada en Ajustes.
wpeval 'update_option( "spiral_mcp_disabled_abilities", array( "spiral-mcp/trash-content" ) );' > /dev/null
call "$AGENTE" POST spiral-mcp/trash-content "{\"id\":$PAGE_PUBLISH}"
expect A2-07a "ability desactivada por REST" 404
mcp "$AGENTE" tools/call "{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"spiral-mcp/trash-content\",\"parameters\":{\"id\":$PAGE_PUBLISH}}}"
if [ "$STATUS" = 200 ] && ! printf '%s' "$BODY" | grep -qi 'not found\|no existe\|isError\":true\|"error"'; then ko "A2-07b ability desactivada ejecutable vía execute-ability del MCP Adapter"; else ok "A2-07b ability desactivada tampoco vía execute-ability (HTTP $STATUS)"; fi
PSTAT=$(wpeval 'echo get_post_status( '"$PAGE_PUBLISH"' );')
STATUS=php; BODY=$PSTAT
[ "$PSTAT" = publish ] && ok "A2-07c la página sigue publicada" || ko "A2-07c la página cambió de estado: $PSTAT"
wpeval 'update_option( "spiral_mcp_disabled_abilities", array() );' > /dev/null

# A2-09: restore-snapshot con IDs malformados.
for id in 0 -1 999999999 '"1 OR 1=1"' '"1; DROP TABLE x"'; do
	call "$AGENTE" POST spiral-mcp/restore-snapshot "{\"id\":$id,\"confirm\":true}"
	case "$STATUS" in 400|404) ok "A2-09 restore-snapshot id=$id rechazado (HTTP $STATUS)";; 500) [ -n "$(jget code)" ] && info "A2-09 restore-snapshot id=$id rechazado pero con HTTP 500 (WP_Error sin status)" || ko "A2-09 restore-snapshot id=$id";; *) ko "A2-09 restore-snapshot id=$id";; esac
done

# A2-10: "confirm" solo como booleano true.
call "$AGENTE" POST spiral-mcp/create-content '{"type":"page","title":"sec-a210","status":"draft"}'
PID=$(jget id)
if [ -z "$PID" ]; then ko "A2-10 no se pudo crear la página de prueba"; else
	POST_IDS="$POST_IDS $PID"
	call "$AGENTE" DELETE spiral-mcp/delete-content "{\"id\":$PID}"
	expect A2-10a "delete-content sin confirm" 400,403
	call "$AGENTE" DELETE spiral-mcp/delete-content "{\"id\":$PID,\"confirm\":\"yes\"}"
	expect A2-10b "delete-content con confirm=yes" 400,403
	mcp "$AGENTE" tools/call "{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"spiral-mcp/delete-content\",\"parameters\":{\"id\":$PID,\"confirm\":\"true\"}}}"
	EXISTS=$(wpeval 'echo get_post( '"$PID"' ) ? 1 : 0;')
	if [ "$EXISTS" = 1 ]; then ok "A2-10c delete-content con confirm=\"true\" (string, vía MCP) no borra"; else ko "A2-10c delete-content con confirm=\"true\" (string, vía MCP) ha borrado la página"; fi
	mcp "$AGENTE" tools/call "{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"spiral-mcp/delete-content\",\"parameters\":{\"id\":$PID,\"confirm\":1}}}"
	EXISTS=$(wpeval 'echo get_post( '"$PID"' ) ? 1 : 0;')
	if [ "$EXISTS" = 1 ]; then ok "A2-10d delete-content con confirm=1 (entero, vía MCP) no borra"; else ko "A2-10d delete-content con confirm=1 (entero, vía MCP) ha borrado la página"; fi
	call "$AGENTE" POST spiral-mcp/trash-content "{\"id\":$PID}"
	call "$AGENTE" DELETE spiral-mcp/delete-content "{\"id\":$PID,\"confirm\":true}"
	expect A2-10e "delete-content con confirm=true" 200
fi

# =============================================================================
# A2 (continuación): código, secretos, entradas malformadas
# =============================================================================

# A2-11: la sección "scripts" exige spiral_mcp_code.
set_caps '{"spiral_mcp_code":false}'
call "$AGENTE" POST spiral-mcp/update-theme-config '{"section":"scripts","values":{"sec":"<script>window.SEC_A211=1</script>"}}'
expect A2-11 "update-theme-config scripts sin spiral_mcp_code" 403
set_caps "$(php -r '$s=json_decode(stream_get_contents(STDIN),true); echo json_encode((object)$s["caps"]);' < "$TMP_STATE")"

# A2-12: secciones reservadas y nombres raros.
CFG_BEFORE=$(wpeval 'echo md5( (string) get_theme_mod( "config" ) );')
for sec in yootheme_apikey version site_url minimum_stability "scripts " Scripts "../scripts"; do
	call "$AGENTE" POST spiral-mcp/update-theme-config "{\"section\":\"$sec\",\"values\":{\"sec\":\"x\"},\"replace\":true}"
	case "$STATUS" in 200) ko "A2-12 update-theme-config aceptó la sección \"$sec\"";; 500) info "A2-12 update-theme-config rechazó la sección \"$sec\" pero con HTTP 500 ($(jget code))";; *) ok "A2-12 update-theme-config rechazó la sección \"$sec\" (HTTP $STATUS)";; esac
done
CFG_AFTER=$(wpeval 'echo md5( (string) get_theme_mod( "config" ) );')
STATUS=php; BODY="$CFG_BEFORE/$CFG_AFTER"
[ "$CFG_BEFORE" = "$CFG_AFTER" ] && ok "A2-12 el theme mod config no cambió" || ko "A2-12 el theme mod config cambió con secciones reservadas"

# A2-13: JavaScript en elementos del builder sin spiral_mcp_code ni unfiltered_html.
# YOOtheme quita kses al guardar layouts (RemoveContentFilter) y el elemento "html" imprime su
# contenido tal cual: lo que se guarde aquí llega al navegador de todos los visitantes.
set_caps '{"spiral_mcp_code":false}'
SEC213='{"type":"section","props":{},"children":[{"type":"row","props":{},"children":[{"type":"column","props":{},"children":[{"type":"html","props":{"content":"<b>SEC-A213-HTML</b><script>window.SEC_A213_HTML=1</script>"}},{"type":"text","props":{"content":"<p>SEC-A213-TEXT</p><script>window.SEC_A213_TEXT=1</script><img src=x onerror=\"window.SEC_A213_IMG=1\">"}}]}]}]}'
call "$AGENTE" POST spiral-mcp/update-theme-layout "{\"area\":\"footer\",\"layout\":{\"type\":\"layout\",\"children\":[$SEC213]}}"
SNAP213=$(jget snapshot_id)
if [ "$STATUS" != 200 ]; then
	ok "A2-13a update-theme-layout del footer con <script> rechazado (HTTP $STATUS, $(jget code))"
else
	raw anon GET "$WP_URL/?sec=$RANDOM"
	HITS=$(printf '%s' "$BODY" | grep -c -F -e 'window.SEC_A213_HTML=1' -e 'window.SEC_A213_TEXT=1' -e 'onerror="window.SEC_A213_IMG=1"')
	BODY="marcadores de script en la portada: $HITS"
	[ "$HITS" = 0 ] && ok "A2-13a el footer se guardó pero sin el <script> ni el onerror" || ko "A2-13a un agente sin spiral_mcp_code inyecta <script> y onerror en el footer (público)"
	[ -n "$SNAP213" ] && call "$AGENTE" POST spiral-mcp/restore-snapshot "{\"id\":$SNAP213,\"confirm\":true,\"include_later\":true}"
	raw anon GET "$WP_URL/?sec=$RANDOM"
	HITS=$(printf '%s' "$BODY" | grep -c SEC_A213); STATUS=-; BODY="marcadores: $HITS"
	[ "$HITS" = 0 ] && ok "A2-13a footer restaurado" || ko "A2-13a el footer sigue con el script tras restaurar el snapshot $SNAP213"
fi
call "$AGENTE" POST spiral-mcp/update-yootheme-layout "{\"id\":$PAGE_DRAFT,\"layout\":{\"type\":\"layout\",\"children\":[$SEC213]}}"
if [ "$STATUS" != 200 ]; then
	ok "A2-13b update-yootheme-layout con <script> rechazado (HTTP $STATUS, $(jget code))"
else
	call "$AGENTE" POST spiral-mcp/get-preview-url "{\"id\":$PAGE_DRAFT}"
	raw anon GET "$(jget url)"
	HITS=$(printf '%s' "$BODY" | grep -c -F -e 'window.SEC_A213_HTML=1' -e 'window.SEC_A213_TEXT=1' -e 'onerror="window.SEC_A213_IMG=1"')
	BODY="marcadores de script en la página: $HITS"
	[ "$HITS" = 0 ] && ok "A2-13b la página se guardó sin el <script> ni el onerror (kses)" || ko "A2-13b un agente sin unfiltered_html inyecta <script> y onerror en una página"
	call "$AGENTE" POST spiral-mcp/revoke-preview-url "{\"id\":$PAGE_DRAFT}"
	call "$AGENTE" POST spiral-mcp/update-yootheme-layout "{\"id\":$PAGE_DRAFT,\"layout\":{\"type\":\"layout\",\"children\":[]}}"
fi
set_caps "$(php -r '$s=json_decode(stream_get_contents(STDIN),true); echo json_encode((object)$s["caps"]);' < "$TMP_STATE")"

set_caps '{"spiral_mcp_code":false}'
# A2-13c: sin elemento html, un text con <script> se guarda filtrado (kses) y con aviso; con spiral_mcp_code, sin filtrar.
TXT213='{"type":"section","props":{},"children":[{"type":"row","props":{},"children":[{"type":"column","props":{},"children":[{"type":"text","props":{"content":"<p>SEC-A213-TEXT <a href=\"/contacto/\">enlace</a> Tom &amp; Jerry</p><script>window.SEC_A213_TEXT=1</script><img src=x onerror=\"window.SEC_A213_IMG=1\">"}},{"type":"button","props":{"content":"ir","link":"javascript:window.SEC_A213_LINK=1"}}]}]}]}'
call "$AGENTE" POST spiral-mcp/update-theme-layout "{\"area\":\"footer\",\"layout\":{\"type\":\"layout\",\"children\":[$TXT213]}}"
SNAP213=$(jget snapshot_id); W=$(jget warnings.#)
if [ "$STATUS" != 200 ]; then
	ko "A2-13c update-theme-layout con un text con <script> debería guardarse filtrado, no rechazarse (HTTP $STATUS, $(jget code))"
else
	raw anon GET "$WP_URL/?sec=$RANDOM"
	BAD=$(printf '%s' "$BODY" | grep -c -F -e '<script>window.SEC_A213_TEXT=1' -e 'onerror="window.SEC_A213_IMG=1"' -e 'javascript:window.SEC_A213_LINK')
	GOOD=$(printf '%s' "$BODY" | grep -c -F 'SEC-A213-TEXT')
	BODY="script/onerror/javascript: $BAD; texto legítimo: $GOOD; warnings: ${W:-0}"; STATUS=-
	if [ "$BAD" = 0 ] && [ "$GOOD" != 0 ] && [ "${W:-0}" -gt 0 ]; then ok "A2-13c text con <script>: se guarda sin el script ni el onerror, con el texto y con aviso"; else ko "A2-13c text con <script>: filtrado incompleto o sin aviso"; fi
	[ -n "$SNAP213" ] && call "$AGENTE" POST spiral-mcp/restore-snapshot "{\"id\":$SNAP213,\"confirm\":true,\"include_later\":true}"
fi
set_caps "$(php -r '$s=json_decode(stream_get_contents(STDIN),true); echo json_encode((object)$s["caps"]);' < "$TMP_STATE")"
# Con spiral_mcp_code (lo tiene el rol en este sitio) el html con script sí se guarda: es el permiso que lo autoriza.
if wpeval 'echo (int) user_can( get_user_by( "login", "'"$AGENTE_USER"'" ), "spiral_mcp_code" );' | grep -q 1; then
	call "$AGENTE" POST spiral-mcp/update-theme-layout "{\"area\":\"footer\",\"layout\":{\"type\":\"layout\",\"children\":[$SEC213]}}"
	SNAP213=$(jget snapshot_id)
	expect A2-13d "con spiral_mcp_code, el elemento html con script se guarda" 200
	[ -n "$SNAP213" ] && call "$AGENTE" POST spiral-mcp/restore-snapshot "{\"id\":$SNAP213,\"confirm\":true,\"include_later\":true}"
fi

# A2-23: la clave de API de YOOtheme no sale en ninguna respuesta.
APIKEY=$(wpeval '$c = json_decode( (string) get_theme_mod( "config" ), true ); echo (string) ( $c["yootheme_apikey"] ?? "" );')
if [ -z "$APIKEY" ]; then
	STATUS=-; BODY=''; info "A2-23 el sitio local no tiene yootheme_apikey: pon una en Personalizar > Ajustes para probar la fuga"
else
	leak=0
	call "$AGENTE" GET spiral-mcp/get-theme-config; printf '%s' "$BODY" | grep -qF "$APIKEY" && leak=1
	for sec in site scripts less style; do call "$AGENTE" GET spiral-mcp/get-theme-config "{\"section\":\"$sec\"}"; printf '%s' "$BODY" | grep -qF "$APIKEY" && leak=1; done
	call "$AGENTE" GET spiral-mcp/list-snapshots '{"kind":"theme_config","per_page":50}'; printf '%s' "$BODY" | grep -qF "$APIKEY" && leak=1
	call "$AGENTE" GET spiral-mcp/get-theme-layout '{"area":"footer"}'; printf '%s' "$BODY" | grep -qF "$APIKEY" && leak=1
	call "$AGENTE" GET spiral-mcp/list-activity '{"per_page":50}'; printf '%s' "$BODY" | grep -qF "$APIKEY" && leak=1
	BODY=''; STATUS=-
	[ $leak -eq 0 ] && ok "A2-23 yootheme_apikey no aparece en theme-config, snapshots, theme-layout ni activity" || ko "A2-23 yootheme_apikey aparece en claro en alguna respuesta"
fi

# A2-27: ¿el agente ve en list-activity las entradas de otros usuarios? (filtra por un user_id ajeno con entradas)
ME=$(wpeval 'echo get_user_by( "login", "'"$AGENTE_USER"'" )->ID;')
OTHER=$(wpeval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM " . spiral_mcp_table( "log" ) . " WHERE user_id <> %d ORDER BY id DESC LIMIT 1", '"$ME"' ) );')
if [ "$OTHER" = 0 ]; then
	STATUS=-; BODY=''; info "A2-27 no hay entradas de otros usuarios en el registro para comprobarlo"
else
	call "$AGENTE" GET spiral-mcp/list-activity "{\"user_id\":$OTHER,\"per_page\":5}"
	N=$(jget items.#)
	if [ "$STATUS" = 200 ] && [ "${N:-0}" -gt 0 ]; then info "A2-27 list-activity muestra al agente $N entradas (con su input) del usuario $OTHER"; else ok "A2-27 list-activity no muestra las entradas del usuario $OTHER (HTTP $STATUS)"; fi
fi

# A2-33: create-content: autor, plantilla y metas.
call "$AGENTE" POST spiral-mcp/create-content '{"type":"page","title":"sec-a233","status":"draft","template":"../../wp-config.php"}'
expect A2-33a "create-content con template con rutas" 400 spiral_mcp_bad_template
[ "$STATUS" = 200 ] && POST_IDS="$POST_IDS $(jget id)"
call "$AGENTE" POST spiral-mcp/create-content '{"type":"page","title":"sec-a233","status":"draft","meta":{"_thumbnail_id":"1","_wp_attached_file":"../x"}}'
expect A2-33b "create-content con metas protegidas" 400,403 spiral_mcp_meta_locked
[ "$STATUS" = 200 ] && POST_IDS="$POST_IDS $(jget id)"
call "$AGENTE" POST spiral-mcp/create-content '{"type":"page","title":"sec-a233","status":"draft","author":1}'
if [ "$STATUS" = 200 ]; then POST_IDS="$POST_IDS $(jget id)"; info "A2-33c create-content permite firmar como el administrador (author=1): el agente tiene edit_others_pages; decidir si limitar"; else ok "A2-33c create-content con author=1 rechazado (HTTP $STATUS)"; fi

# A2-34: nombres de archivo peligrosos en upload-media (base64 de un PNG real).
B64=$(base64 -w0 "$TMP_PNG" 2>/dev/null || base64 "$TMP_PNG" | tr -d '\n')
for fn in shell.php shell.phtml shell.php.jpg shell.phar .htaccess imagen.svg imagen.html "x.php%00.png" "a.jpg;b.php"; do
	call "$AGENTE" POST spiral-mcp/upload-media "{\"data_base64\":\"$B64\",\"filename\":\"$fn\",\"alt\":\"sec\"}"
	if [ "$STATUS" = 200 ]; then
		MEDIA_IDS="$MEDIA_IDS $(jget id)"
		case "$(jget url)" in *.php|*.phtml|*.phar|*.htaccess|*.svg|*.html) ko "A2-34 upload-media guardó \"$fn\" como $(jget url)";; *) ok "A2-34 upload-media renombró \"$fn\" a algo inocuo ($(jget url | sed 's#.*/##'))";; esac
	else ok "A2-34 upload-media rechazó \"$fn\" (HTTP $STATUS, $(jget code))"; fi
done
PHPFILES=$(wpeval '$n = 0; foreach ( array( "/*/*/*", "/*" ) as $g ) { foreach ( (array) glob( wp_upload_dir()["basedir"] . $g ) as $p ) { if ( preg_match( "/\.(php\d?|phtml|phar|phps)$/i", $p ) ) { $n++; } } } echo $n;')
STATUS=php; BODY=$PHPFILES
[ "$PHPFILES" = 0 ] && ok "A2-34 no hay archivos PHP en uploads" || ko "A2-34 hay $PHPFILES archivos PHP en uploads"

# A2-30: filtros de list-activity con caracteres especiales y tamaños absurdos.
SQ="'"
for q in "{\"ability\":\"x$SQ OR 1=1 --\"}" '{"user_id":"1 OR 1=1"}' "{\"result\":\"ok$SQ; DROP TABLE x; --\"}" '{"per_page":-1}' '{"per_page":100000}' '{"page":0}' "{\"since\":\"x$SQ)\"}"; do
	call "$AGENTE" GET spiral-mcp/list-activity "$q"
	case "$STATUS" in 200|400) ok "A2-30 list-activity $q (HTTP $STATUS)";; 500) [ -n "$(jget code)" ] && info "A2-30 list-activity $q rechazado con HTTP 500 (WP_Error sin status)" || ko "A2-30 list-activity $q (error PHP)";; *) ko "A2-30 list-activity $q";; esac
done

# A2-31: JSON Pointer malformado en patch-layout (sobre el borrador de prueba).
for p in '/children/-1' '/children/999999' '//' '/__proto__/x' '/children/0/children/0/children/0/children/0/props/content/0/1/2/3/4/5/6/7/8/9'; do
	call "$AGENTE" POST spiral-mcp/patch-layout "{\"id\":$PAGE_DRAFT,\"operations\":[{\"op\":\"replace\",\"path\":\"$p\",\"value\":\"x\"}]}"
	case "$STATUS" in 400|404) ok "A2-31 patch-layout path $p rechazado (HTTP $STATUS)";; 500) [ -n "$(jget code)" ] && info "A2-31 patch-layout path $p rechazado con HTTP 500 (WP_Error sin status)" || ko "A2-31 patch-layout path $p (error PHP)";; *) ko "A2-31 patch-layout path $p";; esac
done

printf '\n== Resultado: %d OK, %d FALLO, %d INFO ==\n' "$PASS" "$FAIL" "$INFO"
[ "$FAIL" -eq 0 ]
