# PR #15 — integración de seguridad de PR #16

Fecha de validación: **5 de noviembre de 2026**. Este documento reemplaza como referencia actual los resultados históricos de 9B anteriores a la integración de seguridad.

Se actualiza exclusivamente `codex/etapa-9b-api-movil-base`, rama del **PR #15**, que debe permanecer en borrador. No se crea otro PR, fusiona el PR #15 ni despliega. La integración en main corresponde únicamente al usuario tras su validación independiente.

## Referencias y merge controlado

- Cabeza anterior de 9B: **`07cb6dfa9de1916535999c6a9403cc5be948ae28`**.
- `origin/main` obtenido y verificado: **`afb7f851fa8ea142e6639bc7c8431f541ef15dd9`**, merge del PR #16.
- Se ejecutaron `git fetch origin main`, `git merge-base --is-ancestor afb7f851fa8ea142e6639bc7c8431f541ef15dd9 origin/main` y un **merge sin squash ni rebase**: `git merge --no-commit --no-ff origin/main`.
- El SHA actualizado del commit de merge se entrega en el PR #15 y puede comprobarse con `git rev-parse HEAD`; no se incrusta una referencia autorreferente en este archivo.

Los únicos conflictos encontrados fueron **composer.json y composer.lock**. Se conservó la versión de seguridad de main para ambos archivos y se reincorporó Sanctum mediante Composer:

```text
composer require laravel/sanctum:^4.0 --no-update --no-interaction
composer update laravel/sanctum --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

No se editaron manualmente versiones, referencias o hashes de paquetes del lockfile. La comparación automatizada comprueba que **cada registro completo de paquete de main** —versión, fuente, referencia, dist y demás metadatos— permanece idéntico. No se eliminó o rebajó ningún paquete de main. La única adición es **laravel/sanctum 4.3.3**, cuyo registro completo también es idéntico al de 9B original.

El resultado no modifica código de negocio, guards, roles, policies, rutas, bootstrap, Resources o controladores de la API para resolver pruebas. El código de esos módulos y las clases MobileApi* permanecen idénticos a `07cb6df`. Las siete pruebas de seguridad se incorporan desde main sin cambios.

## Versiones finales

Se usaron **PHP 8.2.28 real** y **Composer 2.8.11**, ambos disponibles en el entorno cloud. `config.platform.php` continúa siendo **8.2.28** y el requisito PHP del proyecto sigue en `^8.2`.

| Paquete | Versión final |
| --- | --- |
| laravel/framework | 12.71.0 |
| google/apiclient | 2.20.1 |
| google/auth | 1.55.3 |
| firebase/php-jwt | 7.2.1 |
| laravel/sanctum | 4.3.3 |
| symfony/http-foundation, http-kernel, routing, yaml | 7.4.23 |
| symfony/mailer, mime, process | 7.4.22 |
| guzzlehttp/guzzle | 7.16.1 |
| guzzlehttp/psr7 | 2.13.2 |
| guzzlehttp/promises | 2.6.0 |
| league/commonmark | 2.10.7 |
| league/flysystem | 3.36.0 |
| dompdf/dompdf | 3.1.6 |
| phpseclib/phpseclib | 3.0.58 |
| psy/psysh | 0.12.24 |
| phpunit/phpunit | 11.5.57 |

La [evidencia estructurada](evidence/integracion-seguridad-pr16.json) incluye todas las versiones del lock y los comandos ejecutados. Se conservan también los polyfills y demás dependencias transitivas corregidas del PR #16. No se fuerza JWT a través de una restricción incompatible. Tampoco se ignoran requisitos de plataforma ni se excluyen avisos de auditoría.

## Validación real

En cloud se ejecuta PHP mediante `/workspace/.tekora-security-tools/php8228` y Composer mediante ese intérprete y `/workspace/.tekora-security-tools/composer.phar`. Las órdenes de la tabla son sus equivalentes portables. Node **24.19.0**, npm **11.9.0** y PHPUnit **11.5.57**.

| Comando | Código de salida | Resultado |
| --- | --- | --- |
| `composer validate --strict` | 0 | Manifiesto y lock válidos |
| `composer install --no-scripts --no-interaction` | 0 | Instalación reproducible; sin cambios pendientes |
| `composer check-platform-reqs` | 0 | Requisitos satisfechos en PHP 8.2.28 |
| `composer audit --locked` | 0 | **0 avisos** |
| `composer audit --locked --no-dev` | 0 | **0 avisos** de producción |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **403 pruebas / 5165 aserciones** |
| `php vendor/bin/phpunit --exclude-group composer-security --do-not-cache-result` | 0 | **396 pruebas / 5115 aserciones** |
| `npm ci` | 0 | Dependencias frontend desde su lock, sin cambios del manifiesto |
| `npm audit` | 0 | **0 vulnerabilidades** |
| `npm run build` | 0 | Vite 6.4.5; 116 módulos, 5,05 s informados por Vite |
| `php artisan route:list --path=api -v` | 0 | **8 endpoints móviles**, con middleware esperado |
| Comparación del listado JSON con OpenAPI | 0 | **8/8 operaciones** coinciden en método y URI |
| `git diff --check` | 0 | Sin errores de espacios |

Los conteos coinciden exactamente con el ensayo combinado anterior: no se agregaron, omitieron o modificaron pruebas para alterar el resultado. El grupo `composer-security` contiene solamente las siete regresiones del PR #16.

Composer informa un aviso de resolución ambigua de `AppServiceProvider` debido a la copia de clase incluida en el vendor de Pint: selecciona primero la clase de la aplicación. Es un aviso existente del vendor, no una vulnerabilidad de auditoría ni un fallo de instalación; no se cambia código o configuración de negocio para ocultarlo. El build conserva las advertencias Sass heredadas; termina con código 0.

## Ocho rutas frente al contrato OpenAPI

| Método | Ruta registrada | Operación OpenAPI |
| --- | --- | --- |
| POST | `/api/v1/auth/login` | login |
| POST | `/api/v1/auth/logout` | logout |
| GET | `/api/v1/me` | me |
| GET | `/api/v1/services` | services |
| GET | `/api/v1/services/{service}` | service |
| GET | `/api/v1/providers` | providers |
| GET | `/api/v1/providers/{provider}` | provider |
| GET | `/api/v1/services/{service}/availability` | serviceAvailability |

Se compara el prefijo del servidor OpenAPI `/api/v1` y cada path/método con el JSON real de `route:list`. Los GET también tienen HEAD automático de Laravel; no se cuentan como endpoints adicionales. `openapi.json` permanece idéntico al de 9B original.

La suite comprueba autenticación Bearer real del solicitante, roles vigentes y duales, rotación por dispositivo, máximo de tokens, independencia de otras sesiones/dispositivos, logout y revocación, caducidad, separación web/API, filtros y paginación, publicación pública/privada, disponibilidad informativa, campos sensibles, XSS y errores JSON seguros. También pasan las regresiones web y de workflows incorporadas desde main.

## Bases y migraciones

Se conservaron byte por byte las **36 migraciones históricas de main** y la migración adicional **`2026_11_04_000000_create_personal_access_tokens_table.php`** de 9B: **37 en total**. Sanctum se comprueba únicamente mediante la suite con SQLite **`:memory:`**, incluidas pruebas de adición/reversión e integridad en memoria.

No se ejecutaron migraciones manuales, seeders o rollbacks sobre bases persistentes. Antes y después del trabajo se compararon hashes privados de `.env` y SQLite existentes, sin cambios; no se publican sus contenidos o hashes. La APP_KEY persistente dentro de `.env` permanece intacta. Las claves efímeras y migraciones del bootstrap PHPUnit pertenecen exclusivamente al proceso aislado de pruebas.

No se modificaron instalaciones Laragon anteriores ni credenciales. La [guía actualizada para Windows/Laragon](LARAGON-9B-SEGURIDAD.md) usa un clon nuevo y pruebas en memoria. Windows y OAuth con una cuenta Google real no se ejecutaron en esta entrega; las pruebas Google emplean SDK/proveedor reales y transporte simulado.

## Estado de publicación

La rama existente se publica con un commit de merge cuyos padres son la cabeza anterior de 9B y el main indicado. Después del push se verifica por GitHub el SHA, `draft=true`, `merged=false` y `mergeable=true`; la confirmación remota se entrega en el PR y en el mensaje final. El estado Git local queda limpio. **No se fusiona el PR #15 ni se despliega**; se espera la validación independiente del usuario y ChatGPT.
