# Etapa 5B: mensajes no leídos y bandeja de conversaciones

Entrega del 30 de octubre de 2026, horario de Ciudad de México. Rama `codex/etapa-5b-mensajes-no-leidos`, creada desde `origin/main` en `e5f60de174842c2a4c1d2afddf3ad8e79e32ffc7`, tras verificar la integración del PR #8. El PR se entrega en borrador y no se fusiona automáticamente.

## Funcionamiento

Cada participante central mantiene su propio progreso de lectura por solicitud. Solo cuentan los mensajes de otra persona cuyo ID supera su marcador; los mensajes enviados por la propia cuenta no cuentan. No se escribe progreso al consultar una bandeja, abrir una conversación o recorrer páginas.

`/cuenta/mensajes` muestra las solicitudes en las que participa el User con permisos vigentes, incluso si todavía no tienen mensajes. Prioriza las que tienen recibidos sin leer y después ordena por el ID del mensaje más reciente, con desempate por solicitud. Cada tarjeta indica solicitud, título guardado, estado, cantidad pendiente y enlace autorizado. No incluye cuerpos ni fragmentos de mensajes.

La navegación central añade «Mensajes» con el total de recibidos sin leer de las conversaciones autorizadas. La cuenta Cliente/Prestador usa una sola bandeja y una sola identidad. No se duplican filas por tener ambos roles.

En el detalle existente se muestran pendientes individuales, total de recibidos sin leer y la acción **«Marcar mensajes como leídos»**. También está disponible en solicitudes `rejected`, `cancelled` y `completed`: leer no habilita mensajes nuevos en estados terminales.

## Alcance de una lectura explícita

El botón confirma recibidos hasta el ID más alto de los mensajes recibidos **incluidos en la página servida**, junto con los recibidos anteriores. Es un progreso acumulado, no una selección de mensajes aislados. La primera página de 5A muestra la ventana más reciente y puede confirmar también recibidos anteriores que queden fuera de esa ventana; el texto junto al botón explica el alcance.

No se consulta el máximo global al procesar el POST. Si llega un mensaje después de preparar el formulario, queda pendiente. Al usar un formulario de una página antigua, tampoco se marcan los mensajes posteriores a su límite. Si una página contiene solo mensajes propios y hay recibidos pendientes en otras páginas, no ofrece un botón sin límite válido: invita a recorrer las otras páginas.

Una lectura repetida no cambia marcador ni fechas. Un formulario obsoleto no puede deshacer un avance posterior. Los IDs de inserción, no las fechas del reloj, definen el progreso: una llegada nueva sigue pendiente aunque su fecha sea anterior por un ajuste del reloj.

## Arquitectura y persistencia

`InquiryReadTracking` concentra consultas de conversaciones autorizadas, contadores, preparación del límite protegido y actualización de progreso. `InquiryReadProgress` relaciona `inquiry()`, `user()` y `lastReadMessage()`. `User::inquiryReadProgress()` y `ServiceInquiry::readProgress()` exponen las relaciones del historial de lectura.

Nueva migración: `2026_10_30_000001_create_service_inquiry_reads_table.php`, estrictamente aditiva sobre las 31 anteriores. Total: **32 migraciones**.

| Campo de `service_inquiry_reads` | Garantía |
| --- | --- |
| `id` | Clave primaria |
| `service_inquiry_id` | FK obligatoria a solicitudes centrales |
| `user_id` | FK obligatoria a `users` |
| `last_read_message_id` | Mensaje real perteneciente a la misma solicitud |
| `created_at` | Primera confirmación de lectura |
| `updated_at` | Último avance; las repeticiones no la cambian |

La unicidad `(service_inquiry_id, user_id)` impone una fila por cuenta/solicitud. Una FK compuesta `(service_inquiry_id, last_read_message_id)` referencia `(service_inquiry_id, id)` de mensajes e impide apuntar a otra conversación. Las eliminaciones físicas quedan restringidas. Que el marcador sea un mensaje recibido y que el usuario sea participante con permisos vigentes se comprueba en el servicio.

La misma migración añade dos índices a la tabla de mensajes existente, sin actualizar filas ni columnas:

- Único `inquiry_messages_identity` sobre `(service_inquiry_id, id)`, necesario para la FK compuesta; los IDs ya eran únicos globalmente.
- `inquiry_messages_received_order` sobre `(service_inquiry_id, author_user_id, id)`, para consultas de recibidos sin recuperar cuerpos.

Los índices de idempotencia y orden de 5A permanecen. La migración no crea marcas para usuarios antiguos: todos los recibidos sin progreso conocido empiezan como pendientes. No se asume que una consulta anterior equivalía a una lectura ni se modifican mensajes o sus fechas.

`down()` elimina únicamente la tabla de progreso y los dos índices nuevos. Se verifica exclusivamente en memoria. Aplicarlo sobre una instalación con lecturas borraría ese progreso, por lo que no es un procedimiento de actualización recomendado. El código 5B necesita la migración aplicada antes de habilitarse; planificar la ventana de mantenimiento y respaldo de la instalación, también para construir los índices SQLite.

## Seguridad de formularios y permisos

| Método / ruta | Nombre | Acceso |
| --- | --- | --- |
| GET `/cuenta/mensajes` | `conversations.index` | `auth:web` y permiso actual de Cliente o Prestador |
| POST `/cuenta/solicitudes/{inquiry}/mensajes/leer` | `conversations.read` | `auth:web`, CSRF y `ServiceInquiryPolicy::view` |

No hay rutas públicas de marcadores ni lectura mediante GET. El detalle de 5A mantiene su ruta y policy. La bandeja filtra propiedad y roles en SQL; cada enlace vuelve a comprobar la policy. El contador global aplica el mismo alcance. Usuarios sin permisos de mensajería, incluido un administrador con solo ese rol, reciben 403 en la bandeja y no ven el enlace. Un tercero con roles de negocio obtiene únicamente su propia bandeja vacía, nunca contadores ajenos.

La preparación de la lectura genera un `read_token` cifrado y autenticado mediante Laravel Crypt y la clave existente, sin modificar `APP_KEY`. Contiene User, solicitud y límite validado entre los recibidos de la página. El navegador devuelve este valor opaco; no establece IDs, destinatario o marcador. El servicio valida de nuevo contexto, permisos, existencia del mensaje, pertenencia a la solicitud y que el autor sea otra persona.

Copiar el token de otro usuario o solicitud, alterar el ciphertext o intentar usar un mensaje propio no da acceso. Los campos de identidad, fechas o marcador enviados directamente se rechazan. No se confía en una firma como permiso: los roles y la propiedad se comprueban de nuevo en cada POST, incluso para un formulario preparado antes de una revocación.

Los tokens no necesitan caducidad para esta operación: un token antiguo solo puede confirmar su límite antiguo y no reduce el progreso. No almacenan cuerpos ni sustituyen CSRF. Cambiar la clave de cifrado por un procedimiento futuro obligaría a recargar los formularios; no afecta al marcador guardado.

Blade escapa títulos y cuerpos. Las consultas de bandeja/navegación seleccionan metadatos y agregados, sin cargar mensajes completos. Los errores SQL de escritura se convierten en respuestas genéricas sin cadena de excepciones; `InquiryReadFailure` implementa `ShouldntReport`. No se registran cuerpos en logs ni se incluyen en respuestas de terceros.

## Atomicidad y concurrencia

La confirmación se ejecuta en transacción SQLite: autoriza al participante, valida el token y el mensaje recibido y realiza **un UPSERT atómico**. Ante conflicto de la clave `(solicitud, usuario)`, actualiza únicamente cuando el nuevo `last_read_message_id` es mayor al guardado.

Ese WHERE se evalúa en la base en el momento de escribir, no a partir de un marcador antiguo leído por PHP. Por tanto:

- Si confirma primero el formulario más avanzado, el antiguo no cambia marcador ni fechas al reintentarse.
- Si confirma primero el antiguo, el nuevo puede avanzar después, manteniendo una sola fila.
- Dos participantes tienen filas independientes y no marcan como leídos los mensajes del otro.
- Un mensaje que llegue después del límite capturado conserva un ID mayor y sigue pendiente. La lectura no utiliza `MAX` actual en el POST.
- Una transacción exterior revertida no deja progreso persistido. Un fallo de almacenamiento no confirma una lectura parcial.

SQLite serializa escritores. Se conservan tres intentos transaccionales ante bloqueos reconocidos; una operación todavía solapada puede devolver 409 y requerir reintento. Un fallo de persistencia no reconocido devuelve 503. Las respuestas correctas redirigen con 303; autorización y CSRF mantienen 403/419. La validación devuelve 422 para JSON y, en formularios HTML, los errores habituales con redirección y mensajes.

## Consultas y compatibilidad

La bandeja pagina doce solicitudes. Los contadores usan agregados SQL y búsquedas indexadas de progreso; no cargan un historial ilimitado en PHP ni ejecutan una consulta de cuerpos por cada tarjeta. El detalle conserva los veinte mensajes y cursores de 5A. El total de pendientes incluye todas las páginas autorizadas, mientras el botón conserva el límite de la página mostrada.

No se modifican `InquiryWorkflow`, `InquiryMessaging`, sus estados, bloqueos o idempotencia. No se cambia el modelo, enum, esquema ni unicidad de `inquiry_notifications`. Consultar o marcar mensajes no crea ni lee avisos 4B; su contador permanece separado.

La identidad profesional antigua y sus citas siguen independientes. Una sesión `Profesionista`, incluso con correo coincidente, no puede consultar la bandeja central ni usar una acción de lectura. No se alteran contraseñas, tokens, cuentas o relaciones antiguas.

## Verificación

Antes de cambios: **196 pruebas / 2279 aserciones** aprobadas. Resultado final: **219 pruebas / 2599 aserciones**, con **23 pruebas y 320 aserciones adicionales**. Se ejecutó también la selección de las 196 anteriores y conserva sus 2279 aserciones.

Se mantienen todas las pruebas y aserciones previas. Dos adaptaciones de infraestructura:

- `InquiryMessagesMigrationTest` acota su escenario histórico a 30→31; la actualización 31→32 se verifica por separado.
- `InquiryMessagesPaginationTest` observa únicamente las consultas que seleccionan cuerpos del historial; los agregados de lectura añadidos en 5B no son cargas de historial. Conserva las comprobaciones de tamaño, ausencia de COUNT en esa carga, orden e índice.

Cobertura nueva: entrantes/salientes, contadores independientes, idempotencia y fechas, peticiones obsoletas, llegadas después del GET con fechas anteriores, roles/terceros/admin/cuenta dual, estados terminales y servicios pausados, CSRF real, campos y tokens manipulados, cuerpos no expuestos, páginas antiguas o con solo envíos propios, bandeja paginada, dos conexiones simultáneas en ambos órdenes, mensaje concurrente con lectura, rollback, fallos de almacenamiento, separación heredada y migración aditiva con preservación de datos/FKs.

| Comando | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; autoload/package discovery correctos y lock sin cambios |
| `npm ci` | Código 0; instalación limpia desde lock |
| `npm audit` y `npm audit --json` | Código 0; **cero vulnerabilidades** en auditoría completa |
| `npm run build` | Código 0; mismos assets Vite que 5A |
| `php vendor/bin/phpunit --do-not-cache-result` | **219 / 2599**, código 0 |
| Selección de las 196 anteriores | **196 / 2279**, código 0 |

Entorno: PHP 8.2.35/PHPUnit 11.5.24, Node 24.19.0/npm 11.9.0, SQLite en memoria. La concurrencia usa dos PDO distintos sobre una URI `mode=memory&cache=shared`, con secuencias de transacciones controladas y comprobación de que no existe archivo físico. La reversión de migraciones se ejecuta exclusivamente en esos entornos de memoria.

Composer se ejecutó sin red ni `.env`, reutilizando los paquetes del lock montados de solo lectura, con metadatos y wrappers independientes. Permanecen los avisos previos de clases ambiguas de Flysystem/Pint y las deprecaciones Sass/Bootstrap. No se cambian dependencias, manifiestos o locks.

Chromium revisó **14 estados × escritorio/móvil = 28 renderizados**, en 1440×1000 y 390×844: bandejas vacía, pendiente, mixta y leída; roles de cliente/prestador; detalle antes/después de lectura y nuevas llegadas; solicitud finalizada con/sin pendientes; ventana con solo mensajes propios; paginación y 403. Sin errores JavaScript, peticiones externas ni desbordamientos. Se verifican contador, formularios con CSRF/token y menú móvil/Escape; se inspeccionaron capturas de escritorio, móvil, detalle y estado terminal.

La revisión visual usa rutas renderizadas con fixtures SQLite en memoria y HTML estático, preparando el progreso desde los tokens reales de sus formularios. PHPUnit verifica los POST, CSRF y escrituras HTTP. Windows/Laragon queda pendiente de revisión local; no se afirma su ejecución en Linux.

Evidencias: [resultados](validaciones/etapa-5b/resultados.json), [auditoría npm](validaciones/etapa-5b/npm-audit.json), [revisión visual](validaciones/etapa-5b/visual.json). Los hashes de `.env`, SQLite persistente, locks, las 31 migraciones históricas y componentes protegidos de 4B/5A permanecen intactos. No se publican valores privados.

## Archivos

| Archivos | Cambio |
| --- | --- |
| `database/migrations/2026_10_30_000001_create_service_inquiry_reads_table.php` | Tabla, unicidad, FKs e índices aditivos |
| `app/Models/InquiryReadProgress.php`, `User.php`, `ServiceInquiry.php` | Modelo y relaciones |
| `app/Services/InquiryReadTracking.php` | Alcance, contadores, límite protegido y UPSERT |
| `app/Policies/ServiceInquiryPolicy.php` | Acceso a la bandeja con permisos vigentes |
| `app/Http/Requests/MarkInquiryMessagesReadRequest.php` | Validación del POST y campos prohibidos |
| `app/Exceptions/InquiryReadFailure.php` | Fallos seguros sin SQL o contenido privado |
| `app/Http/Controllers/ConversationController.php` | Bandeja y acción explícita |
| `app/Http/Controllers/ServiceInquiryController.php` | Estado de lectura de la página existente |
| `routes/conversations.php`, `routes/web.php` | Nuevas rutas con `auth:web` |
| `resources/views/conversations/*.blade.php` | Bandeja y errores |
| `resources/views/components/account-navigation.blade.php`, `inquiry-conversation.blade.php`, `resources/views/inquiries/show.blade.php` | Contador, etiquetas y formulario |
| `public/css/inquiries.css` | Presentación del estado y la acción |
| `tests/Feature/Conversation*.php`, `tests/Support/ReadFixtures.php` | Cinco clases nuevas y fixtures |
| `tests/Feature/InquiryMessagesMigrationTest.php`, `InquiryMessagesPaginationTest.php` | Ajustes de escenarios históricos sin perder aserciones |
| `README.md`, esta guía y evidencias JSON | Entrega |

## Laragon / PowerShell: copia aislada en el puerto 8007

Usar **`C:\laragon\www\tekora-etapa5b`**. Desde una terminal PowerShell de Laragon con PHP 8.2+, SQLite y Node 22/24 compatibles con los locks:

```powershell
Set-Location C:\laragon\www
git clone --branch codex/etapa-5b-mensajes-no-leidos --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa5b
Set-Location C:\laragon\www\tekora-etapa5b
php --version
php --ini
php -m
composer install
npm.cmd ci
npm.cmd audit
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
```

Esperado: 219 pruebas y 2599 aserciones, auditoría npm limpia y build correcto. La suite fuerza SQLite en memoria y no necesita `.env` o una base física. No ejecutar seeds para las pruebas.

Para revisar la web, preparar en esta carpeta un entorno local de desarrollo y una copia consistente de la base 5A con sus 31 migraciones y cuentas de prueba conocidas. Conservar su `APP_KEY`; no regenerar claves, modificar credenciales ni el entorno original. No versionar archivos de entorno o bases.

El bloque siguiente crea otro SQLite en TEMP a partir de la copia de desarrollo previamente colocada dentro de `tekora-etapa5b`. Las variables solo afectan la terminal actual; sesiones/caché quedan en archivos de la copia. Usar `artisan serve` desde la misma terminal para heredar los valores, sin depender de Apache ya iniciado:

```powershell
Set-Location C:\laragon\www\tekora-etapa5b
$TekoraCopia = (Get-Location).Path
$BaseDesarrollo = Join-Path $TekoraCopia 'database\database.sqlite'
if (-not (Test-Path -LiteralPath $BaseDesarrollo)) {
    throw 'Falta la copia de desarrollo de SQLite en tekora-etapa5b.'
}
$BasePrueba = Join-Path ([IO.Path]::GetTempPath()) ('tekora-5b-' + [guid]::NewGuid().ToString() + '.sqlite')
Copy-Item -LiteralPath $BaseDesarrollo -Destination $BasePrueba
$env:APP_ENV = 'local'
$env:APP_URL = 'http://127.0.0.1:8007'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-5b-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $BasePrueba
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa5b_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
Write-Host "Base exclusiva de revisión: $BasePrueba"
php artisan migrate:status
php artisan migrate --pretend
# Solo debe estar pendiente create_service_inquiry_reads_table, migración 32.
php artisan migrate
if ($LASTEXITCODE -ne 0) { throw 'La migración de prueba no terminó correctamente.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8007
```

Obtener la copia de origen con la aplicación detenida o mediante su respaldo consistente, especialmente si usa WAL; no copiar parcialmente una base activa. No usar `migrate:fresh`, `db:wipe`, rollback destructivo ni seeds generales. Aplicar la migración únicamente sobre el archivo nuevo de prueba. Cerrar la sesión PowerShell al terminar descarta estas variables.

Recorrido en **`http://127.0.0.1:8007`**, con perfiles de navegador separados para Cliente y Prestador:

1. Enviar mensajes desde ambas cuentas en una solicitud activa. Revisar que cada cuenta cuente solo los recibidos y que bandeja y navegación coincidan.
2. Abrir y recargar detalle/bandeja: el contador no baja. Usar el botón explícito: se actualiza solo la lectura de esa cuenta. Repetir el POST conserva el progreso.
3. Dejar una página abierta, enviar otro mensaje desde la contraparte y después marcar usando el formulario antiguo. El nuevo sigue pendiente. Recargar y confirmar su lectura con un formulario nuevo.
4. Preparar formularios antiguos/nuevos en pestañas distintas: marcar primero el nuevo y luego el antiguo; el contador no vuelve atrás. Probar también en el orden inverso.
5. Con más de veinte mensajes, recorrer páginas y marcar una ventana antigua. Los posteriores a su límite siguen pendientes. Una página con solo mensajes propios no permite confirmar recibidos que no incluye.
6. Finalizar, rechazar y cancelar solicitudes de prueba. El envío permanece cerrado y la acción de lectura sigue disponible para recibidos pendientes. Pausar el servicio conserva el historial.
7. Probar cuenta dual y más de doce solicitudes para la paginación. Los pendientes aparecen primero; se ven una sola vez las solicitudes autorizadas.
8. Con un tercero o administrador sin participación, comprobar 403 en el detalle y POST; no aparecen contadores ajenos. Probar revocación de roles solo mediante mecanismos autorizados en esta copia.
9. Alterar el token o añadir IDs/marcadores al formulario: validación fallida sin escritura. Sin CSRF válido: 419. Una sesión profesional heredada no accede al sistema central.
10. Comprobar que marcar mensajes no altera el contador o lectura de notificaciones 4B, ni crea eventos nuevos. Revisar vacío, leídos/no leídos, errores, menú y diseño móvil/escritorio.

## Límites y riesgos pendientes

Queda pendiente la validación manual del usuario en Laragon. El progreso es acumulado por ID, sin huecos de lectura ni recibos por mensaje visibles a la contraparte. Los contadores cambian al recargar: no hay polling, tiempo real o notificaciones externas.

Los mensajes anteriores sin progreso conocido empiezan pendientes; no hay backfill automático de lectura. Los formularios antiguos son seguros por su límite y por el avance monotónico, pero no representan una confirmación de mensajes que no contenían. Las futuras escrituras de progreso deben reutilizar `InquiryReadTracking`, sin asignar marcadores directamente.

Crear los índices requiere una escritura SQLite durante la migración. Los bloqueos de operación pueden producir 409 recuperable; los fallos de almacenamiento no confirman parcialmente la lectura. La futura eliminación/retención de cuentas o mensajes deberá considerar las nuevas FKs. Las políticas de permisos se vuelven a evaluar, sin borrar progreso al revocar un rol.

Se conservan las deprecaciones Sass/Bootstrap y avisos de Composer existentes. La auditoría npm limpia corresponde a la fecha indicada y no sustituye una auditoría Composer ni garantiza ausencia de futuras alertas. No se incorporan correo, SMS, push, WhatsApp, archivos, pagos, reservas o nuevas dependencias.
