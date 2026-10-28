# Tekora — Etapa 2B: registro, perfiles y navegación

## Base y alcance

La rama `codex/etapa-2b-registro-perfiles` parte de `main` en `55a43291bf172d34ffe58d4583e66340dc92451e`, que integra la Etapa 2A mediante el PR #2. Se confirmó su integración en GitHub y en el historial de `origin/main`. Antes de los cambios pasaron las 57 pruebas y 492 aserciones existentes.

Esta entrega conecta la arquitectura de identidades con la web utilizando Laravel 12, Blade, CSS y JavaScript. Conserva la marca, colores y recursos de MindBloom. No agrega dependencias, frameworks frontend, migraciones ni cambios en los modelos, servicios de roles, policies o configuración de guards de la Etapa 2A.

No implementa catálogo de servicios, contrataciones, pagos, verificaciones profesionales ni administración completa. No se ejecutaron operaciones sobre bases reales ni se modificó `.env`.

## Funcionalidades

### Una sola cuenta para el registro nuevo

`/registro` permite elegir contratar, ofrecer servicios o ambas opciones. Las tres crean una sola identidad `User`, con contraseña cifrada y rol `client`. Ofrecer servicios o ambas agregan `provider` en la misma transacción mediante `RoleMemberships::enroll()`. No se crea un `Profesionista`, se copia una cuenta existente ni se concede `admin`.

El formulario conserva nombre, edad y género porque el esquema actual los requiere. La intención es opcional en el backend para preservar las solicitudes de registro existentes: omitirla mantiene el alta de cliente. La confirmación de contraseña es obligatoria en el formulario y se valida si está presente en la solicitud; no se exige a integraciones anteriores que no la enviaban. No se cambian contraseñas existentes.

El login central dirige al prestador a `/cuenta/prestador`, al cliente a `/cuenta` y conserva el destino solicitado originalmente mediante la sesión. Los usuarios históricos sin roles mantienen su destino anterior `/` y pueden visitar `/cuenta` para activar voluntariamente sus experiencias. No se asignan roles por iniciar sesión.

### Activación y perfil profesional

Una cuenta autenticada puede activar `provider` desde `/cuenta/activar-prestador`. El formulario reutiliza `POST /cuenta/roles`: la operación es idempotente, conserva las asignaciones actuales y solo actúa sobre el usuario del guard `web`. Una cuenta que ya tiene `client` lo conserva. Los usuarios históricos sin ese rol pueden activar Cliente desde `/cuenta`.

En `/cuenta/prestador` se crea o edita el perfil propio con `display_name` (obligatorio, hasta 255 caracteres), `headline` (opcional, hasta 160) y `bio` (opcional, hasta 5000). El resumen muestra la información guardada y enlaza a su consulta privada. Los errores conservan el texto introducido, las escrituras muestran confirmación y los envíos duplicados de creación orientan al perfil existente.

Se reutilizan `ProviderProfile`, `ProviderProfileRequest`, `ProviderProfilePolicy` y los controladores/endpoints de la Etapa 2A. Los formularios usan redirecciones 303 tras las escrituras; las solicitudes con `Accept: application/json` conservan las respuestas JSON anteriores, incluido el conflicto 409 al intentar crear un segundo perfil.

El propietario se obtiene de la sesión, nunca del formulario. Solo se escriben los tres campos permitidos. Los Gates y la policy comprueban el rol y la propiedad en Laravel; un administrador autorizado puede consultar un perfil ajeno, pero no editarlo. Los textos se escapan mediante Blade.

La activación **no es una verificación profesional**. El perfil central todavía no se publica en el directorio anterior de MindBloom ni recibe sus citas. Ambas limitaciones se muestran en las pantallas.

### Navegación por permisos

Un componente compartido presenta Mi cuenta, Cliente, Prestador y Administración según los Gates existentes. Una cuenta con `client` y `provider` cambia de experiencia mediante enlaces, sin cerrar sesión ni crear otra identidad. Las pantallas anteriores de usuario también utilizan esta navegación y agrupan sus enlaces bajo “MindBloom anterior”.

La única pantalla administrativa añadida es la consulta de roles del endpoint existente; solo se muestra a cuentas con permiso y la ruta sigue protegida en el servidor. No existe interfaz para conceder `admin`. Ser administrador no concede implícitamente los otros roles.

Los componentes comunes incluyen layouts de acceso/cuenta, navegación, mensajes y aviso de cuenta antigua. El menú de cuenta se adapta a móvil y permite cerrarse con Escape. Se mantienen etiquetas de formulario, foco visible y estados de validación.

## Pantallas y rutas

Todas las rutas `/cuenta` requieren `auth:web`. Las escrituras utilizan la sesión y CSRF de Laravel; no se agregan excepciones a CSRF.

| Método y ruta | Pantalla o comportamiento | Protección adicional |
| --- | --- | --- |
| `GET /registro` | Registro único, intención cliente/prestador/ambas | Pública |
| `POST /registro` | Crea `User` y roles mediante el servicio existente | Validación y CSRF |
| `GET /login`, `POST /login` | Acceso central y sección de acceso anterior | Validación, CSRF y límite de intentos existente |
| `GET /cuenta` **nueva** | Centro de experiencias y activación de Cliente | Sesión central |
| `GET /cuenta/cliente` **nueva** | Espacio Cliente, enlace a sus citas MindBloom | `client.appointments.view` |
| `GET /cuenta/activar-prestador` **nueva** | Explicación y formulario de activación | Sesión central |
| `GET /cuenta/prestador` **nueva** | Crear/editar perfil y consultar resumen | `provider.profile.manage` y policy |
| `POST /cuenta/roles` | Activación propia; ahora también responde a formularios HTML | Solo `client`/`provider`, sin destino alternativo |
| `POST /perfil-profesional` | Crear perfil propio; ahora también responde a HTML | Rol, policy y campos permitidos |
| `GET /perfiles-profesionales/{providerProfile}` | Consulta privada Blade o JSON | Propietario prestador o administrador autorizado |
| `PUT /perfiles-profesionales/{providerProfile}` | Editar perfil propio; ahora también responde a HTML | Propietario prestador |
| `GET /administracion/roles` | Catálogo de consulta Blade o JSON | `accounts.roles.manage` |
| `GET /registro-profesionista` | Redirige a `/registro?intent=provider` | Pública |
| `POST /registro-profesionista` | Retirado: HTML redirige 303 con explicación; JSON devuelve 410 y URL central | CSRF; no escribe identidades |
| `GET /login-profesionista`, `POST /login-profesionista` | Acceso para cuentas profesionales anteriores | Guard y límite de intentos anteriores |

Las otras rutas de la Etapa 2A y MindBloom se conservan. `/cuenta/citas` reutiliza el listado propio anterior. Los enlaces antiguos `?role=prof` ya no pueden abrir un formulario que cree una segunda identidad profesional.

## Compatibilidad y migraciones

Los registros nuevos pasan exclusivamente por `User`. Se retira deliberadamente el alta de nuevos `Profesionista` tanto en la interfaz como en el servidor para evitar duplicaciones accidentales. Los clientes del antiguo POST deben actualizar su registro; el 410 JSON comunica ese cambio explícitamente.

Los `Profesionista` existentes conservan login, contraseña, guard, directorio, citas, disponibilidad, bloqueos y tokens. Sus pantallas muestran un aviso de acceso anterior. No se vinculan cuentas por email ni por ID coincidente. Dos cuentas con el mismo correo siguen siendo independientes; el formulario de acceso anterior está separado y etiquetado.

Los dos guards comparten la sesión del navegador con claves distintas. Se conserva el comportamiento existente de logout, que invalida la sesión completa. Cambiar de Cliente a Prestador central solo navega entre pantallas de `User`, sin cambiar de guard.

**No hay migraciones nuevas en 2B.** La base debe contar con las 26 migraciones hasta la Etapa 2A, incluidas `roles`, `role_user` y `provider_profiles`. En una instalación que ya las tenga aplicadas, esta entrega no requiere cambios de esquema. Se verificaron sin cambios los hashes de `.env`, `database/database.sqlite` y los 26 archivos de migración respecto del inicio del trabajo.

La estrategia de unificación antigua permanece como propuesta, sin ejecutar: [vinculación voluntaria, prueba de titularidad, mapa explícito y transición reversible](identidades-roles-etapa-2a.md#estrategia-posterior-para-cuentas-antiguas--propuesta-no-implementada). Requiere aprobar las decisiones de titularidad, conflictos y recuperación antes de vincular o mover referencias. Esta entrega no anticipa esas decisiones.

## Pruebas y resultados

Validación realizada con PHP 8.2.35 y PHPUnit 11.5.24 en una copia temporal sin `.env`, base persistente ni archivos de usuarios. El bootstrap fuerza SQLite `:memory:` y sesión, caché y correo de pruebas. Las migraciones se ejecutan exclusivamente en esa conexión aislada.

| Verificación | Resultado |
| --- | --- |
| Suite de partida de `main` | 57 pruebas, 492 aserciones correctas |
| Suite completa de esta entrega | **78 pruebas, 732 aserciones correctas** |
| Nuevas pruebas de experiencia web | 15 casos en `WebAccountsTest` |
| Nuevas pruebas con CSRF realmente activo | 6 casos en `WebCsrfTest` |
| Chromium: 6 pantallas renderizadas con fixtures en memoria, a 1440×1000 y 390×844 | 12 combinaciones correctas, sin desbordamiento horizontal ni errores JavaScript |
| Compilación Blade y análisis de sintaxis PHP | Correctos, 164 archivos incluidos los Blade compilados |
| `node --check public/js/account.js` | Correcto |
| `npm run build`, en la copia temporal | Correcto; conserva avisos de deprecación de Sass/Bootstrap |

No se eliminan las 57 pruebas anteriores. Se adaptan tres casos de `AuthenticationTest`/`RoutesAndGoogleSafetyTest` al retiro del registro antiguo: ahora comprueban redirección al registro central, ausencia de nuevas identidades antiguas y conservación de las cuentas existentes. La suite previa con esos ajustes contiene 57 pruebas y 493 aserciones. Mantener la expectativa de crear `Profesionista` sería incompatible con el registro central solicitado.

Los casos nuevos cubren las tres intenciones de registro, validación, un único usuario con roles múltiples, conservación de Cliente, activación repetida, recorrido de registro/login/activación/creación/edición/cambio de experiencia, acceso a perfiles ajenos, campos de propietario/privilegios prohibidos, contenido escapado, navegación por roles, administración restringida, cuentas sin roles y login heredado con tokens intactos. También comprueban que un correo compartido no provoca una fusión.

Laravel normalmente omite la comprobación CSRF durante pruebas. `tests/Support/EnforcedCsrf.php` la reactiva únicamente para los casos dedicados, conservando `APP_ENV=testing` y SQLite en memoria. Se prueban tokens ausentes/incorrectos, formularios con token, escrituras válidas, bloqueo de escalamiento aun con token válido y rotación de token tras login. Los rechazos 419 no escriben datos y muestran una pantalla de sesión vencida.

La revisión Chromium utiliza HTML Blade generado con fixtures en memoria y recursos locales. Comprueba también apertura del acceso heredado, menú móvil y Escape; no sustituye las pruebas funcionales HTTP ni una ejecución manual en Windows. Laragon/PowerShell se documenta para revisión local y no se ha ejecutado en esta máquina Linux.

## Probar con Laragon y PowerShell

### Copia nueva de revisión

Usar PHP 8.2 o superior compatible con `composer.lock`, Composer 2 y Node 22/24 LTS. Abrir la terminal de Laragon o añadir su PHP al PATH. No sustituir una carpeta con trabajo o datos existentes.

```powershell
Set-Location C:\laragon\www
git clone --branch codex/etapa-2b-registro-perfiles --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa2b
Set-Location .\tekora-etapa2b
where.exe php
php --version
php --ini
php -m | Select-String 'PDO|pdo_sqlite|sqlite3|mbstring|dom|xml|openssl'
composer install
npm.cmd ci

# Crear entorno y clave solo en esta copia nueva, si todavía no existe .env.
if (-not (Test-Path .\.env)) {
    Copy-Item .\.env.example .\.env
    php artisan key:generate
}

# Las pruebas fuerzan SQLite en memoria; no requieren migrar una base local.
php .\vendor\bin\phpunit --do-not-cache-result
```

Resultado esperado: **78 pruebas y 732 aserciones**. Habilitar `pdo_sqlite` y las demás extensiones del README en el `php.ini` que muestra la terminal. `npm.cmd` evita depender de la política de ejecución de scripts de PowerShell.

Para probar la interfaz, revisar el `.env` **de esta copia nueva**: `DB_CONNECTION=sqlite`, sin `DB_URL` que lo sustituya, y `DB_DATABASE` sin definir para usar el archivo predeterminado o con una ruta absoluta como `C:/laragon/www/tekora-etapa2b/database/database.sqlite`. La entrega solo valida SQLite.

El siguiente bloque es exclusivamente para una instalación nueva sin datos:

```powershell
if (Test-Path .\database\database.sqlite) {
    throw 'La base ya existe. No la borres: sigue el procedimiento de actualización.'
}
New-Item -ItemType File -Path .\database\database.sqlite | Out-Null
php artisan migrate
php artisan migrate:status
php artisan storage:link
npm.cmd run build
php artisan route:list --except-vendor
php artisan serve
```

`storage:link` puede necesitar modo de desarrollador de Windows o permisos para enlaces. Abrir la dirección indicada por `serve` y realizar este recorrido:

1. Abrir `/registro`, elegir contratar y crear una cuenta con confirmación de contraseña.
2. Iniciar sesión; desde `/cuenta`, activar Prestador. Comprobar que Cliente continúa disponible.
3. Guardar nombre público, titular y biografía. Consultar el perfil, editarlo y comprobar el resumen actualizado.
4. Cambiar entre Cliente y Prestador sin cerrar sesión. Comprobar que se conserva una sola cuenta.
5. Intentar abrir `/administracion/roles` con esa cuenta: debe devolver 403 y no aparecer el enlace administrativo.
6. En otra sesión o navegador, crear otra cuenta y comprobar que no puede leer ni editar el perfil ajeno.
7. Probar registro con intención prestador/ambas: debe iniciar con los dos roles. El enlace `/registro-profesionista` debe llevar al registro central.
8. Si se dispone de una copia aislada de cuentas anteriores, probar su acceso desde la sección “Cuenta profesional anterior de MindBloom” del login. No usar datos reales para estas comprobaciones ni esperar que el perfil central aparezca en el directorio antiguo.

No se proporciona un administrador predeterminado ni un mecanismo nuevo para conceder ese rol. La prueba administrativa positiva utiliza exclusivamente fixtures en memoria.

### Instalación existente

Antes de actualizar, revisar `git status` y respaldar código local, `.env`, archivos de usuarios y la base con un procedimiento consistente para SQLite (incluidos los cambios pendientes en WAL). Revisar el PR antes de desplegar; no integrarlo automáticamente. Mantener `APP_KEY` y credenciales existentes.

Comprobar `php artisan migrate:status` en el entorno de revisión elegido: si están aplicadas las 26 migraciones de 2A, no hay ninguna nueva en 2B. Si falta 2A, detener la actualización y seguir primero su guía de respaldo/migraciones. No ejecutar instalación nueva, seeds de demostración, `migrate:fresh`, `db:wipe`, `migrate:reset`, rollback o regeneración de clave sobre una instalación existente.

Después de una actualización autorizada y su respaldo, las cachés de código pueden refrescarse con `php artisan config:clear`, `php artisan route:clear` y `php artisan view:clear`. Las pruebas automatizadas siempre pueden ejecutarse por separado en memoria. No se requiere modificar datos para habilitar esta entrega.

## Problemas y decisiones pendientes

- Los perfiles centrales aún no tienen directorio, catálogo, categorías, servicios, ubicación, fotografías, portafolio, verificaciones, contrataciones o pagos.
- La vinculación entre `User` y `Profesionista` continúa pendiente de aprobación. Las citas y credenciales antiguas siguen referenciando su identidad original.
- La revocación de un rol de negocio no es una suspensión permanente: el alta propia sigue siendo voluntaria, como en 2A. Falta diseñar una política de suspensión.
- La edad/género y longitud mínima de contraseña mantienen el contrato heredado. Su evolución y la retirada de compatibilidad para confirmación ausente requieren una etapa específica.
- No se valida MySQL ni una ejecución local de Windows en esta entrega. El listado administrativo es solo de consulta; el primer administrador sigue requiriendo un procedimiento autorizado aparte.
- La compilación actual de Sass/Bootstrap emite avisos de deprecación; su actualización queda fuera de este cambio y no impide generar los recursos.

## Archivos modificados

El inventario de esta entrega se agrupa a continuación; no incluye archivos de datos, secretos, dependencias ni adjuntos del usuario.

### Documentación

- `README.md`
- `docs/registro-perfiles-etapa-2b.md`

### Backend y rutas

- `app/Http/Controllers/AccountController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/ProfesionistaAuthController.php`
- `app/Http/Controllers/ProfesionistaController.php`
- `app/Http/Controllers/ProviderProfileController.php`
- `app/Http/Controllers/RoleMembershipController.php`
- `app/Http/Requests/EnrollRoleRequest.php`
- `app/Http/Requests/ProviderProfileRequest.php`
- `app/Http/Requests/RegisterUserRequest.php`
- `routes/identities.php`
- `routes/web.php`

### Blade

- `resources/views/account/activate-provider.blade.php`
- `resources/views/account/client.blade.php`
- `resources/views/account/index.blade.php`
- `resources/views/account/provider-show.blade.php`
- `resources/views/account/provider.blade.php`
- `resources/views/account/roles.blade.php`
- `resources/views/auth/registro.blade.php`
- `resources/views/auth/registro_profesionista.blade.php`
- `resources/views/citas/create.blade.php`
- `resources/views/citas/mis-citas.blade.php`
- `resources/views/components/access-layout.blade.php`
- `resources/views/components/account-layout.blade.php`
- `resources/views/components/account-navigation.blade.php`
- `resources/views/components/flash-messages.blade.php`
- `resources/views/components/legacy-account-notice.blade.php`
- `resources/views/comunidad.blade.php`
- `resources/views/conferencias.blade.php`
- `resources/views/dashboard-profesionista.blade.php`
- `resources/views/editar-nombre.blade.php`
- `resources/views/errors/419.blade.php`
- `resources/views/home.blade.php`
- `resources/views/login.blade.php`
- `resources/views/perfil.blade.php`
- `resources/views/profesionistas/disponibilidad.blade.php`
- `resources/views/profesionistas/index.blade.php`
- `resources/views/profesionistas/solicitudes-citas.blade.php`

### CSS y JavaScript

- `public/css/account.css`
- `public/js/account.js`

### Pruebas

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/RoutesAndGoogleSafetyTest.php`
- `tests/Feature/WebAccountsTest.php`
- `tests/Feature/WebCsrfTest.php`
- `tests/Support/EnforcedCsrf.php`
