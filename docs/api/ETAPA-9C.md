# Etapa 9C — solicitudes, conversaciones y reservas del solicitante móvil

Base obtenida de `origin/main`: **`1f0926ca9fc0bf2c70a34c1e64af0b98a31d6957`**, exactamente el commit solicitado; no había avances adicionales. Rama `codex/etapa-9c-api-movil-operaciones`. La entrega es un PR nuevo en borrador, sin integrar o desplegar.

Se añaden **13 operaciones** a las ocho de 9B: **21 en total**. Laravel sigue en 12.71.0, Sanctum en 4.3.3 y PHP en 8.2.28. No cambian dependencias, tablas o las **37 migraciones**. No se implementan acciones de prestador, reseñas móviles, pagos, notificaciones push, adjuntos, escrituras Google Calendar, administración o la app Expo.

## Arquitectura y autorización

`OperationsController` adapta HTTP/JSON y delega las escrituras a `InquiryWorkflow::open/decide(Cancel)`, `InquiryMessaging::send`, `InquiryReadTracking::mark` y `ReservationWorkflow::propose/decide('cancel')`. Los workflows mantienen sus transacciones, índices, triggers, bloqueos SQLite, CAS, idempotencia y avisos actuales. No se reimplementa el flujo de escritura.

`MobileClientOperations` aplica `service_inquiries.client_user_id = actor.id` o `service_reservations.client_user_id = actor.id`, y después la policy vigente. `OperationRequest::authorize` resuelve ese ámbito antes de validar cuerpos: un ID ajeno o inexistente devuelve el mismo **404**, incluso con un cuerpo inválido. No se usan bindings globales de esos modelos para resolver la identidad móvil.

Una cuenta dual solo ve la experiencia de solicitante. La lista de conversaciones reutiliza `InquiryReadTracking::conversations()` y le añade explícitamente `client_user_id`; no expone la unión de prestador que admite la web. El conteo de no leídos utiliza el scope del servicio sobre ese conjunto acotado.

Todas las operaciones nuevas llevan `auth:sanctum`, `MobileClient` y limitadores propios. El middleware exige Bearer real, rol solicitante y permisos actuales en cada petición; los workflows vuelven a autorizar. No basta una cookie, una relación de roles cacheada o las abilities del token. Se conserva el nombre legacy `client:read` de los tokens 9B, pero no se interpreta como autorización suficiente para escribir. Las ocho rutas originales conservan sus controladores, middleware y contrato; no se modifican guards ni sesiones web.

## Contrato

[OpenAPI 3.1 / versión 1.1.0](openapi.json) contiene schemas, respuestas y ejemplos. Todas las nuevas rutas tienen prefijo `/api/v1`:

| Método | Ruta | Regla principal |
| --- | --- | --- |
| GET | `/inquiries` | Propias, orden ID descendente; filtros status/page/per_page |
| POST | `/services/{service}/inquiries` | Solo servicio público y ajeno; conservar solicitud activa |
| GET | `/inquiries/{inquiry}` | Historial propio, también terminal |
| PATCH | `/inquiries/{inquiry}/cancel` | Solo pendiente, decisión atómica del workflow |
| GET | `/conversations` | Propias; sin leer primero, último mensaje e ID descendentes |
| GET | `/inquiries/{inquiry}/messages` | Cursor de 20 mensajes, sin marcar lecturas |
| POST | `/inquiries/{inquiry}/messages` | Texto y UUID, idempotencia por autor/conversación |
| POST | `/inquiries/{inquiry}/messages/read` | Token del límite recibido realmente servido |
| GET | `/inquiries/{inquiry}/reservation-options` | Snapshot propio con revisión y horario vigentes |
| POST | `/inquiries/{inquiry}/reservations` | date/time/revisión/UUID, validación del workflow |
| GET | `/reservations` | Propias; filtros status/page/per_page |
| GET | `/reservations/{reservation}` | Estado, versión, fecha civil, zona e instantes UTC propios |
| PATCH | `/reservations/{reservation}/cancel` | Versión optimista y estados permitidos del workflow |

Listas usan `data[]` y `meta` numérico como 9B: página 1–10000, por defecto 12 elementos y máximo 50. Conversaciones solo admite paginación. Detalles/opciones/cancelación de solicitud no admiten campos adicionales. Se rechazan IDs de participantes, estados, roles, timestamps, adjuntos y cualquier parámetro desconocido con 422. Los cuerpos de propuesta/cancelación de reserva se validan con **ReservationInput**, no con una copia de sus reglas.

Resources separados enumeran los campos de solicitudes, conversaciones, mensajes, reservas y opciones. No hay serialización completa de Eloquent, correos, teléfonos, claves de idempotencia persistidas, identidades del autor, OAuth, datos de otros clientes o metadatos internos de eventos. `is_mine` basta para distinguir la dirección del mensaje. Los textos propios son datos JSON: el cliente no debe renderizarlos como HTML.

Fechas de instantes en ISO 8601 UTC `Z`. Las reservas entregan además `date`, `time`, `end_time` y zona IANA; los instantes almacenados explícitamente en UTC no se interpretan con APP_TIMEZONE.

## Solicitudes e idempotencia de mensajes

La descripción inicial exige **10–2000 caracteres**. El primer POST crea con **201**; si existe una solicitud activa del mismo solicitante/servicio, el workflow devuelve la original con **200**, sin sobrescribir descripción ni duplicar avisos. Cancelar una pendiente devuelve 200; repetir la transición o cancelar una aceptada devuelve 409. Los estados terminales conservan historial de lectura.

Expo debe generar un UUID por intención de envío, guardarlo con el texto y reutilizar ambos al perder una respuesta o recibir un fallo temporal:

```json
{"body":"¿Podemos acordar un horario?","idempotency_key":"66fd633b-ec50-4b1a-b61f-29af184ae25b"}
```

El adaptador conserva la normalización web: CRLF/CR a LF y recorte de extremos. Mismo UUID + mismo texto normalizado: **200**, mismo ID. Mismo UUID + otro texto: **409**; crear una intención nueva explícitamente, no cambiar silenciosamente la clave de un reintento. Nuevos mensajes solo en `pending`/`accepted`. El replay reconocido puede recuperar un envío previo tras el cierre; no agrega un mensaje tardío. UUIDs se comparan sin distinguir mayúsculas y minúsculas, según el workflow existente.

## Cursores y lectura efectivamente mostrada

GET de mensajes conserva el orden SQL **created_at DESC, id DESC**, página fija de **20** y el parámetro `messages_cursor`, validado con las reglas existentes. Expo debe usar `next_cursor`/`previous_cursor` opacos y puede invertir la página para mostrarla cronológicamente. No construir cursores a mano ni usar page/per_page. Un cursor de otra conversación no autoriza a leerla: todas las consultas mantienen el ámbito propietario.

La página devuelve `meta.read_token`, `last_read_message_id` y `unread_messages_count`, sin URLs de sesión. `read_token` es null si no hay recibidos nuevos. Solo contiene, cifrado por `InquiryReadTracking`, un límite de mensajes recibidos incluidos en esa página, ligado a cuenta y conversación. No se toma MAX global de mensajes al confirmar.

Después de mostrar la página, Expo confirma:

```json
{"read_token":"<capacidad opaca devuelta en la página mostrada>"}
```

GET no marca lecturas. Mensajes llegados después de obtener la página permanecen sin leer. Un token anterior no reduce el marcador ni cambia su fecha cuando no avanza; el UPSERT devuelve el marcador vigente. Token manipulado, de otra cuenta/conversación: 422; recurso ajeno: 404. No aceptar ni enviar `through`, user_id o last_read_message_id como autoridad. El token no tiene una nueva caducidad inventada por la API: conserva el mecanismo existente. Renovarlo con un nuevo GET para mostrar nuevos recibidos; no reintentar un token inválido indefinidamente. Bearer y read_token nunca van en logs o URLs.

## Opciones privadas y reservas

El GET público de 9B sigue siendo **informativo** y no entrega revisión. Para proponer, Expo primero consulta el GET privado `reservation-options`. Se agregó **un método de lectura `ReservationWorkflow::options`**, sin alterar `propose/decide`: dentro de una transacción de lectura comprueba propiedad/policy, solicitud aceptada y ausencia de reserva activa; reutiliza `setting()` para publicación/permisos y `ProviderAvailability::publicFor()` para el horario.

El snapshot contiene revisión, zona IANA, intervalos, fechas bloqueadas públicas, fecha civil mínima/máxima, duración **60 minutos** y `guaranteed=false`. No obtiene el bloqueo de escritura de `lockInquiry`, no escribe estado y no revela ocupaciones o identidades de otros clientes. El horizonte usa la misma configuración del workflow (90 días por defecto, día límite incluido). Sin horario o si no admite propuesta: 409; fallo de lectura: 503, nunca un horario vacío que parezca libre.

```json
{"date":"2030-01-07","time":"09:00","availability_revision":2,"idempotency_key":"66fd633b-ec50-4b1a-b61f-29af184ae25b"}
```

Mantener el UUID con **date/time/revision originales** ante un resultado incierto. Mismo contenido y UUID: 200; primera propuesta: 201. Cambiar fecha, hora o revisión con la misma clave produce 409. Una edición de disponibilidad requiere releer opciones y preparar una propuesta nueva de forma consciente. El guardado autoritativo conserva revisión, horario futuro, horizonte, bloqueos, intervalos, DST y solapes confirmados. Una hora inexistente/ambigua por DST devuelve 422, sin normalizarla silenciosamente.

Las reservas muestran estados `requested/confirmed/rejected/cancelled/completed` y `version`. Para cancelar, enviar solo `{"version":1}`. Se admite `requested`, o `confirmed` antes del inicio; versión obsoleta o estado inválido: 409. Ante 409, refrescar el detalle; no incrementar una versión a ciegas. No existen rutas móviles para confirmar, rechazar como prestador o completar reservas.

## Errores y límites

Se conservan `error.code`, `error.message`, `error.fields`, JSON sin HTML/redirects, `Cache-Control: no-store, private` y `nosniff`. 200 consulta/replay/modificación; 201 alta; 401 token inválido/ausente; 403 permisos; 404 ownership/inexistencia; 409 conflicto; 422 validación; 429 frecuencia con Retry-After; 503 fallo temporal. Un fallo no controlado conserva el 500 genérico de 9B.

`ApiError` mapea explícitamente InquiryConflict/InquiryMessageConflict a 409, InquiryMessageUnavailable a 503 y los status 409/503 de InquiryReadFailure/ReservationFailure. No usa mensajes internos, SQL, bindings, trazas o URLs de sesión. Los PDO de API se registran solo como advertencia genérica y clase, para evitar cuerpos privados en bindings de un INSERT. El logging web no cambia.

| Limitador nuevo, por minuto | Usuario Sanctum | IP |
| --- | --- | --- |
| Todas las operaciones 9C | 120 | 480 |
| Crear/cancelar solicitud, compartido | 5 | 20 |
| Enviar mensaje | 10 | 40 |
| Confirmar lectura | 30 | 120 |
| Proponer/cancelar reserva, compartido | 10 | 40 |

Los reintentos y errores validados también consumen sus límites. No se reutilizan limitadores que requieren user('web'). El almacén de cache de despliegue debe soportar el rate limiting Laravel; no se modifica su configuración en esta etapa.

## Verificación y límites de la entrega

[VALIDACION-9C.md](VALIDACION-9C.md) registra comandos, códigos, conteos y archivos. [LARAGON-9C.md](LARAGON-9C.md) proporciona una validación independiente en clon nuevo, sin bases persistentes. Las pruebas concurrentes usan dos conexiones SQLite sobre una URI **mode=memory**, verificando que PRAGMA database_list no tiene archivo; no son una prueba de carga de producción ni de MySQL. No se ejecutó Windows o una app Expo real. La revisión independiente del usuario y ChatGPT sigue pendiente; cero avisos no certifica seguridad completa o autorización de despliegue.
