# MindBloom — base de Tekora

Aplicación Laravel 12 con Blade, CSS y JavaScript. La Etapa 1 estabiliza la base existente; la Etapa 2A añade roles múltiples y perfiles centrales; la Etapa 2B habilita su registro, activación y gestión desde la web. Se conservan las pantallas MindBloom y las identidades `users`/`web` y `profesionistas`/`profesionista`, sin fusionar cuentas.

## Requisitos

- PHP 8.2 o superior compatible con `composer.lock`; Composer 2.
- Extensiones PHP: PDO, `pdo_sqlite`, SQLite3, ctype, curl, DOM, fileinfo, filter, hash, mbstring, OpenSSL, session, tokenizer y XML (incluidos XMLReader/XMLWriter). GD se recomienda para imágenes/PDF; ZIP facilita la instalación de dependencias.
- Node.js 22 o 24 LTS y npm. Se deben respetar `composer.lock` y `package-lock.json`.
- Git. En Windows, habilitar las extensiones en el `php.ini` que muestra `php --ini`.

## Instalación nueva en Windows / PowerShell

Es preferible usar una carpeta nueva para no mezclar cambios locales. Desde su carpeta de proyectos:

```powershell
git clone --branch codex/etapa-4a-seguridad-dependencias --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa4a
Set-Location .\tekora-etapa4a
php --version
php --ini
php -m
composer install
npm.cmd ci

# Crear entorno y clave solo cuando todavía no existe .env.
if (-not (Test-Path .\.env)) {
    Copy-Item .\.env.example .\.env
    php artisan key:generate
}
```

Revisar manualmente el entorno antes de migrar: `DB_CONNECTION=sqlite`, sin `DB_URL` que lo sustituya. Puede dejarse `DB_DATABASE` sin definir para usar el archivo `database/database.sqlite`, o indicar una ruta absoluta con barras `/`, por ejemplo `C:/proyectos/tekora-etapa4a/database/database.sqlite`. No copiar una configuración de producción sin revisar sus conexiones. No publicar `.env`.

**El siguiente bloque es solo para una instalación nueva, sin datos. Si la base ya existe, seguir el procedimiento de actualización de la guía, sin borrar el archivo.**

```powershell
if (Test-Path .\database\database.sqlite) {
    throw "La base ya existe. No la borres: sigue el procedimiento para bases existentes."
}
New-Item -ItemType File -Path .\database\database.sqlite | Out-Null
php artisan migrate
php artisan db:seed --class=ServiceCategorySeeder
php artisan storage:link
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
php artisan route:list --except-vendor
php artisan serve
```

`storage:link` puede requerir el modo de desarrollador de Windows o permisos para enlaces simbólicos. Abrir la dirección que indique `artisan serve`.

El seed es opcional y exclusivamente de desarrollo: `php artisan db:seed`. Crea emociones y una cuenta de demostración cuya contraseña procede de la factory; no ejecutarlo en producción. Las imágenes emocionales indicadas por el seeder no están incluidas: migrar/sembrar no garantiza que esas imágenes se visualicen.

## Configuración necesaria

| Variables | Uso |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` | Entorno, depuración, URL y cifrado Laravel. Conservar la clave de instalaciones existentes. |
| `DB_CONNECTION`, `DB_DATABASE`; `DB_URL` si se utiliza | Selección de conexión. Esta etapa solo valida SQLite. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Persistencia de infraestructura; sus tablas están en las migraciones. |
| `FILESYSTEM_DISK` | Disco predeterminado; los avatares utilizan explícitamente el disco público. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Correo. El transporte `log` no envía mensajes y puede registrar enlaces de recuperación: proteger los logs. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | OAuth de Calendar. El callback debe coincidir con `/google/callback` en la URL de la aplicación. |

Los valores privados se configuran localmente y no se agregan al repositorio. No se cambió `.env.example` ni `.env` en esta entrega.

## Verificación y actualización segura

La suite fuerza SQLite `:memory:`, correo/caché/sesión en memoria y un contexto de pruebas separado. No requiere Google ni SMTP. No usa reseteos ni rollback sobre bases persistentes. La reversibilidad de migraciones se comprueba exclusivamente en memoria.

```powershell
php .\vendor\bin\phpunit --do-not-cache-result
npm.cmd run build
```

Para bases existentes, respaldos, revisión de cambios locales, estrategia de migraciones y limitaciones, consultar [la guía de estabilización](docs/estabilizacion-etapa-1.md). **No aplicar los pasos de instalación nueva sobre una base existente ni regenerar su `APP_KEY`.**

## Etapa 2A: identidades y roles

La identidad central incorpora varios roles por cuenta y un perfil profesional separado. Los accesos antiguos siguen funcionando; no se fusionan cuentas por email ni se crean administradores automáticamente. Consultar [arquitectura, migraciones, permisos y prueba con Laragon/PowerShell](docs/identidades-roles-etapa-2a.md).

## Etapa 2B: registro y perfiles desde la web

El registro crea una sola cuenta `User` para contratar, ofrecer servicios o ambas opciones. Desde `/cuenta` se activa Prestador, se conserva Cliente y se gestiona el nombre público, titular profesional y biografía. La navegación utiliza los permisos existentes y permite cambiar de experiencia sin cerrar sesión.

El registro de nuevas cuentas `Profesionista` queda retirado; su acceso anterior, citas y disponibilidad se conservan. Los perfiles centrales todavía no aparecen en el directorio anterior ni reciben sus citas. Esta etapa no agrega migraciones ni concede privilegios administrativos.

Consultar [pantallas, rutas, archivos, pruebas y guía de Laragon/PowerShell](docs/registro-perfiles-etapa-2b.md). La entrega 2B se validó con **78 pruebas y 732 aserciones**, con SQLite en memoria.

## Etapa 3A: catálogo de servicios y prestadores

Los prestadores centrales gestionan borradores y publicaciones en `/cuenta/servicios`. Visitantes y clientes exploran `/servicios` con búsqueda, filtros y paginación, y `/prestadores` muestra fichas públicas solo cuando tienen servicios visibles. Las rutas privadas del perfil conservan su protección.

Esta entrega agrega **dos migraciones** sobre las 26 anteriores y un seeder idempotente de categorías, sin vincular identidades antiguas ni conectar las citas. La entrega 3A se validó con **110 pruebas y 1132 aserciones** en memoria. En esa etapa quedaron alertas npm pendientes; la corrección se documenta en 4A.

Consultar [esquema, rutas, permisos, archivos, validación, auditoría npm y procedimiento seguro de actualización en Laragon/PowerShell](docs/catalogo-servicios-etapa-3a.md).

## Etapa 3B: solicitudes y seguimiento

Cliente puede solicitar un servicio público y consultar `/cuenta/solicitudes`; Prestador revisa `/cuenta/solicitudes/recibidas`. Las decisiones autorizadas permiten aceptar, rechazar, cancelar mientras esté pendiente y finalizar después de aceptar. El título guardado y las fechas mantienen el historial aunque cambie la publicación.

Esta etapa agrega una migración sobre las 28 anteriores y una restricción SQLite contra solicitudes activas duplicadas. No crea citas, reservas, pagos ni contratos automáticos. La entrega 3B se validó con **143 pruebas y 1573 aserciones**, usando solo SQLite en memoria.

Consultar [estados, permisos, concurrencia, rutas, pruebas, auditoría npm y actualización segura en Laragon/PowerShell](docs/solicitudes-servicios-etapa-3b.md).

## Etapa 4A: seguridad de dependencias

La auditoría npm completa pasa de 13 paquetes afectados a **cero**. Se actualizan Axios/Vite y transitivas compatibles, y se retiran Tailwind y su hoja de scaffolding sin uso. Bootstrap, Sass, Laravel 12 y los flujos existentes se conservan; no hay nuevas migraciones ni seeds para actualizar desde 3B.

La instalación limpia y la compilación pasan, al igual que **145 pruebas y 1591 aserciones** (las 143 anteriores más dos regresiones de cabeceras CSRF). Se verifican Node 22/24 y la comparación visual antes/después. Persisten deprecaciones Sass/Bootstrap, diferenciadas de los avisos de seguridad.

Consultar [versiones, avisos GHSA, evidencia antes/después, alcance del bundle e instrucciones Laragon/PowerShell](docs/seguridad-dependencias-etapa-4a.md).

## Etapa 4B: notificaciones internas

`/cuenta/notificaciones` reúne los avisos de solicitudes enviadas y recibidas en la identidad central. La navegación muestra las no leídas; cada aviso propio puede marcarse como leído sin cambiar su primera fecha de lectura. Los enlaces privados dependen de los roles y permisos actuales.

Se agrega una migración aditiva sobre las 29 existentes. Solicitud y aviso se guardan en la misma transacción, con unicidad en SQLite. No se generan avisos retroactivos ni se conectan las citas o cuentas profesionales anteriores. No hay cambios de dependencias.

La entrega se valida con **169 pruebas y 1867 aserciones**; las 145 anteriores conservan sus 1591 aserciones. Consultar [modelo, consistencia, rutas, resultados y prueba segura en Laragon/PowerShell](docs/notificaciones-internas-etapa-4b.md) antes de aplicar la migración en una copia aislada.

## Etapa 5A: conversación privada por solicitud

El detalle de cada solicitud incorpora una conversación de texto entre sus participantes centrales, separada de la necesidad inicial. Solo admite mensajes en `pending` y `accepted`; los estados terminales conservan el historial en modo de lectura. Se aplican las políticas vigentes, CSRF, límites de frecuencia, paginación por cursor e idempotencia por envío, sin avisos automáticos por mensaje.

Se agrega una migración sobre las 30 anteriores, sin modificar las notificaciones ni conectar el sistema heredado. Validación: **196 pruebas y 2279 aserciones**, incluidas las 169 anteriores con sus 1867 aserciones, y auditoría npm completa sin vulnerabilidades reportadas.

Consultar [arquitectura, concurrencia, rutas, resultados y validación aislada en Laragon/PowerShell](docs/mensajeria-solicitudes-etapa-5a.md). La revisión manual se prepara en `C:\laragon\www\tekora-etapa5a`, puerto **8006**; no aplicar cambios sobre la instalación original.

## Etapa 5B: mensajes no leídos y bandeja

`/cuenta/mensajes` reúne las solicitudes autorizadas y prioriza las que tienen recibidos sin leer. La navegación muestra un contador individual, sin incluir mensajes propios. La conversación permite marcar explícitamente los recibidos hasta el límite de la página mostrada; abrirla no cambia el progreso y un formulario antiguo no marca llegadas posteriores.

La migración aditiva **31→32** incorpora progreso por cuenta/solicitud e índices, sin modificar los mensajes, `InquiryWorkflow`, `InquiryMessaging` o las notificaciones 4B. La entrega pasa **219 pruebas y 2599 aserciones**, incluidas las 196 anteriores con sus 2279 aserciones.

Consultar [persistencia, seguridad, concurrencia, evidencias y validación Laragon/PowerShell](docs/mensajes-no-leidos-etapa-5b.md). Copia de revisión: `C:\laragon\www\tekora-etapa5b`, puerto **8007**.

## Etapa 6A: disponibilidad informativa

Cada prestador central gestiona `/cuenta/disponibilidad`: turnos semanales, días sin atención, fechas bloqueadas y zona IANA. Las fichas públicas muestran los horarios orientativos únicamente con la visibilidad actual del catálogo. Sin configuración aparece «Horario no publicado»; no se crean citas o reservas.

La migración aditiva **32→33** crea tres tablas independientes del sistema heredado. Las horas son civiles de la zona elegida, inicialmente `America/Mexico_City`. Se usan revisiones, transacciones y restricciones SQLite contra superposiciones. Validación: **247 pruebas y 2960 aserciones**, con las 219 anteriores y sus 2599 aserciones conservadas.

Consultar [arquitectura, fechas/zonas, seguridad, evidencias y guía de Laragon/PowerShell](docs/disponibilidad-prestadores-etapa-6a.md). Crear una copia nueva en `C:\laragon\www\tekora-etapa6a`, puerto **8008**, sin tocar clones anteriores.
