# Revisión W1 en Windows/Laragon con un clon nuevo

Usar **`C:\laragon\www\tekora-web-w1-20261105`**, sin reutilizar instalaciones anteriores. Verificar que la carpeta no exista. PHP 8.2.28 y Composer 2.8.11 cuando estén disponibles; extensiones SQLite/PDO SQLite y los requisitos indicados por Composer. Registrar versiones distintas si las herramientas locales no coinciden.

En PowerShell:

```powershell
$cloneW1 = 'C:\laragon\www\tekora-web-w1-20261105'
if (Test-Path $cloneW1) { throw 'Elegir otra carpeta NUEVA; no sobrescribir un clon.' }
git clone --branch codex/web-w1-identidad-cinematica-tekora --single-branch https://github.com/Fernando261/tekora-project.git $cloneW1
Set-Location $cloneW1
git rev-parse HEAD
git merge-base HEAD origin/codex/web-w1-identidad-cinematica-tekora
php -v
composer --version
composer validate --strict
composer install --no-scripts --no-interaction
composer check-platform-reqs
composer audit --locked
composer audit --locked --no-dev
npm ci
npm audit
npm run build
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit --exclude-group web-w1 --do-not-cache-result
php artisan route:list --path=api -v
(Get-ChildItem database/migrations -Filter '*.php').Count
git diff --check
git status --short
```

Comparar HEAD con el SHA de entrega del PR y comprobar **442/5763**, **433/5642**, **37 migraciones**, **21 operaciones API** y auditorías en cero. Detener la revisión si el bootstrap de PHPUnit no establece `testing`, SQLite `:memory:`, DB_URL vacío, sesión/cache en memoria y clave efímera. No cambiar `.env`, no copiar una base ni credenciales, no ejecutar `key:generate` o comandos de migración/seed/rollback manuales. `composer install --no-scripts` evita los scripts habituales del proyecto. No iniciar Laravel con una conexión persistente para esta revisión.

## Revisión visual sin base persistente

El exportador CLI incluido usa el mismo bootstrap de pruebas, comprueba SQLite `:memory:` **antes** de migrar y crea fixtures sintéticos solo en memoria. Genera las vistas reales con los assets compilados en una carpeta temporal nueva; no escribe `.env` ni conserva cuentas o tokens. El router posterior sirve únicamente ese HTML/assets y **rechaza POST**. No introducir credenciales reales.

```powershell
$previewW1 = Join-Path $env:TEMP ('tekora-w1-preview-' + [guid]::NewGuid().ToString('N'))
php tools/tekora-w1-preview.php $previewW1 http://127.0.0.1:8015
if ($LASTEXITCODE -ne 0 -or !(Test-Path (Join-Path $previewW1 'home.html'))) { throw 'La exportación no terminó.' }
$env:TEKORA_W1_PREVIEW_DIR = $previewW1
php -S 127.0.0.1:8015 -t public tools/tekora-w1-preview-router.php
```

Visitar `http://127.0.0.1:8015/`, `/login`, `/registro`, `/login?role=prof`, `/empty` y `/errors`. El origen al exportar y al servir debe coincidir. Los enlaces a catálogo/MindBloom apuntan a los destinos reales, pero el servidor estático responde 404 porque solo exporta W1; su funcionamiento y autenticación se validan con la suite Laravel en memoria. Este servidor no es un modo de producción ni un backend de prueba de contraseñas.

Probar 320/375/768/1440 px, zoom nativo 200%, Tab/Enter/Escape, campos y focus rings, menú, enlaces de acceso, giro entre login/registro, errores, opción de reducir movimiento y JS desactivado. En un dispositivo táctil la escena debe quedar estable. Comparar con las capturas y vídeos de `media/`. Revisar lector de pantalla y autofill del navegador como validación independiente pendiente.

Cerrar con Ctrl+C. En PowerShell, quitar la variable temporal y borrar **solo** la exportación creada por estos comandos cuando termine la revisión:

```powershell
Remove-Item Env:TEKORA_W1_PREVIEW_DIR
Remove-Item -Recurse -LiteralPath $previewW1
git status --short
```

Las comprobaciones funcionales de registro, login, CSRF, errores, guards y rutas históricas se ejecutan en PHPUnit con memoria. No conectarlas a una base anterior para completar esta validación. No fusionar ni desplegar el PR desde estos pasos.
