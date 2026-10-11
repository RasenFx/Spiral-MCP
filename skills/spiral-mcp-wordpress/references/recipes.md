# Recetas

Pasos probados para las tareas habituales. Todas siguen el flujo de `SKILL.md`: leer, cambiar con poco, revisar y publicar.

## Índice

- [Crear una página nueva a partir de otra](#crear-una-página-nueva-a-partir-de-otra)
- [Crear una página desde cero](#crear-una-página-desde-cero)
- [Añadir una sección](#añadir-una-sección)
- [Subir una imagen y usarla](#subir-una-imagen-y-usarla)
- [Cambiar un elemento del menú](#cambiar-un-elemento-del-menú)
- [Cambiar el header y el logo](#cambiar-el-header-y-el-logo)
- [Editar el footer](#editar-el-footer)
- [Plantilla para las entradas](#plantilla-para-las-entradas)
- [Cambiar la marca (colores y tipografía)](#cambiar-la-marca-colores-y-tipografía)
- [Programar una publicación](#programar-una-publicación)
- [Página hija con plantilla, categorías y SEO](#página-hija-con-plantilla-categorías-y-seo)
- [Deshacer un cambio](#deshacer-un-cambio)

## Crear una página nueva a partir de otra

1. `duplicate-content {"id": <página modelo>, "title": "Servicios"}`: crea un borrador con el mismo layout, plantilla, imagen destacada y términos.
2. `get-layout-outline` y `patch-layout` para cambiar textos e imágenes; `update-content` para slug, padre o plantilla.
3. `update-seo`, después `inspect-page`, y por último publicar.

## Crear una página desde cero

1. `create-content {"title": "Landing", "status": "draft"}`.
2. `update-yootheme-layout {"id": <id>, "layout": {...}}` con un árbol completo (raíz `layout`, `section > row > column > elementos`). Para conocer los props usa `list-elements` y `get-element-schema`, o parte de un layout de la biblioteca (`list-library`, `insert-from-library`).
3. Lee los `warnings` de la respuesta y corrige.

## Añadir una sección

- **Desde la biblioteca:** `list-library`, y después `insert-from-library {"item_id": "...", "id": 12, "path": "/children/2"}`.
- **Copiando una sección de la misma página:** `{"op": "copy", "from": "/children/1", "path": "/children/2"}`, y luego `replace` de los textos de la copia, cuyas rutas empiezan por `/children/2`.
- **Nueva:**
  ```json
  {"op": "add", "path": "/children/-", "value": {"type": "section", "children": [{"type": "row", "children": [{"type": "column", "children": [
    {"type": "headline", "props": {"content": "Título", "title_element": "h2"}},
    {"type": "text", "props": {"content": "<p>Texto</p>"}}
  ]}]}]}}
  ```

## Subir una imagen y usarla

1. `upload-media {"url": "https://…/foto.jpg", "alt": "Equipo en la oficina", "attach_to": 12}`. También acepta `data_base64` + `filename`, y solo URLs https públicas. Guarda `path_relative` y `id`.
2. En el layout: `{"op": "replace", "path": ".../props/image", "value": "wp-content/uploads/2026/10/foto.jpg"}`, más `image_alt`.
3. Imagen destacada: `set-featured-image {"post_id": 12, "media_id": 345}` (o `featured_media` en `update-content`).
4. Para cambiar el alt después: `update-media {"id": 345, "alt": "..."}`.

## Cambiar un elemento del menú

1. `list-menus` y `get-menu {"menu": "principal"}`.
2. `update-menu-item {"item_id": 80, "title": "Contacto", "url": "/contacto/"}`. Para recolocarlo usa `position` (1 = primero) y `parent_id`.
3. Nuevo enlace: `add-menu-item {"menu": "principal", "type": "custom", "title": "Blog", "url": "/blog/"}`, o `type: "page"` + `object_id`.
4. El mega menú de un elemento se edita como un área: `get-theme-layout {"area": "menu_item:80"}` / `patch-layout {"area": "menu_item:80", ...}`.

## Cambiar el header y el logo

1. Sube el logo con `upload-media` y guarda el `path_relative`.
2. `update-theme-config {"section": "logo", "values": {"image": "wp-content/uploads/…/logo.svg"}}`.
3. Disposición: `get-theme-config {"section": "header"}`, que trae en `defaults` las claves válidas. Después `update-theme-config {"section": "header", "values": {"layout": "stacked-center-a"}}`. Es un merge: solo cambian las claves enviadas.
4. Navbar fija: sección `navbar` (`sticky`). Versión móvil: sección `mobile` (p. ej. `{"header": {"layout": "horizontal-center-logo"}}`).
5. Contenido de builder en el header, la navbar, top o bottom: son widgets de builder. Busca en las `areas` de `get-theme-layout` las que empiezan por `widget:`.

## Editar el footer

`get-layout-outline {"area": "footer"}`, después `patch-layout {"area": "footer", "operations": [...]}`. Si el footer está vacío, créalo con `update-theme-layout {"area": "footer", "layout": {...}}`.

## Plantilla para las entradas

1. `list-templates`: el campo `types` trae los tipos de plantilla del sitio (`single-post`, `archive-post`, `taxonomy-category`, `search`, `error-404`…) y los campos de `query` de cada uno.
2. `create-template {"name": "Entrada", "type": "single-post", "layout": {...}}`. Para mostrar el título o el contenido de cada entrada usa fuentes dinámicas, por ejemplo:
   ```json
   {"type": "headline", "props": {"title_element": "h1"}, "source": {"query": {"name": "template.posts.singlePost"}, "props": {"content": {"name": "title"}}}}
   ```
3. Se aplica a las entradas que no tienen layout propio, por orden: gana la primera plantilla activa que encaja. Compruébalo abriendo una entrada (`inspect-page {"path": "/?p=<id>"}`).

## Cambiar la marca (colores y tipografía)

1. `list-style-variables {"component": "global"}`: grupos primary, secondary, colors, backgrounds, typography. Los valores por defecto son expresiones LESS (`darken(@global-primary-background, 5%)`).
2. `update-theme-style {"less": {"@global-primary-background": "#e63946", "@global-secondary-background": "#1d3557", "@global-font-family": "'Roboto'"}}`. Las fuentes de Google van entre comillas simples y YOOtheme las descarga sola; `null` devuelve una variable a su valor por defecto.
3. Recompila: abre `recompile_url` en un navegador con sesión y espera. Después `get-style-status` → `needs_recompile: false`.
4. Comprueba la portada (captura o `inspect-page {"path": "/"}`).
5. Para un retoque inmediato sin recompilar: `update-custom-css {"css": "...", "mode": "append"}`.

## Programar una publicación

`update-content {"id": 12, "status": "publish", "date": "2026-11-01T09:00:00+01:00"}` deja el contenido en estado `future`. `list-content {"status": "future"}` lista lo programado.

## Página hija con plantilla, categorías y SEO

1. `list-post-types`: muestra qué tipos son jerárquicos, sus taxonomías y las plantillas de página disponibles. Las páginas no tienen categorías en WordPress; las entradas sí.
2. `create-content {"title": "Consultoría", "parent": <id madre>, "template": "<plantilla>", "featured_media": <id>, "status": "draft"}`.
3. Términos: `create-term` si no existen, y luego `terms: {"category": ["noticias"]}` en `create-content` o `update-content` (solo en tipos que los admiten).
4. `update-seo {"id": <id>, "title": "...", "description": "..."}`.

## Deshacer un cambio

- **Página o entrada (título, contenido, layout):** `list-revisions {"id": 12}` → `restore-revision {"revision_id": …, "confirm": true}`. Antes guarda el estado actual, así que también se puede deshacer la restauración.
- **Todo lo demás:** `list-activity` (filtra por `ability` u `object_id`) → `restore-snapshot {"id": <snapshot_id>, "confirm": true}`.
- Si restauras un snapshot que cambia el estilo, vuelve a hacer falta recompilar (`get-style-status`).
