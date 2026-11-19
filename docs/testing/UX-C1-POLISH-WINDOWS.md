# UX-C1 — Repetir el pulido en Windows

Usar PowerShell y copias nuevas fuera de Laragon. No copiar `.env`, bases ni credenciales, iniciar MySQL/Docker ni ejecutar migraciones manualmente. La verificación nativa en Windows sigue pendiente hasta ejecutar estos pasos allí; un PASS de Linux no la sustituye.

## Recuperar y verificar

Importar primero el bundle original siguiendo su guía: debe conservar el commit `d9e916c365dd8d53b0f38ee8317c20f2f7e7278c`, padre `3015d5ffb717dc3772682e7b6f17be451b4d86ed` y árbol `3bf6ce556f89d6b2b8a307073e67df3593f3ec5d`. El bundle incremental de pulido entregado requiere ese commit original. Comprobar su SHA-256 publicado, ejecutar `git bundle verify` y `git bundle list-heads` antes de importar. No usar force ni reconstruir commits. El informe de entrega contiene el SHA y árbol finales.

## Pruebas automatizadas

Repetir por separado con PHP 8.2 y 8.4 en PATH. Requiere PHP CLI con pdo_sqlite, Composer, Git, Node y npm. Los JUnit y logs quedan fuera del checkout.

```powershell
$ErrorActionPreference = 'Stop'
$revision = 'SHA_FINAL_ENTREGADO' # sustituir, no usar main
$id = [Guid]::NewGuid().ToString('N')
$testRoot = Join-Path ([IO.Path]::GetTempPath()) "tekora-ux-c1-$id"
$evidence = Join-Path ([IO.Path]::GetTempPath()) "tekora-ux-c1-evidence-$id"
git worktree add --detach $testRoot $revision
if ($LASTEXITCODE -ne 0) { throw 'No se creó la copia aislada' }
New-Item -ItemType Directory -Path $evidence | Out-Null
Push-Location $testRoot
$ownedTestingFile = $false
try {
    foreach ($path in @('.env', '.env.testing', 'bootstrap/cache/config.php', 'database/database.sqlite')) {
        if (Test-Path -LiteralPath $path) { throw "Copia no aislada: $path" }
    }
    New-Item -ItemType File -Path '.env.testing' -ErrorAction Stop | Out-Null
    $ownedTestingFile = $true
    php --version | Tee-Object (Join-Path $evidence 'php-version.log')
    if ($LASTEXITCODE -ne 0) { throw 'PHP no disponible' }
    php --ri pdo_sqlite | Tee-Object (Join-Path $evidence 'sqlite-version.log')
    if ($LASTEXITCODE -ne 0) { throw 'pdo_sqlite no disponible' }
    composer install --no-scripts --prefer-dist --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Composer falló' }
    npm ci
    if ($LASTEXITCODE -ne 0) { throw 'npm ci falló' }
    npm run build 2>&1 | Tee-Object (Join-Path $evidence 'build.log')
    if ($LASTEXITCODE -ne 0) { throw 'Build falló' }
    $affected = @('tests/Feature/ContractingJourneyPolishTest.php', 'tests/Feature/ContractingJourneyTest.php', 'tests/Feature/WebPricingTest.php')
    foreach ($suite in @('affected', 'full')) {
        $arguments = @('vendor/bin/phpunit', '--log-junit', (Join-Path $evidence "$suite.xml"))
        if ($suite -eq 'affected') { $arguments += $affected }
        php @arguments 2>&1 | Tee-Object (Join-Path $evidence "$suite.log")
        $code = $LASTEXITCODE
        Set-Content (Join-Path $evidence "$suite-exit.txt") $code
        if ($code -ne 0) { throw "Falló $suite; conservar evidencias" }
    }
    php vendor/bin/pint --test app/Http/Controllers/WebPricingController.php tests/Feature/ContractingJourneyPolishTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Pint falló' }
} finally {
    try {
        if ($ownedTestingFile) {
            if ((Get-Item -LiteralPath '.env.testing').Length -ne 0) { throw 'El temporal cambió; conservarlo' }
            Remove-Item -LiteralPath '.env.testing' -ErrorAction Stop
        }
        git status --porcelain
    } finally { Pop-Location }
}
```

El bootstrap exige `APP_ENV=testing`, SQLite `:memory:`, DB_URL vacío y una ruta de caché única. El archivo `.env.testing` es vacío y se elimina solo si lo creó este procedimiento y sigue vacío. No crear `.env` persistente. La suite T1 es separada y no está incluida en `phpunit.xml`.

## Revisión de navegador

Las evidencias entregadas incluyen el arnés externo de navegador y fixtures sintéticas; no forman parte de la aplicación ni deben copiarse a producción. Para reproducirlo se necesitan Python, Playwright y Chromium; usar el procedimiento `REPETIR-NAVEGADOR.md` del paquete, que crea directorios nuevos propios, sin credenciales fijas. No usar una instalación Laragon existente como servidor de pruebas.

Comprobar 320, 375, 430, 768, 1024, 1440 y 2048 px con dos sesiones independientes. Desde catálogo: solicitar → conversar → aceptar solicitud → aceptar cotización → proponer → aceptar horario → verificar 900 segundos → confirmar definitivamente → registrar realización después del fin → cerrar → reseñar. El arnés avanza únicamente el reloj del proceso sintético para llegar al fin del servicio; nunca el reloj del equipo ni las reglas del producto.

En una reserva confirmada con precio antiguo y catálogo más reciente, debe dominar el importe vinculado. El historial permanece fuera del desplegable; el catálogo y la emisión adicional son secundarios. Aceptar otra cotización no debe cambiar reserva, retención, eventos, precio acordado ni contexto móvil. Verificar también legacy, cancelaciones, rechazos, vencimientos y completados. Ninguna pantalla acredita pago.
