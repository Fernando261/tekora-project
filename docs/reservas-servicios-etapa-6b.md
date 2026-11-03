# Reservas de servicios — Etapa 6B

## Alcance y origen

Rama `codex/etapa-6b-reservas-servicios`, creada desde `origin/main` actualizado, después de comprobar que contiene `b5080391cb9e5273915169106aaaedeb2c0e1399` (PR #10). Referencia reproducida en una copia nueva de ese commit: **247 pruebas, 2960 aserciones y 33 migraciones**. No se modificaron `.env`, `APP_KEY`, credenciales, bases persistentes ni clones anteriores.

Un cliente participante propone un horario desde una solicitud central **aceptada**. El prestador participante lo confirma o rechaza. Las reservas son de **60 minutos**; no se infiere ninguna duración del servicio. «Horario propuesto ≠ Reserva confirmada». El formulario, el detalle y la bandeja lo distinguen y aclaran que no se han realizado pagos.

No hay pagos, cobros, calendarios externos, videollamadas, recordatorios, espacios de duración variable ni sincronización con el sistema anterior. No se crean nuevos avisos de reservas: los contadores y eventos de 4B/5A/5B permanecen independientes.

## Arquitectura

`ServiceReservation` pertenece a `ServiceInquiry`, su `Service`, su `ProviderProfile` central y su cliente `User`. `ServiceInquiry::reservations()` y `ProviderProfile::reservations()` son relaciones aditivas. Los modelos nuevos rechazan asignación masiva. Las relaciones y la propiedad se derivan de la solicitud y del servicio en el servidor.

`ServiceReservationPolicy` comprueba participación y permisos centrales vigentes; ningún privilegio administrativo sustituye la propiedad. `ReservationInput` valida el conjunto exacto de campos tanto para HTTP como para el servicio. `ReservationTime` resuelve las horas civiles sin normalizar ambigüedades. `ReservationWorkflow` coordina autorización repetida, bloqueo de escritura, disponibilidad, idempotencia y transición. El controlador mantiene errores y respuestas en español.

| Ruta | Uso |
| --- | --- |
| GET `/cuenta/reservas` | Mis reservas, paginadas de 12 en 12; unión de experiencias autorizadas de cliente/prestador |
| GET `/cuenta/reservas/solicitudes/{inquiry}/crear` | Formulario de propuesta, zona y disponibilidad vigente |
| POST `/cuenta/reservas/solicitudes/{inquiry}` | Crear o reconocer un envío idempotente |
| GET `/cuenta/reservas/{reservation}` | Detalle privado, participantes e historial |
| PATCH `/cuenta/reservas/{reservation}/confirmar` | Confirmar como prestador |
| PATCH `/cuenta/reservas/{reservation}/rechazar` | Rechazar como prestador |
| PATCH `/cuenta/reservas/{reservation}/cancelar` | Cancelar como participante autorizado |
| PATCH `/cuenta/reservas/{reservation}/completar` | Marcar realizado como prestador |

Todas usan `auth:web`. Los enlaces del panel, de la solicitud y de su conversación respetan las policies. No se exponen correos, identificadores de idempotencia ajenos ni cuerpos de conversaciones en la bandeja. No hay bibliotecas ni JavaScript nuevos: formularios Blade y controles nativos de fecha/hora funcionan sin JavaScript; el menú utiliza la mejora progresiva ya existente.

## Política de estados y tiempo

| Origen → destino | Responsable | Condiciones |
| --- | --- | --- |
| — → `requested` | Cliente de la solicitud | Solicitud aceptada, identidades/servicio autorizados, disponibilidad publicada y vigente, fecha futura, sin otra reserva activa de la solicitud |
| `requested` → `confirmed` | Prestador del servicio | Inicio todavía futuro; revalidar toda la disponibilidad, identidad, zona, instantes y ocupación |
| `requested` → `rejected` | Prestador | Puede rechazar también una propuesta expirada |
| `requested` → `cancelled` | Cliente o prestador participante | Puede cerrar también una propuesta expirada |
| `confirmed` → `cancelled` | Cliente o prestador participante | Únicamente antes del inicio |
| `confirmed` → `completed` | Prestador | Una vez alcanzado el fin UTC, inclusive |

`rejected`, `cancelled` y `completed` son terminales. Una reserva no cambia de horario ni se reactiva: se crea otra propuesta con otra clave, si la solicitud sigue aceptada. Una propuesta pendiente expirada sigue registrada como `requested`, se identifica como expirada en pantalla y requiere cancelación o rechazo para liberar la solicitud. No expira silenciosamente ni genera tareas externas.

Cada alta y transición produce automáticamente un evento inmutable con versión, estado anterior/nuevo, actor, motivo y fecha UTC. Los eventos no se editan ni eliminan; la reserva conserva su identidad e intervalo originales. Las fechas de decisión se consultan en el historial; `last_actor_user_id` y `last_reason` describen la última decisión.

### Cierre de solicitudes

Se conservan las reglas de `InquiryWorkflow`: el cliente solo cancela una solicitud pendiente; una aceptada se finaliza como prestador. Una pendiente no admite reservas y no puede dejarlas huérfanas.

Antes de finalizar una aceptada, dentro de la misma transacción, `closeForInquiry()` obtiene el bloqueo y vuelve a autorizar al prestador. Una propuesta pendiente se cancela con motivo `inquiry_completed`. Una reserva confirmada futura o en curso impide finalizar la solicitud (409); puede cancelarse antes de comenzar o completarse cuando termine. Si la confirmada ya terminó, se marca `completed` junto con la solicitud. Una reserva ya terminal conserva su estado e historial.

Marcar únicamente la reserva como realizada no finaliza automáticamente la solicitud. Su finalización posterior conserva el aviso histórico de 4B. Si falla ese aviso, se revierten solicitud, reserva y evento juntos. Un trigger adicional impide cerrar directamente por SQL una solicitud que aún tenga reservas activas.

## Disponibilidad y zonas horarias

Se consultan exclusivamente `provider_availability_settings`, `provider_weekly_intervals` y `provider_blocked_dates`. Nunca se consultan tablas de disponibilidad heredadas.

La propuesta debe caber **completa en un solo intervalo semanal**, sin atravesar dos intervalos contiguos, una fecha bloqueada o medianoche. La ausencia de configuración y una semana sin atención impiden proponer. Crear y confirmar requieren servicio publicado, categoría activa, rol de prestador y permiso de cliente vigentes, siguiendo las reglas centrales existentes. Rechazar/cancelar/cerrar una reserva no requiere que el servicio continúe publicado.

El horizonte inicial se define en `config/reservations.php`: `horizon_days = 90`, configurable mediante despliegue/configuración, sin introducir variables de `.env`. Se permiten fechas civiles hasta hoy en la zona del prestador **más 90 días, inclusive**, con inicio estrictamente posterior al instante actual. Se conserva el rango de fechas 2000–2100 de 6A. La duración fija es 60 minutos civiles y transcurridos.

El navegador envía `date` (`AAAA-MM-DD`) y `time` (`HH:MM`), más la revisión de disponibilidad que presentó el formulario. No envía una zona confiable ni UTC. La zona procede del prestador; si la revisión cambió, el alta devuelve 409 para que el cliente vuelva a revisar el formulario. El formato visual nativo de los controles depende de la configuración del navegador; su valor enviado es canónico.

`ReservationTime` construye primero una representación civil sin zona y enumera los offsets IANA posibles alrededor de esa fecha. Exige exactamente un instante que, convertido de vuelta, reproduzca esa fecha y hora. También resuelve el fin civil y exige mismo día, mismo offset y exactamente 3600 segundos transcurridos. Rechaza horas inexistentes, ambiguas y tramos que crucen una transición. No elige silenciosamente uno de los dos offsets ni ajusta una hora que no existe.

Se almacenan `starts_at_utc`/`ends_at_utc` como cadenas UTC canónicas, `timezone`, `local_date`, `local_start_minute` y `availability_revision`. No hay casts de los instantes a la zona de la aplicación. La pantalla utiliza la fecha/hora/zona originales; las decisiones se muestran expresamente en UTC.

Casos probados: Nueva York, 10/03/2030 02:30 inexistente y 03/11/2030 01:30 ambigua; extremos ambiguos, transición de media hora en `Australia/Lord_Howe`, `Asia/Kathmandu`, `Etc/UTC`, cambios de día/mes/año y `Pacific/Kiritimati`. Hay cobertura HTTP de rechazo DST y conversiones concretas de ida y vuelta.

### Cambios posteriores

Una reserva confirmada conserva siempre sus instantes, zona, datos civiles e historial aunque se cambien zona, semana o bloqueos de 6A. No se mueve ni cancela automáticamente.

Para confirmar una propuesta pendiente, la zona actual debe coincidir exactamente con la histórica y la conversión debe reproducir los mismos instantes. Una zona distinta, incluso un alias con otra cadena, requiere cerrar la propuesta y preparar otra. Si solo cambió la semana o las fechas bloqueadas, la confirmación utiliza la configuración vigente: puede confirmar si todavía cabe, aunque la revisión sea posterior. Esta política evita reinterpretar una propuesta antigua.

## Migración aditiva 33 → 34

`2026_11_03_000000_create_service_reservations.php` crea solo dos tablas:

| Tabla | Datos y garantías |
| --- | --- |
| `service_reservations` | Solicitud/servicio/perfil/cliente, zona y hora civil, inicio/fin UTC, revisión de disponibilidad, clave idempotente, estado, versión, último actor/motivo y timestamps |
| `reservation_events` | Reserva, versión, estado anterior/nuevo, actor, motivo y fecha UTC; un evento por versión |

Las FKs son explícitas y restrictivas hacia las identidades centrales. Hay CHECKs de estados, rangos y tipos, fecha civil real, UTC canónico, duración de 3600 segundos y versión/revisión positivas. La unicidad `(client_user_id, service_inquiry_id, idempotency_key)` protege reenvíos. Un índice parcial permite una sola reserva `requested`/`confirmed` por solicitud; otro índice parcial soporta ocupación confirmada por perfil/inicio/fin. Las listas tienen índices por cliente/perfil e ID.

Los triggers de INSERT/UPDATE verifican que solicitud, servicio, perfil y cliente correspondan. Un alta debe comenzar como `requested`, por el cliente, en versión 1. La base rechaza cambios de identidad, hora, zona, intervalo o clave, reactivación de terminales, actores incompatibles y transiciones temporales incoherentes. El historial se añade automáticamente en la misma escritura; no depende de que el controlador recuerde insertarlo.

Los triggers de solapamiento para **INSERT y UPDATE** rechazan una confirmada que interseque otra del mismo perfil, aunque sea otro servicio. INSERT directo confirmado también está prohibido por la regla del alta inicial. Los intervalos son semiabiertos `[inicio, fin)`: 09:00–10:00 y 10:00–11:00 son válidos. Propuestas de solicitudes diferentes pueden coincidir mientras ninguna esté confirmada.

Se añaden triggers a `service_inquiries`, `services` y `provider_profiles` para impedir cierres con reservas activas o reasignaciones de las identidades relacionadas con un historial existente. No se alteran columnas, filas ni migraciones anteriores. Esta protección es aditiva y permanece inactiva para registros sin reservas.

`down()` elimina los triggers añadidos sobre tablas anteriores y las dos tablas nuevas, con sus índices/triggers. Se probó exclusivamente en memoria, incluido revertir y volver a aplicar conservando todas las columnas y filas anteriores. **No ejecutar rollback sobre una base persistente con reservas**: descartaría el módulo y su historial.

## Concurrencia, idempotencia y errores

SQLite no ofrece `SELECT FOR UPDATE`. El workflow obtiene su bloqueo real mediante un UPDATE condicional de `service_inquiries.status = status`, exigiendo `accepted`, sin cambiar sus timestamps. Después vuelve a comprobar identidad, versión y condiciones, y guarda dentro de la transacción. Las decisiones consumen estado y versión con compare-and-swap; un formulario antiguo devuelve 409.

La comprobación previa de ocupación mejora el mensaje, pero no es la garantía final. Los triggers y la escritura serializada de SQLite impiden que dos transacciones confirmen intervalos incompatibles. Las pruebas mantienen una confirmación sin commit en una conexión e intentan otra desde un PDO independiente; comprueban el conflicto y, tras el commit, la denegación por ocupación. También prueban SQL directo, altas concurrentes y reintento idempotente. Ambas conexiones usan una URI compartida `mode=memory&cache=shared`; `PRAGMA database_list` confirma ausencia de archivo físico.

La clave UUID se normaliza a minúsculas y se vincula a cliente, solicitud y fecha/hora/revisión de la propuesta. Un reenvío idéntico devuelve el registro original, incluso después del cierre o de un cambio de disponibilidad; nunca crea una reserva tardía. Reutilizarla con otros datos devuelve 409. Otra clave tampoco elude el límite de una reserva activa. Si la primera operación aún mantiene un bloqueo, el reintento puede recibir 409; repetir el mismo envío después del commit reconoce el original.

CSRF protege POST y todos los PATCH. Se permiten únicamente los campos públicos esperados; se rechazan IDs, propietario, estado, precio, UTC y datos de decisión manipulados. Creación: 5 intentos/minuto por cuenta. Decisiones: 15/minuto por cuenta, independientemente; 429 con `Retry-After`.

403: falta de participación/rol; 404: recurso inexistente; 409: estado, revisión, expiración, ocupación o conflicto de escritura; 422: formato/campos/DST/horizonte inválidos; 503: fallo temporal de lectura/escritura. HTML de validación conserva el flujo Laravel de redirección con errores; JSON utiliza 422. Se prueban 419 reales de CSRF.

Las excepciones de reservas no conservan una excepción SQL previa. El controlador también contempla consultas fallidas durante el renderizado Blade. Las pantallas de error utilizan un componente sin navegación ni consultas de contadores, para no fallar nuevamente mientras otra transacción bloquea solicitudes. No se exponen SQL ni datos privados, incluso con `app.debug = true`.

## Compatibilidad y archivos

`InquiryWorkflow` recibe únicamente el cierre conjunto antes de su compare-and-swap de finalización. No se cambian sus estados ni transiciones previas. `InquiryMessaging`, `InquiryReadTracking`, avisos, idempotencia y contadores anteriores permanecen intactos. La bandeja y el detalle de reservas no marcan mensajes como leídos.

No se usan ni enlazan `Cita`, `Profesionista`, `ProfesionistaDisponibilidad` o `profesionista_bloqueos`. Se probaron acceso heredado y correo coincidente sin vincular identidades, cambiar contraseñas, tokens, roles ni relaciones. Los hashes de `.env`, bases persistentes presentes, manifiestos/locks, 33 migraciones y servicios protegidos de 5A/5B/6A se verificaron sin cambios.

| Archivos | Cambio |
| --- | --- |
| Migración `2026_11_03_000000_create_service_reservations.php` | Dos tablas, FKs, CHECKs, índices y triggers |
| `app/Models/ServiceReservation.php`, `ReservationEvent.php`, `ProviderProfile.php`, `ServiceInquiry.php` | Modelos y relaciones |
| `app/Enums/ReservationStatus.php`, `app/Policies/ServiceReservationPolicy.php`, `app/Support/ReservationInput.php` | Estados, autorización y validación |
| `app/Services/ReservationWorkflow.php`, `ReservationTime.php`, `app/Exceptions/ReservationFailure.php` | Operaciones, resolución civil/UTC y errores seguros |
| `app/Services/InquiryWorkflow.php` | Cierre conjunto transaccional mínimo |
| `app/Http/Controllers/ServiceReservationController.php`, `routes/reservations.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php`, `config/reservations.php` | HTTP, rutas, frecuencia y horizonte |
| `resources/views/reservations/*`, `components/reservation-*.blade.php`, `components/inquiry-reservations.blade.php` | Pantallas y componentes |
| `resources/views/inquiries/show.blade.php`, `components/account-navigation.blade.php`, `public/css/reservations.css` | Integración y presentación responsive |
| `tests/Feature/Reservation*.php`, `tests/Support/ReservationFixtures.php` | Siete clases nuevas y fixtures |
| `tests/Feature/AvailabilityMigrationTest.php` | Acota su escenario histórico a 32→33, sin retirar ni modificar sus aserciones |
| `README.md`, este documento, `docs/validaciones/etapa-6b/*` | Guía y evidencias |

## Validación ejecutada

| Comando | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; autoload y package discovery correctos, lock intacto |
| `npm ci` | Código 0 |
| `npm audit --json` | Código 0; **0 vulnerabilidades** |
| `npm run build` | Código 0; sin cambios en entradas ni assets Vite anteriores |
| `php vendor/bin/phpunit --do-not-cache-result` | **288 pruebas, 3447 aserciones**, código 0 |

Las **247 pruebas anteriores** se conservan con todas sus aserciones. La reproducción del commit de origen arroja **2960**. En la rama nueva ejecutan **2961**, porque `ConversationInboxTest` valida cada consulta y el enlace autorizado de reservas añade una consulta de permisos. No se quitó ninguna aserción: las 41 pruebas nuevas aportan 486 y la comprobación dinámica anterior añade una. Total: **3447**.

Cobertura: permisos/roles/terceros/admin, CSRF, campos manipulados, 403/404/409/422/429/503, ciclo de vida, expiración y formularios antiguos, duración/intervalos/bloqueos/horizonte, múltiples servicios/prestadores, adyacencia y superposición, dos PDO simultáneos, SQL directo, idempotencia, DST y conversiones, cambio de disponibilidad, cierre de solicitudes, rollback de historial/avisos, separación heredada y preservación de datos 33→34.

Entorno: PHP 8.2.35, PHPUnit 11.5.24, Laravel 12.19.3, Node 24.19.0/npm 11.9.0. Toda operación automatizada de datos utilizó memoria. Composer se ejecutó en una copia 6B nueva sin `.env` ni red, reutilizando paquetes del lock de solo lectura y metadatos propios. Persisten avisos previos Flysystem/Pint y deprecaciones Sass/Bootstrap. No se actualizaron dependencias ni locks.

Revisión visual Chromium: **38 comprobaciones**, 17 estados a 1440×1000 y 390×844, más cuatro sin JavaScript. Incluye bandeja vacía/propuestas, solicitud aceptada, formulario normal/inválido, propuesta por ambos participantes, confirmadas, conflicto, cancelación, rechazo, realizada, expirada, acceso denegado, ausencia de horarios y error temporal. Se verificaron permisos de botones, zona/CSRF/controles nativos, menú móvil, ausencia de desbordamientos, errores JavaScript y peticiones externas. Se inspeccionaron capturas de formulario, validación y confirmación en escritorio/móvil.

Las pantallas se obtuvieron mediante peticiones Laravel y fixtures nuevos en memoria; Chromium revisó sus artefactos HTML estáticos. Las escrituras y respuestas HTTP se verifican en PHPUnit. **Windows/Laragon no se ejecutó en Linux** y queda pendiente de revisión independiente.

Evidencias: [resultados](validaciones/etapa-6b/resultados.json), [npm audit](validaciones/etapa-6b/npm-audit.json), [revisión visual](validaciones/etapa-6b/visual.json).

## Windows / Laragon / PowerShell: copia exclusiva y puerto 8009

No reutilizar ni modificar `tekora-etapa6a` u otros clones. Crear solamente **`C:\laragon\www\tekora-etapa6b`**. En PowerShell con PHP 8.2+ y SQLite, Composer y Node 22/24 compatibles con el lock:

```powershell
Set-Location C:\laragon\www
if (Test-Path -LiteralPath 'C:\laragon\www\tekora-etapa6b') {
    throw 'La carpeta 6B ya existe. No sobrescribir ni reutilizar clones anteriores.'
}
git clone --branch codex/etapa-6b-reservas-servicios --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa6b
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la copia nueva.' }
Set-Location C:\laragon\www\tekora-etapa6b
php --version
php --ini
php -m
composer install
if ($LASTEXITCODE -ne 0) { throw 'Falló Composer.' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'Falló npm ci.' }
npm.cmd audit
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas.' }
```

Esperado: **288 pruebas, 3447 aserciones**, auditoría limpia y build correcto. La suite no necesita `.env` ni SQLite físico; fuerza `:memory:`. El número de aserciones dinámicas puede depender de las consultas añadidas por la versión exacta de dependencias; no deben faltar pruebas o aserciones de seguridad.

Para la web se necesita un **respaldo consistente y exclusivo de prueba de la SQLite 6A con 33 migraciones**, cuentas de desarrollo y la misma clave de aplicación de esa copia. Obtenerlo con la aplicación detenida o con un respaldo SQLite consistente, considerando WAL. No copiar parcialmente una base activa. El siguiente bloque crea otra copia GUID en TEMP y usa variables solamente en esta sesión: no escribe `.env`, no regenera claves y no cambia originales.

```powershell
Set-Location C:\laragon\www\tekora-etapa6b
$Origen6A = Read-Host 'Ruta del respaldo consistente de prueba de SQLite 6A (33 migraciones)'
if (-not (Test-Path -LiteralPath $Origen6A -PathType Leaf)) { throw 'No existe el respaldo.' }
$Base6B = Join-Path ([IO.Path]::GetTempPath()) ('tekora-6b-' + [guid]::NewGuid().ToString() + '.sqlite')
Copy-Item -LiteralPath $Origen6A -Destination $Base6B
$Clave6A = Read-Host 'APP_KEY existente de esa copia 6A (no generar otra)' -AsSecureString
$env:APP_KEY = [Net.NetworkCredential]::new('', $Clave6A).Password
Remove-Variable Clave6A
if ([string]::IsNullOrWhiteSpace($env:APP_KEY)) { throw 'Falta la clave existente.' }
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8009'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-6b-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $Base6B
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa6b_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
Write-Host "Base temporal exclusiva de revisión: $Base6B"
$Revisor6B = Join-Path ([IO.Path]::GetTempPath()) ('tekora-6b-check-' + [guid]::NewGuid().ToString() + '.php')
$Codigo6B = @'
<?php
$pdo = new PDO('sqlite:'.getenv('DB_DATABASE'));
$aplicadas = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
$esperadas = array_map(fn ($p) => basename($p, '.php'), glob('database/migrations/*.php'));
$esperadas = array_values(array_filter($esperadas, fn ($m) => $m < '2026_11_03_000000'));
sort($esperadas);
if (count($aplicadas) !== 33 || $aplicadas !== $esperadas) {
    fwrite(STDERR, 'La copia no corresponde exactamente a las 33 migraciones de 6A.');
    exit(1);
}
echo 'Copia 6A verificada: 33 migraciones.';
'@
[IO.File]::WriteAllText($Revisor6B, $Codigo6B, [Text.UTF8Encoding]::new($false))
php $Revisor6B
$Revision6B = $LASTEXITCODE
Remove-Item -LiteralPath $Revisor6B
if ($Revision6B -ne 0) { throw 'Detenerse: la copia no es la base 6A esperada.' }
php artisan migrate:status
if ($LASTEXITCODE -ne 0) { throw 'No se pudo comprobar el estado.' }
php artisan migrate --pretend
if ($LASTEXITCODE -ne 0) { throw 'Falló la revisión de la migración.' }
# Solo create_service_reservations debe estar pendiente; operar únicamente sobre $Base6B.
php artisan migrate
if ($LASTEXITCODE -ne 0) { throw 'La migración de la copia temporal falló.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8009
```

Abrir `http://127.0.0.1:8009`. El servidor debe arrancar desde esta misma terminal para heredar la configuración; el Apache/Laragon ya iniciado no la hereda. Al finalizar, detenerlo y cerrar PowerShell para descartar variables y clave de sesión. No ejecutar `migrate:fresh`, `db:wipe`, rollback destructivo ni seeds generales sobre bases persistentes. La migración y los formularios operan únicamente sobre `$Base6B`.

### Recorrido manual

1. Iniciar sesión con prestador central, publicar servicio y horarios de 6A con zona visible. Con cliente distinto, enviar solicitud y aceptarla como prestador.
2. Desde la solicitud aceptada, proponer mañana un horario que quepa completo en un intervalo. Confirmar zona y duración 60 minutos; antes de la decisión debe decir «Horario propuesto», sin ocupar el turno.
3. Reenviar el mismo formulario: conservar la reserva original. Cambiar datos conservando su clave: 409. Otra clave para la misma solicitud activa: 409.
4. Revisar detalle y «Mis reservas» como cliente/prestador. Una cuenta dual debe ver ambas experiencias, sin cerrar sesión. Una cuenta ajena o administrador sin participación no debe consultar ni decidir reservas privadas.
5. Confirmar como prestador. Reenviar un formulario de versión anterior: 409, sin cambiar la decisión. Cancelar antes del inicio como cliente o prestador; rechazar una propuesta; crear otra sin borrar las anteriores.
6. Preparar dos propuestas coincidentes antes de confirmar, para clientes y servicios diferentes del mismo prestador. Confirmarlas desde dos pestañas: solo una gana, la otra responde 409. Probar consecutivas y otro prestador: deben poder confirmarse sin conflicto de ocupación.
7. Probar día sin atención, hora que rebasa el intervalo, fecha bloqueada, fecha pasada, horizonte mayor de 90 días, cruce de medianoche y formulario inválido. Ausencia de horarios debe impedir abrir una propuesta utilizable.
8. Cambiar zona o bloquear la fecha después de proponer: impedir una nueva confirmación incoherente. Cambiar la disponibilidad después de confirmar: conservar fecha/hora/zona e instantes históricos.
9. Finalizar la solicitud con propuesta pendiente: cancelarla en la misma operación. Con confirmada futura/en curso: 409. Tras su fin, marcar realizado o finalizar conjuntamente; conservar historial. No alterar el reloj de Windows para simular estos casos: las pruebas de memoria cubren los límites temporales y DST.
10. Verificar CSRF ausente/incorrecto, campos internos manipulados y límites 5/15 por minuto; errores sin SQL. Revisar escritorio, móvil, navegación y formulario sin JavaScript.
11. Comprobar solicitudes, notificaciones, mensajes idempotentes, contadores/progreso de lectura y editor/presentación pública de 6A. El acceso heredado, sus credenciales, citas y disponibilidad deben seguir separados.

## Riesgos y limitaciones

La garantía de concurrencia depende de SQLite con FKs habilitadas y estos triggers; otro motor necesita un diseño equivalente. SQLite serializa escrituras globalmente: operaciones de prestadores distintos pueden recibir un conflicto temporal de bloqueo y requerir reintento, aunque su ocupación no interfiera. Las pruebas de dos PDO cubren las ventanas críticas en memoria; Laragon debe validar también su SQLite y configuración local.

El control de disponibilidad y autorización se realiza en el servicio bajo bloqueo; el esquema refuerza identidad, estado, temporalidad, historial y solapamiento, pero SQL externo no puede resolver zonas IANA ni sustituir las policies. No escribir reservas mediante scripts que eludan el workflow.

Las reglas IANA dependen de la versión de tzdata de PHP. Una nueva confirmación vuelve a verificar la conversión; las confirmadas nunca se recalculan. No se aceptan horas ambiguas/inexistentes, medianoche ni cambios de offset dentro del intervalo. Quedan fuera duraciones variables, capacidad mayor de uno, reprogramación de la misma reserva, recordatorios y expiración automática.

Las FKs y el historial inmutable impiden eliminar/reasignar identidades con reservas; una futura política de retención o eliminación deberá contemplarlo. Un rol revocado conserva el historial pero pierde acceso según las policies vigentes. No se agregan facultades administrativas para resolver casos de cuentas desactivadas.

Windows/Laragon y la revisión del PR quedan pendientes del usuario. Esta entrega es únicamente un PR en borrador: **no fusionar, desplegar ni modificar instalaciones reales** antes de esa revisión independiente.
