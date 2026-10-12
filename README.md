# Spiral-MCP-Control

Plugin de WordPress que permite a un agente de IA (por ejemplo, Claude) gestionar un sitio con **YOOtheme Pro** sin entrar al wp-admin. Registra *abilities* de la [Abilities API](https://developer.wordpress.org/) de WordPress y el plugin **MCP Adapter** las publica como herramientas MCP. Con ellas el agente puede gestionar contenido, layouts del builder, plantillas, menús, widgets, medios, estilos, SEO, ajustes y cachés.

Cada cambio queda en un registro de auditoría y, salvo en los posts (que tienen revisiones), se guarda antes un *snapshot* para poder deshacerlo.

## Requisitos

- WordPress 6.9 o superior (Abilities API).
- PHP 7.4 o superior.
- Plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter) activo.
- YOOtheme Pro (o un tema hijo) para todo lo relacionado con el builder y los estilos. Las abilities de contenido, menús, medios, SEO y cachés funcionan con cualquier tema.

## Instalación

1. Descarga el ZIP de la versión (o genéralo: ver [Desarrollo](#desarrollo)) y súbelo en *Plugins > Añadir nuevo > Subir plugin*.
2. Actívalo. Se crean las tablas de auditoría y snapshots, las capacidades `spiral_mcp_*` (los administradores las reciben todas) y el rol **Agente Spiral-MCP** (`spiral_mcp_agent`).
3. Crea un usuario con ese rol, genera una *Application Password* para él y conecta tu cliente MCP al MCP Adapter con esas credenciales.
4. Para el agente, instala el skill de Claude `skills/spiral-mcp-wordpress/` (método de trabajo, recetas y errores frecuentes).

## Qué puede hacer el agente

Las abilities llevan el prefijo `spiral-mcp/`. La referencia completa de parámetros está en [`skills/spiral-mcp-wordpress/references/abilities.md`](skills/spiral-mcp-wordpress/references/abilities.md).

| Área | Abilities |
|---|---|
| Contenido | listar, leer, crear y editar páginas, entradas y tipos propios; papelera; revisiones; taxonomías y términos; SEO (Yoast o salida propia) |
| Layouts de YOOtheme | esquema de un layout, textos, nodo, `patch-layout` (operaciones JSON Patch atómicas), duplicar, catálogo de elementos y validación |
| Tema | configuración de YOOtheme (header, logo, navbar…), footer y mega menús, widgets (también de builder), plantillas, biblioteca y [librería online de YOOtheme](https://yootheme.com) |
| Medios | subir (URL o base64), listar, editar, borrar, imagen destacada, dónde se usa un medio |
| Estilos | CSS adicional; estilo, variables LESS y estado de compilación de YOOtheme |
| Verificación | URL de vista previa sin sesión, inspección de una página (encabezados, enlaces rotos, alt, SEO efectivo) |
| Ajustes | identidad del sitio, portada, zona horaria |
| Plugins | listar los instalados y buscar en wordpress.org (instalar, activar, actualizar y borrar: en desarrollo, con doble cerrojo) |
| Cachés | estado, purga, precarga y ajustes de la herramienta de caché del sitio (ver abajo) |
| Auditoría | registro de actividad, snapshots y restauración (también parcial) |

## Permisos

| Capacidad | Para qué | Rol del agente |
|---|---|---|
| `spiral_mcp_content` | contenido, taxonomías, SEO | sí |
| `spiral_mcp_design` | builder, tema, menús, widgets, estilos | sí |
| `spiral_mcp_media` | medios | sí |
| `spiral_mcp_settings` | ajustes del sitio | sí |
| `spiral_mcp_code` | JS personalizado (sección `scripts` de YOOtheme) | sí |
| `spiral_mcp_cache` | **cambiar** los ajustes de caché | **no** |
| `spiral_mcp_plugins` | gestionar plugins de wordpress.org (además hay que activar "Permitir que el agente gestione plugins" en *Comportamiento*) | **no** |

Las capacidades del rol del agente se pueden cambiar en **Ajustes > Spiral-MCP > Permisos del agente**, y la elección se mantiene al actualizar el plugin. En la pestaña **Abilities** de esa página se activa o desactiva cada herramienta o una categoría entera (con atajos "Solo lectura" y "Desactivar las destructivas"). Una ability desactivada no se publica en el MCP: el agente ni la ve. La pestaña **Comportamiento** decide si se purga la caché tras cada cambio, cuántos días se conservan el registro y los snapshots, si el agente puede descargar diseños de la librería online de YOOtheme y si al desinstalar se borran las tablas. La pestaña **Actividad** muestra los cambios del agente y permite restaurar cada uno desde wp-admin.

Además, cada ability comprueba la capacidad nativa de WordPress del objeto (`edit_post` de ese post, `edit_theme_options`…). El rol del agente nunca recibe las capacidades nativas de administración (`manage_options`, las de plugins, temas, usuarios y actualizaciones del núcleo, `edit_files`…), ni siquiera con un filtro: las abilities de plugins usan las APIs de WordPress con su propia comprobación. Las acciones destructivas exigen `"confirm": true`.

## Cachés compatibles

El agente usa siempre las mismas abilities (`get-cache-status`, `purge-cache`, `preload-cache`, `get-cache-settings`, `update-cache-settings`). Por dentro, un adaptador por herramienta traduce cada operación. Si el sitio tiene varias a la vez (un plugin más la caché del hosting), la purga llega a todas. Después de cada escritura el plugin purga solo lo que ha cambiado: si el cambio es de un contenido, ese contenido, la portada y sus listados; si es de diseño global, todo.

| Herramienta | Purga | Precarga | Leer ajustes | Cambiar ajustes | Probado |
|---|---|---|---|---|---|
| WP Super Cache | todo / contenido / URL | sí | sí | sí (no el modo Expert) | sí, 3.1.4 |
| WP Fastest Cache | todo / contenido (una URL, si es de un contenido) | — | sí | sí (sin tocar `.htaccess`) | sí, 1.5.3 |
| LiteSpeed Cache | todo / contenido / URL | — | sí | sí | sí, 7.9.1 (fuera de un servidor LiteSpeed no cachea páginas; el estado lo avisa) |
| W3 Total Cache | todo / contenido / URL | — | sí | solo lectura | sí, 2.10.7 |
| SiteGround Optimizer | todo / contenido / URL | — | — | — | API comprobada, 7.8.4 (el efecto solo existe en el hosting SiteGround) |
| Nginx Helper | todo / contenido / URL | — | — | — | API comprobada, 2.4.1 (el efecto solo existe con Nginx) |
| WP Rocket | todo / contenido / URL | — | sí | sí | sin probar (de pago) |
| WP Engine, Pantheon, Kinsta | según su API | — | — | — | sin probar (solo en su plataforma) |
| Caché de objetos (Redis, Memcached…) | todo, si se pide | — | — | — | sí |

Para añadir otra herramienta sin tocar el plugin, registra un adaptador con el filtro `spiral_mcp_cache_providers` (el formato está en la cabecera de `includes/cache.php`). Los modos que reescriben `.htaccess` o la configuración del servidor no se activan desde aquí.

## Desarrollo

- Plan de trabajo e historial de decisiones: [`docs/ROADMAP.md`](docs/ROADMAP.md). Convenciones y lo aprendido de YOOtheme: [`CLAUDE.md`](CLAUDE.md). Cambios por versión: [`CHANGELOG.md`](CHANGELOG.md).
- Sin dependencias externas: el plugin se instala subiendo un ZIP.
- Prueba de humo contra un WordPress **local** (nunca en producción), con un usuario del rol del agente:

  ```bash
  WP_URL=https://mi-sitio.test WP_USER=agente WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' bash tests/smoke.sh
  ```

  Opcionales: `SMOKE_INSPECT=1` (inspección de páginas), `SMOKE_ONLINE=1` (librería online de YOOtheme), `SMOKE_CACHE_WRITE=1` (escritura de ajustes de caché; el usuario necesita `spiral_mcp_cache`).
- Tras añadir o cambiar abilities: `php tests/gen-skill-abilities.php /ruta/al/wordpress` regenera la referencia del skill.
- ZIP de instalación:

  ```bash
  git archive --format=zip --prefix=spiral-mcp-control/ -o dist/spiral-mcp-control-X.Y.Z.zip HEAD -- spiral-mcp-control.php uninstall.php includes LICENSE CHANGELOG.md
  ```

## Licencia

La cabecera del plugin declara GPL-2.0-or-later; el archivo [`LICENSE`](LICENSE) contiene el texto de la GPL v3.
