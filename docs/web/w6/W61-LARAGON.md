# W6.1 · Validación independiente en Windows/Laragon

Esta etapa es documental. Validarla no necesita PHP, Composer, npm, MySQL, un servidor web ni `.env`. No usar una instalación Laragon existente, no copiar su `.env`, APP_KEY, storage ni medios. No ejecutar Artisan, migraciones, seeds, rollbacks, PHPUnit, previews ni OAuth para verificar este PR.

## Clon aislado y SHA

Usar PowerShell y Git en una carpeta nueva fuera del document root/instalación anterior. Sustituir el SHA final por el de la entrega y comprobarlo también en GitHub; no usar un SHA intermedio de implementación. El documento no puede contener su propio hash final, porque al escribirlo cambiaría el commit.

El filtro y sparse checkout evitan materializar medios históricos versionados y documentación binaria. Git puede conservar metadata del árbol completo; estos comandos no abren objetos multimedia ni consultan una instalación. Si el servidor no admite el filtro, Git puede descargar más objetos al clonar: no abrirlos ni usarlos para la auditoría.

```powershell
$Base = '576c937fa3cbd31e6af95eedc1651cb59a16d898'
$Branch = 'codex/w61-mindbloom-decommission-audit'
$Final = Read-Host 'SHA final de 40 caracteres publicado en la entrega'
if ($Final -notmatch '^[0-9a-f]{40}$') { throw 'SHA final inválido' }
git clone --filter=blob:none --no-checkout https://github.com/Fernando261/tekora-project.git tekora-w61-audit
if ($LASTEXITCODE -ne 0) { throw 'Falló el clon' }
Set-Location tekora-w61-audit
git sparse-checkout init --no-cone
$Patterns = @('/docs/web/w6/', '/routes/', '/database/migrations/')
$Patterns | git sparse-checkout set --no-cone --stdin
git fetch origin main $Branch
if ($LASTEXITCODE -ne 0) { throw 'Falló fetch' }
$Main = (git rev-parse origin/main).Trim()
if ($Main -ne $Base) { throw "main cambió: $Main; detener validación contra otra base" }
$RemoteHead = (git rev-parse "origin/$Branch").Trim()
if ($RemoteHead -ne $Final) { throw "La rama cambió: $RemoteHead; comprobar entrega actualizada" }
git checkout --detach $Final
if ($LASTEXITCODE -ne 0) { throw 'No se pudo seleccionar el SHA final' }
if ((git rev-parse HEAD).Trim() -ne $Final) { throw 'HEAD incorrecto' }
if ((git merge-base $Base HEAD).Trim() -ne $Base) { throw 'Base no contenida' }
if (Test-Path .env) { throw 'No debe existir .env en este clon' }
git status --short
```

Detenerse si Git informa errores o si el SHA de `main` ya no es el aprobado. No hacer merge/rebase ni actualizar el PR para esta validación independiente.

## Alcance y archivos

```powershell
$Outside = @(git diff --name-only $Base HEAD -- . ':(exclude)docs/web/w6/**')
if ($LASTEXITCODE -ne 0 -or $Outside.Count -ne 0) { throw 'Hay cambios fuera del alcance documental' }
$Changed = @(git diff --name-only $Base HEAD)
$Expected = @((Get-Content docs/web/w6/evidence/delivery-files.json -Raw | ConvertFrom-Json).files)
if (@(Compare-Object ($Changed | Sort-Object) ($Expected | Sort-Object)).Count -ne 0) { throw 'Lista de entrega distinta' }
foreach ($Path in $Changed) {
    if ($Path -notmatch '^docs/web/w6/.+\.(md|json|csv|txt)$') { throw "Extensión/ruta inesperada: $Path" }
    $Entry = (git ls-tree HEAD -- $Path) -join ''
    if ($Entry -notmatch '^100644 blob ') { throw "Archivo ejecutable, symlink o modo inesperado: $Path" }
}
$Changed
git diff --stat $Base HEAD
git diff --check $Base HEAD
if ($LASTEXITCODE -ne 0) { throw 'diff --check falló' }
$Dirty = @(git status --porcelain)
if ($Dirty.Count -ne 0) { throw 'Clon con cambios locales' }
```

Resultado esperado: únicamente adiciones documentales bajo `docs/web/w6/`, modos Git100644 y estado limpio. El permiso de ejecución Git es el criterio portátil: no se infiere desde ACL de Windows. Leer los cinco documentos y `evidence/README.md`; las propuestas futuras no son instrucciones para ejecutar cortes.

## API y migraciones intactas sin arrancar Laravel

```powershell
git diff --exit-code $Base HEAD -- app bootstrap config routes resources public tests database composer.json composer.lock package.json package-lock.json vite.config.js phpunit.xml .github tools
if ($LASTEXITCODE -ne 0) { throw 'Cambió código, configuración, contratos o recursos' }
$Migrations = @(git ls-tree -r --name-only HEAD -- database/migrations/ | Where-Object { $_ -match '\.php$' })
if ($Migrations.Count -ne 37) { throw "Migraciones: $($Migrations.Count)" }
git diff --exit-code $Base HEAD -- database/migrations routes
if ($LASTEXITCODE -ne 0) { throw 'Rutas o migraciones distintas de la base' }
$Api = Get-Content routes/api.php
$Explicit = @($Api | Select-String "Route::(get|post|patch|put|delete)\('")
$LoopRows = @($Api | Select-String "^\s*\['(get|post|patch)', '[^']+', '[^']+'")
if ($Explicit.Count -ne 8 -or $LoopRows.Count -ne 13) { throw 'La estructura estática API cambió' }
"API estática: $($Explicit.Count + $LoopRows.Count) operaciones; migraciones: $($Migrations.Count)"
$Inventory = Get-Content docs/web/w6/evidence/api-static-inventory.json -Raw | ConvertFrom-Json
if ($Inventory.count -ne 21 -or $Inventory.operations.Count -ne 21) { throw 'Inventario API inconsistente' }
$Matrix = Get-Content docs/web/w6/evidence/decision-matrix.json -Raw | ConvertFrom-Json
if ($Matrix.fields.Count -ne 15 -or $Matrix.rows.Count -ne 34) { throw 'Matriz incompleta' }
foreach ($Row in $Matrix.rows) {
    foreach ($Field in $Matrix.fields) {
        if ([string]::IsNullOrWhiteSpace([string]$Row.$Field)) { throw "Campo vacío: $($Row.id) / $Field" }
    }
}
git status --short
```

Se cuentan ocho declaraciones API explícitas y trece entradas de un bucle literal, **21 operaciones**; HEAD implícito de GET no se añade. Esto no es `route:list` ni ejecución de middleware. La comparación íntegra de fuentes contra la base es la garantía principal. Las 37 migraciones incluyen Sanctum; no se ejecuta ninguna.

## Qué informar en la revisión

Registrar SHA final/`main`, archivos exactos, resultado del diff documental, modos, **21 operaciones / 37 migraciones estáticas** y lectura de los riesgos/decisiones. Confirmar borrador en GitHub, sin merge/deploy/auto-merge. Revisar especialmente historial sin rol, separación de identidades, referencias W5.1, public/uploads, Calendar y shared CSS.

La baseline **626 pruebas / 8.258 aserciones** corresponde a la aprobación Windows anterior. W6.1 no aporta una ejecución PHPUnit nueva ni resultados nuevos de auditorías Composer/npm. No presentar esos números como ejecución de este clon; no se necesita preparar una base para aceptar un diff exclusivamente documental.

Los PRs funcionales futuros tendrán instrucciones propias de ejecución aislada, fixtures sintéticos y suites adecuadas. No empezar W6.2 ni realizar operaciones sobre instalaciones existentes durante esta revisión.
