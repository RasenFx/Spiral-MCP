# Guía para el agente — Spiral-MCP-Control

Cómo trabajar en un sitio WordPress con YOOtheme Pro a través de las abilities `spiral-mcp/*`. Esta guía es la base de un futuro skill de Claude: está escrita para el agente, no para el desarrollador del plugin.

## 1. Principios

1. **Lee antes de escribir.** Cada ability de escritura tiene su par de lectura: `get-layout-outline` antes de `patch-layout`, `get-theme-config` antes de `update-theme-config`, `get-menu` antes de cambiar un menú…
2. **Cambios pequeños.** Usa `patch-layout` con rutas concretas en lugar de reenviar layouts enteros. Así es más barato y no pisas cambios ajenos. Añade una operación `test` para asegurarte de que el nodo sigue como lo leíste.
3. **Borrador antes de publicar.** Crea o duplica en `draft`, revisa con `get-preview-url` e `inspect-page` y publica al final.
4. **Todo se puede deshacer.** Las escrituras sobre posts crean revisiones (`list-revisions`, `restore-revision`). Lo demás guarda un snapshot antes de escribir: la respuesta trae `snapshot_id`, que se pasa a `restore-snapshot`. `list-activity` muestra qué se cambió, cuándo y con qué snapshot.
5. **Lee los `warnings`.** Las abilities avisan sin bloquear: props desconocidos, valores fuera de sus opciones, ajustes que YOOtheme no conoce, SEO demasiado largo… Corrígelos antes de seguir.
6. **Las acciones destructivas piden `"confirm": true`.** Antes de enviarlo, asegúrate de que es lo que quieres. Antes de borrar un medio usa `find-media-usage`.

## 2. Convenciones

- **Enlaces internos: rutas relativas** (`/contacto/`), nunca el dominio completo. Aplica a botones, menús (`type: custom`), HTML y canonical.
- **Imágenes en YOOtheme: `path_relative`** (`wp-content/uploads/2026/10/logo.webp`), tal como lo devuelven `upload-media` y `list-media`. Va en el prop de imagen del elemento (`image`, `media`…), sin barra inicial.
- **Rellena siempre `alt`** al subir imágenes (`upload-media`) o en el prop `image_alt` del elemento.
- **Fuentes dinámicas.** Un nodo con `"source": {...}` recibe sus datos de WordPress: título del post, elementos de un menú, etc. No borres ni cambies `source` sin entenderlo. Si cambias `props` de ese nodo, puede que no se vean porque los sobrescribe la fuente. En `get-layout-outline` estos nodos llevan la marca `[fuente dinámica]`.
- **`version` en la raíz** de los layouts: si falta, el plugin la añade. No la quites.
- **Raíces de layout:** páginas, footer y plantillas usan `"type": "layout"` (section > row > column > elementos). Mega menús y widgets de builder usan `"type": "fragment"` (row > column > elementos, sin secciones).
- **Fechas:** ISO 8601 con zona (`2026-11-01T09:00:00+01:00`). Las respuestas devuelven UTC con `Z`.

## 3. Mapa de abilities

| Área | Leer | Escribir |
|---|---|---|
| Contenido | `list-post-types`, `list-content`, `get-content`, `list-revisions` | `create-content`, `update-content`, `duplicate-content`, `trash-content`, `restore-content`, `delete-content`, `restore-revision` |
| Layout (páginas y áreas) | `get-layout-outline`, `get-layout-node`, `get-yootheme-layout`, `list-elements`, `get-element-schema` | `patch-layout`, `update-yootheme-layout` |
| Áreas del tema | `get-theme-layout` (lista `areas`) | `update-theme-layout`, `patch-layout` con `area` |
| Ajustes de YOOtheme | `get-theme-config` | `update-theme-config` |
| Plantillas y biblioteca | `list-templates`, `get-template`, `list-library`, `get-library-item` | `create-template`, `update-template`, `delete-template`, `save-to-library`, `insert-from-library` |
| Medios | `list-media`, `get-media`, `find-media-usage` | `upload-media`, `update-media`, `delete-media`, `set-featured-image` |
| Menús | `list-menus`, `get-menu` | `create-menu`, `update-menu`, `delete-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item` |
| Widgets | `list-widgets` | `update-widget` |
| Taxonomías | `list-taxonomies`, `list-terms` | `create-term`, `update-term`, `delete-term` |
| SEO y sitio | `get-seo`, `get-site-settings` | `update-seo`, `update-site-settings` |
| Estilos | `get-custom-css`, `get-theme-style`, `list-style-variables`, `get-style-status` | `update-custom-css`, `update-theme-style`, `mark-style-compiled` |
| Verificación | `get-preview-url`, `inspect-page` | `revoke-preview-url`, `clear-cache` |
| Historial | `list-activity`, `list-snapshots` | `restore-snapshot` |

`list-theme-backups` y `restore-theme-backup` están obsoletas: usa `list-snapshots` y `restore-snapshot`.

## 4. Flujo recomendado para cambiar una página

1. **Localiza** la página con `list-content` (`search`, `post_type`, `parent`).
2. **Lee el árbol** con `get-layout-outline` `{"id": 12}`. Cada línea trae la ruta, el tipo y un extracto:
   ```
   /children/1/children/0/children/0/children/2  button
   ```
3. **Mira el nodo** que vas a tocar con `get-layout-node` `{"id": 12, "path": "/children/1/children/0/children/0/children/2"}`.
4. **Consulta el esquema** si no conoces los props: `get-element-schema` `{"type": "button_item"}`.
5. **Aplica el cambio** con `patch-layout`, empezando por un `test`:
   ```json
   {"id": 12, "operations": [
     {"op": "test", "path": "/children/1/children/0/children/0/children/2/children/0/props/content", "value": "Más info"},
     {"op": "replace", "path": "/children/1/children/0/children/0/children/2/children/0/props/content", "value": "Pide presupuesto"},
     {"op": "replace", "path": "/children/1/children/0/children/0/children/2/children/0/props/link", "value": "/contacto/"}
   ]}
   ```
6. **Revisa** con `inspect-page` `{"id": 12}`: encabezados, enlaces rotos, imágenes sin alt y SEO efectivo. Si tienes navegador, abre la URL de `get-preview-url` y haz una captura.
7. **Publica** (si era borrador) con `update-content` `{"id": 12, "status": "publish"}`. Las cachés de página se purgan solas después de cada escritura.

Si algo sale mal: usa `list-revisions` y `restore-revision` en una página, o `list-activity` y `restore-snapshot` en lo demás.

## 5. Recetas

### Crear una página nueva a partir de otra
1. `duplicate-content` `{"id": <página modelo>, "title": "Servicios"}` crea un borrador con el mismo layout.
2. Cambia los textos con `patch-layout`, y el slug, padre o plantilla con `update-content`.
3. `update-seo`, después `inspect-page`, y por último publicar.

### Añadir una sección
- **Desde la biblioteca:** `list-library`, y después `insert-from-library` `{"item_id": "...", "id": 12, "path": "/children/2"}`.
- **Copiando otra sección de la misma página:** `{"op": "copy", "from": "/children/1", "path": "/children/2"}`, y después `replace` de los textos.
- **Nueva:** `{"op": "add", "path": "/children/-", "value": {"type": "section", "children": [{"type": "row", "children": [{"type": "column", "children": [{"type": "headline", "props": {"content": "Título", "title_element": "h2"}}]}]}]}}`.

### Subir una imagen y usarla
1. `upload-media` `{"url": "https://…/foto.jpg", "alt": "Equipo en la oficina", "attach_to": 12}`. Guarda el `path_relative` de la respuesta.
2. `patch-layout`: `{"op": "replace", "path": ".../props/image", "value": "wp-content/uploads/2026/10/foto.jpg"}`, junto con el `image_alt` del elemento.
3. Imagen destacada: `set-featured-image` `{"post_id": 12, "media_id": 345}`.

### Cambiar un botón o enlace del menú
`get-menu`, después `update-menu-item` `{"item_id": 80, "title": "Contacto", "url": "/contacto/"}`. El mega menú de un elemento es el área `menu_item:80`.

### Cambiar el header y el logo
1. Sube el logo con `upload-media`.
2. `update-theme-config` `{"section": "logo", "values": {"image": "wp-content/uploads/…/logo.svg"}}`.
3. Disposición: `get-theme-config` `{"section": "header"}`, que trae en `defaults` las claves válidas, y después `update-theme-config`. Los ajustes móviles van en la sección `mobile`.
4. Contenido de builder en el header, la navbar, top o bottom: son widgets de builder (`get-theme-layout` lista las `areas` `widget:builderwidget-N`).

### Footer
`get-layout-outline` `{"area": "footer"}`, después `patch-layout` `{"area": "footer", "operations": [...]}`.

### Plantilla para las entradas
`list-templates` trae en `types` los tipos disponibles y los campos de `query`. Después `create-template` `{"name": "Entrada", "type": "single-post", "layout": {...}}`. Usa fuentes dinámicas (`source`) para el título y el contenido. La plantilla se aplica a las entradas que no tienen layout propio.

### Cambiar la marca (colores y tipografía)
1. Elige las variables con `list-style-variables` `{"component": "global"}`: grupos primary, secondary, colors, backgrounds, typography.
2. `update-theme-style` `{"less": {"@global-primary-background": "#e63946", "@global-font-family": "'Roboto'"}}`. Las fuentes de Google van entre comillas simples.
3. **El CSS no cambia hasta recompilar.** Abre `recompile_url` en un navegador con sesión iniciada y espera entre 10 y 20 segundos: YOOtheme recompila y guarda solo.
4. `get-style-status` debe devolver `needs_recompile: false`. Comprueba la portada con `inspect-page` o una captura.
5. Para un retoque puntual que se vea al instante sin recompilar, usa `update-custom-css`.

### Programar una publicación
`update-content` `{"id": 12, "status": "publish", "date": "2026-11-01T09:00:00+01:00"}` deja el contenido en estado `future`.

## 6. Errores frecuentes

| Síntoma o error | Causa | Qué hacer |
|---|---|---|
| `spiral_mcp_invalid_layout` | Tipo inexistente o anidado imposible (un headline en una section, una row en la raíz…) | Revisa la ruta con `get-layout-outline` y el tipo con `list-elements`. Los elementos van dentro de columnas. |
| `spiral_mcp_test_failed` | El layout cambió desde que lo leíste | Vuelve a leer el nodo y repite el patch. |
| `spiral_mcp_path_not_found` | Ruta mal escrita, o los índices cambiaron por un `add` o `remove` anterior en la misma petición | Las operaciones se aplican en orden: calcula las rutas tras cada operación. |
| Prop en `warnings` como desconocido | Nombre de prop incorrecto | `get-element-schema`. Los anchos de columna (`width_medium`…) van en las columnas. |
| El cambio de estilo no se ve | Falta recompilar | `get-style-status`, y después abre `recompile_url`. |
| El cambio de un nodo con fuente dinámica no se ve | La fuente sobrescribe el prop | Cambia el dato en su origen (título del post, menú…) o la `source`. |
| La página no muestra la plantilla | La página tiene su propio layout de builder, o hay otra plantilla antes que encaja | Las plantillas solo se aplican sin layout propio y por orden de prioridad. |
| `rest_ability_cannot_execute` / sin permiso | Falta un permiso del rol | Avisa a la persona responsable del sitio. |
| `inspect-page` no carga la página | El servidor no se alcanza a sí mismo por su dominio | Pide que se configure el filtro `spiral_mcp_inspect_base_url`. |

## 7. Qué no hacer

- No enviar layouts completos para cambios pequeños.
- No publicar sin pasar antes por `inspect-page`.
- No usar dominios completos en enlaces internos.
- No tocar `yootheme_apikey`, `version` ni `site_url` (bloqueadas), ni pegar JS en `scripts` sin que te lo pidan (requiere permiso de código).
- No borrar medios, menús, términos ni contenido sin `find-media-usage`, `get-menu`, etc., y sin confirmar que es lo que se quiere.
- No compartir las URLs de vista previa fuera de quien deba ver el borrador. Revócalas con `revoke-preview-url` cuando ya no hagan falta.
