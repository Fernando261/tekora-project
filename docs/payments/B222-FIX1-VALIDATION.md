# TEKORA B2.2.2-fix1 — Contención anterior a BEGIN IMMEDIATE

Estado: **READY FOR INDEPENDENT REVIEW**, exclusivamente para el prototipo sintético local. El P2 tiene una corrección y regresiones ejecutadas; su cierre independiente está pendiente. No se declara READY FOR PR.

Rama: `codex/payments-b222-sqlite-slot-holds`.
Commit original conservado: `426f6f47e456cab84fa84afd8b4da4a3574e282f`.
Base conservada: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
El nuevo commit local tiene al original como padre; su SHA se entrega por separado para evitar una referencia circular dentro del propio commit.

## Incidencia original y causa raíz

La revisión externa `REVIEW-B222.md` concluyó CHANGES REQUIRED por F1/P2: `atomic()` ejecutaba `guard/check` antes del protocolo acotado de BEGIN. Las consultas PRAGMA de admisión podían devolver SQLITE_BUSY bajo un escritor EXCLUSIVE o un COMMIT normal en estado PENDING detrás de un lector. Escapaba PDOException, sin busyEvents ni observador. No se había observado doble ocupación o pérdida de datos.

Se conserva el informe y sus JUnit fallidos en `/workspace/tekora-b222-review-8zo39eob/review`, sin abrir sus bases. Sus 42 casos distintos dieron 340 aserciones, 40 PASS, un error y un fallo de la misma causa. La evidencia original [B222-validation.json](evidence/B222-validation.json) permanece idéntica al commit original. Estos resultados históricos no se reclasifican retrospectivamente.

## Protocolo implementado

`SqliteHoldContention` centraliza la clasificación por código primario SQLite entero (`errorInfo[1] & 255`). Solo el primario 5 activa contención; se conserva el código extendido en los eventos. LOCKED=6, IOERR=10, constraints=19, errores estructurales y excepciones de observadores conservan su tratamiento original. No hay catch que convierta indiscriminadamente PDOException en retryable.

Cada invocación de una fase admite **1..3 intentos totales**, incluidos el primero y el último fallido. Entre intentos hay pausas de 1 y 2 ms; el último no llama de nuevo al observador y produce `SqliteHoldBusy(phase, attempts)`. El presupuesto es por fase/invocación, **no un plazo global de comando**: un comando puede validar admisión más de una vez. Los observadores son seams de prueba y sus barreras tienen límites propios; no son un SLA productivo.

| Contexto | Acción permitida | Motivo y tratamiento |
| --- | --- | --- |
| A — connect | Reabrir un PDO local todavía no autorizado; repetir configuración local idempotente y validación | No hay transacción de negocio ni DML/esquema que repetir. El PDO fallido se descarta y solo se registra el completamente validado. Identidad/ruta/capability se vuelven a comprobar. |
| A — admission | Repetir comprobaciones de propiedad y lecturas de metadatos sin transacción activa | No crea ni modifica ocupaciones. Preserva las validaciones existentes; no las omite bajo carga. |
| A — begin/read_begin | Repetir únicamente BEGIN IMMEDIATE o BEGIN que devolvió BUSY | El cuerpo de negocio aún no se ha ejecutado. |
| B — cuerpo o lectura activa | No repetir el cuerpo; establecer ROLLBACK y registrar body/read antes de responder BUSY | Si ROLLBACK no queda establecido, invalidar la conexión; no tratar el resultado como un simple reintento seguro. |
| B — commit BUSY conocido | Repetir exclusivamente el mismo COMMIT, con el mismo presupuesto | El cuerpo se ejecuta una vez. Agotamiento conserva el rollback/invalidación existentes. |
| C — confirmación incierta | Invalidar y responder HoldCommitUncertain; reconectar y resolver la misma intención persistida | No repetir callbacks ni escrituras inciertas. Incluso un código 5 inyectado después de COMMIT confirmado conserva esta ruta. |

La política solo acepta las fases auditadas connect, admission, begin, read_begin y commit. `check()` sigue siendo una primitiva sin retry interno: durante una transacción activa sus errores llegan al rollback, sin multiplicar presupuestos. El inicializador del esquema no se reejecuta; conserva su uso exclusivo de un archivo recién creado antes de entregar la capability.

La fábrica retiene `busyEvents()` aun cuando connect termina sin devolver PDO. Los eventos de una conexión preparada correctamente se incorporan al store. La relación de propiedad no se registra hasta completar la validación. No se cambió el contrato HoldStore ni se añadieron bindings Laravel.

## Regresiones incorporadas

Son **22 pruebas nuevas / 260 aserciones**, sin editar ni sustituir las 103 pruebas originales:

- 18 / 146: presupuestos de 1/2/3 intentos en admisión y conexión; agotamiento; liberación antes del límite; eventos de fábrica y store; recuperación y callback único; COMMIT normal detrás de lector; errores ajenos a BUSY; user_version incorrecto; excepción del observador; rollback del cuerpo con BUSY; pérdida de confirmación posterior a COMMIT con recuperación de la misma intención y deadline fijo.
- 4 / 114: admission/connect por procesos PHP independientes, cada uno con liberación antes del límite o agotamiento y reintento explícito posterior. Barreras, PIDs y trazas verifican cero callbacks durante el bloqueo y exactamente uno tras liberarlo.

En los cuatro casos nuevos de procesos, una tercera conexión comprueba ausencia de filas antes de la adquisición y después exactamente un hold, su historial, 900 segundos exactos, **cero pares activos incompatibles**, integrity_check=ok y foreign_key_check=[]. Se conservan también las carreras originales de exclusividad, versiones, terminales y recuperación. Esta evidencia demuestra los escenarios enumerados, no todos los interleavings posibles.

Los **42 casos externos originales** y sus workers/testigos se ejecutaron sin cambiar fuentes ni expectativas. Sus huellas se cotejan con los archivos de la revisión anterior. Se conservan fuera del commit y no forman parte del prototipo publicado. Los dos casos que antes fallaban alcanzan ahora sus expectativas restantes; por eso sus aserciones aumentan de 340 a 345, sin suavizarlas.

## Resultados efectivos

| Comprobación | Estado | Resultado |
| --- | --- | --- |
| B2.2.2 completo, originales + fix1 | PASS | 125 pruebas / 765 aserciones |
| Fix1, incluido en el completo | PASS | 22 / 260 |
| Dos conexiones, grupo histórico incluido | PASS | 9 / 51 |
| Procesos originales + fix1, incluidos | PASS | 14 / 262 = 10 / 148 originales + 4 / 114 nuevos |
| Externas originales: núcleo | PASS | 20 / 215 |
| Externas originales: procesos | PASS | 6 / 94 |
| Externas originales: límites/fábrica | PASS | 16 / 36 |
| B2.1 exacto externo | PASS | 80 / 323 |
| Suite histórica de main | PASS | 789 / 10.344 |
| Pint --test | PASS | 19 archivos PHP |
| Build npm --offline | PASS con avisos | 67 módulos |
| integrity_check / foreign_key_check | PASS | ok / [], también desde conexión posterior a procesos |
| Esquema, triggers, fuentes y diff | PASS | SQL y 22 triggers intactos; 47 fuentes protegidas intactas |

Las suites PHPUnit finales tienen cero errores, fallos y omitidas. No sumar grupos incluidos ni repeticiones como pruebas adicionales. Las 789 históricas y las 80 B2.1 son ejecuciones separadas; no certifican integración productiva conjunta.

Los testigos observacionales originales también se conservaron y repitieron:

1. RESERVED por BEGIN IMMEDIATE: SqliteHoldBusy(begin,3), tres eventos, cero callbacks y recuperación posterior.
2. EXCLUSIVE: SqliteHoldBusy(admission,3), tres eventos, cero callbacks y recuperación posterior; antes escapaba PDOException sin eventos.
3. COMMIT normal PENDING detrás de lector: el competidor recibe SqliteHoldBusy(admission,1), un evento y cero callbacks; al cerrar el lector el escritor confirma su reloj e integrity_check=ok.

Estos testigos no se cuentan como pruebas PHPUnit adicionales. Resultados, comandos con exit code, versiones, huellas de fuentes/JUnit/logs y trazas por ejecución están en [B222-fix1-validation.json](evidence/B222-fix1-validation.json).

## Aislamiento y reproducibilidad

Copia nueva: `/workspace/tekora-b222-fix1-o1rtruh6`; referencia B2.1 externa: `/workspace/tekora-b222-fix1-o1rtruh6-b21`. Extracción de fuentes Git permitidas y overlays del fix cotejados byte por byte con las fuentes ejecutadas. No se copió .env, bases, medios o storage de usuarios. Cache/Storage sintéticos nuevos. PHP/Pint en contenedores efímeros sin red y vendor de solo lectura; build offline. Todos los recursos nuevos quedan retenidos, sin limpieza de bases anteriores ni raíces históricas W6.2e.

Runtime: **Linux; PHP 8.4.27 x64; PDO SQLite 3.46.1; PHPUnit 11.5.57; Node 24.22.1; npm 11.9.0; Vite 6.4.5**. Controles observados: DELETE, UTF-8, synchronous=2, FK=1, CHECK activas, recursive_triggers=0, busy_timeout=0, user_version=222. Cinco tablas STRICT, 22 triggers, tres índices explícitos/doce de catálogo.

Los 22 archivos de código/esquema/manifest/exportador/bootstrap coinciden con la copia ejecutada. De los 18 originales, solo store y fábrica cambiaron. Se cotejaron los 20 archivos B2.1 exactos, las 37 migraciones históricas y otras diez fuentes protegidas; OpenAPI 1.1.0 conserva sus 21 operaciones. B1/B2.1 no se fusionan ni copian al commit; no se modifica ReservationWorkflow, rutas, dependencias o código productivo.

Comandos funcionales (el JSON conserva prefijos Docker, montajes, duración y exit code; tokens efímeros sustituidos por un marcador):

```sh
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --log-junit /app/review/final-b222.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-fix1 --log-junit /app/review/final-fix1.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-two-connections --log-junit /app/review/final-connections.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-processes --group b222-fix1-processes --log-junit /app/review/final-processes.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php review/IndependentHoldReviewTest.php --log-junit /app/review/final-external-core.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php review/IndependentProcessReviewTest.php --log-junit /app/review/final-external-processes.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php review/IndependentBoundaryReviewTest.php --log-junit /app/review/final-external-boundaries.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php /b21/tests --log-junit /app/review/final-b21.xml
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/review/final-main.xml
php vendor/bin/pint --test tests/Support/HoldsSqlite tests/HoldsSqlitePrototype tests/payments-b222-bootstrap.php
npm --offline run build
php -d display_errors=stderr review/busy-witness.php
php -d display_errors=stderr review/pending-commit-witness.php
php -d display_errors=stderr review/runtime.php
```

La suite estándar no descubre HoldsSqlitePrototype: el bootstrap/comando específico sigue siendo obligatorio. No se alteró la configuración para integrar este laboratorio en producción.

## Iteraciones y avisos conservados

Una primera invocación de grupos separados por coma completó 14/262 pero salió 1 por aviso de deprecación PHPUnit. Se corrigió exclusivamente el comando usando dos flags --group; el rerun salió 0 sin cambiar expectativas. Pint detectó espacios de argumentos en dos pruebas nuevas; se corrigió formato, se repitieron 125/765, 22/260 y Pint. Los registros anteriores se conservan como FAIL de esa comprobación, no como fallos de integridad o resultados finales PASS retrospectivos.

Se retienen stderr del helper Git de sebastian/version (proc_open/posix_spawn); los procesos PHP sí ejecutaron las barreras y resultados registrados. Vite deja dos referencias woff2 para runtime; no se certifica carga visual de fuentes. No se ocultaron avisos cambiando dependencias o configuración.

**FAIL finales: ninguno. BLOCKED de las comprobaciones obligatorias ejecutadas en Linux: ninguno.** El defecto histórico conserva su registro y la aceptación independiente del fix está pendiente.

## Inventario del fix: diez archivos

Modificados:

1. `tests/Support/HoldsSqlite/SqliteHoldStore.php`
2. `tests/Support/HoldsSqlite/SyntheticHoldDatabase.php`
3. `docs/payments/B222-SQLITE-HOLDS.md`
4. `docs/payments/B222-VALIDATION.md`

Nuevos:

5. `tests/Support/HoldsSqlite/SqliteHoldContention.php`
6. `tests/Support/HoldsSqlite/AdmissionWorker.php`
7. `tests/HoldsSqlitePrototype/SqliteHoldAdmissionTest.php`
8. `tests/HoldsSqlitePrototype/SqliteHoldAdmissionProcessTest.php`
9. `docs/payments/B222-FIX1-VALIDATION.md`
10. `docs/payments/evidence/B222-fix1-validation.json`

## Hallazgos y límites

F1/P2: implementado y regresionado; **cierre independiente pendiente**. No se confirmaron hallazgos adicionales P0/P1/P2/P3 en los escenarios ejecutados. Esta valoración de implementación no sustituye una nueva revisión independiente.

**NOT RUN:** nueva aceptación independiente; WAL/BUSY_SNAPSHOT; pérdida de energía/recuperación física; pérdida real de driver/OS durante COMMIT; Windows/PHP 8.2 (se requieren checks POSIX); mínimo SQLite 3.38; auditorías Composer/npm; CI remoto; autorización/integración productiva y reservas históricas; transacciones financieras conjuntas; proveedores/pagos; aprobaciones legales, fiscales y comerciales. No se declara Composer audit aprobado ni resuelto el antecedente 403.

No se prueba inmunidad frente a cambios hostiles de filesystem por otro proceso del mismo usuario. Se conservan los checks de propiedad y la parada ante discrepancias, sin adoptar ni borrar recursos ajenos. No se afirma haber resuelto W6.2e. Los límites del presupuesto y de los seams de prueba no son una política operativa definitiva.

Siguiente paso: revisión técnica independiente del nuevo SHA, con las reproducciones y expectativas conservadas. Sin push, PR nuevo, cambios a main/#28/#31/#32/#33/#34, migraciones productivas, merge, despliegue, proveedores, cobros, retenciones productivas o B2.3.
