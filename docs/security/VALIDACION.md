# Validación del PR de seguridad Composer — noviembre de 2026

Repositorio: `Fernando261/tekora-project`. Rama: `codex/seguridad-composer-noviembre-2026`. Base actualizada y verificada: **`f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6`**, merge de Etapa 9A. Se creó un worktree separado en `/workspace/tekora-security`, sin cambiar la rama del PR #15.

La implementación de dependencias y pruebas está en los commits `a8b807a652a6fc19ea87d2b739326a4e0ae06d26` y `389f329a1892841a6e58a14564dcdbcabaaf1dc2`. El SHA final, que incluye esta documentación, se entrega en el PR y se comprueba con `git rev-parse HEAD` (no se incrusta un SHA autorreferente).

## Resultado y límites

- Laravel **12.71.0**, dentro de 12.x; corregido `CVE-2026-114509` y los demás avisos aplicables de Laravel.
- Auditoría completa: **67 → 0** avisos. Auditoría sin desarrollo: **61 → 0**. Cero paquetes abandonados reportados por estas consultas.
- Google API Client **2.20.1**, Google Auth **1.55.3** y JWT **7.2.1**, seleccionados como grupo compatible después de ensayar las APIs y contratos usados. No se fuerza JWT a través de restricciones incompatibles.
- **31 paquetes actualizados y 2 polyfills transitivos incorporados**. Sin nuevas dependencias directas, tablas o cambios de código de negocio.
- **PHP 8.2.28 real** para los requisitos y la suite final; PHP 8.2.35 como comprobación adicional. Composer **2.8.11**, PHPUnit **11.5.57**, Node **24.19.0**, npm **11.9.0**.
- La suite de la rama pasa **382 pruebas / 4683 aserciones**. Son las 375 anteriores y siete regresiones nuevas de Google, criptografía y PDF.
- La validación Windows/Laragon es una guía independiente; no se afirma haber ejecutado Windows. No hubo OAuth con cuentas Google reales: los proveedores/SDK son reales y su transporte HTTP está simulado.

El ensayo con PR #15 pasó **403 pruebas / 5165 aserciones**. Al excluir únicamente las siete nuevas del grupo `composer-security`, se reprodujeron exactamente **396 pruebas / 5115 aserciones** de 9B, código 0 en ambos casos. Las ocho operaciones API coinciden con OpenAPI.

Documentos relacionados: [auditoría por paquete y por aviso](AUDITORIA.md), [bloqueo Google y solución](DEPENDENCIAS-BLOQUEADAS.md), [ensayo con PR #15](COMPATIBILIDAD-PR15.md), [guía independiente Laragon](LARAGON.md).

## Comprobaciones iniciales de main

Con Composer 2.8.11 y el runtime PHP 8.2.35 preexistente, antes de actualizar paquetes:

| Comando | Código | Resultado inicial |
| --- | --- | --- |
| `git fetch origin main` y `git merge-base --is-ancestor f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6 origin/main` | 0 | Main apunta a ese mismo SHA |
| `composer validate` | 0 | Aviso por restricción exacta Google 2.15 |
| `composer validate --strict` | 1 | Ese aviso se trata como fallo estricto; no se ocultó |
| `composer install --no-scripts --no-interaction` | 0 | Instalación aislada del lock original |
| `composer check-platform-reqs` | 0 | Requisitos satisfechos |
| `composer audit --locked --format=json` | 1 | 67 avisos / 18 paquetes |
| `composer audit --locked --no-dev --format=json` | 1 | 61 avisos / 16 paquetes |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **375 pruebas / 4633 aserciones**, 34,227 s informados por PHPUnit |
| `npm ci` | 0 | Dependencias desde package-lock |
| `npm audit --json` | 0 | Cero vulnerabilidades |
| `npm run build` | 0 | Build correcto, advertencias Sass heredadas |
| `php artisan route:list --except-vendor --json` | 0 | **97 rutas** observadas en este entorno |

## Actualización por grupos

Se mantuvieron `--with-all-dependencies`, `--minimal-changes`, `--no-scripts` y `--no-interaction`. Las restricciones con versión de las dependencias transitivas son temporales del comando de actualización, no dependencias directas nuevas en el manifiesto. Los scripts de creación de proyecto, publicación de assets y migraciones no se ejecutaron.

1. **Laravel y sus dependencias HTTP relacionadas**; código 0, 48 avisos restantes. Con `--minimal-changes`, los comodines conservaron varias versiones transitivas existentes. Se revisó el resultado y se aplicaron mínimos explícitos en el grupo siguiente.

```text
composer update laravel/framework 'symfony/*' 'guzzlehttp/*' league/commonmark 'league/flysystem*' --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

2. **Symfony, transporte y almacenamiento con mínimos corregidos**; código 0, 19 avisos restantes.

```text
composer update 'symfony/http-foundation:^7.4.23' 'symfony/http-kernel:^7.4.23' 'symfony/routing:^7.4.23' 'symfony/mailer:^7.4.12' 'symfony/mime:^7.4.22' 'symfony/process:^7.4.5' 'symfony/polyfill-intl-idn:^1.38.1' 'guzzlehttp/guzzle:^7.15.2' 'guzzlehttp/psr7:^2.12.3' 'league/flysystem:^3.35.3' --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

3. **PDF, criptografía y consola**; código 0, 7 avisos restantes.

```text
composer update 'dompdf/dompdf:^3.1.6' 'phpseclib/phpseclib:^3.0.57' 'psy/psysh:^0.12.19' --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

4. **Herramientas de prueba**; código 0, 1 aviso restante (JWT bloqueado por Google 2.15).

```text
composer update 'phpunit/phpunit:^11.5.50' 'symfony/yaml:^7.4.23' --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

5. **Google/JWT compatible**, primero en worktree descartable después de consultar los metadatos de Google 2.20.1; código 0, cero avisos. Se modificó `google/apiclient` de `2.15` a `^2.20.1` y se ejecutó:

```text
composer update google/apiclient firebase/php-jwt --with-all-dependencies --minimal-changes --no-scripts --no-interaction
```

Este último grupo cambió únicamente Google API Client, Google Auth y JWT respecto del grupo 4. El candidato pasó **381 pruebas / 4680 aserciones en PHP 8.2.28** antes de incorporarlo a la rama. Después se añadió la séptima regresión, que exige rechazar una clave RSA de 1024 bits aunque la firma sea válida. No se desactiva esa protección para acomodar JWT 7.

## Comprobaciones finales

Los siguientes comandos se ejecutaron explícitamente con el contenedor PHP **8.2.28** y Composer **2.8.11**. En cloud, `php` corresponde a `/workspace/.tekora-security-tools/php8228`, que conserva CA/proxy del entorno. El contenedor agrega solamente `unzip` como herramienta de extracción; no agrega una dependencia al repositorio.

| Comando | Código | Resultado final |
| --- | --- | --- |
| `composer validate` | 0 | Sin aviso por versión exacta de Google |
| `composer validate --strict` | 0 | Validación estricta correcta |
| `composer install --no-scripts --no-interaction` | 0 | Lockfile reproducible; vendor al día tras instalar los tres paquetes Google actualizados |
| `composer check-platform-reqs` | 0 | Requisitos satisfechos con PHP **8.2.28 real** |
| `composer audit --locked --format=json` | 0 | **0 avisos**, 0 abandonados |
| `composer audit --locked --no-dev --format=json` | 0 | **0 avisos**, 0 abandonados |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **382 pruebas / 4683 aserciones**, 34,478 s informados por PHPUnit |
| `npm ci` | 0 | Lock frontend sin cambios |
| `npm audit --json` | 0 | **0 vulnerabilidades** |
| `npm run build` | 0 | Vite 6.4.5, 116 módulos, 6,64 s; advertencias Sass heredadas |
| `php artisan route:list --except-vendor --json` | 0 | 97 rutas; dominio, método, URI, nombre, acción y middleware idénticos a main |
| `php vendor/bin/pint tests/Feature/ComposerDependencyCompatibilityTest.php` | 0 | Archivo conforme al estilo |
| `git diff --check` | 0 | Sin errores de espacios |

La ejecución adicional de la misma suite final con PHP **8.2.35** también pasó **382 pruebas / 4683 aserciones**. La diferencia de `route:list` es el nuevo metadato `path` de closures añadido por Laravel; no es un cambio de rutas o autorización.

## Cobertura y dificultades observadas

Se conservaron todas las pruebas de autenticación, roles duales, policies, dashboard, solicitudes, mensajes, disponibilidad, reservas, reseñas, notificaciones y compatibilidad de guards de main. Las siete nuevas pruebas usan transporte Guzzle simulado en el SDK/proveedor reales y verifican:

- Renovación Google, preservación del refresh token, consulta FreeBusy y creación de evento, con Bearer en la petición efectiva.
- Rechazo de state incorrecto antes de enviar HTTP; callback correcto conservando refresh token cuando Google lo omite.
- Errores HTTP OAuth sin registrar el cuerpo sensible ni sustituir credenciales existentes.
- Firma RS256 válida de 2048 bits y rechazo de una firma alterada.
- Rechazo de una firma válida con RSA de 1024 bits.
- Descarga PDF real desde la ruta actual de tarjetas de emociones.

Las primeras pruebas nuevas detectaron errores en sus fixtures: el proveedor se había construido con la Request anterior al callback y el historial HTTP se tomaba antes del middleware de autorización. Se corrigieron los fixtures para resolver el proveedor al recibir la petición y registrar el transporte final. No se cambió código de aplicación para hacerlas pasar.

La imagen PHP 8.2.28 inicial no tenía ZIP/unzip y una instalación limpia falló al extraer dependencias. Se agregó `unzip` a una imagen descartable y la instalación limpia del candidato Google pasó. La instalación del ensayo 9B encontró además falta de espacio temporal; se retiraron artefactos descartables/cache VCS y se completó con una copia independiente del vendor ya validado, instalando únicamente Sanctum y regenerando su autoload en el worktree temporal. No se presenta ese último paso como instalación limpia desde cero. Una reconexión interrumpió una suite de integración: esa ejecución parcial no se contó como aprobada y se repitió completa.

## Integridad y alcance

- Las **36 migraciones** de main permanecen byte por byte sin cambios; el PR de seguridad no agrega migraciones ni tablas.
- `git diff` contra el SHA base es vacío para `app`, `routes`, `config`, `database/migrations` y `.env.example`.
- Se compararon hashes antes/después de los dos archivos protegidos existentes (.env y SQLite) del worktree original: **sin cambios**, sin publicar contenidos o hashes. APP_KEY dentro de .env permanece intacta.
- El worktree de seguridad no creó `.env` ni SQLite persistentes. El bootstrap PHPUnit fuerza SQLite `:memory:` y se niega a migrar si pierde ese aislamiento.
- PR #15 y su rama conservan `07cb6dfa9de1916535999c6a9403cc5be948ae28`. No se publican merges o cambios del ensayo descartable.
- La rama entregada queda con estado Git limpio después del commit de documentación. Los cambios staged del worktree descartable pertenecen únicamente a su merge local, no al PR de seguridad ni al PR #15.

Una auditoría con cero avisos no certifica la seguridad completa del backend ni su preparación para producción. Permanecen la revisión independiente Laragon, la evaluación de configuración/despliegue y, si se decide realizarlo, un ensayo Google con una cuenta de validación autorizada. **No se fusiona ni despliega; el usuario decide la integración.**

## Lista completa de archivos modificados

**13 archivos:**

- `composer.json`
- `composer.lock`
- `docs/security/AUDITORIA.md`
- `docs/security/COMPATIBILIDAD-PR15.md`
- `docs/security/DEPENDENCIAS-BLOQUEADAS.md`
- `docs/security/LARAGON.md`
- `docs/security/VALIDACION.md`
- `docs/security/evidence/audit-final-no-dev.json`
- `docs/security/evidence/audit-final.json`
- `docs/security/evidence/audit-initial-no-dev.json`
- `docs/security/evidence/audit-initial.json`
- `docs/security/evidence/execution-summary.json`
- `tests/Feature/ComposerDependencyCompatibilityTest.php`
