# Spiral-MCP-Control — Roadmap de desarrollo

**Objetivo de esta etapa:** que un agente de IA pueda controlar por completo un sitio WordPress con YOOtheme Pro (contenido, diseño, medios, menús, estilos y ajustes) mediante abilities expuestas por MCP, sin entrar al wp-admin.

**Criterio de éxito global:** desde un cliente MCP, el agente puede (1) crear una página completa con imágenes propias subidas por él, (2) cambiar el header, el footer y la paleta de marca, (3) previsualizar el resultado antes de publicar y (4) deshacer cualquier cambio. Todo con registro de auditoría.

**Orden de las fases:** 0 → 2 → 3 → 1 → 4 → 5 → 6 → 7 → 8. Medios y YOOtheme son lo que más bloquea el trabajo de diseño hoy.

Leyenda: `[ ]` pendiente · `[x]` hecho · **Spike** = investigar en el entorno local antes de implementar, y anotar el resultado en este documento.

---

## Fase 0 — Base segura

Antes de dar más poder al agente: trazabilidad, deshacer y permisos acotados.

### 0.1 Reorganización
- [x] Mover las abilities de contenido de `spiral-mcp-control.php` a `includes/content.php`. El archivo principal queda con la cabecera, las constantes (`SPIRAL_MCP_VERSION`, `SPIRAL_MCP_DIR`), la comprobación de requisitos y los `require_once`.
- [x] `includes/core.php` con los helpers compartidos:
  - `spiral_mcp_register_ability( $name, $args )`: envuelve `wp_register_ability`. Envuelve `execute_callback` para registrar en la auditoría, rellena `meta` por defecto y fuerza el prefijo `spiral-mcp/`.
  - `spiral_mcp_meta( $readonly, $destructive, $idempotent )`.
  - `spiral_mcp_json( $data )`: `wp_json_encode` con los flags del proyecto.
  - `spiral_mcp_require_confirm( $input )`: devuelve un `WP_Error` si falta `"confirm": true`.
- [x] Migrar todas las abilities existentes al wrapper, sin cambiar sus nombres ni sus esquemas.
- [x] Añadir `.gitignore` (`dist/`, `node_modules/`, `.idea/`) y `CHANGELOG.md`.

### 0.2 Registro de auditoría
- [x] Tabla `{prefix}spiral_mcp_log`, creada con `dbDelta` en la activación y en cada actualización de versión: `id, created_at, user_id, ability, object_type, object_id, input_json, result ('ok'|código de error), snapshot_id, duration_ms`.
- [x] El wrapper registra **solo las abilities que escriben** (`readonly = false`). `input_json` se trunca a 8 KB y se redactan las claves sensibles (`password`, `token`, `apikey`, `yootheme_apikey`, `key`).
- [x] Ability `spiral-mcp/list-activity`: filtros por ability, objeto, usuario y fechas, con paginación. Solo lectura.
- [x] Retención configurable (filtro `spiral_mcp_log_retention_days`, 90 días por defecto) con limpieza diaria vía WP-Cron.

### 0.3 Snapshots genéricos
- [x] Tabla `{prefix}spiral_mcp_snapshots`: `id, created_at, user_id, kind, ref, data (longtext), note`.
  - `kind`: `theme_config`, `menu`, `widgets`, `option`, `custom_css`, `post_meta`, `media_meta`.
- [x] Helpers `spiral_mcp_snapshot_take( $kind, $ref, $data, $note )` y `spiral_mcp_snapshot_restore( $id )`. Restaurar también guarda un snapshot del estado actual.
- [x] Usar los snapshots en todo lo que escribe fuera de posts: theme config, menús (el árbol completo antes de cada cambio), widgets (`sidebars_widgets` y la opción `widget_<base>` afectada).
- [x] Migrar `rasenfx_ca_yootheme_config_backups` / `spiral_mcp_yootheme_config_backups` (opción de la 1.3.0) a la tabla y borrar la opción. `list-theme-backups` y `restore-theme-backup` pasan a `list-snapshots` y `restore-snapshot` (con `confirm`). Se mantienen los nombres viejos como alias durante una versión.

### 0.4 Permisos
- [x] Capacidades propias: `spiral_mcp_content`, `spiral_mcp_design` (builder, menús, widgets, estilos), `spiral_mcp_media`, `spiral_mcp_settings`, `spiral_mcp_code` (scripts y JS personalizado: el riesgo es mayor).
- [x] Rol `spiral_mcp_agent` con esas capacidades más las nativas necesarias (`edit_pages`, `edit_others_pages`, `publish_pages`, `edit_posts`, `upload_files`, `edit_theme_options`…), sin `install_plugins`, `edit_plugins`, `manage_options`, `promote_users` ni `delete_users`.
- [x] Los administradores reciben todas las capacidades `spiral_mcp_*` en la activación.
- [x] Cada ability comprueba la capacidad `spiral_mcp_*` de su grupo y, además, la nativa del objeto (`edit_post` para ese ID, etc.).
- [x] La desinstalación (`uninstall.php`) elimina el rol y las capacidades. **No** borra las tablas salvo que el filtro `spiral_mcp_uninstall_drop_tables` devuelva true.

### 0.5 Pruebas
- [x] `tests/smoke.sh`: llama por REST a cada ability con una Application Password del rol `spiral_mcp_agent` contra el WordPress local. Comprueba códigos HTTP y claves de la respuesta. Variables: `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`.
- [x] `tests/fixtures/`: layouts de YOOtheme de ejemplo (página simple, página con fuentes dinámicas, footer).
- [x] Spike: comprobar la ruta REST de la Abilities API en el entorno local y anotarla en `CLAUDE.md`. **Resultado (WP 7.1.3):** `/wp-abilities/v1/abilities/<nombre>/run`; `GET` si readonly, `DELETE` si destructive + idempotent, `POST` en el resto. Detalle en `CLAUDE.md`.

**Terminado cuando:** todas las abilities existentes pasan por el wrapper, cada escritura queda en el log, cualquier cambio de menú, widget o theme config se puede restaurar, y `smoke.sh` pasa con el rol `spiral_mcp_agent`.

---

## Fase 2 — Medios

Sin medios, el agente no puede poner logos ni fotos en los diseños.

- [x] `spiral-mcp/upload-media`. Entrada: `url` **o** `data_base64` + `filename`; además `title`, `alt`, `caption`, `description`, `attach_to` (ID de post).
  - Usa `download_url()` + `media_handle_sideload()`. Para base64: escribir a un temporal y usar el mismo camino.
  - Tipos permitidos: jpg, jpeg, png, webp, gif, avif, pdf, mp4, webm. **SVG no** (riesgo de XSS) salvo que esté activo un plugin de saneado de SVG (filtro `spiral_mcp_allow_svg`).
  - Límite de tamaño: `wp_max_upload_size()`. Solo URLs `https`, y rechazar IPs privadas y loopback (SSRF).
  - Salida: `id`, `url`, `path_relative` (`wp-content/uploads/…`, el formato que usan los props de imagen de YOOtheme), `width`, `height`, `mime`, `alt` y `sizes`.
- [x] `spiral-mcp/create-upload-url` (v1.27): enlace de un solo uso para subir un archivo local por POST multipart sin base64 (con base64 el agente escribe el archivo entero como texto: lento). `upload-media` devuelve `timings` y ya no purga la caché. v1.28: token en la cabecera `X-Spiral-Upload-Token` (fuera de los logs), consumo atómico y límite de tamaño propio (ajuste `upload_link_max_mb`).
- [x] `spiral-mcp/list-media`: filtros `search`, `mime` (image, video, application/pdf), `parent`, paginación. Devuelve lo mismo que la salida de `upload-media`.
- [x] `spiral-mcp/get-media`: un adjunto por ID.
- [x] `spiral-mcp/update-media`: `alt`, `title`, `caption`, `description`. Hace snapshot `media_meta` del estado anterior.
- [x] `spiral-mcp/delete-media`: exige `confirm`. Si existe `MEDIA_TRASH`, va a la papelera; si no, se borra definitivamente, y la descripción debe decirlo.
- [x] `spiral-mcp/set-featured-image`: `post_id` + `media_id` (o `0` para quitarla).
- [x] `spiral-mcp/find-media-usage` (opcional): busca el `path_relative` o la URL de un adjunto en el contenido de posts, el theme config y los widgets, para saber qué se rompe antes de borrar.

**Terminado cuando:** el agente sube una imagen desde una URL, la usa en un elemento `image` de un layout de página, actualiza su alt text y la página la muestra.

---

## Fase 3 — YOOtheme completo

### 3.1 Spikes (hacer primero y anotar los resultados aquí)
- [x] **Áreas de la configuración**: qué contiene cada clave de `config` (`header`, `navbar`, `dialog`, `mobile`, `top`, `bottom`, `logo`, `site`, `post`, `blog`, `consent`, `scripts`…), cuáles son ajustes planos y cuáles contienen layouts de builder (`content`).
- [x] **Plantillas del builder** (Templates: plantillas para entradas individuales, archivos, búsqueda, 404, tipos de contenido): dónde se guardan (¿`config.templates`?), su estructura (`name`, `type`, `query`/condiciones, `layout`) y cómo decide YOOtheme cuál aplicar.
- [x] **Biblioteca de layouts** (secciones guardadas por el usuario): dónde y cómo se guarda.
- [x] **Definiciones de elementos**: dónde guarda YOOtheme la definición de cada elemento (campos, tipos, opciones, valores por defecto) dentro del tema, y cómo leerlas desde PHP con las APIs del propio YOOtheme (preferible a leer archivos a mano).
- [x] **Caché de YOOtheme**: qué hay que invalidar tras cambiar un layout o el theme config (caché de imágenes en `uploads/yootheme/`, transients…).

**Resultados de los spikes (YOOtheme 5.0.51, comprobados en local):**
- **Configuración**: theme mod `config` (JSON en texto); YOOtheme la lee en `wp_loaded` (`~theme` en su contenedor) y el Personalizador la reemplaza entera pasando antes por el filtro `config.save`. Sin `version`, su `Updater` aplica todas las migraciones. Solo hay dos sitios con layouts de builder: `footer.content` (raíz `layout`) y `menu.items.<ID del nav_menu_item>.content` (mega menús, raíz `fragment`). `header`, `navbar`, `dialog`, `mobile`, `top`, `bottom`, `logo`, `site`, `post`, `blog`, `consent`… son ajustes planos. Los valores por defecto (y por tanto las claves válidas) están en `theme.defaults` del contenedor. Algunas claves llevan punto en el nombre (`search_dropdown.stretch`): son literales.
- **Contenido de builder en header/navbar/top/bottom…**: son **widgets de builder** (`widget_builderwidget[n]['content']`, JSON en texto, raíz `fragment`) asignados a esas posiciones en `sidebars_widgets`.
- **Plantillas**: opción `yootheme` (JSON, servicio `YOOtheme\Storage`), clave `templates.<id>` → `{name, type, status, query, params, layout}`. `type`: `single-<post_type>`, `archive-<post_type>`, `taxonomy-<tax>`, `author-archive`, `date-archive`, `search`, `_search`, `error-404` (catálogo y campos de `query`/`params` en `BuilderConfig['templates']`). Se aplica la **primera** plantilla activa (orden = prioridad) cuyo tipo coincide y cuyas condiciones no vacías encajan; una página con layout propio ignora las plantillas. IDs: 8 caracteres.
- **Biblioteca**: misma opción, `library.<id>` → nodo (`type: "layout"` para layouts, o el tipo del elemento) con `name`, `modified` y `version`.
- **Storage**: escribe la opción **entera** en `shutdown` si se modificó. Hay que escribir siempre con `\YOOtheme\app(\YOOtheme\Storage::class)->set()/del()`; un `update_option('yootheme')` en la misma petición se pierde.
- **Definiciones de elementos**: `element.php` (no JSON) en `packages/builder/elements/*` y otros paquetes; se leen con `\YOOtheme\app(\YOOtheme\Builder::class)->getTypes()` (59 tipos en local). Serializados con JSON traen los campos compartidos (`${builder.xxx}`) resueltos. Estructura: layout > section > row > column > elementos; `fragment` (sublayout) contiene filas; cada elemento múltiple tiene su `<tipo>_item` (campo `content-items`). Los anchos de columna (`width_default`, `width_small`…) son `child-prop` de la fila. PHP no valida el anidado (lo hace el JS del Personalizador): lo valida este plugin.
- **Caché**: YOOtheme no cachea el HTML (páginas, plantillas, footer y widgets se renderizan en cada petición) ni hay que invalidar nada suyo; la caché de imágenes (`uploads/yootheme/cache`) va por contenido. Solo hay que purgar plugins de caché de página. El CSS del tema se compila en el navegador (Fase 4).

### 3.2 Configuración del tema
- [x] `spiral-mcp/get-theme-config`: `section` opcional. Sin sección devuelve la lista de secciones con un resumen. Con sección devuelve su contenido. `yootheme_apikey` y cualquier secreto se devuelven enmascarados.
- [x] `spiral-mcp/update-theme-config`: `section` + `values` (merge profundo por defecto, o `replace: true`). Hace snapshot antes.
  - Lista blanca de secciones editables (filtro `spiral_mcp_theme_config_sections`). Por defecto: `site, header, navbar, dialog, mobile, top, bottom, footer, logo, favicon, touchicon, post, blog, consent, lazyload, webp`.
  - `scripts` (JS personalizado) requiere `spiral_mcp_code`.
  - No editables: `yootheme_apikey`, `version`, `minimum_stability`, `site_url`.
- [x] Generalizar `get-theme-layout` y `update-theme-layout` a todas las áreas con builder que salgan del spike (no solo `footer`).

### 3.3 Edición eficiente de layouts
Hoy `update-yootheme-layout` exige enviar el árbol completo: es caro y arriesgado en páginas grandes.
- [x] `spiral-mcp/get-layout-outline`: árbol resumido de una página o de un área del tema. Una línea por nodo con su **ruta** (`/children/0/children/1/children/2`), `type`, `name` y un extracto de su texto o título. Para que el agente localice qué tocar sin descargar todo el JSON.
- [x] `spiral-mcp/get-layout-node`: devuelve un nodo por ruta.
- [x] `spiral-mcp/patch-layout`: aplica operaciones sobre un layout (de página por `id`, o de un área del tema por `area`). Operaciones al estilo JSON Patch (RFC 6902): `replace`, `add` (insertar en una posición de `children`), `remove`, `move`, `copy`, con rutas sobre el árbol. Es atómico: si falla una operación, no se guarda nada. Crea una revisión (página) o un snapshot (área del tema).
- [x] `spiral-mcp/duplicate-content`: clona una página o entrada con su layout, como borrador.

### 3.4 Catálogo de elementos y validación
- [x] `spiral-mcp/list-elements`: los tipos de elemento disponibles (`name`, `title`, `group`, si admite hijos y qué hijos).
- [x] `spiral-mcp/get-element-schema`: los campos de un elemento (nombre, tipo, opciones permitidas, valor por defecto).
- [x] Validación en `update-yootheme-layout`, `update-theme-layout` y `patch-layout`:
  - **Error** si hay un `type` desconocido o una estructura imposible (p. ej. un `row` fuera de una `section`).
  - **Aviso** (no bloquea) si hay props desconocidos o valores fuera de sus opciones.
  - Los avisos van en la respuesta (`warnings`) para que el agente se corrija.

### 3.5 Plantillas y biblioteca (según el spike)
- [x] `spiral-mcp/list-templates`, `get-template`, `create-template`, `update-template`, `delete-template` (este último con `confirm`). Snapshot antes de cada escritura.
- [x] `spiral-mcp/list-library`, `get-library-item`, `save-to-library` (desde una ruta de un layout), `insert-from-library` (en una ruta de un layout).

### 3.6 Caché
- [x] `spiral-mcp/clear-cache`: caché de YOOtheme (según el spike), `wp_cache_flush()`, y hooks a los plugins de caché más comunes si están activos (LiteSpeed, WP Rocket, W3TC, WP Super Cache). Devuelve qué limpió.
- [x] Llamarla automáticamente después de cada escritura de diseño (desactivable con el filtro `spiral_mcp_auto_clear_cache`).

**Terminado cuando:** el agente cambia el header (logo, navbar y su versión móvil), crea una plantilla de YOOtheme para las entradas individuales y edita una sección concreta de una página grande con `patch-layout`, todo validado por el catálogo de elementos.

---

## Fase 1 — Contenido completo

- [x] `spiral-mcp/list-post-types`: tipos públicos y editables (no solo `page` y `post`). Las abilities de contenido aceptan cualquiera de ellos; el filtro `spiral_mcp_post_types` mantiene la posibilidad de restringirlos.
- [x] Ampliar `create-content` y `update-content`: `parent`, `menu_order`, `template` (plantilla de página), `author`, `date` (programar), `comment_status`, `terms` (`{taxonomía: [ids o slugs]}`), `featured_media`, `meta` (solo claves en una lista blanca, filtro `spiral_mcp_allowed_meta`).
- [x] `spiral-mcp/trash-content`, `restore-content` (sacar de la papelera), `delete-content` (definitivo, con `confirm`).
- [x] `spiral-mcp/list-revisions` y `restore-revision` (con `confirm`; antes guarda el estado actual como revisión).
- [x] Taxonomías: `list-taxonomies`, `list-terms`, `create-term`, `update-term`, `delete-term` (con `confirm`).
- [x] SEO: `spiral-mcp/get-seo` y `update-seo` con título, meta description, título y descripción Open Graph, canonical y noindex. Si Yoast está activo, usar sus metas (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`…). Dejar la estructura preparada para Rank Math, sin implementarlo todavía.
- [x] Ajustes del sitio: `spiral-mcp/get-site-settings` y `update-site-settings` (`blogname`, `blogdescription`, `show_on_front`, `page_on_front`, `page_for_posts`, `site_icon`). Requiere `spiral_mcp_settings`. Snapshot `option` antes.

**Notas de implementación:** las páginas no tienen categorías en WordPress (las categorías se probaron en una entrada). YOOtheme no trae plantillas de página propias: `template` se valida contra las del tema activo. Sin plugin de SEO, Spiral-MCP guarda el SEO en metas `_spiral_mcp_seo_*` y lo imprime él mismo (title, description, Open Graph, canonical, robots). Los ajustes del sitio exigen `spiral_mcp_settings` + `edit_theme_options` (el rol del agente no tiene `manage_options`).

**Terminado cuando:** el agente crea una página hija con plantilla, imagen destacada, categorías y SEO completos, la programa para publicarse, y luego la restaura a una revisión anterior.

---

## Fase 4 — Estilos

### 4.1 CSS inmediato
- [x] `spiral-mcp/get-custom-css` y `update-custom-css`: el "CSS adicional" de WordPress (`wp_get_custom_css()` / `wp_update_custom_css_post()`). Se aplica al instante y tiene revisiones propias. Snapshot `custom_css` antes.

### 4.2 Estilo de YOOtheme
- [x] Spike: catálogo de variables LESS del estilo activo (nombre, valor actual, valor por defecto, grupo) para que el agente sepa qué puede cambiar.
- [x] `spiral-mcp/get-theme-style`: `style` (estilo base), `less` (variables modificadas) y `custom_less`.
- [x] `spiral-mcp/update-theme-style`: cambia `style`, variables en `less` (merge) o `custom_less`. Snapshot antes. Marca la opción `spiral_mcp_style_dirty` con fecha. La respuesta incluye `needs_recompile: true` y las instrucciones para recompilar.
- [x] Spike clave: **¿se puede compilar el LESS de YOOtheme en el servidor?** Investigar cómo compila y guarda el CSS el Personalizador (qué petición hace al guardar, dónde se escribe el CSS) y si hay alguna vía en el servidor. Si no la hay, el flujo oficial es: el agente abre Personalizar → Guardar con un navegador y luego llama a `spiral-mcp/mark-style-compiled`.
- [x] `spiral-mcp/get-style-status`: si hay cambios de estilo pendientes de compilar, desde cuándo, y la fecha del CSS compilado actual.

**Resultados de los spikes (YOOtheme 5.0.51, comprobados en local):**
- **Catálogo de variables**: 48 estilos en `less/theme.<id>.less` (variaciones `id:variación` en `uikit-themes/master-<id>/styles/<variación>.less`). Las fuentes LESS se obtienen en PHP con `Event::emit('styler.imports|filter', [], $id)` (en orden de import) y la agrupación (componente, grupo, tipo de campo) con `StylerConfig` (`components`, `ignore`, `types`), la misma que usa el Personalizador. Los valores por defecto son expresiones LESS sin evaluar (evaluarlas exige less.js). `config.less` guarda `{"@variable": "valor"}` y las fuentes de Google van entre comillas simples (`"'Roboto'"`); YOOtheme las descarga sola al compilar.
- **Compilación**: no hay compilador LESS en el servidor; el Personalizador compila en el navegador (less.js en un Web Worker) y guarda el CSS con `POST theme/style` (escribe `css/theme.<blog>.css` y, en el sitio principal, `css/theme.css`, con la cabecera `/* YOOtheme Pro vX compiled on FECHA */`). **Si `StylerConfig['update']` es true, al abrir el Personalizador recompila y guarda solo, sin pulsar nada.** El plugin pone ese flag a true mientras haya cambios pendientes (`spiral_mcp_style_dirty`), así que el flujo es: `update-theme-style` → abrir `recompile_url` en un navegador con sesión → `get-style-status` detecta el CSS nuevo y quita el pendiente. Comprobado con Edge headless: cambio de marca recompilado en ~10 s, y deshacer + recompilar devuelve un CSS idéntico al original.

**Terminado cuando:** el agente cambia la paleta de marca (primario, secundario, fondo) y la tipografía, el CSS queda recompilado y la portada se ve con la nueva marca.

---

## Fase 5 — Verificación y flujo de trabajo

- [x] `spiral-mcp/get-preview-url`: URL temporal (1 h por defecto, máximo 24 h) que permite ver un borrador o una revisión pendiente sin sesión. Token firmado y de un solo post; se puede revocar.
- [x] `spiral-mcp/inspect-page`: renderiza una página (publicada o por token de vista previa) y devuelve los encabezados (h1–h6), enlaces internos y externos (marcando los rotos: 404 interno), imágenes sin alt y el título y descripción efectivos de SEO. Sin devolver el HTML completo.
- [x] Integración con la Fase 3.6: `clear-cache` tras publicar.
- [x] `docs/agent-guide.md`: guía para el agente. Flujo recomendado (leer outline → patch → preview → inspect → publicar), convenciones (rutas relativas, borrador antes de publicar, no tocar fuentes dinámicas sin entenderlas), recetas comunes (añadir una sección, cambiar un botón, subir y usar una imagen) y errores frecuentes. Esta guía se convertirá en un skill de Claude.

**Notas de implementación:** los tokens de vista previa son `post.caducidad.id.firma` (HMAC con una sal de WordPress); el id se guarda en el meta `_spiral_mcp_previews` y borrarlo los revoca. La vista previa sale con noindex y sin caché. `inspect-page` pide la página al propio servidor (si no se alcanza por su dominio: filtro `spiral_mcp_inspect_base_url`); con servidores de un solo hilo (`php -S`) esa petición se bloquea, por eso `smoke.sh` solo la prueba con `SMOKE_INSPECT=1`. La purga de cachés tras publicar ya la hace el wrapper después de cada escritura (Fase 3.6). Comprobado con un borrador con errores (h3 sin h2, enlace roto, imagen sin alt, sin description): `inspect-page` los detectó, se corrigieron con `patch-layout` y `update-seo`, se publicó, y la vista previa sin sesión se capturó con Edge headless.

**Terminado cuando:** el agente crea un borrador, lo revisa con `get-preview-url` + `inspect-page` (y una captura de pantalla con su navegador), corrige lo que encuentre y lo publica.

---

## Fase 6 — Cachés (independiente de la herramienta)

El plugin se instala en sitios con distintas herramientas de caché (o ninguna, o varias a la vez: un plugin más la caché del hosting). El agente ve siempre las mismas abilities; por dentro, un **adaptador** por herramienta traduce cada operación a su API. Cada adaptador declara si su herramienta está activa y qué sabe hacer (`purge_all`, `purge_post`, `purge_url`, `preload`, `read_settings`, `write_settings`); el agente lo ve en `get-cache-status` y solo se le ofrece lo que el sitio permite. Filtro `spiral_mcp_cache_providers` para añadir adaptadores sin tocar el plugin.

**Vocabulario común de ajustes** (cada adaptador mapea los que su herramienta tenga): `enabled`, `lifetime` (segundos), `cache_logged_in`, `mobile`, `compression`, `exclude_urls`, `clear_on_edit`. Lo propio de cada herramienta va en `provider_settings`, con lista blanca por adaptador.

**Decisiones:** cambiar ajustes exige la capacidad nueva `spiral_mcp_cache`, que el rol del agente **no** tiene por defecto (operar la caché —ver estado, purgar, precargar— sí). Fuera de alcance: modos que reescriben `.htaccess` o la configuración del servidor (modo Expert de WP Super Cache, reglas de Nginx…), porque un fallo deja el sitio caído.

### 6.1 Base + WP Super Cache
- [x] `includes/cache.php` reorganizado: registro de adaptadores, detección, interfaz común y adaptadores de compatibilidad para lo que ya purgaba `clear-cache` (LiteSpeed, WP Rocket, W3TC con purga total, por post y por URL según su API pública, marcados "sin probar"; caché de objetos).
- [x] Adaptador completo de WP Super Cache (estado, purga total / por post / por URL, precarga, lectura de ajustes).
- [x] `spiral-mcp/get-cache-status`: herramientas detectadas, capacidades y estado de cada una (activa, modo, caducidad, precarga, tamaño y número de archivos si se puede).
- [x] `spiral-mcp/purge-cache`: todo, un post (`post_id`) o una URL relativa, en todas las herramientas activas. `clear-cache` queda como alias.
- [x] `spiral-mcp/preload-cache`: iniciar, parar o consultar la precarga (si la herramienta la admite).
- [x] `spiral-mcp/get-cache-settings`: ajustes comunes + `provider_settings` por herramienta.
- [x] Purga automática dirigida tras cada escritura: el post cambiado, la portada y sus listados (`purge_post`) cuando la herramienta lo admite; si no, purga total como hasta ahora.

**Resultado 6.1 (WP Super Cache 3.1.4, modo Simple):** purgar `/blog/` por URL borra solo esa página; purgar un post borra el post, la portada y la página de entradas (`wp_cache_post_change`); editar el SEO de una página purga esa página y la portada; un cambio de diseño global purga todo. La precarga arranca con WP-Cron, se consulta y se detiene. Las abilities de caché no disparan la purga automática (si no, una purga por URL acababa purgando todo).

### 6.2 Ajustes
- [x] Capacidad `spiral_mcp_cache` (administradores sí; rol del agente no, por defecto).
- [x] `spiral-mcp/update-cache-settings`: ajustes comunes y `provider_settings`, por herramienta. Snapshot `cache_settings` antes; `restore-snapshot` lo deshace.
- [x] Adaptador de escritura para WP Super Cache (`wp_cache_setting()`, activar/desactivar con sus funciones; sin modo Expert).

**Resultado 6.2:** el adaptador reutiliza la clase de la API REST de WP Super Cache (`WP_Super_Cache_Rest_Update_Settings`, subclase `Spiral_MCP_WPSC_Updater`) para los ajustes con efectos secundarios (activar, modo, usuarios con sesión, compresión, URLs excluidas, caducidad, precarga), sin su comprobación `manage_options` ni la parte de depuración, y `wp_cache_setting()` para los simples. Bugs de WP Super Cache sorteados: su API pierde los ajustes cuya clave REST no coincide con el nombre de la variable (móvil, purgar al editar, regenerar, `no_cache_for_get`…); guardar la caducidad activa el aviso por correo de la recogida de basura y guardar la precarga activa `preload_on` y `preload_taxonomies` si no se les pasa su valor actual. Comprobado: el agente sin `spiral_mcp_cache` recibe 403; con el permiso, un cambio de 8 ajustes escribe solo esas líneas en `wp-cache-config.php` (que sigue siendo PHP válido) y restaurar el snapshot deja los mismos valores.

### 6.3 Más herramientas: purga y estado
- [x] WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Fastest Cache, SiteGround Optimizer, Nginx Helper y cachés de hosting (Kinsta, WP Engine…).

**Resultado 6.3** (adaptadores en `includes/cache-providers.php`; cada herramienta instalada en local de una en una, con WP Super Cache desactivado, y el sitio restaurado al final desde copia de archivos y BD):

| Herramienta | Versión | Purga | Estado | Probado |
|---|---|---|---|---|
| WP Super Cache | 3.1.4 | todo / post / URL | sí (+ ajustes y precarga) | sí: archivos borrados |
| WP Fastest Cache | 1.5.3 | todo / post (URL → su post) | activada, móvil, archivos | sí: archivos borrados |
| W3 Total Cache | 2.10.7 | todo / post / URL | activada, motor, caducidad, archivos | sí: páginas regeneradas (marca "Served from") |
| LiteSpeed Cache | 7.9.1 | todo / post / URL | activada; avisa si el servidor no es LiteSpeed | sí: cabeceras `X-LiteSpeed-Purge` correctas |
| SiteGround Optimizer | 7.8.4 | todo / post / URL | activada | API comprobada; el efecto solo existe en SiteGround |
| Nginx Helper | 2.4.1 | todo / post / URL | activada, método | API comprobada; el efecto solo existe con Nginx |
| WP Rocket | — | todo / post / URL | versión | sin probar (de pago) |
| WP Engine, Pantheon, Kinsta | — | según su API | — | sin probar (solo en su plataforma) |
| Caché de objetos | — | todo (solo si se pide) | — | sí |

Mejora salida de las pruebas: si una herramienta no purga por URL pero la URL es de un contenido, se purga ese contenido en lugar de todo. Notas: la activación de W3 Total Cache tarda más de 2 minutos (hace peticiones a sí mismo); su motor "Disk: Basic" guarda en `cache/page` con nombres en hash. Desactivar WP Super Cache desde código necesita `wp-admin/includes/file.php` (si no, se rompe a medias tras quitar `advanced-cache.php`).

### 6.4 Más herramientas: ajustes
- [x] WP Rocket (`wp_rocket_settings`), LiteSpeed Cache y WP Fastest Cache. W3 Total Cache solo lectura al principio.

**Resultado 6.4:**

| Herramienta | Leer | Escribir (comunes) | Propios | Cómo escribe | Probado |
|---|---|---|---|---|---|
| WP Super Cache | sí | los 7 | modo (no Expert), usuarios conocidos, regenerar, precarga… | su clase REST + `wp_cache_setting()` | sí |
| LiteSpeed Cache | sí | enabled, lifetime, cache_logged_in, mobile, exclude_urls | caducidad de portada y feeds, REST, comentaristas, login | acción pública `litespeed_save_conf` (como su panel: actualiza su bloque de `.htaccess`) | sí: opciones, `.htaccess` y restaurar |
| WP Fastest Cache | sí | enabled, cache_logged_in, exclude_urls (`/ruta/`, `/ruta*`, `*texto*`), clear_on_edit | no cachear en móvil, purgar al publicar | opciones `WpFastestCache` y `WpFastestCacheExclude` (sin tocar `.htaccess`; al desactivar, vacía la caché) | sí: la URL excluida deja de cachearse; restaurar |
| WP Rocket | sí | lifetime, cache_logged_in, mobile, exclude_urls | caché móvil | `update_option( 'wp_rocket_settings' )` (dispara sus ganchos) | sin probar (de pago) |
| W3 Total Cache | sí | — (solo lectura) | motor, portada, feeds | — | lectura, sí |

Cada adaptador declara `writable` (claves que acepta) y `restore-snapshot` solo reescribe esas: así se restauran ajustes de herramientas que no permiten escribirlos todos. Arreglado de paso: si una restauración falla, ya no queda la copia "antes de restaurar" (bloqueaba el siguiente intento con `spiral_mcp_snapshot_later`).

### 6.5 Pruebas y documentación
- [x] `tests/smoke.sh`: pruebas según la herramienta detectada (purga limitada a cada una, sus ajustes en `get-cache-settings`; con `SMOKE_CACHE_WRITE=1`, escritura sin cambios y restauración en las que admiten escribir).
- [x] Probar cada adaptador instalando la herramienta en local de una en una (W3TC, LiteSpeed, WP Fastest Cache, Nginx Helper son gratuitos). Los que no se puedan probar (WP Rocket, de pago; cachés de hosting) se marcan "sin probar" en la tabla de compatibilidad.
- [x] Skill: receta "un cambio no se ve" y "acelerar el sitio". Tabla de herramientas compatibles en el README.

**Fase 6 terminada.** Nota: restaurar los ajustes de WP Super Cache deja los mismos valores, pero sus funciones reescriben algunas líneas de `wp-cache-config.php` con otro formato (`'600'` en vez de `600`, arrays con índices); es equivalente.

**Terminado cuando:** en un sitio con cualquiera de las herramientas compatibles, el agente sabe qué caché hay, la purga (toda o una página), lanza la precarga si existe y, con permiso, cambia los ajustes básicos y puede deshacerlo.

---

## Fase 7 — Ajustes del plugin

Página *Ajustes > Spiral-MCP* (solo administradores, `manage_options`) para decidir qué puede hacer el agente sin tocar código. El agente no tiene ninguna ability para cambiarla.

**Decisiones:** desactivar una ability = **no registrarla** (el MCP Adapter no la publica y el agente ni la ve). Se puede activar o desactivar una a una y por grupos, con atajos ("solo lectura", "sin destructivas"). Las abilities nuevas de versiones futuras llegan **activadas** (la opción guarda las desactivadas). Siempre activas, para que el agente pueda deshacer: `list-activity`, `list-snapshots` y `restore-snapshot`. Cada cambio en la página queda en el registro de auditoría.

### 7.1 Abilities y permisos del agente
- [x] El wrapper lleva un registro de todas las abilities (también las desactivadas) con su categoría, tipo (lectura / escritura / destructiva) y descripción, y no registra las desactivadas (opción `spiral_mcp_disabled_abilities`).
- [x] Pestaña **Abilities**: interruptor por ability y por categoría; atajos "activar todas", "solo lectura" y "desactivar destructivas".
- [x] Pestaña **Permisos del agente**: qué capacidades `spiral_mcp_*` tiene el rol del agente (opción `spiral_mcp_agent_caps`, que respetan también las actualizaciones del plugin). Las nativas peligrosas (`install_plugins`, `manage_options`…) nunca se pueden dar.
- [x] Auditoría de los cambios, enlace "Ajustes" en la lista de plugins, limpieza de las opciones al desinstalar.

**Resultado 7.1** (`includes/admin.php`, solo en `is_admin()`; guarda por `admin-post.php` con nonce y `manage_options`): comprobado con una sesión de administrador contra el sitio local. "Solo lectura" deja al agente 39 de 84 herramientas (las de lectura más `restore-snapshot`) y `update-content` responde `rest_ability_not_found`; desactivar solo `delete-media` la quita de la lista; las protegidas no se pueden desactivar aunque se omitan en el formulario. Quitar `spiral_mcp_code` y dar `spiral_mcp_cache` cambia el rol al momento (el agente ya puede cambiar ajustes de caché y no puede tocar `scripts`) y se mantiene tras la sincronización de una actualización. Sin sesión: HTTP 400; con nonce falso: 403. Cada guardado queda en el registro como `admin/abilities` o `admin/agent_caps` (`object_type` `settings`).

### 7.2 Comportamiento
- [x] Ajustes que hoy son filtros: purga automática tras cada escritura, días de retención del registro, librería online de YOOtheme sí/no, borrar las tablas al desinstalar.

**Resultado 7.2:** pestaña *Comportamiento* (opción `spiral_mcp_settings`, leída con `spiral_mcp_setting()`): purga automática, retención del registro y de los snapshots (0 = nunca), descargas de la librería online con la clave de API y borrar tablas al desinstalar. Los filtros de siempre reciben el valor guardado y tienen la última palabra (la página avisa si hay alguno). Comprobado: sin purga automática, editar el SEO de una página deja su caché de WP Super Cache intacta; con 30 días de retención la limpieza diaria borra un registro de hace 40 días y con 0 conserva un snapshot de hace 400; sin descargas, `import-from-online-library` responde `spiral_mcp_online_disabled` y `list-online-library` indica `download_enabled: false`.

### 7.3 Actividad
- [x] Pestaña con los últimos cambios del agente y su snapshot.

**Resultado 7.3:** pestaña *Actividad*: registro paginado (50 por página) con filtros por usuario, resultado y acción; cada fila muestra fecha local, usuario, acción con su entrada (desplegable), objeto (enlazado al editor si es un contenido), resultado y duración, y un botón **Restaurar** para su snapshot. Restaurar desde aquí respeta la protección de cambios posteriores: si los hay, el aviso los lista y ofrece "Restaurar igualmente"; después purga la caché (si la purga automática está activa) y queda en el registro como `admin/restore-snapshot`. Comprobado con dos cambios seguidos de la descripción del sitio: el primer intento se rechazó sin tocar nada y "Restaurar igualmente" la devolvió al valor original.

**Fase 7 terminada.**

**Terminado cuando:** un administrador desactiva una ability o un grupo desde la página y el agente deja de verla en su lista de herramientas; cambia los permisos del rol y se mantienen tras actualizar el plugin.

---

## Fase 8 — Plugins

Que el agente pueda buscar, instalar, activar, desactivar, actualizar y borrar plugins del directorio oficial de wordpress.org. Es el permiso de más riesgo del plugin: instalar código es tener el control del sitio. Por eso va detrás de **dos cerrojos** y con comprobaciones después de cada paso.

**Decisiones (confirmadas):**
- **Dos cerrojos:** la capacidad nueva `spiral_mcp_plugins` (grupo `plugins`, el rol del agente **no** la tiene por defecto; se da en *Permisos del agente*) **y** un interruptor del sitio en *Comportamiento* ("Permitir que el agente gestione plugins", apagado por defecto). Si falta cualquiera de los dos, las abilities de escritura responden con un error que explica cuál falta. Si `DISALLOW_FILE_MODS` está activo, nada de esto funciona y se dice.
- **Sin dar permisos nativos al agente:** las abilities usan las APIs de WordPress (`Plugin_Upgrader`, `activate_plugin()`…) con su propia comprobación. El rol nunca recibe `install_plugins`, `activate_plugins`, `update_plugins`, `delete_plugins` ni los de temas: se añaden a la lista de nativas que nunca se conceden (hoy faltan `activate_plugins`, `update_plugins`, `delete_plugins`, `install_themes`, `switch_themes`, `edit_themes` y `update_core`).
- **Solo wordpress.org, por slug**, y cualquier plugin del directorio (sin lista de permitidos). Nunca un ZIP de una URL ni subido.
- **Plugins intocables:** Spiral-MCP-Control y MCP Adapter no se pueden desactivar, actualizar ni borrar desde aquí (el agente se cortaría el acceso).
- **Instalar no activa:** se instala desactivado; activar es otro paso explícito.
- **Comprobación tras activar o actualizar:** WordPress ya activa en un "sandbox" (si el plugin da un error fatal al cargar, no se activa). Además, el plugin pide la portada y wp-admin al propio servidor; si responden con error 500, desactiva el plugin solo y lo dice.
- **Deshacer:** activar y desactivar son reversibles (snapshot `plugins_state` con la lista de plugins activos). Instalar se deshace borrando. Actualizar y borrar no se pueden deshacer del todo: son destructivas y piden `"confirm": true`.
- **Temas:** fuera de esta fase (solo lectura). Los sitios son YOOtheme; cambiar de tema rompe el diseño.
- **Multisitio:** fuera (sin activación en red).

### 8.1 Lectura y cerrojos
- [x] Capacidad `spiral_mcp_plugins`, interruptor en *Comportamiento* y respeto de `DISALLOW_FILE_MODS`.
- [x] Ampliar la lista de nativas que nunca se conceden al agente.
- [x] `spiral-mcp/list-plugins`: instalados (activo, versión, actualización disponible, autoactualización, si es intocable), más mu-plugins y drop-ins (`advanced-cache.php`, `object-cache.php`) en solo lectura.
- [x] `spiral-mcp/search-plugins`: búsqueda en wordpress.org (nombre, slug, valoración, instalaciones activas, última actualización, compatible hasta, requiere PHP).

**Resultado 8.1** (`includes/plugins.php`): `spiral_mcp_plugins_check_locks()` devuelve qué cerrojo falta (archivos, ajuste o capacidad); `list-plugins` lo resume en `management` (`allowed` y `reason`). Comprobado con la sesión de administrador: con todo cerrado, "la gestión está desactivada"; solo con el ajuste, "falta la capacidad"; con los dos, `allowed: true`. Lista de nativas que nunca se conceden (`spiral_mcp_never_granted_caps()`): ahora incluye `activate_plugins`, `update_plugins`, `delete_plugins`, los de temas, `update_core`, `edit_files`, `edit_users`, `create_users` y `unfiltered_upload`, y la sincronización las retira del rol si alguien las añadió. `search-plugins` probado contra wordpress.org (búsqueda, ficha por slug, slug inexistente). Arreglado de paso: las listas de nombres de la página de ajustes salían con las etiquetas `<code>` escapadas.

### 8.2 Instalar, activar, desactivar
- [x] `spiral-mcp/install-plugin` (`slug`; instala sin activar; comprueba compatibilidad de WordPress y PHP antes).
- [x] `spiral-mcp/activate-plugin` y `deactivate-plugin`, con snapshot `plugins_state` y comprobación de salud tras activar (desactivación automática si el sitio da 500).
- [x] Purga de caché tras activar o desactivar.

**Resultado 8.2:** `install-plugin` usa `plugins_api()` + `Plugin_Upgrader` con `Automatic_Upgrader_Skin`, solo si la descarga viene de `downloads.wordpress.org` y el sistema de archivos es `direct`. `activate-plugin` envuelve `activate_plugin()` en `try/catch` (WordPress incluye el plugin y ejecuta su activación **antes** de guardarlo como activo, así que un error fatal no lo deja activo; hay que cerrar el búfer de salida que abre) y luego pide `/` y `/wp-admin/` al propio servidor: con un 500 o el aviso de error crítico, lo desactiva. Probado en local: Classic Editor instalado (desactivado), activado (portada 200, wp-admin 302), desactivado y vuelto a activar restaurando el snapshot; protegidos rechazados; tres plugins de prueba en `tests/fixtures/plugins/`: el que falla al cargar no se activa (el error dice la función y la línea), el que rompe la web pública se activó, la portada dio 500 y se desactivó solo (la portada volvió a 200), el que pide PHP 99 se rechaza. Con los cerrojos cerrados, las abilities responden 403 con el cerrojo que falta. Sitio restaurado desde copia de la BD al terminar.

### 8.3 Actualizar y borrar
- [x] `spiral-mcp/update-plugin` (con `confirm`; misma comprobación de salud; WordPress 6.3+ revierte solo si la actualización da un error fatal).
- [x] `spiral-mcp/delete-plugin` (con `confirm`; solo desactivados; nunca los intocables).

**Resultado 8.3:** `update-plugin` usa `Plugin_Upgrader::upgrade()`, que desactiva el plugin sin avisar y deja la versión anterior en `wp-content/upgrade-temp-backup/plugins/<slug>` hasta el final de la petición. Si estaba activo, se vuelve a marcar como activo **sin cargarlo** (en esa petición sigue cargado el código viejo, como en las actualizaciones en segundo plano) y la nueva versión se prueba con peticiones al propio sitio; con un 500, `restore_temp_backup()` pone la versión anterior. Solo paquetes de `downloads.wordpress.org` (filtro `spiral_mcp_plugins_allowed_hosts`) y con compatibilidad comprobada. `delete-plugin` usa `delete_plugins()` (ejecuta la desinstalación del plugin, en `try/catch`) y dice si se borraron sus datos. Probado en local: Classic Editor 1.6.7 → 1.7.0 activo (portada 200); `spiral-test-update` 1.0.0 → 2.0.0, que rompe la portada: 500, vuelta a la 1.0.0 y portada 200; un fallo de descarga deja el plugin activo y en su versión; borrar uno activo, uno protegido o sin `confirm` se rechaza; `spiral-test-uninstall` se borra junto con su opción. Con los cerrojos cerrados, 403. Sitio restaurado desde copia de la BD al terminar.

### 8.4 Pruebas y documentación
- [x] Pruebas en local: instalar y activar un plugin pequeño de wordpress.org; un plugin de prueba que da error fatal (en `tests/fixtures/`) para comprobar la desactivación automática; los dos cerrojos; los intocables.
- [x] Skill: receta "instalar un plugin" (solo si el usuario lo pide: buscar → enseñarle opciones → instalar → que confirme → activar → revisar con `inspect-page`).
- [x] README y tabla de permisos.

**Resultado 8.4:** prueba final en local con los dos cerrojos abiertos: `search-plugins` "classic widgets" → `install-plugin` classic-widgets 0.3 (desactivado) → `activate-plugin` (portada 200, wp-admin 302) → `inspect-page /` (200) → `deactivate-plugin` → `delete-plugin`. Cada cerrojo por separado: con solo el ajuste abierto, las cinco abilities de escritura responden 403 `spiral_mcp_plugins_capability`; con solo la capacidad, 403 `spiral_mcp_plugins_disabled`. Las pruebas de fallos (error fatal, web rota, incompatible, actualización rota, desinstalación) están en los resultados de 8.2 y 8.3. README con la sección "Gestión de plugins" y `SMOKE_PLUGINS`. Sitio restaurado desde copia de la BD al terminar.

**Terminado cuando:** con los dos cerrojos abiertos, el agente instala y activa un plugin de wordpress.org que el usuario pidió, comprueba que el sitio sigue bien, y lo puede desactivar y borrar; con cualquiera de los dos cerrados, no puede hacer nada de eso.

---

## Fase 9 — Formularios (Contact Form 7)

Que el agente cree y cambie los formularios del sitio sin entrar en wp-admin: campos, correo, mensajes, y que los inserte en las páginas de YOOtheme. Spiral-MCP-Control se instala también en sitios sin Contact Form 7: el módulo solo se registra si CF7 está activo.

### 9.1 Abilities
- [x] `spiral-mcp/list-forms`: formularios con su shortcode, a quién llega el correo y dónde se usan (contenidos, también dentro de layouts de YOOtheme, y widgets).
- [x] `spiral-mcp/get-form`: plantilla, campos interpretados, correo, correo 2, mensajes, ajustes adicionales, etiquetas de correo y problemas de configuración que ve CF7.
- [x] `spiral-mcp/create-form` (plantilla por defecto de CF7 o copia de otro) y `spiral-mcp/update-form` (correo y mensajes por claves), con snapshot kind `form`.
- [x] `spiral-mcp/delete-form` (con `confirm`; avisa si se usa; restore-snapshot lo recrea con el mismo ID y hash).
- [x] Seguridad: cambiar a quién llega el correo (destinatario, cabeceras To/Cc/Bcc, adjuntos; activar el correo 2 hacia una dirección fija) exige `confirm`. No cuenta un destinatario hecho solo de `[_site_admin_email]` o de campos de email que escribe el visitante (sí uno oculto).

### 9.2 Pruebas y documentación
- [x] Prueba de humo (solo si CF7 está activo), receta del skill y errores, README.

**Resultado 9:** CF7 6.2.2 guarda cada formulario como post `wpcf7_contact_form` con metas `_form`, `_mail`, `_mail_2`, `_messages`, `_additional_settings`, `_locale` y `_hash`; se escribe con `wpcf7_save_contact_form()` (sanea como wp-admin) y se valida con `WPCF7_ConfigValidator`. Ojo: `wpcf7_sanitize_mail()` vacía las claves que no se le pasan (por eso se mezcla con lo actual) y, sin `unfiltered_html`, quita del cuerpo lo que parece HTML (`<[your-email]>`): la ability lo avisa. Permisos: los de CF7 (`wpcf7_edit_contact_forms` → `publish_pages`), grupo `content`. YOOtheme ejecuta los shortcodes de un elemento `text`, y con las clases de UIkit en las etiquetas (`class:uk-input`, `class:uk-button class:uk-button-primary`) el formulario toma el estilo del tema. Probado en local: formulario en español creado, editado, insertado en una página de YOOtheme (se ve con sus campos y lo detecta `used_in`), borrado (la página muestra el aviso) y restaurado con el mismo shortcode; destinatario externo, Bcc y campo oculto como destinatario piden `confirm`; Reply-To y la respuesta al visitante no. Sitio restaurado desde copia de la BD al terminar.

**Terminado cuando:** el agente crea un formulario de contacto en español, lo pone en una página de YOOtheme con el estilo del tema y cambia sus textos; no puede desviar los mensajes a otra dirección sin que el usuario lo confirme.

---

## Fase 10 — Ajustes generales de Yoast SEO

Que el agente cambie lo que en wp-admin está en *Yoast SEO > Ajustes* (plantillas de título, Schema, redes, mapas del sitio, rastreo…), no solo el SEO de cada contenido. Solo se registra si Yoast está activo.

- [x] `spiral-mcp/get-seo-settings`: secciones y, por sección, claves con su valor y ayudas (separadores, tipos de Schema, variables).
- [x] `spiral-mcp/update-seo-settings`: claves de la lista blanca, tipo comprobado, validación de Yoast (`not_applied`), imágenes por ID, snapshot kind `seo_settings` y purga de toda la caché.
- [x] Prueba de humo (si Yoast está activo), receta del skill, README.

**Decisiones:** grupo `settings` + `edit_theme_options`, como los ajustes del sitio (el agente no tiene `manage_options`). Lista blanca por secciones con patrones sobre las claves existentes (filtro `spiral_mcp_yoast_sections`): fuera quedan el estado interno de Yoast (indexación, versión, tokens de SEMrush y Wincher, OAuth), el seguimiento de uso y el usuario que representa al sitio cuando es una persona (usuarios: fuera de alcance). El snapshot guarda solo las claves editables, así que restaurarlo nunca pisa el estado interno.

**Resultado 10** (Yoast SEO 28.7): 11 secciones, 184 claves en local. Probado por REST con el rol del agente: separador `|` y plantilla de título de las páginas (la página del blog sale "Blog | MCP Control PRUEBA"), `noindex-tax-post_tag`, `remove_shortlinks` e imagen por defecto al compartir por ID (`og:image` con medidas); `twitter_card_type: "enorme"` queda en `not_applied` y se conserva `summary_large_image`; una clave interna (`semrush_tokens`) o un tipo equivocado se rechazan sin escribir; `stripcategorybase` hace que `/category/uncategorized/` redirija con 301 a `/uncategorized/`, y desactivar el mapa del sitio deja `/sitemap_index.xml` en 404. Restaurando los tres snapshots todo vuelve al estado original (reglas de reescritura incluidas). Descubierto: Yoast gratuito ignora las plantillas `social-*` por tipo de contenido (Premium); la descripción y `help` lo dicen.

**Terminado cuando:** el agente cambia la plantilla de título de las páginas, la imagen por defecto al compartir y el mapa del sitio, lo comprueba en la web y lo deshace.

---

## Fase 11 — WooCommerce

Que el agente lleve la tienda: catálogo, cupones, ajustes, envíos e impuestos y, con permiso aparte, pedidos y clientes. Solo se registra si WooCommerce está activo.

**Decisiones (confirmadas):**
- **Alcance:** catálogo (productos simples y variables, variaciones, atributos, categorías y etiquetas con las abilities de taxonomías), cupones, ajustes de la tienda, envíos, impuestos, pedidos y clientes.
- **Permisos:** `spiral_mcp_shop` (catálogo, cupones, ajustes; el agente la tiene) y `spiral_mcp_orders` (pedidos y clientes: datos personales y dinero; el agente **no** la tiene por defecto). Pasarelas de pago solo en lectura (sus ajustes llevan claves y cuentas).
- **Cómo:** la API REST de WooCommerce por dentro, con su permiso concedido solo durante cada llamada (ver CLAUDE.md). El rol nunca recibe `manage_woocommerce`; con `spiral_mcp_shop` recibe los permisos de términos de producto.

### 11.1 Catálogo y cupones
- [x] `list-products`, `get-product`, `create-product`, `update-product`, `delete-product`, `update-variations`.
- [x] `list-product-attributes`, `create-product-attribute`, `update-product-attribute`, `delete-product-attribute`.
- [x] `list-coupons`, `get-coupon`, `create-coupon`, `update-coupon`, `delete-coupon`.

### 11.2 Tienda
- [x] `get-shop-settings` y `update-shop-settings` (lista blanca de grupos y campos; destinatarios de correo con `confirm`).
- [x] Envíos: `list-shipping-zones`, `save-shipping-zone`, `delete-shipping-zone`, `save-shipping-method`, `delete-shipping-method`.
- [x] Impuestos: `list-tax-rates`, `update-tax-rates`. Pasarelas: `list-payment-gateways` (lectura).

### 11.3 Pedidos y clientes
- [x] `list-orders`, `get-order`, `update-order`, `add-order-note`, `create-refund`, `list-customers`, `get-customer`.

### 11.4 Pruebas y documentación
- [x] Prueba de humo (si WooCommerce está activo), recetas del skill, README.

**Resultado 11** (WooCommerce 11.2.1, instalado con `install-plugin` + `activate-plugin`; probado por REST con el rol del agente y el sitio restaurado desde copia de la BD al terminar): categoría de producto creada con `create-term` (gracias a `manage_product_terms`); producto simple con imagen de la biblioteca, publicado con oferta: YOOtheme muestra la ficha con "19,90 € / 14,90 €" tras cambiar moneda y formato y quitar el modo "próximamente". Atributo "Talla" con términos, producto variable con tres variaciones (una con imagen), cambio de precio de una, y restaurar: el snapshot de antes de crearlas las quita y el de después las vuelve a crear (con IDs nuevos). Borrar un producto lo manda a la papelera y restaurar lo deja publicado con sus categorías e imagen. Cupón creado, cambiado y borrado. Zona "España peninsular" con tarifa plana y envío gratis desde 50 €, cambio del mínimo y restauración (quita el método añadido después). IVA 21 % y 10 % creados y deshechos con el snapshot. Pedidos: 403 sin `spiral_mcp_orders`; con ella, listar, leer (líneas, notas), completar (envía el correo de WooCommerce), nota privada, reembolso por línea (importe calculado, unidad devuelta al stock, sin tocar la pasarela) y restaurar el estado. Rechazados: imágenes por URL, campos inventados, grupo de pagos, `woocommerce_custom_orders_table_enabled`, destinatario de correo, cancelar, nota al cliente y reembolso sin `confirm`, y `get-customer` de un administrador.

**Encontrado y corregido durante las pruebas:** los términos de un atributo recién creado fallaban sin avisar (taxonomía sin registrar en esa petición); `site-visibility` llega vacío por la API (grupo propio con sus dos opciones); el grupo `advanced` exponía el cambio de almacenamiento de pedidos y funciones experimentales (ocultos); un reembolso no devolvía el stock sin `api_restock` y, en la ruta nueva de WooCommerce, sin `api_refund` explícito intentaría devolver el dinero.

**Terminado cuando:** el agente crea un producto variable con sus fotos, abre la tienda con la moneda y los envíos de España, crea un cupón y, con permiso, gestiona un pedido hasta el reembolso; todo se puede deshacer salvo los reembolsos y los correos enviados.

---

## Fase 12 — Idiomas (Polylang)

Que el agente lleve un sitio multilingüe: idiomas, traducciones de páginas (con su diseño de YOOtheme), entradas y términos, textos sueltos, menús por idioma y selector de idioma. Solo se registra si Polylang está activo.

**Decisiones:**
- **Permisos:** gestionar idiomas, ajustes y textos va en el grupo `settings` + `edit_theme_options`, como los ajustes de Yoast (Polylang pide `manage_options`, que el agente no tiene; se usa su modelo por dentro). Las traducciones de contenidos y términos, en `content` con la capacidad nativa del objeto (`edit_post`, `edit_term`). Menús por idioma y selector, en `design`.
- **Ampliar en vez de duplicar:** filtro nuevo `spiral_mcp_ability_args` en `spiral_mcp_register_ability()`. El módulo de Polylang añade `language` (y `translation_of`) a `list-content`, `get-content`, `create-content`, `duplicate-content`, `list-terms` y `create-term`, las ubicaciones por idioma a `list-menus`, `create-menu` y `update-menu`, y `pll_lang` a `create-widget` y `update-widget`. Los módulos de contenido, términos y menús no saben nada de Polylang.
- **Traducir = copiar y enlazar:** `create-translation` copia el contenido como borrador en el otro idioma (layout de YOOtheme incluido) y el agente traduce los textos con `get-layout-texts` + `patch-layout`. Solo asigna términos y página madre que ya tengan traducción (Polylang crearía copias sin traducir) y avisa de los que faltan.
- **Confirmación** para lo que cambia las URLs del sitio (`default_lang`, `force_lang`, `hide_default`, `rewrite`, `domains`, el slug de un idioma) y para borrar un idioma.

- [x] `list-languages`, `create-language`, `update-language`, `delete-language` (snapshot completo: idioma, contenidos, términos, grupos y textos), `update-language-settings`, `assign-missing-languages`.
- [x] `get-translations`, `create-translation` (contenido y término), `update-translations` (idioma y enlaces), con snapshot kind `pll_translations`.
- [x] `list-string-translations` y `update-string-translations` (snapshot kind `pll_strings`).
- [x] `set-menu-locations` (snapshot kind `pll_menu_locations`) y `add-language-switcher`.
- [x] Prueba de humo (si Polylang está activo con dos idiomas o más), recetas del skill y errores, README.

**Resultado 12** (Polylang 3.9.1, español por defecto + inglés; probado por REST con el rol del agente): categoría "Noticias" y su traducción "News"; un término "Noticias" en inglés recibe el slug `noticias-en`. Entrada en español traducida con `create-translation`: borrador en inglés con la categoría "News", publicada en `/en/hello-multilingual-world/` con `lang="en-US"` y el nombre del sitio traducido en el `<title>` (`update-string-translations`). Página de YOOtheme "Servicios" traducida: `/en/services/` con el mismo diseño y los textos cambiados con `patch-layout`. Menú por idioma en la misma ubicación (`navbar`) con el selector de idioma: cada idioma ve su menú y los enlaces a la traducción de la página actual. Widget con `pll_lang: "en"` visible solo en inglés. Idioma francés creado, renombrado, con una traducción, borrado y restaurado con su traducción enlazada. `assign-missing-languages` y su snapshot (vuelve a dejar sin idioma). Rechazados: traducción duplicada en un idioma, `translation_of` sin `language`, textos no registrados, claves internas de Polylang (`version`), valores que Polylang no valida (`not_applied`) y cambios de URL sin `confirm`. `smoke.sh`: 196 correctas. Sitio restaurado desde copia de la BD al terminar.

**Encontrado y corregido durante las pruebas:** al enlazar un contenido que ya tenía traducciones, la antigua seguía apuntando al grupo nuevo (Polylang reutiliza el término de grupo de cualquier miembro: ahora se desenlaza todo antes de rehacer los grupos, también al restaurar). `duplicate-content` copiaba las taxonomías internas de Polylang y metía la copia en el grupo de traducciones del original. Con Polylang, la web ignora el theme mod `nav_menu_locations` (lee la opción `nav_menus` por idioma), así que `locations` de los menús no se veía. La clase de cada widget descarta `pll_lang` al guardar. `update-menu` en otro idioma sin `name` no hacía nada. Con la prueba de la home en tres idiomas (español, inglés y japonés, creado con `create-language {"locale": "ja"}`): al pasar la portada de "últimas entradas" a una página, `/en/` y `/ja/` daban "Nothing Found" porque Polylang solo carga `PLL_Static_Pages` en REST si la portada ya era una página (ahora se carga y se limpia la caché); y cambiar `redirect_lang` no cambiaba las URLs hasta limpiar la caché de idiomas (ahora se limpia tras cualquier cambio de ajustes, y `redirect_lang` exige `confirm`).

**Terminado cuando:** el agente añade un idioma, traduce una página de YOOtheme y una entrada con su categoría, pone un menú por idioma con el selector y traduce el nombre del sitio; todo se puede deshacer.

---

## Fuera de alcance en esta etapa
- Instalar, activar o actualizar temas. Los plugins se tratan en la Fase 8.
- WooCommerce: configurar pasarelas de pago, crear o editar pedidos a mano (líneas e importes), editar clientes, informes y analítica, suscripciones y extensiones de pago.
- Usuarios y roles más allá del rol `spiral_mcp_agent`.
- Temas distintos de YOOtheme: las abilities de contenido, menús, medios y SEO deben funcionar con cualquier tema; las de diseño pueden exigir YOOtheme y devolver un error claro si no está activo.

## Preguntas abiertas
- ¿Spiral-MCP será solo para sitios propios, también para clientes o un producto de RasenFx? Afecta a la licencia (hoy GPL-2.0-or-later), a la actualización automática y al nivel de endurecimiento de la Fase 0.
- ¿Se publicará en wordpress.org? Si es así, revisar desde ya los requisitos de las guías de plugins (prefijos, sanitización y escapado, sin código ofuscado).
