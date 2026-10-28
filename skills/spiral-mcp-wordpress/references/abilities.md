# Referencia de abilities spiral-mcp/*

Generada a partir de los esquemas registrados por el plugin (v1.32.1). Cada ability trae además su descripción completa en la lista de herramientas MCP: léela allí para los detalles. Aquí está la vista de conjunto: método implícito, parámetros obligatorios (**negrita**) y opcionales.

Leyenda: lectura = no cambia nada; escritura = se registra en la auditoría; destructiva = requiere `"confirm": true`.

Las abilities de YOOtheme (layouts, `get-yootheme-layout`, áreas del tema, configuración, plantillas, biblioteca, librería online y estilo) solo existen si YOOtheme Pro es el tema activo. En un sitio con Elementor el diseño va por las herramientas `elementor/*` de su MCP, que no están en esta referencia: lee su descripción en la lista de herramientas (ver «Sitios con Elementor» en `SKILL.md`).

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
- [Plugins](#plugins)
- [Formularios (Contact Form 7)](#formularios-contact-form-7)
- [Tienda (WooCommerce)](#tienda-woocommerce)
- [Pedidos y clientes (WooCommerce)](#pedidos-y-clientes-woocommerce)
- [Idiomas (Polylang)](#idiomas-polylang)
- [Auditoría](#auditoria)

## Contenido, taxonomías y SEO

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-post-types` | lectura | — |
| `list-content` | lectura | post_type `"any" / "post" / "page" / "product"`, status `"any" / "publish" / "future" / "draft" / "pending" / "private" / "trash"`, search `string`, parent `integer`, per_page `integer`, page `integer`, language `string` |
| `get-content` | lectura | **id** `integer` |
| `create-content` | escritura | post_type `"post" / "page" / "product"`, **title** `string`, content `string`, excerpt `string`, status `"publish" / "future" / "draft" / "pending" / "private"`, slug `string`, parent `integer`, menu_order `integer`, template `string`, author `integer`, date `string`, comment_status `"open" / "closed"`, terms `object`, featured_media `integer`, meta `object`, language `string`, translation_of `integer` |
| `update-content` | escritura | **id** `integer`, title `string`, content `string`, excerpt `string`, status `"publish" / "future" / "draft" / "pending" / "private"`, slug `string`, parent `integer`, menu_order `integer`, template `string`, author `integer`, date `string`, comment_status `"open" / "closed"`, terms `object`, featured_media `integer`, meta `object` |
| `trash-content` | escritura | **id** `integer` |
| `restore-content` | escritura | **id** `integer` |
| `delete-content` | destructiva | **id** `integer`, **confirm** `boolean` |
| `list-revisions` | lectura | **id** `integer`, per_page `integer` |
| `restore-revision` | destructiva | **revision_id** `integer`, **confirm** `boolean` |
| `get-yootheme-layout` | lectura | **id** `integer` |
| `update-yootheme-layout` | escritura | **id** `integer`, **layout** `object` |
| `list-taxonomies` | lectura | — |
| `list-terms` | lectura | **taxonomy** `string`, search `string`, parent `integer`, per_page `integer`, page `integer`, language `string` |
| `create-term` | escritura | **taxonomy** `string`, **name** `string`, slug `string`, description `string`, parent `integer`, language `string`, translation_of `integer` |
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
| `get-layout-texts` | lectura | id `integer`, area `string`, path `string`, kinds `array` |
| `get-layout-node` | lectura | id `integer`, area `string`, **path** `string` |
| `patch-layout` | escritura | id `integer`, area `string`, **operations** `array` |
| `duplicate-content` | escritura | **id** `integer`, title `string`, slug `string`, language `string` |
| `insert-from-library` | escritura | **item_id** `string`, id `integer`, area `string`, **path** `string` |

## Tema: áreas, configuración, plantillas, biblioteca, widgets y caché

| Ability | Tipo | Parámetros |
|---|---|---|
| `get-theme-layout` | lectura | area `string` |
| `update-theme-layout` | escritura | area `string`, **layout** `object` |
| `list-theme-backups` | lectura | — |
| `restore-theme-backup` | destructiva | **backup_id** `string`, **confirm** `boolean` |
| `list-widgets` | lectura | — |
| `update-widget` | escritura | **widget_id** `string`, instance `object`, layout `object`, layout_key `string`, sidebar `string`, position `integer` |
| `create-widget` | escritura | **type** `string`, **sidebar** `string`, instance `object`, layout `object`, position `integer` |
| `delete-widget` | destructiva | **widget_id** `string`, **confirm** `boolean` |
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
| `list-online-library` | lectura | kind `"layout" / "element"`, group `string`, type `string`, website `string`, search `string`, per_page `integer`, page `integer`, refresh `boolean` |
| `get-online-library-item` | lectura | **id** `string`, kind `"layout" / "element"`, include_tree `boolean` |
| `import-from-online-library` | escritura | **id** `string`, kind `"layout" / "element"`, **target** `"template" / "library" / "insert"`, name `string`, template_type `string`, status `"enabled" / "disabled"`, post_id `integer`, area `string`, path `string` |
| `get-cache-status` | lectura | include_stats `boolean` |
| `purge-cache` | escritura | scope `"all" / "post" / "url"`, post_id `integer`, url `string`, provider `string`, object_cache `boolean` |
| `clear-cache` | escritura | — |
| `preload-cache` | escritura | action `"start" / "stop" / "status"`, provider `string` |

## Menús

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-menus` | lectura | — |
| `get-menu` | lectura | **menu** `integer / string` |
| `create-menu` | escritura | **name** `string`, locations `array`, items `array`, language `string` |
| `update-menu` | escritura | **menu** `integer / string`, name `string`, locations `array`, language `string` |
| `delete-menu` | destructiva | **menu** `integer / string`, **confirm** `boolean` |
| `add-menu-item` | escritura | **menu** `integer / string`, parent_id `integer`, position `integer`, title `string`, **type** `string`, url `string`, object_id `integer`, target_blank `boolean`, classes `string` |
| `update-menu-item` | escritura | **item_id** `integer`, parent_id `integer`, position `integer`, title `string`, type `string`, url `string`, object_id `integer`, target_blank `boolean`, classes `string` |
| `delete-menu-item` | destructiva | **item_id** `integer`, delete_children `boolean` |

## Medios

| Ability | Tipo | Parámetros |
|---|---|---|
| `upload-media` | escritura | url `string`, data_base64 `string`, filename `string`, title `string`, alt `string`, caption `string`, description `string`, attach_to `integer` |
| `create-upload-url` | escritura | filename `string`, title `string`, alt `string`, caption `string`, description `string`, attach_to `integer`, expires_in `integer` |
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
| `get-cache-settings` | lectura | provider `string` |
| `update-cache-settings` | escritura | provider `string`, settings `object`, provider_settings `object` |
| `get-seo-settings` | lectura | section `"general" / "site_representation" / "content_types" / "taxonomies" / "archives" / "breadcrumbs" / "rss" / "social" / "verification" / "features" / "crawl"` |
| `update-seo-settings` | escritura | **settings** `object` |
| `get-site-settings` | lectura | — |
| `update-site-settings` | escritura | blogname `string`, blogdescription `string`, show_on_front `"posts" / "page"`, page_on_front `integer`, page_for_posts `integer`, site_icon `integer`, timezone_string `string` |

## Snapshots

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-snapshots` | lectura | kind `string`, ref `string`, per_page `integer`, page `integer` |
| `restore-snapshot` | destructiva | **id** `integer`, **confirm** `boolean`, parts `array`, include_later `boolean` |

## Plugins

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-plugins` | lectura | status `"all" / "active" / "inactive" / "update"`, search `string` |
| `search-plugins` | lectura | search `string`, slug `string`, per_page `integer`, page `integer` |
| `install-plugin` | escritura | **slug** `string` |
| `activate-plugin` | escritura | **plugin** `string` |
| `deactivate-plugin` | escritura | **plugin** `string` |
| `update-plugin` | destructiva | **plugin** `string`, **confirm** `boolean` |
| `delete-plugin` | destructiva | **plugin** `string`, **confirm** `boolean` |

## Formularios (Contact Form 7)

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-forms` | lectura | search `string` |
| `get-form` | lectura | **id** `string / integer` |
| `create-form` | escritura | **title** `string`, template `string`, mail `object`, mail_2 `object`, messages `object`, additional_settings `string`, confirm `boolean`, copy_from `string / integer`, locale `string` |
| `update-form` | escritura | **id** `string / integer`, title `string`, template `string`, mail `object`, mail_2 `object`, messages `object`, additional_settings `string`, confirm `boolean` |
| `delete-form` | destructiva | **id** `string / integer`, **confirm** `boolean` |

## Tienda (WooCommerce)

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-products` | lectura | search `string`, status `"any" / "draft" / "pending" / "private" / "publish" / "trash"`, type `"simple" / "variable" / "grouped" / "external"`, category `integer`, tag `integer`, sku `string`, stock_status `"instock" / "outofstock" / "onbackorder"`, on_sale `boolean`, featured `boolean`, orderby `"date" / "modified" / "title" / "price" / "popularity" / "rating" / "menu_order" / "id"`, order `"asc" / "desc"`, page `integer`, per_page `integer` |
| `get-product` | lectura | **id** `integer` |
| `create-product` | escritura | **fields** `object` |
| `update-product` | escritura | **id** `integer`, **fields** `object` |
| `delete-product` | destructiva | **id** `integer`, force `boolean`, **confirm** `boolean` |
| `update-variations` | escritura | **product_id** `integer`, create `array`, update `array`, delete `array`, confirm `boolean` |
| `list-product-attributes` | lectura | — |
| `create-product-attribute` | escritura | **name** `string`, slug `string`, order_by `"menu_order" / "name" / "name_num" / "id"`, has_archives `boolean`, terms `array` |
| `update-product-attribute` | escritura | **id** `integer`, name `string`, slug `string`, order_by `"menu_order" / "name" / "name_num" / "id"`, has_archives `boolean`, terms `array` |
| `delete-product-attribute` | destructiva | **id** `integer`, **confirm** `boolean` |
| `list-coupons` | lectura | search `string`, page `integer`, per_page `integer` |
| `get-coupon` | lectura | **id** `integer` |
| `create-coupon` | escritura | **fields** `object` |
| `update-coupon` | escritura | **id** `integer`, **fields** `object` |
| `delete-coupon` | destructiva | **id** `integer`, force `boolean`, **confirm** `boolean` |
| `get-shop-settings` | lectura | group `string` |
| `update-shop-settings` | escritura | **group** `string`, **values** `object`, confirm `boolean` |
| `list-shipping-zones` | lectura | — |
| `save-shipping-zone` | escritura | id `integer`, name `string`, order `integer`, locations `array` |
| `delete-shipping-zone` | destructiva | **id** `integer`, **confirm** `boolean` |
| `save-shipping-method` | escritura | **zone_id** `integer`, instance_id `integer`, method_id `string`, enabled `boolean`, order `integer`, settings `object` |
| `delete-shipping-method` | destructiva | **zone_id** `integer`, **instance_id** `integer`, **confirm** `boolean` |
| `list-tax-rates` | lectura | class `string` |
| `update-tax-rates` | escritura | create `array`, update `array`, delete `array`, confirm `boolean` |
| `list-payment-gateways` | lectura | — |

## Pedidos y clientes (WooCommerce)

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-orders` | lectura | status `"any" / "pending" / "processing" / "on-hold" / "completed" / "cancelled" / "refunded" / "failed" / "checkout-draft"`, customer `integer`, search `string`, after `string`, before `string`, page `integer`, per_page `integer` |
| `get-order` | lectura | **id** `integer` |
| `update-order` | escritura | **id** `integer`, status `"pending" / "processing" / "on-hold" / "completed" / "cancelled" / "refunded" / "failed" / "checkout-draft"`, customer_note `string`, billing `object`, shipping `object`, confirm `boolean` |
| `add-order-note` | escritura | **id** `integer`, **note** `string`, customer_note `boolean`, confirm `boolean` |
| `create-refund` | destructiva | **order_id** `integer`, amount `string`, reason `string`, line_items `array`, api_refund `boolean`, restock `boolean`, **confirm** `boolean` |
| `list-customers` | lectura | search `string`, page `integer`, per_page `integer` |
| `get-customer` | lectura | **id** `integer` |

## Idiomas (Polylang)

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-languages` | lectura | — |
| `create-language` | escritura | name `string`, slug `string`, **locale** `string`, rtl `boolean`, order `integer`, flag `string` |
| `update-language` | escritura | **language** `string`, name `string`, slug `string`, locale `string`, rtl `boolean`, order `integer`, flag `string`, confirm `boolean` |
| `delete-language` | destructiva | **language** `string`, **confirm** `boolean` |
| `update-language-settings` | escritura | **settings** `object`, confirm `boolean` |
| `assign-missing-languages` | escritura | language `string` |
| `get-translations` | lectura | **type** `"post" / "term"`, **id** `integer` |
| `create-translation` | escritura | **type** `"post" / "term"`, **id** `integer`, **language** `string`, title `string`, slug `string`, description `string` |
| `update-translations` | escritura | **type** `"post" / "term"`, **id** `integer`, language `string`, translations `object` |
| `list-string-translations` | lectura | language `string`, context `string`, search `string`, untranslated `boolean` |
| `update-string-translations` | escritura | **language** `string`, **strings** `array` |
| `set-menu-locations` | escritura | **language** `string`, **locations** `object` |
| `add-language-switcher` | escritura | **menu** `integer / string`, parent_id `integer`, layout `"horizontal" / "dropdown"`, show_labels `"names" / "codes" / ""`, show_flags `boolean`, hide_current `boolean`, hide_if_no_translation `boolean`, force_home `boolean` |

## Auditoría

| Ability | Tipo | Parámetros |
|---|---|---|
| `list-activity` | lectura | ability `string`, object_type `string`, object_id `string`, user_id `integer`, result `string`, since `string`, until `string`, per_page `integer`, page `integer` |

