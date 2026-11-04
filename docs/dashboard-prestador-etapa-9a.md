# Dashboard web del prestador — Etapa 9A

## Origen y alcance

Rama `codex/etapa-9a-dashboard-prestador`, creada desde `origin/main` actualizado después de comprobar que contiene **`7523cfd9a938b85958df26bf4de0543badc9467e`**, integración de Etapa 8. La referencia se reprodujo antes de implementar: **350 pruebas, 4337 aserciones y 36 migraciones**.

La ruta nueva **GET `/cuenta/prestador/dashboard`**, nombre **`provider.dashboard`**, presenta la actividad propia del prestador central. `/cuenta/prestador` conserva la configuración del perfil. La navegación y la página de cuenta ofrecen acceso al panel a quienes tienen permiso de prestador.

Se mantienen Laravel 12, Blade, CSS, formularios GET nativos y la apariencia actual. No se implementan API móvil, React Native, Expo, TypeScript, pagos, ingresos estimados ni datos ficticios en producción. Las capturas usan únicamente fixtures sintéticos de prueba.

**No hay migraciones nuevas**: siguen siendo 36. No se crearon tablas de estadísticas, contadores almacenados, procesos de sincronización ni dependencias frontend. Los workflows, modelos, policies y reglas de lectura anteriores no cambian.

## Definiciones de los diez KPI

Todos los recursos se restringen al perfil resuelto desde la relación `User::providerProfile()` del usuario central autenticado. Los vínculos de solicitud y reserva corresponden al prestador propietario con rol vigente; los controladores de destino vuelven a autorizar sus policies.

| KPI | Fuente y fórmula | Alcance y estado vacío |
| --- | --- | --- |
| 1. Servicios publicados | `Service::publiclyVisible()` + `provider_profile_id` propio. Requiere servicio publicado, categoría activa y rol de prestador vigente. | Estado actual. Cero si no hay servicios visibles. |
| 2. Solicitudes recibidas | `service_inquiries` de servicios propios, con `created_at` dentro de los límites UTC seleccionados. | Del periodo. Cero sin altas. |
| 3. Solicitudes pendientes | Solicitudes de servicios propios cuyo estado actual es `pending`, cualquiera que sea su fecha. | Estado actual. Cero sin pendientes. |
| 4. Tasa de aceptación | `100 × A / (A + R)`. A: `accepted_at` en el periodo. R: `rejected_at` en el periodo. No se usa el estado actual para borrar una aceptación histórica ni la fecha de creación para atribuir la decisión. | Del periodo. «Sin decisiones» si A+R=0. Una decimal y numerador/denominador visibles. |
| 5. Reservas por confirmar | Reservas propias con `status = requested` e inicio UTC **estrictamente mayor** al instante consultado. | Estado actual. Cero; excluye propuestas vencidas o iniciadas. |
| 6. Próximas reservas | Reservas propias con `status = confirmed` e inicio UTC **estrictamente mayor** al instante consultado. | Estado actual. Cero; excluye reservas iniciadas aunque sigan confirmadas. |
| 7. Servicios completados | Eventos reales `reservation_events.to_status = completed`, relacionados con reservas del perfil propio, y `occurred_at_utc` en el periodo. | Del periodo. Cero sin transiciones reales. No cuenta una solicitud completada sin reserva. |
| 8. Tasa de cancelación | `100 × C / (C + F)`. C: eventos propios a `cancelled`; F: eventos propios a `completed`, ambos por `occurred_at_utc` en el periodo. | Del periodo. «Sin reservas finalizadas» si C+F=0. **Cancelaciones entre reservas finalizadas**, no entre todas las creadas. Rechazos de propuesta y solicitudes pendientes no pertenecen al denominador. |
| 9. Calificación promedio | AVG y COUNT de `ServiceReview::publiclyVisible()` restringido al perfil propio. Promedio ponderado por cada reseña, no promedio de promedios por servicio. | Estado público actual. Una decimal sobre 5, con número de reseñas. «Sin calificaciones» si COUNT=0. |
| 10. Mensajes sin leer | Suma SQL del `withUnreadCount()` de `InquiryReadTracking`, aplicada únicamente a solicitudes de servicios propios. Conserva autor distinto al usuario y mensaje posterior a su marcador. | Estado actual. Cero sin recibidos pendientes. Excluye mensajes de solicitudes donde la cuenta actúa como cliente de otro prestador. |

Las solicitudes aceptadas y después completadas conservan su `accepted_at` y entran en A cuando esa aceptación ocurrió en el periodo. Las pendientes y las canceladas sin aceptación no crean decisiones. Una aceptación antigua completada hoy no se convierte en una aceptación de hoy.

Los cierres de solicitud usan los eventos centrales existentes: la cancelación automática de una propuesta y la finalización automática de una reserva terminada se contabilizan igual que las acciones directas. No se usan `Cita`, `Profesionista` ni calendarios heredados.

El scope público de reseñas excluye las retiradas, servicios no públicos, categorías inactivas y correspondencias incoherentes entre reseña y reserva completada. Las restricciones SQLite existentes protegen estrellas, identidades y fechas. No se cargan comentarios ni autores para calcular el promedio.

**El contador global de Mensajes y el KPI 10 tienen ámbitos diferentes**: el menú mantiene la bandeja conjunta de cliente y prestador; el KPI cuenta solamente la experiencia de prestador. Ninguno modifica marcadores al consultarlo.

## Periodos y zona horaria

Solo se acepta el parámetro `period` con valores exactos `7`, `30` o `90`; por defecto se usan 30 días. Valores vacíos, arrays, negativos, decimales, otros números y parámetros adicionales —incluidos `provider_id`, `user_id`, `profile_id`— devuelven **422** con un mensaje seguro. No se interpreta ningún identificador del navegador como autorización.

Se captura una vez el instante UTC, con precisión de segundo, acorde con las fechas persistidas. Para N días:

- Inicio: medianoche de Ciudad de México de hoy menos N−1 días naturales.
- Fin: el instante capturado, incluido; nunca el final futuro del día actual.
- Ambos límites se convierten a UTC para comparar columnas almacenadas en UTC.
- Los buckets de la gráfica son intervalos locales **[medianoche, siguiente medianoche)** convertidos individualmente a UTC. El filtro global limita el último bucket al instante consultado.

Ejemplo: a las 12:00 del 10/04/2030 en Ciudad de México, 7 días empiezan el 04/04/2030 a las 00:00 locales (`06:00:00 UTC`) y terminan el 10/04 a las `18:00:00 UTC`. Las conversiones usan `America/Mexico_City` y sus reglas históricas; no se aplica un desplazamiento fijo de seis horas. Se prueba también el cambio de horario de 2022 y una aplicación/PHP configurados en Tokio.

La interfaz distingue cuatro indicadores «Del periodo» de seis «Estado actual». Todos muestran explicación y valor vacío definido. No hay porcentajes ficticios cuando falta denominador.

## Arquitectura, seguridad y privacidad

`ProviderDashboardController` delega los cálculos a `ProviderDashboardMetrics`. El servicio centraliza autorización, resolución de perfil, validación, límites, agregaciones, formato y estructura de las tarjetas. No hay SQL en Blade.

El servicio autoriza `Permission::ManageProviderProfile` con el usuario central y, si hay perfil, la acción `update` de su policy, que requiere propiedad y rol vigente. No usa relaciones de roles cacheadas. Una cuenta administradora sin rol prestador recibe **403**; con ambos roles solo ve su propio perfil. Cliente, terceros y guard heredado no obtienen acceso por coincidencia de correo ni por parámetros.

Un prestador sin perfil recibe instrucciones para crearlo y enlace a la configuración; el GET no crea perfil, servicios ni actividad. Un perfil sin actividad ve sus diez KPI y estados vacíos correctos.

El controlador mantiene una transacción de lectura durante métricas, listas y renderizado de navegación, para compartir instantánea. No actualiza reservas, solicitudes, reseñas, avisos, mensajes o marcadores. La sesión web conserva su funcionamiento normal; no se confunde infraestructura de sesión con una operación de negocio.

Se devuelven errores de filtro 422, autorización 403 y fallo temporal 503 con una vista segura que no consulta contadores. Invitados y cuentas autenticadas solo en el guard anterior deben iniciar sesión central. Los fallos PDO durante cálculo o renderizado no muestran SQL, bindings ni rutas internas, incluso con depuración activa.

Solo se proyectan títulos propios, IDs de enlaces autorizados y fechas necesarias para listas. No se seleccionan cuerpos de mensajes, comentarios de reseñas, nombres ni correos de clientes. Blade escapa nombre público y títulos, incluidos valores hostiles. La gráfica tiene nombre accesible y tabla alternativa con fechas y valores; el selector usa etiqueta y botón explícito, sin depender de JavaScript.

## Consultas y rendimiento

La carga del servicio realiza **11 consultas**, con o sin muchos registros, excluyendo autenticación y navegación global:

1. Permiso vigente.
2. Perfil propio, con columnas mínimas.
3. Policy de propiedad/permiso.
4. Agregación de solicitudes: recibidas, pendientes, aceptadas y rechazadas.
5. Agregación de reservas: propuestas futuras y confirmadas futuras.
6. Agregación de eventos: completadas, canceladas y buckets diarios.
7. Agregación de reseñas públicas: COUNT y AVG.
8. Suma de los subconteos de mensajes sin leer usando las reglas existentes.
9. Conteo de servicios públicos propios.
10. Agenda: como máximo **cinco** reservas confirmadas futuras, ordenadas por inicio UTC e ID ascendente.
11. Atención: como máximo **cinco** solicitudes pendientes, las más antiguas por creación e ID ascendente.

La gráfica contiene exactamente 7, 30 o 90 buckets. SQLite calcula los agregados mediante COUNT/CASE y AVG/SUM; no se descarga el historial para agregarlo en PHP. Los títulos se obtienen por JOIN, sin consultas por fila. Los accesos «Ver todas» llevan a las secciones existentes con su paginación y autorización.

Se reutilizan índices por perfil, servicio/estado, versión de evento, reseñas públicas y progreso de lectura. No se propone una migración especulativa. El coste de los agregados crece con el historial del prestador; cargas grandes reales podrían justificar medir planes y proponer índices temporales después, con revisión independiente. No se ha medido rendimiento sobre datos reales ni se afirma que once consultas impliquen tiempo constante.

## Archivos

| Archivo | Cambio |
| --- | --- |
| `app/Services/ProviderDashboardMetrics.php` | Autorización, ventanas, consultas y proyección de diez KPI, gráfica, agenda y pendientes |
| `app/Http/Controllers/ProviderDashboardController.php` | GET del panel, instantánea de lectura y errores seguros |
| `app/Exceptions/DashboardFailure.php` | Respuestas Blade controladas |
| `routes/identities.php` | Ruta `provider.dashboard` bajo `auth:web` |
| `resources/views/provider-dashboard/{index,error}.blade.php` | Pantalla principal y errores |
| `resources/views/components/provider-kpi.blade.php` | Tarjeta reutilizable |
| `public/css/provider-dashboard.css` | Diseño responsive acotado al panel |
| `resources/views/components/account-navigation.blade.php` | Entrada del panel solo para prestadores |
| `resources/views/account/index.blade.php` | Acceso desde la página de cuenta, conservando el perfil |
| `tests/Support/DashboardFixtures.php` | Fixtures que reutilizan workflows y escenarios anteriores |
| `tests/Feature/ProviderDashboard{Metrics,Access,Performance}Test.php` | 25 pruebas nuevas |
| `README.md`, esta guía, `docs/validaciones/etapa-9a/*` | Documentación, resultados, auditoría, informe visual y dos capturas |

## Validaciones ejecutadas

| Comando / comprobación | Resultado |
| --- | --- |
| Referencia antes de implementar | **350 pruebas / 4337 aserciones**, código 0 |
| `composer install --no-interaction --prefer-dist` | Código 0; autoload y package discovery correctos |
| `npm ci` | Código 0 |
| `npm audit` y `npm audit --json` | Código 0; **0 vulnerabilidades npm** |
| `npm run build` | Código 0; mismos assets Vite |
| `php vendor/bin/phpunit --do-not-cache-result` | **375 pruebas / 4633 aserciones**, código 0 |
| `php artisan route:list --except-vendor` | Código 0, **99 rutas**; sin `.env` ni SQLite persistente |
| `PRAGMA foreign_key_check` / `integrity_check` en memoria | Sin infracciones / `ok` |

Las **350 pruebas anteriores mantienen exactamente sus 4337 aserciones**. No se modificó ninguna prueba anterior. Las **25 nuevas aportan 296 aserciones** y cubren definiciones, fechas, denominadores vacíos, aceptación histórica, cierres automáticos, reseñas retiradas/no públicas/incoherentes, cuentas duales, aislamiento, roles, filtros, XSS, orden/límites, ausencia de escrituras y consultas constantes.

PHP 8.2.35, PHPUnit 11.5.24, Laravel 12.19.3, Node 24.19.0 y npm 11.9.0. PHP/Composer se ejecutaron en una copia nueva Etapa 9A, sin `.env` ni red, con paquetes del lock montados de solo lectura y metadatos propios. Persisten avisos previos de Composer por clases Flysystem/Pint y deprecaciones Sass/Bootstrap; no son fallos nuevos. Manifiestos y locks permanecen intactos.

La revisión Chromium comprende **32 comprobaciones**: 12 estados en escritorio 1440×1000 y móvil 390×844, cuatro estados con JavaScript desactivado, tres cambios del selector mediante envío GET nativo sin JavaScript y menú móvil por teclado. Sin errores JavaScript, peticiones externas ni desbordamiento horizontal. Se revisaron panel con actividad en los tres periodos, perfil ausente, estado vacío, reseña retirada, servicios no públicos, texto largo/hostil, filtro inválido, cliente/admin sin acceso y fallo temporal.

Las respuestas HTML se generaron con Laravel y fixtures sintéticos nuevos en memoria, y Chromium revisó esos HTML servidos localmente. No fue una sesión sobre una base persistente; PHPUnit verificó las operaciones y permisos del backend. Windows/Laragon queda pendiente de ejecución independiente.

Evidencias:

- [Resultados exactos](validaciones/etapa-9a/resultados.json).
- [Auditoría npm](validaciones/etapa-9a/npm-audit.json).
- [Informe visual](validaciones/etapa-9a/visual.json).
- [Captura de escritorio](validaciones/etapa-9a/dashboard-escritorio.jpg).
- [Captura móvil](validaciones/etapa-9a/dashboard-movil.jpg).

## Windows / Laragon / PowerShell

Usar exclusivamente **`C:\laragon\www\tekora-etapa9a`**, puerto **8012**. No reutilizar clones anteriores. PHP 8.2+ con PDO SQLite, Composer y Node 22/24 compatibles con el lock.

### 1. Clon nuevo; dependencias, build, pruebas en memoria y rutas

No copiar `.env` ni ninguna SQLite en esta fase. No regenerar claves.

```powershell
Set-Location C:\laragon\www
if (Test-Path -LiteralPath 'C:\laragon\www\tekora-etapa9a') {
    throw 'La carpeta ya existe. No sobrescribir ni reutilizar clones anteriores.'
}
git clone --branch codex/etapa-9a-dashboard-prestador --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa9a
if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear el clon nuevo.' }
Set-Location C:\laragon\www\tekora-etapa9a
git rev-parse HEAD
php --version
php --ini
php -m
composer install
if ($LASTEXITCODE -ne 0) { throw 'Falló Composer.' }
npm.cmd ci
if ($LASTEXITCODE -ne 0) { throw 'Falló npm ci.' }
npm.cmd audit
if ($LASTEXITCODE -ne 0) { throw 'Revisar la auditoría antes de continuar.' }
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación.' }
php .\vendor\bin\phpunit --do-not-cache-result
if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas.' }
php artisan route:list --except-vendor
if ($LASTEXITCODE -ne 0) { throw 'Falló la revisión de rutas.' }
```

Comparar el SHA con el entregado en el PR. Esperado: **375 pruebas / 4633 aserciones**, auditoría npm limpia, build correcto y 99 rutas. El bootstrap de pruebas exige SQLite `:memory:` y una clave efímera del proceso; no necesita claves de una instalación ni copia SQLite persistente.

**Detenerse para revisión independiente de estos resultados.** No preparar la base de prueba antes de esa revisión.

### 2. Solo después de la revisión independiente: copia SQLite TEMP exclusiva

Usar un respaldo **consistente de prueba** de la SQLite temporal validada en Etapa 8, con **36 migraciones** y cuentas de desarrollo. Detener su aplicación o preparar un respaldo SQLite consistente que considere WAL; no copiar parcialmente una base activa. No tocar el origen. Conservar su APP_KEY de desarrollo.

El siguiente bloque crea una copia nueva identificada por GUID en TEMP. No escribe `.env`, no aplica migraciones y no crea perfiles ni datos de prueba automáticamente.

```powershell
Set-Location C:\laragon\www\tekora-etapa9a
$Origen8 = Read-Host 'Ruta del respaldo consistente de prueba de SQLite Etapa 8 (36 migraciones)'
if (-not (Test-Path -LiteralPath $Origen8 -PathType Leaf)) { throw 'No existe el respaldo.' }
$Base9A = Join-Path ([IO.Path]::GetTempPath()) ('tekora-9a-' + [guid]::NewGuid().ToString() + '.sqlite')
if (Test-Path -LiteralPath $Base9A) { throw 'No sobrescribir archivos temporales existentes.' }
Copy-Item -LiteralPath $Origen8 -Destination $Base9A
$Clave8 = Read-Host 'APP_KEY existente de esa copia Etapa 8 (no generar otra)' -AsSecureString
$env:APP_KEY = [Net.NetworkCredential]::new('', $Clave8).Password
Remove-Variable Clave8
if ([string]::IsNullOrWhiteSpace($env:APP_KEY)) { throw 'Falta la clave existente.' }
$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8012'
$env:APP_CONFIG_CACHE = Join-Path ([IO.Path]::GetTempPath()) ('tekora-9a-config-' + [guid]::NewGuid().ToString() + '.php')
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $Base9A
$env:DB_FOREIGN_KEYS = 'true'
$env:DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:SESSION_COOKIE = 'tekora_etapa9a_session'
$env:SESSION_DOMAIN = ''
$env:SESSION_SECURE_COOKIE = 'false'
$env:MAIL_MAILER = 'array'
$Revisor9A = Join-Path ([IO.Path]::GetTempPath()) ('tekora-9a-check-' + [guid]::NewGuid().ToString() + '.php')
if (Test-Path -LiteralPath $Revisor9A) { throw 'No sobrescribir un revisor existente.' }
$Codigo9A = @'
<?php
$pdo = new PDO('sqlite:'.getenv('DB_DATABASE'));
$aplicadas = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
$esperadas = array_map(fn ($p) => basename($p, '.php'), glob('database/migrations/*.php'));
sort($esperadas);
if (count($aplicadas) !== 36 || $aplicadas !== $esperadas) {
    fwrite(STDERR, 'La copia no corresponde a las 36 migraciones de Etapa 8.'); exit(1);
}
if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== [] || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    fwrite(STDERR, 'La copia no pasa la revisión de integridad.'); exit(1);
}
echo 'Copia Etapa 8 verificada: 36 migraciones e integridad correctas.';
'@
[IO.File]::WriteAllText($Revisor9A, $Codigo9A, [Text.UTF8Encoding]::new($false))
php $Revisor9A
$Revision9A = $LASTEXITCODE
Remove-Item -LiteralPath $Revisor9A
if ($Revision9A -ne 0) { throw 'Detenerse y revisar la copia de origen.' }
Write-Host "Base exclusiva de revisión: $Base9A"
php artisan migrate:status
if ($LASTEXITCODE -ne 0) { throw 'Falló la consulta del estado.' }
php artisan serve --host=127.0.0.1 --port=8012
```

Todas las 36 migraciones deben aparecer aplicadas; **no hay migración de Etapa 9A que ejecutar**. Si falta alguna o la copia no coincide, detenerse y revisar el origen. No ejecutar `migrate`, `migrate:fresh`, `db:wipe`, rollback destructivo ni seeds generales sobre bases persistentes.

Abrir `http://127.0.0.1:8012`. Usar el servidor iniciado en esa PowerShell, no un Apache de Laragon que no herede estas variables. Al terminar, detenerlo y cerrar la terminal para descartar configuración y clave de sesión. No se modificaron `.env`, claves, SQLite persistentes ni clones anteriores durante esta entrega.

### Recorrido manual recomendado

1. Acceder con prestador central: verificar acceso desde Mi cuenta y navegación; `/cuenta/prestador` sigue mostrando configuración del perfil.
2. Comprobar nombre público, diez tarjetas, seis etiquetas de estado actual y cuatro del periodo, así como las explicaciones.
3. Cambiar 7/30/90 con «Aplicar»; cotejar altas y decisiones con las fechas de los historiales y conservar iguales los indicadores actuales si los datos no cambiaron.
4. Verificar «Sin decisiones», «Sin reservas finalizadas» y «Sin calificaciones» con una cuenta de prueba sin esos datos.
5. Comprobar aceptación de una solicitud después completada; distinguir aceptación antigua de finalización reciente.
6. Cotejar eventos reales de reservas completadas y canceladas, incluidos cierres automáticos. Un rechazo de propuesta no entra en la tasa de cancelación.
7. Comprobar futuras estrictas: una propuesta que comienza ahora o ya empezó no está por confirmar; una confirmada iniciada no pertenece a próximas reservas.
8. Cotejar el promedio público con las reseñas visibles; retiradas y servicios no públicos no participan. El panel no muestra comentarios ni estrellas individuales privadas.
9. Con cuenta dual, distinguir el total global de mensajes del menú y el KPI exclusivo de prestador; abrir el panel no mueve marcadores ni avisos leídos.
10. Verificar agenda ordenada y límite de cinco; pendientes más antiguas y límite de cinco; los enlaces «Ver todas» abren los listados existentes.
11. Probar cliente y administrador sin rol prestador: 403. Un segundo prestador ve únicamente sus propios indicadores. `?provider_id=...` o `?period[]=7` deben producir 422.
12. Comprobar prestador sin perfil: instrucciones y ningún alta automática. Prestador sin actividad: diez indicadores y estados vacíos.
13. Revisar escritorio, móvil, menú por teclado y JavaScript desactivado; filtro nativo, gráfica y tabla por día deben funcionar sin scripts.
14. Cotejar reservas, solicitudes, mensajes, marcadores, reseñas y notificaciones antes/después de abrir el panel; no cambian por esa consulta.

## Limitaciones y pendientes

Los indicadores describen datos operativos existentes, no pagos, facturación, ingresos, duración efectiva del trabajo ni verificaciones profesionales adicionales. El conteo de servicios completados representa transiciones de reserva, no solicitudes ni servicios únicos del catálogo.

La agenda se presenta en Ciudad de México, indicada expresamente, aunque la reserva se hubiera creado en otra zona; el detalle existente conserva sus datos civiles originales. Solo incluye confirmadas futuras, y las propuestas por confirmar se resumen por separado.

Los agregados no usan caché. Historiales grandes requieren medir planes y latencias reales antes de proponer índices; la prueba demuestra número de consultas constante y proyecciones acotadas, no una cota fija de tiempo.

Esta etapa conserva SQLite y todos sus triggers de integridad. No se cambiaron marcadores ni workflows para el dashboard. Las capturas y los casos automáticos son datos sintéticos en memoria; Windows/Laragon y la integración en main quedan pendientes de revisión y pruebas independientes. No se fusionó ni desplegó.
