# W5.1 · Validación Windows/Laragon aislada

Usar PHP **8.2.28**, Composer **2.8.11**, `pdo_sqlite` y Node/npm compatibles con el lockfile (ensayo: Node 24.19.0 / npm 11.9.0). Las imágenes sintéticas no necesitan GD. No editar `php.ini` global: el límite de 256 MB se aplica al proceso.

El procedimiento usa un clon nuevo y un **SHA completo fijado antes de ejecutar**. No trabajar sobre Laragon anterior ni copiar `.env`, APP_KEY, credenciales, bases o medios. No ejecutar migraciones manuales, seeds, rollbacks ni `storage:link`; no iniciar un servidor ni enviar POST a instalaciones reales.

## Clon y versión exacta

Pegar el SHA **final de entrega W5.1** de la descripción del PR/entrega. No sustituirlo por `main`, el nombre de la rama o `HEAD`. La comprobación exige 40 caracteres para impedir validar una referencia flotante. El SHA se proporciona externamente porque un documento no puede contener el hash de su propio commit.

```powershell
$ErrorActionPreference = 'Stop'
$shaW51 = 'PEGAR_SHA_FINAL_COMPLETO_DE_LA_ENTREGA_W51'
if ($shaW51 -notmatch '^[0-9a-f]{40}$') { throw 'Fijar el SHA final completo de W5.1' }
$clonW51 = Join-Path 'C:\laragon\www' ('tekora-w51-' + [guid]::NewGuid().ToString('N'))
git clone --no-checkout --single-branch --branch codex/web-w51-photo-integrity https://github.com/Fernando261/tekora-project.git $clonW51
if ($LASTEXITCODE) { throw 'clone' }
Set-Location $clonW51
git checkout --detach $shaW51
if ($LASTEXITCODE -or (git rev-parse HEAD) -ne $shaW51) { throw 'SHA incorrecto' }
if (Test-Path .env) { throw 'El clon debe estar sin .env' }
php --version
composer --version
node --version
npm --version
php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if ($LASTEXITCODE) { throw 'Habilitar pdo_sqlite en el PHP seleccionado' }
$baseW51 = 'cc27543de2f7c24aa405c218b580cc3b52ed2532'
git cat-file -e ($baseW51 + '^{commit}')
if ($LASTEXITCODE) { throw 'Falta la base aprobada en el clon' }
```

El `TestCase` existente rechaza cualquier conexión distinta de SQLite `:memory:` antes de sus migraciones automatizadas. W5.1 cambia temporalmente el storage de cada prueba a un directorio aleatorio del sistema y usa `Storage::fake('public')`; lo retira al terminar. No se leen fotos reales ni se necesitan fixtures del usuario.

## Dependencias y auditorías

```powershell
composer validate --strict
if ($LASTEXITCODE) { throw 'validate' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE) { throw 'install' }
composer check-platform-reqs
if ($LASTEXITCODE) { throw 'platform' }
composer audit --locked
if ($LASTEXITCODE) { throw 'audit' }
composer audit --locked --no-dev
if ($LASTEXITCODE) { throw 'audit no-dev' }
```

`--no-scripts` evita scripts automáticos de instalación. Los lockfiles no se actualizan. El aviso histórico de ambigüedad de `AppServiceProvider`/Pint no se resuelve cambiando dependencias durante W5.1.

## Build y suites obligatorias

No borrar el manifiesto para pasar pruebas. Si existe un hot file en este clon desechable, se aparta con respaldo único y se restaura al terminar. Detener primero cualquier servidor Vite que pudiera recrearlo.

```powershell
$hotW51 = Join-Path $clonW51 'public\hot'
$respaldoHotW51 = Join-Path $env:TEMP ('tekora-w51-hot-' + [guid]::NewGuid().ToString('N'))
$teniaHotW51 = Test-Path $hotW51
if ($teniaHotW51) { Move-Item $hotW51 $respaldoHotW51 }
try {
    npm ci
    if ($LASTEXITCODE) { throw 'npm ci' }
    npm audit
    if ($LASTEXITCODE) { throw 'npm audit' }
    npm run build
    if ($LASTEXITCODE) { throw 'build' }
    if (!(Test-Path public/build/manifest.json) -or (Test-Path $hotW51)) { throw 'Build sin manifiesto o Vite dev activo' }

    php -d memory_limit=256M vendor/bin/phpunit --group photo-integrity-w51 --do-not-cache-result
    if ($LASTEXITCODE) { throw 'W5.1' }
    php -d memory_limit=256M vendor/bin/phpunit --exclude-group photo-integrity-w51 --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Baseline' }
    php -d memory_limit=256M vendor/bin/phpunit --group web-w5 --do-not-cache-result
    if ($LASTEXITCODE) { throw 'W5 original' }
    php -d memory_limit=256M vendor/bin/phpunit --filter 'AuthenticationTest|WebAccountsTest|IdentityRolesTest|TekoraW5Test::test_(personal_data|name_form|photo_upload|invalid_photo|existing_photo|name_photo)' --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Autenticación/perfil' }
    php -d memory_limit=256M vendor/bin/phpunit --filter 'CitasRegressionTest|RoutesAndGoogleSafetyTest|TekoraW11Test|InstallationTest|IdentityMigrationTest' --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Históricos' }
    php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Suite completa posterior al build' }
} finally {
    if ($teniaHotW51) {
        if (Test-Path $hotW51) { Write-Warning "Hot nuevo presente; respaldo original conservado en $respaldoHotW51" }
        else { Move-Item $respaldoHotW51 $hotW51 }
    }
}
```

Esperado: grupo W5.1 **49 pruebas / 459 aserciones**, baseline **577 / 7.799**, W5 original **29 / 397**, suite total **626 / 8.258**. Los grupos de autenticación/perfil e históricos se registran por separado en el informe. Cualquier variación requiere explicación; no ajustar tests antiguos para ocultarla.

Todas las pruebas de fallos delegan las operaciones no afectadas al disco falso/SQLite real. Incluyen excepción/`false`, escritura incompleta o corrupta, trigger SQL que aborta/ignora, veto del modelo, commit PDO fallido/`false`, error después del commit real y rollback no confirmado. No son mocks que declaren éxito de una operación fallida.

## Reproducción antes/después sin modificar producción

Ambos modos se ejecutan en procesos nuevos y cargan distintas clases del controlador en memoria. No reemplazan el archivo de producción. `before` exige el hash de la copia aprobada y demuestra el defecto; `after` usa el controlador del SHA validado. Ambos comprueban su resultado conocido y deben terminar con código 0.

```powershell
php -d memory_limit=256M docs/web/w51/evidence/reproduce-false-write.php before
if ($LASTEXITCODE) { throw 'Diagnóstico before' }
php -d memory_limit=256M docs/web/w51/evidence/reproduce-false-write.php after
if ($LASTEXITCODE) { throw 'Diagnóstico after' }
php -d memory_limit=256M vendor/bin/phpunit --filter test_a_false_write_preserves_the_old_reference_and_file_and_reports_photo_error --do-not-cache-result
if ($LASTEXITCODE) { throw 'Regresión equivalente' }
```

`before`: éxito incorrecto, referencia anterior perdida y archivo anterior ausente. `after`: error `photo`, sin éxito, referencia y bytes anteriores conservados, un único archivo falso. La prueba PHPUnit previa contra la base falló deliberadamente; su log se conserva en la evidencia.

## Hashes, contratos y cierre

Los siguientes comandos deben mostrar **cero cambios** en superficies protegidas; las dos únicas fuentes funcionales nuevas/cambiadas son el controlador y `ProfilePhotoIntegrityTest.php`.

```powershell
git diff --exit-code $baseW51 $shaW51 -- resources public routes config bootstrap database app/Models app/Policies app/Services app/Http/Middleware .github composer.json composer.lock package.json package-lock.json
if ($LASTEXITCODE) { throw 'Cambio fuera de alcance' }
php -d auto_prepend_file=tests/bootstrap.php artisan route:list --path=api -v
if ($LASTEXITCODE) { throw 'Rutas API' }
(Get-ChildItem database/migrations -File -Filter '*.php').Count
php vendor/bin/pint --test app/Http/Controllers/ProfileController.php tests/Feature/ProfilePhotoIntegrityTest.php docs/web/w51/evidence/reproduce-false-write.php
if ($LASTEXITCODE) { throw 'Pint' }
git diff --check
if ($LASTEXITCODE) { throw 'diff check' }
git status --short
```

Esperado: **21 operaciones API / 37 migraciones**; Composer con/sin dev y npm sin avisos. Los resultados de auditoría son una fotografía temporal: si aparece un aviso posterior, registrarlo, no actualizar el lockfile silenciosamente.

Para comparar los 17 archivos de build contra los hashes de la entrega (mismo toolchain):

```powershell
$assetsW51 = Get-Content docs/web/w51/evidence/asset-hashes.json -Raw | ConvertFrom-Json
foreach ($assetW51 in $assetsW51.files) {
    $archivoW51 = Join-Path $clonW51 ('public/build/' + $assetW51.file)
    if (!(Test-Path $archivoW51)) { throw "Falta $($assetW51.file)" }
    $hashW51 = (Get-FileHash $archivoW51 -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hashW51 -ne $assetW51.sha256) { throw "Hash distinto: $($assetW51.file). Registrar toolchain y revisar; no ocultar el manifiesto." }
}
```

Las vistas/estilos/scripts conservados y las pruebas del formulario/URL bastan para esta corrección funcional; no se repiten las 490 combinaciones visuales W5. No iniciar una preview para efectuar uploads ni probar con fotos personales.

La suite SQLite verifica intercalados deterministas, no concurrencia multiproceso real en MySQL. Revisar los [límites de commit, compensación y medios heredados](W51.md#concurrencia-y-límites). Si aparece un fallo, conservar logs sintéticos y el SHA, detener la validación y comunicarlo. No ejecutar operaciones de reparación contra instalaciones anteriores.

El PR sigue en borrador, sin fusionar ni desplegar, pendiente de revisión técnica y validación Windows del usuario.
