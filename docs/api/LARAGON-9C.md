# Etapa 9C — validación Windows/Laragon sin bases persistentes

Usar **un clon nuevo**: `C:\laragon\www\tekora-etapa9c-api-20261105`. No tocar `tekora-etapa9b`, `tekora-seguridad-composer-202611`, `tekora-etapa9b-seguridad-20261105` u otros proyectos existentes. Esta guía no inicia un servidor ni requiere una base en disco o credenciales externas.

Requisitos: PHP **8.2.28**, Composer **2.8.11**, Node 22/24, npm, Git, extensiones DOM/XML, mbstring, curl, OpenSSL, PDO SQLite/SQLite3 y ZIP o unzip/7z. Revisar el PHP activo con `php --ini`.

```powershell
Set-Location C:\laragon\www
if (Test-Path .\tekora-etapa9c-api-20261105) { throw 'La carpeta ya existe; usar otra nueva.' }
git clone --branch codex/etapa-9c-api-movil-operaciones --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa9c-api-20261105
if ($LASTEXITCODE -ne 0) { throw 'Falló el clon.' }
Set-Location .\tekora-etapa9c-api-20261105
git rev-parse HEAD
# Comparar el SHA con el PR borrador de 9C.
git merge-base --is-ancestor 1f0926ca9fc0bf2c70a34c1e64af0b98a31d6957 HEAD
if ($LASTEXITCODE -ne 0) { throw 'No contiene la base esperada.' }
php --version
php --ini
composer --version
composer validate --strict
if ($LASTEXITCODE -ne 0) { throw 'Manifiesto inválido.' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Instalación fallida.' }
composer check-platform-reqs
if ($LASTEXITCODE -ne 0) { throw 'Requisitos no satisfechos.' }
composer audit --locked
if ($LASTEXITCODE -ne 0) { throw 'Revisar nuevos avisos.' }
composer audit --locked --no-dev
if ($LASTEXITCODE -ne 0) { throw 'Revisar avisos de producción.' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
npm.cmd audit
if ($LASTEXITCODE -ne 0) { throw 'Revisar avisos npm.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Build fallido.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló la suite completa.' }
# Reproducir por separado las 403 pruebas originales, sin modificar ninguna:
php .\vendor\bin\phpunit --exclude-group mobile-operations --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló la regresión de la base.' }
# Inspección detallada de los nuevos escenarios HTTP/JSON en memoria:
php .\vendor\bin\phpunit --group mobile-operations --testdox --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló una operación nueva.' }
php artisan route:list --path=api -v
if ($LASTEXITCODE -ne 0) { throw 'Falló el listado de rutas.' }
git diff --exit-code 1f0926ca9fc0bf2c70a34c1e64af0b98a31d6957 -- database/migrations composer.json composer.lock package.json package-lock.json
if ($LASTEXITCODE -ne 0) { throw 'Cambios inesperados en esquema o dependencias.' }
git diff --check
git status --short
```

Consultar los **conteos reales** y códigos de la entrega en [VALIDACION-9C.md](VALIDACION-9C.md). Las auditorías se consultan nuevamente: si aparecen avisos posteriores, revisarlos sin ignorarlos para reproducir un cero histórico. Se esperan **21 operaciones API** y **37 migraciones sin modificaciones**. Los HEAD automáticos de GET no se cuentan por separado.

**No copiar .env, ejecutar key:generate ni introducir APP_KEY persistente.** No ejecutar manualmente migrate, migrate:fresh, migrate:refresh, db:seed o rollback sobre bases de desarrollo/producción. Composer se instala sin scripts de creación de proyecto. PHPUnit genera una clave efímera del proceso, fuerza SQLite `:memory:`, cache/sesiones/correo en memoria y verifica ese aislamiento antes de migrar. Las pruebas de concurrencia también verifican una base SQLite compartida exclusivamente en memoria, sin archivo.

Las pruebas Feature realizan peticiones JSON al kernel HTTP real, con tokens Sanctum reales y fixtures sintéticos que desaparecen al terminar el proceso. Cubren el recorrido solicitud → conversación → lectura de recibidos → opciones con revisión → propuesta → cancelación. No enviar los ejemplos de escritura del OpenAPI a un backend de una instalación anterior. Para revisar los payloads y cómo manejar UUID/cursor/read_token/version/revisión desde Expo, usar [ETAPA-9C.md](ETAPA-9C.md) y [OpenAPI](openapi.json).

No se requiere ni se afirma haber probado Expo, Google real o un dispositivo físico. Los tests de dependencias Google heredados mantienen el transporte simulado. No ejecutar escrituras Calendar u otras integraciones reales para validar 9C.

El PR permanece **en borrador**. El usuario y ChatGPT realizan la validación independiente y deciden cuándo integrar. No fusionar ni desplegar desde estos pasos.
