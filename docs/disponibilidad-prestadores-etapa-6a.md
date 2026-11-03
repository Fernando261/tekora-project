# Etapa 6A: disponibilidad y horarios de prestadores

Entrega del 30 de octubre de 2026, horario de Ciudad de México. Rama `codex/etapa-6a-disponibilidad-prestadores`, creada desde `origin/main` en `43a64e13e52debdf127311ec2be2af96cb1658a5`, con el PR #9 confirmado como fusionado. Entrega en PR en borrador, sin integración automática ni despliegue.

## Funcionalidad y alcance

Un prestador central puede guardar una zona IANA, varios turnos por día y fechas completas sin atención. Gestiona exclusivamente la configuración de su propio `ProviderProfile` desde `/cuenta/disponibilidad`, accesible también desde su panel y navegación. Si todavía no tiene perfil, se le orienta a completarlo mediante el flujo existente.

Guardar publica la configuración informativa cuando el prestador tiene servicios visibles en el catálogo. La ficha pública central y los servicios publicados muestran el mismo horario del prestador. No se cambian filtros, reglas de publicación o campos de identidad del catálogo; sus controladores y `Service::publiclyVisible` permanecen intactos. Se añade el componente visual a las vistas existentes.

Aviso visible: **«Horarios orientativos, sujetos a confirmación con el prestador»**. No se crean reservas, citas, contratos, pagos, espacios de duración fija o compromisos automáticos. Las solicitudes actuales siguen permitidas según sus reglas anteriores, sin condicionarlas a estos horarios.

| Situación | Presentación |
| --- | --- |
| No existe configuración | «Horario no publicado»; no se presume atención libre |
| Configuración guardada y día sin intervalos | «Sin atención» |
| Turnos guardados | Horas locales, en formato público de 24 horas |
| Fechas bloqueadas | Días completos vigentes, en la zona configurada |
| Error temporal al leer el horario | «Horario temporalmente no disponible. Consulta al prestador.» |
| Servicio pausado/borrador o ficha sin servicios visibles | Se conserva la restricción 404 actual, sin publicar horarios por una vía alternativa |

Si otro servicio del mismo prestador continúa publicado, su ficha puede seguir visible con el horario general, pero el servicio pausado continúa inaccesible. La configuración se conserva al pausar servicios o revocar el rol; su exposición pública sigue dependiendo del catálogo y del rol actual.

## Modelo y migración aditiva

Nueva migración única: `2026_10_30_000002_create_provider_availability_tables.php`. Pasa de **32 a 33 migraciones**, sin editar las 32 históricas. Usa SQLite explícitamente para CHECKs y triggers, coherente con el motor validado del proyecto.

| Tabla nueva | Datos y restricciones |
| --- | --- |
| `provider_availability_settings` | FK única a `provider_profiles`, zona IANA, revisión entera positiva y fechas de creación/edición |
| `provider_weekly_intervals` | FK al perfil, día ISO 1–7, inicio y fin en minutos civiles enteros 0–1439, inicio menor al fin y unicidad del intervalo por perfil/día |
| `provider_blocked_dates` | FK al perfil y fecha civil ISO única por perfil, con formato/rango/validez de calendario comprobados |

Todas las FKs apuntan al `ProviderProfile` central y restringen eliminación física. No hay referencias a `Profesionista`, disponibilidades antiguas, bloqueos antiguos o `Cita`. Tampoco se añaden columnas a las tablas anteriores.

`ProviderProfile` incorpora relaciones aditivas `availabilitySetting()`, `weeklyIntervals()` y `blockedDates()`. Los modelos nuevos bloquean asignación masiva. Intervalos y fechas no usan timestamps de edición individuales: la revisión y fechas de la configuración identifican cada edición completa.

El índice `(provider_profile_id, day_of_week, start_minute)` apoya el orden semanal y las comprobaciones de superposición. La unicidad de fechas proporciona un índice por perfil/fecha. Dos triggers, exclusivos de la tabla semanal nueva, rechazan superposiciones tanto en INSERT como en UPDATE, incluso al omitir la capa de aplicación. Turnos contiguos, como 09:00–12:00 y 12:00–17:00, son válidos.

La migración no crea configuraciones iniciales ni copia horarios anteriores. `down()` elimina solo las tres tablas nuevas y los triggers pertenecientes a la tabla semanal; se verifica exclusivamente en memoria. No se recomienda revertir sobre una instalación con horarios guardados: se perdería esa configuración y el código 6A requiere el nuevo esquema.

## Decisiones de fecha y zona horaria

El valor inicial del formulario es **`America/Mexico_City`**, visible y modificable. No se guarda ni se atribuye una zona al prestador hasta que confirma su configuración. No se cambia la zona global de Laravel, `.env` o `APP_KEY`.

- Días ISO: lunes = 1, martes = 2, …, domingo = 7; no reutilizan convenciones de tablas heredadas.
- Cada hora se valida como HH:MM de 00:00 a 23:59 y se almacena como minutos desde el inicio del día civil. No representa un instante UTC ni incluye una fecha o duración reservable.
- Inicio debe ser menor al fin. No se admite 24:00, horas con segundos o turnos que crucen medianoche. Un día sin turnos significa sin atención.
- Se aceptan identificadores y alias IANA disponibles en PHP, incluidos `UTC`, `Etc/UTC` y `US/Central`. Se rechazan offsets arbitrarios, abreviaturas ajenas a la lista y `Factory`, que representa una zona desconocida.
- Cambiar la zona conserva los números de hora y las fechas: 09:00 sigue siendo 09:00 local de la nueva zona. No se convierte un horario anterior entre zonas.
- Las fechas bloqueadas son cadenas civiles `YYYY-MM-DD`, entre **2000-01-01 y 2100-12-31**, con comprobación gregoriana y unicidad. No se convierten a medianoches UTC ni se normalizan por horario de verano.
- Se pueden conservar fechas pasadas para que la configuración siga siendo editable después de un cambio de día/año. La vista pública filtra desde «hoy» calculado una sola vez en la zona del prestador; la edición muestra también las pasadas.

En cambios de horario de verano, 6A muestra las horas civiles tal como fueron declaradas. Por ejemplo, un turno dominical 02:00–04:00 permanece escrito así aunque una fecha de esa zona tenga una hora inexistente o repetida. Eso no asegura disponibilidad efectiva ni genera instantes. Resolver huecos, ambigüedades y espacios reservables corresponde a 6B, antes de permitir reservas.

La presentación pública de fechas usa sus componentes de calendario, sin reinterpretar una cadena como instante. Los controles de hora del editor pueden usar 12 o 24 horas según el navegador; su valor enviado es HH:MM y la publicación siempre usa 24 horas.

## Escritura, concurrencia y lectura consistente

`ProviderAvailability::save()` infiere el perfil desde el User recibido, vuelve a autorizar con la policy existente y valida mediante las reglas compartidas de `AvailabilityInput`. No recibe un propietario elegido por el navegador.

Cada guardado reemplaza **solo** los turnos y fechas del perfil autorizado dentro de las tablas nuevas. Es una edición completa, no una mezcla de filas procedentes de formularios distintos. Los IDs de intervalos/fechas pueden cambiar; no son identificadores de reservas.

1. Un formulario sin configuración usa revisión 0. El primer guardado inserta la configuración con revisión 1, protegida además por unicidad del perfil.
2. Una edición existente actualiza la configuración únicamente si coincide la revisión enviada; incrementa su revisión dentro de la transacción. Esa escritura adquiere el bloqueo SQLite antes de reemplazar filas.
3. Una petición antigua o dos editores con la misma revisión no pueden sobrescribirse silenciosamente. El perdedor recibe 409 y debe recargar antes de preparar otra edición.
4. Zona, turnos y fechas se guardan o revierten juntos. Un fallo posterior al cambio de revisión también revierte esa revisión. La reversión de una transacción exterior conserva la configuración anterior.
5. Las reglas de aplicación rechazan superposiciones y los triggers SQLite mantienen esa garantía incluso para dos escritores que intenten saltarse el servicio.

Se usan tres intentos transaccionales ante bloqueos reconocidos. No se promete éxito inmediato para todas las operaciones simultáneas: puede devolverse un conflicto recuperable. Las lecturas de la configuración privada y pública también usan una instantánea transaccional, para no mezclar una zona anterior con horas o fechas de una revisión posterior.

Ante un bloqueo/error de lectura, la ficha pública conserva el servicio y muestra el mensaje temporal seguro, sin asumir horario libre ni confundirlo con una configuración inexistente. El editor devuelve 503 con una explicación de consulta no disponible. Los errores de escritura responden 409/503 sin SQL, bindings ni cadenas de excepciones; `AvailabilityFailure` implementa `ShouldntReport`. Esto se prueba también con depuración activa.

## Rutas, autorización y validación

| Método / ruta | Nombre | Control |
| --- | --- | --- |
| GET `/cuenta/disponibilidad` | `availability.edit` | `auth:web` y propietario con permiso actual de prestador |
| PATCH `/cuenta/disponibilidad` | `availability.update` | Los mismos controles, CSRF, revisión y límite de frecuencia |

El formulario HTML usa POST con `_method=PATCH`. No existen rutas para seleccionar o editar un perfil ajeno. Se reutilizan `ProviderProfilePolicy::create/update`, con propiedad y `ManageProviderProfile`; la capacidad administrativa de revisar perfiles no concede edición de horarios ajenos.

Se rechazan campos de identidad/propiedad, privilegios, IDs de configuración y minutos internos enviados por el cliente. Los intervalos solo admiten día/inicio/fin. La propiedad se obtiene de `User::providerProfile()`, no de campos ocultos. Los campos ocultos de día y revisión solo expresan la configuración propuesta y su versión.

Límites de entrada: **42 filas semanales** y **100 fechas distintas**. Ambas horas vacías omiten ese turno; una sola hora o un orden inválido produce validación fallida. Las fechas se introducen una por línea; CRLF y líneas vacías se normalizan. No se almacenan motivos personales de bloqueo.

Hay **10 intentos de edición por minuto por cuenta**, incluidos los fallidos, con 429 y `Retry-After`. Los errores JSON de validación responden 422 y los formularios HTML conservan datos seguros y muestran sus mensajes habituales con redirección. CSRF mantiene 419 y permisos/identidad mantienen 403/401 o redirección de invitado.

Blade escapa todos los valores. El JavaScript progresivo únicamente añade o quita filas del borrador y mantiene nombres únicos, foco y máximo de filas; no envía peticiones ni genera calendarios. Sin JavaScript se editan las filas existentes y se ofrecen inicialmente dos por día. Quitar filas del borrador no persiste hasta guardar.

## Compatibilidad y preservación

No se modifican los controladores de catálogo, `Service::publiclyVisible`, campos de identidad del perfil, `InquiryWorkflow`, `InquiryMessaging`, `InquiryReadTracking`, modelos/eventos/esquema de notificaciones o reglas de solicitudes. La integración pública añade un componente y un punto de estilos en el layout, sin cambiar las reglas de publicación.

Las pruebas comparan todas las filas y columnas anteriores durante 32→33, guardados y reversión en memoria, incluidas solicitudes, mensajes, claves de idempotencia, avisos, progreso de lectura, cuentas, sesiones y datos heredados. También comprueban que un reenvío de mensaje sigue siendo idempotente después de editar horarios.

Una cuenta antigua con el mismo correo no aporta horarios a Tekora y no puede editarlos desde su guard. No se fusionan identidades, cambian contraseñas o permisos, copian bloqueos/citas ni se crea ninguna reserva.

## Resultados y evidencias

Referencia antes de modificar código: **219 pruebas y 2599 aserciones**. Resultado final: **247 pruebas y 2960 aserciones**, con **28 pruebas y 361 aserciones adicionales**. La selección de las 219 anteriores también pasa con sus 2599 aserciones.

No se elimina ni modifica ninguna aserción anterior. Se acota únicamente `ConversationMigrationTest` a su escenario histórico 31→32, manteniendo sus contadores y reversión. La nueva prueba verifica 32→33 y reversión exclusiva de 6A.

Cobertura nueva: propietario/terceros/admin/roles revocados, ausencia de perfil, turnos múltiples/vacíos/contiguos/inválidos, superposición/duplicación/contenimiento, guardas INSERT/UPDATE en dos PDO, revisiones y ediciones iniciales/simultáneas, rollback/fallos, fechas gregorianas/años bisiestos/cambios de mes y año, zonas IANA y alias, DST, lectura consistente y errores seguros durante bloqueos, servicios pausados/ocultos, privacidad, CSRF real, campos manipulados, edición máxima de 42 filas, frecuencia, preservación de relaciones y separación heredada.

| Comando | Resultado |
| --- | --- |
| `composer install --no-interaction --prefer-dist` | Código 0; lock intacto, autoload y package discovery correctos |
| `npm ci` | Código 0; instalación limpia desde lock |
| `npm audit` y `npm audit --json` | Código 0; **cero vulnerabilidades** reportadas |
| `npm run build` | Código 0; mismos assets Vite que 5B |
| `php vendor/bin/phpunit --do-not-cache-result` | **247 / 2960**, código 0 |
| Selección de las 219 anteriores | **219 / 2599**, código 0 |

Entorno: PHP 8.2.35, PHPUnit 11.5.24 y Node 24.19.0/npm 11.9.0. SQLite en memoria; concurrencia con dos PDO distintos y URI `mode=memory&cache=shared`, sin archivo físico. Todas las migraciones y reversiones ejecutadas por las pruebas quedan en memoria.

Composer reutilizó los paquetes del lock montados de solo lectura, en una copia 6A nueva sin `.env`, con metadatos/wrappers independientes y red desactivada. Persisten los avisos previos Flysystem/Pint y deprecaciones Sass/Bootstrap. No se cambian dependencias, manifiestos o locks. Los estilos y JavaScript nuevos se sirven desde `public`; no alteran las entradas de Vite.

Revisión Chromium: **32 comprobaciones**, correspondientes a 14 estados en escritorio 1440×1000 y móvil 390×844, más cuatro comprobaciones sin JavaScript. Incluye vacío/configurado, perfil/servicio públicos, todos los días sin atención, servicio pausado, editor todavía accesible al propietario, 403, conflicto y lecturas temporalmente no disponibles. Se comprueban añadir/quitar turnos, nombres únicos, CSRF/PATCH, zona inicial, fechas futuras, menú móvil y lectura de controles de hora. Sin errores JavaScript, peticiones externas o desbordamiento horizontal. Se inspeccionaron capturas del editor, turno móvil y fichas públicas.

La revisión visual usa rutas renderizadas con fixtures nuevos en memoria y HTML estático; el conflicto se renderiza desde su vista. Los errores de lectura se simulan solo en ese esquema de memoria. PHPUnit verifica HTTP, autorización y escrituras. Windows/Laragon queda pendiente de revisión local; no se afirma su ejecución en Linux.

Evidencias: [resultados](validaciones/etapa-6a/resultados.json), [auditoría npm](validaciones/etapa-6a/npm-audit.json), [revisión visual](validaciones/etapa-6a/visual.json). Se verificaron sin cambios los hashes de `.env`, base persistente, locks, las 32 migraciones y componentes protegidos de catálogo/solicitudes/mensajes/avisos/lectura. No se publican valores privados y no se reutilizaron o modificaron clones de revisión anteriores.

## Archivos

| Archivos | Cambio |
| --- | --- |
| `database/migrations/2026_10_30_000002_create_provider_availability_tables.php` | Tres tablas, FKs, CHECKs, unicidad, índice y triggers |
| `app/Models/ProviderAvailabilitySetting.php`, `ProviderWeeklyInterval.php`, `ProviderBlockedDate.php`, `ProviderProfile.php` | Modelos y relaciones aditivas |
| `app/Enums/AvailabilityDay.php`, `app/Support/AvailabilityInput.php` | Días ISO, normalización y reglas compartidas |
| `app/Services/ProviderAvailability.php`, `app/Exceptions/AvailabilityFailure.php` | Escritura/lectura consistente, revisiones y errores seguros |
| `app/Http/Requests/ProviderAvailabilityRequest.php`, `app/Http/Controllers/ProviderAvailabilityController.php` | Autorización, formulario y PATCH |
| `routes/availability.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php` | Rutas y frecuencia |
| `resources/views/availability/*.blade.php`, `components/provider-availability.blade.php` | Editor, errores y resumen público |
| `resources/views/components/account-navigation.blade.php`, `components/catalog-layout.blade.php`, `account/provider.blade.php`, `catalog/show.blade.php`, `public-providers/show.blade.php` | Accesos y presentación aditiva |
| `public/css/availability.css`, `public/js/availability.js` | Estilos y mejora progresiva |
| `tests/Feature/Availability*.php`, `tests/Support/AvailabilityFixtures.php` | Seis clases nuevas y fixtures |
| `tests/Feature/ConversationMigrationTest.php` | Escenario histórico acotado sin cambiar aserciones |
| `README.md`, esta guía y evidencias JSON | Entrega |

## Laragon / PowerShell: copia nueva y puerto 8008

Usar **`C:\laragon\www\tekora-etapa6a`**, sin tocar `tekora-etapa5b` ni otros clones. Desde PowerShell de Laragon, con PHP 8.2+, extensiones SQLite y Node 22/24 compatibles con los locks:

```powershell
Set-Location C:\laragon\www
if (Test-Path -LiteralPath 'C:\laragon\www\tekora-etapa6a') {
    throw 'La carpeta 6A ya existe. No sobrescribir ni reutilizar un clon anterior.'
}
git clone --branch codex/etapa-6a-disponibilidad-prestadores --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa6a
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la copia nueva.' }
Set-Location C:\laragon\www\tekora-etapa6a
php --version
php --ini
php -m
composer install
npm.cmd ci
npm.cmd audit
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
```

Esperado: 247 pruebas y 2960 aserciones, auditoría npm limpia y build correcto. La suite no necesita `.env` ni una base física; fuerza SQLite en memoria. No ejecutar seeds generales para las pruebas.

Para la revisión web, preparar únicamente esta carpeta con un entorno local de desarrollo y una copia consistente de la base 5B con 32 migraciones y cuentas de prueba. Conservar la clave de esa copia; no regenerar `APP_KEY`, modificar credenciales o entornos originales, ni versionar `.env` o bases. Este paso de preparación local no se ejecutó sobre la instalación del usuario.

El siguiente bloque toma el SQLite de desarrollo previamente colocado dentro de la carpeta 6A y crea otro archivo en TEMP. Las variables solo afectan esta terminal; usar `artisan serve` desde ella para que las herede, sin depender del Apache de Laragon ya iniciado:

```powershell
Set-Location C:\laragon\www\tekora-etapa6a
$TekoraCopia = (Get-Location).Path
$BaseDesarrollo = Join-Path $TekoraCopia 'database\database.sqlite'
if (-not (Test-Path -LiteralPath $BaseDesarrollo)) {
    throw 'Falta la copia de desarrollo de SQLite en tekora-etapa6a.'
}
$BasePrueba = Join-Path ([IO.Path]::GetTempPath()) ('tekora-6a-' + [guid]::NewGuid().ToString() + '.sqlite')
Copy-Item -LiteralPath $BaseDesarrollo -Destination $BasePrueba
$env:APP_ENV = 'local'
$env:APP_URL = 'http://127.0.0.1:8008'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-6a-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $BasePrueba
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa6a_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
Write-Host "Base exclusiva de revisión: $BasePrueba"
php artisan migrate:status
php artisan migrate --pretend
# Solo debe estar pendiente create_provider_availability_tables, migración 33.
php artisan migrate
if ($LASTEXITCODE -ne 0) { throw 'La migración de prueba no terminó correctamente.' }
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8008
```

Obtener la copia de origen con la aplicación detenida o con su procedimiento de respaldo consistente, especialmente si usa WAL. No copiar parcialmente una base activa. No utilizar `migrate:fresh`, `db:wipe`, rollback destructivo o seeds generales. Los comandos anteriores deben operar solo sobre el archivo nuevo de prueba. Cerrar PowerShell al terminar descarta estas variables.

Recorrido en **`http://127.0.0.1:8008`**:

1. Con un prestador central que tenga perfil, abrir Disponibilidad: verificar zona inicial explícita y ausencia de configuración publicada. Con un perfil sin completar, comprobar la orientación al formulario existente.
2. Guardar dos turnos del lunes y uno del miércoles; dejar otros días sin atención. Añadir fechas futuras y una pasada. Confirmar guardado y consultar perfil público/servicio publicado: la zona debe verse, los turnos coincidir y solo las fechas vigentes aparecer públicamente.
3. Probar igualdad inicio/fin, cruce de medianoche, horas inválidas, duplicación y superposición. Confirmar rechazo sin alterar la configuración anterior; turnos adyacentes deben aceptarse.
4. Probar fechas imposibles y duplicadas, año bisiesto, cambio de mes/año y zonas como UTC o America/New_York. Cambiar zona debe conservar números de hora/fechas, sin convertirlos como instantes UTC.
5. Abrir el mismo editor en dos pestañas. Guardar la primera y luego la segunda: debe responder 409, sin mezclar turnos o sobrescribir la revisión ganadora. Recargar y volver a preparar la edición.
6. Probar una cuenta ajena, administrador sin propiedad, campos de propietario manipulados y CSRF ausente/incorrecto. No debe editar otro perfil ni asignar privilegios. Once intentos en un minuto deben producir 429 sin avanzar otra revisión.
7. Pausar el servicio: su detalle debe seguir en 404; el propietario conserva su editor. Si no hay otros servicios visibles, la ficha pública tampoco debe exponer horarios. Reactivar conforme al flujo actual restaura la presentación informativa.
8. Revisar editor y fichas en móvil/escritorio, formato nativo de hora, añadir/quitar turnos y edición básica sin JavaScript. Guardar una semana vacía debe mostrar «Sin atención», no disponibilidad libre.
9. Confirmar que solicitudes, avisos, mensajes, reenvíos idempotentes y contadores de lectura permanecen iguales. Probar el acceso antiguo de Profesionista y sus horarios/citas sin vincular cuentas por correo.

## Riesgos y límites pendientes

La revisión manual Windows/Laragon queda pendiente del usuario. Los horarios son informativos: no expresan capacidad, duración, ocupación o reserva efectiva. No se generan instantes ni se resuelven ambigüedades DST para reservas; 6B deberá definirlo antes de ofrecer espacios contratables.

Guardar reemplaza las filas del módulo propio y exige la revisión vigente. No fusiona cambios entre pestañas; un conflicto requiere recargar y revisar. SQLite puede bloquear temporalmente lecturas/escrituras; las respuestas conservan un estado seguro. Los límites de 42 intervalos y 100 fechas son explícitos, y solo se muestran fechas vigentes públicamente, sin eliminar las pasadas.

La zona depende de los identificadores/reglas IANA disponibles en PHP. Se usa un valor inicial explícito, no geolocalización. Una futura política de retención o eliminación de perfiles deberá contemplar las nuevas FKs. No se almacenan razones privadas de bloqueo.

Persisten los avisos de Composer y deprecaciones Sass/Bootstrap anteriores. La auditoría npm limpia corresponde a esta entrega y no equivale a una auditoría Composer ni garantiza ausencia de alertas futuras. No se incorporan reservas, citas, pagos, calendario externo, correo o nuevas dependencias.
