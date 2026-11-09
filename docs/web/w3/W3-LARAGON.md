# W3 · Validación aislada Windows/Laragon

Fijar el SHA final de la entrega W3. El PR #19 ya está fusionado y no se reutiliza. Base de W3: `00c13ee0f9504125320f0119d29454bd503326cd`.

## Clon completamente nuevo

No reutilizar instalaciones, carpetas, puertos ni bases de revisiones anteriores. No crear/copiar `.env`, APP_KEY, credenciales Google/SMTP o una base persistente.

```powershell
$cloneW3 = Join-Path 'C:\laragon\www' ('tekora-w3-' + [guid]::NewGuid().ToString('N').Substring(0,8))
$w3Sha = 'PEGAR_SHA_FINAL_W3_DE_LA_ENTREGA'
if (Test-Path $cloneW3) { throw 'La carpeta debe ser NUEVA.' }
if ($w3Sha -notmatch '^[0-9a-f]{40}$') { throw 'Pegar el SHA final completo.' }
git clone --no-checkout --single-branch --branch codex/web-w3-cinematic-marketplace https://github.com/Fernando261/tekora-project.git $cloneW3
Set-Location $cloneW3
git switch --detach $w3Sha
if ((git rev-parse HEAD) -ne $w3Sha) { throw 'No coincide el commit.' }
php -v
composer --version
composer validate --strict
composer install --no-scripts --no-interaction
composer check-platform-reqs
composer audit --locked
composer audit --locked --no-dev
# Escenario sin build: las pruebas usan un hotfile temporal propio.
# También prueban un public path temporal sin manifiesto, sin borrar el real.
php -d memory_limit=256M vendor/bin/phpunit --filter test_marketplace_assets_are_referenced --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Falló la portabilidad Vite.' }
```

Preferir PHP **8.2.28** y Composer **2.8.11**, con PDO SQLite disponible. Registrar cualquier versión diferente. No cambiar php.ini global para esta validación: los 256 MB se aplican únicamente a los procesos indicados. Composer install puede informar la ambigüedad preexistente de `AppServiceProvider` con el proveedor interno de Pint; se conserva el del proyecto. No editar dependencias ni autoload para ocultar ese aviso.

## Build real y suites

Detener cualquier `npm run dev` únicamente de este clon. Conservar su hotfile si existe para impedir que oculte el manifiesto durante las pruebas post-build.

```powershell
$hotW3 = Join-Path $cloneW3 'public/hot'
$savedHotW3 = Join-Path $env:TEMP ('tekora-w3-hot-' + [guid]::NewGuid().ToString('N'))
$hadHotW3 = Test-Path $hotW3
if ($hadHotW3) { Move-Item -LiteralPath $hotW3 -Destination $savedHotW3 }
try {
    npm ci
    if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
    npm audit
    if ($LASTEXITCODE -ne 0) { throw 'npm audit falló.' }
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'El build falló.' }
    if (!(Test-Path 'public/build/manifest.json')) { throw 'Falta el manifiesto real.' }
    if (Test-Path $hotW3) { throw 'Vite recreó el hotfile: detener ese servidor.' }
    php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'La suite completa falló.' }
    php -d memory_limit=256M vendor/bin/phpunit --group web-w3 --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'W3 falló.' }
    php -d memory_limit=256M vendor/bin/phpunit --exclude-group web-w3 --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'La baseline aprobada falló.' }
    php -d memory_limit=256M vendor/bin/phpunit --exclude-group web-w21 --exclude-group web-w3 --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'La baseline histórica falló.' }
} finally {
    if ($hadHotW3) {
        if (Test-Path $hotW3) { throw "Conservar el backup $savedHotW3 y resolver el hotfile nuevo antes de restaurarlo." }
        Move-Item -LiteralPath $savedHotW3 -Destination $hotW3
    }
}
# Ejecución habitual, con el entorno local restaurado.
php -d memory_limit=256M vendor/bin/phpunit --do-not-cache-result
php -d auto_prepend_file=tests/bootstrap.php artisan route:list --path=api --json
(Get-ChildItem database/migrations -Filter '*.php').Count
git diff --check
git status --short
```

Esperado con el build: **501 pruebas / 6.896 aserciones**; W3 **25/345**; baseline aprobada **476/6.551**; histórica **456/6.081**. El aumento es exactamente **25 pruebas y 345 aserciones**. Se usan flags `--exclude-group` separados para evitar la sintaxis obsoleta de listas por comas en PHPUnit 11.5.

Controles: **37 migraciones**, **21 operaciones API idénticas**, auditorías Composer con/sin dev y npm en cero. Las pruebas existentes no se cambian. La migración automática del TestCase se ejecuta solamente después de comprobar testing, SQLite `:memory:` y DB_URL vacío. Nunca ejecutar `migrate`, `migrate:fresh`, `migrate:refresh`, seeds o rollbacks manuales contra bases persistentes.

## Preview estático sin backend

Elegir un puerto loopback libre y usarlo tanto en export como en servidor. El ejemplo usa **8057**, independiente de las validaciones anteriores.

```powershell
$portW3 = 8057
$listenW3 = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()
if ($listenW3 | Where-Object { $_.Port -eq $portW3 }) { throw 'Elegir otro puerto libre y ajustar el origen.' }
$originW3 = 'http://127.0.0.1:' + $portW3
$previewW3 = Join-Path $env:TEMP ('tekora-w3-preview-' + [guid]::NewGuid().ToString('N'))
php tools/tekora-w3-preview.php $previewW3 $originW3
if ($LASTEXITCODE -ne 0) { throw 'La exportación no terminó.' }
$safetyW3 = Get-Content (Join-Path $previewW3 'safety.json') -Raw | ConvertFrom-Json
if ($safetyW3.environment -ne 'testing' -or $safetyW3.connection -ne 'sqlite' -or $safetyW3.database -ne ':memory:' -or $safetyW3.environment_file_exists -or $safetyW3.environment_directory_is_repository -or $safetyW3.post_backend) { throw 'No continuar: aislamiento incorrecto.' }
Get-Content (Join-Path $previewW3 'marketplace-fixtures.json')
$env:TEKORA_W3_PREVIEW_DIR = $previewW3
php -S ('127.0.0.1:' + $portW3) -t public tools/tekora-w3-preview-router.php
```

El export usa los controladores y workflows existentes con datos sintéticos reconocibles, fechados en febrero de 2030, exclusivamente en memoria. Se reutiliza además el export seguro de dashboards/acceso para comprobar continuidad. Ambas aplicaciones arrancan con un archivo de entorno inexistente fuera del repositorio: Dotenv no lee el `.env` persistente.

Visitar el origen elegido:

- `/servicios`: 27 servicios de los fixtures, formulario GET real y paginación. Limpiar filtros vuelve al listado.
- `/servicios?q=Dise%C3%B1o&category=diseno&modality=remote`: 14 resultados, 12 en la primera página y 2 en la segunda; los parámetros se conservan.
- `/servicios?q=Dise%C3%B1o`, `/servicios?category=diseno`, `/servicios?modality=remote`: búsquedas/filtros representativos exportados.
- `/servicios?q=sin-resultados`: estado vacío. `/preview/invalid-filters`: error 422 real exportado.
- Usar `service_path` y `profile_path` de `marketplace-fixtures.json` para el detalle y el perfil principal; todos los enlaces de servicios/prestadores de los fixtures tienen páginas exportadas.
- Perfil con `?page=2&reviews_page=2`: 3 servicios de esa página y segunda página de reseñas; ambas paginaciones son independientes.
- `/preview/service-no-reviews`, `/preview/providers-empty`, `/preview/long-text`.
- `/preview/client-service`, `/preview/owner-service`, `/preview/provider-service`: HTML de estados autorizados, no sesiones reales. `/preview/request-form` permite inspeccionar el formulario existente para un cliente nuevo.
- `/`, `/login`, `/registro?intent=provider`, `/cuenta/cliente`, `/cuenta/prestador/dashboard`: continuidad con portada, autenticación y dashboards aprobados.

Este servidor sirve un **conjunto finito de respuestas GET ya generadas**. No filtra en JavaScript ni consulta una base al navegar. Una consulta no exportada devuelve 404; no se sustituye silenciosamente por el catálogo completo. El router normaliza orden de parámetros, `%20`/`+` y filtros vacíos según su semántica existente. Los filtros arbitrarios del backend real están cubiertos por PHPUnit.

El recorrido de visitante «Solicitar servicio» mantiene la redirección GET a login. Los aliases de roles no crean una sesión; permisos, propiedad y POST se prueban con PHPUnit en memoria. POST/PUT/PATCH/DELETE se rechazan con **405**, `.env` y rutas no exportadas con **404**. No introducir credenciales ni enviar datos reales. El servidor se limita a `127.0.0.1`; no usar `0.0.0.0`.

## Revisión visual independiente

Comparar con [W3.md](W3.md) y sus capturas/videos auténticos. Revisar:

1. Anchos **320/375/768/1024/1100/1280/1440/1920**: todo el texto y controles legibles, sin overflow horizontal.
2. Hero del catálogo, escena diferente del directorio, monograma real del perfil y profundidad del detalle. Las placas deben tener perspectiva y alturas Z distintas; no son fotografías ni credenciales.
3. Puntero fino: inclinación muy limitada de la escena y reflejo/plano decorativo de tarjetas. Los botones y enlaces mantienen su posición y responden inmediatamente.
4. Buscar Diseño, elegir categoría y modalidad, paginar y limpiar. Comprobar los valores de los controles y las URLs reales.
5. Disponibilidad orientativa, reseñas presentes/ausentes, aviso de falta de verificación profesional y ausencia de precios/pagos inventados.
6. Tab/Shift+Tab, foco visible y completo, Enter en enlaces/botones, lectura de encabezados y labels. Probar NVDA/Narrador: las escenas son decorativas y no agregan controles al árbol accesible.
7. Desactivar JS: contenido, navegación, formulario y paginación siguen funcionando. Bloquear solo `marketplace-*.js`: permanece la versión estática.
8. Movimiento y transparencia reducidos, touch y orientación horizontal. En táctil la escena queda estática. Las fuentes locales usan `font-display: optional`: en cargas lentas se conserva el fallback de esa visita, evitando un reemplazo tardío que mueva el contenido.
9. **Zoom nativo 200 %**, sin emulación de viewport ni CSS zoom, en las cuatro páginas. Verificar búsqueda y CTA con foco, scroll hasta reseñas y footer, y volver al 100 %.
10. Sin marca MindBloom promocional en marketplace/portada. El acceso privado a historial anterior permanece; no comprobarlo borrando datos o alterando guards.

Si ya se dispone de Python Playwright, Chromium/Chrome, FFmpeg de Playwright y Axe como herramientas externas, sin añadir paquetes al proyecto:

```powershell
python tools/tekora-w3-browser-checks.py --base-url $originW3 --fixture (Join-Path $previewW3 'marketplace-fixtures.json') --output (Join-Path $env:TEMP ('tekora-w3-browser-' + [guid]::NewGuid().ToString('N'))) --executable 'RUTA_A_CHROME_O_CHROMIUM' --axe 'RUTA_A_AXE_MIN_JS' --record
python tools/tekora-w3-zoom-check.py --base-url $originW3 --fixture (Join-Path $previewW3 'marketplace-fixtures.json') --output (Join-Path $env:TEMP ('tekora-w3-zoom-' + [guid]::NewGuid().ToString('N'))) --executable 'RUTA_A_CHROME_O_CHROMIUM'
```

El script de zoom utiliza una API de ajustes de Chromium en un perfil temporal eliminado al terminar. Si no está disponible en la versión de Windows, grabar manualmente la misma secuencia 100→200→100. No se toca el perfil personal del navegador. Los resultados Linux headless/software no certifican GPU, trackpad/touch físico, Windows ni lector de pantalla.

Al terminar, cerrar el servidor con Ctrl+C y eliminar exclusivamente el export temporal creado por estos pasos:

```powershell
Remove-Item Env:TEKORA_W3_PREVIEW_DIR
Remove-Item -Recurse -LiteralPath $previewW3
git status --short
```

Conservar el nuevo PR W3 en borrador. No fusionar ni desplegar. Esperar revisión técnica y aprobación visual del usuario.
