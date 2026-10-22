# Spiral-MCP-Control — contexto para Claude Code

Plugin de WordPress que registra *abilities* (Abilities API de WordPress 6.9+) y las expone a agentes de IA a través del plugin **MCP Adapter**. Objetivo: que un agente (Claude) pueda controlar por completo un sitio WordPress con **YOOtheme Pro**: contenido, diseño, menús, medios, estilos y ajustes, sin entrar al wp-admin.

El plan de trabajo está en [`docs/ROADMAP.md`](docs/ROADMAP.md). Léelo antes de empezar cualquier tarea. La guía de uso para el agente es el skill de Claude [`skills/spiral-mcp-wordpress/`](skills/spiral-mcp-wordpress/SKILL.md) (flujo, convenciones, recetas y errores frecuentes). Cuando añadas o cambies una ability, regenera su referencia con `php tests/gen-skill-abilities.php /ruta/al/wordpress` y revisa si `SKILL.md` o las recetas necesitan cambios.

## Requisitos
- WordPress 6.9+ (Abilities API: `wp_register_ability`, `wp_register_ability_category`).
- Plugin **MCP Adapter** activo (es el que publica las abilities como herramientas MCP). Declarado en la cabecera como `Requires Plugins: mcp-adapter` (slug de wordpress.org): WordPress no deja activar este plugin sin él.
- YOOtheme Pro activo (o un tema hijo de YOOtheme) para todo lo relacionado con el builder y los estilos.
- PHP 8.1 como mínimo (lo exige la librería cliente de SPUpdater). El archivo principal no debe usar sintaxis posterior a PHP 7.4: tiene que poder interpretarse para mostrar el aviso de requisitos.

## Estructura actual (v1.29.0)
```
spiral-mcp-control.php   cabecera, constantes, comprobación de requisitos, require_once y hooks de activación
uninstall.php            borra el rol y las capacidades (las tablas solo con el filtro spiral_mcp_uninstall_drop_tables)
includes/core.php        spiral_mcp_register_ability(), spiral_mcp_meta(), spiral_mcp_json(), spiral_mcp_require_confirm()…
includes/permissions.php capacidades spiral_mcp_* y rol spiral_mcp_agent
includes/activity.php    registro de auditoría ({prefix}spiral_mcp_log), list-activity y limpieza diaria
includes/snapshots.php   snapshots ({prefix}spiral_mcp_snapshots), list-snapshots y restore-snapshot
includes/install.php     dbDelta, rol, migraciones y cron; se ejecuta al activar y al cambiar SPIRAL_MCP_VERSION
includes/content.php     tipos de contenido; listar, leer, crear y editar (padre, plantilla, autor, fecha, términos, destacada, metas); papelera; revisiones; layout de página
includes/menus.php       abilities de menús y elementos de menú (+ snapshot kind "menu")
includes/theme.php       footer de YOOtheme (builder) y widgets (+ snapshot kinds "theme_config" y "widgets")
includes/media.php       subir (URL, base64 o enlace de subida directa: POST a spiral-mcp/v1/upload con la cabecera X-Spiral-Upload-Token), listar, editar y borrar medios, imagen destacada y usos (+ snapshot kinds "media_meta" y "post_meta")
includes/elements.php    catálogo de elementos de YOOtheme (list-elements, get-element-schema) y validación de layouts
includes/layout.php      JSON Pointer, get-layout-outline, get-layout-texts, get-layout-node, patch-layout y duplicate-content
includes/theme-config.php get-theme-config y update-theme-config (lista blanca de secciones)
includes/templates.php   plantillas y biblioteca de YOOtheme vía su Storage (+ snapshot kind "yootheme_storage")
includes/online-library.php librería online de YOOtheme (api.yootheme.com): catálogo, vista previa e importación
includes/cache.php       cachés con un adaptador por herramienta: lógica común, WP Super Cache (completo), caché de objetos, abilities y purga automática dirigida
includes/cache-providers.php adaptadores de purga y estado: WP Fastest Cache, W3TC, LiteSpeed, SiteGround, Nginx Helper, WP Rocket, WP Engine, Pantheon, Kinsta
includes/taxonomies.php  taxonomías y términos (+ snapshot kind "term")
includes/seo.php         SEO por contenido: Yoast o salida propia (+ snapshot kind "seo")
includes/seo-settings.php ajustes generales de Yoast SEO (solo si está activo), por secciones con lista blanca (+ snapshot kind "seo_settings")
includes/settings.php    ajustes del sitio: identidad y portada (+ snapshot kind "option")
includes/preview.php     URLs de vista previa sin sesión (token firmado) e inspect-page
includes/plugins.php     plugins: listar, buscar en wordpress.org, instalar, activar (protegida), desactivar, actualizar (con vuelta atrás) y borrar; cerrojos (spiral_mcp_plugins + ajuste plugins_management) y snapshot kind "plugins_state"
includes/forms.php       formularios de Contact Form 7 (solo si está activo): listar, leer, crear, editar y borrar (+ snapshot kind "form")
includes/woocommerce.php tienda (solo si WooCommerce está activo): helpers comunes (spiral_mcp_wc_rest()…), productos, variaciones, atributos y cupones (+ snapshot kinds "wc_product", "wc_coupon", "wc_attribute")
includes/woocommerce-store.php ajustes de la tienda, envíos, impuestos y pasarelas (lectura) (+ "wc_settings", "wc_shipping_zone", "wc_tax_rates")
includes/woocommerce-orders.php pedidos, notas, reembolsos y clientes (grupo "orders") (+ "wc_order")
includes/admin.php       página Ajustes > Spiral-MCP (solo admin): abilities, permisos del rol del agente, comportamiento y actividad (con restaurar)
includes/styles.php      CSS adicional (+ snapshot kind "custom_css") y estilo de YOOtheme: catálogo de variables, cambios y estado de compilación
tests/smoke.sh           prueba de humo por REST (ver "Entorno de desarrollo")
tests/fixtures/          layouts de YOOtheme de ejemplo
tests/gen-skill-abilities.php  regenera la referencia de abilities del skill
skills/spiral-mcp-wordpress/   skill de Claude para el agente que usa el plugin
includes/updates.php     actualizaciones desde SPUpdater (https://plugins.rasenfx.com, producto `spiral-mcp-control`, gratuito)
includes/spupdater/      copia de spupdater/packages/spupdater-wp-client/src con el namespace SpiralMcp\SPUpdater\WpClient (+ autoload.php)
dist/                    ZIP para instalar (no versionar)
```

Abilities existentes (todas con prefijo `spiral-mcp/`):
- Contenido: `list-post-types`, `list-content`, `get-content`, `create-content`, `update-content`, `trash-content`, `restore-content`, `delete-content`, `list-revisions`, `restore-revision`, `get-yootheme-layout`, `update-yootheme-layout`.
- Taxonomías y SEO: `list-taxonomies`, `list-terms`, `create-term`, `update-term`, `delete-term`, `get-seo`, `update-seo`.
- Ajustes: `get-site-settings`, `update-site-settings`; con Yoast SEO activo, `get-seo-settings` y `update-seo-settings` (ajustes generales de Yoast).
- Verificación: `get-preview-url`, `revoke-preview-url`, `inspect-page`.
- Estilos: `get-custom-css`, `update-custom-css`, `get-theme-style`, `list-style-variables`, `update-theme-style`, `get-style-status`, `mark-style-compiled`.
- Menús: `list-menus`, `get-menu`, `create-menu`, `update-menu`, `delete-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`.
- Tema: `get-theme-layout`, `update-theme-layout` (áreas `footer`, `menu_item:<ID>`, `widget:<ID>`, `template:<id>`), `list-widgets`, `create-widget`, `update-widget`, `delete-widget`; `list-theme-backups` y `restore-theme-backup` son alias obsoletos (se quitan en la próxima versión).
- Layouts: `list-elements`, `get-element-schema`, `get-layout-outline`, `get-layout-texts`, `get-layout-node`, `patch-layout`, `duplicate-content`, `insert-from-library`.
- YOOtheme: `get-theme-config`, `update-theme-config`, `list-templates`, `get-template`, `create-template`, `update-template`, `delete-template`, `list-library`, `get-library-item`, `save-to-library`; librería online: `list-online-library`, `get-online-library-item`, `import-from-online-library`.
- Medios: `upload-media`, `create-upload-url`, `list-media`, `get-media`, `update-media`, `delete-media`, `set-featured-image`, `find-media-usage`.
- Cachés: `get-cache-status`, `purge-cache`, `preload-cache`, `get-cache-settings`, `update-cache-settings` (exige `spiral_mcp_cache`); `clear-cache` es un alias obsoleto de `purge-cache`.
- Plugins: `list-plugins`, `search-plugins`, `install-plugin`, `activate-plugin`, `deactivate-plugin`, `update-plugin`, `delete-plugin` (escritura con dos cerrojos).
- Formularios (solo con Contact Form 7 activo): `list-forms`, `get-form`, `create-form`, `update-form`, `delete-form` (cambiar a quién llega el correo exige `confirm`).
- Tienda (solo con WooCommerce activo): `list-products`, `get-product`, `create-product`, `update-product`, `delete-product`, `update-variations`, `list-product-attributes`, `create-product-attribute`, `update-product-attribute`, `delete-product-attribute`, `list-coupons`, `get-coupon`, `create-coupon`, `update-coupon`, `delete-coupon`, `get-shop-settings`, `update-shop-settings`, `list-shipping-zones`, `save-shipping-zone`, `delete-shipping-zone`, `save-shipping-method`, `delete-shipping-method`, `list-tax-rates`, `update-tax-rates`, `list-payment-gateways`.
- Pedidos (solo con WooCommerce activo; exigen `spiral_mcp_orders`): `list-orders`, `get-order`, `update-order`, `add-order-note`, `create-refund`, `list-customers`, `get-customer`.
- Auditoría y snapshots: `list-activity`, `list-snapshots`, `restore-snapshot`.

## Convenciones (obligatorias)
- **Nombres**: abilities `spiral-mcp/<verbo>-<objeto>` en kebab-case; funciones PHP `spiral_mcp_*`; constantes `SPIRAL_MCP_*`; códigos de `WP_Error` `spiral_mcp_*`; filtros y acciones `spiral_mcp_*`; categorías `spiral-mcp-<módulo>`.
- **Un módulo por archivo** en `includes/`, cada uno con su categoría y sus abilities, registrado desde el archivo principal con `require_once`.
- **Patrón de registro**: copiar el de `includes/menus.php`. Registrar siempre con `spiral_mcp_register_ability()`, nunca con `wp_register_ability()` directamente. Argumentos extra del wrapper: `'group'` (`content`, `design`, `media`, `settings`, `code`, `cache`, `plugins`, `shop`, `orders`, o un array si basta con uno: exige la capacidad `spiral_mcp_<grupo>`; `cache`, `plugins` y `orders` no las tiene el rol del agente por defecto, ver `spiral_mcp_agent_excluded_caps()`; las nativas que nunca recibe están en `spiral_mcp_never_granted_caps()`, y las que recibe solo junto con una propia, en `spiral_mcp_conditional_caps()`) y `'log' => [ 'type' => …, 'id' => clave de la entrada o callable( $input, $result ) ]` (objeto para la auditoría; `'purge' => false` evita la purga automática de caché cuando la ability no cambia nada visible, como `upload-media`). `'meta' => spiral_mcp_meta( $readonly, $destructive, $idempotent )`. `input_schema` con `required`. El wrapper registra en el log toda ability con `readonly = false`, anota cada ability en `spiral_mcp_ability_catalog()` y **no registra** las desactivadas en Ajustes > Spiral-MCP (opción `spiral_mcp_disabled_abilities`; `spiral_mcp_protected_abilities()` no se pueden desactivar). Las capacidades del rol del agente las decide `spiral_mcp_agent_caps()` (por defecto + opción `spiral_mcp_agent_caps` + filtro), y `spiral_mcp_install_caps()` añade y retira las propias según eso. Los ajustes de comportamiento se leen con `spiral_mcp_setting( 'auto_purge' | 'log_retention_days' | 'snapshot_retention_days' | 'online_download' | 'drop_tables_on_uninstall' | 'plugins_management' | 'upload_link_max_mb' )` (opción `spiral_mcp_settings`); los filtros antiguos reciben ese valor.
- **Snapshots**: cada módulo registra sus tipos con el filtro `spiral_mcp_snapshot_kinds` (`group`, `cap`, `capture`, `restore`; opcionales `parts` y `partial` para restaurar por partes, como `theme_config`) y llama a `spiral_mcp_snapshot_capture( $kind, $ref, $note )` antes de escribir. Si devuelve `WP_Error`, no escribir. Devolver `snapshot_id` en la respuesta.
- **Descripciones en español, escritas para un agente**: qué hace, qué leer antes ("primero lee con X"), qué devuelve y qué es irreversible. Las descripciones son la documentación que verá el agente.
- **Permisos**: el wrapper exige la capacidad `spiral_mcp_*` del grupo; además, `permission_callback` comprueba la nativa del objeto (`edit_post` con ese ID, `edit_theme_options`…). Nunca `__return_true`.
- **Acciones destructivas** (borrar, restaurar, reemplazar algo sin revisión): exigir `"confirm": true` con `spiral_mcp_require_confirm()` y marcar `destructive => true`.
- **Antes de escribir en opciones o theme mods, guardar una copia** (snapshot). Los posts ya tienen revisiones; lo demás no.
- **Esquemas con varios tipos** (`'type' => array( … )`): poner `string` primero y `array` antes que `object`. El core convierte la entrada al primer tipo que la valide y un texto con espacios pasa como lista (`wp_parse_list`).
- **Escritura de posts**: siempre con `wp_slash()` antes de `wp_insert_post` / `wp_update_post` (si no, se rompen las `\` del JSON de YOOtheme).
- **JSON**: `spiral_mcp_json( $x )` (`wp_json_encode` con `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`).
- **Errores**: devolver `WP_Error` con mensaje en español que diga cómo corregir la llamada. No lanzar excepciones hacia fuera.
- **Enlaces internos**: rutas relativas (`/contacto/`), nunca el dominio completo. Aplica a ejemplos, descripciones y valores por defecto.
- **Sin dependencias externas** (ni Composer en runtime): el plugin debe instalarse subiendo un ZIP. La librería de SPUpdater va copiada en `includes/spupdater/` con su namespace prefijado (`SpiralMcp\SPUpdater\WpClient`): nktools trae otra copia con el namespace original y, si coincidieran, solo se cargaría una para los dos plugins. Para sincronizarla, copia de nuevo `src/` y repite el cambio de namespace.

## Lo que ya sabemos de YOOtheme (comprobado en producción)
- **Layout de una página**: JSON al final de `post_content`, dentro del último comentario `<!-- {…} -->`. Raíz `{"type":"layout","children":[section > row > column > elementos]}`. Lo que va antes del comentario es el HTML de respaldo. Ver `spiral_mcp_split_layout()`.
- **Configuración del tema**: theme mod `config` (JSON en texto). Claves de primer nivel vistas: `menu, site, header, navbar, dialog, mobile, top, main_sidebar, bottom, footer, webp, consent, less, custom_less, style, post, blog, minimum_stability, lazyload, version, logo, favicon, touchicon, yootheme_apikey, jquery, site_url, favicon_svg, search_filter, scripts, image_urls`.
- **Footer**: `config.footer.content` es un layout con el mismo formato que una página.
- **Mega menús**: `config.menu.items.<ID del elemento de menú>.content`, raíz `"type":"fragment"`.
- **Header, navbar, top, bottom…** no tienen layout en `config`: son ajustes planos; su contenido de builder son widgets `builderwidget` en esas posiciones.
- **Ubicaciones de menú**: el evento `config.save` de YOOtheme reescribe `nav_menu_locations` a partir de `config.menu.positions.<ubicación>.menu`, que en la BD puede estar desfasado. `spiral_mcp_theme_config_write()` lo sincroniza con las ubicaciones actuales antes de guardar; cualquier otra escritura de `config` que pase por `config.save` debe hacer lo mismo.
- **`version` en la raíz de todo layout** (`"5.0.51"`): sin ella YOOtheme asume 1.0.0 y aplica todas sus migraciones al renderizar. Las funciones de guardado la añaden (`spiral_mcp_layout_stamp_version()`).
- **Plantillas y biblioteca**: opción `yootheme` (JSON) bajo `templates.<id>` y `library.<id>`. Escribir **solo** con `\YOOtheme\app(\YOOtheme\Storage::class)`: guarda la opción entera en `shutdown` y pisaría un `update_option()` hecho en la misma petición.
- **Elementos**: `\YOOtheme\app(\YOOtheme\Builder::class)->getTypes()`; `json_encode` resuelve los campos compartidos. `YOOtheme\Config` es una **interfaz** (usar `interface_exists`, no `class_exists`).
- **Plantillas**: se aplica la primera activa (orden = prioridad) cuyo tipo y condiciones encajan; una página con layout propio no usa plantillas.- **Widgets de builder** (`builderwidget-N`): guardan su layout como JSON en texto en la clave `content` de la instancia. Raíz `"type":"fragment"` en header, navbar, sidebar…; en `top` y `bottom` YOOtheme imprime el widget sin sección alrededor (`templates/position.php`, estilo `section`), así que la raíz es `"type":"layout"` con secciones. Se editan con `update-widget` o con las abilities de layout y `"area": "widget:builderwidget-N"`.
- **Fuentes dinámicas**: un elemento puede tener `"source": {"query": {"name": "customMenuItems", "arguments": {...}}, "props": {...}}`. Hay que conservarlas al editar.
- **Rutas de imagen** en props: relativas a la raíz, p. ej. `wp-content/uploads/2024/11/logo.webp`.
- **Los estilos (LESS) se compilan en el navegador**, en el Personalizador. Cambiar `config.style`, `config.less` o `config.custom_less` en la BD no cambia el CSS servido hasta recompilar. Si `StylerConfig['update']` es true, abrir el Personalizador recompila y guarda el CSS solo; este plugin lo fuerza mientras exista la opción `spiral_mcp_style_dirty` (ver `includes/styles.php`). El CSS va a `themes/yootheme/css/theme.<blog>.css` y las fuentes de Google se descargan a `themes/yootheme/fonts/`.
- **Cachés**: cada herramienta es un adaptador en `spiral_mcp_cache_providers()` (filtro del mismo nombre) con `detect`, `supports`, callables (`purge_all`, `purge_post`, `purge_url`, `preload`, `settings`, `write_settings`, `status`) y `writable` (claves que acepta `write_settings`; `restore-snapshot` solo reescribe esas). Las abilities de caché llevan `'log' => ['type' => 'cache']`: el wrapper no hace purga automática tras ellas (si no, una purga por URL acabaría purgando todo). WP Super Cache guarda sus ajustes en variables globales cargadas de `wp-content/wp-cache-config.php`; `wp_cache_post_change()` purga el post, su autor, la portada y la página de entradas. Para escribir ajustes se usa una subclase de `WP_Super_Cache_Rest_Update_Settings` (sus métodos hacen los efectos secundarios); ojo con sus fallos: pierde los ajustes cuya clave REST no coincide con la variable, y al guardar caducidad o precarga activa casillas que no se le pasan (`cache_gc_email_me`, `preload_on`, `preload_taxonomies`): pásaselas siempre con su valor actual.
- **El CSS por elemento** (prop `css` de una sección o elemento) sí se procesa en el servidor al renderizar.
- **Shortcodes**: YOOtheme los ejecuta dentro de un elemento `text` (comprobado con el de Contact Form 7). En el JSON del layout las comillas van escapadas (`id=\"a58ff5f\"`): quien busque shortcodes en `post_content` debe contar con ello.
- **Librería online** (`https://api.yootheme.com/v1/library/layout` y `/element`): el catálogo es público; cada diseño se descarga en `<link>?key=<yootheme_apikey>`. El JSON trae `images` (`src` firmado que caduca en 1 h + `md5`): hay que descargarlas a `uploads` sin subcarpeta (deduplicando por md5, como `BuilderController::loadImage`) y cambiar las URLs. Las plantillas de la librería son contenido de muestra fijo: no traen fuentes dinámicas.
- `config.yootheme_apikey` es un secreto: no devolverlo nunca en una respuesta (enmascararlo). Se usa solo en el servidor (`spiral_mcp_online_fetch()`); los errores pasan por `spiral_mcp_online_scrub()`.

## Lo que ya sabemos de Yoast SEO (28.7, comprobado en local)
- Ajustes generales en tres opciones: `wpseo` (funciones, verificación, rastreo y mucho estado interno: tokens, indexación, versión), `wpseo_titles` (plantillas de título, tipos de contenido, taxonomías, archivos, Schema, migas, RSS) y `wpseo_social`. Las claves por tipo de contenido o taxonomía llevan su nombre (`title-page`, `noindex-tax-post_tag`).
- Escribir con `update_option()` de la opción entera mezclada (como `WPSEO_Options::save_option()`): pasa por `sanitize_option_<opción>` → `WPSEO_Option::validate()`, que descarta en silencio lo que no valida (se conserva el valor anterior), y por los watchers `update_option_wpseo_titles` (p. ej. `stripcategorybase` regenera las reglas de reescritura y redirige `/category/x/` con 301). Después, `WPSEO_Options::clear_cache()`. No exige `manage_options`.
- Yoast gratuito ignora las plantillas `social-title-*`, `social-description-*` y `social-image-*` por tipo de contenido y taxonomía (son de Premium); la imagen por defecto al compartir es `wpseo_social.og_default_image(_id)`.
- Metas por contenido: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`… (ver `includes/seo.php`).

## Lo que ya sabemos de WooCommerce (11.2.1, comprobado en local)
- **Todo por su API REST por dentro**: `spiral_mcp_wc_rest( $method, 'products/12', $params )` hace `rest_do_request()` a `/wc/v3/…` con cuerpo JSON (las ubicaciones de una zona esperan una lista) y concede `woocommerce_rest_check_permissions` solo durante la llamada: todas las comprobaciones de wc/v3 pasan por ese filtro (`wc_rest_check_post_permissions`, `_manager_`, `_product_term_`, `_user_`). Así el rol del agente no necesita `manage_woocommerce` ni `edit_products`, y su Application Password no puede llamar a wc/v3 por su cuenta. Los permisos y las listas blancas los ponen las abilities; ojo: con el filtro concedido, `customers/<id>` devuelve cualquier usuario (por eso `get-customer` comprueba el rol `customer`).
- Campos escribibles = propiedades del esquema sin `readonly` (`OPTIONS` sobre la colección; `spiral_mcp_wc_writable()`). Los snapshots guardan el GET (`context=edit`) y restauran con PUT de esos campos; si el objeto ya no existe, lo vuelven a crear (otro ID); un snapshot `null` (creación) se restaura borrando.
- **Imágenes solo por ID** (`images: [{"id": 12}]`): con `src`, WooCommerce descargaría la URL.
- **Atributos recién creados**: su taxonomía `pa_<slug>` no está registrada hasta la siguiente petición; para crear términos en la misma hay que registrarla al vuelo.
- **Reembolsos**: pasar siempre `api_refund` y `api_restock` explícitos. Con el cálculo de totales nuevo, WooCommerce toma `api_refund = true` si no es booleano (devolvería el dinero por la pasarela).
- **Ajustes**: `GET /settings` lista grupos; `site-visibility` sale sin campos (pantalla React): son las opciones `woocommerce_coming_soon` y `woocommerce_store_pages_only` (`yes`/`no`). Una tienda nueva arranca en "próximamente" (los visitantes ven "Great things are on the horizon"). El grupo `advanced` mezcla las páginas y endpoints con interruptores peligrosos (`woocommerce_custom_orders_table_enabled` migra los pedidos entre tablas): `spiral_mcp_wc_settings_hidden()` los oculta.
- **Pedidos**: HPOS activo por defecto (los pedidos no son posts); la API lo resuelve. `wc_create_order( array( 'status' => 'processing' ) )` marca el stock como reducido antes de tener líneas: en pruebas, crear en pendiente y luego cambiar el estado.
- YOOtheme muestra la ficha de producto de WooCommerce con su plantilla (precio con la moneda y formato de los ajustes).

## Entorno de desarrollo
- WordPress local de pruebas con YOOtheme Pro, MCP Adapter y este plugin enlazado (junction en `wp-content/plugins/Spiral-MCP-Control`).
- Nunca probar contra un sitio en producción.
- Entorno local actual: `D:\web-data\www\wordpress-dev` (Laragon), compartido con los demás plugins del repo paraguas `D:\WP-Plugin-Develop` (ver su `docs/entorno-local.md`). WordPress 7.1.3, prefijo `wp_`, base de datos `wordpress-dev`, `home` = `https://wordpress-dev.test`. El plugin está enlazado con `mklink /J D:\web-data\www\wordpress-dev\wp-content\plugins\Spiral-MCP-Control D:\WP-Plugin-Develop\Spiral-MCP-Control`: no borrarlo ni actualizarlo desde wp-admin (borraría o sobrescribiría el repo). Enlaces permanentes `/%postname%/` (funciona `/wp-json/`). Apache de Laragon usa mod_fcgid, que no pasa la cabecera `Authorization` a PHP: el `.htaccess` del sitio lleva `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1` (fuera del bloque de WordPress) para que funcionen las Application Passwords. `smoke.sh` contra el dominio real puede usar `SMOKE_INSPECT=1`.
- Comprobación mínima antes de cada commit: `php -l` en todos los archivos PHP y `tests/smoke.sh`:
  `WP_URL=https://wordpress-dev.test WP_USER=<usuario con rol spiral_mcp_agent> WP_APP_PASSWORD='…' bash tests/smoke.sh`
- **Ruta REST de la Abilities API** (comprobada en WP 7.1.3): `/wp-abilities/v1/abilities` (listado) y `/wp-abilities/v1/abilities/<nombre>/run` (ejecutar). El método HTTP depende de las anotaciones: `GET` si `readonly`, `DELETE` si `destructive` e `idempotent`, `POST` en el resto. En `GET`/`DELETE` la entrada va en la query (`input[id]=5`) y el core la convierte a los tipos del esquema; en `POST`, en el cuerpo JSON `{"input": {…}}`. Con enlaces permanentes simples: `/?rest_route=/wp-abilities/v1/abilities/spiral-mcp/list-content/run`. Las Application Passwords exigen HTTPS (o `WP_ENVIRONMENT_TYPE` = `local`).

## Versionado y entrega
- SemVer en la cabecera del plugin (`Version:`) y en `SPIRAL_MCP_VERSION`. `SPIRAL_MCP_VERSION` es también la versión que se envía a SPUpdater: si no coincide con la del release, WordPress ofrece la actualización una y otra vez.
- Cada versión anota sus cambios en `CHANGELOG.md`.
- ZIP: carpeta raíz `spiral-mcp-control/`, sin `.git`, `dist/`, `tests/`, `docs/` ni `skills/` (p. ej. `git archive --format=zip --prefix=spiral-mcp-control/ -o dist/spiral-mcp-control-X.Y.Z.zip HEAD -- spiral-mcp-control.php uninstall.php includes LICENSE CHANGELOG.md`).
- Publicación: en `https://plugins.rasenfx.com/admin` → **Releases → New**, subir el ZIP del producto `spiral-mcp-control` (no en plugins.nkcore.com, que es el servidor de nktools).
- Al terminar una fase: actualizar las casillas de `docs/ROADMAP.md`.
