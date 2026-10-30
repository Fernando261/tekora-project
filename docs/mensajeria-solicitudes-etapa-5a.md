# Etapa 5A: mensajería privada por solicitud

Entrega del 30 de octubre de 2026, horario de Ciudad de México. Rama `codex/etapa-5a-mensajeria-solicitudes`, creada desde `origin/main` en `48cd269ec81c477a5fd68c6300d5af14fb65923f`, con el PR #7 confirmado como fusionado. Entrega en PR en borrador, sin integración automática ni despliegue.

## Funcionamiento

Cada `ServiceInquiry` incorpora una conversación privada de texto en su detalle existente. La necesidad inicial se conserva en su sección anterior y no se transforma en un mensaje retroactivo. Cada mensaje posterior muestra Cliente/Prestador, nombre del autor, indicación «tú» y fecha en horario de Ciudad de México. Los saltos de línea se conservan y HTML se muestra como texto escapado.

| Estado vigente de la solicitud | Lectura autorizada | Mensajes nuevos |
| --- | --- | --- |
| `pending` | Sí | Sí |
| `accepted` | Sí | Sí |
| `rejected` | Sí | No |
| `cancelled` | Sí | No |
| `completed` | Sí | No |

Pausar o editar la publicación no cierra la solicitud ni elimina su historial. Las transiciones y los cinco avisos de 4B conservan sus reglas. Los mensajes no generan avisos, no marcan notificaciones como leídas y no modifican la necesidad inicial ni las fechas de la solicitud.

No hay WebSockets, actualización automática, estados de lectura de mensajes, archivos, correo, llamadas, pagos, contratos, reservas o calendario. Se consulta el contenido nuevo al recargar la página.

## Arquitectura, tabla y relaciones

La identidad sigue siendo `User`, guard `web`. El modelo `ServiceInquiryMessage` pertenece a una solicitud y a un autor central. `ServiceInquiry::messages()` permite consultar exclusivamente el historial de esa solicitud.

Nueva migración aditiva: `2026_10_30_000000_create_service_inquiry_messages_table.php`. Pasa de **30 a 31 migraciones** y crea únicamente `service_inquiry_messages`:

| Campo | Uso |
| --- | --- |
| `id` | Clave primaria |
| `service_inquiry_id` | FK obligatoria a `service_inquiries`, eliminación restringida |
| `author_user_id` | FK obligatoria a `users`, eliminación restringida |
| `idempotency_key` | UUID normalizado a minúsculas, identifica una petición de envío |
| `body` | Texto del mensaje validado por el servidor |
| `created_at` | Fecha asignada por el servidor; no hay fecha de edición |

Índices:

- Único `(service_inquiry_id, author_user_id, idempotency_key)`, denominado `inquiry_messages_one_submission`.
- `(service_inquiry_id, created_at, id)`, denominado `inquiry_messages_conversation_order`, para recuperar páginas en orden con desempate por ID.

`ServiceInquiryMessage::inquiry()` y `author()` usan FKs explícitas; no hay relaciones polimórficas ni referencias a `Profesionista` o `Cita`. La asignación masiva está bloqueada. La serialización se limita a los campos del mensaje: no incorpora relaciones cargadas ni la clave de idempotencia. El endpoint web devuelve una redirección, sin exponer un listado JSON independiente.

No se modifican las 30 migraciones históricas, no hay seeds ni mensajes retroactivos. `down()` elimina solo la tabla nueva; se prueba exclusivamente en memoria. Aplicarlo sobre una instalación con conversaciones borraría ese historial y dejaría incompatible el código 5A: no se propone como procedimiento operativo de actualización.

## Rutas y autorización

| Método y ruta | Nombre | Comportamiento |
| --- | --- | --- |
| GET `/cuenta/solicitudes/{inquiry}` | `inquiries.show` | Detalle privado existente con la conversación paginada |
| POST `/cuenta/solicitudes/{inquiry}/mensajes` | `inquiries.messages.store` | Envío validado; éxito o repetición reconocida redirige con 303 |

Ambas requieren `auth:web`. No hay endpoints independientes para leer mensajes de otra solicitud, editar o borrar mensajes.

`ServiceInquiryPolicy::sendMessage()` reutiliza la autorización de `view`: cliente propietario con su permiso de cliente vigente o prestador propietario con su permiso de prestador vigente. Tener un rol administrativo no concede acceso a conversaciones ajenas; una cuenta dual conserva una sola identidad. Si pierde el rol necesario, tampoco puede leer o reenviar un mensaje anterior para evitar esa restricción.

El autor procede de la sesión y la solicitud de la ruta autorizada. El navegador no asigna remitente, destinatario, otra solicitud, roles, estados, fechas, eventos o adjuntos. Los campos de identidad/propiedad protegidos se rechazan con 422. Se admite únicamente el cuerpo validado y la clave de idempotencia. La autorización se vuelve a comprobar dentro de `InquiryMessaging`, además de la comprobación del formulario.

## Validación, privacidad y errores

El cuerpo admite de 1 a 2000 caracteres, incluidos caracteres Unicode. Se eliminan espacios de los extremos y se normalizan CRLF/CR a LF antes de validar. Se rechazan cuerpos vacíos, compuestos solo por espacios, arrays y textos demasiado largos. La clave debe ser un UUID válido.

Blade utiliza `{{ }}` para el cuerpo y los nombres: no se ejecuta HTML, no se interpreta Markdown y no se convierten enlaces en elementos activos. Los mensajes solo se consultan después de autorizar el detalle. No entran en el catálogo público, fichas de prestadores ni payloads de notificaciones.

El envío tiene CSRF real y tres límites acumulativos: **10 intentos por minuto por cuenta/solicitud**, **60 por hora por cuenta** y **30 por minuto por IP**. Los reintentos también consumen estos límites. El 429 conserva `Retry-After` y muestra un mensaje seguro.

| Respuesta | Motivo |
| --- | --- |
| 303 | Mensaje guardado o envío anterior reconocido |
| 403 | No es participante autorizado con los roles vigentes |
| 404 | Solicitud inexistente |
| 409 | Solicitud cerrada, conflicto de escritura o reutilización de clave con otro texto |
| 419 | CSRF ausente o incorrecto |
| 422 | Cuerpo, clave, campos protegidos o cursor inválidos |
| 429 | Límite de frecuencia |
| 503 | Fallo de persistencia sin confirmar el mensaje |

No se registra el cuerpo en logs de aplicación. Los errores SQL se convierten en excepciones con texto constante, sin adjuntar la excepción anterior ni sus bindings. Esto incluye `QueryException` y `DeadlockException`/PDO de transacciones anidadas. Las excepciones específicas implementan `ShouldntReport` y el controlador produce respuestas genéricas 409/503. No se devuelve SQL, el cuerpo enviado o la clave en esos errores.

## Idempotencia

Cada formulario recibe un UUID nuevo generado en el servidor. El campo oculto se conserva ante validación fallida; enviar dos veces el mismo formulario conserva la misma clave. La clave identifica la petición, no concede permisos ni identifica al autor.

1. La sesión determina el autor y se autoriza la solicitud actual.
2. La búsqueda de una petición anterior se limita a esa solicitud, ese autor y esa clave normalizada.
3. Si ya existe con el mismo texto normalizado, se devuelve el mensaje original sin insertar ni modificar fechas.
4. Si la clave ya se usó con otro texto, responde 409 sin reemplazar el contenido anterior.
5. La restricción única impide duplicados también ante dos escritores. Tras un conflicto recuperable, el reintento debe conservar cuerpo y clave para reconocer el envío original.

Un reintento de un envío exitoso después del cierre puede devolver 303 para confirmar el mensaje anterior; **no crea un mensaje en estado terminal**. Si el usuario pierde su permiso, responde 403 incluso para esa repetición.

La misma persona puede enviar intencionalmente el mismo texto usando un formulario nuevo con otra clave. Tampoco se confunden claves iguales de autores o solicitudes distintas. No se deduplica por contenido y no hay caducidad de claves mientras exista el mensaje. No se ofrecen operaciones de borrado que debiliten esta garantía.

## Consistencia con cambios de estado en SQLite

`InquiryMessaging::send()` usa una transacción en la misma conexión que la solicitud. Conserva `InquiryWorkflow` sin modificar su código y no depende de un bloqueo `SELECT FOR UPDATE`, que SQLite no ofrece.

Después de autorizar y comprobar una repetición, ejecuta un UPDATE condicional sobre la fila de la solicitud: `SET status = status WHERE id = ? AND status IN ('pending', 'accepted')`. Este UPDATE adquiere el bloqueo de escritura de SQLite, exige que el estado siga admitiendo mensajes y **no modifica el valor del estado ni `updated_at`**. Se vuelve a autorizar bajo ese bloqueo y después se inserta el mensaje. El bloqueo se conserva hasta terminar la transacción.

Las decisiones existentes también necesitan escribir esa fila. Así, cierre e inserción quedan serializados:

- Si el cierre se confirma primero, el envío solapado recibe conflicto y su reintento encuentra un estado terminal; no guarda un mensaje tardío.
- Si el mensaje obtiene primero el bloqueo, se confirma antes del cierre. La decisión puede reintentarse después y el mensaje ya confirmado queda en el historial.
- Dos envíos con la misma clave no pueden confirmar dos mensajes. Una petición distinta puede reintentarse después del bloqueo y conservar ambos mensajes legítimos.

Se mantienen tres intentos transaccionales de Laravel ante conflictos reconocidos. Cuando el bloqueo sigue ocupado, se responde con un 409 seguro; no se promete que toda operación concurrente termine inmediatamente en éxito. Una falla del INSERT revierte su transacción, y una transacción exterior revertida también elimina el mensaje y deja disponible su clave.

## Paginación

El detalle recupera **20 mensajes**, más una fila de anticipación, con cursor sobre `(created_at, id)` y la condición obligatoria de solicitud. No ejecuta COUNT del historial ni usa offsets que crezcan con el tamaño de la conversación; una prueba verifica además el índice elegido por SQLite.

La primera vista muestra los veinte más recientes, presentados de antiguo a nuevo dentro de la página. «Mensajes anteriores» carga la ventana anterior y «Mensajes más recientes» permite volver. El cursor se calcula sobre el orden de consulta y la colección se recorre al revés solo para renderizar, sin alterar los límites del paginador. Al confirmar un envío se vuelve a la ventana más reciente.

El cursor es un marcador de posición, no un permiso. Se valida su longitud, estructura, fecha e ID; manipularlo no permite quitar el filtro de solicitud ni evitar la policy. Una entrada inválida devuelve 422 sin exponer la conversación.

## Compatibilidad con 4B y MindBloom

No cambian el modelo, esquema, enum de eventos ni restricciones de `inquiry_notifications`, ni el servicio `InquiryWorkflow`. Los cinco avisos continúan asociados a operaciones exitosas de solicitudes. Enviar, leer o repetir un mensaje no crea avisos y no modifica fechas de lectura de avisos existentes.

El sistema anterior continúa separado: no se vinculan cuentas por correo ni se utilizan IDs de `Profesionista`, citas, disponibilidades o tokens. Una sesión del guard profesional anterior, incluso con correo coincidente, no puede acceder a esta conversación central. No se modifican cuentas, contraseñas o credenciales antiguas.

## Pruebas y evidencias

Referencia inicial ejecutada antes de modificar código: **169 pruebas / 1867 aserciones**. Resultado final: **196 pruebas / 2279 aserciones**, con **27 pruebas y 412 aserciones nuevas**. Se ejecutó por separado la selección de las 169 anteriores y conserva sus 1867 aserciones.

No se elimina ninguna prueba o aserción anterior. Solo se acota el conjunto de migraciones de los escenarios históricos `InquiryMigrationTest` e `InquiryNotificationMigrationTest` hasta 4B, manteniendo sus contadores, reversiones y comprobaciones originales. Una prueba nueva verifica específicamente la actualización **30→31**, reversión exclusiva de la tabla nueva y preservación de todas las filas, notificaciones, relaciones y credenciales anteriores, siempre en memoria.

Las pruebas nuevas cubren ambos participantes y roles, terceros y administradores, pérdida de roles, estados activos/terminales, servicios pausados, manipulación de campos, Unicode y longitud, normalización, escape HTML, CSRF real, límites de frecuencia, claves repetidas y en conflicto, dos conexiones con escritores solapados, cierre en ambas secuencias, rollback exterior, errores SQL sin exposición, cursores, orden y acceso a conversaciones largas, compatibilidad de avisos y separación heredada.

| Comando | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; lock sin cambios, autoload y package discovery correctos |
| `npm ci` | Código 0; instalación limpia desde lock |
| `npm audit` y `npm audit --json` | Código 0; **cero vulnerabilidades** en auditoría completa |
| `npm run build` | Código 0; assets Vite iguales a 4B |
| `php vendor/bin/phpunit --do-not-cache-result` | Código 0; **196 pruebas / 2279 aserciones** |
| Selección de las 169 anteriores | Código 0; **169 / 1867** |

Entorno: PHP 8.2.35, PHPUnit 11.5.24 y Node 24.19.0/npm 11.9.0. SQLite `:memory:` y dos PDO independientes sobre una URI `mode=memory&cache=shared` para las pruebas de concurrencia; se controla la intercalación de transacciones, sin depender de carreras aleatorias entre procesos. No se abren bases persistentes.

Composer se ejecutó sin red en una copia sin `.env`, con metadatos y wrappers independientes y los paquetes existentes del lock montados solo para lectura. Permanecen avisos previos de clases ambiguas de Flysystem/Pint. Sass/Bootstrap conserva sus deprecaciones anteriores. No se actualizaron dependencias, manifiestos o locks.

Revisión Chromium: **14 estados × escritorio/móvil = 28 renderizados**, a 1440×1000 y 390×844. Incluye vacío, ambos participantes, pendiente, aceptada, servicio pausado, los tres estados terminales, conversación larga en dos ventanas y errores 403/422/409/429. Sin errores JavaScript, peticiones externas ni desbordamiento horizontal; HTML hostil se muestra literalmente y no ejecuta scripts. Menú móvil, CSRF, UUID del formulario y límite de textarea comprobados. Se inspeccionaron capturas de escritorio/móvil, vacío y solo lectura.

La revisión visual usa rutas renderizadas con fixtures en memoria y HTML estático; los errores 409/429 se renderizan desde su vista. Las operaciones HTTP, autorización y escrituras se verifican con PHPUnit. Windows/Laragon no se ejecutó en este entorno Linux y queda pendiente de revisión local.

Evidencias versionadas: [resultados](validaciones/etapa-5a/resultados.json), [auditoría npm completa](validaciones/etapa-5a/npm-audit.json) y [revisión visual](validaciones/etapa-5a/visual.json). Los hashes de `.env`, base persistente, locks, manifiestos, las 30 migraciones y los componentes de notificaciones protegidos no cambian. No se publican valores privados.

## Archivos

| Archivos | Cambio |
| --- | --- |
| `app/Models/ServiceInquiryMessage.php`, `app/Models/ServiceInquiry.php` | Mensaje y relaciones |
| `app/Services/InquiryMessaging.php` | Autorización, transacción, idempotencia y bloqueo SQLite |
| `app/Exceptions/InquiryMessageConflict.php`, `InquiryMessageUnavailable.php` | Errores seguros sin contenido privado |
| `app/Http/Requests/StoreInquiryMessageRequest.php`, `InquiryConversationRequest.php` | Cuerpo/clave/campos protegidos y cursor |
| `app/Policies/ServiceInquiryPolicy.php` | Autorización de envío reutilizando participantes y roles vigentes |
| `app/Http/Controllers/InquiryMessageController.php` | POST y respuestas seguras |
| `app/Http/Controllers/ServiceInquiryController.php` | Carga acotada de conversación en el detalle |
| `app/Providers/AppServiceProvider.php`, `routes/inquiries.php` | Límites y ruta POST con `auth:web` |
| `database/migrations/2026_10_30_000000_create_service_inquiry_messages_table.php` | Migración 31 |
| `resources/views/components/inquiry-conversation.blade.php` | Historial, paginación y formulario |
| `resources/views/inquiries/show.blade.php`, `message-error.blade.php` | Integración y errores |
| `public/css/inquiries.css` | Estilos responsive de la conversación |
| `tests/Support/MessageFixtures.php`, `tests/Feature/InquiryMessages*.php` | Fixtures y cinco clases nuevas de pruebas |
| `tests/Feature/InquiryMigrationTest.php`, `InquiryNotificationMigrationTest.php` | Escenarios históricos acotados a sus migraciones |
| `README.md`, esta guía y `docs/validaciones/etapa-5a/*.json` | Documentación y evidencias |

## Laragon / PowerShell: carpeta aislada y puerto 8006

Clonar exactamente en **`C:\laragon\www\tekora-etapa5a`**, sin reemplazar la instalación original. Ejecutar desde una terminal PowerShell de Laragon con PHP 8.2+, extensiones SQLite y Node 22/24 compatibles con los locks:

```powershell
Set-Location C:\laragon\www
git clone --branch codex/etapa-5a-mensajeria-solicitudes --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa5a
Set-Location C:\laragon\www\tekora-etapa5a
php --version
php --ini
php -m
composer install
npm.cmd ci
npm.cmd audit
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
```

Resultado esperado: **196 pruebas y 2279 aserciones**, auditoría npm limpia y compilación correcta. La suite no necesita `.env` ni una base física: su bootstrap fuerza pruebas con SQLite en memoria. No ejecutar seeds para pasar las pruebas.

Para la prueba web, preparar esta carpeta aislada con su entorno **local de desarrollo** y una copia consistente de la base 4B con 30 migraciones y cuentas de prueba conocidas. Conservar la clave de esa copia; no regenerar `APP_KEY` ni modificar credenciales o el entorno de la instalación original. No incorporar `.env`, claves o bases al repositorio.

El siguiente bloque toma la copia de desarrollo previamente colocada en esta carpeta y genera **otro archivo SQLite en TEMP**. Las variables afectan únicamente esta sesión. Sesiones/caché quedan en archivos de esta carpeta, sin reutilizar conexiones de datos externas. Usar `artisan serve` desde esta misma terminal, ya que Apache de Laragon puede no heredar estas variables:

```powershell
Set-Location C:\laragon\www\tekora-etapa5a
$TekoraCopia = (Get-Location).Path
$BaseDesarrollo = Join-Path $TekoraCopia 'database\database.sqlite'
if (-not (Test-Path -LiteralPath $BaseDesarrollo)) {
    throw 'Falta la copia de desarrollo de SQLite dentro de tekora-etapa5a.'
}
$BasePrueba = Join-Path ([IO.Path]::GetTempPath()) ('tekora-5a-' + [guid]::NewGuid().ToString() + '.sqlite')
Copy-Item -LiteralPath $BaseDesarrollo -Destination $BasePrueba
$env:APP_ENV = 'local'
$env:APP_URL = 'http://127.0.0.1:8006'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-5a-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $BasePrueba
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa5a_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
Write-Host "Base exclusiva de la revisión: $BasePrueba"
php artisan migrate:status
php artisan migrate --pretend
# Solo debe estar pendiente create_service_inquiry_messages_table, migración 31.
php artisan migrate
if ($LASTEXITCODE -ne 0) { throw 'La migración de prueba no terminó correctamente.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8006
```

Obtener la copia SQLite con la aplicación de origen detenida o mediante su procedimiento de respaldo consistente, especialmente si utiliza WAL; no copiar parcialmente una base activa. No ejecutar `migrate:fresh`, `db:wipe`, rollback destructivo o seeds generales. El bloque anterior solo debe operar sobre el archivo nuevo de prueba. Cerrar la sesión PowerShell al terminar descarta estas variables. El código 5A requiere aplicar la migración antes de habilitar sus rutas, usando el procedimiento de mantenimiento de la instalación.

Recorrido en **`http://127.0.0.1:8006`**, usando dos perfiles de navegador para las cuentas de Cliente y Prestador:

1. Abrir una solicitud pendiente propia: comprobar necesidad inicial separada, estado vacío y formulario. Enviar un mensaje, responder desde la otra cuenta y revisar autores, fechas y saltos de línea.
2. Aceptar y continuar enviando. Pausar o editar el servicio y comprobar que el historial y la mensajería de la solicitud activa permanecen disponibles.
3. En solicitudes distintas, rechazar, cancelar mientras está pendiente y finalizar después de aceptar. Comprobar historial de solo lectura y ausencia de formulario. Un POST nuevo forzado debe responder 409.
4. Reenviar el mismo formulario con su misma clave y texto: no aparece otra fila. Reutilizar esa clave cambiando el texto: 409. Un formulario nuevo permite otro mensaje, aunque el texto sea igual.
5. Probar cuerpo vacío, más de 2000 caracteres y HTML de prueba: los inválidos se rechazan y el HTML válido se muestra literalmente, sin ejecución. El envío sin token CSRF o con token alterado responde 419.
6. Con cuenta ajena o administrador sin participación, el detalle y POST responden 403. El acceso profesional heredado no da acceso al guard central. Probar pérdida de roles únicamente con mecanismos autorizados en esta copia.
7. Crear más de veinte mensajes y recorrer «Mensajes anteriores»/«Mensajes más recientes»: cada ventana conserva orden cronológico y los mensajes recientes aparecen al volver al detalle sin cursor.
8. Revisar contador y bandeja de notificaciones antes/después de mensajes: no cambian. Aceptar/rechazar/cancelar/finalizar solicitudes continúa generando únicamente los cinco eventos actuales.
9. Probar escritorio y móvil, menú y mensajes de error. Once intentos en un minuto para la misma cuenta/solicitud deben producir 429 sin guardar el undécimo; para esta prueba la caché de archivos mantiene los contadores entre peticiones.
10. Revisar los logs locales de esta copia sin publicar su contenido: no deben contener los cuerpos enviados. Las pruebas automatizadas ya verifican fallos de almacenamiento y concurrencia sin exponer SQL ni cadenas de excepciones privadas.

## Riesgos y límites pendientes

La revisión local Windows/Laragon queda pendiente del usuario. Esta etapa no añade lectura por mensaje, avisos de mensajes ni entrega en tiempo real. El historial se consulta por ventanas, sin numeración o total global. Los mensajes no se editan ni se borran desde la aplicación.

Los bloqueos SQLite pueden producir conflictos recuperables. Revisar la conversación y conservar clave/texto al reintentar una petición; usar otra clave representa un envío nuevo. Las futuras entradas de escritura deben reutilizar `InquiryMessaging` y su validación, no insertar directamente ni eludir las policies.

Los cuerpos se almacenan como texto privado en SQLite, no como contenido cifrado individualmente. Mantener la protección y control de acceso de archivos, respaldos y sesiones de la instalación. Las FKs restringen eliminaciones físicas; una futura política de retención o baja de cuentas deberá contemplar solicitudes, mensajes y avisos juntos. Si se añaden herramientas de trazas o logging SQL externas, deberán excluir cuerpos y bindings privados; esta entrega no las incorpora.

Los errores de almacenamiento se comunican de forma genérica para no registrar el contenido. Una futura observabilidad podrá contar fallos mediante metadatos seguros sin copiar mensajes. La auditoría npm limpia corresponde a la fecha de esta entrega y no sustituye una auditoría Composer ni garantiza ausencia de alertas futuras.
