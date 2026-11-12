# TEKORA B2.2.2 — Validación del HoldStore SQLite aislado

Estado: **READY FOR INDEPENDENT REVIEW**, solo para el prototipo sintético. No es aprobación de publicación, migración productiva, fusión o integración de reservas.

Rama: `codex/payments-b222-sqlite-slot-holds`.
Base: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
Se crea un único commit local nuevo sobre esa base; el SHA exacto se identifica en Git y en la entrega. No se incorporan commits de #32/#33/#34 ni fuentes B2.1 al historial.

## Preparación e integridad

- main y HEAD de #32/#33/#34 se verificaron contra GitHub y coinciden con las referencias autorizadas; abiertos/en borrador y no fusionados al consultar.
- Diseño completo B2.2.2 disponible y leído; inspección del HoldStore, motor, DTO y soporte originales de #33 antes de implementar.
- Rama nueva desde main con árbol limpio, sin trabajo parcial desconocido sobrescrito.
- Veinte fuentes B2.1 externas exactas verificadas por SHA-256. La exportación es nueva, externa y se rechaza si ya existe o cambia alguna huella.
- Dieciocho archivos de implementación/SQL/manifest/exportador/bootstrap coinciden con la copia realmente ejecutada. Cuarenta y siete fuentes protegidas permanecen intactas: incluyen las 37 migraciones, rutas, workflows, dependencias y OpenAPI 1.1.0/21 operaciones.
- Sin edición de código de aplicación, ReservationWorkflow, rutas, contratos públicos o estados productivos. Sin W6.2e/PR #31 o limpieza de raíces históricas.

## Resultados efectivos

| Nivel / comprobación | Estado | Resultado |
| --- | --- | --- |
| A — B2.1 original, contrato/motor/store de memoria intactos | PASS | 80 pruebas / 323 aserciones |
| B2.2.2 completo | PASS | 103 / 505 |
| Valores exactos y scopes, incluidos en el completo | PASS | 19 / 23 |
| B — Una conexión: ciclo/persistencia/constraints | PASS | 65 / 283 |
| C — Dos conexiones PDO independientes | PASS | 9 / 51 |
| D — Dos procesos PHP independientes | PASS | 10 / 148 |
| Suite histórica main | PASS | 789 / 10.344 |
| Pint --test, únicamente PHP del incremento | PASS | 15 archivos |
| Build npm --offline | PASS con avisos | 67 módulos |
| PRAGMA integrity_check / foreign_key_check | PASS | ok / [] |
| Diff e integridad de fuentes | PASS | Solo archivos nuevos del incremento |

Todas las ejecuciones PHPUnit finales: **0 errores, 0 fallos, 0 omitidas**. Los grupos B/C/D y la matriz de valores están incluidos en 103; A y main son ejecuciones separadas. No sumar estas filas como si fueran una suite integrada con persistencia productiva. Las 80 pruebas originales siguen probando memoria; las pruebas PDO nuevas verifican compatibilidad por valores, sin cambiar sus expectativas.

Runtime efectivo: **Linux, PHP 8.4.27 x64, PDO SQLite 3.46.1, PHPUnit 11.5.57, Node 24.22.1, npm 11.9.0 y Vite 6.4.5**.

Controles observados: UTF-8, journal DELETE, synchronous FULL=2, foreign_keys=1, ignore_check_constraints=0, recursive_triggers=0, busy_timeout=0 y user_version=222. Cinco tablas STRICT, dos WITHOUT ROWID, 22 triggers, tres índices explícitos/doce de catálogo. Las comprobaciones de integridad se ejecutan también tras fixtures poblados y desde una conexión posterior a los procesos.

## Garantías cubiertas

- UTC-us enteros reversibles, incluidos offsets equivalentes y microsegundos; tipos inválidos y valores fuera del rango técnico rechazados sin floats.
- Servicio de 3.600 s y plazo exactamente 900 s; vigente a 899.999999, debido a 900.000000. No se inicia un temporizador por construir una propuesta/aceptar inquiry inicial.
- Solape del mismo prestador rechazado también entre servicios distintos; consecutivos y otro prestador permitidos. Guard SQL directo ensayado además del motor PHP.
- held→committed/released/expired, CAS, terminales sin reactivación, committed bloqueante tras 900 s, deadline fijo en reintentos y bindings históricos conservados.
- Historia/eventos/identidades/recibos/reloj append-only donde corresponde; rechazo de UPDATE/DELETE/REPLACE, incluidas ambas claves de identidad de prestador. PK enteras WITHOUT ROWID evitan autogeneración silenciosa ante NULL.
- Representación completa UUID/ref/scopes/huellas, NUL, tipos y relaciones. Reconstrucción comprueba snapshots y cadena de eventos. Un hash semánticamente falso se detecta aun cuando su forma SQL es válida.
- Rollback de todos los efectos ante fallo estructural y persistencia de expiración legítima ante rechazo tardío o conflicto de otra adquisición; no se deshabilitan constraints/triggers para probarlo.
- BEGIN IMMEDIATE antes del reloj/decisión, contención acotada, COMMIT reintentado sin repetir cuerpo, agotamiento con rollback conocido y posterior reintento.
- Conexión invalidada si se pierde contexto o confirmación; recuperación de misma intención con otra conexión y sin duplicar ni renovar.

## Procesos: sincronización y evidencias

Los dos workers son PHP CLI genuinos invocados mediante PHP_BINARY; no comparten un PDO ni un array. El coordinador entrega una capability del archivo creado para la misma prueba y cada worker abre su conexión propia. Se registran PIDs distintos del coordinador y entre sí, fases, resultados, busy y stderr.

1. A recibe RUN y mantiene BEGIN IMMEDIATE en LOCK_ACQUIRED.
2. B intenta la decisión y comunica BUSY de BEGIN; todavía no leyó el reloj de decisión.
3. Coordinador permite CONTINUE a A y observa su resultado confirmado/salida.
4. B recibe RETRY y resuelve sobre estado fresco: conflicto definitivo, adquisición consecutiva/otro prestador, o replay del mismo hold.
5. Otra conexión verifica snapshot, eventos, recibos y reloj, más integridad/FK. BUSY por sí solo no se presenta como prueba de un único ganador.

Diez casos: mismo intervalo; solape parcial entre servicios; consecutivo; otro prestador; misma intención; commit a deadline−1 us; commit exactamente a deadline frente a nueva adquisición; salida antes de COMMIT; salida después de COMMIT sin respuesta; release/replay de otro proceso. Cada traza incluye el nombre del caso/dataset, PIDs y fases. Las diez trazas finales y sus huellas se enumeran en el JSON de evidencia; las veinte de ambas ejecuciones completas no se cuentan como veinte escenarios distintos.

El reloj de prueba es inyectado y explícito en los procesos. Las pruebas de reinicio utilizan salida controlada de proceso y reconexión; no son una prueba de caída física, pérdida de energía o fallo real del driver durante fsync.

## Iteraciones FAIL conservadas

Las primeras versiones del arnés de procesos fallaron: diez errores por una propiedad de pipes no inicializada y después diez por un aviso del runtime mezclado con stdout JSON. Se corrigieron la inicialización y los canales de diagnóstico; no se cambiaron expectativas, constraints o reglas de negocio. Los JUnit/logs fallidos permanecen fuera del commit, con huellas en la evidencia.

`display_errors=stderr` separa diagnóstico y protocolo, sin silenciarlo. Los stderr de los workers se retienen junto con las trazas. El aviso proc_open/posix_spawn corresponde al helper Git de sebastian/version en la imagen; los procesos PHP de las pruebas sí se ejecutaron y sus PIDs/resultados son observables.

Otros avisos: Vite conserva dos referencias woff2 para resolución en runtime en la copia aislada; no se certifica carga visual de fuentes. No se modificó frontend/configuración/dependencias para ocultarlos.

**FAIL actuales: ninguno. BLOCKED de las comprobaciones obligatorias en Linux: ninguno.** Los FAIL anteriores no se reclasifican como PASS históricos.

## Entorno reproducible y SQL

Copia de ejecución nueva: `/workspace/tekora-b222-check-qo10gcif`.
Referencia B2.1 externa nueva: `/workspace/tekora-b222-check-qo10gcif-b21`.
Las fuentes de main se extrajeron de blobs Git permitidos y los dieciocho archivos nuevos se verificaron contra la copia; no se copiaron .env, bases, instalaciones, storage o medios de usuarios. Storage/cache nuevos, vendor de solo lectura, contenedores efímeros sin red para PHP/Pint; build offline. No se ejecutó ningún contenedor productivo.

SQL real: [tests/Support/HoldsSqlite/schema.sql](../../tests/Support/HoldsSqlite/schema.sql), aplicado solo por la fábrica a archivos exclusivos nuevos bajo review/labs. No migración Artisan ni modificación de tabla histórica. Se conservaron exactamente las cinco entidades del diseño; el uso de WITHOUT ROWID para identidad/singleton está justificado y probado. No se abrieron bases anteriores ni se limpiaron recursos; los directorios y trazas quedan retenidos.

Comandos exactos/montajes/duración/exit code y hashes de fuentes/JUnit/logs en [evidence/B222-validation.json](evidence/B222-validation.json). Los tokens de run se sustituyen en el documento por un marcador; los comandos completos del entorno local se conservan fuera del commit. No se publican capabilities o credenciales.

```sh
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --log-junit /app/review/final-b222.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-single --log-junit /app/review/final-single.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-two-connections --log-junit /app/review/final-connections.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-processes --log-junit /app/review/final-processes.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php /b21/tests --log-junit /app/review/final-b21.xml
npm --offline run build
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/review/final-main.xml
php vendor/bin/pint --test tests/Support/HoldsSqlite tests/HoldsSqlitePrototype tests/payments-b222-bootstrap.php
php -d display_errors=stderr review/runtime.php
```

El último probe es soporte externo de validación, no un endpoint/script productivo. La suite estándar de main no descubre HoldsSqlitePrototype; el comando específico es obligatorio. Pruebas y fixtures originales de #33 se exportan sin cambios; no se incorpora código B2.1 a este commit.

## Inventario final: 21 archivos nuevos

1. `docs/payments/B222-SQLITE-HOLDS.md`
2. `docs/payments/B222-VALIDATION.md`
3. `docs/payments/evidence/B222-validation.json`
4. `tests/HoldsSqlitePrototype/HoldValuesTest.php`
5. `tests/HoldsSqlitePrototype/SqliteHoldConcurrencyTest.php`
6. `tests/HoldsSqlitePrototype/SqliteHoldConstraintsTest.php`
7. `tests/HoldsSqlitePrototype/SqliteHoldLifecycleTest.php`
8. `tests/HoldsSqlitePrototype/SqliteHoldProcessTest.php`
9. `tests/Support/HoldsSqlite/B21Reference.php`
10. `tests/Support/HoldsSqlite/HoldCommitUncertain.php`
11. `tests/Support/HoldsSqlite/HoldFixture.php`
12. `tests/Support/HoldsSqlite/HoldValues.php`
13. `tests/Support/HoldsSqlite/HoldWorker.php`
14. `tests/Support/HoldsSqlite/ProcessPeer.php`
15. `tests/Support/HoldsSqlite/SqliteHoldBusy.php`
16. `tests/Support/HoldsSqlite/SqliteHoldStore.php`
17. `tests/Support/HoldsSqlite/SyntheticHoldDatabase.php`
18. `tests/Support/HoldsSqlite/b21-reference.json`
19. `tests/Support/HoldsSqlite/schema.sql`
20. `tests/payments-b222-bootstrap.php`
21. `tools/payments-b222/export_b21_reference.py`

## Hallazgos y límites

No se confirmaron defectos P0/P1/P2/P3 abiertos en el alcance Linux/PDO sintético después de estas validaciones. Esa es la valoración del incremento implementado: la revisión independiente aún no se realizó. Los riesgos de integración del diseño anterior siguen vigentes, no se consideran resueltos por estos PASS.

**NOT RUN:** revisión independiente; WAL/BUSY_SNAPSHOT; recuperación física/pérdida de energía; fallo real de conexión durante COMMIT; Windows/PHP 8.2 (la fábrica actual requiere POSIX); mínimo SQLite 3.38; auditorías Composer/npm; GitHub CI; integración/autorización productiva y reservas históricas; transacciones financieras conjuntas/fixture B1; proveedores y pagos. No se declara Composer audit aprobado ni se da por resuelto el antecedente 403.

#32 conserva su independencia: no se ejecuta B1 ni se prueba aceptación financiera con este adaptador. #33 se reutiliza exactamente como contrato/motor; #34 es antecedente de diseño y de P2/P3, sin cargar su store/base o anidar atomic. Cotización 1.800 s y hold 900 s independientes.

No se garantiza aislamiento frente a un atacante host del mismo usuario que cambie el filesystem entre comprobaciones; no se trasladan garantías de W6.2e. Los recursos nuevos se crean en parent privado y se validan identidad/ruta/capability antes de usarlos; ante discrepancia se detiene sin adoptar o borrar. La integración con reservas requiere autoridad común, cobertura de writers antiguos, claim cerrable separado de committed terminal y autorización propia para esquema/estados/API/notificaciones.

Validaciones legales, fiscales, comerciales y del proveedor pendientes. Sin push, PR nuevo, merge, auto-merge, despliegue, pagos o retenciones productivas. La siguiente acción recomendada es una revisión técnica independiente del SHA local, SQL, factory, idempotencia y evidencias antes de considerar publicación.
