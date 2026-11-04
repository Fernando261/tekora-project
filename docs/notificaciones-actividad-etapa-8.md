# Notificaciones de actividad — Etapa 8

## Origen y alcance

Rama `codex/etapa-8-notificaciones-reservas-resenas`, creada desde `origin/main` actualizado tras comprobar que contiene **`3d9c183aaaeecc403a5dc16715894fd6b3e352a7`** (integración de Etapa 7, PR #12). Referencia reproducida antes de implementar: **319 pruebas, 3903 aserciones y 35 migraciones**.

Se conserva **una bandeja** en `/cuenta/notificaciones` y **un contador de no leídos** para solicitudes, reservas y reseñas. Los avisos anteriores permanecen en `inquiry_notifications`, sin modificar esa tabla ni su mecanismo de generación. La nueva tabla `activity_notifications` registra solo hechos nuevos de reservas y reseñas; no hay backfill de operaciones antiguas.

No se agregan correo, SMS, WhatsApp, push, WebSockets, proveedores externos ni tareas de caducidad. No se modificaron `.env`, claves, credenciales, bases persistentes, instalaciones reales o clones anteriores.

## Arquitectura e integración transaccional

La escritura de avisos está integrada en SQLite mediante **triggers síncronos**, instalados por la migración 36. Es una integración del mecanismo central de cambios, no un observer HTTP ni una entrega posterior al commit:

- `ReservationWorkflow::propose()` inserta la reserva y su evento inicial de 6B. El nuevo hook de `reservation_events` inserta el aviso en la misma sentencia/transacción.
- `ReservationWorkflow::decide()` y `closeForInquiry()` consumen una versión y generan un evento central; el mismo hook cubre decisiones directas y cierres automáticos.
- `ReviewWorkflow::publish()` inserta la reseña; un hook genera el aviso de publicación.
- `ReviewWorkflow::withdraw()` cambia publicada → retirada; un hook genera el aviso de retiro únicamente cuando ocurre esa transición.

Esta elección conserva sin cambios los workflows, estados, versiones, fechas y eventos anteriores, evita duplicar generación en PHP y cubre también escrituras SQL válidas. Los hooks escriben exclusivamente en la tabla nueva. No se añaden columnas a reservas, eventos, reseñas o mensajes para almacenar avisos.

Si falla un aviso, SQLite aborta la sentencia y el workflow revierte la operación de negocio. Si falla el aviso antiguo de finalización de solicitud después del cambio de reserva, la transacción exterior revierte ambos avisos y el cambio de reserva. Se probaron ambos sentidos de fallo y rollback exterior.

### Matriz de eventos y destinatarios

| Hecho | Evento almacenado | Destinatario |
| --- | --- | --- |
| Cliente propone reserva | `reservation_requested` | Prestador |
| Prestador confirma | `reservation_confirmed` | Cliente |
| Prestador rechaza | `reservation_rejected` | Cliente |
| Cliente cancela | `reservation_cancelled` | Prestador |
| Prestador cancela | `reservation_cancelled` | Cliente |
| Prestador completa | `reservation_completed` | Cliente |
| Cierre de solicitud cancela propuesta pendiente | `reservation_cancelled`, versión del evento central | Cliente |
| Cierre de solicitud completa reserva terminada | `reservation_completed`, versión del evento central | Cliente |
| Cliente publica reseña | `review_published` | Prestador |
| Autor retira reseña | `review_withdrawn` | Prestador |

No se notifica al actor. El destinatario y las fechas se derivan de los registros centrales, sin datos del navegador. La creación utiliza la fecha UTC del evento de reserva o de publicación/retiro de reseña, no otra actualización artificial del recurso.

**Finalizar solicitud y cambiar reserva son hechos distintos**: el cliente puede recibir un aviso antiguo de solicitud finalizada y otro de reserva cancelada/completada. Cada uno aparece una sola vez en su origen; no se duplica un mismo evento de reserva.

Consultar, abrir formularios, reenviar propuestas o reseñas idénticas, repetir un retiro, intentar una decisión obsoleta, recibir un rechazo de autorización/validación o revertir una transacción no crea avisos. Una propuesta que solo aparece expirada en pantalla no genera un evento de caducidad inexistente.

## Migración estrictamente aditiva 35 → 36

`2026_11_03_000002_create_activity_notifications.php` crea `activity_notifications`:

| Campos | Restricción |
| --- | --- |
| `user_id` | Destinatario central, FK restrictiva |
| `service_reservation_id` | FK restrictiva nullable |
| `service_review_id` | FK restrictiva nullable |
| `event` | Lista cerrada de cinco eventos de reserva y dos de reseña |
| `reservation_version` | Entero positivo solo para avisos de reserva |
| `created_at` | Fecha UTC canónica e idéntica al hecho de origen |
| `read_at` | NULL inicialmente; fecha UTC canónica no anterior a creación |

Los CHECKs exigen exactamente un recurso y un evento del tipo correspondiente. Una FK compuesta `(service_reservation_id, reservation_version)` apunta a la clave única de `reservation_events`, además de la FK a la reserva: el aviso referencia una transición real, incluso cuando la reserva avanza a otras versiones.

Índices únicos parciales:

- `(user_id, service_reservation_id, reservation_version)` para reservas.
- `(user_id, service_review_id, event)` para reseñas.

Hay índices por destinatario/fecha/ID y un índice parcial de no leídos. Los triggers de inserción comprueban destinatario opuesto al actor, correspondencia de evento/versión y fecha exacta. En reseñas, el destinatario es el dueño del perfil; publicar es un hecho histórico válido incluso después del retiro, y el retiro requiere que esa transición haya ocurrido.

Los campos de identidad, recurso, evento, versión y creación son inmutables. El único UPDATE admitido mueve `read_at` de NULL a una fecha válida una vez. No puede sustituirse ni volver a NULL. DELETE está prohibido. La asignación masiva del modelo está deshabilitada; su serialización no incorpora recursos privados cargados como relaciones.

Las **35 migraciones históricas permanecen intactas**. Se agregan tres hooks sobre las tablas centrales existentes, sin alterar sus filas, columnas o reglas anteriores. El `down()` elimina esos tres hooks y la tabla nueva con sus restricciones; no toca `inquiry_notifications` ni los recursos de negocio. Solo se ejecutó en memoria. Un rollback persistente descartaría historial de actividad y no forma parte de la preparación de Laragon.

## Idempotencia y concurrencia

Los reintentos de propuestas y publicaciones que ya existían regresan antes de escribir; por tanto no activan hooks. Una decisión aplicada no puede volver a consumir su versión. El retiro repetido no vuelve a cambiar el estado. Los índices únicos refuerzan estas propiedades incluso ante SQL directo y dos conexiones.

Las pruebas usan dos PDO independientes sobre una URI `mode=memory&cache=shared`, con `PRAGMA database_list` sin archivo físico. Mantienen transacciones abiertas mientras la otra conexión intenta proponer, decidir, publicar, retirar o marcar lectura. Comprueban conflictos controlados y reintentos sin duplicación.

La lectura usa un UPDATE condicional por ID, destinatario y `read_at IS NULL`. Dos clics conservan la primera fecha. Leer un aviso no cambia reserva, evento, solicitud, reseña, mensaje ni progreso de lectura de conversaciones.

## Bandeja SQL y contador único

`NotificationInbox` combina dos subconsultas filtradas por **el ID del usuario autenticado**, mediante `UNION ALL`. La paginación de 12 avisos se ejecuta en SQL; no se cargan historiales completos para luego cortarlos en PHP.

Orden determinista descendente:

1. `created_at`.
2. `source_type` (`inquiry` antes de `activity` cuando la fecha empata).
3. ID del origen.

Los IDs pueden coincidir entre tablas; `source_type` identifica su ruta de lectura y evita ambigüedad. `NotificationEntry` es una proyección con enum, categoría, fecha, lectura y enlaces ya autorizados. El historial anterior sigue consultable sin convertir ni copiar sus registros.

Los enlaces se resuelven por lotes con permisos vigentes y propiedad, equivalentes a las policies actuales de solicitud/reserva. Los controladores de destino vuelven a aplicar sus policies al abrirlos. Hay como máximo **siete consultas de carga de bandeja**, sin contar la navegación global, independientemente del número de avisos: total/página, permisos de las dos experiencias y hasta tres consultas de enlaces.

El contador usa otra consulta de `UNION ALL`, filtrando `read_at IS NULL` en ambos orígenes. No hay contadores separados para reservas/reseñas ni caché que pueda quedar obsoleta. La etiqueta accesible describe los avisos de las tres áreas. Si SQLite no permite consultar el contador, se muestra **«Contador no disponible»**, no un cero inventado ni SQL desde Blade.

El controlador mantiene una transacción de lectura durante total, página, enlaces y renderizado con el contador, para que compartan una instantánea. Abrir la bandeja no escribe `read_at`. Las fechas se interpretan expresamente como UTC y se presentan con el componente existente en `America/Mexico_City`, también si cambia la zona de la aplicación. Los registros antiguos siguen la configuración UTC original de Laravel.

Páginas válidas: 1..10000. Un formato inválido devuelve 422 y una página que excede el historial devuelve 404; no se presenta falsamente el estado vacío.

## Privacidad y autorización

Los textos son constantes del enum y genéricos. No se almacenan ni cargan títulos de servicios, mensajes, comentarios, estrellas, correos o nombres de la otra cuenta para la bandeja. Las categorías «Solicitudes», «Reservas» y «Reseñas» se distinguen mediante texto, no solo color.

Los avisos de reseñas solo pueden enlazar al servicio público, con `Service::publiclyVisible()`, aunque la reseña ya esté retirada. No hay enlaces al detalle privado de la reseña. Si el servicio se pausa, su categoría se inactiva o el prestador pierde su rol, se muestra un mensaje neutro. La consulta solo obtiene IDs de servicio, nunca el contenido de la reseña o su autor.

Al perder permisos de un recurso privado, el aviso se conserva con texto genérico pero sin enlace al recurso. El usuario sigue pudiendo leer su propio historial y marcarlo; no obtiene acceso por ser administrador. La proyección serializada tampoco incluye IDs privados de reseñas o reservas. Los IDs de recurso no aparecen en el texto accesible de lectura cuando el enlace no está autorizado.

### Rutas y lectura

- Se conserva GET `/cuenta/notificaciones` y POST `/cuenta/notificaciones/{notification}/leer` para solicitudes.
- Nueva POST `/cuenta/notificaciones/actividad/{notification}/leer`, nombre `notifications.activity.read`.
- Todas requieren `auth:web`; los POST usan CSRF.
- La ruta antigua conserva su comportamiento: ignora datos enviados por el navegador y actualiza únicamente la lectura de un aviso propio.
- La nueva ruta rechaza campos adicionales con 422 y limita a **30 lecturas/minuto/cuenta**, con `Retry-After` en 429. No afecta el límite ni el contrato de la ruta antigua.
- Se busca siempre dentro del destinatario antes de autorizar: aviso inexistente o ajeno devuelve 404. No existe endpoint de creación de avisos desde el navegador.

Los errores relevantes incluyen 401/redirect para invitados, 403 del recurso sin permiso, 404 de aviso/página, 409 de escritura concurrente, 419 CSRF, 422 de entrada, 429 de frecuencia y 503 temporal. El controlador contempla también consultas fallidas durante Blade. La vista de error no consulta bandeja ni contadores; no expone SQL, bindings, textos de fallos internos o rutas del servidor con depuración activa.

## Compatibilidad y archivos

Los workflows de solicitudes, reservas y reseñas, las clases de eventos y los modelos de negocio protegidos permanecen **sin cambios de código**. Los hooks nuevos no modifican sus estados, versiones, fechas ni historial. Se preservan autenticación/roles, catálogo, avisos antiguos, mensajería/idempotencia, progreso de lectura, disponibilidad, prevención de doble ocupación y retiro irreversible.

No se utiliza `Cita`, `Profesionista` ni disponibilidad heredada como fuente. Se probó el guard antiguo y correo coincidente sin enlazar identidades o cambiar credenciales. Las verificaciones de hashes incluyen `.env`, SQLite persistentes presentes, manifiestos/locks, 35 migraciones y componentes de negocio anteriores.

| Archivos | Cambio |
| --- | --- |
| `database/migrations/2026_11_03_000002_create_activity_notifications.php` | Tabla, FKs, CHECKs, índices, restricciones e integración transaccional |
| `app/Models/ActivityNotification.php`, `app/Enums/ActivityNotificationEvent.php` | Modelo de lectura y textos seguros |
| `app/Policies/ActivityNotificationPolicy.php`, `app/Exceptions/NotificationFailure.php` | Autorización y errores seguros |
| `app/Services/NotificationInbox.php`, `app/Support/NotificationEntry.php` | UNION SQL, contador, enlaces por lotes y lectura |
| `app/Models/User.php` | Relación aditiva `activityNotifications()` |
| `InquiryNotificationController.php`, `routes/notifications.php`, `AppServiceProvider.php` | Bandeja, ruta nueva, policy y frecuencia |
| Navegación de cuenta y `resources/views/notifications/*` | Contador unificado, categorías, formularios nativos y errores |
| `tests/Feature/Activity*.php` | Cinco clases nuevas, 31 pruebas |
| `tests/Feature/InquiryNotificationsTest.php` | Fixture de escape adaptado a la nueva proyección, sin quitar aserciones |
| `tests/Feature/ReviewMigrationTest.php` | Escenario histórico acotado a 34→35, sin quitar aserciones |
| `README.md`, esta guía, `docs/validaciones/etapa-8/*` | Documentación y evidencias |

## Validaciones ejecutadas

| Comando | Resultado |
| --- | --- |
| Referencia antes de implementar | **319 pruebas / 3903 aserciones**, código 0 |
| `composer install --no-interaction --prefer-dist` | Código 0; autoload/package discovery correctos |
| `npm ci` | Código 0 |
| `npm audit` y `npm audit --json` | Código 0; **0 vulnerabilidades npm** |
| `npm run build` | Código 0; mismos assets Vite anteriores |
| `php vendor/bin/phpunit --do-not-cache-result` | **350 pruebas / 4337 aserciones**, código 0 |
| `php artisan route:list --except-vendor` | Código 0; **98 rutas**, sin `.env` ni SQLite persistente |

Se conservan exactamente **319 pruebas y 3903 aserciones anteriores**. Las **31 pruebas nuevas aportan 434 aserciones**. Los dos ajustes de fixtures anteriores mantienen todas sus aserciones. No hay diferencias dinámicas en los contadores de aserciones previos.

Cobertura nueva: matriz de eventos/destinatarios, ausencia de autoavisos, cancelación de ambas partes, cierre automático, idempotencia, rollback de negocio/avisos, dos conexiones, restricciones SQL, historial antiguo, orden/paginación UNION, contador exacto, lectura concurrente, aislamiento, privacidad de reseñas, pérdida de permisos, códigos de error, CSRF, frecuencia, independencia de zona, guard heredado y migración 35→36. `PRAGMA foreign_key_check` no reporta infracciones e `integrity_check` devuelve `ok`.

PHP 8.2.35, PHPUnit 11.5.24, Laravel 12.19.3, Node 24.19.0/npm 11.9.0. Datos y reversiones automatizadas solo en memoria. Composer se ejecutó en una copia Etapa 8 nueva sin `.env` ni red, reutilizando paquetes del lock montados de solo lectura con metadatos propios. Persisten avisos previos Flysystem/Pint y deprecaciones Sass/Bootstrap; no se tratan como fallos nuevos. No se actualizaron dependencias ni locks.

Revisión Chromium: **34 comprobaciones**, 15 estados a 1440×1000 y 390×844 más cuatro sin JavaScript. Incluye bandeja vacía/combinada, contador en panel, lectura de ambos orígenes, reseña retirada, lectura histórica de publicación después del retiro, permisos revocados, paginación, servicio no público, aviso ajeno, página inválida, error temporal y contador no disponible. Se revisaron etiquetas, categorías, CSRF, formularios nativos, menú móvil y ausencia de datos privados. Sin desbordamientos horizontales, errores JavaScript o peticiones externas.

Los estados se generaron por peticiones Laravel y fixtures sintéticos nuevos en memoria; Chromium revisó sus artefactos HTML estáticos. Las escrituras están verificadas en PHPUnit. Se inspeccionaron capturas de bandeja combinada en escritorio, lectura móvil y contador temporal. **Windows/Laragon queda pendiente**, no se afirma su ejecución desde Linux.

Evidencias: [resultados](validaciones/etapa-8/resultados.json), [npm audit](validaciones/etapa-8/npm-audit.json), [visual](validaciones/etapa-8/visual.json).

## Riesgos y limitaciones

Los hooks transaccionales son específicos de SQLite. Cambiar de motor requiere garantías equivalentes. No deben retirarse los hooks en una instalación activa: forman parte de la entrega atómica y no hay otro escritor PHP duplicado ni un proceso de reparación que los sustituya.

No hay avisos retrospectivos. Las operaciones anteriores siguen en sus historiales originales; una publicación anterior a la migración puede generar un aviso nuevo de retiro posterior, pero no uno retroactivo de publicación. No se inventan caducidades.

La tabla es historial restrictivo: eliminar o reasignar cuentas/recursos requiere una estrategia futura de retención. No hay borrado ni lectura reversible. SQLite puede devolver conflictos temporales; si falla la escritura del aviso se revierte el negocio, y si no puede consultarse el contador se muestra indisponibilidad explícita.

La resolución por lotes de enlaces refleja las policies actuales de propiedad/roles; cambios futuros de esas policies deben actualizar la proyección y sus pruebas de equivalencia. El recurso siempre vuelve a autorizar el acceso al abrirse. Los enlaces de reseñas dependen del catálogo público, no de acceso privado a su texto retirado.

La aplicación de la migración sobre una copia persistente y la integración en `main` quedan pendientes de la aprobación y pruebas independientes. Esta entrega no fusiona, despliega ni modifica instalaciones reales.

## Windows / Laragon / PowerShell — preparación sin aplicar la migración

Usar exclusivamente **`C:\laragon\www\tekora-etapa8`**, puerto **8011**. No reutilizar ni modificar clones anteriores. PHP 8.2+ con SQLite, Composer y Node 22/24 compatibles con el lock.

### 1. Clon nuevo y pruebas sin `.env` ni SQLite persistente

```powershell
Set-Location C:\laragon\www
if (Test-Path -LiteralPath 'C:\laragon\www\tekora-etapa8') {
    throw 'La carpeta Etapa 8 ya existe. No sobrescribir ni reutilizar clones anteriores.'
}
git clone --branch codex/etapa-8-notificaciones-reservas-resenas --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa8
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el clon nuevo.' }
Set-Location C:\laragon\www\tekora-etapa8
php --version
php --ini
php -m
composer install
if ($LASTEXITCODE -ne 0) { throw 'Falló Composer.' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'Falló npm ci.' }
npm.cmd audit
if ($LASTEXITCODE -ne 0) { throw 'Revisar la auditoría antes de continuar.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas.' }
php artisan route:list --except-vendor
if ($LASTEXITCODE -ne 0) { throw 'Falló la revisión de rutas.' }
```

Esperado: **350 pruebas, 4337 aserciones**, auditoría npm limpia y build correcto. Las pruebas fuerzan SQLite en memoria y generan una clave efímera de proceso; no crean `.env` ni regeneran la clave de una instalación. No necesitan una copia persistente ni seeds generales.

### 2. Copia temporal exclusiva de Etapa 7 y revisión de la migración

**Después de la revisión independiente** de los resultados anteriores, preparar un respaldo consistente **de prueba** de la SQLite validada de Etapa 7, con **35 migraciones** y cuentas de desarrollo. No copiar parcialmente una base activa; detener la aplicación de origen o utilizar un respaldo SQLite consistente, considerando WAL. Conservar su clave existente.

Este bloque crea una copia nueva GUID en TEMP, verifica las migraciones y la integridad, y ejecuta únicamente `migrate --pretend`. Las variables afectan solo esta terminal; no escribe `.env` ni modifica el respaldo original.

```powershell
Set-Location C:\laragon\www\tekora-etapa8
$Origen7 = Read-Host 'Ruta del respaldo consistente de prueba de SQLite Etapa 7 (35 migraciones)'
if (-not (Test-Path -LiteralPath $Origen7 -PathType Leaf)) { throw 'No existe el respaldo.' }
$Base8 = Join-Path ([IO.Path]::GetTempPath()) ('tekora-8-' + [guid]::NewGuid().ToString() + '.sqlite')
if (Test-Path -LiteralPath $Base8) { throw 'No sobrescribir archivos temporales existentes.' }
Copy-Item -LiteralPath $Origen7 -Destination $Base8
$Clave7 = Read-Host 'APP_KEY existente de esa copia Etapa 7 (no generar otra)' -AsSecureString
$env:APP_KEY = [Net.NetworkCredential]::new('', $Clave7).Password
Remove-Variable Clave7
if ([string]::IsNullOrWhiteSpace($env:APP_KEY)) { throw 'Falta la clave existente.' }
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8011'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-8-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $Base8
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa8_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
$Revisor8 = Join-Path ([IO.Path]::GetTempPath()) ('tekora-8-check-' + [guid]::NewGuid().ToString() + '.php')
if (Test-Path -LiteralPath $Revisor8) { throw 'No sobrescribir un archivo revisor existente.' }
$Codigo8 = @'
<?php
$pdo = new PDO('sqlite:'.getenv('DB_DATABASE'));
$aplicadas = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
$esperadas = array_map(fn ($p) => basename($p, '.php'), glob('database/migrations/*.php'));
$esperadas = array_values(array_filter($esperadas, fn ($m) => $m < '2026_11_03_000002'));
sort($esperadas);
if (count($aplicadas) !== 35 || $aplicadas !== $esperadas) {
    fwrite(STDERR, 'La copia no corresponde a las 35 migraciones de Etapa 7.'); exit(1);
}
if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    fwrite(STDERR, 'La copia no pasa la revisión de integridad.'); exit(1);
}
echo 'Copia Etapa 7 verificada: 35 migraciones e integridad correctas.';
'@
[IO.File]::WriteAllText($Revisor8, $Codigo8, [Text.UTF8Encoding]::new($false))
php $Revisor8
$Revision8 = $LASTEXITCODE
Remove-Item -LiteralPath $Revisor8
if ($Revision8 -ne 0) { throw 'Detenerse y revisar la copia de origen.' }
Write-Host "Base exclusiva de revisión: $Base8"
php artisan migrate:status
if ($LASTEXITCODE -ne 0) { throw 'Falló la consulta del estado.' }
php artisan migrate --pretend
if ($LASTEXITCODE -ne 0) { throw 'Falló la revisión de la migración.' }
Write-Host 'Preparación terminada. NO aplicar la migración hasta recibir aprobación independiente.'
```

**Detenerse aquí.** Debe aparecer solo `2026_11_03_000002_create_activity_notifications` pendiente. Revisar el SQL y obtener la aprobación independiente antes de aplicar cambios incluso sobre esa copia persistente de prueba. Esta entrega no ejecutó tal migración persistente.

### 3. Únicamente después de la aprobación independiente

Desde la misma terminal y usando exclusivamente `$Base8`, aplicar la migración revisada y arrancar el servidor que heredará esas variables:

```powershell
# Ejecutar únicamente después de recibir la aprobación independiente del paso 2.
php artisan migrate --path=database/migrations/2026_11_03_000002_create_activity_notifications.php
if ($LASTEXITCODE -ne 0) { throw 'La migración de la copia temporal falló.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8011
```

Esperado después de aplicar: **36 migraciones**. Abrir `http://127.0.0.1:8011`. No usar un Apache de Laragon ya iniciado, que no hereda estas variables. Al terminar, detener el servidor y cerrar PowerShell para descartar configuración y clave de sesión. No ejecutar `migrate:fresh`, `db:wipe`, rollback destructivo ni seeds generales sobre bases persistentes; no regenerar `APP_KEY` ni sobrescribir `.env` o copias existentes.

### Recorrido manual recomendado

1. Revisar primero instalación, compilación, PHPUnit y listado de rutas sin `.env` ni SQLite. Tras revisión independiente, preparar la copia temporal de Etapa 7, verificar 35 migraciones e integridad y detenerse después de `migrate --pretend`.
2. Solo con aprobación de aplicación y migración 36 realizada en esa copia, entrar a `http://127.0.0.1:8011`. Usar cuentas centrales de cliente/prestador distintas y comprobar el estado vacío de una cuenta sin avisos.
3. Crear y aceptar una solicitud: los avisos históricos deben seguir apareciendo. Proponer reserva, confirmar, rechazar y cancelar como cada participante. Verificar destinatario contrario al actor y ausencia de avisos al propio actor.
4. Completar una reserva después de su fin y finalizar solicitudes con reserva pendiente o confirmada terminada. Deben producir los hechos de reserva correspondientes, además del aviso de solicitud cuando corresponda, sin duplicar una versión de reserva.
5. Publicar y retirar una reseña. El prestador solo debe recibir los textos genéricos; no comentario, estrellas, nombre del cliente ni enlace a reseña privada. Pausar el servicio: el enlace público debe desaparecer y mostrar un mensaje neutro.
6. Reenviar propuestas/publicaciones idénticas y retiros repetidos: el contador no debe crecer por duplicados. Consultar formularios, recursos y bandeja tampoco debe generar avisos ni marcar lecturas.
7. Abrir dos pestañas y marcar un mismo aviso. Debe conservarse una fecha de lectura y disminuir una sola vez el contador compartido. La lectura de solicitudes antiguas debe seguir funcionando con su ruta original.
8. Verificar categorías textuales, fecha en Ciudad de México, orden cronológico y empates, paginación y contador exacto en una cuenta dual. Revisar móvil, escritorio y formularios nativos sin JavaScript.
9. Intentar leer un aviso ajeno como cliente, prestador y administrador: 404. Revocar permisos de un recurso en la copia: conservar aviso genérico sin enlace; abrir su detalle por URL no debe eludir la policy. Probar CSRF, datos adicionales y 429 después del límite de lectura.
10. Comprobar solicitudes, mensajes/idempotencia, contadores de mensajes, lectura de conversaciones, horarios, prevención de doble reserva, reseñas y guard antiguo. No usar citas antiguas como fuente de avisos centrales ni vincular identidades por correo.
