# Spiral-MCP-Control — contexto para Claude Code

Plugin de WordPress que registra *abilities* (Abilities API de WordPress 6.9+) y las expone a agentes de IA a través del plugin **MCP Adapter**. Objetivo: que un agente (Claude) pueda controlar por completo un sitio WordPress con **YOOtheme Pro**: contenido, diseño, menús, medios, estilos y ajustes, sin entrar al wp-admin.

El plan de trabajo está en [`docs/ROADMAP.md`](docs/ROADMAP.md). Léelo antes de empezar cualquier tarea.

## Requisitos
- WordPress 6.9+ (Abilities API: `wp_register_ability`, `wp_register_ability_category`).
- Plugin **MCP Adapter** activo (es el que publica las abilities como herramientas MCP).
- YOOtheme Pro activo (o un tema hijo de YOOtheme) para todo lo relacionado con el builder y los estilos.
- PHP 7.4 como mínimo: no usar sintaxis de PHP 8 (match, nullsafe `?->`, argumentos con nombre, tipos de unión, `readonly`, enums).

## Estructura actual (v1.3.0)
```
spiral-mcp-control.php   cabecera del plugin, helpers de contenido y abilities de páginas/entradas y layouts de página
includes/menus.php       abilities de menús y elementos de menú
includes/theme.php       footer de YOOtheme (builder), copias de la configuración del tema y widgets
dist/                    ZIP para instalar (no versionar)
```

Abilities existentes (todas con prefijo `spiral-mcp/`):
- Contenido: `list-content`, `get-content`, `create-content`, `update-content`, `get-yootheme-layout`, `update-yootheme-layout`.
- Menús: `list-menus`, `get-menu`, `create-menu`, `update-menu`, `delete-menu`, `add-menu-item`, `update-menu-item`, `delete-menu-item`.
- Tema: `get-theme-layout`, `update-theme-layout`, `list-theme-backups`, `restore-theme-backup`, `list-widgets`, `update-widget`.

## Convenciones (obligatorias)
- **Nombres**: abilities `spiral-mcp/<verbo>-<objeto>` en kebab-case; funciones PHP `spiral_mcp_*`; constantes `SPIRAL_MCP_*`; códigos de `WP_Error` `spiral_mcp_*`; filtros y acciones `spiral_mcp_*`; categorías `spiral-mcp-<módulo>`.
- **Un módulo por archivo** en `includes/`, cada uno con su categoría y sus abilities, registrado desde el archivo principal con `require_once`.
- **Patrón de registro**: copiar el de `includes/menus.php` (closures `$can` y `$meta( $readonly, $destructive, $idempotent )`, `input_schema` con `required`, `'meta' => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => … ]`). Desde la Fase 0, registrar siempre con el wrapper `spiral_mcp_register_ability()` (ver ROADMAP), que añade la auditoría.
- **Descripciones en español, escritas para un agente**: qué hace, qué leer antes ("primero lee con X"), qué devuelve y qué es irreversible. Las descripciones son la documentación que verá el agente.
- **Permisos**: cada ability comprueba una capacidad concreta en `permission_callback`. Nunca `__return_true`.
- **Acciones destructivas** (borrar, restaurar, reemplazar algo sin revisión): exigir `"confirm": true` y marcar `destructive => true`.
- **Antes de escribir en opciones o theme mods, guardar una copia** (snapshot). Los posts ya tienen revisiones; lo demás no.
- **Escritura de posts**: siempre con `wp_slash()` antes de `wp_insert_post` / `wp_update_post` (si no, se rompen las `\` del JSON de YOOtheme).
- **JSON**: `wp_json_encode( $x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )`.
- **Errores**: devolver `WP_Error` con mensaje en español que diga cómo corregir la llamada. No lanzar excepciones hacia fuera.
- **Enlaces internos**: rutas relativas (`/contacto/`), nunca el dominio completo. Aplica a ejemplos, descripciones y valores por defecto.
- **Sin dependencias externas** (ni Composer en runtime): el plugin debe instalarse subiendo un ZIP.

## Lo que ya sabemos de YOOtheme (comprobado en producción)
- **Layout de una página**: JSON al final de `post_content`, dentro del último comentario `<!-- {…} -->`. Raíz `{"type":"layout","children":[section > row > column > elementos]}`. Lo que va antes del comentario es el HTML de respaldo. Ver `spiral_mcp_split_layout()`.
- **Configuración del tema**: theme mod `config` (JSON en texto). Claves de primer nivel vistas: `menu, site, header, navbar, dialog, mobile, top, main_sidebar, bottom, footer, webp, consent, less, custom_less, style, post, blog, minimum_stability, lazyload, version, logo, favicon, touchicon, yootheme_apikey, jquery, site_url, favicon_svg, search_filter, scripts, image_urls`.
- **Footer**: `config.footer.content` es un layout con el mismo formato que una página.
- **Widgets de builder** (`builderwidget-N`): guardan su layout como JSON en texto en la clave `content` de la instancia (raíz `"type":"fragment"`).
- **Fuentes dinámicas**: un elemento puede tener `"source": {"query": {"name": "customMenuItems", "arguments": {...}}, "props": {...}}`. Hay que conservarlas al editar.
- **Rutas de imagen** en props: relativas a la raíz, p. ej. `wp-content/uploads/2024/11/logo.webp`.
- **Los estilos (LESS) se compilan en el navegador**, en el Personalizador. Cambiar `config.less` o `config.custom_less` en la BD no cambia el CSS servido hasta que alguien abre Personalizar y guarda.
- **El CSS por elemento** (prop `css` de una sección o elemento) sí se procesa en el servidor al renderizar.
- `config.yootheme_apikey` es un secreto: no devolverlo nunca en una respuesta (enmascararlo).

## Entorno de desarrollo
- WordPress local de pruebas con YOOtheme Pro, MCP Adapter y este plugin enlazado (symlink o copia de la carpeta en `wp-content/plugins/spiral-mcp-control`).
- Nunca probar contra un sitio en producción.
- Comprobación mínima antes de cada commit: `php -l` en todos los archivos PHP. Desde la Fase 0 también el script de humo `tests/smoke.sh` (ver ROADMAP).
- Las abilities se pueden ejecutar sin cliente MCP vía REST de la Abilities API (verificar la ruta exacta en el entorno local; en WP 6.9 es `/wp-json/wp-abilities/v1/...`) con una Application Password.

## Versionado y entrega
- SemVer en la cabecera del plugin (`Version:`) y en `SPIRAL_MCP_VERSION`.
- Cada versión anota sus cambios en `CHANGELOG.md`.
- ZIP: carpeta raíz `spiral-mcp-control/`, sin `.git`, `dist/`, `tests/` ni `docs/`.
- Al terminar una fase: actualizar las casillas de `docs/ROADMAP.md`.
