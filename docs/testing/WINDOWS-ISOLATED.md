# Pruebas Windows aisladas de B3.0

Usar una terminal PowerShell con Git, PHP CLI (pdo_sqlite), Composer y npm. Seleccionar el PHP deseado en PATH antes de empezar; repetir con PHP 8.2 y 8.4 en copias nuevas. El commit correctivo debe estar disponible localmente mediante el parche/bundle revisado; este procedimiento no publica nada.

Desde un repositorio que contenga ese commit, crear un worktree nuevo. No usar el directorio de la instalación Laragon, copiar su .env, iniciar MySQL/Docker ni ejecutar artisan migrate manualmente.

```powershell
$ErrorActionPreference = 'Stop'
$revision = 'SHA_DEL_COMMIT_CORRECTIVO' # sustituir por el SHA entregado
$id = [Guid]::NewGuid().ToString('N')
$testRoot = Join-Path ([IO.Path]::GetTempPath()) "tekora-windows-test-$id"
$evidence = Join-Path ([IO.Path]::GetTempPath()) "tekora-windows-evidence-$id"
git worktree add --detach $testRoot $revision
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la copia nueva' }
New-Item -ItemType Directory -Path $evidence | Out-Null
Push-Location $testRoot
$ownedTestingFile = $false
try {
    foreach ($path in @('.env', '.env.testing', 'bootstrap/cache/config.php', 'database/database.sqlite')) {
        if (Test-Path -LiteralPath $path) { throw "Copia no aislada: $path" }
    }
    $testingFile = Join-Path $testRoot '.env.testing'
    New-Item -ItemType File -Path $testingFile -ErrorAction Stop | Out-Null
    $ownedTestingFile = $true # archivo vacio creado exclusivamente aqui
    $php = (Get-Command php -ErrorAction Stop).Source
    & $php --version | Tee-Object -FilePath (Join-Path $evidence 'php-version.log')
    if ($LASTEXITCODE -ne 0) { throw 'Comprobar PHP CLI' }
    & $php --ri pdo_sqlite | Tee-Object -FilePath (Join-Path $evidence 'pdo-sqlite.log')
    if ($LASTEXITCODE -ne 0) { throw 'Comprobar pdo_sqlite' }
    composer install --no-scripts --prefer-dist --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Composer fallo' }
    npm ci
    if ($LASTEXITCODE -ne 0) { throw 'npm ci fallo' }
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'Build fallo' }
    foreach ($suite in @('WebPricingStorageTest', 'WebPricingTest', 'full')) {
        $arguments = @('vendor/bin/phpunit', '--log-junit', (Join-Path $evidence "$suite.xml"))
        if ($suite -ne 'full') { $arguments += "tests/Feature/$suite.php" }
        & $php @arguments 2>&1 | Tee-Object -FilePath (Join-Path $evidence "$suite.log")
        $code = $LASTEXITCODE
        Set-Content -LiteralPath (Join-Path $evidence "$suite-exit.txt") -Value $code
        if ($code -ne 0) { throw "Fallo $suite; conservar evidencia, no afirmar PASS" }
    }
    git status --porcelain
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo comprobar el arbol' }
} finally {
    try {
        if ($ownedTestingFile) {
            if ((Get-Item -LiteralPath $testingFile).Length -ne 0) {
                throw 'El archivo temporal cambio: conservarlo para inspeccion'
            }
            Remove-Item -LiteralPath $testingFile -ErrorAction Stop
        }
    } finally { Pop-Location }
}
```

No crear .env ni escribir credenciales. El .env.testing vacio proporciona a phpdotenv un archivo existente para testing, sin configuracion ni secretos; el propietario confirmo que evita sus advertencias en Windows. tests/bootstrap.php fuerza testing, SQLite :memory:, DB_URL vacia y una ruta de cache de configuracion unica. TestCase rechaza otra conexion antes de migrar. Las pruebas de archivos crean SQLite sinteticas temporales propias. No se altera el entorno global de Windows ni la configuracion de Laragon.

Conservar logs, exit codes y JUnit completos fuera del checkout, incluso ante fallos. No borrar bases existentes ni limpiar carpetas compartidas; el script elimina solamente el .env.testing vacio que acaba de crear. La suite T1 separada no forma parte de phpunit.xml y requiere su propio entorno fijado. Este procedimiento queda pendiente de ejecucion nativa en Windows; un PASS en Linux no lo certifica.
