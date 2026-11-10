# W6.1 · Evidencias estáticas

Procedencia única: `576c937fa3cbd31e6af95eedc1651cb59a16d898`, `Fernando261/tekora-project`. No se ejecutó la aplicación ni se consultaron datos persistentes, sesiones, credenciales o contenidos multimedia. Los nombres de columnas/configuración se leen en código; no sus valores de una instalación.

## Archivos y alcance

| Archivo | Qué acredita | Qué no acredita |
| --- | --- | --- |
| [inventory-method.json](inventory-method.json) | Totales y límites del inventario de fuentes | Usuarios activos, filas reales o uso en producción |
| [branding-occurrences.json](branding-occurrences.json) | Expresión `mind.?bloom` sin distinguir mayúsculas; archivo, línea y fragmento de fuente | No es una lista exclusiva de textos visibles: incluye comentarios y pruebas |
| [asset-reference-inventory.json](asset-reference-inventory.json) | 62 assets, Git blob, claves literales, categoría de referencia e imports relativos candidatos | Cero referencias no autoriza borrado; no cubre valores de datos, tráfico ni todos los imports dinámicos |
| [dormant-candidates.json](dormant-candidates.json) | Controladores auxiliares/scaffold y vistas sin consumidores exactos localizados | No prueba que una instalación o invocador dinámico no los use |
| [schema-inventory.json](schema-inventory.json) | 37 ficheros de migración y declaraciones Schema create/table | No es el esquema real instalado ni un recuento de registros |
| [api-static-inventory.json](api-static-inventory.json) | 21 operaciones API: 8 declaraciones más 13 entradas del bucle | No es salida de Artisan; no ejecuta middleware ni cuenta HEAD implícito como operación extra |
| [web-route-declarations.json](web-route-declarations.json) | Declaraciones literales en web.php e identities.php | No resuelve grupos, middleware ni todas las rutas nativas; esa interpretación está en el mapa |
| [decision-matrix.json](decision-matrix.json) / [CSV](decision-matrix.csv) | 34 filas, quince campos, SHA y referencias por decisión | Son propuestas; ninguna retirada está aprobada por este fichero |
| [source-index.json](source-index.json) | Archivos citados, Git blob y referencias de línea/símbolo | No inventaría todos los archivos del repositorio ni contenidos de medios |
| [verification.json](verification.json) | Diff documental, modos, invariantes y validación de consistencia | No aporta una nueva ejecución de PHPUnit, Composer/npm ni inspección de datos |
| [delivery-files.json](delivery-files.json) | Lista exacta de archivos añadidos en esta entrega | No incluye scripts privados de análisis ni el árbol completo de medios |

## Método reproducible

1. Confirmar que HEAD/base autorizada y `origin/main` son el SHA anterior. Enumerar metadata con `git ls-tree -rz --full-tree <base>`. Leer fuentes mediante `git show <base>:<ruta>`; no desde instalaciones de Laragon ni storage. El árbol completo se usa para comparar identidad, no se publica con nombres de uploads.
2. Examinar extensiones `.php`, `.css`, `.js`, `.json`, `.md`, `.scss`, `.txt`, `.yaml`, `.yml` en app, bootstrap, config, migrations, seeders, resources, routes, tests, tools, docs, .github; además composer.json, package.json y vite.config.js. Añadir fuentes públicas css/js. Se excluyen `.env*`, `public/uploads/` y docs de W6.1 del corpus base. No leer binarios. Resultado: 839 fuentes examinadas.
3. Para marca, buscar `mind.?bloom` sin distinguir mayúsculas, excluyendo docs/tools del resultado de experiencia; clasificar tests aparte. Resultado: 93 líneas coincidentes, 75 de fuente de ejecución y 18 de pruebas. El corpus puede contener comentarios; no se deduce visibilidad de un match.
4. Para assets, usar metadata de rutas bajo public/css, public/js, public/img, public/fonts, resources/css, resources/js y resources/sass. Buscar su ruta relativa a public o ruta completa resources en las fuentes, excluyendo el propio asset. Separar runtime, docs, tests, tools y seeder. Añadir candidatos literales de import `./stem`, `./filename` y el import parcial Sass `variables`. Estos candidatos no constituyen un resolver completo de módulos. `_variables.scss` sí tiene consumidor. OFL sigue siendo necesario aunque su contador sea cero.
5. Para rutas API, leer `routes/api.php`: ocho `Route::get/post/...` explícitos y trece filas de `$operations`. Para esquema, contar `.php` bajo database/migrations y extraer literales `Schema::create/table`. Revisar manualmente guards, closures, aliases y consumidores; no cargar Laravel.
6. Para candidatos dormidos, buscar clase fuera de su definición o render/include/herencia literal de vista, y revisar rutas. En scaffold Auth, el nombre plenamente cualificado sin referencia y la ausencia de `Auth::routes` son indicios; los aliases/imports cortos o dinámicos exigen comprobación adicional antes de retirar nada.
7. Revisar relaciones, ownership, estados, archivos y servicios nativos para las decisiones de la matriz. Citar archivos/líneas/símbolos al SHA fijo y contrastarlos con la base. La lectura manual complementa las búsquedas; no se infiere equivalencia de dominios.
8. Verificar que todos los cambios son adiciones de documentos ordinarios Git100644, sin recursos de UI ni ejecutables, y ejecutar `git diff --check` sobre los archivos añadidos al índice. Comparar todas las rutas fuera de docs/web/w6 con la base; registrar árboles y archivos de invariantes. Validar JSON/CSV, campos y referencias sin importar código de aplicación.

Ejemplos de búsquedas estáticas sobre un checkout aislado de fuentes (los patrones se delimitan a código; nunca buscar en `.env`, storage ni uploads):

```text
rg -n -i 'mind.?bloom' app bootstrap config resources routes tests public/css public/js
rg -n 'auth:profesionista|/google/|/citas|/consultas|/comunidad|/comments|/posts' routes
rg -n 'photoMayBeReferenced|posts|comments|emotions' app/Http/Controllers/ProfileController.php
rg -n 'morphMap|enforceMorphMap' app config
rg -n 'Auth::routes|GoogleCalendarController|ReminderController' app routes bootstrap config
git diff --check --cached
```

Un resultado vacío de rg puede devolver código1 y no demuestra inactividad. El inventario no resuelve URLs externas guardadas, referencias en datos, cambios no versionados, configuración real de servidores, consentimiento OAuth ni retención legal. Si una retirada requiere ese conocimiento, debe solicitarse una revisión posterior delimitada y autorizada.

Solo se contó metadata de nueve objetos versionados bajo `public/uploads/`; no se abrieron sus bytes ni se incluyen sus nombres individuales. No es una copia o auditoría de medios de producción. Los Git blob IDs de assets fuente se obtuvieron del árbol, sin leer imágenes o fuentes binarias.

La baseline 626 pruebas / 8.258 aserciones pertenece a la aprobación Windows anterior. **No hubo ejecución nueva de tests ni auditorías de paquetes en W6.1.** No se añaden scripts ejecutables a la entrega; los bloques de la guía son instrucciones de comparación documental sin operaciones destructivas.
