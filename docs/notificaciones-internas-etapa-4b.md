# Etapa 4B: notificaciones internas de solicitudes

Entrega del 28 de octubre de 2026, horario de Ciudad de México. Rama `codex/etapa-4b-notificaciones-internas`, creada desde `origin/main` en `da1c65718c10b9cb055b1c0fa8bdb93e03cbdaff`, con el PR #6 confirmado como fusionado. El PR de esta etapa se prepara en borrador y no se integra automáticamente.

## Funcionalidad

La cuenta central recibe avisos cuando otra persona crea una solicitud o realiza una decisión autorizada. `/cuenta/notificaciones` muestra doce avisos por página, ordenados por creación descendente y, en caso de empate, por ID descendente. Incluye evento en español, título constante, fecha en Ciudad de México y estado de lectura. El menú central incluye un contador de no leídas de la cuenta autenticada.

Una cuenta Cliente/Prestador recibe ambas experiencias en la misma bandeja. Consultar la bandeja o abrir la solicitud no marca automáticamente un aviso como leído. El botón de lectura usa POST y CSRF. No hay actualización automática: el contador se consulta al renderizar la navegación.

| Operación confirmada | Evento persistido | Destinatario |
| --- | --- | --- |
| Crear solicitud | `created` | User propietario del perfil del servicio |
| Aceptar | `accepted` | User cliente solicitante |
| Rechazar | `rejected` | User cliente solicitante |
| Cancelar | `cancelled` | User propietario del perfil del servicio |
| Finalizar | `completed` | User cliente solicitante |

El autor nunca recibe el aviso de su propia acción. Las reglas y los estados existentes de `ServiceInquiry` no cambian: por ejemplo, cancelar sigue requiriendo el estado `pending`, y finalizar requiere `accepted`.

## Persistencia y decisión sobre Laravel Notifications

Se evaluó el canal `database` de Laravel, disponible mediante el trait `Notifiable` de `User`. Su tabla genérica guarda destinatario polimórfico y datos JSON. Esta etapa necesita únicamente cinco eventos, claves foráneas explícitas a User/solicitud y unicidad por evento. Usar ese canal exigiría personalizar su persistencia para obtener estas mismas restricciones.

Se elige una tabla específica `inquiry_notifications` y el modelo `InquiryNotification`, sin canales de envío ni colas. `Notifiable` permanece intacto; `User::inquiryNotifications()` es una relación separada de la relación genérica `notifications()` del framework. Las futuras notificaciones Laravel no aparecen automáticamente en esta bandeja.

| Campo | Garantía |
| --- | --- |
| `id` | Clave primaria |
| `user_id` | FK obligatoria a `users`, con eliminación restringida |
| `service_inquiry_id` | FK obligatoria a `service_inquiries`, con eliminación restringida |
| `event` | Uno de los cinco valores admitidos; enum PHP y CHECK de SQLite |
| `created_at` | Fecha de creación asignada por el servidor |
| `read_at` | Nullable; se establece solo en la primera lectura |

La restricción única `(user_id, service_inquiry_id, event)` impide repetir un aviso aunque se intente insertar fuera del workflow. Hay índices para listar por usuario y contar no leídas. `InquiryNotification` relaciona `user()` e `inquiry()` y ofrece el scope `unread()`.

No hay payload JSON, URL almacenada, título tomado del usuario ni copia de nombres, servicio, mensajes privados, correos, teléfonos, direcciones, contraseñas o tokens. Los textos se derivan de `InquiryNotificationEvent`. El modelo bloquea asignación masiva y limita los campos serializables: incluso con relaciones privadas cargadas, estas no entran en su JSON. La lista solo carga de la solicitud los identificadores necesarios para la policy.

## Migración y compatibilidad

Nueva migración: `2026_10_29_000000_create_inquiry_notifications_table.php`. Crea únicamente la tabla y sus restricciones; `down()` elimina únicamente esa tabla. La instalación pasa de **29 a 30 migraciones**. Los 29 archivos históricos permanecen idénticos y no hay seeds nuevos.

No se crean avisos retroactivos. Una solicitud anterior sigue funcionando y una nueva transición exitosa sí genera su aviso. Reenviar una solicitud activa antigua devuelve la original, sin inventar un aviso de creación. Pausar o editar el servicio conserva los avisos y el acceso privado continúa sujeto a la policy.

No hay relaciones con `Profesionista`, `Cita`, disponibilidades o solicitudes de citas. El guard antiguo sigue separado, incluso cuando coincide el email con una identidad central. No se vinculan cuentas ni se alteran contraseñas, roles, citas o tokens existentes.

La reversión técnica se prueba únicamente en memoria. Eliminar esta tabla en una instalación con avisos borraría su historial y dejaría incompatible el código 4B; no se recomienda hacerlo como procedimiento de actualización. El despliegue debe aplicar la migración antes de habilitar el código que consulta la nueva tabla, usando una ventana de mantenimiento según el procedimiento de cada instalación.

## Consistencia transaccional y concurrencia

`InquiryWorkflow` guarda el aviso dentro de la transacción existente y en la misma conexión que la solicitud. No utiliza observers, GET, eventos del navegador, `afterCommit`, colas ni procesos externos para persistir avisos.

1. Al crear, conserva la validación de visibilidad, autorización e índice único de solicitud activa. Solo después de insertar una solicitud nueva guarda `created`. Retornar la activa existente no escribe avisos.
2. Al decidir, conserva la autorización y el compare-and-swap sobre el estado de origen. Solo si se modifica exactamente una fila guarda el evento correspondiente.
3. El destinatario se obtiene de las relaciones persistidas y el evento de la decisión fijada por el servidor. Los parámetros del formulario no asignan destinatarios, eventos, fechas o enlaces.
4. Si falla el aviso, se propaga el error y se revierte también la creación o decisión. Una transacción exterior que se revierta deshace ambas escrituras.
5. Las protecciones y reintentos de concurrencia anteriores permanecen. El índice único del aviso añade una segunda garantía. Una escritura solapada en SQLite puede responder con el conflicto recuperable existente; reintentar nunca duplica el aviso de una operación ya confirmada.

Los errores 403, 409, 422 y CSRF 419 no agregan avisos. Repetir una transición procesada mantiene el 409 previo y no cambia fechas ni crea otro aviso. Una nueva solicitud posterior al cierre tiene otro ID y sus propios avisos.

## Rutas y permisos

Todas las rutas nuevas pertenecen al grupo web y requieren `auth:web`:

| Método y ruta | Nombre | Comportamiento |
| --- | --- | --- |
| GET `/cuenta/notificaciones` | `notifications.index` | Bandeja paginada exclusivamente de la cuenta actual |
| POST `/cuenta/notificaciones/{notification}/leer` | `notifications.read` | Marca únicamente un aviso propio como leído; redirige con 303 |

No se ofrece detalle independiente de avisos ni escritura mediante GET. La búsqueda del aviso ocurre dentro de `User::inquiryNotifications()`; un ID ajeno o inexistente devuelve 404, incluido para administradores. Un usuario sin roles puede consultar su bandeja y marcar sus avisos; eso no concede permiso para leer una solicitud.

La lectura ejecuta un UPDATE con `user_id`, ID y `read_at IS NULL`: dos intentos conservan la primera fecha. Ignora fechas, tipos, destinatarios y URLs enviados en el cuerpo. GET valida `page` como entero positivo y nunca altera lectura.

Cada enlace se construye mediante `route('inquiries.show', ...)` solo si `ServiceInquiryPolicy::view` lo permite en ese momento. Al perder un rol, permanece el aviso genérico y aparece el texto de detalle no disponible. Escribir manualmente la URL tampoco evita la policy. No existe excepción administrativa ni permisos derivados de poseer un aviso.

Blade escapa los textos. Los estados leído/no leído tienen etiquetas visibles además de color, fechas semánticas y controles adaptados a móviles. Se reutilizan el layout de cuenta, navegación, mensajes, paginación y componente de fecha existentes; se añade una hoja CSS pequeña, sin cambiar el bundle ni la identidad visual.

## Pruebas y resultados

La suite de referencia pasó antes de modificar código: **145 pruebas, 1591 aserciones**. La suite final pasa con **169 pruebas, 1867 aserciones**: 24 pruebas y 276 aserciones adicionales.

Se ejecutó también la selección de las **145 pruebas anteriores**, excluyendo únicamente las cuatro clases nuevas `InquiryNotification*`: siguen pasando con **1591 aserciones**. No se eliminan pruebas ni aserciones anteriores. Se adapta únicamente `InquiryMigrationTest`: el contador total pasa a 30 y la reversión de su escenario 28→actual revierte las dos tablas aditivas, exclusivamente en memoria. Conserva sus comprobaciones de datos, relaciones y reversibilidad.

Cobertura nueva:

- Los cinco eventos, destinatario central, ausencia de aviso al autor y parámetros manipulados.
- Reenvío pendiente/aceptado, nuevas solicitudes tras cierre y decisiones repetidas.
- Errores 403, 409 y 422 sin avisos; CSRF realmente activado para lectura.
- Fallo de persistencia del aviso y rollback exterior de creación/decisión.
- Dos PDO distintos sobre una misma URI SQLite en memoria, con transacciones solapadas, bloqueo, reintento, decisión obsoleta e índice único. La secuencia se controla de forma determinista; no depende de lanzar procesos al azar.
- Aislamiento entre usuarios, administrador sin acceso ajeno, roles revocados, cuenta dual, contador propio, lectura idempotente y dos lecturas obsoletas.
- Paginación/orden, servicios pausados/editados, escape HTML, serialización y ausencia de datos privados.
- Acceso heredado separado y migración 29→30/reversión solo del nuevo esquema, preservando filas y relaciones anteriores; FKs y valores de evento inválidos rechazados.

| Verificación solicitada | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; lock sin cambios, autoload y package discovery correctos |
| `npm ci` | Código 0, instalación limpia desde el lock de 4A |
| `npm audit` y `npm audit --json` | Código 0, **cero vulnerabilidades** en auditoría completa |
| `npm run build` | Código 0; los mismos assets CSS/JS que 4A |
| `php vendor/bin/phpunit --do-not-cache-result` | **169 / 1867**, código 0 |
| Selección de las pruebas anteriores | **145 / 1591**, código 0 |

Entorno: PHP 8.2.35, PHPUnit 11.5.24 y Node 24.19.0/npm 11.9.0. Composer se ejecutó en copia sin `.env`, con metadatos independientes y los paquetes ya instalados del lock montados de solo lectura; no se descargó de nuevo el vendor completo. La prueba no modifica el vendor original. Permanecen sus avisos de resolución ambigua de clases Flysystem/Pint, sin errores de instalación. Sass/Bootstrap mantiene sus deprecaciones anteriores.

La revisión visual en Chromium cubre **9 estados × 2 tamaños = 18 renderizados**: vacío, no leídas, mezcla, leídas, navegación dual, rol revocado sin enlaces, página 403 y dos páginas de resultados. Escritorio 1440×1000 y móvil 390×844, sin errores JavaScript, tráfico externo ni desbordamientos horizontales; menú móvil y Escape comprobados. Se renderizaron rutas reales con fixtures y SQLite en memoria, y se inspeccionaron capturas de escritorio, móvil, menú y permisos revocados. La escritura/autorización HTTP se comprueba con PHPUnit; la revisión visual usa HTML renderizado estático. Laragon/Windows queda pendiente de revisión local.

Evidencias: [resultados](validaciones/etapa-4b/resultados.json), [auditoría completa npm](validaciones/etapa-4b/npm-audit.json) y [revisión visual](validaciones/etapa-4b/visual.json). Los hashes de `.env`, base persistente, manifiestos, locks y las 29 migraciones históricas se comprobaron sin cambios; no se publican valores privados.

## Archivos

| Archivo | Cambio |
| --- | --- |
| `app/Enums/InquiryNotificationEvent.php` | Eventos, textos constantes y experiencia destinataria |
| `app/Models/InquiryNotification.php` | Persistencia, relaciones, lectura y serialización limitada |
| `app/Models/User.php` | Relación `inquiryNotifications()` |
| `app/Services/InquiryWorkflow.php` | Inserción transaccional tras cada operación nueva exitosa |
| `app/Http/Controllers/InquiryNotificationController.php` | Bandeja propia y lectura atómica |
| `database/migrations/2026_10_29_000000_create_inquiry_notifications_table.php` | Migración aditiva 30 |
| `routes/notifications.php`, `routes/web.php` | Dos rutas protegidas y carga del grupo |
| `resources/views/notifications/index.blade.php` | Bandeja, estados, enlaces y formularios CSRF |
| `resources/views/components/account-navigation.blade.php` | Enlace y contador |
| `public/css/notifications.css` | Presentación responsive con estilos existentes |
| `tests/Feature/InquiryNotificationsTest.php` | Eventos, privacidad, aislamiento y experiencia web |
| `tests/Feature/InquiryNotificationConcurrencyTest.php` | Dos conexiones, duplicados y lectura |
| `tests/Feature/InquiryNotificationCsrfTest.php` | CSRF real y autorización independiente |
| `tests/Feature/InquiryNotificationMigrationTest.php` | Actualización, reversión y restricciones en memoria |
| `tests/Feature/InquiryMigrationTest.php` | Mantener escenario anterior con 30 migraciones |
| `README.md`, esta guía y `docs/validaciones/etapa-4b/*.json` | Entrega y evidencias |

## Validación en Laragon / Windows con PowerShell

Primero clonar una carpeta nueva y verificar PHP 8.2+, sus extensiones SQLite y Node 22/24 compatibles con los locks. No ejecutar estos pasos en la instalación original:

```powershell
Set-Location C:\laragon\www
git clone --branch codex/etapa-4b-notificaciones-internas --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa4b
Set-Location .\tekora-etapa4b
php --version
php --ini
php -m
composer install
npm.cmd ci
npm.cmd audit
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
```

La suite no necesita `.env` ni una base física: su bootstrap fuerza SQLite `:memory:` y un contexto de pruebas separado. No ejecutar seeds para pasar las pruebas.

Para la revisión web, preparar **esta copia aislada** con su entorno local ya configurado y una copia de desarrollo de la base 4A. Conservar la `APP_KEY` de esa copia; no regenerarla ni editar el entorno de la instalación original. No añadir entorno, claves ni bases al control de versiones. La base debe tener las 29 migraciones anteriores aplicadas y cuentas de prueba centrales separadas para cliente y prestador.

El siguiente ejemplo toma la base de desarrollo que ya se colocó dentro de la copia y crea otro archivo fuera del repositorio. Las variables solo afectan esta sesión PowerShell. Usar `artisan serve` desde la misma sesión; un servidor Apache de Laragon ya iniciado puede no heredar estas variables.

```powershell
# Ejecutar desde tekora-etapa4b, una vez preparado su entorno local aislado.
$TekoraCopia = (Get-Location).Path
$BaseDesarrollo = Join-Path $TekoraCopia 'database\database.sqlite'
if (-not (Test-Path -LiteralPath $BaseDesarrollo)) {
    throw 'Falta la copia de desarrollo de SQLite. No apuntar a la instalación original.'
}
$BasePrueba = Join-Path ([IO.Path]::GetTempPath()) ('tekora-4b-' + [guid]::NewGuid().ToString() + '.sqlite')
Copy-Item -LiteralPath $BaseDesarrollo -Destination $BasePrueba
$env:APP_ENV = 'local'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-4b-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $BasePrueba
$env:DB_URL = ''
$env:MAIL_MAILER = 'array'
Write-Host "Base exclusiva de esta revisión: $BasePrueba"
php artisan migrate:status
php artisan migrate --pretend
# Revisar que solo esté pendiente la migración de inquiry_notifications.
php artisan migrate
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8001
```

No usar `migrate:fresh`, `db:wipe`, rollback, seeds generales ni regeneración de claves. Los comandos `migrate` de este ejemplo apuntan exclusivamente al archivo nuevo de prueba. Detener la aplicación de origen antes de preparar la copia de SQLite; si utiliza WAL, obtener una copia consistente mediante su procedimiento de respaldo, no copiar un archivo activo de forma parcial. Al terminar el servidor, cerrar esta sesión PowerShell para descartar sus variables.

Recorrido manual en `http://127.0.0.1:8001`:

1. Iniciar sesión con una cuenta sin avisos: comprobar estado vacío y enlace del menú, también en móvil.
2. Cliente solicita un servicio publicado de otro User. El prestador recibe una sola «Nueva solicitud» y contador; el cliente no recibe su propio aviso. Reenviar conserva el envío y contador originales.
3. Prestador acepta y finaliza: cliente recibe ambos avisos. En solicitudes diferentes probar rechazo y cancelación del cliente. Repetir decisiones no agrega avisos.
4. Abrir la bandeja y detalle: los avisos siguen sin leer. Marcar uno: aparece la fecha y baja el contador; repetir el POST no modifica esa primera fecha.
5. Con cuenta dual, comprobar avisos enviados/recibidos en la misma bandeja. Con más de doce avisos, revisar paginación y contador total de no leídas.
6. Pausar/editar el servicio y comprobar historial y enlaces autorizados. Probar revocación de rol únicamente mediante los mecanismos autorizados en la copia: el aviso permanece, el enlace desaparece y el detalle responde 403.
7. Con otra cuenta, intentar POST de lectura de un ID ajeno: 404. Con token ausente/incorrecto: 419. Invitado y guard profesional anterior no acceden a la bandeja central.
8. Confirmar que citas, disponibilidades y acceso MindBloom anterior siguen funcionando sin avisos derivados de esas operaciones. Revisar escritorio/móvil, menú, mensajes de éxito y fechas.

## Limitaciones y pendientes

Solo se generan avisos de operaciones posteriores a esta migración; no hay backfill, preferencias, borrado, tiempo real ni canales externos. Las escrituras directas que omitan `InquiryWorkflow` no generan avisos: mantener ese servicio como entrada de operaciones.

Se prioriza consistencia: si no se puede guardar el aviso, tampoco se confirma la solicitud o decisión. Los bloqueos de SQLite se siguen comunicando como conflicto recuperable. Las claves foráneas restringen la eliminación física del historial; una futura política de retención/baja de cuentas deberá contemplarlas explícitamente.

La auditoría npm está limpia en la fecha de esta entrega, con exactamente las dependencias de 4A. No equivale a una auditoría Composer ni a ausencia de futuras alertas. No se implementan correo, chat, SMS, WhatsApp, push, WebSockets, pagos, reservas ni calendario.
