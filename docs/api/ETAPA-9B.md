# Etapa 9B — API móvil de solicitantes

Base: `origin/main` actualizado, SHA **f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6** (merge 9A). Rama: `codex/etapa-9b-api-movil-base`. Entrega en PR borrador hacia `main`; la integración y cualquier despliegue corresponden exclusivamente al usuario.

Laravel 12 y las tablas centrales siguen siendo la fuente de identidad y negocio. No se crea una app Expo ni se agregan dependencias React Native. Esta etapa solo emite/revoca credenciales y consulta datos. Las escrituras de solicitudes, reservas, mensajes y reseñas pertenecen a 9C.

## Contrato para Expo / TypeScript

[OpenAPI 3.1](openapi.json) contiene los ocho endpoints, schemas, filtros y ejemplos JSON completos. La URL base local es `http://127.0.0.1:8013/api/v1`; fuera de desarrollo local se debe usar HTTPS. Los IDs JSON son números enteros. El cliente debe tratar el token como cadena opaca.

| Método y ruta | Resultado |
| --- | --- |
| POST `/auth/login` | 201, `data.token_type`, `access_token`, `expires_at`, `user` |
| POST `/auth/logout` | 200, `data.revoked=true`; exige Bearer |
| GET `/me` | 200, identidad propia y experiencia `client`; exige Bearer |
| GET `/services` | 200, `data[]` y `meta`; filtros `q`, `category` (slug activo), `modality`, `page`, `per_page` |
| GET `/services/{service}` | 200, `data` con servicio, categoría y perfil público |
| GET `/providers` | 200, `data[]` y `meta`; filtros `q`, `page`, `per_page` |
| GET `/providers/{provider}` | 200, `data` con perfil público |
| GET `/services/{service}/availability` | 200, horario público informativo |

Los parámetros adicionales en login y consultas públicas generan 422. `q` busca literalmente `%`, `_` y `!`, con parámetros SQL enlazados. En servicios busca título/descripción; en perfiles, nombre público/titular. `remote` y `onsite` incluyen `both`; `both` filtra solamente `both`. La paginación empieza en 1, admite hasta 10 000 páginas y 50 elementos por página, con 12 por defecto. Servicios se ordenan por ID descendente y perfiles por nombre público e ID ascendentes. Una página posterior a la última devuelve lista vacía. No se construyen URLs con parámetros arbitrarios.

```json
{
  "data": [],
  "meta": {"current_page": 1, "per_page": 12, "last_page": 1, "total": 0}
}
```

Los errores conservan la misma estructura, incluso sin `Accept: application/json` o con `APP_DEBUG=true`:

```json
{"error":{"code":"unauthenticated","message":"Se requiere un token válido.","fields":{}}}
```

Validación: 422, código `validation_failed`, `fields` es un objeto de arrays de mensajes por campo. El contrato define 200, 201, 401, 403, 404, 422, 429 y 503; también 400, 405 y 500 sanitizados. 409 queda definido para conflictos futuros de 9C: no se inventa una operación conflictiva en esta etapa de lectura. 429 conserva `Retry-After`. Se omiten trazas, SQL, rutas y mensajes de excepciones. Todas las respuestas API llevan JSON, `Cache-Control: no-store, private` y `X-Content-Type-Options: nosniff`.

Las fechas de instantes (`expires_at`, `queried_at`) son ISO 8601 UTC terminadas en `Z`. La disponibilidad expone zona IANA, día ISO 1=lunes a 7=domingo, horas civiles `HH:mm` y fechas bloqueadas `YYYY-MM-DD`. `24:00` significa medianoche al final del día del intervalo. Expo debe combinar una fecha civil con la zona IANA para representarla; no aplicar un offset fijo ni interpretar una fecha bloqueada como instante UTC. Son horarios informativos existentes, **no slots reservables ni confirmación de que un hueco esté libre**. No se revelan reservas, revisiones, IDs internos de intervalos o motivos de bloqueo. Sin configuración, `configured=false`, `timezone=null`, listas vacías. Si falla la lectura se devuelve 503, nunca una disponibilidad vacía que parezca libre.

Las cadenas públicas se entregan como texto JSON. El consumidor debe presentarlas como texto, sin WebView/HTML ni evaluación de código. No se devuelven reseñas, autores, conversaciones o reservas en estos endpoints. Los correos solo aparecen en la identidad privada del propio solicitante, nunca en servicios o perfiles públicos.

## Autenticación y límites

Sanctum **4.3.3**, compatible con Laravel 12/PHP 8.2. `config/sanctum.php` no tiene guards de sesión ni dominios stateful. No se habilita `statefulApi()`. Se mantienen intactos `config/auth.php`, ambos guards web y las rutas anteriores.

Login recibe `email`, `password`, `device_name` descriptivo de hasta 80 caracteres. Valida las contraseñas existentes de `users`. Una cuenta inexistente, contraseña incorrecta o sin rol/permisos vigentes obtiene exactamente el mismo error 401 `invalid_credentials`. No hay endpoint de registro móvil. Se comprueba un hash ficticio para una identidad inexistente. Límite compartido entre dispositivos: 5 intentos/minuto por correo normalizado e IP, y 20 por IP. El catálogo comparte 120 peticiones/minuto por IP. Los limitadores usan el cache store existente; una instalación con varios procesos/nodos requiere un almacén compartido que soporte los limitadores Laravel.

Cada emisión exige rol `client` y permisos actuales `ViewClientAppointments` y `ManageOwnInquiries`. El token solo contiene `client:read`, caduca a los 30 días y se persiste como SHA-256. El valor completo se devuelve una sola vez en el cuerpo de login. Guardarlo en almacenamiento seguro del dispositivo; nunca en URLs, logs, capturas o mensajes de error. No hay refresh token: al caducar se vuelve a iniciar sesión.

El nombre almacenado `mobile:<device_name>` identifica la credencial del dispositivo. Un nuevo login con el mismo nombre revoca su token anterior. Hay como máximo cinco tokens del espacio `mobile:` por usuario: al emitir el sexto se revoca el más antiguo. Tokens de otros espacios no se modifican. La emisión usa una transacción y bloqueo de la fila de usuario; ante contención SQLite agotada devuelve 503 y revierte la emisión. El límite no implica que el texto `device_name` sea un identificador de hardware verificado.

`auth:sanctum` comprueba hash y caducidad; `MobileClient` exige Bearer real y `PersonalAccessToken`, espacio móvil, ability y permisos/rol actuales consultados de nuevo. Una sesión web, `TransientToken`, un token de otro espacio o abilities sin rol vigente no otorgan acceso. El rol dual no recibe permisos de prestador/administrador en el recurso móvil ni inicia sesión en esos guards. Las rutas web siguen exigiendo sus sesiones y no aceptan el Bearer como sustituto.

Logout borra exclusivamente `currentAccessToken()`, preservando otros dispositivos, usuarios y sesiones web. Un token ya revocado/caducado devuelve 401. Si se retiró el rol solicitante, los endpoints privados devuelven 403 y el login no emite otra credencial; la limpieza administrativa de tokens puede hacerse mediante el modelo Sanctum en un procedimiento autorizado aparte. Las filas caducadas dejan de autenticar aunque permanezcan almacenadas. Una futura tarea de limpieza puede usar `sanctum:prune-expired` tras revisión operativa; no se añade un scheduler que toque bases existentes.

## Publicación y arquitectura

Se reutiliza `Service::publiclyVisible()`: estado publicado, categoría activa y rol prestador vigente. Los perfiles requieren al menos un servicio que cumpla ese scope. Detalle, listas y disponibilidad aplican las mismas reglas, sin excepciones para el dueño o un administrador que consulte el catálogo público. Los Resources enumeran expresamente campos públicos; no serializan modelos completos. `ProviderAvailability::publicFor()` conserva sus reglas y su lectura transaccional.

En 9C los controladores deberán autorizar la identidad/objeto y delegar en `InquiryWorkflow`, `InquiryReadTracking`, `ReservationWorkflow`, `ReviewWorkflow` y las notificaciones actuales. Este PR no replica ni modifica esos workflows, policies, estados o mecanismos de avisos.

## Migración e instalación revisada

Se inspeccionó `Illuminate/Foundation/Console/ApiInstallCommand.php`: `install:api` puede modificar Composer, publicar configuración y migraciones, escribir rutas/bootstrap y ofrecer ejecutar migraciones. **No se ejecutó ese instalador.** Se agregó Sanctum con `composer require laravel/sanctum:^4.0 --no-scripts --no-interaction` y se revisaron/agregaron manualmente configuración, rutas y el stub de migración del paquete. `package:discover` solo descubre proveedores.

Única migración nueva: `2026_11_04_000000_create_personal_access_tokens_table.php`. Crea `personal_access_tokens` con identidad polimórfica, nombre, hash único, abilities, última utilización, caducidad indexada y timestamps. `down()` elimina solamente esa tabla y sus tokens. **36 migraciones históricas sin modificar; 37 en total.** No se migra ni se revierte una base persistente en esta entrega. La reversibilidad se prueba en SQLite `:memory:` preservando los atributos del usuario.

No se modificaron `.env`, APP_KEY ni archivos SQLite persistentes. [Guía de revisión independiente y Laragon](LARAGON-9B.md). Los resultados exactos de comprobaciones y la lista de archivos están en [VALIDACION-9B.md](VALIDACION-9B.md).
