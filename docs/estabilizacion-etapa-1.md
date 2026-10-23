# Etapa 1: estabilización técnica

## Alcance y decisiones

Se conservan Laravel 12, Blade, SQLite y las dos identidades. No se unifican tablas, cambian contraseñas existentes, eliminan datos o archivos, ni se implementan servicios de Tekora.

### Migraciones

Se revisaron las 24 migraciones. El cambio histórico está limitado a dos migraciones y está pensado para instalaciones donde aún estén pendientes:

- **Campos Google:** la migración inicial se conserva. La adición posterior comprueba cada columna y agrega solo las ausentes. Si ya están todas, no hace cambios de esquema ni de datos. Su `down()` es deliberadamente conservador: no borra columnas que pueden pertenecer a la migración inicial ni credenciales existentes.
- **Citas:** se conservan ambos pares de fechas y ambos estados. Las columnas nuevas de fechas se agregan nullable para admitir filas anteriores y se rellenan desde sus fechas originales. Un `status` nuevo se inicializa según `estado`: pendiente/pending, aceptada/scheduled, rechazada/rejected, cancelada/cancelled. Si las columnas ya existen, no se sobrescriben sus valores. `down()` conserva los datos, igual que la intención no destructiva del archivo anterior.
- Una base que ya tenga esas migraciones registradas **no las vuelve a ejecutar**. No se agregan migraciones de transformación de datos ni se fuerza su repetición. No borrar filas de la tabla `migrations` para ejecutarlas de nuevo.
- La factory incluye edad y género, requeridos por el esquema. Los seeders se pueden repetir sin reemplazar una cuenta de igual email ni emociones de igual slug. No ejecutarlos sobre datos reales como parte de esta actualización.

Modificar estas dos migraciones corrige instalaciones nuevas y actualizaciones pendientes sin exigir una migración nueva para bases que ya tienen las columnas. Se mantienen nombres, IDs y relaciones. La suite cubre un esquema Google parcial y citas anteriores con los cuatro estados.

**Límites:** no se reconcilian columnas preexistentes contradictorias. Si una instalación ya tiene fechas/estados divergentes, las alternativas son conservarlas mientras se investiga, o preparar después una migración aditiva con reglas de precedencia y un informe previo de filas afectadas. Requiere decidir qué fuente es válida; esta entrega no toma esa decisión ni elimina columnas. MySQL y fallos intermedios de DDL en ese motor siguen pendientes de validación.

### Autenticación

- Login y logout de usuario usan explícitamente `web`; las rutas de usuario exigen `auth:web`. El profesional conserva `profesionista`.
- Rate limiter nativo: máximo 5 solicitudes de login/minuto por endpoint + email normalizado + IP, y 20/minuto por IP entre ambos endpoints. Cuenta también solicitudes exitosas o inválidas. Al excederlo devuelve HTTP 429 con `Retry-After`; la ventana se libera después de un minuto. La caché configurada debe estar disponible y, en un despliegue con varios servidores, compartida.
- El reset apunta a la vista `auth.reset-password`. La prueba de recuperación usa notificaciones simuladas y verifica el consumo de token y el hash nuevo solo en una cuenta temporal.
- Se corrige la validación de cédula duplicada, el parámetro `role=prof` y la confirmación visible de registro/restablecimiento. El GET del login profesional redirige al panel profesional que ya existe en la vista compartida; su POST se conserva.
- No se agregó recuperación para profesionistas ni se cambió la política general de contraseñas. El logout conserva la invalidación completa de sesión; no se promete mantener otro guard abierto en el mismo navegador.

### Rutas y navegación

- Se importa el modelo `Emotion`.
- `/consultas` redirige al listado real `/citas`, conservando la protección de usuario.
- `/reuniones` queda sin ruta y responde 404; se retiran sus accesos y los de `/notificaciones`, que nunca tuvo módulo.
- Se retiran enlaces inertes de ajustes, favoritos y preferencias; el perfil de usuario deja de ofrecerse desde el menú profesional. No se crean pantallas ficticias.
- El dashboard usa el logout profesional y “Ver mis citas” apunta a su listado correcto.

### Tokens y configuración

- Access y refresh tokens Google se excluyen de `toArray()` y JSON del modelo. Siguen disponibles internamente para la integración existente.
- Los errores del flujo Calendar registran clase/código y referencias de contexto, no mensajes arbitrarios del proveedor o excepciones SQL que puedan contener credenciales.
- El callback OAuth controla errores con mensaje genérico y mantiene la validación de `state` de Socialite. No se usa `stateless()`.
- No se cambian scopes, renovación de tokens, eventos o reglas de aceptación. No se aplica cifrado masivo a tokens existentes: queda como trabajo posterior con estrategia de transición y conservación de claves.
- Se corrige el fallback `smtp` mal escrito. No se alteran archivos locales de entorno.

## Descargar y revisar en Windows sin mezclar cambios locales

La opción más segura es el clon independiente documentado en el README. Conserva intacta la carpeta donde ya trabajas.

Para revisar desde un repositorio existente, sin cambiar su rama ni sus archivos:

```powershell
Set-Location C:\ruta\a\tu\tekora-project
git status --short
git fetch origin
git log --oneline HEAD..origin/codex/etapa-1-estabilizacion
git diff --stat HEAD...origin/codex/etapa-1-estabilizacion
git diff HEAD...origin/codex/etapa-1-estabilizacion
```

Si tienes cambios locales, no uses `reset --hard`, `clean`, sobrescrituras o un cambio forzado de rama. Guarda tu trabajo en una rama propia con commits selectivos o respáldalo fuera del repositorio; recuerda que un diff no incluye archivos sin seguimiento. No agregues `.env` ni documentos privados a Git. La revisión mediante `fetch` y `diff` no integra automáticamente los cambios.

Otra opción para probar sin tocar la carpeta actual:

```powershell
git worktree add --detach ..\tekora-etapa1-review origin/codex/etapa-1-estabilizacion
Set-Location ..\tekora-etapa1-review
```

Ese worktree es solo de revisión y no contiene tu `.env`, dependencias ignoradas ni base local. Sigue los pasos de instalación nueva del README. Para integrar luego, usa una rama propia y resuelve explícitamente los conflictos; no se hizo merge a `main` en esta entrega.

## Antes de migrar una base existente

1. Detener servidor y workers para impedir escrituras concurrentes. Verificar qué base selecciona la configuración, incluidas variables del sistema y una posible caché.
2. Respaldar la base SQLite, `.env` y archivos subidos (`storage/app/public` y `public/uploads`) en un destino privado fuera del repositorio. Conservar `APP_KEY`. Si SQLite usa WAL, emplear su mecanismo de backup consistente; no copiar únicamente el archivo principal mientras haya escrituras o un WAL pendiente.
3. Comprobar que el respaldo puede restaurarse en una carpeta aislada. Ensayar primero allí con una copia de los datos.
4. Si hay configuración/rutas/vistas cacheadas, limpiar solo esos artefactos generados:

```powershell
php artisan config:clear
php artisan route:clear
php artisan view:clear
composer install
npm ci
php .\vendor\bin\phpunit --do-not-cache-result
php artisan migrate:status
php artisan migrate --pretend
```

5. Revisar las migraciones pendientes y su SQL. `--pretend` es una ayuda, no una garantía de compatibilidad. Si las 24 ya están aplicadas, estas correcciones históricas no transformarán los datos existentes.
6. Solo después de validar el respaldo y el ensayo, aplicar avance normal y verificar:

```powershell
php artisan migrate
php artisan migrate:status
npm run build
php artisan route:list --except-vendor
```

**No ejecutar `migrate:fresh`, `db:wipe`, `migrate:reset`, rollback, borrado manual de tablas ni `key:generate` sobre la instalación existente.** Tampoco ejecutar seeders de demostración como parte de su actualización. Si una migración falla, detenerse y revisar el estado con el respaldo; no intentar resolverlo eliminando datos o marcas del historial.

## Pruebas y límites operativos

La suite usa PHPUnit con un bootstrap que fuerza SQLite `:memory:`, sin `DB_URL`, y omite cualquier caché de configuración real mediante una ruta temporal única. Antes de migrar, `Tests\TestCase` comprueba el aislamiento y falla si la conexión no es la esperada. Solo ejecuta `migrate` sobre memoria; no utiliza traits que reinicien bases persistentes. La clave de pruebas es efímera y no se guarda.

Las verificaciones incluyen registro, guards independientes, contraseñas incorrectas, límites por identidad/IP, recuperación, logout, páginas protegidas, navegación, detalle emocional, las 24 migraciones, actualización de citas con datos antiguos, seeders repetibles y exclusión de tokens. Google y correo se simulan; no se contactan servicios reales.

Pendientes de validación manual:

- Windows/PowerShell, enlaces simbólicos y presentación en navegador.
- Base real del usuario y una copia de sus datos; no fue consultada ni migrada.
- MySQL y su comportamiento de DDL.
- Entrega SMTP y OAuth/Calendar reales.
- Imágenes emocionales ausentes.

Pendientes de otra etapa: unicidad de horarios rechazados, concurrencia de reservas, validación completa de disponibilidad, doble representación de fechas/estados, expiración Google, cifrado de tokens y recuperación del profesional. Se mantienen para evitar ampliar esta estabilización a cambios de negocio o transformaciones no aprobadas.

## Resultados ejecutados en esta entrega

Validación en copia temporal, sin `.env`, sin la base existente ni archivos subidos. PHP 8.2.35 / PHPUnit 11.5.24 en contenedor con red deshabilitada; Node 24.19.0 / npm 11.9.0 para compilación.

| Comprobación | Resultado |
| --- | --- |
| PHPUnit completo | 33 pruebas, 333 aserciones: correcto. |
| Aislamiento con variables externas de producción/MySQL deliberadamente incorrectas | Correcto; la suite siguió usando SQLite en memoria. |
| Las 24 migraciones en una base vacía y segunda ejecución de `migrate` | Correcto. |
| Upgrade simulado de Google parcial y citas antiguas con los cuatro estados | Correcto; valores anteriores conservados. |
| Factories y seeders repetidos | Correcto, sin sobrescribir filas existentes. |
| Registro de rutas (`route:list --except-vendor`) | Correcto. |
| Compilación Blade (`view:cache`) | Correcto, solo en la copia temporal. |
| Sintaxis PHP y Blade compilado | 130 archivos comprobados, sin errores de sintaxis. |
| `npm run build` | Correcto; advertencias existentes de obsolescencia Sass/Bootstrap. |
| `composer validate --no-check-publish` | Válido; advertencia por la restricción exacta de Google API Client. |
| `composer check-platform-reqs` | Correcto para PHP 8.2.35 del contenedor. |
| `git diff --check` | Correcto. |

Se verificó por hash que `.env` y `database/database.sqlite` conservan su contenido. No se ejecutaron migraciones ni seeders sobre esa base, no se modificaron cuentas reales ni archivos subidos. No se instalaron ni actualizaron dependencias. No se comprobó una descarga nueva de dependencias desde sus proveedores.

## Archivos de esta entrega

Las plantillas reciben ajustes puntuales de navegación o mensajes; no se rediseñan. La lista incluye archivos modificados y añadidos, y excluye los textos adjuntos al chat:

- `README.md`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/CitasController.php`
- `app/Http/Controllers/ProfesionistaAuthController.php`
- `app/Http/Controllers/ProfesionistaCitaController.php`
- `app/Http/Controllers/ProfesionistaController.php`
- `app/Http/Controllers/ProfesionistaGoogleController.php`
- `app/Http/Controllers/ResetPasswordController.php`
- `app/Models/Profesionista.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `config/mail.php`
- `database/factories/UserFactory.php`
- `database/migrations/2026_02_20_224556_add_google_fields_to_profesionistas_table.php`
- `database/migrations/2026_02_21_071030_add_start_end_to_citas_table.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/EmotionSeeder.php`
- `docs/estabilizacion-etapa-1.md`
- `phpunit.xml`
- `resources/views/auth/registro.blade.php`
- `resources/views/citas.blade.php`
- `resources/views/citas/create.blade.php`
- `resources/views/citas/mis-citas.blade.php`
- `resources/views/comunidad.blade.php`
- `resources/views/conferencias.blade.php`
- `resources/views/dashboard-profesionista.blade.php`
- `resources/views/editar-nombre.blade.php`
- `resources/views/home.blade.php`
- `resources/views/login.blade.php`
- `resources/views/perfil.blade.php`
- `resources/views/profesionistas/disponibilidad.blade.php`
- `resources/views/profesionistas/index.blade.php`
- `resources/views/profesionistas/solicitudes-citas.blade.php`
- `routes/web.php`
- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/CitasRegressionTest.php`
- `tests/Feature/ExampleTest.php`
- `tests/Feature/InstallationTest.php`
- `tests/Feature/RoutesAndGoogleSafetyTest.php`
- `tests/TestCase.php`
- `tests/bootstrap.php`
