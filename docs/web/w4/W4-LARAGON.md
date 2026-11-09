# W4 · Validación Windows/Laragon en un clon aislado

Base: `8599cff7e95f1eecd3c6b38890e5b089feaf1f49`. Usar el SHA final completo de la entrega del nuevo PR W4; no reutilizar #19 ni #20. No modificar instalaciones anteriores, `.env`, APP_KEY, credenciales o bases persistentes.

## Clon nuevo y escenario sin build

Abrir PowerShell con PHP **8.2.28**, Composer **2.8.11** y PDO SQLite disponibles. Registrar si las versiones difieren. No cambiar php.ini global; los 256 MB se aplican solo a PHPUnit.

```powershell
$cloneW4 = Join-Path 'C:\laragon\www' ('tekora-w4-' + [guid]::NewGuid().ToString('N').Substring(0,8))
$shaW4 = 'PEGAR_SHA_FINAL_COMPLETO_DE_LA_ENTREGA_W4'
if (Test-Path $cloneW4) { throw 'Usar una carpeta NUEVA.' }
if ($shaW4 -notmatch '^[0-9a-f]{40}$') { throw 'Pegar el SHA final de 40 caracteres.' }
git clone --no-checkout --single-branch --branch codex/web-w4-cinematic-experience https://github.com/Fernando261/tekora-project.git $cloneW4
if ($LASTEXITCODE -ne 0) { throw 'No se pudo clonar.' }
Set-Location $cloneW4
git switch --detach $shaW4
if ((git rev-parse HEAD) -ne $shaW4) { throw 'No coincide el SHA.' }
if (Test-Path '.env') { throw 'Este clon de validación debe permanecer sin .env.' }
php -v
composer --version
composer validate --strict
if ($LASTEXITCODE -ne 0) { throw 'Composer validate falló.' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer install falló.' }
composer check-platform-reqs
if ($LASTEXITCODE -ne 0) { throw 'La plataforma no es compatible.' }
composer audit --locked
if ($LASTEXITCODE -ne 0) { throw 'Revisar la auditoría Composer.' }
composer audit --locked --no-dev
if ($LASTEXITCODE -ne 0) { throw 'Revisar la auditoría sin dev.' }
php -d memory_limit=256M vendor/bin/phpunit --filter test_operational_assets_reference_both_real_manifest_files_or_both_hot_entries --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló el escenario Vite.' }
```

Antes del build, la variante sin manifiesto verifica los **dos recursos reales de entrada** en un public path/hotfile temporal propio. La variante que exige manifiesto se omite explícitamente si todavía no existe. No borrar un manifiesto real para preparar esta prueba. Composer puede conservar el aviso previo de ambigüedad de `AppServiceProvider` con Pint; no modificar autoload ni dependencias para ocultarlo.

## Build real y suites

Detener únicamente un servidor Vite de este clon si estuviera activo. Conservar el hotfile original y restaurarlo al terminar; no permitir que sustituya el manifiesto durante la validación.

```powershell
$hotW4 = Join-Path $cloneW4 'public/hot'
$savedHotW4 = Join-Path $env:TEMP ('tekora-w4-hot-' + [guid]::NewGuid().ToString('N'))
$hadHotW4 = Test-Path $hotW4
if ($hadHotW4) { Move-Item -LiteralPath $hotW4 -Destination $savedHotW4 }
try {
    npm ci
    if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
    npm audit
    if ($LASTEXITCODE -ne 0) { throw 'npm audit falló.' }
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'El build falló.' }
    if (!(Test-Path 'public/build/manifest.json')) { throw 'Falta el manifiesto real.' }
    if (Test-Path $hotW4) { throw 'Detener el Vite que recreó el hotfile.' }
    php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'La suite completa falló.' }
    php -d memory_limit=256M vendor/bin/phpunit --exclude-group web-w4 --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'La baseline falló.' }
    php -d memory_limit=256M vendor/bin/phpunit --group web-w4 --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'W4 falló.' }
} finally {
    if ($hadHotW4) {
        if (Test-Path $hotW4) { throw "Conservar $savedHotW4 y resolver el nuevo hotfile antes de restaurarlo." }
        Move-Item -LiteralPath $savedHotW4 -Destination $hotW4
    }
}
# Ejecución habitual, una vez restaurado el entorno del clon.
php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
php -d auto_prepend_file=tests/bootstrap.php artisan route:list --path=api -v
(Get-ChildItem database/migrations -Filter '*.php').Count
git diff --check
git status --short
```

Resultados de referencia: **548 pruebas / 7.402 aserciones**; baseline **501 / 6.896**; grupo `web-w4` **47 / 506**. El incremento corresponde exclusivamente al nuevo grupo. **37 migraciones y 21 operaciones API** sin cambios; Composer con/sin dev y npm con cero vulnerabilidades.

No ejecutar `key:generate`, migraciones manuales, seeds ni rollbacks. La migración automática del TestCase solo se permite después de comprobar testing + SQLite `:memory:` + DB_URL vacío. El bootstrap genera una clave efímera para ese proceso; no la copia a ningún `.env`.

## Preview de solo lectura

El ejemplo usa **8067**, independiente de W3 y de otras validaciones. Comprobar primero que esté libre y usar el mismo origen en export y servidor.

```powershell
$portW4 = 8067
$listenersW4 = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()
if ($listenersW4 | Where-Object { $_.Port -eq $portW4 }) { throw 'Elegir otro puerto loopback libre.' }
$originW4 = 'http://127.0.0.1:' + $portW4
$previewW4 = Join-Path $env:TEMP ('tekora-w4-preview-' + [guid]::NewGuid().ToString('N'))
if (Test-Path '.env') { throw 'No continuar con .env en este clon.' }
php -d memory_limit=256M tools/tekora-w4-preview.php $previewW4 $originW4
if ($LASTEXITCODE -ne 0) { throw 'Falló la exportación.' }
$safetyW4 = Get-Content (Join-Path $previewW4 'safety.json') -Raw | ConvertFrom-Json
if ($safetyW4.environment -ne 'testing' -or $safetyW4.connection -ne 'sqlite' -or $safetyW4.database -ne ':memory:' -or $safetyW4.environment_file_exists -or $safetyW4.environment_directory_is_repository -or $safetyW4.post_backend) { throw 'Aislamiento incorrecto: no abrir el preview.' }
$pagesW4 = Get-Content (Join-Path $previewW4 'w4-pages.json') -Raw | ConvertFrom-Json
$pagesW4.PSObject.Properties | ForEach-Object { '{0} -> {1} [{2}]' -f $_.Name, $_.Value.path, $_.Value.role }
$env:TEKORA_W4_PREVIEW_DIR = $previewW4
php -S ('127.0.0.1:' + $portW4) -t public tools/tekora-w4-preview-router.php
```

Las aplicaciones del export no leen el `.env` del repositorio: seleccionan un archivo inexistente en el directorio temporal antes del bootstrap. Los fixtures reconocibles como **Demostración** se crean en memoria con los workflows reales. La fecha sintética de referencia es enero de 2030. La conexión se descarta al terminar; el servidor posterior no arranca Laravel ni tiene backend POST.

Abrir `$originW4` y consultar los aliases de `w4-pages.json`:

| Recorrido | Aliases bajo `/preview/w4/` |
| --- | --- |
| Solicitudes | `request-create`, `request-validation`, `requests-sent`, `requests-received`, `request-detail`, `request-provider`, `requests-empty`, `request-invalid`, `inquiries-conflict` |
| Conversaciones | `conversations`, `conversation-older`, `conversation-readonly`, `conversations-empty`, `conversations-read-error` |
| Reservas | `reservations`, `reservation-propose`, `reservation-requested`, `reservation-provider`, `reservation-confirmed`, `reservation-expired`, `reservation-completed`, `reservations-error` |
| Reseñas | `reviews`, `review-create`, `review-published`, `review-withdrawn`, `reviews-error` |
| Herramientas | `availability`, `availability-empty`, `services`, `service-create`, `service-edit`, `service-inactive-category`, `services-empty`, `services-setup`, `profile`, `profile-show`, `profile-create` |
| Avisos y cuenta dual | `notifications`, `notifications-page-2`, `notifications-unavailable`, `notifications-empty`, `dual-sent`, `dual-request-detail` |

También se exportan destinos enlazados y retornos de paginación en `w4-linked-pages.json`. Las páginas públicas de ese export usan los mismos fixtures operativos; portada y dashboards de referencia mantienen su dataset de demostración propio. Los aliases son snapshots de roles, **no sesiones**: no introducir credenciales ni interpretar un cambio de página como una operación de negocio.

El filtro nativo de solicitudes puede revisarse con estado vacío o `pending`, y con la segunda página exportada. La conversación incluye ambas direcciones del cursor. El conjunto es finito: una consulta no exportada devuelve 404, nunca un listado falso. Las vistas exclusivas de errores POST se etiquetan en JSON como presentación con mensaje sintético; no representan una operación ejecutada.

Los formularios conservan su HTML real. Se puede escribir, elegir estrellas y añadir/quitar turnos, pero **enviarlos devuelve 405**. No se simula aceptación, lectura, reserva confirmada, publicación ni retirada. POST/PATCH reales, permisos, versiones e idempotencia se verifican con PHPUnit en memoria. GET/HEAD sirven solo el export y recursos locales permitidos; `.env` y API no se exponen.

## Revisión manual

1. Probar **320/375/768/1024/1100/1280/1440/1920 px**, desktop y móvil; sin scroll horizontal ni campos cortados.
2. Comparar con [capturas y videos](W4.md): tres escenas con profundidad distinta, formularios fijos y tarjetas con reflejo contenido. No debe moverse un botón bajo el puntero.
3. Seguir solicitud → conversación → propuesta → reserva → reseña. Revisar también recibidas, cuenta dual, historial, expiradas, retiradas, validación y errores.
4. Confirmar que abrir una bandeja o detalle no anuncia lecturas ni decisiones automáticas. La propuesta es explícitamente distinta de una reserva confirmada.
5. Recorrer Tab/Shift+Tab y foco visible. Revisar labels, errores, estados con texto y lectura con NVDA/Narrador.
6. Desactivar JavaScript: formularios, cinco radios de estrellas, valores, filtros y paginación permanecen. El editor ofrece filas nativas; añadir/quitar es mejora progresiva del script original.
7. Activar movimiento y transparencia reducidos; revisar touch/trackpad físicos y orientación horizontal. Las escenas táctiles son estáticas.
8. Zoom **nativo 200 %** en todas las familias: campos, botones, historial y conversaciones. No sustituirlo por CSS zoom o emulación.
9. Conservar visibles las advertencias de datos personales, ausencia de pagos/verificación y retirada irreversible.
10. Verificar continuidad con W3 y dashboards/sidebar aprobados. No probar compatibilidad borrando datos, desactivando guards o alterando permisos.

Si ya se dispone de Python Playwright, Chromium/Chrome, su FFmpeg y Axe como herramientas externas, sin añadir dependencias al proyecto:

```powershell
python tools/tekora-w4-browser-checks.py --base-url $originW4 --pages (Join-Path $previewW4 'w4-pages.json') --output (Join-Path $env:TEMP ('tekora-w4-browser-' + [guid]::NewGuid().ToString('N'))) --axe 'RUTA_A_AXE_MIN_JS' --executable 'RUTA_A_CHROME_O_CHROMIUM' --record
python tools/tekora-w4-zoom-check.py --base-url $originW4 --pages (Join-Path $previewW4 'w4-pages.json') --output (Join-Path $env:TEMP ('tekora-w4-zoom-' + [guid]::NewGuid().ToString('N'))) --executable 'RUTA_A_CHROME_O_CHROMIUM'
```

El script de zoom usa ajustes nativos de Chromium en un perfil temporal que elimina al terminar; no toca el perfil personal. Si esa API no está disponible en Windows, grabar manualmente 100→200→100. Los resultados headless/Linux no certifican GPU física, Firefox/Safari, lectores de pantalla ni Windows.

Cerrar el servidor con Ctrl+C y limpiar exclusivamente el export creado:

```powershell
Remove-Item Env:TEKORA_W4_PREVIEW_DIR
Remove-Item -Recurse -LiteralPath $previewW4
git status --short
```

Mantener el PR W4 en borrador. No fusionar ni desplegar; esperar revisión independiente y aprobación visual.
