# Evidencia de validación — Etapa 9B

Validación realizada el **4 de noviembre de 2026** en el entorno cloud Linux, con PHP **8.2.35**, Composer **2.5.5**, PHPUnit **11.5.24**, Node **24.19.0** y npm **11.9.0**. La guía Windows/Laragon es un procedimiento de revisión independiente: no se ejecutó Windows durante esta entrega.

Origen exacto: **`f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6`**. Se ejecutaron `git fetch origin main` y `git merge-base --is-ancestor f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 origin/main` antes de crear `codex/etapa-9b-api-movil-base` desde `origin/main`. El SHA final de rama se entrega en el PR y se comprueba con `git rev-parse HEAD`; no se incrusta un SHA autorreferente en este mismo commit.

## Comprobaciones y resultados reales

En cloud se invocan PHP/Composer mediante `/workspace/.tekora-environment/bin/php` y `/workspace/.tekora-environment/bin/composer`, que ejecutan el runtime Docker local con el repositorio montado. Las órdenes siguientes muestran los comandos equivalentes dentro del runtime. Ningún comando de migración se ejecutó contra una base persistente; las migraciones de la suite se ejecutan exclusivamente en memoria.

| Comando | Resultado |
| --- | --- |
| `composer require laravel/sanctum:^4.0 --no-scripts --no-interaction` | Código 0; único paquete añadido: Sanctum 4.3.3; sin actualizaciones o eliminaciones de paquetes previos |
| `composer install --no-scripts --no-interaction` | Código 0; lockfile instalable, nada pendiente de instalar/actualizar/eliminar |
| `composer dump-autoload --no-scripts` | Código 0; avisos de clases ambiguas en el vendor preexistente (Flysystem y AppServiceProvider de Pint) |
| `php artisan package:discover --ansi` | Código 0; proveedor Sanctum descubierto; no ejecuta migraciones |
| `composer validate` | Código 0; aviso heredado por restricción exacta `google/apiclient:2.15` |
| `composer validate --strict` | Código 1 por ese aviso heredado; no se presenta como validación estricta limpia |
| `composer check-platform-reqs` | Código 0; todos los requisitos satisfechos |
| `composer audit --locked --format=json` | Código **18** en Composer 2.5.5; **67 avisos / 18 paquetes** heredados; [referencias completas](COMPOSER-AUDIT-9B.md) |
| `php vendor/bin/phpunit --do-not-cache-result` | Código 0; **396 pruebas / 5115 aserciones**, 47,637 s en esta ejecución |
| `npm ci` | Código 0; 90 paquetes instalados desde `package-lock.json` |
| `npm audit --json` | Código 0; **0 vulnerabilidades**, incluyendo 0 altas/críticas |
| `npm run build` después de `npm ci` | Código 0; Vite 6.4.5, 116 módulos; advertencias Sass de APIs/imports heredados; build final 5,69 s |
| `php artisan route:list --path=api -v` | Código 0; **8 rutas** y middleware esperado, sin grupo web/stateful en la API |
| `php artisan route:list --except-vendor --json` | Código 0; **105 rutas** visibles al comando en este entorno; 8 bajo `/api/v1` |
| Comparación de operaciones OpenAPI con el JSON de rutas | Las 8 operaciones coinciden exactamente en método y URI |
| `npm exec --yes --package=@redocly/cli@2.6.0 -- redocly lint docs/api/openapi.json` | Código 0; OpenAPI **válido**, 3 avisos de componentes de error 400/405/409 sin referencia en una operación específica; 409 está reservado para 9C |
| `php vendor/bin/pint --test` con los 20 archivos nuevos de API/configuración/rutas/pruebas enumerados abajo | Código 0; 20 archivos conformes |
| `git diff --check` | Código 0 |

No se actualizó el resto del backend para ocultar los avisos de seguridad: la comparación de ambos lockfiles verifica que **todos los paquetes anteriores conservan su versión**. Sanctum no tiene avisos en la consulta realizada. El backend conserva vulnerabilidades heredadas que necesitan evaluación y actualización antes de una integración/despliegue autorizado. El PR sigue en borrador.

Orden exacta de formato comprobada:

```text
php vendor/bin/pint --test app/Http/Controllers/Api app/Http/Middleware app/Http/Requests/Api app/Http/Resources/Api app/Services/MobileTokens.php app/Support/Api config/mobile_api.php config/sanctum.php routes/api.php tests/Feature/MobileApiAuthTest.php tests/Feature/MobileApiCatalogTest.php tests/Feature/MobileApiMigrationTest.php
```

La primera ejecución completa encontró el conteo histórico 35→36 que incorporaba automáticamente la nueva migración 37. Se acotaron **solo sus dos llamadas de actualización** a la migración de actividad original: permanecen las expectativas de 35/36, snapshots y comprobaciones de reversibilidad. La ejecución final completa pasa; no se eliminaron ni omitieron pruebas anteriores.

## Cobertura añadida

**21 pruebas nuevas**, además de las 375 de la base:

- Login existente, identidad privada, hash del token, caducidad, ability acotada y ausencia de sesiones web nuevas.
- Respuestas indistinguibles para credenciales/cuentas/roles no válidos; validación de tipos y rechazo de inyección de abilities.
- Límite por correo/IP y por IP, 429 con `Retry-After` y recuperación del limitador.
- Sesión web que sigue funcionando, pero no autentica la API; Bearer dual que no autentica dashboard/administración web.
- Revalidación inmediata de roles/permisos, token sin ability, token de otro espacio y ausencia de Bearer real.
- Caducidad, revocación, rotación del mismo dispositivo, máximo de cinco móviles y conservación de otros espacios.
- Logout limitado al token actual, preservando otro dispositivo, otra cuenta y la sesión web.
- Fallo real de SQLite al insertar el token después del borrado de rotación: 503 seguro y rollback que conserva el token anterior.
- Publicación: borradores, pausados, categorías inactivas y prestadores sin rol; ausencia uniforme en listas, detalles y horarios.
- Paginación, límites, búsqueda literal/SQL enlazado, parámetros desconocidos y proyección expresa sin campos sensibles.
- Disponibilidad sin configurar, zona IANA, fechas civiles, horarios públicos y fallo 503 que no parece disponibilidad libre.
- Texto XSS almacenado presentado como JSON, `nosniff`, errores seguros sin `Accept` o con depuración activa, 404/405/409/500/503 y limitación pública.
- Migración aditiva y reversible; salto **36→37→36** en una conexión SQLite nueva en memoria, con snapshots de columnas, índices, filas y triggers de **todas** las tablas anteriores; comprobaciones de integridad y claves foráneas.

No se prueban writes de negocio móviles ni la app Expo porque pertenecen a etapas posteriores. No se afirma haber probado una carga de producción o concurrencia real multiusuario en MySQL. Las pruebas de fallo transaccional de esta etapa usan SQLite en memoria.

## Archivos cambiados

- `README.md`
- `app/Http/Controllers/Api/V1/AuthController.php`
- `app/Http/Controllers/Api/V1/CatalogController.php`
- `app/Http/Middleware/ApiHeaders.php`
- `app/Http/Middleware/MobileClient.php`
- `app/Http/Requests/Api/V1/ApiRequest.php`
- `app/Http/Requests/Api/V1/CatalogRequest.php`
- `app/Http/Requests/Api/V1/LoginRequest.php`
- `app/Http/Resources/Api/V1/ApiCollection.php`
- `app/Http/Resources/Api/V1/AvailabilityResource.php`
- `app/Http/Resources/Api/V1/ClientResource.php`
- `app/Http/Resources/Api/V1/ProviderResource.php`
- `app/Http/Resources/Api/V1/ServiceResource.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/MobileTokens.php`
- `app/Support/Api/ApiError.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `config/mobile_api.php`
- `config/sanctum.php`
- `database/migrations/2026_11_04_000000_create_personal_access_tokens_table.php`
- `docs/api/COMPOSER-AUDIT-9B.md`
- `docs/api/ETAPA-9B.md`
- `docs/api/LARAGON-9B.md`
- `docs/api/VALIDACION-9B.md`
- `docs/api/openapi.json`
- `routes/api.php`
- `tests/Feature/ActivityMigrationTest.php`
- `tests/Feature/MobileApiAuthTest.php`
- `tests/Feature/MobileApiCatalogTest.php`
- `tests/Feature/MobileApiMigrationTest.php`

Única migración nueva: `database/migrations/2026_11_04_000000_create_personal_access_tokens_table.php`. Las 36 anteriores permanecen idénticas. Se mantienen intactos los guards/sesiones, las rutas previas, workflows y reglas de publicación/disponibilidad. No se cambian `.env`, APP_KEY, SQLite persistentes ni dependencias frontend.
