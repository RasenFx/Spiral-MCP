# Recetas

Pasos probados para las tareas habituales. Todas siguen el flujo de `SKILL.md`: leer, cambiar con poco, revisar y publicar.

## Índice

- [Crear una página nueva a partir de otra](#crear-una-página-nueva-a-partir-de-otra)
- [Crear una página desde cero](#crear-una-página-desde-cero)
- [Añadir una sección](#añadir-una-sección)
- [Subir una imagen y usarla](#subir-una-imagen-y-usarla)
- [Crear el menú principal](#crear-el-menú-principal)
- [Cambiar un elemento del menú](#cambiar-un-elemento-del-menú)
- [Cambiar el header y el logo](#cambiar-el-header-y-el-logo)
- [Editar el footer](#editar-el-footer)
- [Widgets: barra lateral, barra superior, top y bottom](#widgets-barra-lateral-barra-superior-top-y-bottom)
- [Plantilla para las entradas](#plantilla-para-las-entradas)
- [Página del blog con rejilla de entradas](#página-del-blog-con-rejilla-de-entradas)
- [Usar la librería online de YOOtheme](#usar-la-librería-online-de-yootheme)
- [Cambiar la marca (colores y tipografía)](#cambiar-la-marca-colores-y-tipografía)
- [Programar una publicación](#programar-una-publicación)
- [Página hija con plantilla, categorías y SEO](#página-hija-con-plantilla-categorías-y-seo)
- [Un cambio no se ve (cachés)](#un-cambio-no-se-ve-cachés)
- [Deshacer un cambio](#deshacer-un-cambio)

## Crear una página nueva a partir de otra

1. `duplicate-content {"id": <página modelo>, "title": "Servicios"}`: crea un borrador con el mismo layout, plantilla, imagen destacada y términos.
2. `get-layout-outline` y `patch-layout` para cambiar textos e imágenes; `update-content` para slug, padre o plantilla.
3. `update-seo`, después `inspect-page`, y por último publicar.

## Crear una página desde cero

1. Comprueba los destinos de los enlaces que vas a poner (`list-content`).
2. `create-content {"title": "Servicios", "slug": "servicios", "status": "draft"}`.
3. `update-yootheme-layout {"id": <id>, "layout": {...}}` con un árbol completo (raíz `layout`, `section > row > column > elementos`). Ejemplo probado con hero, tres columnas y llamada a la acción (adáptalo; para otros props usa `get-element-schema`, o parte de la biblioteca con `list-library` / `insert-from-library`):
   ```json
   {"type": "layout", "children": [
     {"type": "section", "props": {"style": "secondary", "text_color": "light", "padding_top": "xlarge", "padding_bottom": "xlarge", "name": "Hero"}, "children": [
       {"type": "row", "children": [{"type": "column", "children": [
         {"type": "headline", "props": {"content": "Webs que trabajan para tu negocio", "title_element": "h1", "title_style": "heading-large", "text_align": "center"}},
         {"type": "text", "props": {"content": "<p>Entradilla.</p>", "text_style": "lead", "text_align": "center"}},
         {"type": "button", "props": {"text_align": "center", "margin_top": "medium"}, "children": [
           {"type": "button_item", "props": {"content": "Pide presupuesto", "link": "/contacto/", "button_style": "primary"}}]}]}]}]},
     {"type": "section", "props": {"style": "default", "name": "Servicios"}, "children": [
       {"type": "row", "props": {"layout": "1-3,1-3,1-3", "column_gap": "large"}, "children": [
         {"type": "column", "props": {"width_medium": "1-3", "style": "card-default", "padding": "large"}, "children": [
           {"type": "headline", "props": {"content": "Diseño web", "title_element": "h3"}},
           {"type": "text", "props": {"content": "<p>Descripción.</p>"}}]},
         {"type": "column", "props": {"width_medium": "1-3", "style": "card-default", "padding": "large"}, "children": ["…"]},
         {"type": "column", "props": {"width_medium": "1-3", "style": "card-default", "padding": "large"}, "children": ["…"]}]}]},
     {"type": "section", "props": {"style": "primary", "text_color": "light", "name": "Llamada a la acción"}, "children": ["… como el hero, con h2 …"]}
   ]}
   ```
   - `title_element` es la etiqueta (h1, h2…: la jerarquía para SEO, un solo h1 por página) y `title_style` el tamaño visual (`heading-large`, `h1`…): un h2 puede verse grande sin romper la jerarquía.
   - En filas con varias columnas pon `layout` en la fila (`"1-2,1-2"`, `"1-3,1-3,1-3"`, `"2-3,1-3"`…) y el ancho en cada columna (`width_medium`); sin `layout` las columnas se apilan.
4. Lee los `warnings` de la respuesta y corrige. Revisa con `inspect-page`, guarda el SEO con `update-seo` y da al usuario la URL de `get-preview-url`.

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

## Crear el menú principal

1. `list-menus`: menús existentes y ubicaciones (`locations`). `list-content {"post_type": "any"}` para los destinos; si una página no existe, ver el principio 7 del skill.
2. `create-menu {"name": "Menú principal", "items": [{"type": "custom", "title": "Inicio", "url": "/"}, {"type": "page", "object_id": 12}], "locations": ["navbar", "dialog-mobile"]}`.
   - En YOOtheme el menú de la cabecera de escritorio va en la ubicación `navbar` (en todas las disposiciones de `header.layout`). En móvil, `dialog-mobile` es el menú que abre el botón de hamburguesa (`navbar-mobile` si la cabecera móvil muestra una barra de navegación; mira `get-theme-config {"section": "mobile"}`).
   - Las demás ubicaciones (`header`, `toolbar-left`, `toolbar-right`, `dialog`, `header-mobile`) son posiciones extra.
3. Lee los `warnings`: avisan de elementos que enlazan a borradores (404 para los visitantes hasta publicarlos).
4. Comprueba la portada: `inspect-page {"path": "/"}` debe listar los enlaces del menú.

## Cambiar un elemento del menú

1. `list-menus` y `get-menu {"menu": "principal"}`.
2. `update-menu-item {"item_id": 80, "title": "Contacto", "url": "/contacto/"}`. Para recolocarlo usa `position` (1 = primero) y `parent_id`.
3. Nuevo enlace: `add-menu-item {"menu": "principal", "type": "custom", "title": "Blog", "url": "/blog/"}`, o `type: "page"` + `object_id`.
4. El mega menú de un elemento se edita como un área: `get-theme-layout {"area": "menu_item:80"}` / `patch-layout {"area": "menu_item:80", ...}`.

## Cambiar el header y el logo

1. Sube el logo con `upload-media` y guarda el `path_relative`. Si ya está en la biblioteca, búscalo con `list-media` y ponle `alt` con `update-media`.
2. `get-theme-config {"section": "logo"}`: trae `known_keys` con las claves válidas. Después `update-theme-config {"section": "logo", "values": {"image": "wp-content/uploads/…/logo.png", "image_width": 50, "image_height": 50, "text": "Nombre de la marca"}}`.
   - El alt del logo en la página sale de `text`, no del alt del medio: rellénalo siempre.
   - Da un tamaño en px si la imagen es grande (YOOtheme sirve una copia redimensionada). Para un logo cuadrado, ancho y alto iguales.
   - `image_mobile` (si falta, se usa `image`), `image_inverse` para fondos oscuros, `image_svg_inline` para SVG.
   - Compruébalo con `inspect-page {"path": "/"}`: `images.empty_alt` debe quedar vacío.
3. Disposición: `get-theme-config {"section": "header"}`, que trae en `defaults` las claves válidas. Después `update-theme-config {"section": "header", "values": {"layout": "stacked-center-a"}}`. Es un merge: solo cambian las claves enviadas.
4. Navbar fija: sección `navbar` (`sticky`). Versión móvil: sección `mobile` (p. ej. `{"header": {"layout": "horizontal-center-logo"}}`).
5. Contenido de builder en el header, la navbar, top o bottom: son widgets de builder. Busca en las `areas` de `get-theme-layout` las que empiezan por `widget:`.

## Editar el footer

`get-layout-outline {"area": "footer"}`, después `patch-layout {"area": "footer", "operations": [...]}`. Si el footer está vacío, créalo con `update-theme-layout {"area": "footer", "layout": {...}}`. Una línea de copyright:

```json
{"type": "layout", "children": [{"type": "section", "props": {"style": "muted", "padding_top": "small", "padding_bottom": "small"}, "children": [
  {"type": "row", "children": [{"type": "column", "children": [
    {"type": "text", "props": {"content": "<p>© 2026 Nombre del sitio · <a href=\"/politica-de-privacidad/\">Política de privacidad</a></p>", "text_style": "small", "text_color": "muted", "text_align": "center"}}]}]}]}]}
```

El año y el nombre quedan escritos tal cual: díselo al usuario. Comprueba con `inspect-page {"path": "/"}`.

## Widgets: barra lateral, barra superior, top y bottom

1. `list-widgets`: áreas con sus widgets y `types` (tipos disponibles). En YOOtheme, `toolbar-left`/`toolbar-right` es la barra superior, `top`/`bottom` van encima y debajo del contenido y `sidebar` es la lateral. Lo que el usuario llama "arriba" suele ser la barra superior, no `top`: compruébalo en la lista.
2. **Quitar** del sitio sin perderlo: `update-widget {"widget_id": "block-5", "sidebar": "wp_inactive_widgets"}`. **Borrar** de verdad: `delete-widget {"widget_id": "block-5", "confirm": true}` (deja snapshot).
3. **Crear**:
   - Texto con bloques: `create-widget {"type": "block", "sidebar": "sidebar", "instance": {"content": "<!-- wp:paragraph --><p>Texto</p><!-- /wp:paragraph -->"}}`.
   - Menú: `{"type": "nav_menu", "sidebar": "toolbar-left", "instance": {"nav_menu": 81}}`.
   - Contenido con el builder de YOOtheme en `top` o `bottom`: raíz `layout` con secciones, porque YOOtheme no le pone sección alrededor (con `fragment` sale pegado a los bordes, sin contenedor): `{"type": "builderwidget", "sidebar": "top", "layout": {"type": "layout", "children": [{"type": "section", "props": {"style": "muted", "padding_top": "large", "padding_bottom": "large"}, "children": [{"type": "row", "children": [{"type": "column", "children": [...]}]}]}]}}`.
   - En las demás áreas (sidebar, header, navbar…) el widget va dentro del módulo del tema: raíz `fragment` > row > column, sin secciones. Si la raíz no encaja con el área, la respuesta lo avisa en `warnings`.
   - `position` (1 = primero) coloca el widget dentro del área; sin él va al final.
4. **Editar**: `update-widget` con las claves de `instance` que cambian, `sidebar` para moverlo o `position` para reordenarlo. Los widgets de builder se editan como un área: `get-layout-outline {"area": "widget:builderwidget-2"}` y `patch-layout`.
5. Comprueba en una página donde se vea el área (`inspect-page`; la lateral sale en entradas, archivos y búsquedas). Para deshacer una creación, restaura su `snapshot_id`.

## Plantilla para las entradas

1. `list-templates`: el campo `types` trae los tipos de plantilla del sitio (`single-post`, `archive-post`, `taxonomy-category`, `search`, `error-404`…) y los campos de `query` de cada uno.
2. `create-template {"name": "Entrada", "type": "single-post", "layout": {...}}`. Para mostrar el título o el contenido de cada entrada usa fuentes dinámicas. El nombre de la consulta es `posts.singlePost` (sin prefijo; con otro nombre la plantilla sale vacía):
   ```json
   {"type": "headline", "props": {"title_element": "h1"}, "source": {"query": {"name": "posts.singlePost"}, "props": {"content": {"name": "title"}}}},
   {"type": "text", "source": {"query": {"name": "posts.singlePost"}, "props": {"content": {"name": "content"}}}}
   ```
   Campos útiles de una entrada: `title`, `content`, `excerpt`, `date` (con `"filters": {"date": "j F, Y"}`), `link`, `featuredImage.url` y `featuredImage.alt`.
3. Se aplica a las entradas que no tienen layout propio, por orden: gana la primera plantilla activa que encaja. Compruébalo abriendo una entrada (`inspect-page {"path": "/hello-world/"}`).

## Página del blog con rejilla de entradas

1. Crea y publica la página (`create-content {"title": "Blog", "slug": "blog", "status": "publish"}`) y asígnala como página de entradas: `update-site-settings {"page_for_posts": <id>}`. Esa página no usa su propio layout: WordPress muestra ahí el listado de entradas, y YOOtheme le aplica la plantilla de tipo `archive-post`.
2. `create-template {"name": "Blog", "type": "archive-post", "layout": {...}}` con un `grid` cuyo único `grid_item` lleva la fuente `posts.archivePost` (la lista de entradas de la página actual). YOOtheme repite ese item por cada entrada:
   ```json
   {"type": "grid", "props": {"grid_default": "1", "grid_small": "2", "grid_medium": "3", "grid_column_gap": "large", "grid_row_gap": "large",
     "panel_style": "card-default", "panel_padding": "default", "panel_image_no_padding": true,
     "show_title": true, "show_meta": true, "show_content": true, "show_image": true, "show_link": true,
     "title_element": "h2", "meta_style": "text-meta", "meta_align": "above-title", "link_text": "Leer más"},
    "children": [{"type": "grid_item", "props": {}, "source": {"query": {"name": "posts.archivePost", "arguments": {}}, "props": {
      "title": {"name": "title"}, "meta": {"name": "date", "filters": {"date": "j F, Y"}}, "content": {"name": "excerpt"},
      "image": {"name": "featuredImage.url"}, "image_alt": {"name": "featuredImage.alt"}, "link": {"name": "link"}}}}]},
   {"type": "pagination", "props": {"text_align": "center", "margin_top": "large"}}
   ```
   - Con `panel_style` de tarjeta pon también `panel_padding`; si no, el texto queda pegado al borde.
   - El elemento `pagination` usa la paginación del propio listado.
   - Las categorías, etiquetas y autores usan sus propios tipos (`taxonomy-category`, `taxonomy-post_tag`, `author-archive`): crea una plantilla para cada uno, o el sitio usará la vista por defecto del tema.
3. Comprueba con `inspect-page {"path": "/blog/"}`: debe salir un enlace "Leer más" por entrada publicada.

## Usar la librería online de YOOtheme

La librería de YOOtheme tiene cientos de diseños hechos por su equipo (páginas Home, Services, About…, plantillas Post, Post Index, Search, Error 404…, footers, mega menús y elementos sueltos), agrupados por web de ejemplo con el mismo nombre que los estilos (Circle, Fuse…).

1. **Busca:** `list-online-library {"group": "template", "website": "Circle"}` (o `"group": "layout", "type": "Home"`, o `"kind": "element", "type": "accordion"`). Sin filtros, `facets` dice qué grupos, tipos y webs hay. Los diseños de la web que coincide con el estilo activo (`get-theme-style`) encajan mejor. `has_api_key: false` = falta la clave: pide al usuario que la ponga en Personalizar > Ajustes > API Key.
2. **Mira antes de importar:** `get-online-library-item {"id": "a8c540b2"}` da el esquema, cuántas imágenes trae y cuántos nodos tienen fuente dinámica. `preview` (en el listado) es una imagen del diseño para enseñársela al usuario.
3. **Importa:**
   - Como plantilla: `import-from-online-library {"id": "a8c540b2", "target": "template"}` (tipo deducido; si es null, indica `template_type`). Queda **desactivada**.
   - En una página: `{"id": "...", "target": "insert", "post_id": 12, "path": "/children/1"}`. Si la página aún no usa el builder, el diseño pasa a ser su layout.
   - Un elemento suelto en una columna: `{"id": "...", "kind": "element", "target": "insert", "post_id": 12, "path": "/children/0/children/0/children/0/children/-"}`.
   - En la biblioteca del sitio, para reutilizarlo: `"target": "library"`.
   Las imágenes se descargan a la biblioteca de medios (sin duplicar).
4. **Adapta:** los diseños son contenido de muestra (lorem ipsum, autores, direcciones y fotos de ejemplo).
   - `get-layout-texts {"id": 12, "kinds": ["text", "link", "image"]}` (o `"area": "template:<id>"`) lista **todos** los textos, enlaces e imágenes con su ruta: también los que no salen en el esquema, como etiquetas y mensajes de formularios (`props/provider/message` de un newsletter), textos de botones, metas o el alt. Cámbialos en un solo `patch-layout` con un `replace` por entrada; en los que tienen `"html": true` conserva las etiquetas y los enlaces (`tel:`, `mailto:`).
   - Lo que no es texto también es de muestra: coordenadas de un `map_item` (`location`), proveedor del newsletter (Mailchimp sin lista), enlaces `#`. Avisa al usuario en lugar de dejarlo a medias.
   - Vuelve a llamar a `get-layout-texts` al final: no debe quedar nada en el idioma o con los datos de la web de ejemplo.
   - En plantillas, conecta título, fecha, imagen y contenido con fuentes dinámicas (`posts.singlePost`, `posts.archivePost`; ver las recetas de plantillas) antes de activarla con `update-template {"id": ..., "status": "enabled"}`: si no, todas las entradas mostrarían el mismo artículo de ejemplo.
5. Revisa con `inspect-page` (o la URL de `get-preview-url` en un navegador) y enseña el resultado al usuario.

## Cambiar la marca (colores y tipografía)

1. `list-style-variables {"component": "global"}`. Los colores están en los grupos `colors` y `backgrounds`; el color principal de la marca es `@global-primary-background`, del que salen botones, secciones y fondos "primary". Ojo: los grupos `primary` y `secondary` son las **fuentes** primaria y secundaria, no colores. Los valores por defecto son expresiones LESS (`darken(@global-primary-background, 5%)`): cambiar la variable base arrastra a las derivadas.
2. `update-theme-style {"less": {"@global-primary-background": "#e63946", "@global-secondary-background": "#1d3557", "@global-font-family": "'Roboto'"}}`. Las fuentes de Google van entre comillas simples y YOOtheme las descarga sola; `null` devuelve una variable a su valor por defecto.
3. Recompila: abre `recompile_url` en un navegador con sesión y espera. Después `get-style-status` → `needs_recompile: false`.
4. Comprueba la portada (captura o `inspect-page {"path": "/"}`).
5. Para un retoque inmediato sin recompilar: `update-custom-css {"css": "...", "mode": "append"}`.

## Programar una publicación

1. `get-site-settings`: comprueba la zona horaria del sitio (`timezone`). Si no es la del usuario, avísale: wp-admin mostrará la hora en esa zona. Cambiarla (`update-site-settings {"timezone_string": "Europe/Madrid"}`) afecta a todo el sitio: hazlo solo si te lo pide.
2. Crea en borrador y revisa (`inspect-page`) como cualquier contenido.
3. `update-content {"id": 12, "status": "publish", "date": "2026-11-01T09:00:00+01:00"}` lo deja en estado `future`; también vale `create-content` con esos dos campos si no hace falta revisar. Envía siempre la zona en la fecha. `list-content {"status": "future"}` lista lo programado.

## Página hija con plantilla, categorías y SEO

1. `list-post-types`: muestra qué tipos son jerárquicos, sus taxonomías y las plantillas de página disponibles. Las páginas no tienen categorías en WordPress; las entradas sí.
2. `create-content {"title": "Consultoría", "parent": <id madre>, "template": "<plantilla>", "featured_media": <id>, "status": "draft"}`.
3. Términos: `create-term` si no existen, y luego `terms: {"category": ["noticias"]}` en `create-content` o `update-content` (solo en tipos que los admiten).
4. `update-seo {"id": <id>, "title": "...", "description": "..."}`.

## Un cambio no se ve (cachés)

Las escrituras ya purgan solas la caché de lo que cambian (un contenido: esa página, la portada y sus listados; un cambio de diseño global: todo). Si aun así algo no se ve:

1. ¿Es un estilo (colores, tipografía, LESS)? Entonces no es caché: falta recompilar (`get-style-status`).
2. `get-cache-status`: qué herramientas de caché hay (WP Super Cache, WP Fastest Cache, W3 Total Cache, LiteSpeed, SiteGround, Nginx Helper, WP Rocket, cachés de hosting, caché de objetos…), qué admite cada una (`supports`) y si están activadas. Lee `problem` y `note`: algunas no pueden funcionar en ese servidor (LiteSpeed fuera de un servidor LiteSpeed, SiteGround fuera de su hosting, Nginx Helper sin Nginx). Sin herramientas, no hay caché que purgar: el problema es otro (el navegador del usuario, una CDN externa).
3. Purga lo justo: `purge-cache {"scope": "url", "url": "/contacto/"}` o `{"scope": "post", "post_id": 12}`; si no basta, `{"scope": "all"}`. La respuesta dice qué purgó cada herramienta (`purged`: si una no sabe purgar una sola página, purga todo).
4. Tras cambios grandes (rediseño, muchas páginas), `preload-cache {"action": "start"}` regenera la caché en segundo plano, si la herramienta lo admite. No lo hagas tras cada edición: consume recursos.
5. Si el usuario sigue sin verlo, puede ser su navegador: que recargue sin caché (Ctrl+F5) o pruebe en una ventana privada.

**Cambiar ajustes de caché** (activarla, caducidad, no cachear a usuarios con sesión, versión móvil, URLs excluidas…): `get-cache-settings` y después `update-cache-settings {"settings": {"lifetime": 3600}}` (solo cambian las claves enviadas; las propias de la herramienta van en `provider_settings`). Exige el permiso de caché (`spiral_mcp_cache`), que el agente no tiene por defecto: si la respuesta es 403, explícale al usuario qué cambiarías y por qué, y que lo haga él o te dé el permiso. Activar o desactivar la caché afecta a todo el sitio: solo si el usuario lo pide. Se deshace con el `snapshot_id` de la respuesta.

## Deshacer un cambio

- **Página o entrada (título, contenido, layout):** `restore-revision {"revision_id": <undo_revision_id>, "confirm": true}` (o búscala con `list-revisions {"id": 12}`). Antes guarda el estado actual, así que también se puede deshacer la restauración.
- **Un valor concreto** (un color, una sección de la configuración): lo más seguro es revertir solo ese valor con la herramienta que lo cambió. Por ejemplo `update-theme-style {"less": {"@global-primary-background": null}}` vuelve al valor por defecto del estilo. Para saber el valor anterior, mira `list-activity {"ability": "spiral-mcp/update-theme-style", "result": "ok"}`.
- **Con snapshot:** `list-activity` (filtra por `ability` u `object_id`) → `restore-snapshot {"id": <snapshot_id>, "confirm": true}`. Si después hubo otros cambios en el mismo objeto, la llamada falla y los lista (en la configuración del tema, también en qué secciones difiere). Entonces restaura solo la parte que toca: `"parts": ["less"]` (estilo) o `["footer"]`. `"include_later": true` lo restaura entero y deshace también lo posterior: solo si el usuario lo quiere.
- Restaurar el snapshot de un menú recién creado lo borra.
- Si lo restaurado cambia el estilo, vuelve a hacer falta recompilar (`get-style-status`).
