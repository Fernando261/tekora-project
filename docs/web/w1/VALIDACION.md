# Validación W1

Ejecutada el 5 de noviembre de 2026 con PHP **8.2.28**, Composer **2.8.11**, Node **24.19.0**, npm **11.9.0** y Chromium Linux. Los archivos de `evidence/` registran salidas y resultados. Las auditorías se ejecutaron con red disponible, sin modo offline; no se sustituyeron por un resultado de caché ante un fallo de red.

| Comando | Código | Resultado |
| --- | --- | --- |
| `composer validate --strict` | 0 | válido |
| `composer install --no-scripts --no-interaction` | 0 | lock instalado; sin scripts Laravel |
| `composer check-platform-reqs` | 0 | requisitos satisfechos |
| `composer audit --locked` | 0 | 0 avisos de vulnerabilidades |
| `composer audit --locked --no-dev` | 0 | 0 avisos de vulnerabilidades |
| `php vendor/bin/phpunit --do-not-cache-result` | 0 | **442 pruebas / 5763 aserciones** |
| `php vendor/bin/phpunit --exclude-group web-w1 --do-not-cache-result` | 0 | **433 pruebas / 5642 aserciones** |
| `npm ci` | 0 | lock instalado |
| `npm audit` | 0 | **0 vulnerabilidades** |
| `npm run build` | 0 | compilación correcta |
| `php artisan route:list --path=api -v` | 0 | **21 operaciones API** |
| `git diff --check` | 0 | sin errores de espacios |

## Pruebas y contratos

Las nueve pruebas nuevas agregan **121 aserciones**. Cubren ruta pública y vacíos, visibilidad de categorías/servicios/prestadores, exclusión de roles revocados y datos privados, escape XSS, lectura sin escrituras de negocio, búsqueda y límite existente, acceso histórico, campos y guard legacy, CSRF real, errores/old/password, intended, roles y cabeceras/assets.

Se actualizaron cuatro archivos históricos solo para apuntar las expectativas MindBloom a `route('home')`: `ExampleTest`, `IdentityRolesTest`, `RoutesAndGoogleSafetyTest`, `WebAccountsTest`. La expectativa deliberada que cambia es la ubicación de MindBloom: `/` pasa a ser público y `/mindbloom/inicio` conserva su autenticación. No se eliminó ninguna prueba ni se redujo ninguna aserción histórica. La baseline excluyendo W1 coincide exactamente con 433/5642.

Se compararon los 21 registros API completos (método, URI, nombre, controlador y middleware) con la evidencia 9C: idénticos. `routes/api.php`, OpenAPI, modelos, enums, servicios, policies, controladores API/Auth y `config/auth.php` no cambiaron. Las 37 migraciones son idénticas byte por byte a la base, incluida Sanctum. Las únicas migraciones ejecutadas durante pruebas/exportación fueron contra SQLite `:memory:`.

`composer.json` y `composer.lock` son idénticos a la base. Versiones conservadas: Laravel 12.71.0, Sanctum 4.3.3, Google API Client 2.20.1, Google Auth 1.55.3, Firebase JWT 7.2.1, Guzzle 7.16.1 y Dompdf 3.1.6. Todos los paquetes npm previos conservan sus registros bloqueados; se agrega únicamente Anime.js 4.5.0.

Se verificaron hashes locales de `.env` y `database/database.sqlite` antes/después: sin cambios. No se generó una APP_KEY persistente ni se modificaron Google/SMTP, Laragon o Expo. PHPUnit/exportación generan una clave efímera en el proceso mediante el bootstrap de pruebas existente.

## Navegador y accesibilidad

- 20 combinaciones: `/`, `/login`, `/registro`, vacío y error a **320/375/768/1440 px**. Un `h1`, `main` visible y sin desbordamiento horizontal en todas.
- Menú con Enter/Escape, foco devuelto al summary, `aria-expanded` actualizado; skip link con Tab. No hay modales que requieran focus trap.
- Formularios visibles sin JavaScript; navegación nativa. Escena sin movimiento en táctil y con `prefers-reduced-motion`; entradas/flips/transiciones deshabilitados con esa preferencia. Fallback opaco para transparencia reducida y colores base sin `backdrop-filter` definidos en CSS.
- Reflow equivalente a zoom 200%: ventana física de 1440 px representada a **720 CSS px**, sin overflow en registro. No equivale a haber probado cada navegador con su control de zoom nativo.
- Axe-core **4.10.3**, WCAG 2 A/AA y 2.1 AA: **0 infracciones automáticas** en las cuatro vistas a 375 y 1440 px. La categoría se convirtió en `nav` con nombre accesible. Axe deja revisión de contraste en gradientes/pseudo-elementos; esto no acredita conformidad WCAG completa.
- Revisión visual propia desktop/móvil y cálculo de pares: texto `#EAFBFF` / panel `#07152A` **17,17:1**; secundario `#A3B6CC` / superficie clara conservadora `#25495E` **4,61:1**; hielo sobre esa superficie **6,43:1**; CTA `#031526` / cyan **7,48:1**; borde de input `#6286A3` / fondo `#061429` **4,79:1**; error rosa / superficie de error **9,33:1**. Se revisaron foco y legibilidad en las capturas; se recomienda validación independiente con lector de pantalla y autofill real.
- Sin errores de página ni recursos fallidos en la matriz Chromium. El servidor de evidencia usa PHP 8.2.28, exportación estática y CSP aplicada. POST se rechaza con 405; `/.env` y rutas no exportadas dan 404. El comportamiento real de autenticación se prueba con Laravel/PHPUnit, no con el servidor estático.

Las capturas finales usan el compositor software de Chromium (`--disable-gpu`). Safari, Firefox, lectores de pantalla y Windows/Laragon no se ejecutaron en este entorno; la aceleración gráfica real queda pendiente de esa validación independiente. View Transitions depende del navegador; su fallback es el enlace normal.

## Peso y rendimiento

Presupuesto W1: JS Tekora ≤ 50 kB sin comprimir / 20 kB gzip, CSS ≤ 25 kB / 8 kB gzip, fuentes locales ≤ 60 kB. Los tamaños exactos están en `evidence/invariants.json`; gzip calculado con Python, por lo que puede diferir ligeramente de la estimación Vite.

| Recurso W1 | Bytes | Gzip bytes |
| --- | ---: | ---: |
| JS incluido Anime | 42592 | 16258 |
| CSS | 22765 | 6037 |
| DM Sans WOFF2 | 36932 | ya comprimido |
| Space Grotesk WOFF2 | 22288 | ya comprimido |

No se carga video de fondo, imágenes remotas, escena pesada ni visor 3D. Los videos de `media/` son evidencias y no se enlazan desde la aplicación. El hero usa dimensiones/aspect-ratio y sus dibujos no bloquean el texto. LCP/CLS de producción requieren medir Laravel y una red/dispositivo representativos; las capturas locales no son una medición de producción.

Build conserva avisos de deprecación del Sass/Bootstrap histórico. Vite también avisa sobre las dos URL absolutas de fuentes públicas: se sirven desde `public/fonts/tekora` en ejecución y se comprobó su carga con CSP; no son descargas remotas ni archivos faltantes. No se alteró la compilación legacy para suprimir avisos.
