# B3.2 — Validación aislada de Windows, sin bases comerciales
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ($project -match '(?i)[\\/]laragon[\\/]' -or
    (Test-Path (Join-Path $project '.env')) -or
    (Test-Path (Join-Path $project 'bootstrap/cache/config.php'))) {
    throw 'Validación permitida solo desde una copia nueva fuera de Laragon, sin entorno existente.'
}
foreach ($p in @('vendor/autoload.php','vendor/bin/phpunit','tools/tekora-b32-fixture.php')) {
    if (-not (Test-Path (Join-Path $project $p))) { throw "Falta $p. Instala dependencias de desarrollo en esta copia aislada." }
}
Push-Location $project
$names = @('APP_ENV','APP_DEBUG','APP_KEY','APP_URL','APP_CONFIG_CACHE','DB_CONNECTION','DB_DATABASE','DB_URL','CACHE_STORE','SESSION_DRIVER')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
try {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $env:APP_KEY = 'base64:' + [Convert]::ToBase64String($bytes)
    $env:APP_ENV = 'testing'
    $env:APP_DEBUG = 'false'
    $env:APP_URL = 'http://127.0.0.1:8097'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = ':memory:'
    $env:DB_URL = ''
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:APP_CONFIG_CACHE = Join-Path $env:TEMP ('tekora-b32-no-config-' + [guid]::NewGuid().ToString('N') + '.php')
    $evidence = Join-Path $env:TEMP ('tekora-b32-validation-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $evidence -ErrorAction Stop | Out-Null

    foreach ($file in @('app/Http/Controllers/LocalIntegratedCheckoutController.php',
        'app/Payments/LocalCheckoutAttemptStore.php', 'tools/tekora-b32-fixture.php',
        'tests/Feature/LocalCheckoutAttemptStoreTest.php',
        'tests/Feature/LocalIntegratedCheckoutHttpTest.php',
        'tests/Feature/LocalIntegratedCheckoutBoundaryTest.php')) {
        & php -l $file
        if ($LASTEXITCODE -ne 0) { throw ('PHP lint FAILED: ' + $file) }
    }

    & php vendor/bin/phpunit --filter 'LocalIntegratedCheckout|LocalCheckoutAttemptStore|CheckoutContextApiTest|ReservationSlotHoldsTest' --log-junit (Join-Path $evidence 'affected.xml')
    if ($LASTEXITCODE -ne 0) { throw ('Subconjunto funcional FAILED. Evidencia: ' + $evidence) }

    & php vendor/bin/phpunit --log-junit (Join-Path $evidence 'full.xml')
    if ($LASTEXITCODE -ne 0) { throw ('Suite completa FAILED. Evidencia: ' + $evidence) }

    & php vendor/bin/pint --test app/Http/Controllers/LocalIntegratedCheckoutController.php app/Payments/LocalCheckoutAttemptStore.php tools/tekora-b32-fixture.php tests/Feature/LocalCheckoutAttemptStoreTest.php tests/Feature/LocalIntegratedCheckoutHttpTest.php
    if ($LASTEXITCODE -ne 0) { throw ('Pint FAILED. Evidencia: ' + $evidence) }
    & npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Build FAILED.' }

    Write-Host ('B3.2 PASS. Evidencias: ' + $evidence) -ForegroundColor Green
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Pop-Location
}
