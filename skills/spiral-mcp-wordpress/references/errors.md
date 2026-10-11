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
