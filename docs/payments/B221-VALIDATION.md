# TEKORA B2.2.1 — Validación y entrega local

Registro **histórico del commit original `417fbbeea08e1222eedd362dc6af09b1b47c5b8b`**. Su estado inicial fue READY FOR REVIEW; la revisión independiente posterior concluyó **CHANGES REQUIRED** por P2 (representación NUL) y P3 (REPLACE por user_id). Este registro y su JSON original se conservan; no describen la aceptación de fix1.

Corrección y validaciones actuales: [B221-FIX1-VALIDATION.md](B221-FIX1-VALIDATION.md).
Rama: `codex/payments-b221-sqlite-pricing-prototype`.
Base: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
No se ha publicado la rama ni creado un PR.

## Resultados ejecutados

| Validación | Estado | Resultado |
| --- | --- | --- |
| B2.2.1 final | PASS | 111 pruebas / 360 aserciones; 0 errores, fallos u omitidos |
| B1 original, fuentes fijadas del PR #32 | PASS | 82 / 4.353; 0 errores, fallos u omitidos |
| Suite histórica de main | PASS | 789 / 10.344; 0 errores, fallos u omitidos |
| W5.1, dentro del JUnit main | PASS | 49 / 459 |
| W6.2a, dentro del JUnit main | PASS | 25 / 406 |
| W6.2b, dentro del JUnit main | PASS | 34 / 586 |
| Ensayo W6.2c-d, dentro del JUnit main | PASS | 120 / 1.318 |
| Pint final | PASS | 10 PHP nuevos, sin cambios históricos |
| Build offline | PASS con avisos | 67 módulos |
| SQLite integrity_check | PASS | ok, en teardown de cada test con base y en sondeo independiente nuevo |
| SQLite foreign_key_check | PASS | Sin filas, en teardown y sondeo independiente |
| Esquema y pragmas | PASS | 7 tablas STRICT, 21 triggers propios, 9 índices explícitos/21 totales; FK=1, journal DELETE, synchronous FULL, busy_timeout=0 |
| Integridad de fuentes | PASS | 13 fuentes ejecutadas coinciden; 47 fuentes protegidas (incluidas las 37 migraciones) conservadas |
| API | PASS estático | OpenAPI 1.1.0 y sus 21 operaciones sin cambios |

Son **tres ejecuciones separadas**: prototipo, B1 original y baseline main. No se atribuye una suite combinada ni integración productiva. B1 aporta 43 pruebas monetarias / 4.126 aserciones y 39 funcionales / 227. La suite de main no incorpora B1 ni B2.1; sus 789 pruebas no deben compararse como regresión con las 871/869 de aquellas ramas.

Runtime efectivo: PHP **8.4.27 x64**, PDO SQLite/SQLite **3.46.1**, PHPUnit **11.5.57**, Node **24.22.1**, npm **11.9.0**, Vite **6.4.5**. Journal DELETE es el único modo ensayado aquí. No se trasladan resultados de Python o W6.2e.

## Entorno y comandos

Copia nueva: `/workspace/tekora-b221-check-esrx3a5m`.
Fuentes base extraídas de Git sin .env*, uploads, storage de instalación, medios ni bases existentes. Directorios cache/storage del ensayo nuevos. Referencias B1 verificadas y montadas de solo lectura, fuera del repositorio. PHP en contenedores efímeros sin red y con vendor existente de solo lectura. No se instalaron ni alteraron dependencias.

Las pruebas del adaptador crean archivos SQLite nuevos en subdirectorios aleatorios de `review/labs`. Solo esa fábrica puede emitir conexiones para el prototipo. Las pruebas históricas/B1 funcionales crean sus esquemas operativos de fixtures únicamente en `:memory:`; no se enlaza el nuevo adaptador a ellos. No se aplicó migración de aplicación nueva.

Comandos relevantes dentro de la copia aislada (los argumentos Docker completos están en el JSON de evidencia):

```sh
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype --log-junit /app/review/accepted-b221.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php /b1/tests --log-junit /app/review/complete-b1.xml
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/review/final-main.xml
php vendor/bin/pint --test tests/Support/PricingSqlite tests/PaymentsSqlitePrototype tests/payments-b221-bootstrap.php
npm --offline run build
```

El nombre local accepted del log es una etiqueta de corrida, no aprobación humana o de integración. La entrega queda READY FOR REVIEW.

El SQL realmente ejecutado se conserva íntegro en `tests/Support/PricingSqlite/schema.sql`; solo SyntheticPricingDatabase::create lo aplica sobre su archivo exclusivo nuevo. No hay entrada de esquema en database/migrations ni triggers en tablas existentes.

## Cobertura del encargo

| Requisito | Evidencia efectiva |
| --- | --- |
| 1. Creación de esquema | Lista exacta de siete tablas nuevas, STRICT, pragmas y huella del SQL. |
| 2. Precios versionados | Inserción consecutiva, versión obsoleta/gap rechazadas, ORDER BY version, preservación de anteriores. |
| 3. Importes exactos | Nueve fronteras monetarias en SQL/DTO, incluidas mitad de centavo y techo; B1 monetario original completo. |
| 4. Aceptaciones válidas | Inicio de vigencia, aceptación normal y último microsegundo antes del límite. |
| 5. Inconsistencias | 17 variantes de precio, 21 de quote y 6 de aceptación rechazadas por SQL; validaciones de representación adicionales. |
| 6. Historia inmutable | UPDATE/DELETE/REPLACE sobre cada tabla comercial rechazados; fixtures de identidad tampoco se reemplazan. |
| 7. Reintentos | Recuperación persistida por clave tras nueva conexión; ámbitos de servicio/cliente, duplicados y aceptación original sin renovar. |
| 8. Dos conexiones | Lectura no ve append sin commit; ve versión confirmada en consulta posterior. |
| 9. Versiones concurrentes | Segundo writer BUSY mientras primero mantiene lock; tras commit rechaza versión obsoleta y admite la siguiente. |
| 10. Conflictos de escritura | BUSY real en BEGIN y COMMIT; número de intentos acotado; callback ejecutado cero/una vez según fase. |
| 11. Rollback | Fallo tras precio+quote+aceptación y restricción después de precio revierten todo. COMMIT BUSY agotado también revierte. |
| 12. Reinicio de conexión | Destruir PDO sin commit revierte; nueva conexión recupera snapshots confirmados. Contexto perdido exige invalidar conexión/reconectar y consultar por intención. |
| 13. Referencias | FK compuestas, relaciones falsas rechazadas y foreign_key_check vacío. |
| 14. Clientes | Mismo UUID de emisión para clientes distintos no comparte quote; cliente/inquiry incorrectos rechazados. Métodos internos por ID no se presentan como endpoint autorizado. |
| 15. Importes/versiones conservados | Precio posterior no modifica quote/aceptación; rehidratación por valor permite objetos distintos pero rechaza metadatos alterados. |

La concurrencia ejecutada es un intercalado controlado de **dos conexiones PDO independientes en un proceso**, con locks reales de SQLite. No se presenta como carrera entre dos procesos. El cierre de conexión y el fallo de contexto son ensayos ordinarios sintéticos, no una interrupción de energía ni una falla física de disco.

## Incidencias conservadas y revisión defensiva

1. **FAIL inicial:** checkpoint de 80 pruebas / 0 aserciones / 80 errores, por usar fchmod inexistente en PHP al preparar el archivo. Falló antes de crear el esquema. Se cambió por chmod sobre el archivo recién creado de forma exclusiva; no se alteraron expectativas de pruebas. Se conserva b221-first.log/xml.
2. Checkpoint posterior **PASS 106 / 335**. Tras añadir fronteras de idempotencia y control de contexto pasó **110 / 353** y luego **111 / 357**. La versión final invalida el PDO incierto también para otros adaptadores y pasa **111 / 360**.
3. Primera selección B1: **PASS parcial 39 / 227**, solo Feature. Se añadió a la exportación el test monetario original y se ejecutó `/b1/tests`: **82 / 4.353** sin modificar B1.
4. Pint aplicó formato solo a PHP nuevos en la copia aislada; las fuentes formateadas se sincronizaron y se volvieron a ejecutar las pruebas afectadas. Pint --test final pasa.
5. PHPUnit y el bootstrap pueden imprimir proc_open/posix_spawn del helper Git de sebastian/version. Los comandos finales terminaron con exit 0. El sondeo runtime conserva el stdout crudo y también su JSON extraído, sin ocultar el aviso.
6. Vite mantiene dos referencias woff2 para resolución en runtime. Build termina con exit 0; no se verificó carga visual de fuentes ni se modificó frontend.

| Severidad | Estado | Resultado |
| --- | --- | --- |
| P0/P1 | Sin hallazgos pendientes confirmados en este alcance | No hay conexión operativa, migración de aplicación o efecto financiero. |
| P2 | Resuelto en implementación | Fábrica PHP invocaba función inexistente: incidencia inicial conservada. |
| P2 | Resuelto en autorrevisión | Conexión cuyo rollback no puede establecerse debe invalidarse para lectura, reuso y todos sus wrappers; prueba final conserva resultado por reconexión. |
| P3 | Resuelto | Completar selección de pruebas B1, sin atribuir el subset Feature como suite B1 completa. |

## NOT RUN, límites y riesgos pendientes

- Concurrencia entre procesos, WAL, BUSY_SNAPSHOT, SQLITE_LOCKED real e IO/disco/pérdida de energía. La rama clasifica BUSY de cuerpo pero los conflictos reales demostrados fueron de BEGIN/COMMIT.
- Fallo real de conexión durante COMMIT con resultado incierto. El fallo de contexto inyectado comprueba rechazo/reconexión, no reproduce esa falla física.
- Windows 11/PHP 8.2 x64, MySQL, Composer/npm audit y GitHub CI. No se declaran aprobados ni se reutiliza un PASS histórico.
- Integración de permisos Laravel con el adaptador SQL. Las policies B1 se ensayan con su store original y fixtures RAM; los FK del prototipo no autentican usuarios.
- B2.1/HoldStore, ocupación, reservas persistentes existentes, web/API, providers, pagos, ledger, efectivo o liquidaciones.
- Cambios de CHECK/triggers históricos, nuevos estados de reserva/API v2 y composición transaccional de B1+B2: todavía pendientes de diseño autorizado y prueba independiente.
- Las fuentes B1 están fijadas al SHA revisado, no se valida automáticamente una versión futura del PR. Cualquier cambio exige revisar manifest/compatibilidad.
- Las nuevas pruebas son una suite explícita fuera del descubrimiento estándar; el comando específico B2.2.1 es obligatorio. No existe automatización CI nueva.
- No se demuestra propiedad frente a un atacante del filesystem ni se retoman ensayos especializados W6.2e. La fábrica solo admite recursos nuevos propios y no ofrece adoptar rutas existentes.

FAIL en ejecuciones finales: ninguno. BLOCKED de validaciones obligatorias para este alcance: ninguno. Persistencia productiva y garantías no ejecutadas siguen pendientes; no se interpretan como PASS por éxito del prototipo.

## Inventario de 16 archivos nuevos

1. `tests/Support/PricingSqlite/B1Reference.php`
2. `tests/Support/PricingSqlite/b1-reference.json`
3. `tests/Support/PricingSqlite/SyntheticPricingDatabase.php`
4. `tests/Support/PricingSqlite/SqlitePricingStore.php`
5. `tests/Support/PricingSqlite/SqlitePricingBusy.php`
6. `tests/Support/PricingSqlite/PricingValues.php`
7. `tests/Support/PricingSqlite/PricingFixture.php`
8. `tests/Support/PricingSqlite/schema.sql`
9. `tests/PaymentsSqlitePrototype/PricingSqlitePrototypeTest.php`
10. `tests/PaymentsSqlitePrototype/PricingSqliteConcurrencyTest.php`
11. `tests/PaymentsSqlitePrototype/PricingValuesTest.php`
12. `tests/payments-b221-bootstrap.php`
13. `tools/payments-b221/export_b1_reference.py`
14. `docs/payments/B221-SQLITE-PROTOTYPE.md`
15. `docs/payments/B221-VALIDATION.md`
16. `docs/payments/evidence/B221-validation.json`

Código productivo, 37 migraciones, ReservationWorkflow, rutas y OpenAPI permanecen intactos. No hay merge de B1/B2.1, publicación remota ni cambios a PR #28/#31/#32/#33. No se borraron raíces históricas ni se leyeron credenciales/.env, medios o bases reales.

Arquitectura y reproducción: [B221-SQLITE-PROTOTYPE.md](B221-SQLITE-PROTOTYPE.md).
Evidencia portable: [B221-validation.json](evidence/B221-validation.json).
Logs/JUnit y bases sintéticas retenidas: `/workspace/tekora-b221-check-esrx3a5m/review`.

**READY FOR REVIEW.** La siguiente acción recomendada es una revisión técnica independiente de este commit local antes de autorizar publicación o cualquier incremento adicional. B2.2.2 no se ha comenzado. Las aprobaciones legales, fiscales y del proveedor siguen pendientes.
