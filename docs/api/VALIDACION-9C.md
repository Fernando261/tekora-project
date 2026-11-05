# Validación real — Etapa 9C

Fecha: **5 de noviembre de 2026**. Repositorio `Fernando261/tekora-project`, rama `codex/etapa-9c-api-movil-operaciones`.

Se obtuvo `origin/main` con `git fetch origin main` y se comprobó ancestry de **`1f0926ca9fc0bf2c70a34c1e64af0b98a31d6957`**. Ese es también el SHA real de base; no había diferencias adicionales que revisar. El SHA final se entrega en el PR en borrador y puede comprobarse con `git rev-parse HEAD` (no se incrusta una referencia autorreferente en este archivo).

## Resultado

**433 pruebas / 5642 aserciones**, código **0**. Se incorporan **30 pruebas nuevas** del grupo `mobile-operations`: 477 aserciones adicionales respecto de la referencia **403 / 5165**. Se reprodujeron por separado las **403 pruebas / 5165 aserciones anteriores** con `--exclude-group mobile-operations`, sin editar ningún archivo de pruebas de la base. La comparación byte por byte de todos los tests históricos verifica esa conservación.

**21 operaciones API**, exactamente 13 nuevas y las ocho de 9B. Coinciden **21/21 métodos y paths** con OpenAPI; las ocho definiciones originales de operación OpenAPI permanecen iguales. El listado `--except-vendor` muestra **118 rutas** en este entorno.

**0 migraciones nuevas**. Las **37 migraciones** de base se compararon byte por byte y siguen intactas. `composer.json`, `composer.lock`, `package.json` y `package-lock.json` también son idénticos a la base. No se agrega ninguna dependencia.

## Entorno y comandos exactos

PHP **8.2.28 real**, Composer **2.8.11**, PHPUnit **11.5.57**, Node **24.19.0**, npm **11.9.0**. Laravel **12.71.0**, Sanctum **4.3.3**; las demás versiones de dependencias se incluyen en [la evidencia estructurada](evidence/etapa-9c.json).

En cloud `php` se ejecutó mediante `/workspace/.tekora-security-tools/php8228`, y Composer mediante ese intérprete y `/workspace/.tekora-security-tools/composer.phar`. El runtime conserva proxy/CA y no se desactiva TLS. Las siguientes son las órdenes equivalentes portables:

| Comando | Código | Resultado medido |
| --- | --- | --- |
| `composer validate --strict` | 0 | Manifiesto y lock válidos |
| `composer install --no-scripts --no-interaction` | 0 | Nada pendiente de instalar/actualizar/eliminar |
| `composer check-platform-reqs` | 0 | Plataforma PHP 8.2.28 satisfecha |
| `composer audit --locked` | 0 | **0 avisos** |
| `composer audit --locked --no-dev` | 0 | **0 avisos** de producción |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **433 pruebas / 5642 aserciones**, 48,188 s informados por PHPUnit |
| `php vendor/bin/phpunit --exclude-group mobile-operations --do-not-cache-result` | 0 | **403 pruebas / 5165 aserciones**, 45,663 s informados por PHPUnit |
| `npm ci` | 0 | Instalación desde el lock, sin modificaciones |
| `npm audit` | 0 | **0 vulnerabilidades** |
| `npm run build` | 0 | Vite 6.4.5, 116 módulos, 5,17 s informados por Vite |
| `php artisan route:list --path=api -v` | 0 | **21 rutas** con middleware esperado |
| `php artisan route:list --except-vendor --json` | 0 | 118 rutas; 21 bajo `/api/v1` |
| Comparación JSON de rutas / OpenAPI | 0 | **21/21**, sin diferencias de método o URI |
| `npm exec --yes --package=@redocly/cli@2.6.0 -- redocly lint docs/api/openapi.json` | 0 | OpenAPI 3.1 válido; **2 advertencias** por componentes Error400/Error405 sin referencia específica de operación |
| Pint `--test` sobre los 16 archivos nuevos y adaptadores enumerados abajo | 0 | 16 archivos conformes |
| `git diff --check` | 0 | Sin errores de espacios |

Orden exacta de estilo comprobada (el comodín del shell selecciona solamente las cinco clases nuevas MobileOperations*):

```text
php vendor/bin/pint --test app/Http/Controllers/Api/V1/OperationsController.php app/Http/Requests/Api/V1/OperationRequest.php app/Http/Resources/Api/V1/InquiryResource.php app/Http/Resources/Api/V1/ConversationResource.php app/Http/Resources/Api/V1/MessageResource.php app/Http/Resources/Api/V1/ReservationResource.php app/Http/Resources/Api/V1/ReservationOptionsResource.php app/Services/MobileClientOperations.php app/Support/Api/ApiError.php routes/api.php tests/Feature/MobileOperations* tests/Support/MobileOperationsFixtures.php
```

Composer conserva un aviso existente de clase ambigua `AppServiceProvider` en vendor de Pint: se selecciona primero la clase de la aplicación. El build conserva advertencias Sass heredadas, sin errores. No se presentan estos avisos como auditorías fallidas ni se alteran guards/configuración de negocio para ocultarlos.

## Cobertura nueva

Las 30 pruebas Feature cubren:

- Los 13 endpoints sin Bearer y con ability incorrecta; tokens caducados/revocados, cookies web y retirada del rol actual.
- Cuenta dual con solicitudes/conversaciones/reservas recibidas como prestador, invisibles en la API de solicitante. IDs ajenos/inexistentes devuelven 404 incluso con cuerpos inválidos.
- Solicitud nueva, duplicada sin sobrescribir, cancelación pendiente, historial terminal y conflicto tras aceptación. Servicios propios, borrador, pausados, categorías inactivas y prestadores sin permisos.
- Paginación/filtros/whitelist de campos, texto XSS presentado como JSON y ausencia de datos sensibles.
- Mensajes normalizados, replay, clave reutilizada con otro cuerpo, replay tras cierre y rechazo de envíos nuevos tardíos.
- Cursor estable con empate de fechas, límite 20, cursor inválido y páginas sin escritura de lectura.
- Tokens recibidos solo de páginas servidas, mensajes posteriores al GET que permanecen sin leer, límite de recibidos sin usar mensajes propios y marcador monotónico con tokens antiguos.
- Tokens manipulados, de otro actor o conversación, ownership de marcadores y rechazo de límites inyectados.
- Opciones privadas con revisión/zona coherentes, sin filtrar la revisión en el GET público de 9B. No aceptada, sin horario, servicio pausado, permisos retirados, reserva activa y cambios de revisión/zona.
- Propuesta/replay/conflicto de UUID, UTC correcto, pasado, bloqueo, fuera de intervalo/horizonte y horario confirmado solapado de otro cliente.
- DST inexistente y ambiguo en America/New_York; no se cambian las reglas de ReservationTime.
- Cancelación con versión vigente/obsoleta, confirmada antes del inicio y rechazo tras el inicio; ausencia de rutas móviles de confirmar/rechazar/completar como prestador.
- Límites por usuario Sanctum para solicitudes/mensajes/lecturas/reservas, 429 y recuperación tras espera.
- Fallos reales mediante triggers SQLite en memoria: no quedan solicitudes, mensajes, marcadores, reservas, eventos o avisos parciales. Respuestas 409/503 y logging API sin SQL/bindings/texto privado.
- Dos conexiones SQLite con escritores solapados: conflicto y recuperación de solicitud original; error temporal de lookup y replay de mensaje sin duplicación. Cada conexión verifica `PRAGMA database_list.file = ''`, es decir, sin archivo persistente. La prueba desactiva solo el bookkeeping `last_used_at` de Sanctum para ejercitar el bloqueo del workflow, no la autenticación o permisos.

La primera ejecución focalizada encontró dos expectativas incorrectas en fixtures nuevos: el viaje de fecha DST había caducado un token y un RAISE SQLite era clasificado autoritativamente como conflicto por ReservationWorkflow. Se renovó el token de prueba al cambiar de fecha y se conservó la clasificación 409 del workflow. No se modificaron reglas de producción o tests anteriores para hacerlas pasar. Después se añadió una aserción que comprueba la redacción del logging, por eso el conteo final es 5642 (la ejecución previa tenía 5641).

## Aislamiento y limitaciones

Solo se migró dentro de PHPUnit con SQLite **`:memory:`** o conexiones compartidas `mode=memory`, como las pruebas existentes de concurrencia. No se ejecutaron migraciones manuales, seeders o rollbacks contra archivos. Se compararon hashes privados de `.env` y SQLite antes/después: **sin cambios**, sin publicar valores o hashes. APP_KEY persistente permanece intacta; las claves efímeras pertenecen al proceso de pruebas.

Las policies, guards, rutas web, reglas de escritura de workflows y dependencias se conservan. ReservationWorkflow recibe únicamente una lectura `options()` que reutiliza su fuente de disponibilidad/permisos. Los adaptadores JSON y el reporte PDO seguro son adiciones para API, con regresión completa de 9B.

No se ejecutó Windows, Expo en dispositivo, carga de producción, MySQL, Google real, pagos o push. El ensayo HTTP automatizado utiliza el kernel real con datos sintéticos en memoria; no una instalación existente. Cero avisos conocidos no certifica seguridad total. La revisión independiente de ChatGPT y la validación Laragon del usuario quedan pendientes. El PR debe permanecer en borrador, sin fusionar o desplegar.

## Archivos modificados

**25 archivos** frente al SHA de base:

- `README.md`
- `app/Http/Controllers/Api/V1/OperationsController.php`
- `app/Http/Requests/Api/V1/OperationRequest.php`
- `app/Http/Resources/Api/V1/ConversationResource.php`
- `app/Http/Resources/Api/V1/InquiryResource.php`
- `app/Http/Resources/Api/V1/MessageResource.php`
- `app/Http/Resources/Api/V1/ReservationOptionsResource.php`
- `app/Http/Resources/Api/V1/ReservationResource.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/MobileClientOperations.php`
- `app/Services/ReservationWorkflow.php`
- `app/Support/Api/ApiError.php`
- `bootstrap/app.php`
- `docs/api/ETAPA-9C.md`
- `docs/api/LARAGON-9C.md`
- `docs/api/VALIDACION-9C.md`
- `docs/api/evidence/etapa-9c.json`
- `docs/api/openapi.json`
- `routes/api.php`
- `tests/Feature/MobileOperationsConcurrencyTest.php`
- `tests/Feature/MobileOperationsContractTest.php`
- `tests/Feature/MobileOperationsInquiryTest.php`
- `tests/Feature/MobileOperationsMessagesTest.php`
- `tests/Feature/MobileOperationsReservationTest.php`
- `tests/Support/MobileOperationsFixtures.php`
