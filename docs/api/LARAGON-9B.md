# Validación independiente — Windows / Laragon, puerto 8013

Este procedimiento se realiza en un clon separado: `C:\laragon\www\tekora-etapa9b`. No usar ni reemplazar la carpeta del proyecto actual. El PR permanece en borrador: validar no autoriza fusionar o desplegar.

## 1. Revisar el código y probar sin base persistente

Requisitos: PHP 8.2+, Composer 2, Node 22/24, extensiones del README, SQLite. Abrir PowerShell en Laragon:

```powershell
Set-Location C:\laragon\www
if (Test-Path .\tekora-etapa9b) { throw 'La carpeta ya existe; elige un clon nuevo.' }
git clone --branch codex/etapa-9b-api-movil-base --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa9b
Set-Location .\tekora-etapa9b
git rev-parse HEAD
git merge-base --is-ancestor f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 HEAD
if ($LASTEXITCODE -ne 0) { throw 'Falta el merge 9A esperado.' }
git diff f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 -- database/migrations
composer install --no-scripts --no-interaction
php artisan package:discover
composer validate --strict
composer check-platform-reqs
php .\vendor\bin\phpunit --do-not-cache-result
npm.cmd ci
npm.cmd audit
npm.cmd run build
php artisan route:list --path=api -v
```

No ejecutar `install:api`, `key:generate`, `migrate:fresh`, `migrate:refresh`, seeders generales ni rollback sobre el proyecto existente. No copiar `.env` ni modificar APP_KEY existente. PHPUnit fuerza SQLite `:memory:` y una clave efímera de pruebas; se niega a migrar si pierde ese aislamiento.

El revisor debe comprobar los Resources, errores aun con depuración, separación de guards, permisos actuales, rotación/límites y migración aditiva. Contrastar los resultados con `VALIDACION-9B.md` y revisar los avisos de Composer antes de autorizar cualquier integración.

## 2. Base TEMP para HTTP manual, solo tras la revisión independiente

Este bloque no se ejecutó durante la entrega. Usar **una copia de validación del esquema 9A**, con 36 migraciones aplicadas y cuentas de prueba conocidas (solicitante, prestador, dual, administrador), más servicios públicos y privados. La contraseña es la existente de esas cuentas. El servidor y la copia pueden cambiar sesiones/cache/token; por eso no deben apuntar a la base original.

Después de revisar independientemente el diff de la migración, copiar la base de validación a una nueva carpeta TEMP. Mantener la aplicación original detenida al generar su copia coherente; no copiar un SQLite activo con WAL pendiente. Nunca abrir la base original con comandos de migración.

```powershell
$source = (Resolve-Path (Read-Host 'Ruta de la copia coherente de validación 9A')).Path
$tempDir = Join-Path $env:TEMP ('tekora-9b-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempDir | Out-Null
$tempDb = Join-Path $tempDir 'validation.sqlite'
Copy-Item -LiteralPath $source -Destination $tempDb
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8013'
# Clave efímera solo para este proceso y este clon sin .env; no cambia la clave original.
@'
<?php echo 'base64:'.base64_encode(random_bytes(32));
'@ | Set-Content -Encoding ASCII (Join-Path $tempDir 'ephemeral-key.php')
$env:APP_KEY = php (Join-Path $tempDir 'ephemeral-key.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $tempDb.Replace('\', '/')
$env:DB_URL = ''
$env:APP_CONFIG_CACHE = Join-Path $tempDir 'unused-config.php'
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
php artisan migrate:status
# Debe haber exactamente 36 aplicadas y solo personal_access_tokens pendiente.
php artisan migrate --pretend
# Leer el SQL: CREATE personal_access_tokens y sus índices, sin modificar tablas de negocio.
# Solo después de esa revisión, comprobar de nuevo el destino TEMP:
if (-not $env:DB_DATABASE.StartsWith($tempDir.Replace('\', '/'))) { throw 'Destino no TEMP.' }
php artisan migrate --force
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8013
```

La clave efímera solo sirve para esta validación con cuentas de prueba y no permite descifrar datos cifrados con otra clave. No validar datos cifrados ni OAuth heredado con ella. Cerrar la terminal al terminar para descartar las variables del proceso. No transferir cambios desde TEMP a ninguna base original.

## 3. Peticiones JSON desde otra terminal PowerShell

No imprimir, registrar ni incluir el Bearer en URLs. Desactivar transcripciones HTTP que registren cabeceras/cuerpos sensibles durante la prueba. El ejemplo guarda el token solo en una variable de la sesión.

```powershell
$base = 'http://127.0.0.1:8013/api/v1'
$email = Read-Host 'Correo de solicitante de prueba'
$secure = Read-Host 'Contraseña existente' -AsSecureString
$credential = [System.Net.NetworkCredential]::new('', $secure)
$body = @{ email=$email; password=$credential.Password; device_name='PowerShell Android' } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri "$base/auth/login" -ContentType 'application/json' -Body $body
$body = $null
$credential = $null
$headers = @{ Authorization=('Bearer ' + $login.data.access_token); Accept='application/json' }
Invoke-RestMethod -Uri "$base/me" -Headers $headers | ConvertTo-Json -Depth 8
$catalog = Invoke-RestMethod -Uri "$base/services?per_page=2&page=1&modality=remote" -Headers @{Accept='application/json'}
$catalog | ConvertTo-Json -Depth 8
Invoke-RestMethod -Uri "$base/providers?per_page=2" | ConvertTo-Json -Depth 8
# Elegir IDs reales del catálogo público:
$serviceId = $catalog.data[0].id
$providerId = $catalog.data[0].provider.id
Invoke-RestMethod -Uri "$base/services/$serviceId" | ConvertTo-Json -Depth 8
Invoke-RestMethod -Uri "$base/providers/$providerId" | ConvertTo-Json -Depth 8
Invoke-RestMethod -Uri "$base/services/$serviceId/availability" | ConvertTo-Json -Depth 8
Invoke-RestMethod -Method Post -Uri "$base/auth/logout" -Headers $headers
# Debe fallar con 401; comprobar JSON y ausencia de trazas:
try { Invoke-RestMethod -Uri "$base/me" -Headers $headers } catch { $_.ErrorDetails.Message }
$headers = $null
$login = $null
```

La respuesta de login debe ser 201; la segunda sesión con otro `device_name` debe seguir válida tras logout de la primera. Repetir login con el mismo nombre invalida el token anterior. Tras cinco dispositivos, un sexto elimina el móvil más antiguo (esperar el intervalo de limitación entre emisiones). Probar también:

- Contraseña errónea, correo inexistente, prestador sin rol cliente y administrador sin rol cliente: mismo 401 y mensaje.
- Sesión web sin Bearer en `/me`: 401; Bearer sin cookie en dashboard/administración: redirección a login web.
- Cuenta dual: `/me` solo muestra experiencia y permisos de cliente. Retirar rol cliente en **la copia TEMP** hace que su token reciba 403 inmediatamente.
- Servicio borrador, pausado, categoría inactiva o prestador sin rol vigente: 404 en detalle/disponibilidad; ausente en listas.
- `services?per_page=51`, `services?user_id=1` y filtro desconocido: 422 JSON. Después de cinco logins fallidos del mismo correo/IP: 429 y `Retry-After`.
- Horarios: zona IANA, días ISO y fechas civiles; nada de IDs internos, revisiones o reservas. Campos públicos hostiles son texto, nunca HTML ejecutable.
- Abrir la web y el dashboard con una sesión web normal para confirmar continuidad. No emplear credenciales ni datos de producción.

La caducidad a 30 días, errores 409/500/503 seguros, reversibilidad y separación entre usuarios también se comprueban automatizadamente en memoria. No cambiar el reloj del sistema ni modificar la base original para probarlos.
