# Reseñas verificadas — Etapa 7

## Origen y alcance

Rama `codex/etapa-7-resenas-verificadas`, creada desde `origin/main` actualizado después de comprobar que contiene **`fe46c9ff9974e00cbc2a89bdef53572e292094a9`** (integración de 6B, PR #11). Referencia reproducida antes de implementar: **288 pruebas, 3447 aserciones y 34 migraciones**.

Los clientes pueden valorar con 1–5 estrellas una reserva central completada, con comentario opcional de hasta 1000 caracteres. La etiqueta pública es **«Servicio verificado»**, con identificación genérica **«Cliente verificado»**. Verifica la relación con una reserva completada; no acredita un pago ni una verificación profesional.

No se incorporan pagos, recomendaciones IA, incentivos, moderadores, edición de reseñas ni reputación automática. No se modificaron `.env`, `APP_KEY`, credenciales, bases persistentes, instalaciones reales o clones anteriores.

## Elegibilidad y arquitectura

`ServiceReservation` es la única fuente. El autor debe ser su cliente `User`, autenticado con `web`, conservar `ManageOwnInquiries` y no ser dueño del perfil prestador. La reserva debe tener estado exactamente `ReservationStatus::Completed` y relaciones centrales coherentes con servicio, solicitud y perfil.

**No se exige que la solicitud esté completada.** Una reserva completada con solicitud todavía aceptada permite reseñar. Una solicitud aceptada o finalizada sin una reserva completada no da derecho a publicar. `requested`, `confirmed`, `rejected` y `cancelled` se rechazan.

`ServiceReviewPolicy` comprueba identidad, rol y propiedad. La elegibilidad de estado se comprueba en el controlador de creación, el servicio transaccional y los triggers; el enlace «Dejar reseña» solo aparece en reservas completadas sin reseña, para su cliente autorizado. Un prestador y un administrador ajenos no pueden escribir en nombre del cliente ni acceder a sus rutas privadas.

`ReviewInput` comparte las reglas HTTP y del servicio. Solo permite `rating` y `comment`; retirar no admite datos de negocio. Rechaza IDs, autor, estado, verificación, timestamps y cualquier campo adicional, incluso vacío. Las estrellas aceptan enteros 1–5 o sus valores textuales de formulario; no aceptan booleanos, fracciones ni notación exponencial. El comentario normaliza CRLF/CR a LF, recorta espacios exteriores y convierte blanco/vacío en NULL; cuenta hasta 1000 caracteres y rechaza NUL.

El contenido se guarda como texto literal. Blade lo escapa tanto en público como en privado y en el formulario. No se interpreta HTML, Markdown ni scripts. El formulario utiliza radios nativos, `fieldset`/`legend`, etiquetas y textarea, sin JavaScript ni bibliotecas nuevas. Las estrellas visuales incluyen una etiqueta accesible, no dependen únicamente del color.

| Ruta | Función |
| --- | --- |
| GET `/cuenta/resenas` | Mis reseñas, solo del autor, 12 por página |
| GET `/cuenta/resenas/reservas/{reservation}/crear` | Formulario o redirección a la reseña existente |
| POST `/cuenta/resenas/reservas/{reservation}` | Publicar o reconocer el mismo envío |
| GET `/cuenta/resenas/{review}` | Registro privado del autor, incluso retirado |
| PATCH `/cuenta/resenas/{review}/retirar` | Retiro irreversible por el autor |

Las escrituras repiten la autorización dentro de la transacción. El INSERT y el retiro vuelven a comprobar permisos mientras mantienen el bloqueo de escritura. No hay un privilegio administrativo global ni un acceso privado especial del prestador.

## Modelo y migración aditiva 34 → 35

La migración nueva `2026_11_03_000001_create_service_reviews.php` crea únicamente `service_reviews`:

| Campos | Garantía |
| --- | --- |
| `service_reservation_id` | FK restrictiva, UNIQUE; una sola reseña histórica por reserva |
| `service_id`, `provider_profile_id`, `client_user_id` | FKs centrales, valores derivados de la reserva en el servidor |
| `rating` | INTEGER con CHECK de tipo y rango 1..5 |
| `comment` | TEXT opcional, CHECK de longitud 1..1000 y sin NUL |
| `status` | Solo `published` o `withdrawn` |
| `created_at` | UTC canónico, no anterior a la decisión de reserva completada |
| `withdrawn_at_utc`, `withdrawn_by_user_id` | Fecha UTC y FK del actor del retiro; el actor debe coincidir con el autor |

Las publicadas tienen metadatos de retiro NULL; las retiradas los requieren. Hay índices parciales por servicio/perfil para reseñas publicadas y otro por cliente para su historial privado.

Cinco triggers protegen SQL directo: INSERT/UPDATE exigen reserva completada y coherencia con el grafo reserva → solicitud/servicio/perfil/cliente; el alta debe ser publicada; la única actualización válida es publicada → retirada, conservando todos los campos de identidad, estrellas, comentario y creación; DELETE está prohibido. La retirada es terminal y tampoco permite editar sus metadatos.

Los modelos tienen asignación masiva deshabilitada. Se agregan `ServiceReservation::review()`, `Service::reviews()` y `ProviderProfile::reviews()`. No se modifica ningún estado, intervalo o evento de la reserva. La verificación no se almacena como un booleano controlado por el navegador.

Las **34 migraciones históricas permanecen intactas**. No se alteran columnas, filas o triggers anteriores. `down()` solo elimina la tabla nueva con sus índices/triggers. Se verificaron upgrade, reversión y reaplicación **exclusivamente en memoria**, preservando todas las columnas y filas anteriores. No ejecutar rollback sobre una instalación persistente: descartaría el historial nuevo.

## Concurrencia e idempotencia

`ReviewWorkflow::publish()` autoriza y valida dentro de una transacción. La reserva completada es terminal e inmutable por las garantías de 6B. El INSERT de la reseña adquiere el bloqueo SQLite sin escribir artificialmente sobre reservas, eventos, cuentas o disponibilidad. UNIQUE es la garantía final de exclusión; no depende solo de un SELECT.

Un reenvío con las mismas estrellas y comentario normalizado devuelve el registro original, incluso retirado, sin reactivarlo. Contenido distinto devuelve 409 y no sobrescribe. No se necesita otra clave UUID: la operación única está identificada por la reserva y su autor derivado. Si una inserción encuentra un conflicto UNIQUE, se vuelve a autorizar y reconocer el contenido tras el rollback. Durante un bloqueo aún activo puede ser necesario reintentar el mismo formulario después de un 409.

El retiro es un UPDATE condicional de estado publicado a retirado. Un reintento posterior conserva la primera fecha y actor, sin escribir de nuevo. No libera el UNIQUE de la reserva. Ningún retiro permite reemplazo ni republicación.

Las pruebas mantienen una transacción de publicación sin commit en una conexión mientras otra conexión PDO intenta publicar; comprueban el conflicto, el reintento idéntico y la existencia de una única fila. También prueban retiro simultáneo y restricciones por SQL directo. Ambas conexiones usan URI compartida `mode=memory&cache=shared`, con `PRAGMA database_list` sin archivo físico.

Los conflictos se clasifican por códigos SQLite (BUSY/LOCKED/CONSTRAINT), sin buscar nombres de conexión o texto del comentario en el SQL. Las excepciones seguras no conservan una excepción SQL previa ni se reportan con bindings privados.

## Visibilidad, retiro y privacidad

`published` permite presentación pública únicamente si el servicio satisface `Service::publiclyVisible()`: publicación, categoría activa y rol de prestador vigente. Se puede valorar un servicio pausado que ya fue recibido, pero la reseña solo queda en el espacio privado hasta que el servicio vuelva a cumplir las reglas públicas.

El autor puede retirar su reseña, sin edición ni borrado. Desde ese momento deja de aparecer y contribuir a promedios/recuentos. Solo el autor con sus permisos centrales vigentes conserva acceso privado; ni el prestador participante de la reserva ni un administrador ajeno pueden consultar el texto retirado. El detalle privado de la reserva muestra el enlace a la reseña únicamente al autor, nunca al prestador.

Si se pausa un servicio, se desactiva su categoría o se revoca el rol del prestador, las reseñas se conservan, pero desaparecen de su presentación y agregados públicos. Cambiar zona, intervalos o bloqueos de disponibilidad no afecta reseñas ni reservas completadas. La pérdida posterior del rol cliente impide acceso/escrituras privadas; no retira automáticamente una reseña válida ya publicada.

`PublicReviews` usa una proyección pública explícita: estrellas, comentario, mes/año y servicio público. No selecciona ni devuelve nombre del cliente, correo, teléfono, reserva, solicitud o conversación. El mes/año corresponde a la creación de la reseña en UTC; no se publican tiempos exactos ni la fecha de la reserva. Los enlaces de servicio son públicos; no hay un detalle público de reseña por ID.

El texto voluntario del comentario es público mientras la reseña sea visible; el formulario pide evitar datos personales. Este MVP no introduce detección de datos personales ni moderación automática del contenido.

## Promedios, recuentos y paginación

Una sola consulta base selecciona reseñas publicadas, relacionadas con una reserva completada y servicios actualmente visibles. Servicio y perfil comparten este alcance. El promedio del prestador se calcula **por reseña**, no como promedio de promedios de sus servicios. Se redondea a un decimal para mostrar, manteniendo el recuento entero.

Promedio, recuento y página se obtienen en una misma transacción de lectura. No hay caché de reputación ni columnas agregadas que puedan quedar desactualizadas. La prueba de lectura intercalada impide mezclar un promedio anterior al retiro con comentarios posteriores al retiro; la siguiente petición refleja el cambio.

Las reseñas públicas se paginan de 5 en 5 mediante `reviews_page`; los servicios del perfil conservan su parámetro `page`. Los enlaces mantienen ambas posiciones sin mezclar sus controles. Las páginas se limitan a 1..10000; formato inválido responde 422 y página inexistente 404. La navegación de reseñas tiene su etiqueta accesible propia.

Hay **cuatro consultas de reseñas**, con datos presentes, independientemente de su cantidad: comprobar servicio visible, agregado, total de paginación y página con título público del servicio mediante JOIN. No se cargan relaciones privadas por fila. Si no hay reseñas se muestra **«Aún no hay reseñas verificadas»**, sin representar 0 estrellas como una mala valoración.

## Seguridad y compatibilidad

Todos los POST/PATCH tienen `auth:web`, CSRF, validación estricta y límite compartido de **10 operaciones por minuto/cuenta**. 429 incluye `Retry-After`. Las rutas privadas autorizan en controlador y servicio; no usan propietarios enviados por el navegador.

Respuestas: 403 sin permiso, 404 recurso/página inexistente, 409 contenido duplicado incompatible/estado/conflicto de escritura, 419 CSRF, 422 datos inválidos, 429 frecuencia y 503 fallo temporal de lectura o escritura. HTML de validación conserva la redirección Laravel con errores; JSON devuelve 422. Las lecturas públicas temporalmente no disponibles responden 503, sin fingir un recuento vacío.

Los errores usan una vista sin navegación ni contadores de base de datos. También se controlan excepciones de consultas envueltas por Blade; con depuración activa no se exponen SQL, bindings o texto de errores internos. Los enlaces de error llevan al catálogo, accesible tanto a visitantes como a cuentas sin rol cliente.

Se preservan registros/roles, catálogo, solicitudes, avisos, mensajes, idempotencia, contadores/progreso de lectura, disponibilidad y reservas. Publicar o retirar no modifica esas tablas ni genera avisos nuevos. El sistema heredado no se consulta para verificar reseñas ni se enlaza por correo; se probaron sus credenciales, disponibilidad y acceso separados.

### Archivos

| Archivos | Cambio |
| --- | --- |
| Migración `2026_11_03_000001_create_service_reviews.php` | Tabla, FKs, CHECKs, unicidad, índices y triggers |
| `app/Models/ServiceReview.php`, `Service.php`, `ProviderProfile.php`, `ServiceReservation.php` | Modelo y relaciones aditivas |
| `app/Enums/ReviewVisibility.php`, `app/Policies/ServiceReviewPolicy.php`, `app/Support/ReviewInput.php` | Visibilidad, autorización, validación y paginación |
| `app/Services/ReviewWorkflow.php`, `PublicReviews.php`, `app/Exceptions/ReviewFailure.php` | Escrituras, lecturas consistentes y errores seguros |
| `app/Http/Controllers/ServiceReviewController.php`, `routes/reviews.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php` | HTTP, rutas, policy y frecuencia |
| `ServiceCatalogController.php`, `PublicProviderController.php`, vistas de detalle público | Integración aditiva, respetando los scopes anteriores |
| `resources/views/reviews/*`, `components/public-reviews.blade.php`, `review-stars.blade.php`, `reservation-review.blade.php`, `public/css/reviews.css` | Pantallas privadas/públicas y componentes accesibles |
| Navegación de cuenta, detalle de reserva y `catalog-pagination.blade.php` | Enlaces autorizados y etiqueta opcional de paginación |
| `tests/Feature/Review*.php`, `tests/Support/ReviewFixtures.php` | Cinco clases nuevas y fixtures a través de los workflows reales |
| `tests/Feature/ReservationMigrationTest.php` | Acota el escenario histórico 33→34, manteniendo todas sus aserciones |
| `README.md`, este documento y `docs/validaciones/etapa-7/*` | Guía y evidencias |

## Resultados ejecutados

| Comando | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; autoload/package discovery correctos, sin cambios de lock |
| `npm ci` | Código 0 |
| `npm audit` y `npm audit --json` | Código 0; **0 vulnerabilidades** |
| `npm run build` | Código 0; assets Vite anteriores conservados |
| `php vendor/bin/phpunit --do-not-cache-result` | **319 pruebas, 3903 aserciones**, código 0 |

La referencia **288 / 3447** se reprodujo antes de implementar. Las 288 pruebas anteriores se conservan y ahora ejecutan **3448**: `ConversationInboxTest` comprueba cada consulta, y la policy del enlace «Mis reseñas» añade una comprobación dinámica (45→46 en ese caso). Ninguna aserción anterior se retiró. Las **31 pruebas nuevas aportan 455 aserciones**; total **3903**.

Cobertura: reserva completada con solicitud aceptada, otros estados no elegibles, propiedad/terceros/admin/autorreseña/roles vigentes, campos manipulados, estrellas y comentarios, HTML literal escapado, reintentos y conflictos, dos conexiones SQLite reales, retiro irreversible, privacidad, datos públicos mínimos, servicios pausados/categorías/roles, medias ponderadas y cero reseñas, paginación, número constante de consultas, snapshot coherente, CSRF, errores seguros con debug, compatibilidad y migración 34→35. Incluye `PRAGMA foreign_key_check` e `integrity_check`.

Entorno: PHP 8.2.35, PHPUnit 11.5.24, Laravel 12.19.3, Node 24.19.0/npm 11.9.0. Toda operación automatizada de datos utilizó memoria. Composer reutilizó paquetes del lock montados de solo lectura, con metadatos propios, en una copia Etapa 7 nueva sin `.env` ni red. Persisten los avisos anteriores Flysystem/Pint y deprecaciones Sass/Bootstrap. No se actualizaron dependencias ni manifiestos/locks.

Revisión Chromium: **38 comprobaciones** (17 estados a 1440×1000 y 390×844, más cuatro sin JavaScript). Incluye vacío, reserva completada, formulario, validación, reseña publicada/retirada, listado privado, servicio/perfil públicos, promedio 4.7, paginación, 403/404/409/503. Se comprobaron radios/textarea nativos, CSRF/PATCH, anonimato, estrellas accesibles, menú móvil y formularios sin JavaScript. Sin desbordamiento horizontal, errores JavaScript o peticiones externas. Se inspeccionaron capturas de formulario móvil y presentación pública, además del retiro privado.

Las pantallas se generaron mediante peticiones Laravel y fixtures nuevos en memoria; Chromium inspeccionó sus artefactos HTML estáticos. Las escrituras y respuestas HTTP corresponden a PHPUnit. **Windows/Laragon no se ejecutó en Linux**; queda pendiente de revisión independiente.

Evidencias: [resultados](validaciones/etapa-7/resultados.json), [npm audit](validaciones/etapa-7/npm-audit.json), [revisión visual](validaciones/etapa-7/visual.json). Se verificaron sin cambios hashes de los archivos protegidos, `.env`, bases persistentes presentes, 34 migraciones, manifiestos/locks y workflows anteriores.

## Windows / Laragon / PowerShell — preparación sin aplicar la migración

Usar exclusivamente **`C:\laragon\www\tekora-etapa7`**, puerto **8010**. No reutilizar ni modificar clones anteriores. PHP 8.2+ con SQLite, Composer y Node 22/24 compatibles con el lock.

### 1. Clon nuevo y pruebas sin `.env` ni SQLite persistente

```powershell
Set-Location C:\laragon\www
if (Test-Path -LiteralPath 'C:\laragon\www\tekora-etapa7') {
    throw 'La carpeta Etapa 7 ya existe. No sobrescribir ni reutilizar clones anteriores.'
}
git clone --branch codex/etapa-7-resenas-verificadas --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa7
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el clon nuevo.' }
Set-Location C:\laragon\www\tekora-etapa7
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
```

Esperado: **319 pruebas, 3903 aserciones**, auditoría npm limpia y build correcto. Las pruebas fuerzan SQLite en memoria y generan una clave efímera de proceso; no crean `.env` ni regeneran la clave de una instalación. No necesitan una copia persistente ni seeds generales.

### 2. Copia temporal exclusiva de 6B y revisión de la migración

Solo después de lo anterior, preparar un respaldo consistente **de prueba** de la SQLite validada de 6B, con **34 migraciones** y cuentas de desarrollo. No copiar parcialmente una base activa; detener la aplicación de origen o utilizar un respaldo SQLite consistente, considerando WAL. Conservar su clave existente.

Este bloque crea una copia nueva GUID en TEMP, verifica las migraciones y la integridad, y ejecuta únicamente `migrate --pretend`. Las variables afectan solo esta terminal; no escribe `.env` ni modifica el respaldo original.

```powershell
Set-Location C:\laragon\www\tekora-etapa7
$Origen6B = Read-Host 'Ruta del respaldo consistente de prueba de SQLite 6B (34 migraciones)'
if (-not (Test-Path -LiteralPath $Origen6B -PathType Leaf)) { throw 'No existe el respaldo.' }
$Base7 = Join-Path ([IO.Path]::GetTempPath()) ('tekora-7-' + [guid]::NewGuid().ToString() + '.sqlite')
if (Test-Path -LiteralPath $Base7) { throw 'No sobrescribir archivos temporales existentes.' }
Copy-Item -LiteralPath $Origen6B -Destination $Base7
$Clave6B = Read-Host 'APP_KEY existente de esa copia 6B (no generar otra)' -AsSecureString
$env:APP_KEY = [Net.NetworkCredential]::new('', $Clave6B).Password
Remove-Variable Clave6B
if ([string]::IsNullOrWhiteSpace($env:APP_KEY)) { throw 'Falta la clave existente.' }
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8010'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-7-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $Base7
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa7_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
$Revisor7 = Join-Path ([IO.Path]::GetTempPath()) ('tekora-7-check-' + [guid]::NewGuid().ToString() + '.php')
if (Test-Path -LiteralPath $Revisor7) { throw 'No sobrescribir un archivo revisor existente.' }
$Codigo7 = @'
<?php
$pdo = new PDO('sqlite:'.getenv('DB_DATABASE'));
$aplicadas = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
$esperadas = array_map(fn ($p) => basename($p, '.php'), glob('database/migrations/*.php'));
$esperadas = array_values(array_filter($esperadas, fn ($m) => $m < '2026_11_03_000001'));
sort($esperadas);
if (count($aplicadas) !== 34 || $aplicadas !== $esperadas) {
    fwrite(STDERR, 'La copia no corresponde a las 34 migraciones de 6B.'); exit(1);
}
if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    fwrite(STDERR, 'La copia no pasa la revisión de integridad.'); exit(1);
}
echo 'Copia 6B verificada: 34 migraciones e integridad correctas.';
'@
[IO.File]::WriteAllText($Revisor7, $Codigo7, [Text.UTF8Encoding]::new($false))
php $Revisor7
$Revision7 = $LASTEXITCODE
Remove-Item -LiteralPath $Revisor7
if ($Revision7 -ne 0) { throw 'Detenerse y revisar la copia de origen.' }
Write-Host "Base exclusiva de revisión: $Base7"
php artisan migrate:status
if ($LASTEXITCODE -ne 0) { throw 'Falló la consulta del estado.' }
php artisan migrate --pretend
if ($LASTEXITCODE -ne 0) { throw 'Falló la revisión de la migración.' }
Write-Host 'Preparación terminada. NO aplicar la migración hasta recibir aprobación independiente.'
```

**Detenerse aquí.** Debe aparecer solo `2026_11_03_000001_create_service_reviews` pendiente. Revisar el SQL y obtener la aprobación independiente antes de aplicar cambios incluso sobre esa copia persistente de prueba. Esta entrega no ejecutó tal migración persistente.

### 3. Únicamente después de la aprobación independiente

Desde la misma terminal y usando exclusivamente `$Base7`, aplicar la migración revisada y arrancar el servidor que heredará esas variables:

```powershell
# Ejecutar únicamente después de recibir la aprobación independiente del paso 2.
php artisan migrate --path=database/migrations/2026_11_03_000001_create_service_reviews.php
if ($LASTEXITCODE -ne 0) { throw 'La migración de la copia temporal falló.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8010
```

Esperado después de aplicar: **35 migraciones**. Abrir `http://127.0.0.1:8010`. No usar un Apache de Laragon ya iniciado, que no hereda estas variables. Al terminar, detener el servidor y cerrar PowerShell para descartar configuración y clave de sesión. No ejecutar `migrate:fresh`, `db:wipe`, rollback destructivo ni seeds generales sobre bases persistentes; no regenerar `APP_KEY` ni sobrescribir `.env` o copias existentes.

### Recorrido manual recomendado

1. Con cliente y prestador centrales distintos, usar una reserva `completed`. La solicitud puede seguir aceptada. Una pendiente, confirmada, rechazada o cancelada no debe ofrecer «Dejar reseña» ni permitir publicación directa.
2. Abrir el formulario, comprobar advertencia de publicación/inmutabilidad, cinco opciones accesibles y comentario opcional. Publicar estrellas 1..5 y hasta 1000 caracteres; verificar errores sin perder el formulario con estrellas inválidas, comentario largo o campos internos manipulados.
3. Reenviar exactamente el formulario: reconocer la reseña original. Cambiar su contenido mediante otro envío: 409, sin reemplazo. Comprobar 429 después de más de diez operaciones en un minuto.
4. Consultar servicio y ficha de prestador como visitante. Ver «Cliente verificado», «Servicio verificado», mes/año, promedio/recuento y comentarios paginados. No deben aparecer datos del cliente, reserva o conversación. Etiquetas HTML escritas en el comentario deben verse como texto, sin ejecutarse.
5. Retirar desde «Mis reseñas». Confirmar conservación privada y desaparición pública, también en promedios/recuentos. No debe aparecer una opción para editar, republicar o dejar otra reseña de la misma reserva.
6. Probar acceso privado y retiro como prestador, tercero y administrador no participante: 403. Recurso inexistente: 404; CSRF ausente/incorrecto: 419. El prestador no debe ver el contenido retirado en el detalle de la reserva.
7. Pausar un servicio, desactivar su categoría o retirar el rol prestador en el entorno de prueba: no debe contribuir a agregados públicos. Restaurar la publicación debe recuperar solo las reseñas que continúen publicadas. Los cambios de disponibilidad no alteran reseñas ya emitidas.
8. Comprobar promedios con puntuaciones distintas y varios servicios. El agregado del perfil debe ponderar cada reseña, excluyendo retiradas/servicios ocultos, independientemente de la página de servicios.
9. Revisar dos envíos simultáneos y retiro en dos pestañas; no deben duplicar, sobrescribir ni revelar SQL. La cobertura de dos PDO, errores de lectura y escrituras directas se ejecuta además en las pruebas de memoria.
10. Revisar móvil/escritorio y formulario/retiro sin JavaScript. Confirmar preservación de solicitudes, avisos, mensajes idempotentes, lectura, horarios, reservas e historial. El acceso heredado debe seguir separado, sin reseñas verificadas deducidas de sus citas.

## Limitaciones y riesgos

La garantía de persistencia utiliza SQLite, FKs activadas, unicidad y triggers; otro motor requiere garantías equivalentes. SQLite puede bloquear temporalmente lecturas o escrituras y exigir reintento. Las transacciones de dos conexiones se validaron en memoria; Windows/Laragon debe revisar su versión y configuración de SQLite.

Una reseña verificada acredita una relación central con una reserva marcada completada, no prueba independiente de calidad, pago, identidad profesional o prestación material. No hay moderación completa ni filtros automáticos de contenido. El prestador no puede retirar reseñas; pausar el servicio oculta conjuntamente su publicación y reseñas, conforme al alcance público solicitado. No se implementa una reputación histórica de servicios retirados.

La inmutabilidad, UNIQUE y FKs restrictivas conservan historial y limitan borrado/reasignación; una política futura de retención, derechos de cuenta o moderación deberá definir excepciones mediante otra entrega. Una retirada es irreversible en este MVP. Revocar el rol cliente impide acceso privado hasta recuperar los permisos correspondientes; no existe un bypass administrativo implícito.

Persisten avisos previos de Composer y Sass/Bootstrap; una auditoría npm limpia no constituye auditoría Composer. La revisión local de Laragon y la integración quedan pendientes del usuario. Entrega únicamente un **PR en borrador**, sin fusionar ni desplegar.
