# Changelog

Todos los cambios relevantes de Spiral-MCP-Control. Versionado [SemVer](https://semver.org/lang/es/).

## [1.5.0] — 2026-10-11 · Fase 2: medios

### Añadido
- Módulo `includes/media.php` (grupo de permisos `media`).
- `spiral-mcp/upload-media`: sube desde una URL `https` pública o desde base64 (+ `filename`), con `title`, `alt`, `caption`, `description` y `attach_to`. Tipos: jpg, jpeg, png, webp, gif, avif, pdf, mp4, webm (filtro `spiral_mcp_upload_types`). SVG solo con el filtro `spiral_mcp_allow_svg`. Límite `wp_max_upload_size()`. Protección SSRF: solo https al puerto 443, sin credenciales; rechaza IPs privadas, reservadas y de loopback (también en redirecciones, vía `wp_safe_remote_get`). Valida el tipo real del archivo.
- `spiral-mcp/list-media`, `get-media`: salida con `id`, `url`, `path_relative` (formato de los props de imagen de YOOtheme), `width`, `height`, `mime`, `alt`, textos y `sizes`.
- `spiral-mcp/update-media`: alt, title, caption y description, con snapshot `media_meta`.
- `spiral-mcp/delete-media` (con `confirm`): papelera si `MEDIA_TRASH`; si no, borrado definitivo (la descripción lo indica según el sitio).
- `spiral-mcp/set-featured-image` (`media_id` 0 = quitar), con snapshot `post_meta`.
- `spiral-mcp/find-media-usage`: busca el adjunto en contenido y layouts de posts, imagen destacada, configuración del tema, widgets, logo e icono del sitio.
- Tipos de snapshot `media_meta` y `post_meta`.
- Pruebas de medios en `tests/smoke.sh` (variable `SMOKE_MEDIA_URL`).

## [1.4.0] — 2026-10-11 · Fase 0: base segura

### Añadido
- **Registro de auditoría**: tabla `{prefix}spiral_mcp_log`. Cada ejecución de una ability que escribe guarda usuario, ability, objeto, entrada (redactada y truncada a 8 KB), resultado, snapshot y duración. Las lecturas no se registran.
- Ability `spiral-mcp/list-activity` (filtros por ability, objeto, usuario, resultado y fechas; paginada).
- **Snapshots genéricos**: tabla `{prefix}spiral_mcp_snapshots`. Antes de cada cambio en la configuración de YOOtheme, un menú (árbol completo y ubicaciones) o un widget (su opción y `sidebars_widgets`) se guarda una copia.
- Abilities `spiral-mcp/list-snapshots` y `spiral-mcp/restore-snapshot` (con `confirm`). Restaurar guarda antes el estado actual, así que también se puede deshacer.
- Las abilities de menús, `update-theme-layout` y `update-widget` devuelven `snapshot_id`.
- **Permisos**: capacidades `spiral_mcp_content`, `spiral_mcp_design`, `spiral_mcp_media`, `spiral_mcp_settings` y `spiral_mcp_code`; rol `spiral_mcp_agent` (sin `install_plugins`, `edit_plugins`, `manage_options`, `promote_users` ni `delete_users`). Los administradores reciben todas las capacidades propias.
- Limpieza diaria por WP-Cron: registro y snapshots de más de 90 días (filtros `spiral_mcp_log_retention_days` y `spiral_mcp_snapshot_retention_days`).
- `uninstall.php`: elimina el rol y las capacidades; las tablas solo si `spiral_mcp_uninstall_drop_tables` devuelve true.
- `tests/smoke.sh` y `tests/fixtures/` (página simple, página con fuentes dinámicas y footer).

### Cambiado
- Reorganización: el contenido pasa a `includes/content.php`; helpers en `includes/core.php`; nuevos `permissions.php`, `activity.php`, `snapshots.php` e `install.php`. El archivo principal solo tiene cabecera, constantes, requisitos y `require_once`.
- Todas las abilities se registran con `spiral_mcp_register_ability()`, que exige la capacidad `spiral_mcp_*` del grupo además de la nativa, y audita las escrituras. Nombres y esquemas de entrada sin cambios.
- Las abilities sin campos obligatorios aceptan llamadas sin `input` (antes fallaban con `ability_invalid_input`).
- `update-widget` valida el área destino antes de escribir (antes podía guardar la instancia y luego fallar).
- Las copias de la configuración de YOOtheme (opción de la 1.3.0) se migran a la tabla de snapshots y se borra la opción.

### Obsoleto
- `spiral-mcp/list-theme-backups` y `spiral-mcp/restore-theme-backup`: siguen funcionando como alias de `list-snapshots` / `restore-snapshot` (aceptan también los IDs antiguos). Se eliminarán en la próxima versión.

## [1.3.0]

- Abilities de contenido y layouts de YOOtheme, menús, footer de YOOtheme (builder), copias de la configuración del tema y widgets.
