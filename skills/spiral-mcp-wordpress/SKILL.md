---
name: spiral-mcp-wordpress
description: "Cómo trabajar en un sitio WordPress con YOOtheme Pro o con Elementor mediante las herramientas MCP spiral-mcp/* (plugin Spiral-MCP-Control) y, con Elementor, las elementor/* de su MCP. Úsalo siempre que haya herramientas spiral-mcp y la tarea toque el sitio: crear, editar o publicar páginas y entradas; layouts, secciones, botones, textos o imágenes de YOOtheme; páginas de Elementor; header, footer, menús o widgets; formularios; tienda de WooCommerce (productos, precios, stock, cupones, envíos, impuestos, pedidos); idiomas y traducciones con Polylang; subir imágenes; plantillas; SEO; colores, tipografía o CSS de la marca; programar publicaciones; revisar antes de publicar o deshacer un cambio. Aplícalo aunque el usuario no diga \"YOOtheme\" ni \"MCP\" (p. ej. \"cambia el botón de la portada\", \"pon el logo nuevo\", \"crea una landing de servicios\")."
---

# Trabajar en WordPress (YOOtheme o Elementor) con spiral-mcp

Las herramientas `spiral-mcp/*` controlan un sitio WordPress sin entrar en wp-admin. El diseño de las páginas depende del builder del sitio: con YOOtheme Pro se edita con las herramientas de layout de spiral-mcp; con Elementor, con las herramientas `elementor/*` de su propio MCP (ver «Sitios con Elementor»). Lo primero es saber dónde estás: si en tu lista hay `list-elements` y `get-theme-config`, el sitio tiene YOOtheme; si no están y hay `elementor/*`, es un sitio de Elementor. `list-content` dice el `builder` de cada página (`yootheme`, `elementor` o `none`). Cada herramienta trae en su descripción qué hace, qué leer antes y qué devuelve: léela cuando dudes. Este skill da el método para usarlas bien juntas.

Si necesitas los parámetros de una herramienta concreta, consulta `references/abilities.md`. Para tareas habituales (página nueva, sección, imagen, menú, header, footer, plantilla, marca, programar, traducir), mira `references/recipes.md`. Si una llamada falla, `references/errors.md` explica los errores frecuentes.

## Principios

1. **Lee antes de escribir.** Cada escritura tiene su lectura: `get-layout-outline` antes de `patch-layout`, `get-theme-config` antes de `update-theme-config`, `get-menu` antes de tocar un menú. Así sabes qué hay y no pisas lo que no ves.
2. **Cambios pequeños y comprobables.** Edita layouts con `patch-layout` y rutas concretas en lugar de reenviar el árbol entero. Es más barato y no destruye cambios de otros. Empieza el patch con una operación `test` sobre el valor que vas a cambiar: si alguien lo modificó desde que lo leíste, el patch falla sin guardar nada.
3. **Borrador primero.** Crea o duplica en `draft`, revisa con `get-preview-url` e `inspect-page` y publica al final. Un error en un borrador no lo ve nadie.
4. **Todo es reversible, y conviene saber cómo.** Los posts guardan revisiones: las escrituras de layout devuelven `undo_revision_id`, que pasas a `restore-revision`. Lo demás (configuración del tema, menús, widgets, medios, SEO, términos, ajustes, estilos, plantillas) guarda un snapshot antes de cada cambio: la respuesta trae `snapshot_id`. Anota esos IDs para poder ofrecer deshacer. Pero un snapshot es la foto del objeto **entero**: toda la configuración del tema (estilo, footer, header, mega menús…) es un solo objeto. Para deshacer un cambio concreto cuando después hubo otros, revierte solo ese valor (p. ej. `update-theme-style` con `null`) o restaura solo esa parte (`restore-snapshot` con `"parts": ["less"]`). `restore-snapshot` se niega a pisar cambios posteriores sin `"include_later": true`; no lo envíes sin que el usuario sepa qué más se deshace.
5. **Los `warnings` son trabajo pendiente.** Muchas herramientas avisan sin bloquear: props desconocidos, valores fuera de sus opciones, ajustes que YOOtheme no conoce, SEO demasiado largo, enlaces rotos. Corrígelos o explica por qué no.
6. **Si falta una herramienta, no busques rodeos.** El administrador puede desactivar herramientas o grupos enteros (Ajustes > Spiral-MCP), y entonces no aparecen en tu lista; también puede quitarle permisos a tu rol (la llamada responde 403). Dile al usuario qué herramienta necesitarías y para qué, y que la active él o lo haga desde wp-admin.
7. **Lo destructivo pide `"confirm": true`.** Envíalo solo cuando el usuario quiera de verdad borrar o restaurar. Antes de borrar un medio, mira `find-media-usage`; antes de borrar contenido, recuerda que solo se borra definitivamente desde la papelera.
8. **Los enlaces deben llevar a algo publicado.** Antes de enlazar una página (botón, menú, footer), compruébala con `list-content`. Si no existe, no la inventes en silencio: créala en borrador y díselo al usuario, o pregúntale el destino. Un enlace a un borrador se ve, pero da 404 a los visitantes hasta que se publica (`inspect-page` lo marca con `target_status`; `get-menu` avisa en `warnings`).

## Convenciones del sitio

- **Enlaces internos: rutas relativas** (`/contacto/`), nunca el dominio. Vale para botones, menús (`type: custom`), HTML y canonical.
- **Imágenes en YOOtheme: `path_relative`** (`wp-content/uploads/2026/10/logo.webp`, sin barra inicial), tal como lo devuelven `upload-media` y `list-media`. Rellena siempre el `alt` (al subir y en el prop `image_alt` del elemento).
- **Subir archivos: nunca base64 para fotos.** `upload-media` con `url` si está en internet; si es un archivo local, `create-upload-url` y el comando curl de su `example` (token en la cabecera `X-Spiral-Upload-Token`). `data_base64` solo para archivos de pocos KB.
- **Fuentes dinámicas.** Un nodo con `"source"` toma sus datos de WordPress (título del post, elementos de menú…); en `get-layout-outline` aparece con `[fuente dinámica]`. No borres ni cambies `source` sin entenderla, y recuerda que cambiar los props de ese nodo puede no verse porque la fuente los sobrescribe.
- **Raíces de layout.** Páginas, footer y plantillas: `"type": "layout"` → section → row → column → elementos. Mega menús y widgets de builder en header, navbar, sidebar y similares: `"type": "fragment"` → row → column → elementos (sin secciones). Los widgets de builder en `top` y `bottom` son la excepción: YOOtheme no les pone sección alrededor, así que llevan raíz `"layout"` con sus propias secciones, como una página. Los elementos múltiples tienen hijos `<tipo>_item` (list → list_item, button → button_item). Los anchos de columna (`width_default`, `width_medium`…) se ponen en cada columna. Ante la duda: `list-elements` y `get-element-schema`.
- **Sitios con varios idiomas (Polylang).** Si existe `list-languages`, cada contenido, término y menú es de un idioma y sus traducciones son objetos aparte enlazados. Antes de crear o enlazar algo, mira su idioma (`language` en `list-content`, `get-content` y `list-terms`) y crea lo nuevo en el idioma correcto (`"language"`). Un botón o menú en inglés debe llevar a la página en inglés. Para traducir, `create-translation` (ver las recetas de idiomas). La configuración del tema (footer, header) es común a todos los idiomas.
- **`version`** en la raíz de los layouts: si falta se añade; no la quites.
- **Fechas:** envíalas en ISO 8601 con zona (`2026-11-01T09:00:00+01:00`). En las respuestas, lo que acaba en `Z` es UTC (`date_gmt`, `expires_at`, auditoría y snapshots) y lo que no lleva zona (`date`, `modified`) es la hora local del sitio. `get-site-settings` da la zona (`timezone`) y la hora local: compruébala antes de programar y avisa si no es la del usuario.
- **Header, navbar, top, bottom** no tienen layout propio: sus ajustes van en `update-theme-config` (secciones `header`, `navbar`, `mobile`, `logo`…) y su contenido de builder son widgets de builder (áreas `widget:builderwidget-N`). `get-theme-layout` lista todas las áreas con builder del sitio. **El menú de la cabecera** no se pone con `update-theme-config`, sino asignando el menú a una ubicación (`locations` de `create-menu` o `update-menu`; ver la receta).

## Flujo para cambiar una página

1. Localiza el contenido: `list-content` (`search`, `post_type`, `parent`, `status`).
2. Lee el árbol: `get-layout-outline {"id": 12}`. Cada línea es un nodo con su ruta JSON Pointer, su tipo y un extracto del texto.
3. Mira el nodo exacto: `get-layout-node {"id": 12, "path": "/children/1/children/0/children/0/children/2"}`. Para cambiar muchos textos a la vez (traducir, adaptar un diseño importado), `get-layout-texts` da todos los textos visibles con su ruta, incluidos los que el esquema no muestra (botones, formularios, metas). Si no conoces sus props: `get-element-schema {"type": "button_item"}`.
4. Cambia con `patch-layout` (operaciones `test`, `replace`, `add`, `remove`, `move`, `copy`). Se aplican en orden y de forma atómica:
   ```json
   {"id": 12, "operations": [
     {"op": "test", "path": "/children/1/children/0/children/0/children/2/children/0/props/content", "value": "Más info"},
     {"op": "replace", "path": "/children/1/children/0/children/0/children/2/children/0/props/content", "value": "Pide presupuesto"},
     {"op": "replace", "path": "/children/1/children/0/children/0/children/2/children/0/props/link", "value": "/contacto/"}
   ]}
   ```
5. Revisa: `inspect-page {"id": 12}` (encabezados, enlaces rotos, imágenes sin alt, SEO efectivo y avisos). Los avisos miran solo el contenido: los encabezados con `"in_content": false` son del tema (widgets, comentarios) y no se arreglan en la página. Para cambios globales (menú, footer, header, estilos) inspecciona la portada: `{"path": "/"}`. Si tienes navegador, abre la URL de `get-preview-url` y haz una captura: es la única forma de ver el diseño de verdad.
6. Publica si era un borrador: `update-content {"id": 12, "status": "publish"}`. Las cachés de página se purgan solas tras cada escritura; si algo no se ve, mira la receta «Un cambio no se ve (cachés)».
7. Cuenta al usuario qué cambiaste, qué avisos quedaron y cómo deshacerlo (`undo_revision_id` o `snapshot_id`). Si lo dejas en borrador, dale la URL de `get-preview-url` y cuándo caduca.

El mismo flujo sirve para el footer, los mega menús, los widgets de builder y las plantillas: usa `"area"` en lugar de `"id"` (`"footer"`, `"menu_item:80"`, `"widget:builderwidget-3"`, `"template:<id>"`).

## Estilos: el CSS no cambia solo

`update-theme-style` (colores, tipografía, estilo base, LESS) guarda la configuración, pero **el CSS no cambia hasta recompilar**, y YOOtheme solo compila en un navegador. La respuesta trae `needs_recompile: true` y `recompile_url`:

- Si tienes navegador: abre `recompile_url` con la sesión del usuario, espera 10-20 s y comprueba con `get-style-status` que `needs_recompile` es `false`.
- Si no lo tienes: díselo claramente al usuario y pásale `recompile_url` para que la abra él. No digas que el cambio ya se ve.
- Para un retoque que deba verse al instante, usa `update-custom-css` (no necesita recompilar).

## Sitios con Elementor

Spiral-MCP no edita el diseño de Elementor: lo hace el MCP de Elementor, cuyas herramientas `elementor/*` aparecen en tu misma lista cuando el administrador lo tiene activado (wp-admin > Elementor > Elementor MCP). Spiral les pone sus permisos por grupo y las anota en `list-activity`, así que valen las mismas reglas: lee antes de escribir, borrador primero, cuenta lo que cambiaste y cómo deshacerlo.

- **Qué hace cada uno.** Con `spiral-mcp/*`: títulos, estados, slugs, páginas madre, categorías, imagen destacada, SEO, menús, widgets, medios (`upload-media`; `elementor/list-assets` los lista por `id` para componer), ajustes, cachés, plugins, formularios, tienda, idiomas, vista previa (`get-preview-url` e `inspect-page` renderizan páginas de Elementor) y deshacer (`restore-revision`: Elementor guarda sus datos en las revisiones de WordPress). Con `elementor/*`: el diseño (`get-page-structure`, `build-composition`, `manage-elements`, `update-page-settings`), el kit (`manage-global-variable`, `manage-classes`, `manage-default-styles`), los componentes y `publish-document`.
- **Una página de Elementor** (`"builder": "elementor"` en `list-content` / `get-content`) ignora su `content`: `update-content` lo rechaza (`spiral_mcp_elementor_content`). No intentes reproducir el diseño en HTML. Los demás campos (título, estado, slug, padre, términos, imagen destacada) sí se cambian con `update-content`.
- **Página nueva:** `elementor/create-page` (queda marcada para Elementor) → `elementor/build-composition` → `elementor/create-preview-link` o `get-preview-url` → `inspect-page` → `elementor/publish-document` (o `update-content {"status": "publish"}`). Una página creada con `create-content` no es de Elementor.
- **Página publicada:** los cambios de `build-composition` y `manage-elements` se guardan como borrador de Elementor y no se ven en la web hasta `elementor/publish-document`. Compruébalos con `create-preview-link`.
- **Solo elementos V4 (atómicos).** El MCP de Elementor exige el editor atómico y solo edita elementos atómicos; los widgets clásicos se tocan a mano en el editor de Elementor (`list-widget-schemas` dice cuáles puede configurar). Si una herramienta responde que el editor V4 no está activo, díselo al usuario.
- **Lo que exige administrador:** variables globales, estilos por defecto y componentes piden `manage_options`, que el rol del agente no tiene (403); las clases globales, una capacidad propia de Elementor. Explica el cambio al usuario para que lo haga él, o que amplíe los permisos.
- Antes de maquetar, lee `elementor/style-best-practices` y `elementor/wordpress-best-practices`: son las guías de Elementor para su builder.
- Las herramientas de YOOtheme (`list-elements`, `patch-layout`, `get-theme-config`, plantillas, librería, `update-theme-style`) no existen en un sitio de Elementor, y las recetas de YOOtheme de `references/recipes.md` no aplican: el header, el footer y los estilos globales se hacen en Elementor (su Theme Builder; el kit con `manage-*`). Las recetas «Elementor: …» sí.

## Qué evitar

- En páginas de Elementor, enviar `content` o reproducir el diseño en HTML: el diseño va por `elementor/*`.

- Reenviar layouts enteros para cambios pequeños, o publicar sin `inspect-page`.
- Restaurar un snapshot antiguo de la configuración del tema para deshacer un solo cambio (ver principio 4).
- `list-activity` sin filtros: es larga. Filtra por `ability`, `object_type` u `object_id`, y `"result": "ok"` para ignorar los intentos fallidos.
- Dominios completos en enlaces internos, o imágenes sin `alt`.
- Tocar `yootheme_apikey`, `version`, `site_url` (bloqueadas) o la sección `scripts` (JS) sin que el usuario lo pida.
- Borrar medios, menús, términos o contenido sin comprobar antes qué depende de ellos.
- Compartir URLs de vista previa fuera de quien deba ver el borrador; revócalas con `revoke-preview-url` cuando ya no hagan falta.
- `list-theme-backups` y `restore-theme-backup` están obsoletas: usa `list-snapshots` y `restore-snapshot`.
