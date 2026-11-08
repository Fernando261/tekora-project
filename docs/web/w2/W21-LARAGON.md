# W2.1 · Clon nuevo y validación Windows/Laragon

Usar una carpeta completamente nueva, por ejemplo `C:\laragon\www\tekora-web-w21-20261108`. No reutilizar W1, W2, bases o instalaciones anteriores. Copiar el **SHA final W2.1 de la entrega del PR #19** en `$w21Sha`; fijar ese commit, no solo el nombre de rama.

```powershell
$cloneW21 = 'C:\laragon\www\tekora-web-w21-20261108'
$w21Sha = 'PEGAR_SHA_FINAL_W21_DEL_PR_19'
if (Test-Path $cloneW21) { throw 'Elegir una carpeta NUEVA.' }
if ($w21Sha -notmatch '^[0-9a-f]{40}$') { throw 'Pegar el SHA final de 40 caracteres.' }
git clone --no-checkout --single-branch --branch codex/web-w2-dashboards-blue-obsidian https://github.com/Fernando261/tekora-project.git $cloneW21
Set-Location $cloneW21
git switch --detach $w21Sha
if ((git rev-parse HEAD) -ne $w21Sha) { throw 'No coincide el commit revisado.' }
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
php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
php -d memory_limit=256M vendor/bin/phpunit --exclude-group web-w21 --do-not-cache-result
php artisan route:list --path=api --json
(Get-ChildItem database/migrations -Filter '*.php').Count
git diff --check
git status --short
```

Usar PHP 8.2.28 y Composer 2.8.11 cuando estén disponibles; registrar versiones diferentes y asegurar SQLite/PDO SQLite. Esperado: **463/6350**, baseline **456/6081**, **37 migraciones**, **21 operaciones API** y auditorías en cero. Se usan 256 MB solo en los procesos PHPUnit: el límite cloud de 128 MB se agotó en la ejecución larga. No modificar php.ini para reproducirlo.

No crear/copiar `.env`, APP_KEY persistente, bases, Google o SMTP. No ejecutar `key:generate`, migraciones manuales, seeds o rollbacks. El bootstrap de pruebas fuerza `testing`, SQLite `:memory:`, DB_URL vacío y clave efímera; la clase de pruebas comprueba el aislamiento antes de migrar en memoria. Detener la revisión si esas condiciones no se cumplen.

## Preview estático seguro

El exportador W2 reforzado para W2.1 establece un archivo de entorno **inexistente fuera del repositorio antes de bootstrapping**. Dotenv no lee el `.env` persistente. Usa fixtures y workflows reales solo dentro del proceso de pruebas y exporta las respuestas HTML a una carpeta temporal nueva. `safety.json` debe confirmar testing/sqlite/:memory:, archivo de entorno inexistente, directorio fuera del repositorio y ausencia de backend POST.

```powershell
$previewW21 = Join-Path $env:TEMP ('tekora-w21-preview-' + [guid]::NewGuid().ToString('N'))
php tools/tekora-w2-preview.php $previewW21 http://127.0.0.1:8017
if ($LASTEXITCODE -ne 0) { throw 'La exportación no terminó.' }
Get-Content (Join-Path $previewW21 'safety.json')
$env:TEKORA_W1_PREVIEW_DIR = $previewW21
php -S 127.0.0.1:8017 -t public tools/tekora-w1-preview-router.php
```

La variable conserva su nombre histórico por compatibilidad. El router solo sirve HTML/assets de ese export por GET/HEAD, sin iniciar Laravel: POST devuelve 405, rutas no exportadas 404. No introducir credenciales reales ni exponerlo fuera de `127.0.0.1`. El origen al exportar y servir debe coincidir.

Visitar en `http://127.0.0.1:8017`:

- `/cuenta/cliente`: Resumen / Reservas / Solicitudes y contadores 3/1/1/2. Notificaciones globales rotuladas.
- `/cuenta/prestador/dashboard`: Resumen / Actividad / Agenda / Solicitudes; cuatro KPI principales y seis de detalle. Aplicar GET 7/30/90 y abrir la tabla.
- `/preview/client-empty`, `/preview/provider-empty`, `/preview/provider-setup`.
- `/cuenta`, `/cuenta/prestador`, `/servicios`, `/login`, `/registro` para continuidad con formularios y W1.1.

Probar 320/375/768/1024/1440/1920 px. La cápsula debe estirarse a derecha e izquierda y asentarse en el ancho de su opción. Alternar rápido, cambiar tamaño/orientación y usar etiquetas con distinto ancho. No debe bloquear clics ni producir scroll horizontal de la página.

Con teclado: Tab/Shift+Tab, flechas, Home/End, Enter/Space y Escape. El foco sigue la pestaña; Tab pasa por acciones y filtros antes del contenido. Cambiar el **zoom nativo del navegador al 200%**, comprobar controles sin superposición y cápsula alineada, y volver al 100%. Esta revisión de Windows es independiente del zoom nativo al 200% ya probado en Chromium Linux headless, con perfil temporal y API de ajustes del navegador. También se comprobó reflow a 720 CSS px.

Desactivar JS: todos los módulos deben quedar disponibles, con enlaces ancla, menú y tabla nativos. Activar movimiento reducido: no debe moverse la cápsula ni el fondo, y no debe haber desplazamiento en hover. Activar transparencia reducida: paneles opacos. En DevTools, bloquear el chunk de Anime y recargar: las secciones deben seguir funcionando instantáneamente.

La previsualización no permite escribir reservas, enviar mensajes o autenticar usuarios. Los enlaces no exportados señalan rutas reales y pueden dar 404 aquí; esas operaciones y sus permisos se prueban con PHPUnit en memoria. Las cifras de enero de 2030 son fixtures, nunca actividad de producción.

Si Python Playwright está disponible como herramienta externa:

```powershell
python tools/tekora-w21-browser-checks.py --base-url http://127.0.0.1:8017 --output (Join-Path $env:TEMP ('tekora-w21-checks-' + [guid]::NewGuid().ToString('N'))) --record
```

Acepta `--executable <chrome.exe>` y `--axe <axe.min.js>` opcional; la grabación necesita el FFmpeg de Playwright. No añadir paquetes al proyecto para ejecutar estas herramientas. El script exige la cabecera del router estático. Para consultas: `php tools/tekora-w2-benchmark.php`, exclusivamente en memoria. No interpretar tres tiempos locales o throttling de CPU como rendimiento de producción.

Comparar con [W21.md](W21.md) y el [análisis original](W21-REFERENCIA.md). Completar contraste, lector de pantalla, navegadores y aceleración gráfica real. Conservar el PR #19 en borrador y esperar aprobación visual independiente.

Cerrar con Ctrl+C y eliminar solo el export creado por estos pasos:

```powershell
Remove-Item Env:TEKORA_W1_PREVIEW_DIR
Remove-Item -Recurse -LiteralPath $previewW21
git status --short
```

No fusionar ni desplegar.
