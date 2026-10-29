# Spiral-MCP-Control

Plugin de WordPress que permite a un agente de IA (por ejemplo, Claude) gestionar un sitio sin entrar al wp-admin: con cualquier tema, el contenido, los menús, los medios, el SEO, los ajustes, las cachés y los plugins; con **YOOtheme Pro**, además el builder, la configuración del tema, las plantillas y los estilos; y en un sitio con **Elementor**, las herramientas de su propio MCP pasan por los permisos y la auditoría de Spiral. Registra *abilities* de la [Abilities API](https://developer.wordpress.org/) de WordPress y el plugin **MCP Adapter** las publica como herramientas MCP. Con ellas el agente puede gestionar contenido, layouts del builder, plantillas, menús, widgets, medios, estilos, SEO, ajustes, cachés y, con Polylang, idiomas y traducciones.

Cada cambio queda en un registro de auditoría y, salvo en los posts (que tienen revisiones), se guarda antes un *snapshot* para poder deshacerlo.

## Requisitos

- WordPress 6.9 o superior (Abilities API).
- PHP 8.1 o superior.
- Plugin [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) de wordpress.org, activo. Es una dependencia declarada (`Requires Plugins: mcp-adapter`): WordPress lo muestra en la lista de plugins ("Requiere: MCP Adapter"), ofrece instalarlo y no deja activar Spiral-MCP-Control sin él ni desactivar MCP Adapter mientras Spiral-MCP-Control esté activo. Instálalo desde *Plugins > Añadir nuevo*: WordPress reconoce la dependencia por la carpeta `mcp-adapter`, así que un ZIP de GitHub con otro nombre de carpeta no cuenta.
- Opcional: YOOtheme Pro (o un tema hijo) para el builder, la configuración del tema, las plantillas, la librería y los estilos. Sin él, esas 31 abilities no se registran (el agente no las ve y *Ajustes > Spiral-MCP* las muestra como no disponibles); el resto funciona con cualquier tema.
- Opcional: Elementor. Spiral lee y edita las páginas hechas con sus widgets clásicos (heading, texto, imagen, botón, carrusel de imágenes, secciones y columnas) a través de la propia API de documentos de Elementor, con revisión, snapshot y borrador antes de publicar; no reimplementa su builder. Con Elementor 4.3+ y su MCP activado (*Elementor > Elementor MCP*) envuelve además las herramientas `elementor/*` (elementos V4) con sus permisos por grupo, el registro de actividad, la purga de caché y el interruptor por ability. Cada contenido lleva `builder` (`yootheme`, `elementor` o `none`).

## Instalación

1. Descarga el ZIP de la versión (o genéralo: ver [Desarrollo](#desarrollo)) y súbelo en *Plugins > Añadir nuevo > Subir plugin*.
2. Si MCP Adapter no está instalado, WordPress lo indica en la ficha del plugin: instálalo y actívalo (*Plugins > Añadir nuevo*, busca "MCP Adapter").
3. Activa Spiral-MCP-Control. Se crean las tablas de auditoría y snapshots, las capacidades `spiral_mcp_*` (los administradores las reciben todas) y el rol **Agente Spiral-MCP** (`spiral_mcp_agent`).
4. Crea un usuario con ese rol, genera una *Application Password* para él y conecta tu cliente MCP al MCP Adapter con esas credenciales.
5. Para el agente, instala el skill de Claude `skills/spiral-mcp-wordpress/` (método de trabajo, recetas y errores frecuentes).

Las versiones nuevas llegan por el actualizador de WordPress (*Escritorio → Actualizaciones*), servidas por SPUpdater desde `https://plugins.rasenfx.com`. No hace falta clave de licencia.

## Qué puede hacer el agente

Las abilities llevan el prefijo `spiral-mcp/`. La referencia completa de parámetros está en [`skills/spiral-mcp-wordpress/references/abilities.md`](skills/spiral-mcp-wordpress/references/abilities.md).

| Área | Abilities |
|---|---|
| Contenido | listar, leer, crear y editar páginas, entradas y tipos propios; papelera; revisiones; taxonomías y términos; SEO (Yoast o salida propia) |
| Layouts de YOOtheme | esquema de un layout, textos, nodo, `patch-layout` (operaciones JSON Patch atómicas), duplicar, catálogo de elementos y validación |
| Tema | configuración de YOOtheme (header, logo, navbar…), footer y mega menús, widgets (también de builder), plantillas, biblioteca y [librería online de YOOtheme](https://yootheme.com) |
| Elementor | widgets clásicos: catálogo y esquema de controles, árbol de la página (esquema, textos, nodo), cambios por id de elemento o JSON Patch (ajustes, mover, borrar, duplicar, insertar), reemplazo del árbol, borrador de Elementor con vista previa y publicación; con su MCP activo, las herramientas `elementor/*` (elementos V4, kit, componentes) bajo los permisos y la auditoría de Spiral; `list-content` y `get-content` indican el `builder` de cada contenido y `update-content` rechaza `content` en páginas de Elementor, porque Elementor lo ignora |
| Medios | subir (URL, base64 o enlace de subida directa para archivos locales), listar, editar, borrar, imagen destacada, dónde se usa un medio |
| Estilos | CSS adicional; estilo, variables LESS y estado de compilación de YOOtheme |
| Verificación | URL de vista previa sin sesión, inspección de una página (encabezados, enlaces rotos, alt, SEO efectivo) |
| Ajustes | identidad del sitio, portada, zona horaria; con Yoast SEO activo, sus ajustes generales (plantillas de título, Schema, redes sociales, migas de pan, mapas del sitio, verificación, rastreo) |
| Plugins | listar los instalados, buscar en wordpress.org, instalar (sin activar), activar con comprobación de salud, desactivar, actualizar (vuelve a la versión anterior si rompe el sitio) y borrar; con doble cerrojo, cerrado por defecto |
| Tienda | con WooCommerce activo: productos simples y variables, variaciones, atributos, cupones, ajustes de la tienda (incluido el modo "próximamente"), zonas y métodos de envío, impuestos y pasarelas de pago (solo lectura) |
| Pedidos | con WooCommerce activo y permiso aparte: pedidos (estado, notas, reembolsos) y clientes (lectura) |
| Idiomas | con Polylang activo: idiomas y ajustes, traducciones de páginas (con su diseño de YOOtheme), entradas y términos, textos sueltos (nombre del sitio, widgets), menús por idioma y selector de idioma; las abilities de contenido, términos, menús y widgets aceptan `language` |
| Formularios | con Contact Form 7 activo: listar (y dónde se usan), leer, crear, editar y borrar; cambiar a quién llega el correo exige confirmación |
| Cachés | estado, purga, precarga y ajustes de la herramienta de caché del sitio (ver abajo) |
| Auditoría | registro de actividad, snapshots y restauración (también parcial) |

## Permisos

| Capacidad | Para qué | Rol del agente |
|---|---|---|
| `spiral_mcp_content` | contenido, taxonomías, SEO, formularios | sí |
| `spiral_mcp_design` | builder, tema, menús, widgets, estilos | sí |
| `spiral_mcp_media` | medios | sí |
| `spiral_mcp_settings` | ajustes del sitio, de Yoast SEO y de Polylang (idiomas) | sí |
| `spiral_mcp_code` | JS personalizado (sección `scripts` de YOOtheme) y HTML sin filtrar en los layouts (elemento `html`, `<script>`, `<iframe>`, atributos `on*`…); sin él, el HTML de los elementos pasa por `wp_kses_post`, como el de cualquier usuario sin `unfiltered_html` | sí |
| `spiral_mcp_cache` | **cambiar** los ajustes de caché | **no** |
| `spiral_mcp_plugins` | gestionar plugins de wordpress.org (además hay que activar "Permitir que el agente gestione plugins" en *Comportamiento*) | **no** |
| `spiral_mcp_shop` | tienda de WooCommerce: catálogo, cupones, ajustes, envíos e impuestos | sí |
| `spiral_mcp_orders` | pedidos y clientes de WooCommerce (datos personales, reembolsos) | **no** |

Las capacidades del rol del agente se pueden cambiar en **Ajustes > Spiral-MCP > Permisos del agente**, y la elección se mantiene al actualizar el plugin. En la pestaña **Abilities** de esa página se activa o desactiva cada herramienta o una categoría entera (con atajos "Solo lectura" y "Desactivar las destructivas"). Una ability desactivada no se publica en el MCP: el agente ni la ve. Las de YOOtheme aparecen como no disponibles cuando el tema no está activo, y las de Elementor se gestionan en la misma pestaña (desactivada = oculta al MCP y 403). La pestaña **Comportamiento** decide si se purga la caché tras cada cambio, cuántos días se conservan el registro y los snapshots, si el agente puede descargar diseños de la librería online de YOOtheme y si al desinstalar se borran las tablas. La pestaña **Actividad** muestra los cambios del agente y permite restaurar cada uno desde wp-admin.

Además, cada ability comprueba la capacidad nativa de WordPress del objeto (`edit_post` de ese post, `edit_theme_options`…). El rol del agente nunca recibe las capacidades nativas de administración (`manage_options`, las de plugins, temas, usuarios y actualizaciones del núcleo, `edit_files`…), ni siquiera con un filtro: las abilities de plugins usan las APIs de WordPress con su propia comprobación. Las acciones destructivas exigen `"confirm": true`.

## Gestión de plugins

El agente siempre puede **ver** los plugins instalados (`list-plugins`) y **buscar** en wordpress.org (`search-plugins`). Para **instalar, activar, desactivar, actualizar o borrar** hacen falta dos cerrojos, los dos cerrados al instalar Spiral-MCP-Control:

1. En **Ajustes > Spiral-MCP > Comportamiento**, marcar "Permitir que el agente gestione plugins de wordpress.org".
2. En **Ajustes > Spiral-MCP > Permisos del agente**, dar al rol la capacidad `spiral_mcp_plugins`.

Con cualquiera de los dos cerrado, esas abilities responden 403 y dicen cuál falta (`list-plugins` lo indica en `management`). Si el sitio define `DISALLOW_FILE_MODS`, no funcionan nunca.

| Ability | Qué hace | Salvaguardas |
|---|---|---|
| `install-plugin` | instala por slug de wordpress.org | solo descargas de `downloads.wordpress.org` (no acepta ZIP ni URLs); comprueba la versión de WordPress y PHP; queda **desactivado** |
| `activate-plugin` | activa | un error fatal al cargarlo no lo deja activo; si después la portada o wp-admin dan error 500, se desactiva solo; snapshot de los plugins activos (`restore-snapshot` lo deshace) y purga de caché |
| `deactivate-plugin` | desactiva (conserva los datos) | snapshot y comprobación del sitio |
| `update-plugin` | actualiza a la última versión de wordpress.org | exige `"confirm": true`; comprueba la compatibilidad; si la nueva versión da error 500, vuelve sola a la anterior |
| `delete-plugin` | borra un plugin desactivado y ejecuta su desinstalación | exige `"confirm": true`; **irreversible** (sus datos pueden borrarse) |

Spiral-MCP-Control y MCP Adapter están protegidos: el agente no puede desactivarlos, actualizarlos ni borrarlos (sin ellos perdería el acceso). Los plugins de pago o de otros orígenes no se actualizan desde aquí. Todo queda en el registro de actividad. El skill del agente le pide instalar solo lo que el usuario pida, enseñarle antes las opciones y confirmar antes de activar o borrar.

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
- Sin dependencias externas: el plugin se instala subiendo un ZIP. La librería cliente de SPUpdater va copiada en `includes/spupdater/` con el namespace `SpiralMcp\SPUpdater\WpClient`.
- Publicar una versión: genera el ZIP (abajo) y súbelo en `https://plugins.rasenfx.com/admin` → **Releases → New** (producto `spiral-mcp-control`), con el mismo número que `SPIRAL_MCP_VERSION`.
- Prueba de humo contra un WordPress **local** (nunca en producción), con un usuario del rol del agente:

  ```bash
  WP_URL=https://mi-sitio.test WP_USER=agente WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' bash tests/smoke.sh
  ```

  Opcionales: `SMOKE_INSPECT=1` (inspección de páginas), `SMOKE_ONLINE=1` (librería online de YOOtheme), `SMOKE_CACHE_WRITE=1` (escritura de ajustes de caché; el usuario necesita `spiral_mcp_cache`), `SMOKE_PLUGINS=1` (con los dos cerrojos de plugins abiertos para ese usuario: comprueba los protegidos y `confirm`; sin él, comprueba que la gestión está cerrada).
- Pruebas de seguridad (plan, batería automatizada y resultados): [`docs/pruebas-seguridad.md`](docs/pruebas-seguridad.md). Contra un WordPress **local**: `php tests/security-setup.php /ruta/wordpress https://mi-sitio.test /ruta/privada/creds.env` y después `SEC_ENV=/ruta/privada/creds.env WP_PATH=/ruta/wordpress bash tests/security.sh`.
- Plugins de prueba para la gestión de plugins en [`tests/fixtures/plugins/`](tests/fixtures/plugins/): uno que da error fatal al cargarse, otro que rompe la web pública, uno incompatible, uno con desinstalación y uno cuya versión 2.0.0 rompe la portada (con un mu-plugin que anuncia esa actualización). Cópialos a `wp-content/plugins` de un sitio **local**.
- Tras añadir o cambiar abilities: `php tests/gen-skill-abilities.php /ruta/al/wordpress` regenera la referencia del skill. Solo ve las abilities registradas: hazlo con Contact Form 7, Yoast SEO, WooCommerce y Polylang activos, o quitará sus secciones (revisa el diff).
- ZIP de instalación:

  ```bash
  git archive --format=zip --prefix=spiral-mcp-control/ -o dist/spiral-mcp-control-X.Y.Z.zip HEAD -- spiral-mcp-control.php uninstall.php includes LICENSE CHANGELOG.md
  ```

## Licencia

La cabecera del plugin declara GPL-2.0-or-later; el archivo [`LICENSE`](LICENSE) contiene el texto de la GPL v3.
