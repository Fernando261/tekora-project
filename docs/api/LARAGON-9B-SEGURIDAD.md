# PR #15 actualizado — validación independiente Windows/Laragon

Esta guía corresponde a la rama `codex/etapa-9b-api-movil-base` después de incorporar el merge de seguridad **`afb7f851fa8ea142e6639bc7c8431f541ef15dd9`**. Consultar el SHA de la rama y la evidencia actual en el PR #15 y en [INTEGRACION-SEGURIDAD-PR16.md](INTEGRACION-SEGURIDAD-PR16.md).

Usar un clon **nuevo** en `C:\laragon\www\tekora-etapa9b-seguridad-20261105`. No reutilizar `tekora-etapa9b` ni otras instalaciones. Esta validación no necesita `.env`, un servidor HTTP, una APP_KEY persistente ni una base de datos en disco. No se ejecutó Windows en la entrega; estos pasos permiten la revisión independiente del usuario.

## Requisitos

- PHP **8.2.28** y Composer **2.8.11**; revisar `php --version`, `php --ini` y `composer --version` desde la terminal Laragon.
- Extensiones requeridas por `composer check-platform-reqs`, incluidas PDO SQLite, SQLite3, DOM, XML/XMLWriter, mbstring, curl y OpenSSL. ZIP o unzip/7z para extraer dependencias.
- Node 22/24 y npm; Git.

## Clon aislado y comprobación de ancestry

```powershell
Set-Location C:\laragon\www
if (Test-Path .\tekora-etapa9b-seguridad-20261105) { throw 'La carpeta ya existe; usa otra nueva.' }
git clone --branch codex/etapa-9b-api-movil-base --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa9b-seguridad-20261105
if ($LASTEXITCODE -ne 0) { throw 'Falló el clon.' }
Set-Location .\tekora-etapa9b-seguridad-20261105
git rev-parse HEAD
# Comparar con el SHA actualizado entregado en el PR #15.
git merge-base --is-ancestor afb7f851fa8ea142e6639bc7c8431f541ef15dd9 HEAD
if ($LASTEXITCODE -ne 0) { throw 'No contiene el merge de seguridad.' }
git merge-base --is-ancestor 07cb6dfa9de1916535999c6a9403cc5be948ae28 HEAD
if ($LASTEXITCODE -ne 0) { throw 'No contiene la API 9B original.' }
php --version
php --ini
composer --version
```

No copiar `.env`, ejecutar `key:generate`, abrir el servidor con una configuración anterior ni introducir credenciales Google/producción. La clave efímera creada por `tests/bootstrap.php` solo existe dentro del proceso de prueba y no cambia APP_KEY de ninguna instalación.

## Comandos de validación

```powershell
composer validate --strict
if ($LASTEXITCODE -ne 0) { throw 'Manifiesto inválido.' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Instalación fallida.' }
composer check-platform-reqs
if ($LASTEXITCODE -ne 0) { throw 'Plataforma incompatible.' }
composer audit --locked
if ($LASTEXITCODE -ne 0) { throw 'Revisar avisos de seguridad.' }
composer audit --locked --no-dev
if ($LASTEXITCODE -ne 0) { throw 'Revisar avisos de producción.' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
npm.cmd audit
if ($LASTEXITCODE -ne 0) { throw 'Revisar avisos npm.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Falló el build.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló la suite completa.' }
php .\vendor\bin\phpunit --exclude-group composer-security --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló la suite original de 9B.' }
php artisan route:list --path=api -v
if ($LASTEXITCODE -ne 0) { throw 'Falló el listado API.' }
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Revisar espacios en el diff.' }
git status --short
```

Resultados de referencia medidos de nuevo en esta integración: **403 pruebas / 5165 aserciones**, y **396 / 5115** excluyendo solamente las siete pruebas nuevas de seguridad. Se esperan ocho endpoints API y cero avisos en ambas auditorías Composer y npm. Si aparecen nuevos avisos después de la fecha de entrega, revisarlos; no ignorarlos para obtener un cero histórico. El build conserva avisos Sass heredados que no son fallos.

`tests/bootstrap.php` y `Tests\TestCase` fuerzan SQLite **`:memory:`**, correo/cache/sesiones en memoria y comprueban ese aislamiento antes de migrar. Las migraciones invocadas internamente por la suite no usan bases en disco. **No ejecutar comandos manuales `migrate`, `migrate:fresh`, `migrate:refresh`, `db:seed` o rollback contra ninguna base persistente.**

## Versiones, migraciones y contrato API

```powershell
composer show --locked laravel/framework
composer show --locked google/apiclient
composer show --locked google/auth
composer show --locked firebase/php-jwt
composer show --locked laravel/sanctum
# Las 36 históricas no deben tener diferencias; Sanctum es la única adición frente a main.
git diff afb7f851fa8ea142e6639bc7c8431f541ef15dd9 -- database/migrations
(Get-ChildItem .\database\migrations\*.php).Count
# Esperado: 37. La migración de tokens se prueba solamente en memoria.
```

Versiones esperadas: Laravel **12.71.0**, Google API Client **2.20.1**, Google Auth **1.55.3**, JWT **7.2.1**, Sanctum **4.3.3**. La tabla completa está en el informe de integración.

Comparar el listado de rutas con [OpenAPI](openapi.json): POST `/api/v1/auth/login`, POST `/api/v1/auth/logout`, GET `/api/v1/me`, GET `/api/v1/services`, GET `/api/v1/services/{service}`, GET `/api/v1/providers`, GET `/api/v1/providers/{provider}` y GET `/api/v1/services/{service}/availability`. `HEAD` es el método automático de Laravel para los GET; no es un noveno endpoint.

Las pruebas automatizadas ejercitan el kernel HTTP real para Bearer, rotación, independencia entre dispositivos, logout, roles vigentes/duales, separación web/API, filtros, publicación, disponibilidad y errores seguros. Los tests de Google usan SDK/proveedor reales con transporte simulado, sin una cuenta externa. No se afirma haber probado OAuth con credenciales reales ni despliegue.

El PR debe permanecer **en borrador**. Validar este clon no autoriza fusionar, desplegar ni actualizar instalaciones anteriores. El usuario decide la integración después de su revisión con ChatGPT.
