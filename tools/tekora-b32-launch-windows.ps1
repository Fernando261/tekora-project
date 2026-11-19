# B3.2 — Inicio seguro de Tekora completo en Windows 11
# SOLO ejecutar desde una copia NUEVA extraída fuera de Laragon.
# Este script NO clona, instala ni modifica bases existentes.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ($project -match '(?i)[\\/]laragon[\\/]' -or
    (Test-Path (Join-Path $project '.env')) -or
    (Test-Path (Join-Path $project 'bootstrap/cache/config.php'))) {
    throw 'Usa un ZIP/copia nueva FUERA de Laragon, sin .env ni caché previa.'
}
foreach ($required in @('vendor/autoload.php', 'vendor/bin/phpunit', 'public/build/manifest.json', 'tools/tekora-b32-fixture.php')) {
    if (-not (Test-Path (Join-Path $project $required))) {
        throw "Falta $required. En esta copia aislada ejecuta composer install --no-scripts --prefer-dist --no-interaction, npm ci y npm run build."
    }
}
Push-Location $project
$names = @('APP_ENV','APP_DEBUG','APP_KEY','APP_URL','APP_CONFIG_CACHE','DB_CONNECTION','DB_DATABASE','DB_URL','CACHE_STORE','SESSION_DRIVER')
$previous = @{}
foreach ($name in $names) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
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
    $env:SESSION_DRIVER = 'file'
    $env:APP_CONFIG_CACHE = Join-Path $env:TEMP ('tekora-b32-no-config-' + [guid]::NewGuid().ToString('N') + '.php')

    $output = & php (Join-Path $project 'tools/tekora-b32-fixture.php') 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ('Fixture fallida. No iniciaremos el servidor: ' + ($output -join [Environment]::NewLine))
    }
    $fixture = (($output | Out-String).Trim() | ConvertFrom-Json)
    if (-not $fixture.database -or -not (Test-Path $fixture.database)) {
        throw 'No se creó una base SQLite sintética verificable.'
    }
    if (-not (Test-Path (Join-Path (Split-Path $fixture.database) '.tekora-b32-synthetic'))) {
        throw 'Falta el marcador de base sintética: servidor no iniciado.'
    }

    $env:APP_ENV = 'local'
    $env:DB_DATABASE = [string] $fixture.database
    Write-Host ''
    Write-Host 'TEKORA B3.2 - RECORRIDO INTEGRADO LOCAL' -ForegroundColor Cyan
    Write-Host ('SQLite descartable: ' + $env:DB_DATABASE)
    Write-Host ('Cliente: ' + $fixture.client_email + ' / ' + $fixture.client_password)
    Write-Host ('Prestador: ' + $fixture.provider_email + ' / ' + $fixture.provider_password)
    Write-Host ('Solicitud inicial: ' + $fixture.pending_inquiry_id)
    Write-Host ('Reserva lista para checkout: ' + $fixture.held_reservation_id)
    Write-Host 'Abrir http://127.0.0.1:8097/login en dos perfiles del navegador.'
    Write-Host 'NO compartas estas contraseñas: son temporales solo para esta copia.'
    Write-Host 'Importante: el hold es de 15 min desde la generación; al vencer, crea otra fixture NUEVA.'
    Write-Host ''
    & php artisan serve --host=127.0.0.1 --port=8097
    if ($LASTEXITCODE -ne 0) { throw 'El servidor Laravel no inició correctamente.' }
} finally {
    foreach ($name in $names) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
    Pop-Location
}
