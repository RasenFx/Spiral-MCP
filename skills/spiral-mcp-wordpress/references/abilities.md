# Referencia de abilities spiral-mcp/*

Generada a partir de los esquemas registrados por el plugin (v1.9.0). Cada ability trae además su descripción completa en la lista de herramientas MCP: léela allí para los detalles. Aquí está la vista de conjunto: método implícito, parámetros obligatorios (**negrita**) y opcionales.

Leyenda: lectura = no cambia nada; escritura = se registra en la auditoría; destructiva = requiere `"confirm": true`.

## Índice

- [Contenido, taxonomías y SEO](#contenido-taxonomias-y-seo)
- [Layouts y elementos](#layouts-y-elementos)
- [Tema: áreas, configuración, plantillas, biblioteca, widgets y caché](#tema-areas-configuracion-plantillas-biblioteca-widgets-y-cache)
- [Menús](#menus)
- [Medios](#medios)
- [Estilos](#estilos)
- [Verificación](#verificacion)
- [Ajustes del sitio](#ajustes-del-sitio)
- [Snapshots](#snapshots)
- [Auditoría](#auditoria)

## Contenido, taxonomías y SEO

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-post-types` | lectura | — |
| `list-content` | lectura | post_type `"post" / "page"`, status `"any" / "publish" / "future" / "draft" / "pending" / "private" / "trash"`, search `string`, parent `integer`, per_page `integer`, page `integer` |
| `get-content` | lectura | **id** `integer` |
| `create-content` | escritura | post_type `"post" / "page"`, **title** `string`, content `string`, excerpt `string`, status `"publish" / "future" / "draft" / "pending" / "private"`, slug `string`, parent `integer`, menu_order `integer`, template `string`, author `integer`, date `string`, comment_status `"open" / "closed"`, terms `object`, featured_media `integer`, meta `object` |
| `update-content` | escritura | **id** `integer`, title `string`, content `string`, excerpt `string`, status `"publish" / "future" / "draft" / "pending" / "private"`, slug `string`, parent `integer`, menu_order `integer`, template `string`, author `integer`, date `string`, comment_status `"open" / "closed"`, terms `object`, featured_media `integer`, meta `object` |
| `trash-content` | escritura | **id** `integer` |
| `restore-content` | escritura | **id** `integer` |
| `delete-content` | destructiva | **id** `integer`, **confirm** `boolean` |
| `list-revisions` | lectura | **id** `integer`, per_page `integer` |
| `restore-revision` | destructiva | **revision_id** `integer`, **confirm** `boolean` |
| `get-yootheme-layout` | lectura | **id** `integer` |
| `update-yootheme-layout` | escritura | **id** `integer`, **layout** `object` |
| `list-taxonomies` | lectura | — |
| `list-terms` | lectura | **taxonomy** `string`, search `string`, parent `integer`, per_page `integer`, page `integer` |
| `create-term` | escritura | **taxonomy** `string`, **name** `string`, slug `string`, description `string`, parent `integer` |
| `update-term` | escritura | **term_id** `integer`, name `string`, slug `string`, description `string`, parent `integer` |
| `delete-term` | destructiva | **term_id** `integer`, **confirm** `boolean` |
| `get-seo` | lectura | **id** `integer` |
| `update-seo` | escritura | **id** `integer`, title `string`, description `string`, og_title `string`, og_description `string`, canonical `string`, noindex `boolean` |

## Layouts y elementos

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-elements` | lectura | group `string` |
| `get-element-schema` | lectura | **type** `string` |
| `get-layout-outline` | lectura | id `integer`, area `string` |
| `get-layout-node` | lectura | id `integer`, area `string`, **path** `string` |
| `patch-layout` | escritura | id `integer`, area `string`, **operations** `array` |
| `duplicate-content` | escritura | **id** `integer`, title `string`, slug `string` |
| `insert-from-library` | escritura | **item_id** `string`, id `integer`, area `string`, **path** `string` |

## Tema: áreas, configuración, plantillas, biblioteca, widgets y caché

| Ability | Tipo | Parámetros |
|---|---|---|
| `get-theme-layout` | lectura | area `string` |
| `update-theme-layout` | escritura | area `string`, **layout** `object` |
| `list-theme-backups` | lectura | — |
| `restore-theme-backup` | destructiva | **backup_id** `string`, **confirm** `boolean` |
| `list-widgets` | lectura | — |
| `update-widget` | escritura | **widget_id** `string`, instance `object`, layout `object`, layout_key `string`, sidebar `string` |
| `get-theme-config` | lectura | section `string` |
| `update-theme-config` | escritura | **section** `string`, **values** `string / integer / number / boolean / array / object`, replace `boolean` |
| `list-templates` | lectura | — |
| `get-template` | lectura | **id** `string` |
| `create-template` | escritura | **name** `string`, **type** `string`, status `"enabled" / "disabled"`, query `object`, params `object`, layout `object` |
| `update-template` | escritura | **id** `string`, name `string`, type `string`, status `"enabled" / "disabled"`, query `object`, params `object`, layout `object` |
| `delete-template` | destructiva | **id** `string`, **confirm** `boolean` |
| `list-library` | lectura | — |
| `get-library-item` | lectura | **id** `string` |
| `save-to-library` | escritura | id `integer`, area `string`, **path** `string`, **name** `string` |
| `clear-cache` | escritura | — |

## Menús

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-menus` | lectura | — |
| `get-menu` | lectura | **menu** `integer / string` |
| `create-menu` | escritura | **name** `string`, locations `array`, items `array` |
| `update-menu` | escritura | **menu** `integer / string`, name `string`, locations `array` |
| `delete-menu` | destructiva | **menu** `integer / string`, **confirm** `boolean` |
| `add-menu-item` | escritura | **menu** `integer / string`, parent_id `integer`, position `integer`, title `string`, **type** `string`, url `string`, object_id `integer`, target_blank `boolean`, classes `string` |
| `update-menu-item` | escritura | **item_id** `integer`, parent_id `integer`, position `integer`, title `string`, type `string`, url `string`, object_id `integer`, target_blank `boolean`, classes `string` |
| `delete-menu-item` | destructiva | **item_id** `integer`, delete_children `boolean` |

## Medios

| Ability | Tipo | Parámetros |
|---|---|---|
| `upload-media` | escritura | url `string`, data_base64 `string`, filename `string`, title `string`, alt `string`, caption `string`, description `string`, attach_to `integer` |
| `list-media` | lectura | search `string`, mime `"image" / "video" / "application/pdf"`, parent `integer`, per_page `integer`, page `integer` |
| `get-media` | lectura | **id** `integer` |
| `update-media` | escritura | **id** `integer`, alt `string`, title `string`, caption `string`, description `string` |
| `delete-media` | destructiva | **id** `integer`, **confirm** `boolean` |
| `set-featured-image` | escritura | **post_id** `integer`, **media_id** `integer` |
| `find-media-usage` | lectura | **id** `integer` |

## Estilos

| Ability | Tipo | Parámetros |
|---|---|---|
| `get-custom-css` | lectura | — |
| `update-custom-css` | escritura | **css** `string`, mode `"replace" / "append"` |
| `get-theme-style` | lectura | include_styles `boolean` |
| `list-style-variables` | lectura | component `string`, group `string`, search `string`, modified_only `boolean`, per_page `integer`, page `integer` |
| `update-theme-style` | escritura | style `string`, less `object`, custom_less `string` |
| `get-style-status` | lectura | — |
| `mark-style-compiled` | escritura | force `boolean` |

## Verificación

| Ability | Tipo | Parámetros |
|---|---|---|
| `get-preview-url` | escritura | **id** `integer`, hours `number` |
| `revoke-preview-url` | escritura | **id** `integer`, token_id `string` |
| `inspect-page` | lectura | id `integer`, path `string`, check_links `boolean`, max_links `integer` |

## Ajustes del sitio

| Ability | Tipo | Parámetros |
|---|---|---|
| `get-site-settings` | lectura | — |
| `update-site-settings` | escritura | blogname `string`, blogdescription `string`, show_on_front `"posts" / "page"`, page_on_front `integer`, page_for_posts `integer`, site_icon `integer` |

## Snapshots

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-snapshots` | lectura | kind `string`, ref `string`, per_page `integer`, page `integer` |
| `restore-snapshot` | destructiva | **id** `integer`, **confirm** `boolean` |

## Auditoría

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-activity` | lectura | ability `string`, object_type `string`, object_id `string`, user_id `integer`, result `string`, since `string`, until `string`, per_page `integer`, page `integer` |

