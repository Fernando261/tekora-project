# W6.2c-d — Validación Windows/Laragon en fuentes aisladas

**Preparada, NOT RUN en Windows.** No usar una instalación Laragon existente, `.env`, SQLite, medios o respaldos de otra instalación. El único motor del ensayo es SQLite `:memory:`. Laragon no implica que se deba usar MySQL. PHP mínimo **8.2**, extensiones del README (incluidas PDO SQLite, SQLite3, DOM/XML, mbstring y fileinfo), Composer y Node/npm compatibles con el lock.

## Obtener una copia sin medios ni configuración existente

En una carpeta nueva, PowerShell y Git recientes. La selección de fuentes se hace antes del checkout: no descargar blobs de uploads/imágenes ni crear una DB de disco.

```powershell
git clone --filter=blob:none --no-checkout https://github.com/Fernando261/tekora-project.git tekora-w62cd-review
Set-Location tekora-w62cd-review
$requiredBase = '7c6b99823630fb457d7f53b7e73732da454e7b81'
if ((git rev-parse origin/main).Trim() -ne $requiredBase) { throw 'main difiere: detener la validacion y revisar la base.' }
@'
/app/
/bootstrap/app.php
/bootstrap/providers.php
/config/
/database/migrations/
/database/factories/
/database/seeders/
/resources/css/
/resources/js/
/resources/views/
/routes/
/tests/
/tools/
/docs/
/artisan
/phpunit.xml
/composer.json
/composer.lock
/package.json
/package-lock.json
/vite.config.js
/public/index.php
/public/robots.txt
'@ | git sparse-checkout set --no-cone --stdin
git checkout --track origin/codex/w62c-d-sqlite-retirement-rehearsal
git rev-parse HEAD
git merge-base HEAD origin/main
git status --short
```

Comparar HEAD con el SHA exacto publicado en el PR y la entrega; merge-base debe ser la base obligatoria. El checkout debe estar limpio. Si la carpeta ya existía, no reutilizarla ni sobrescribir archivos. El sparse checkout conserva las migraciones de fuentes, no una base. Las referencias CSS a recursos públicos excluidos pueden generar advertencias de resolución en build; no copiar imágenes/fuentes privadas para resolverlas.

```powershell
if (Test-Path .env) { throw 'Existe .env: detenerse, no leer ni copiar.' }
if (Test-Path .env.testing) { throw 'No reutilizar una configuracion de pruebas existente.' }
New-Item -ItemType File .env.testing | Out-Null
@('bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs') | ForEach-Object {
    New-Item -ItemType Directory -Force $_ | Out-Null
}
$env:APP_ENV = 'testing'
php --version
php -m
composer --version
node --version
npm --version
composer install --prefer-dist --no-interaction --no-plugins --no-scripts
npm ci --ignore-scripts
npm run build
```

No ejecutar scripts Composer de instalación, `php artisan key:generate`, servidores, workers ni comandos de reset de bases. El bootstrap de PHPUnit crea la clave efímera y fuerza SQLite en memoria; `.env.testing` es el archivo vacío recién creado. No importar credenciales desde variables o gestores de otra instalación. Si una herramienta necesita regenerar archivos, resolverlo solo en este clon aislado, sin copiar caches de otra aplicación.

## Ejecutar y registrar resultados reales

```powershell
$env:TEKORA_W62CD_EVIDENCE = '1'
php -d memory_limit=512M .\vendor\bin\phpunit tests/Feature/RetirementPreflightTest.php --log-junit rehearsal.xml
php -d memory_limit=512M .\vendor\bin\phpunit --group photo-integrity-w51
php -d memory_limit=512M .\vendor\bin\phpunit --group legacy-decoupling-w62a
php -d memory_limit=512M .\vendor\bin\phpunit tests/Feature/RetiredProductTest.php
php -d memory_limit=512M .\vendor\bin\phpunit --log-junit full.xml
Remove-Item Env:TEKORA_W62CD_EVIDENCE
php .\vendor\bin\pint --test tests/Feature/RetirementPreflightTest.php tests/Support/RetirementMemoryIntegrity.php
composer --no-plugins --no-scripts validate --strict
composer --no-plugins --no-scripts check-platform-reqs
composer --no-plugins --no-scripts audit --locked --format=json
composer --no-plugins --no-scripts audit --locked --no-dev --format=json
npm audit --package-lock-only --json
git diff --check
```

Registrar el exit code de cada comando y versiones efectivas. Referencias cloud tras P1/P2: **105/996** para el ensayo, **774/10.022** en total, W5.1 **49/459**, W6.2a **25/406**, W6.2b **34/586**. No atribuir estos resultados a Windows ni a PHP 8.2: cloud ejecutó PHP 8.4.27. El límite CLI de 512 MB solo afecta al proceso de prueba; no modifica configuración del producto.

El ensayo normal incluye las 35 regresiones nuevas. Las cinco reproducciones originales se conservan como texto en `docs/web/w6/evidence/W62C-D-original-review-probes.php.txt`; en cloud pasan **5/10** sin cambiar una línea. Son una ejecución opt-in adicional: copiar ese texto como `ReviewAdversarialProbeTest.php` únicamente dentro del runner aislado, con un directorio temporal nuevo montado como `/review`, y ejecutar `php -d memory_limit=512M run-phpunit.php ReviewAdversarialProbeTest.php --filter test_review_`. Esa ruta de salida fija pertenece al harness Linux original; no ejecutarlo directamente sobre una raíz Windows existente. El resultado Windows de ese harness también es **NOT RUN**. Ver [procedencia y casos del fix](evidence/W62C-D-fix-review.json).

Los JSON opcionales del test aparecen en `storage/framework/testing/w62cd-evidence/`: esquema y resúmenes de datos sintéticos, sin valores de usuarios/tokens/passwords ni hashes de medios. No publicar logs de errores sin sanitizar. Los archivos en Storage falso son creados por los tests; no sustituirlos por medios versionados ni reales. Un fallo de guard debe bloquear la prueba, nunca resolverse desactivando FK/triggers o admitiendo rutas de SQLite.

Un proxy que impida auditorías se registra **BLOCKED**, nunca PASS. En cloud Composer 2.11.2 recibió CONNECT 403 de Packagist; npm audit terminó sin vulnerabilidades. Ver [evidencias](evidence/W62C-D-validation.json) y [alcance del ensayo](W62C-D-SQLITE-REHEARSAL.md).

## Límites que siguen cerrados

Locks sobre archivos, WAL, varios writers de disco, fsync y recuperación física: **NOT RUN — requiere laboratorio SQLite persistente sintético separado**. Este simulador no admite archivos ni bases adjuntas distintas de main/temp en memoria. Un futuro laboratorio opt-in debe crear sus propios archivos en una raíz temporal con marcador, sin aceptar rutas de bases existentes y sin relajar este guard.

Sin acceso a instalaciones reales, purgas, migración de motor, modificación de migraciones, fusión, despliegue ni auto-merge. No tocar PR #28. El rollback Git no restaura datos ni fotografías; un corte real requiere otra entrega y autorización específicas.
