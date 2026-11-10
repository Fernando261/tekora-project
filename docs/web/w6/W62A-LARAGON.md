# W6.2a · Validación Windows/Laragon aislada

Objetivo: verificar desacoplamiento técnico con PHP8.2.28/Composer2.8.11 cuando estén disponibles. No usar ni modificar instalaciones Laragon existentes, sus `.env`, APP_KEY, storage, cuentas, bases o medios. No crear historial, ejecutar limpieza ni empezar W6.2b/c.

## Clon nuevo sin datos previos

PowerShell, Git y Node/npm disponibles. Trabajar fuera del document root y no arrancar Apache/MySQL. Elegir el SHA final publicado en el PR, no el de implementación intermedio. No copiar `.env`; no ejecutar `key:generate`, `migrate`, `seed`, `rollback`, previews ni comandos de reparación manuales.

```powershell
$Base = '2d7e3c05fb3c38857dfe8102e9c5a1c6c70dfe43'
$Branch = 'codex/w62a-tekora-legacy-decoupling'
$Final = Read-Host 'SHA final de 40 caracteres de la entrega'
if ($Final -notmatch '^[0-9a-f]{40}$') { throw 'SHA inválido' }
git clone --filter=blob:none --no-checkout https://github.com/Fernando261/tekora-project.git tekora-w62a-validation
if ($LASTEXITCODE -ne 0) { throw 'Falló clone' }
Set-Location tekora-w62a-validation
git sparse-checkout init --no-cone
@('/*', '!/public/uploads/', '!/storage/', '!/public/img/', '!/.env') | git sparse-checkout set --no-cone --stdin
git fetch origin main $Branch
if ($LASTEXITCODE -ne 0) { throw 'Falló fetch' }
if ((git rev-parse origin/main).Trim() -ne $Base) { throw 'main cambió; revisar la nueva base antes de continuar' }
if ((git rev-parse "origin/$Branch").Trim() -ne $Final) { throw 'La rama cambió; comprobar SHA de entrega' }
git checkout --detach $Final
if ($LASTEXITCODE -ne 0) { throw 'Falló checkout' }
if ((git rev-parse HEAD).Trim() -ne $Final) { throw 'HEAD incorrecto' }
if ((git merge-base $Base HEAD).Trim() -ne $Base) { throw 'Base incorrecta' }
if (Test-Path .env) { throw 'No debe existir .env' }
if (Test-Path public/hot) { throw 'Clon inesperado con hot file; usar un clon nuevo' }
@('storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs') | ForEach-Object {
    New-Item -ItemType Directory -Force $_ | Out-Null
}
git diff --check $Base HEAD
if ($LASTEXITCODE -ne 0) { throw 'diff --check falló' }
git diff --exit-code $Base HEAD -- routes database/migrations config bootstrap app/Policies app/Models composer.json composer.lock package.json package-lock.json tests/bootstrap.php tests/TestCase.php
if ($LASTEXITCODE -ne 0) { throw 'Cambió un contrato protegido' }
git status --short
```

Sparse checkout evita materializar medios versionados/almacenamiento; el filtro puede descargar más objetos si el servidor no lo soporta. No abrir los objetos de medios. La API y el esquema se comparan como fuentes completas: las 37 migraciones no se borran ni reescriben.

## Preparar dependencias sin scripts de aplicación

```powershell
php --version
composer --version
node --version
npm --version
composer validate --strict
if ($LASTEXITCODE -ne 0) { throw 'Composer validate falló' }
composer install --no-scripts --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Composer install falló' }
composer check-platform-reqs
if ($LASTEXITCODE -ne 0) { throw 'Requisitos de plataforma fallidos' }
composer audit --locked
if ($LASTEXITCODE -ne 0) { throw 'Composer audit requiere revisión' }
composer audit --locked --no-dev
if ($LASTEXITCODE -ne 0) { throw 'Composer audit sin dev requiere revisión' }
```

El bootstrap de PHPUnit fuerza SQLite `:memory:`, DB_URL vacío, sesión/cache en memoria y clave efímera, antes de migrar dentro de las pruebas. `Tests\TestCase` verifica la configuración antes de cada migración. Se requiere pdo_sqlite, extensiones de plataforma y base IANA completa, incluidos aliases `Etc/UTC` y `US/Central`. No relajar las reglas de disponibilidad si el PHP local carece de tzdata/extensiones.

## Regresión específica sin build y suite completa con build

Los previews CLI existentes de W2/W3 requieren bundles reales; ignoran el hot file de desarrollo deliberadamente. La suite completa necesita `npm run build`. El escenario sin build se verifica con la prueba aislada de tags de desarrollo, que crea su propia raíz pública y hot file temporales sin borrar el manifiesto real.

```powershell
php -d memory_limit=256M vendor/bin/phpunit --filter test_dashboard_assets_without_a_build_use_real_development_tags --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Tags de desarrollo sin build fallaron' }
npm ci
if ($LASTEXITCODE -ne 0) { throw 'npm ci falló' }
npm audit
if ($LASTEXITCODE -ne 0) { throw 'npm audit requiere revisión' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Build falló' }
if (-not (Test-Path public/build/manifest.json)) { throw 'Falta manifiesto real' }
if (Test-Path public/hot) { throw 'Un hot file ocultaría el manifiesto; revisar clon aislado' }
php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'PHPUnit completo con build falló' }
php -d memory_limit=256M vendor/bin/phpunit --group photo-integrity-w51 --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Regresión W5.1 falló' }
php -d memory_limit=256M vendor/bin/phpunit --group legacy-decoupling-w62a --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Desacoplamiento falló' }
php vendor/bin/pint --test app/Http/Controllers/ProfileController.php app/Services/ProfilePhotoReferences.php app/Services/LegacyPhotoReferences.php tests/Feature/LegacyPhotoDecouplingTest.php tests/Feature/NativeLegacyIndependenceTest.php tests/Support/WithoutLegacySchema.php
if ($LASTEXITCODE -ne 0) { throw 'Pint falló' }
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Diff local inválido' }
git status --short
```

Para ejecución habitual posterior, conservar el build y repetir el mismo comando PHPUnit. No borrar ni renombrar el manifiesto en una instalación para conseguir pruebas verdes. No ejecutar Composer scripts que creen `.env`, generen clave o migren una base fuera de PHPUnit. No cambiar permisos, roles o guards de la aplicación para resolver errores: la ausencia del esquema/guard profesional se simula exclusivamente en los fixtures nuevos.

## Resultados que contrastar

La ejecución con manifiesto completo debe dar **651 pruebas / 8.659 aserciones**; W5.1 **49 / 459**; W6.2a **25 / 401**; el caso aislado sin build **1 / 6**. La entrega también registró un ensayo extra de la suite completa sin bundles: 651 pruebas / 8.562 aserciones, dos errores de previews CLI y dos casos de manifiesto omitidos. Los dos previews fallan igualmente con las fuentes originales de la base sin build. Es una precondición existente, no una regresión corregida mediante cambios de tests; por eso esta guía prepara bundles antes de ejecutar toda la suite.

Las 25 nuevas pruebas / 401 aserciones explican el aumento respecto a 626 / 8.258 en el escenario con build. Si cambia otro conteo, conservar salida completa y determinar la diferencia de assets/configuración antes de modificar tests. Registrar versiones PHP/Composer/Node/npm y estado del manifiesto/hot en cada ejecución. Las auditorías reflejan la fecha de ejecución; avisos nuevos requieren revisión, no modificación silenciosa de locks.

Las **21 operaciones API** siguen en `routes/api.php`: ocho declaraciones explícitas y trece entradas del bucle. HEAD implícito no es otra operación. Las **37 migraciones** permanecen idénticas a la base. Se puede comprobar sin arrancar Artisan:

```powershell
$Migrations = @(git ls-tree -r --name-only HEAD -- database/migrations/ | Where-Object { $_ -match '\.php$' })
if ($Migrations.Count -ne 37) { throw 'Conteo de migraciones distinto' }
$Api = Get-Content routes/api.php
$Direct = @($Api | Select-String "Route::(get|post|patch|put|delete)\('")
$Loop = @($Api | Select-String "^\s*\['(get|post|patch)', '[^']+', '[^']+'")
if ($Direct.Count -ne 8 -or $Loop.Count -ne 13) { throw 'Estructura API distinta' }
git diff --exit-code $Base HEAD -- routes database/migrations
```

Revisar [W62A.md](W62A.md), mapas y listas de retirada. Confirmar PR en borrador, sin merge/deploy/auto-merge. No validar purgas con usuarios/medios reales ni ejecutar el fixture de drop como un comando manual; vive solo dentro de PHPUnit y SQLite `:memory:`. El siguiente paso es revisión independiente de W6.2a, no W6.2b/c.
