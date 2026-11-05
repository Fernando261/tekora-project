# Validación independiente en Windows / Laragon

Usar una carpeta nueva: **`C:\laragon\www\tekora-seguridad-composer-202611`**. No reutilizar un proyecto existente, copiar `.env`, generar claves ni abrir bases de otras instalaciones. No se necesita un servidor HTTP o Google real para las pruebas automatizadas.

## Clon y comprobaciones

PHP **8.2.28**, Composer **2.8.11**, extensiones del README, Node 22/24 y npm. Revisar `php --ini` para confirmar que se ejecuta el PHP esperado. Composer necesita ZIP habilitado o `unzip`/7z para extraer archivos; esta herramienta no es una dependencia nueva de la aplicación.

```powershell
Set-Location C:\laragon\www
if (Test-Path .\tekora-seguridad-composer-202611) { throw 'La carpeta ya existe; usa otra nueva.' }
git clone --branch codex/seguridad-composer-noviembre-2026 --single-branch https://github.com/Fernando261/tekora-project.git tekora-seguridad-composer-202611
Set-Location .\tekora-seguridad-composer-202611
git rev-parse HEAD
git merge-base --is-ancestor f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 HEAD
if ($LASTEXITCODE -ne 0) { throw 'Falta la base 9A.' }
php --version
php --ini
composer --version
composer validate
if ($LASTEXITCODE -ne 0) { throw 'Manifiesto inválido.' }
composer validate --strict
if ($LASTEXITCODE -ne 0) { throw 'Validación estricta fallida.' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Instalación fallida.' }
composer check-platform-reqs
if ($LASTEXITCODE -ne 0) { throw 'Plataforma incompatible.' }
composer audit --locked
if ($LASTEXITCODE -ne 0) { throw 'Hay avisos nuevos: revisar antes de continuar.' }
composer audit --locked --no-dev
if ($LASTEXITCODE -ne 0) { throw 'Hay avisos de producción: revisar.' }
# No ejecutar scripts de creación de proyecto ni migraciones manuales.
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
npm.cmd audit
if ($LASTEXITCODE -ne 0) { throw 'npm audit detectó avisos.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Build fallido.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló PHPUnit.' }
php artisan route:list --except-vendor
if ($LASTEXITCODE -ne 0) { throw 'Falló el listado de rutas.' }
git diff --exit-code f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 -- database/migrations app routes config .env.example
if ($LASTEXITCODE -ne 0) { throw 'Hay cambios fuera del alcance esperado.' }
git diff --check
git status --short
```

Comparar el SHA con el PR. Los conteos y códigos realmente observados están en [VALIDACION.md](VALIDACION.md). Las auditorías se consultan de nuevo: si aparecen avisos posteriores no ignorarlos para reproducir un cero histórico.

PHPUnit usa únicamente SQLite **`:memory:`**, sesiones/cache/correo en memoria y una APP_KEY efímera del proceso de pruebas. Su bootstrap comprueba el aislamiento antes de migrar. No ejecutar `migrate`, `migrate:fresh`, `migrate:refresh`, `db:seed`, `key:generate` ni rollback sobre una base persistente. No abrir el servidor con conexiones de una instalación existente. No se modifican `.env`, claves o sesiones de otros proyectos.

## Ensayo opcional de compatibilidad con PR #15

Esta sección es local y descartable; no hacer push, commit de merge, actualizar el PR #15 ni crear otro PR. Partir del clon de seguridad ya validado. Obtener exactamente el código que se quiere revisar y registrar su SHA:

```powershell
git fetch origin pull/15/head:refs/remotes/origin/pr15-validation
git rev-parse refs/remotes/origin/pr15-validation
# Referencia del ensayo entregado: 07cb6dfa9de1916535999c6a9403cc5be948ae28
if (Test-Path ..\tekora-seguridad-pr15-local) { throw 'El worktree temporal ya existe.' }
git worktree add --detach ..\tekora-seguridad-pr15-local HEAD
Set-Location ..\tekora-seguridad-pr15-local
git merge --no-commit --no-ff refs/remotes/origin/pr15-validation
# Revisar conflictos. Los observados en cloud fueron únicamente Composer.
# Conservar manifiesto/lock de seguridad, después incorporar Sanctum mediante Composer.
git restore --source=HEAD --staged --worktree composer.json composer.lock
composer require laravel/sanctum:^4.0 --no-update --no-interaction
composer update laravel/sanctum --with-all-dependencies --minimal-changes --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'No se pudo resolver el ensayo.' }
git add composer.json composer.lock
composer validate --strict
composer check-platform-reqs
composer audit --locked
composer audit --locked --no-dev
npm.cmd ci
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
# Reproducir por separado las pruebas originales de 9B, excluyendo solo las nuevas de este PR:
php .\vendor\bin\phpunit --exclude-group composer-security --do-not-cache-result
php artisan route:list --path=api -v
# No ejecutar una migración manual: Sanctum también se valida solo en memoria.
```

Si aparecen conflictos distintos, detenerse a revisarlos; no restaurar de forma indiscriminada archivos de aplicación. Todas las resoluciones permanecen únicamente en este worktree detached. Tras guardar los resultados, puede abortarse el merge local con `git merge --abort`; no hay integración remota.

## Alcance de la evidencia Google

Las pruebas usan Google API Client, Google Auth y el proveedor Socialite reales con Guzzle MockHandler: OAuth con state correcto/incorrecto, renovación, conservación de refresh token, Calendar FreeBusy, creación de evento y errores sanitizados. Se comprueban firmas RS256 válidas, alteradas y con clave insuficiente, y la descarga PDF real del controlador.

No se usaron credenciales ni cuentas Google reales. Un ensayo manual con Google externo requiere una cuenta de validación autorizada y una instalación aislada adicional; no habilitarlo usando sesiones o claves existentes como parte de este procedimiento de pruebas en memoria. La ausencia de ese ensayo en vivo se registra como limitación, no como validación realizada.
