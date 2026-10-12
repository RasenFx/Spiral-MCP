# Changelog

Todos los cambios relevantes de Spiral-MCP-Control. Versionado [SemVer](https://semver.org/lang/es/).

## [1.16.0] — 2026-10-12 · Fase 6.3: más herramientas de caché

### Añadido
- Adaptadores de purga y estado (`includes/cache-providers.php`) para WP Fastest Cache, W3 Total Cache, LiteSpeed Cache, SiteGround Optimizer, Nginx Helper, WP Rocket y las cachés de WP Engine, Pantheon y Kinsta. Cada uno informa de su estado (activada, motor, caducidad, archivos…) y avisa cuando la caché no puede funcionar en ese servidor (LiteSpeed fuera de LiteSpeed, SiteGround fuera de su hosting, Nginx Helper sin Nginx).
- Probados en local con la versión actual de cada plugin gratuito; `tested` en `get-cache-status` dice cuáles.

### Cambiado
- `purge-cache` con `"scope": "url"`: si una herramienta no sabe purgar por URL pero la URL es de un contenido, purga ese contenido en lugar de todo.

## [1.15.0] — 2026-10-11 · Fase 6.2: ajustes de caché

### Añadido
- `spiral-mcp/update-cache-settings`: cambia los ajustes de una herramienta de caché (vocabulario común + `provider_settings`), validando todo antes de escribir. Snapshot `cache_settings` antes; `restore-snapshot` lo deshace. Los modos que reescriben `.htaccess` (Expert de WP Super Cache) no se pueden activar.
- Capacidad `spiral_mcp_cache` (grupo `cache`): la reciben los administradores; el rol del agente **no** por defecto (filtro `spiral_mcp_agent_caps` para concederla).
- Adaptador de escritura de WP Super Cache, que reutiliza su propia lógica de guardado y sortea tres fallos de su API REST (ajustes perdidos, aviso por correo de la recogida de basura y casillas de precarga activadas sin pedirlo).

## [1.14.0] — 2026-10-11 · Fase 6.1: cachés independientes de la herramienta

### Añadido
- **Adaptadores de caché** (`includes/cache.php`, filtro `spiral_mcp_cache_providers`): cada herramienta declara si está activa y qué sabe hacer. Incluidos: WP Super Cache (completo y probado), WP Rocket, LiteSpeed Cache y W3 Total Cache (purga total, por post y por URL según su API pública; sin probar) y la caché de objetos.
- `spiral-mcp/get-cache-status`: herramientas activas, capacidades y estado (activada, modo, caducidad, precarga, archivos y tamaño).
- `spiral-mcp/purge-cache`: todo, un post o una ruta relativa, en todas las herramientas activas o en una.
- `spiral-mcp/preload-cache`: lanzar, parar o consultar la precarga.
- `spiral-mcp/get-cache-settings`: ajustes con un vocabulario común (enabled, lifetime, cache_logged_in, mobile, compression, exclude_urls, clear_on_edit) más los propios de cada herramienta.

### Cambiado
- La purga automática tras cada escritura es dirigida: si el cambio afecta a un solo contenido, purga ese contenido (y lo que la herramienta asocie: portada, listados); si es de diseño global, todo.
- `clear-cache` pasa a ser un alias obsoleto de `purge-cache`.

## [1.13.0] — 2026-10-11

### Añadido
- `spiral-mcp/get-layout-texts`: lista todos los textos visibles de un layout (y, si se pide, enlaces e imágenes) con su ruta JSON Pointer lista para `patch-layout`, según el catálogo de elementos: incluye títulos y metas de items, textos de botones, etiquetas y mensajes de formularios, títulos de sección, alt y ajustes anidados (p. ej. `props/provider/message` de un newsletter). Marca los valores HTML y los que rellena una fuente dinámica.
- Skill: adaptar o traducir un diseño importado con `get-layout-texts` (receta de la librería online y flujo de edición).

## [1.12.0] — 2026-10-11

### Añadido
- **Librería online de YOOtheme** (`includes/online-library.php`):
  - `spiral-mcp/list-online-library`: catálogo de diseños (plantillas, páginas, footers, mega menús, búsqueda, diálogos) y de elementos prediseñados, con filtros por grupo, tipo, web de ejemplo y texto, `facets` y, en plantillas, el tipo de plantilla del sitio equivalente. Público, cacheado 12 h.
  - `spiral-mcp/get-online-library-item`: descarga un diseño con la clave de API y devuelve su esquema, nº de imágenes y de fuentes dinámicas, sin importarlo.
  - `spiral-mcp/import-from-online-library`: importa como plantilla (desactivada por defecto), en la biblioteca del sitio o insertado en una página o área. Descarga las imágenes a la biblioteca de medios (sin duplicar, por md5) y cambia sus URLs; aplica las migraciones de YOOtheme. La clave de API nunca sale en las respuestas.
- `insert-from-library` (y la importación) en una página que aún no usa el builder: el layout del diseño pasa a ser el de la página.
- `tests/smoke.sh`: bloque `SMOKE_ONLINE=1` para la librería online.

## [1.11.2] — 2026-10-11

### Corregido
- Skill: la fuente dinámica de una entrada es `posts.singlePost` (la receta decía `template.posts.singlePost`, con la que la plantilla salía vacía). Nueva receta para la página del blog con una rejilla de entradas (`archive-post` + `posts.archivePost` en el `grid_item`).
- `create-template` / `update-template`: la descripción indica los nombres de las consultas de entradas.

## [1.11.1] — 2026-10-11

### Corregido
- Los widgets de builder en `top` y `bottom` no se podían editar con raíz `layout`: el área `widget:<ID>` exigía siempre `fragment`. En esas posiciones YOOtheme imprime el widget sin sección alrededor, así que con `fragment` sale sin contenedor ni márgenes. Ahora la raíz del área es la del layout guardado (o, si no hay, `layout` en top/bottom y `fragment` en las demás), y `create-widget` / `update-widget` avisan en `warnings` si la raíz no encaja con el área. `update-widget` devuelve también los avisos de validación del layout.
- Skill y descripciones: raíz de los widgets de builder según el área.

## [1.11.0] — 2026-10-11

### Añadido
- `spiral-mcp/create-widget`: crea un widget de cualquier tipo registrado (bloques, HTML, texto, menú, builder de YOOtheme con "layout"…) en un área y posición. La configuración pasa por el `update()` del widget, como en wp-admin. Snapshot para deshacer.
- `spiral-mcp/delete-widget`: borra un widget (área y configuración), con `confirm` y snapshot.
- `list-widgets` devuelve los tipos disponibles (`types`) y describe las áreas de YOOtheme; `update-widget` admite `position` para reordenar.
- Ciclo completo de widgets en `tests/smoke.sh` (crear, editar, restaurar, patch de un widget de builder y borrar), con widgets desactivados para no tocar el sitio.

## [1.10.2] — 2026-10-11

### Corregido
- `update-theme-style` rechazaba cualquier estilo (p. ej. `"circle"`) y listaba los disponibles como números: `getThemes()` de YOOtheme devuelve una lista y el ID del estilo va en `id`. `get-theme-style` devuelve ahora cada estilo con su `id`, nombre y variaciones.

## [1.10.1] — 2026-10-11

### Cambiado
- `get-theme-config {"section": "logo"}` devuelve `known_keys` (claves válidas y qué hace cada una), porque YOOtheme no tiene valores por defecto para el logo; `update-theme-config` avisa de claves desconocidas en `logo` y de un logo con imagen pero sin `text`, que YOOtheme usa como alt.
- Skill: la receta del logo explica `known_keys`, el alt (`text`), el tamaño en px y las variantes móvil e inversa.

## [1.10.0] — 2026-10-11 · Correcciones tras probar el skill con casos reales

### Corregido
- **Guardar la configuración del tema quitaba las ubicaciones a los menús.** El evento `config.save` de YOOtheme reescribe `nav_menu_locations` desde `config.menu.positions`, que en la BD estaba desfasado. Ahora se sincroniza antes de guardar.
- **`restore-snapshot` deshacía sin avisar los cambios posteriores** del mismo objeto (p. ej. restaurar un cambio de color deshacía también el footer creado después). Ahora devuelve `spiral_mcp_snapshot_later` (HTTP 409) con la lista de cambios posteriores y, en `theme_config`, las secciones en que difiere; se puede restaurar solo una parte con `"parts"` (p. ej. `["less"]`) o todo con `"include_later": true`. El alias obsoleto `restore-theme-backup` mantiene su comportamiento.
- **Opciones de select agrupadas** (p. ej. `animation`: `fade`, `scale-up`…) salían como un valor `"3"` en `get-element-schema` y la validación de layouts avisaba de valores válidos.
- **`inspect-page`**: los enlaces a la propia vista previa (p. ej. "Cancel reply") salían rotos y mostraban el token; ahora se marcan `self`, sin token ni comprobación.

### Cambiado
- `inspect-page`: los encabezados llevan `in_content` (false si son del tema: barra lateral, widgets, comentarios) y los avisos de h1 y saltos de nivel solo miran el contenido; los enlaces se agrupan por destino (`count`, `texts`); un enlace roto a contenido sin publicar trae `target_id` y `target_status`; `preview` y `notes` explican el `noindex` de las vistas previas.
- `get-element-schema`: lista las opciones de los campos `select-img` (p. ej. el `layout` de las filas), omite los controles internos del editor (`_source*`, botones de panel) y explica `layout` + `width_*` en filas.
- `get-site-settings` devuelve `timezone_string`, la zona efectiva (`timezone`), la hora local (`local_time`) y `blog_public`; `update-site-settings` acepta `timezone_string` (zona IANA validada).
- Los menús avisan (`warnings` y `object_status` en cada elemento) de los elementos que enlazan a contenido sin publicar.
- `list-content` acepta `post_type: "any"`.
- `update-yootheme-layout` y `patch-layout` (en páginas) devuelven `revision_id` y `undo_revision_id`.
- `list-style-variables`: la descripción aclara que los grupos `primary`/`secondary` son fuentes y que el color principal es `@global-primary-background`.
- Skill `spiral-mcp-wordpress`: cómo deshacer un cambio concreto sin pisar otros, enlaces a contenido publicado, fechas y zona horaria, receta para crear el menú principal, ejemplos completos de landing y footer, y nuevos errores frecuentes.

## [1.9.0] — 2026-10-11 · Fase 5: verificación y flujo de trabajo

### Añadido
- `spiral-mcp/get-preview-url`: URL temporal (1 h por defecto, máximo 24 h) para ver borradores, pendientes o programados sin sesión. Token firmado (HMAC) de un solo post, revocable; la vista previa sale con noindex y sin caché.
- `spiral-mcp/revoke-preview-url`: revoca un token o todos los de un contenido.
- `spiral-mcp/inspect-page`: renderiza una página (por ID, también borradores, o por ruta relativa) y devuelve código HTTP, SEO efectivo, encabezados, enlaces internos (con los rotos) y externos, imágenes sin alt y avisos. Filtro `spiral_mcp_inspect_base_url` para servidores que no se alcanzan por su dominio.
- `docs/agent-guide.md`: guía para el agente (principios, convenciones, mapa de abilities, flujo recomendado, recetas y errores frecuentes).
- Pruebas de vista previa en `tests/smoke.sh` (`SMOKE_INSPECT=1` para `inspect-page`).

## [1.8.0] — 2026-10-11 · Fase 4: estilos

### Añadido
- **CSS adicional** (`includes/styles.php`): `get-custom-css` y `update-custom-css` (reemplazar o añadir al final; sin HTML y con llaves equilibradas; revisión de WordPress y snapshot `custom_css`). Se aplica al instante.
- **Estilo de YOOtheme**: `get-theme-style` (estilo, variables cambiadas, LESS propio, estilos disponibles), `list-style-variables` (catálogo del estilo activo con valor por defecto, componente, grupo y tipo, como en el Personalizador; cacheado por estilo y versión) y `update-theme-style` (estilo/variación, merge de variables LESS con validación, LESS propio; snapshot).
- **Recompilación**: `get-style-status` y `mark-style-compiled`. Mientras haya cambios de estilo pendientes, el plugin fuerza el flag `update` del Styler de YOOtheme: abrir la URL de recompilación en un navegador con sesión recompila y guarda el CSS sin pulsar nada; el pendiente se quita solo al detectar el CSS nuevo.

### Cambiado
- Restaurar un snapshot de la configuración que cambie el estilo marca el CSS como pendiente de recompilar.
- `uninstall.php` borra también la opción `spiral_mcp_style_dirty`.

## [1.7.0] — 2026-10-11 · Fase 1: contenido completo

### Añadido
- `spiral-mcp/list-post-types`. Las abilities de contenido aceptan todos los tipos públicos con interfaz (no solo page y post); el filtro `spiral_mcp_post_types` sigue permitiendo restringirlos.
- `create-content` y `update-content` admiten `parent` (sin ciclos), `menu_order`, `template` (validada contra las plantillas del tema), `author`, `date` (fecha futura + publish = programar), `comment_status`, `terms` (`{taxonomía: [IDs, slugs o nombres]}`), `featured_media` y `meta` (lista blanca `spiral_mcp_allowed_meta`). Todo se valida antes de escribir. `get-content` devuelve la ficha completa.
- `trash-content`, `restore-content` (recupera el estado anterior) y `delete-content` (solo desde la papelera, con `confirm`).
- `list-revisions` (con los campos que difieren del estado actual) y `restore-revision` (con `confirm`; antes guarda el estado actual como revisión).
- Taxonomías: `list-taxonomies`, `list-terms`, `create-term`, `update-term`, `delete-term`. Snapshot `term`: deshacer un borrado recrea el término y se lo vuelve a asignar a sus contenidos.
- SEO: `get-seo` y `update-seo` (title, description, og_title, og_description, canonical, noindex). Usa Yoast SEO si está activo; Rank Math tiene su mapa preparado pero aún no está soportado; sin plugin de SEO, Spiral-MCP imprime los metadatos. Snapshot `seo`.
- Ajustes del sitio: `get-site-settings` y `update-site-settings` (nombre, lema, portada, página de entradas, icono), grupo `settings`, snapshot `option`.

### Cambiado
- `list-content` filtra también por `parent` y por los estados `future` y `trash`.
- El rol `spiral_mcp_agent` recibe `delete_published_*`, `delete_others_*`, `delete_private_*`, `edit_private_posts` y `read_private_posts` (necesarios para la papelera).

## [1.6.0] — 2026-10-11 · Fase 3: YOOtheme completo

### Añadido
- **Catálogo de elementos** (`includes/elements.php`): `list-elements` y `get-element-schema`, leídos de la API del propio YOOtheme (`Builder::getTypes()`), con opciones de los select, valores por defecto y props de hijos (anchos de columna).
- **Validación de layouts** en `update-yootheme-layout`, `update-theme-layout`, `update-widget`, `patch-layout`, plantillas e inserciones de biblioteca: error (no se guarda) con tipos desconocidos o anidados imposibles; avisos (`warnings`) con props desconocidos o valores fuera de sus opciones.
- **Edición por partes** (`includes/layout.php`): `get-layout-outline` (una línea por nodo con su ruta JSON Pointer), `get-layout-node` y `patch-layout` (add, remove, replace, move, copy, test; atómico) sobre páginas, footer, mega menús, widgets de builder y plantillas. `duplicate-content`.
- **Configuración de YOOtheme** (`includes/theme-config.php`): `get-theme-config` (secciones, valores con secretos ocultos y valores por defecto) y `update-theme-config` (merge profundo o `replace`, lista blanca `spiral_mcp_theme_config_sections`, `scripts` con `spiral_mcp_code`, avisos de claves desconocidas, snapshot).
- **Plantillas** (`list-templates` con catálogo de tipos, `get-template`, `create-template`, `update-template`, `delete-template`) y **biblioteca** (`list-library`, `get-library-item`, `save-to-library`, `insert-from-library`), escritas con el Storage de YOOtheme y con snapshot `yootheme_storage`.
- **Caché**: `clear-cache` y purga automática de cachés de página (LiteSpeed, WP Rocket, W3TC, WP Super Cache) tras cada escritura de contenido, diseño o medios (filtro `spiral_mcp_auto_clear_cache`).

### Cambiado
- `get-theme-layout` / `update-theme-layout` admiten todas las áreas con builder: `footer`, `menu_item:<ID>` (mega menú), `widget:<ID>` (widgets de builder) y `template:<id>`; `get-theme-layout` devuelve `settings` y la lista `areas` (antes `area_config` y `config_keys`).
- Los layouts se guardan con `version` en la raíz (la de YOOtheme) si falta, para que YOOtheme no les aplique migraciones antiguas. Fixtures actualizados a 5.0.51.
- La configuración del tema se escribe pasando por el filtro `config.save` de YOOtheme, como el Personalizador.

### Corregido
- `update-widget` y `list-widgets` aceptan los layouts con raíz `fragment` de los widgets de builder (antes exigían `layout`).

## [1.5.0] — 2026-10-11 · Fase 2: medios

### Añadido
- Módulo `includes/media.php` (grupo de permisos `media`).
- `spiral-mcp/upload-media`: sube desde una URL `https` pública o desde base64 (+ `filename`), con `title`, `alt`, `caption`, `description` y `attach_to`. Tipos: jpg, jpeg, png, webp, gif, avif, pdf, mp4, webm (filtro `spiral_mcp_upload_types`). SVG solo con el filtro `spiral_mcp_allow_svg`. Límite `wp_max_upload_size()`. Protección SSRF: solo https al puerto 443, sin credenciales; rechaza IPs privadas, reservadas y de loopback (también en redirecciones, vía `wp_safe_remote_get`). Valida el tipo real del archivo.
- `spiral-mcp/list-media`, `get-media`: salida con `id`, `url`, `path_relative` (formato de los props de imagen de YOOtheme), `width`, `height`, `mime`, `alt`, textos y `sizes`.
- `spiral-mcp/update-media`: alt, title, caption y description, con snapshot `media_meta`.
- `spiral-mcp/delete-media` (con `confirm`): papelera si `MEDIA_TRASH`; si no, borrado definitivo (la descripción lo indica según el sitio).
- `spiral-mcp/set-featured-image` (`media_id` 0 = quitar), con snapshot `post_meta`.
- `spiral-mcp/find-media-usage`: busca el adjunto en contenido y layouts de posts, imagen destacada, configuración del tema, widgets, logo e icono del sitio.
- Tipos de snapshot `media_meta` y `post_meta`.
- Pruebas de medios en `tests/smoke.sh` (variable `SMOKE_MEDIA_URL`).

## [1.4.0] — 2026-10-11 · Fase 0: base segura

### Añadido
- **Registro de auditoría**: tabla `{prefix}spiral_mcp_log`. Cada ejecución de una ability que escribe guarda usuario, ability, objeto, entrada (redactada y truncada a 8 KB), resultado, snapshot y duración. Las lecturas no se registran.
- Ability `spiral-mcp/list-activity` (filtros por ability, objeto, usuario, resultado y fechas; paginada).
- **Snapshots genéricos**: tabla `{prefix}spiral_mcp_snapshots`. Antes de cada cambio en la configuración de YOOtheme, un menú (árbol completo y ubicaciones) o un widget (su opción y `sidebars_widgets`) se guarda una copia.
- Abilities `spiral-mcp/list-snapshots` y `spiral-mcp/restore-snapshot` (con `confirm`). Restaurar guarda antes el estado actual, así que también se puede deshacer.
- Las abilities de menús, `update-theme-layout` y `update-widget` devuelven `snapshot_id`.
- **Permisos**: capacidades `spiral_mcp_content`, `spiral_mcp_design`, `spiral_mcp_media`, `spiral_mcp_settings` y `spiral_mcp_code`; rol `spiral_mcp_agent` (sin `install_plugins`, `edit_plugins`, `manage_options`, `promote_users` ni `delete_users`). Los administradores reciben todas las capacidades propias.
- Limpieza diaria por WP-Cron: registro y snapshots de más de 90 días (filtros `spiral_mcp_log_retention_days` y `spiral_mcp_snapshot_retention_days`).
- `uninstall.php`: elimina el rol y las capacidades; las tablas solo si `spiral_mcp_uninstall_drop_tables` devuelve true.
- `tests/smoke.sh` y `tests/fixtures/` (página simple, página con fuentes dinámicas y footer).

### Cambiado
- Reorganización: el contenido pasa a `includes/content.php`; helpers en `includes/core.php`; nuevos `permissions.php`, `activity.php`, `snapshots.php` e `install.php`. El archivo principal solo tiene cabecera, constantes, requisitos y `require_once`.
- Todas las abilities se registran con `spiral_mcp_register_ability()`, que exige la capacidad `spiral_mcp_*` del grupo además de la nativa, y audita las escrituras. Nombres y esquemas de entrada sin cambios.
- Las abilities sin campos obligatorios aceptan llamadas sin `input` (antes fallaban con `ability_invalid_input`).
- `update-widget` valida el área destino antes de escribir (antes podía guardar la instancia y luego fallar).
- Las copias de la configuración de YOOtheme (opción de la 1.3.0) se migran a la tabla de snapshots y se borra la opción.

### Obsoleto
- `spiral-mcp/list-theme-backups` y `spiral-mcp/restore-theme-backup`: siguen funcionando como alias de `list-snapshots` / `restore-snapshot` (aceptan también los IDs antiguos). Se eliminarán en la próxima versión.

## [1.3.0]

- Abilities de contenido y layouts de YOOtheme, menús, footer de YOOtheme (builder), copias de la configuración del tema y widgets.
