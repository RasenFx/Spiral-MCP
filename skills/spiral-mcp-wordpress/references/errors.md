# Errores frecuentes

| Síntoma o código | Causa | Qué hacer |
|---|---|---|
| `spiral_mcp_invalid_layout` | Tipo inexistente o anidado imposible (un headline dentro de una section, una row en la raíz, un item fuera de su padre) | Revisa la ruta con `get-layout-outline` y los tipos con `list-elements`. Los elementos van dentro de columnas. |
| `spiral_mcp_test_failed` | El layout cambió desde que lo leíste | Vuelve a leer el nodo (`get-layout-node`) y repite el patch con el valor actual. |
| `spiral_mcp_path_not_found` | Ruta mal escrita, o los índices cambiaron por un `add`, `remove` o `move` anterior en la misma petición | Las operaciones se aplican en orden: calcula cada ruta sobre el resultado de las anteriores. Para añadir al final usa `-`. |
| Prop desconocido en `warnings` | Nombre de prop incorrecto para ese tipo | `get-element-schema`. Los anchos de columna van en cada columna (son `child_props` de la fila). |
| Valor "no está entre sus opciones" en `warnings` | Valor no válido para un select | Usa uno de los `options` de `get-element-schema`. |
| El cambio de estilo no se ve | Falta recompilar el CSS | `get-style-status` → abre `recompile_url` en un navegador con sesión. |
| El cambio en un nodo con fuente dinámica no se ve | La fuente (`source`) sobrescribe el prop | Cambia el dato en su origen (título del post, menú…) o la propia `source`. |
| Una página no usa la plantilla | La página tiene layout propio, o hay otra plantilla antes que también encaja | Las plantillas solo se aplican sin layout propio y por orden de prioridad (`list-templates`). |
| `spiral_mcp_bad_url` al subir una imagen | URL no https, privada o local | Usa una URL https pública, o `data_base64` + `filename`. |
| `spiral_mcp_bad_type` al subir | Tipo no permitido (SVG, PHP…) o el contenido no coincide con la extensión | Convierte a jpg, png, webp o avif. |
| `spiral_mcp_bad_terms` | El tipo de contenido no admite esa taxonomía, o el término no existe | `list-post-types` (taxonomías por tipo) y `create-term`. |
| `spiral_mcp_not_trashed` | Se intentó borrar definitivamente algo que no está en la papelera | `trash-content` primero. |
| `spiral_mcp_section_locked` | Sección de configuración no editable (`yootheme_apikey`, `version`…) o `footer.content` | El footer se edita con `update-theme-layout` o `patch-layout` y `"area": "footer"`. |
| `rest_ability_cannot_execute` / `ability_invalid_permissions` | Al usuario del agente le falta un permiso | Avisa a la persona responsable del sitio; no intentes rodearlo. |
| `ability_invalid_input` | Falta un parámetro obligatorio o tiene otro tipo | Revisa `references/abilities.md` (los obligatorios van en negrita). |
| `inspect-page` no carga la página | El servidor no se alcanza a sí mismo por su dominio | Pide que se configure el filtro `spiral_mcp_inspect_base_url`. Mientras tanto, revisa con la URL de `get-preview-url` en tu navegador. |
| `rest_ability_cannot_execute` en `update-cache-settings` | Falta el permiso de caché (`spiral_mcp_cache`), que el agente no tiene por defecto | Explica al usuario el cambio que harías; que lo haga en el panel de su plugin de caché o te conceda el permiso. |
| `spiral_mcp_online_disabled` | El administrador ha desactivado las descargas de la librería online (Ajustes > Spiral-MCP > Comportamiento) | Puedes enseñar el catálogo (`list-online-library`), pero no importar: díselo al usuario. |
| `spiral_mcp_plugins_disabled` / `spiral_mcp_plugins_capability` (403) | Falta un cerrojo de la gestión de plugins: el ajuste del administrador o la capacidad `spiral_mcp_plugins` | Explica al usuario cuál falta (lo dice el mensaje); no lo intentes por otra vía. |
| `spiral_mcp_plugin_fatal` / `spiral_mcp_plugin_broke_site` | El plugin da un error fatal o rompe la web al activarse | No ha quedado activo y el sitio funciona. Díselo al usuario y busca otra opción. |
| `spiral_mcp_plugin_protected` | Se intentó desactivar, actualizar o borrar Spiral-MCP-Control o MCP Adapter | No se puede desde aquí: sin ellos perderías el acceso. |
| `spiral_mcp_plugin_update_rolled_back` | La nueva versión daba error 500 en la portada o wp-admin | Ya se ha vuelto a la versión anterior. Díselo al usuario; no lo reintentes. |
| `spiral_mcp_plugin_no_update` / `spiral_mcp_plugin_source` | No hay actualización, o viene de fuera de wordpress.org (plugin de pago) | Si es de pago, que lo actualice el usuario desde wp-admin. |
| `spiral_mcp_plugin_active` (en `delete-plugin`) | Solo se borran plugins desactivados | Desactívalo antes con `deactivate-plugin` (con permiso del usuario). |
| `spiral_mcp_snapshot_later` | Después de ese snapshot hubo otros cambios en el mismo objeto, y restaurarlo los desharía | Restaura solo la parte que toca (`"parts"`, en la configuración del tema) o revierte el valor con su herramienta. `"include_later": true` solo si el usuario quiere volver al estado completo. |
| Enlace roto con `target_status` en `inspect-page` | El destino existe pero no está publicado (borrador, programado…) | Publica el destino cuando el usuario lo apruebe, o cambia el enlace. La ruta está bien. |
| `warnings` de `get-menu` / `create-menu` sobre elementos en borrador | El menú enlaza a contenido sin publicar | Igual que arriba: el elemento se ve en el menú, pero da 404 hasta publicar. |
| Avisos de encabezados que no son de la página | Encabezados del tema (widgets, comentarios) | Los que tienen `"in_content": false` no cuentan para los avisos y no se arreglan en la página. |
