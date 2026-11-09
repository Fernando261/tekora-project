# W3 · Inventario MindBloom y retirada gradual

Base auditada: `00c13ee0f9504125320f0119d29454bd503326cd`, integración del PR #19. La auditoría se hace sobre código, consumidores Blade, rutas, guards y esquemas; no se consultan registros de bases persistentes. No se presume que un nombre antiguo sea código muerto ni que una referencia ausente permita borrar medios históricos.

## A · Marca antigua retirada de la experiencia pública moderna

| Elemento | Situación anterior | Acción W3 |
| --- | --- | --- |
| `components/catalog-layout.blade.php` | Título terminado en MindBloom, logo histórico y marca MindBloom con subtítulo Catálogo Tekora | Título, meta description, marca vectorial Tekora, navegación pública y footer Cinematic Marketplace |
| `/servicios`, `/servicios/{service}` | Layout mixto de ambas marcas y tarjetas de superficies claras | Solo Tekora; encabezados y composición propios, filtros y datos originales |
| `/prestadores`, `/prestadores/{providerProfile}` | Misma identidad antigua heredada del layout de catálogo | Solo Tekora; avatares tipográficos derivados del nombre público, perfiles y conteos originales |
| `components/tekora-layout.blade.php` | Footer de portada/acceso promocionaba MindBloom junto a Prestadores | Se sustituye ese enlace por Servicios. El resto de portada y autenticación aprobadas no cambia |
| Enlaces privados de cuenta | Sección MindBloom anterior en el sidebar aprobado | Se conserva como acceso histórico autenticado; no se promociona como otra marca pública |

No se hace un reemplazo global de cadenas. Las citas, PDF, medios y registros antiguos conservan su procedencia; no se presentan como actividad del marketplace.

Las cuatro rutas GET del catálogo reciben el middleware **existente** `TekoraPageHeaders`, que ya protegía portada y acceso. El middleware, su CSP y la autorización no cambian. Esta adición explícita en `routes/catalog.php` completa las cabeceras de las páginas modernizadas sin modificar URI, nombre, controlador, métodos ni reglas de visibilidad.

## Consumidores reales y límites de CSS

| Recurso | Consumidores encontrados | Decisión |
| --- | --- | --- |
| `service-card` | Catálogo y servicios del perfil público | Adaptar para W3; las tarjetas de gestión de servicios usan markup propio |
| `catalog-pagination` | Marketplace, reseñas privadas, reservas, conversaciones, solicitudes, notificaciones y gestión de servicios | Mantener markup y comportamiento; W3 solo lo estiliza dentro de `.tk-mp` |
| `provider-availability` | Detalle/perfil públicos y formulario de propuesta de reserva | Mantener invocación, lectura, zona horaria y advertencias; estilos W3 acotados |
| `public-reviews` / `review-stars` | Servicio y perfil públicos; estrellas también en vistas operativas | Mantener promedio, anonimato, verificación por reserva completada y paginación `reviews_page` |
| `public/css/catalog.css` | Marketplace anterior y páginas de gestión, solicitudes, conversaciones, notificaciones | Se conserva byte por byte para W4; el nuevo layout público usa su propio bundle |
| `public/css/account.css`, `availability.css`, `reviews.css` | Formularios, reseñas, reservas y otras vistas activas | Se conservan byte por byte; W3 no impone sus estilos fuera del marketplace |
| `ambient.css` | Portada, acceso, cuenta y marketplace | Se reutiliza sin cambios. W3 evita la clase `tekora-interior`, cuyo tratamiento claro anterior no corresponde a sus superficies |
| `tekora-brand`, fuentes locales y `tekora-icon` | Portada, acceso y shell moderno | Reutilizados sin imágenes ficticias ni descargas externas |

## B · Candidatos sin consumo estático suficiente para retirarlos

Se registran los consumidores por archivo en [legacy-static-references.json](evidence/legacy-static-references.json). La búsqueda incluye fuentes versionadas de `app`, `routes`, `config`, `resources`, `public`, `tests`, herramientas y Vite; excluye documentación, vendor y outputs de build. Los números de referencia son evidencia estática, no una certificación sobre URLs externas, cachés o datos dinámicos.

| Candidato | Referencias estáticas al path CSS | Decisión y comprobación pendiente |
| --- | --- | --- |
| `public/css/login.css` | 0 | Conservar. Contrastar acceso central y recuperación con las plantillas históricas y enlaces externos antes de retirar |
| `public/css/login-unificado.css` | 0 | Conservar. Comparar con `login.blade.php`, bloque de profesionista y autenticación aprobada W1.1 |
| `public/css/registro-unificado.css` | 0 | Conservar. Verificar intenciones de registro y compatibilidad de bookmarks/documentación antigua |
| `public/css/provider-dashboard.css` | 0 | Conservar. El dashboard moderno carga Vite, pero la retirada del recurso histórico requiere revisar consumidores externos y cobertura visual específica |
| `public/css/citas.css` | 0 | Conservar. Las citas antiguas continúan activas; no concluir que una variante CSS sobrante autorice retirar su experiencia o datos |

`GoogleCalendarController.php` también merece revisión posterior de referencias frente al flujo activo de `ProfesionistaGoogleController`. No se elimina: la familia de integración sigue viva y exige pruebas OAuth, renovación, desconexión y eventos simulados antes de decidir qué implementación está obsoleta.

**Archivos eliminados en W3: ninguno.** La limpieza segura de esta entrega retira promoción/branding obsoleto y separa el nuevo bundle de los estilos antiguos; no borra recursos cuya compatibilidad histórica no está demostrada. Esta decisión aplica la regla «ante la duda, conservar».

## C · Compatibilidad activa que debe conservarse

| Familia | Evidencia de uso actual | Datos/dependencias y riesgo | Sustituto futuro y pruebas necesarias |
| --- | --- | --- | --- |
| Inicio histórico `/mindbloom/inicio` | `routes/web.php` → `HomeController`, `home.blade.php`; enlace privado en `account-navigation` | `Emotion`, tarjetas, contenido e imágenes. Cambiarlo puede romper enlaces guardados y acceso a información histórica | Una sección privada de historial con procedencia. Probar autenticación web, enlaces, contenido existente y medios antes de migrar |
| Comunidad `/comunidad`, posts, comentarios y likes | Rutas GET/POST/DELETE activas; `ComunidadController`, `CommentController`, `CommentLikeController` | Modelos `Post`, `Comment`, `Like`, relaciones de `User`, archivos adjuntos y ownership | No hay sustituto W3/W4 aprobado. Requiere inventario de contenido, permisos, adjuntos y exportación/archivo explícito |
| Citas `/citas`, `/citas/create/{profesionista}`, `/consultas` | `CitasController`, `UserCitaController`; `/consultas` redirige al listado de citas | `Cita`, `Profesionista`, `ProfesionistaDisponibilidad`; relaciones e identidad distintas de `ServiceReservation` | Evaluar mapping y estados hacia reservas Tekora, sin atribuir automáticamente el origen antiguo al nuevo. Probar propiedad, concurrencia, fechas, disponibilidad y cancelación |
| Directorio `/profesionistas` y disponibilidad histórica | Rutas autenticadas, `ProfesionistaController`, vistas `profesionistas/*` | La tabla/identidad `profesionistas` no equivale a `provider_profiles`; campos y credenciales no son acreditación Tekora | Decisión de identidad y consentimiento antes de migrar. Probar URLs antiguas, permisos, visibilidad y ausencia de exposición de datos adicionales |
| Acceso de profesionistas | GET/POST `/login-profesionista`, logout y dashboard; `ProfesionistaAuthController`; `config/auth.php` guard/provider separados | Sesión `profesionista`, contraseña, recuperación y asociaciones de citas | No unir guards por coincidencia de email. Probar aislamiento entre sesiones, acceso central, acceso anterior, logout y recuperación. El texto de acceso histórico en login conserva la procedencia MindBloom, sin ser branding del marketplace |
| Google Calendar heredado | `/google/connect`, `/google/callback`, `/google/disconnect`; `ProfesionistaGoogleController`, `ProfesionistaCitaController` | Tokens OAuth, scopes, expiración, refresh y IDs de eventos. Borrarlo puede perder vínculos o duplicar eventos | No hay reemplazo W3. Antes de retirar: mocks de OAuth/refresh, desconexión, aceptación/rechazo, conflicto de agenda y eventos existentes; nunca usar credenciales reales en preview |
| PDF y emociones | `/pdf/emociones/tarjetas`, la ruta histórica pública `/emociones/{slug}`, `PdfController`, `Emotion`, `emotion/show.blade.php`, `pdf/emotions/cards.blade.php` | `Storage::disk('public')`, `image_path` de datos, contenido y nombre de descarga `mindbloom-tarjetas-emociones.pdf` | Archivo/exportación con origen claro. Probar generación de PDF, imágenes presentes/ausentes, nombres y descargas existentes |
| Perfil, fotos y medios | `/perfil`, edición de nombre/foto, `PerfilController`/`ProfileController` | `profile_photo_path`, rutas Storage y medios referenciados desde datos | La modernización visual no autoriza borrar archivos sin una auditoría de referencias persistentes y autorización sobre su conservación |
| Autenticación y tablas comunes | `User`, roles, permisos, guards, migraciones y relaciones compartidas | Cambiar datos o esquemas para limpiar marca puede afectar marketplace, API y legado | Fuera de W3. Mantener 37 migraciones y 21 operaciones API; exigir plan de migración independiente |

No se han inspeccionado tokens, contraseñas, credenciales ni contenido persistente. No se han ejecutado migraciones manuales, seeds o rollbacks sobre instalaciones existentes.

## Puerta de salida para una retirada posterior

Para cada familia se requiere un PR independiente que identifique consumidores y datos reales autorizados, el sustituto aprobado, un mapping reversible o una estrategia de archivo, cobertura de permisos/descargas/medios, compatibilidad de URLs y una validación de Windows. Hasta entonces siguen activos los modelos, tablas, rutas, guards y workflows históricos.

W3 moderniza únicamente el marketplace público. La experiencia operativa queda para W4; la retirada de datos e infraestructura heredada necesita una decisión explícita posterior.
