# W2 · Dashboards Blue Obsidian

W2 convierte `/cuenta/cliente` en un resumen funcional de la actividad del cliente y rediseña `/cuenta/prestador/dashboard` con la identidad Tekora de W1/W1.1. Base verificada: `dbb919939802d16c7ebd1737dc695f6bf88fcb1d`. Rama: `codex/web-w2-dashboards-blue-obsidian`. El PR se entrega en borrador para revisión independiente; no se fusiona ni despliega.

## Lecturas y autorización

`ClientDashboardSummary` concentra las agregaciones nuevas. Utiliza siempre el usuario autenticado por `web`, vuelve a autorizar `ManageOwnInquiries` y aplica `client_user_id` tanto a solicitudes como a reservas. No admite filtros de identidad: cualquier query string no vacía produce 422. El middleware de la ruta conserva su permiso existente. Administrador o prestador sin rol de cliente no obtienen acceso; una cuenta dual mantiene sus dos actividades separadas.

| Información del cliente | Semántica |
| --- | --- |
| Solicitudes activas | Propias, pendientes o aceptadas, mediante el scope existente |
| Reservas confirmadas futuras | `confirmed` con inicio estrictamente posterior al instante UTC consultado |
| Propuestas por confirmar | `requested` con inicio futuro; nunca se presentan como confirmadas |
| Mensajes como cliente | Predicado de `InquiryReadTracking::withUnreadCount()` sobre sus propias solicitudes; excluye autor propio y mensajes ya cubiertos por el marcador |
| Notificaciones | `NotificationInbox::unreadCount()` de la **cuenta completa**, rotulado explícitamente; un fallo del contador muestra indisponibilidad, no cero |
| Agenda | Solo confirmadas futuras, orden por inicio e ID, hasta cinco |
| Últimas solicitudes | Todos sus estados, orden descendente por creación e ID, hasta cinco |
| Historial de reservas | Totales separados por estado actual `completed` y `cancelled`, fuera de la agenda futura |

Un único instante UTC, truncado a segundos, sirve para todas las comparaciones. Las fechas visibles se convierten a `America/Mexico_City`, que aparece en pantalla; los elementos `time` conservan el instante UTC ISO. Los enlaces apuntan a los controladores existentes y estos siguen aplicando sus policies. El bloque MindBloom conduce a `identity.citas` y explica su separación del marketplace.

El controlador renderiza la lectura dentro de una transacción, sin DML ni llamadas a workflows. Un fallo PDO produce 503 con un mensaje seguro y una vista de acceso que no consulta contadores. No se marcan conversaciones ni avisos al abrir el panel. Las pruebas comparan todas las tablas antes/después de ambos GET y revisan que no se emitan INSERT/UPDATE/DELETE/REPLACE.

## Los diez KPI del prestador

`ProviderDashboardMetrics.php` y `ProviderDashboardController.php` permanecen idénticos byte por byte a la base. Se conservan las fuentes, ventanas, valores, etiquetas, notas y enlaces de los diez identificadores: `published`, `received`, `pending`, `acceptance`, `requested`, `upcoming`, `completed`, `cancellation`, `rating`, `unread`.

El componente añade iconos y estructura visual alrededor del mismo DTO. Las etiquetas «Estado actual» y «Del periodo» siguen explicando su alcance. El selector mantiene GET y periodos 7/30/90; 403/422/503 mantienen su comportamiento. Los rectángulos SVG conservan las coordenadas calculadas por el servicio; solo cambian su relleno y la opacidad de entrada. La tabla nativa desplegable contiene todos los días y valores exactos, también sin JavaScript. La batería de navegador compara cada barra con su fila y la suma con el KPI de completadas.

## Shell y movimiento

`account-layout` reutiliza `x-tekora-ambient`, `x-tekora-brand` y las fuentes locales DM Sans/Space Grotesk. La navegación central usa un `details` nativo: con JavaScript se abre en escritorio y se colapsa en móvil; sin JavaScript permanece abierta y se puede alternar. Enter, Tab y Escape conservan foco visible. Los contadores del menú se identifican como actividad de toda la cuenta.

El CSS está delimitado por `.tk-account-shell` y `.tk-dashboard`. Las vistas interiores que usan el shell conservan sus controles y superficies de formulario. Las pantallas clínicas históricas, CSS/JS públicos, rutas, modelos, enums, guards y policies no se modifican. La iconografía solo se añade a `account-navigation` cuando recibe `tekora=true`.

Anime.js 4.5.0 ya instalado anima la entrada de tarjetas y secciones desde 8 px y opacidad parcial. Las cifras son siempre las reales del servidor. No hay contadores ficticios, peticiones AJAX ni interceptores de formularios. El brillo y las microinteracciones son discretos; la navegación normal conserva una entrada CSS de 140 ms, sin interceptar enlaces ni formularios. `prefers-reduced-motion` detiene el fondo y todo movimiento decorativo; `prefers-reduced-transparency` utiliza paneles opacos. El contenido arranca visible incluso sin Anime.js o sin IntersectionObserver.

## Validación, evidencia y revisión local

Resultados y alcance de las comprobaciones: [VALIDACION.md](VALIDACION.md). Pasos para un clon nuevo y una previsualización sin credenciales: [LARAGON.md](LARAGON.md).

Las capturas y los videos son originales de esta implementación. Se generaron desde respuestas de los controladores reales, utilizando workflows existentes y fixtures sintéticos solo en SQLite `:memory:`. El reloj de demostración es **20/01/2030 12:00 UTC**; Alex es una cuenta dual y Estudio Nodo su perfil público. En su papel de cliente tiene **3 solicitudes activas, 1 reserva confirmada futura, 1 propuesta futura y 2 mensajes sin leer**; las 29 notificaciones son globales. En el panel del prestador aparecen tres completadas y una calificación 4,7/5 derivada de reseñas 5/4/5. Son datos reproducibles de prueba, no actividad de producción ni valores codificados en las vistas.

| Estado | Capturas / grabaciones |
| --- | --- |
| Cliente | [Escritorio](media/client-1440.png), [móvil](media/client-375.png), [vacío](media/client-empty-375.png) |
| Prestador | [Escritorio](media/provider-1440.png), [móvil](media/provider-375.png), [vacío](media/provider-empty-1440.png), [perfil pendiente](media/provider-setup-375.png) |
| Periodos | [7 días](media/dashboard-7-1440.png), [90 días](media/dashboard-90-1440.png) |
| Reducción de movimiento/transparencia | [Cliente](media/client-reduced-1440.png), [prestador](media/provider-reduced-1440.png) |
| Compatibilidad del shell | [Cuenta](media/cuenta-1440.png), [formulario conservado](media/prestador-375.png) |
| Videos del cliente | [Escritorio](media/client-1440.webm), [móvil](media/client-375.webm) |
| Videos del prestador | [Escritorio](media/provider-1440.webm), [móvil](media/provider-375.webm) |
| Video con movimiento reducido | [Ambos paneles](media/reduced-motion.webm) |

Los videos móviles usan viewport de 375 CSS px; el codificador VP8 alinea la salida a 374 px de ancho, registrado en [videos.json](evidence/videos.json). Los videos incluyen lectura, desplazamiento, estados vacíos y, para el prestador, tabla diaria, filtros nativos y perfil pendiente. Las evidencias no se sirven como fondos ni se incluyen en las páginas de la aplicación.

## Consultas y peso

La comparación usa el mismo dataset de `DashboardFixtures`, una petición de calentamiento y tres peticiones medidas por panel. Se midió la versión previa antes de editarla, y después W2, con el mismo PHP/SQLite en este entorno. Reproducible con `php tools/tekora-w2-benchmark.php`; para la base, copiar solo ese script a un checkout aislado del SHA base. Salidas completas: [antes](evidence/benchmark-before.json) y [después](evidence/benchmark-after.json).

| Panel | Consultas HTTP antes → después | Tiempo HTTP antes, ms | Tiempo HTTP después, ms |
| --- | --- | --- | --- |
| Cliente | 15 → **22** | 7,70 / 91,70 / 31,55 | 13,77 / 12,80 / 12,61 |
| Prestador | **24 → 24** | 23,04 / 17,14 / 35,35 | 16,17 / 14,92 / 14,06 |

El cliente agrega siete consultas al antiguo panel informativo: autorización fresca y seis consultas de lectura, incluidas las agregaciones y listas. El servicio tiene un límite probado de siete, incluso con once reservas, sin cargar cuerpos de mensajes ni historiales completos. El servicio del prestador mantiene once consultas, además de las necesarias para el shell y la petición. Estas mediciones pequeñas y locales tienen ruido; no acreditan una mejora de latencia de producción ni una prueba de carga.

Los nuevos scripts de shell/dashboard suman 1.291 bytes; el chunk compartido de Anime ocupa 37.646 bytes (se descarga una sola vez). CSS modular de shell/dashboard: 19.374 bytes, además del fondo ambiental existente. Gzip por recurso y rutas compiladas en [assets.json](evidence/assets.json). Las fuentes locales existentes suman 59.220 bytes. Las páginas interiores sin `dashboard=true` no solicitan el módulo de animación del dashboard. No se añadieron dependencias ni assets remotos.

Pendiente de la revisión independiente: Windows/Laragon, aceleración gráfica real, Safari/Firefox, lector de pantalla y zoom nativo. La validación cloud usa Chromium Linux con compositor software; no acredita hardware ni navegadores que no se ejecutaron.
