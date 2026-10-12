# Spiral-MCP-Control — Roadmap de desarrollo

**Objetivo de esta etapa:** que un agente de IA pueda controlar por completo un sitio WordPress con YOOtheme Pro (contenido, diseño, medios, menús, estilos y ajustes) mediante abilities expuestas por MCP, sin entrar al wp-admin.

**Criterio de éxito global:** desde un cliente MCP, el agente puede (1) crear una página completa con imágenes propias subidas por él, (2) cambiar el header, el footer y la paleta de marca, (3) previsualizar el resultado antes de publicar y (4) deshacer cualquier cambio. Todo con registro de auditoría.

**Orden de las fases:** 0 → 2 → 3 → 1 → 4 → 5 → 6 → 7. Medios y YOOtheme son lo que más bloquea el trabajo de diseño hoy.

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

## Fuera de alcance en esta etapa
- Instalar, activar o actualizar plugins y temas (se trata aparte).
- WooCommerce (fase posterior del sitio).
- Usuarios y roles más allá del rol `spiral_mcp_agent`.
- Temas distintos de YOOtheme: las abilities de contenido, menús, medios y SEO deben funcionar con cualquier tema; las de diseño pueden exigir YOOtheme y devolver un error claro si no está activo.

## Preguntas abiertas
- ¿Spiral-MCP será solo para sitios propios, también para clientes o un producto de RasenFx? Afecta a la licencia (hoy GPL-2.0-or-later), a la actualización automática y al nivel de endurecimiento de la Fase 0.
- ¿Se publicará en wordpress.org? Si es así, revisar desde ya los requisitos de las guías de plugins (prefijos, sanitización y escapado, sin código ofuscado).
