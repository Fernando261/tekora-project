# W5 · Validación independiente Windows/Laragon

Usar un clon nuevo, PHP 8.2.28, Composer 2.8.11 y SQLite habilitado. No trabajar sobre la instalación del usuario. No copiar `.env`, APP_KEY, credenciales, BD ni medios reales. No ejecutar `key:generate`, migraciones manuales, seeds ni rollbacks. PHPUnit y el exportador usan SQLite `:memory:` y fotos falsas; no necesitan GD para las fixtures de imagen.

## Clon y SHA exacto

En PowerShell, pegar como `$shaW5` el SHA completo de 40 caracteres de la entrega/PR W5. El valor es deliberadamente externo al commit que documenta el procedimiento.

```powershell
$ErrorActionPreference = 'Stop'
$shaW5 = 'PEGAR_SHA_FINAL_COMPLETO_DE_LA_ENTREGA_W5'
if ($shaW5 -notmatch '^[0-9a-f]{40}$') { throw 'Pegar SHA completo de la entrega W5' }
$clonW5 = Join-Path 'C:\laragon\www' ('tekora-w5-' + [guid]::NewGuid().ToString('N'))
git clone --no-checkout --single-branch --branch codex/web-w5-cinematic-identity https://github.com/Fernando261/tekora-project.git $clonW5
if ($LASTEXITCODE) { throw 'clone' }
Set-Location $clonW5
git checkout --detach $shaW5
if ($LASTEXITCODE -or (git rev-parse HEAD) -ne $shaW5) { throw 'SHA incorrecto' }
if (Test-Path .env) { throw 'El clon debe estar sin .env' }
php --version
composer --version
php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if ($LASTEXITCODE) { throw 'Falta pdo_sqlite' }
composer validate --strict
if ($LASTEXITCODE) { throw 'composer validate' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE) { throw 'composer install' }
composer check-platform-reqs
if ($LASTEXITCODE) { throw 'platform' }
composer audit --locked
if ($LASTEXITCODE) { throw 'audit' }
composer audit --locked --no-dev
if ($LASTEXITCODE) { throw 'audit no-dev' }
```

## Dos escenarios de assets y suite completa

La prueba seleccionada usa un directorio público virtual para probar explícitamente el entorno sin build y el hot file de desarrollo. Cuando existe el manifiesto real, resuelve y exige ambos archivos con hash en HTML. Antes del primer build, solo esa variante real puede quedar marcada como omitida. No borrar ni ocultar el manifiesto para conseguir que pase.

```powershell
php -d memory_limit=256M vendor/bin/phpunit --filter test_identity_assets_reference_actual_js_and_css_with_a_manifest_or_without_a_build --do-not-cache-result
if ($LASTEXITCODE) { throw 'Assets antes del build' }

$hotW5 = Join-Path $clonW5 'public\hot'
$backupHotW5 = Join-Path $env:TEMP ('tekora-w5-hot-' + [guid]::NewGuid().ToString('N'))
$teniaHotW5 = Test-Path $hotW5
if ($teniaHotW5) { Move-Item $hotW5 $backupHotW5 }
try {
    npm ci
    if ($LASTEXITCODE) { throw 'npm ci' }
    npm audit
    if ($LASTEXITCODE) { throw 'npm audit' }
    npm run build
    if ($LASTEXITCODE) { throw 'build' }
    if (!(Test-Path public/build/manifest.json)) { throw 'Falta manifiesto' }
    if (Test-Path $hotW5) { throw 'Hay servidor Vite dev activo; detenerlo' }
    php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Suite después del build' }
    php -d memory_limit=256M vendor/bin/phpunit --exclude-group web-w5 --do-not-cache-result
    if ($LASTEXITCODE) { throw 'Baseline' }
    php -d memory_limit=256M vendor/bin/phpunit --group web-w5 --do-not-cache-result
    if ($LASTEXITCODE) { throw 'W5' }
} finally {
    if ($teniaHotW5) {
        if (Test-Path $hotW5) { Write-Warning "Hot nuevo presente; respaldo conservado en $backupHotW5" }
        else { Move-Item $backupHotW5 $hotW5 }
    }
}
# Ejecución habitual, después de restaurar el estado local del hot file:
php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
if ($LASTEXITCODE) { throw 'Suite habitual' }
php -d auto_prepend_file=tests/bootstrap.php artisan route:list --path=api -v
if ($LASTEXITCODE) { throw 'Rutas' }
(Get-ChildItem database/migrations -File -Filter '*.php').Count
git diff --check
if ($LASTEXITCODE) { throw 'diff check' }
git status --short
```

Resultado esperado después del build: **577 pruebas / 7.799 aserciones**; baseline excluyendo W5: **548 / 7.402**; grupo W5: **29 / 397**; 37 migraciones y 21 operaciones API. Auditorías Composer con/sin dev y npm: cero avisos. La configuración de prueba rechaza conexiones persistentes; `memory_limit=256M` es temporal al proceso, sin editar `php.ini` global. Cualquier variación debe investigarse, no ajustar pruebas antiguas.

## Preview GET real exportado y servidor estático

Reservar un puerto libre (8077 sugerido), sin reutilizar servidores previos. El exportador usa fixtures SQLite en memoria, `Storage::fake`, `Notification::fake` y un token no funcional. No lee `.env` ni fotos reales. Genera HTML de GET reales; los tres errores autónomos se renderizan con un mensaje sintético, sin simular una operación fallida. Los estados de correo enviado/error se representan mediante sesión sintética y **no prueban envío de correo**.

```powershell
$puertoW5 = 8077
$ocupadoW5 = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners() | Where-Object Port -eq $puertoW5
if ($ocupadoW5) { throw 'Puerto ocupado: elegir otro y no detener instalaciones anteriores' }
$previewW5 = Join-Path $env:TEMP ('tekora-w5-preview-' + [guid]::NewGuid().ToString('N'))
$origenW5 = "http://127.0.0.1:$puertoW5"
php -d memory_limit=256M tools/tekora-w5-preview.php $previewW5 $origenW5
if ($LASTEXITCODE) { throw 'Export W5' }
$safetyW5 = Get-Content (Join-Path $previewW5 'w5-safety.json') -Raw | ConvertFrom-Json
if ($safetyW5.environment -ne 'testing' -or $safetyW5.connection -ne 'sqlite' -or $safetyW5.database -ne ':memory:' -or $safetyW5.environment_file_exists -or $safetyW5.post_backend -or $safetyW5.mail_backend -or !$safetyW5.storage_fake -or $safetyW5.real_media_copied) { throw 'Preview insegura' }
$env:TEKORA_W5_PREVIEW_DIR = $previewW5
php -S "127.0.0.1:$puertoW5" -t public tools/tekora-w5-preview-router.php
```

Abrir `/preview/w5/account-client`. Todos los estados W5 bajo `/preview/w5/`:

| Familia | Alias |
| --- | --- |
| Cuenta | `account-client`, `account-provider`, `account-dual`, `account-admin`, `account-none`; `account-guest` redirige al login |
| Activación | `activation-pending`, `activation-active` |
| Perfil | `profile-photo`, `profile-no-photo`, `photo-validation` |
| Nombre | `edit-name`, `name-validation` |
| Roles | `roles` |
| Recuperación | `forgot`, `forgot-sent`, `forgot-validation`, `reset`, `reset-invalid` |
| Errores | `client-error`, `provider-error`, `session-expired` (503, 503 y 419) |

Los alias de rol son instantáneas independientes, no sesiones intercambiables. `/cuenta` representa la fixture cliente. Tres destinos adicionales (dashboard cliente, formulario y dashboard prestador) son GET reales enlazados. Las referencias heredadas W1–W4 conservan fixtures independientes.

Seleccionar un archivo solo cambia el control nativo; no lo envía automáticamente. No introducir credenciales ni archivos personales. El router no inicia Laravel: solo admite GET/HEAD; POST/PUT/PATCH/DELETE responden 405, `.env`, `/api` y fotos ajenas responden 404. Sirve únicamente la foto sintética exacta sin `storage:link`. Consultas no exportadas responden 404, sin sustituirlas por una pantalla de éxito. El marcador de reset es literal, no un token del broker; la validación real de tokens ocurre en PHPUnit con notificaciones falsas.

## Revisión visual y accesible

Revisar 320, 375, 768, 1024, 1100, 1280, 1440 y 1920 px. Comparar capturas con [la evidencia W5](media/). Verificar teclado y foco, menú compacto, etiquetas, errores, controles nativos y ausencia de overflow. Los formularios deben funcionar sin JS; los tres escenarios 3D solo decoran, sin desplazar los controles. La foto sintética y el monograma tienen estados propios. Roles sigue siendo de consulta; ningún acceso aparece sin autorización.

Activar movimiento reducido y emulación táctil; la escena debe quedar estática. Aplicar zoom **nativo del navegador** 100 → 200 → 100 % en las siete familias, sin CSS zoom ni emulación de escala. Validar físicamente Windows/Laragon, GPU y NVDA: la evidencia Linux/Chromium no sustituye esa revisión.

Automatización opcional externa al proyecto (Python, Playwright, Chromium/Chrome, FFmpeg y axe-core locales; no añadirlos a npm/Composer). Guardar resultados en directorios nuevos:

```powershell
$axeW5 = 'C:\herramientas\axe.min.js'
$chromeW5 = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
python tools/tekora-w5-browser-checks.py --base-url $origenW5 --pages (Join-Path $previewW5 'w5-pages.json') --output (Join-Path $env:TEMP ('w5-browser-' + [guid]::NewGuid().ToString('N'))) --axe $axeW5 --executable $chromeW5 --record
if ($LASTEXITCODE) { throw 'Browser W5' }
python tools/tekora-w5-zoom-check.py --base-url $origenW5 --pages (Join-Path $previewW5 'w5-pages.json') --output (Join-Path $env:TEMP ('w5-zoom-' + [guid]::NewGuid().ToString('N'))) --executable $chromeW5
if ($LASTEXITCODE) { throw 'Zoom W5' }
```

La herramienta de zoom crea un perfil temporal y usa la API nativa de Chrome. Si el navegador instalado bloquea esa instrumentación, realizar la comprobación manual nativa y registrar el resultado; no sustituirla por CSS zoom.

Detener únicamente este servidor con Ctrl+C. Retirar `TEKORA_W5_PREVIEW_DIR` de esta consola y, si ya no se necesita, borrar únicamente `$previewW5`. Comprobar `git status --short`. No tocar otros clones ni servidores.

El [inventario](W5-MINDBLOOM-INVENTORY.md) documenta un fallo preexistente de integridad si falla la escritura de una foto. Solo se reprodujo con almacenamiento falso; su corrección funcional requiere otro PR. W5 permanece en borrador, sin fusión ni despliegue, pendiente de revisión independiente.
