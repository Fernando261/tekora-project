# Validación W2

La iteración actual se documenta en [W21.md](W21.md); esta página conserva los resultados históricos W2.

Ejecutada el **6 de noviembre de 2026**, con PHP **8.2.28**, Composer **2.8.11**, Node **24.19.0** y npm **11.9.0**. PHP corre en un contenedor aislado; PHPUnit y los exportadores usan el bootstrap existente de SQLite `:memory:`, sesión/cache en memoria y clave efímera del proceso. No se cambia la configuración persistente. Las auditorías se ejecutaron con red disponible.

| Comando | Salida | Resultado |
| --- | --- | --- |
| `composer validate --strict` | [log](evidence/composer-validate.txt) | 0, válido |
| `composer install --no-scripts --no-interaction` | [log](evidence/composer-install.txt) | 0, lock instalado sin scripts |
| `composer check-platform-reqs` | [log](evidence/composer-platform.txt) | 0, requisitos satisfechos |
| `composer audit --locked` | [log](evidence/composer-audit.txt) | 0, sin avisos de vulnerabilidades |
| `composer audit --locked --no-dev` | [log](evidence/composer-audit-no-dev.txt) | 0, sin avisos de vulnerabilidades |
| `npm ci` | [log](evidence/npm-ci.txt) | 0 |
| `npm audit` | [log](evidence/npm-audit.txt) | 0, cero vulnerabilidades |
| `npm run build` | [log](evidence/npm-build.txt) | 0 |
| `php vendor/bin/phpunit --do-not-cache-result` | [log](evidence/phpunit.txt) | **456 pruebas / 6.081 aserciones**, 0 |
| `php vendor/bin/phpunit --exclude-group web-w2 --do-not-cache-result` | [log](evidence/phpunit-baseline.txt) | **447 pruebas / 5.925 aserciones**, 0 |
| `php artisan route:list --path=api -v` | [log](evidence/api-routes.txt) | **21 operaciones**, 0 |
| `git diff --check` y comprobación del índice | [log](evidence/git-diff-check.txt) | 0 |

Los **nueve tests nuevos / 156 aserciones** están exclusivamente en `ClientDashboardTest`, grupo `web-w2`. No se cambió ningún test previo. Cubren invitados y guard histórico; roles cliente/prestador/admin/dual; revocación fresca; vacíos; aislamiento de cuentas y roles; predicado de lectura; filtros de identidad; escape HTML; separación de estados; frontera temporal estricta y conversión UTC; límites/orden sin N+1; ausencia de DML y mutaciones; contador indisponible y error 503 seguro.

Los cuatro archivos de dependencias están idénticos a la base. Se conservan Laravel 12.71.0, Sanctum 4.3.3, Google API Client 2.20.1, Google Auth 1.55.3, Firebase JWT 7.2.1, Guzzle 7.16.1 y Dompdf 3.1.6. La compilación mantiene avisos del Sass/Bootstrap histórico y URLs absolutas de fuentes públicas; la carga real de las fuentes se revisó en navegador.

## Invariantes

[invariants.json](evidence/invariants.json) registra las comprobaciones contra la base. Las **37 migraciones** son idénticas byte por byte, incluida Sanctum. Las **21 operaciones API** coinciden exactamente, método/URI/nombre/controlador/middleware, con el [JSON W1/W1.1](../w1/evidence/api-routes.json). No cambian rutas, OpenAPI, contratos móviles, modelos, guards, roles, policies, enums ni workflows. Backend del prestador y fuentes/notas de los diez KPI sin cambios.

Se compararon hashes privados de `.env` y `database/database.sqlite` antes y después: idénticos. No se generó ni cambió una APP_KEY persistente. No se ejecutaron migraciones, seeds o rollbacks sobre bases persistentes, ni se accedió a una instalación Laragon anterior. Las únicas migraciones de fixtures se ejecutan en SQLite `:memory:` después de comprobar el aislamiento en `Tests\TestCase`.

## Navegador

La batería reproducible está en `tools/tekora-w2-browser-checks.py`; usa exclusivamente el router estático con la cabecera `X-Tekora-Preview`. Sus resultados, capturas y videos se detallan en el README y en [browser-results.json](evidence/browser-results.json). El exportador es `tools/tekora-w2-preview.php`. La matriz Chromium se sirvió mediante un servidor HTTP estático local sobre ese mismo export y su CSP, para evitar mantener una capa Docker mientras se capturaba. El router PHP de la guía se comprobó además directamente con PHP 8.2.28: [resultados](evidence/preview-router.json); GET/HEAD exportados dan 200, POST 405 y rutas privadas/dinámicas 404. Ninguno de esos servidores inicia un backend Laravel.

**50 combinaciones responsive**, seis ejecuciones Axe sin infracciones automáticas, cero errores de página y cero recursos fallidos. Se revisaron visualmente las capturas desktop/móvil y los pares sólidos de [contrast.json](evidence/contrast.json): texto principal 17,17:1, secundario 8,80:1 y CTA cian 7,48:1. Axe deja comprobaciones de contraste en gradientes como incompletas.

Se comprueban ambos paneles, periodos 7/30/90, estados vacíos, perfil pendiente, cuenta, formulario de perfil y catálogo en **320/375/768/1024/1440 px**. No se modifica ni se incluye la pantalla clínica histórica en esa matriz de rediseño; sus archivos y contratos se conservan y los tests históricos siguen pasando. La tabla y el menú nativos se comprueban también sin JavaScript; Enter/Escape devuelve foco al menú y Tab alcanza el skip link. Los filtros producen una navegación GET completa con cambio de `performance.timeOrigin`.

La comprobación final adicional de [hover con movimiento reducido](evidence/reduced-motion-hover.json) verifica ambos paneles a 375 y 1440 px: botones y accesos rápidos no se desplazan. Se revisan las preferencias de movimiento y transparencia reducidos; no quedan animaciones decorativas activas y las superficies se vuelven opacas. Se comprueba el giro de autenticación W1.1 tras compartir el chunk de Anime en el nuevo build. No se introducen acciones sobre credenciales en la previsualización.

Axe-core 4.10.3 se ejecuta como herramienta externa de revisión con las etiquetas WCAG 2 A/AA y 2.1 AA. Sus comprobaciones automáticas no acreditan conformidad WCAG completa: gradientes, foco y lectura visual requieren también revisión. Windows/Laragon, Safari/Firefox, lector de pantalla, GPU física y zoom nativo quedan para la validación independiente. Los videos se grabaron en Chromium headless Linux y el resultado registra el renderer real.
