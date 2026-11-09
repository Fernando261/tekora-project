# W2.1 · Portabilidad de PHPUnit con assets Vite

La validación independiente Windows/Laragon del SHA `53d56f04452d1ce6cc089f8f084f6bf2415b097c` descubrió una expectativa incorrecta: el HTML compilado usa nombres con hash, mientras la prueba buscaba rutas fuente literales. La presencia de `public/hot` en el entorno cloud ocultaba esa diferencia.

`TekoraW21Test` ahora lee las dos entradas del manifiesto, comprueba su extensión y existencia física, y exige las URLs exactas en un `script type="module" src` y un `link rel="stylesheet" href`. Cada recurso tiene una aserción independiente: un preload o texto suelto no satisface la comprobación. Un hotfile temporal propio permite comprobar el manifiesto aunque exista `public/hot` local. Sin manifiesto, se verifican las dos etiquetas reales de desarrollo, sin `withoutVite`.

Se añade una sola regresión para ejecutar la rama sin build en un public path temporal, incluso cuando el proyecto ya está compilado. No se elimina el manifiesto real. Las comprobaciones originales de CSP, scripts inline, logout POST, CSRF y autorización se mantienen. No se cambia código productivo ni dependencias.

## Resultados Linux

PHP **8.2.28**, PHPUnit **11.5.57**, límite temporal de **256 MB**, Node **24.19.0**, npm **11.9.0**. Los comandos PHP usan el wrapper Docker local con esa versión; en Windows se usa `php` según la [guía actualizada](W21-LARAGON.md).

| Ejecución | Resultado | Evidencia |
| --- | --- | --- |
| PHPUnit habitual, con el `public/hot` original presente | **464 pruebas / 6.366 aserciones**, 129 MB | [Log](evidence/w21/portabilidad/phpunit-usual.log) |
| `npm ci` | Código **0**, 91 paquetes instalados | [Log](evidence/w21/portabilidad/npm-ci.log) |
| `npm run build` | Código **0**, manifiesto y assets generados | [Log](evidence/w21/portabilidad/npm-build.log) |
| PHPUnit completo inmediatamente después del build, sin `public/hot` | **464 pruebas / 6.366 aserciones**, 129 MB | [Log](evidence/w21/portabilidad/phpunit-built.log) |
| Baseline `--exclude-group web-w21`, también sin hotfile | **456 pruebas / 6.081 aserciones**, 127 MB | [Log](evidence/w21/portabilidad/baseline-built.log) |
| Grupo `--group web-w21` | **8 pruebas / 285 aserciones** | [Log](evidence/w21/portabilidad/focused.log) |
| `npm audit --json` | Código **0**, **0 vulnerabilidades** | [JSON](evidence/w21/portabilidad/npm-audit.json) |
| `git diff --check` | Código **0** | Sin errores de espacios |

La cadena post-build se ejecutó secuencialmente: `npm ci → npm run build → PHPUnit completo → baseline`. Se apartó temporalmente el hotfile real y se restauró al terminar; [comandos y códigos](evidence/w21/portabilidad/postbuild-results.json). El build mantiene los avisos de Sass y resolución de fuentes existentes; no impiden compilar y se conservan en el log.

Frente a la ejecución completa anterior de **463/6.350**, el incremento es **una prueba y 16 aserciones**: diez comprobaciones nuevas del manifiesto (cinco por recurso) y seis de la regresión sin build, incluida la comprobación heredada de aislamiento. Las dos comprobaciones de URLs sustituyen a las dos comprobaciones textuales anteriores. La baseline permanece exactamente igual. Las **6.341** aserciones reportadas en Windows corresponden a una ejecución con fallo; no son el total anterior correcto. El total de aserciones de una ejecución sin manifiesto puede ser menor, porque no ejecuta las comprobaciones de archivos compilados.

`.env`, la base SQLite persistente, `public/hot` y el manifiesto real conservan sus SHA-256 privados previos: [comparación sin exponer hashes](evidence/w21/portabilidad/protected-files.json). Las pruebas utilizan SQLite `:memory:`. No se ejecutaron migraciones manuales ni operaciones sobre bases persistentes. No se modifican APP_KEY, app, autenticación, permisos, workflows, contratos API, migraciones ni archivos de dependencias.

El mismo PR **#19** permanece en borrador, pendiente de repetir ambos escenarios en Windows/Laragon con el nuevo SHA. No se fusiona ni despliega.
