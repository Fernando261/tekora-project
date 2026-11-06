# W2 en un clon nuevo de Windows/Laragon

Usar una carpeta **nueva**, por ejemplo `C:\laragon\www\tekora-web-w2-20261106`, sin reutilizar instalaciones anteriores. Seleccionar PHP 8.2.28 y Composer 2.8.11 cuando estén disponibles, con SQLite/PDO SQLite y las extensiones indicadas por Composer. Registrar las versiones si difieren.

En PowerShell:

```powershell
$cloneW2 = 'C:\laragon\www\tekora-web-w2-20261106'
if (Test-Path $cloneW2) { throw 'Elegir una carpeta NUEVA.' }
git clone --branch codex/web-w2-dashboards-blue-obsidian --single-branch https://github.com/Fernando261/tekora-project.git $cloneW2
Set-Location $cloneW2
git rev-parse HEAD
git merge-base HEAD origin/codex/web-w2-dashboards-blue-obsidian
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
php vendor/bin/phpunit --exclude-group web-w2 --do-not-cache-result
php artisan route:list --path=api -v
(Get-ChildItem database/migrations -Filter '*.php').Count
git diff --check
git status --short
```

Comparar HEAD con el SHA de entrega del PR. Esperado: **456/6081**, baseline **447/5925**, **37 migraciones**, **21 operaciones API** y auditorías en cero. Los nueve tests nuevos agregan 156 aserciones. No copiar `.env`, bases o credenciales de otros clones; no ejecutar `key:generate`, `artisan serve` con configuración persistente, ni comandos manuales de migración/seed/rollback. No se necesita crear `.env` para las pruebas o el exportador: el bootstrap establece `testing`, SQLite `:memory:`, DB_URL vacío, sesión/cache en memoria y una clave efímera. Detener la revisión si esas condiciones no se cumplen.

## Previsualización sin credenciales reales

El exportador usa controladores y workflows reales con fixtures sintéticos exclusivamente en memoria. Guarda HTML estático en una carpeta temporal nueva. El router de W1 se reutiliza sin cambios y solo sirve HTML/assets por GET/HEAD; POST devuelve 405. **No introducir credenciales reales.**

```powershell
$previewW2 = Join-Path $env:TEMP ('tekora-w2-preview-' + [guid]::NewGuid().ToString('N'))
php tools/tekora-w2-preview.php $previewW2 http://127.0.0.1:8016
if ($LASTEXITCODE -ne 0 -or !(Test-Path (Join-Path $previewW2 'client.html'))) { throw 'La exportación no terminó.' }
$env:TEKORA_W1_PREVIEW_DIR = $previewW2
php -S 127.0.0.1:8016 -t public tools/tekora-w1-preview-router.php
```

Visitar en `http://127.0.0.1:8016`:

- `/cuenta/cliente`: Alex como cliente, 3/1/1/2 y aviso global de 29 notificaciones.
- `/cuenta/prestador/dashboard`: Estudio Nodo; probar GET de 7, 30 y 90 días y desplegar la tabla diaria.
- `/preview/client-empty`, `/preview/provider-empty`, `/preview/provider-setup`: vacíos y perfil pendiente.
- `/cuenta` y `/cuenta/prestador`: shell compartido y formulario conservado.
- `/servicios`, `/`, `/login`, `/registro`: continuidad con W1/W1.1.

El reloj es fijo en enero de 2030 y todos los usuarios son sintéticos. El origen usado para exportar y servir debe coincidir. Los destinos no exportados devuelven 404; el envío de formularios devuelve 405. Esta previsualización no ejecuta un backend Laravel, no autentica personas y no demuestra escrituras reales: estas se validan en PHPUnit. No exponer el servidor fuera de loopback.

Revisar 320/375/768/1024/1440 px y zoom nativo al 200%; Tab/Enter/Escape, foco, menú, estados con texto, tabla diaria y tamaños de botones. Desactivar JavaScript: menú, cifras, tabla y filtros deben seguir disponibles. Activar movimiento reducido: fondo y entradas quedan estáticos. Con transparencia reducida los paneles son opacos. Verificar por separado una cuenta dual: los mensajes y reservas del cliente no deben sumar su actividad como prestador; los contadores del menú y notificaciones sí son globales.

Comparar las imágenes y videos de `media/`. Completar la revisión con lector de pantalla, Chrome/Edge en Windows, Safari/Firefox donde estén disponibles y GPU real. No se acreditaron esas comprobaciones en cloud. Las pantallas clínicas históricas no forman parte del rediseño.

Si Python Playwright ya está instalado como herramienta externa, la batería acepta `--executable <chrome.exe>`, `--record` y `--axe <axe.min.js>` opcional. No instalar dependencias en los manifiestos del proyecto para usarla:

```powershell
python tools/tekora-w2-browser-checks.py --base-url http://127.0.0.1:8016 --output (Join-Path $env:TEMP ('tekora-w2-checks-' + [guid]::NewGuid().ToString('N'))) --record
```

La grabación requiere el FFmpeg de Playwright. Para comparar consultas con los mismos fixtures: `php tools/tekora-w2-benchmark.php`; es CLI y solo usa SQLite en memoria. No interpretar tres mediciones locales como un benchmark de producción.

Cerrar el servidor con Ctrl+C y limpiar solo la exportación creada por estos pasos:

```powershell
Remove-Item Env:TEKORA_W1_PREVIEW_DIR
Remove-Item -Recurse -LiteralPath $previewW2
git status --short
```

Mantener el PR en borrador. No fusionar ni desplegar: esperar la validación independiente del usuario.
