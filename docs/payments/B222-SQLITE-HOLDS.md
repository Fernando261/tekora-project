# TEKORA B2.2.2 — HoldStore SQLite sintético aislado

**B2.2.2-fix1: READY FOR INDEPENDENT REVIEW.** La revisión del commit original detectó un P2 de contención previa a BEGIN; la corrección y sus resultados están en [B222-FIX1-VALIDATION.md](B222-FIX1-VALIDATION.md). Prototipo de pruebas, sin binding Laravel, endpoints, integración financiera ni reservas operativas. La publicación y cualquier integración requieren autorización posterior.

Base: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
Rama local: `codex/payments-b222-sqlite-slot-holds`.
Referencias verificadas: #32 `a7efd0e353435c46c633f02120d131507bdc7516`, #33 `815879d448e17ef8955354c6a275201ba6f350fe`, #34 `7775938dda5440c03964009847d4a8d9d63b0805`. Permanecían abiertos/en borrador al preparar el incremento. No se fusionaron ni modificaron.

Se consultó el diseño completo `/workspace/tekora-b222-preparation-_ig1vbsh/TEKORA-B222-PREPARATION.md`. La autorización posterior permite implementar el adaptador y aplicar el esquema exclusivamente a bases nuevas del laboratorio. No se creó una migración Laravel ni se modificó ninguna de las 37 fuentes históricas.

## Dependencia exacta de B2.1

Main no contiene `App\Scheduling\Holds`. El adaptador implementa el **HoldStore original** del PR #33 y usa su SyntheticHoldEngine sin reescribirlo ni incorporarlo al commit.

- [b21-reference.json](../../tests/Support/HoldsSqlite/b21-reference.json) fija SHA y SHA-256 de veinte fuentes: catorce clases de dominio y seis fuentes originales de pruebas/soporte.
- [export_b21_reference.py](../../tools/payments-b222/export_b21_reference.py) obtiene los blobs Git exactos, verifica huellas y escribe exclusivamente un directorio **nuevo externo**; rechaza destino existente o dentro del repositorio.
- B21Reference comprueba las veinte huellas antes de instalar un autoloader limitado al dominio y dos soportes originales. Referencia ausente/alterada detiene el bootstrap; no usa otra rama o versión como fallback.
- Las pruebas originales de B2.1 siguen usando InMemorySlotHoldStore y se ejecutan intactas. No se modifican sus expectativas de identidad de objetos. Las pruebas PDO adicionales comparan por valor.
- Ninguna clase de B1 o B2.1 está copiada en el historial nuevo. #32/#34 se consultan como referencias de diseño, no se cargan ni se componen en este prototipo.

## Componentes

| Componente de tests | Responsabilidad |
| --- | --- |
| SyntheticHoldDatabase | Archivo nuevo exclusivo, directorio privado, capability de la ejecución, conexiones PDO propias y controles efectivos. Reapertura por workers solo con capability del mismo run. |
| SqliteHoldStore | Implementación completa del puerto original, transacciones IMMEDIATE, consultas coherentes, append/CAS y recuperación de recibos. |
| HoldValues | UTC-us enteros, representación canónica, reconstrucción de propuesta/hold/eventos y validación semántica de recibos. |
| SqliteHoldBusy / HoldCommitUncertain | Diferenciar contención acotada y resultado que exige reconexión/recuperación de la misma intención. |
| SqliteHoldContention | Política compartida para pasos SQL auditados, con clasificación exclusiva de BUSY, presupuesto por fase y registro. Nunca recibe el callback de negocio. |
| HoldWorker / ProcessPeer | PHP CLI independiente, barreras por pipes, deadline del protocolo, registro de PID/resultados/diagnósticos. No es un comando Artisan ni un servicio. |
| AdmissionWorker | Worker adicional de fix1 para contención de admisión/apertura, agotamiento, liberación y verificación con otro proceso/conexión. |
| HoldFixture | Solo identidades y propuestas inventadas; integridad y FK al terminar cada fixture con base. |

Todo el PHP nuevo vive en tests/. No hay import desde app/routes/providers hacia el prototipo, modificación de composer.json, registro de store o modificación de ReservationWorkflow.

## SQL realmente aplicado

Archivo: [schema.sql](../../tests/Support/HoldsSqlite/schema.sql). La fábrica lo aplica dentro de BEGIN IMMEDIATE a un archivo que acaba de crear mediante `fopen(..., 'x+b')`; no acepta una ruta/DSN de instalación. Usa user_version=222, UTF-8, journal DELETE, synchronous FULL, FK ON, CHECK activas, recursive_triggers=0 y busy_timeout=0. Requiere PHP x64/POSIX y SQLite >=3.38; solo se ejecutó en el runtime de la validación.

Cinco entidades STRICT, sin tablas operativas:

1. `b222_provider_identities`: perfil y usuario positivos, PK, UNIQUE de usuario/pareja y protección de colisiones por ambas identidades.
2. `b222_clock`: singleton con última observación UTC-us no decreciente; no delete/replace/reset.
3. `b222_holds`: binding inmutable de propuesta y participantes, intervalo, estado, versión y plazos; reserva y quote únicas aun después de terminar.
4. `b222_hold_events`: cadena por hold/versión, actor, from/to y tiempo; append-only, generada por triggers de la transición.
5. `b222_hold_receipts`: intención por ámbito de actor/UUID, huella y referencia FK al evento exacto; append-only.

Hay **22 triggers**, tres índices explícitos y doce índices de catálogo. PK/FK, UNIQUE, NOT NULL y CHECK cubren forma, relaciones y versiones. Las identidades y el singleton usan **WITHOUT ROWID**: ajuste técnico justificado para impedir que un NULL explícito genere silenciosamente otro INTEGER PRIMARY KEY. No altera las cinco entidades; existe una regresión específica de rechazo.

UUID y huellas son ASCII canónicos con validación completa de bytes y rechazo de NUL; no se confía solo en length(TEXT). El guard de identidad contempla perfil y user_id antes de REPLACE, sin depender de recursive_triggers. Cada clave única relevante tiene protección ante sustituciones; UPDATE/DELETE históricos se rechazan. Los triggers de eventos crean la historia junto con snapshot: no se depende del orden entre triggers AFTER hermanos o de un trigger diferido al COMMIT.

B2.1 admite quote_ref opaco ASCII `[A-Za-z0-9_-]`, de 1..128 bytes: se preserva ese contrato, sin exigirle un UUID financiero que aún no posee. Ese valor no prueba aceptación B1. El puente futuro exigirá la cotización/aceptación real, sin hacer más permisivo el SQL financiero.

UTC se persiste en INTEGER de microsegundos Unix, sin float/REAL/julianday. Conversión reversible; rango técnico explícito 2000..2100, con control de los extremos del intervalo/vencimiento. Los DTO B2.1 no se cambian para declarar ese límite: un instante fuera de la capacidad del prototipo se rechaza. UUID/actores/tiempos se validan también en PHP; la afinidad SQLite controla el valor almacenado, no acredita el tipo original del request.

## Estados y exclusividad

- held/v1: updatedAt=acceptedAt; expiresAt=acceptedAt+900 segundos exactos.
- committed/released/v2: acceptedAt<=updatedAt<expiresAt.
- expired/v2: updatedAt>=expiresAt. Exactamente a 900 segundos ya es debido.
- Solo held transiciona. Terminales no se reactivan ni reciben v3; el binding histórico reserva/quote no se recicla.
- held y committed bloquean; released y expired no. Committed sigue bloqueando después del deadline original.
- Capacidad compartida por prestador, incluso para servicios distintos: conflicto si A.start<B.end y A.end>B.start. [start,end) permite horarios consecutivos y distingue un microsegundo de solape.
- Guard SQL BEFORE INSERT/UPDATE más BEGIN IMMEDIATE; no basta un chequeo PHP o UNIQUE de hora inicial. No se filtran held vencidos para fingir una liberación: el motor registra su expiración bajo el mismo lock.

Las identidades, intervalos, instantes y versiones son inmutables. Cada consulta reconstruye los DTO originales, comprueba cadena completa de eventos y compara todos los campos por valor. Los recibos recuperan el agregado vigente, no un snapshot viejo. SQL valida forma/FK/unicidad; PHP recomputa la huella del comando/evento porque SQLite estándar no aporta SHA-256. Un recibo de forma válida pero huella semántica errónea se rechaza al reconstruir.

## Transacciones, reloj y resultado incierto

`atomic()` abre una conexión propia sin transacción exterior/reentrada, adquiere BEGIN IMMEDIATE antes del callback y confirma una sola vez. Snapshot, identidad, eventos, recibo y reloj se revierten ante excepción estructural. Las lecturas compuestas fuera de atomic usan una transacción de lectura breve para no mezclar snapshot e historial de versiones distintas.

Se conserva la semántica original del motor: un HoldViolation **devuelto como valor** permite confirmar expiraciones legítimas y se lanza fuera de atomic; un error **lanzado dentro** provoca rollback. No se confirma cualquier excepción de negocio indiscriminadamente. Las regresiones cubren rechazo tardío y rechazo por conflicto que conserva otra expiración, así como fallo estructural que revierte la expiración.

El motor usa su reloj inyectado después del bloqueo y vuelve a leer al registrar. `observeClock` persiste la marca compartida entre conexiones/procesos. Un retroceso observado falla cerrado. Espera por BEGIN no crea el instante de aceptación; un replay devuelve ID/T/deadline originales y no los renueva. T es el registro lógico persistido atómicamente, no la hora de llegada HTTP ni el instante físico de fsync.

BUSY también puede producirse en los PRAGMA de validación previos a BEGIN. Fix1 cubre las fases `connect` (apertura/configuración local/validación), `admission` (lecturas de controles fuera de transacción), `begin`, `read_begin` y `commit`. Solo reconoce el código primario SQLite 5, conservando el código extendido en los eventos. Errores estructurales, LOCKED y otros códigos se propagan sin reclasificarlos o reintentarlos.

Cada unidad auditada tiene un presupuesto configurable de uno a tres intentos y pausas 1/2 ms, con busy_timeout=0. Es un límite por llamada/fase, no un SLA o presupuesto global de todo un comando. Las verificaciones de capability/rutas/identidad se conservan en cada intento. Las preparaciones fallidas de conexión no registran un PDO autorizado; sus eventos quedan en la fábrica y, al obtener una conexión válida, el store conserva su historial de preparación. `store()` propaga presupuesto/observador también a la apertura inicial.

Solo se repiten lecturas de admisión, apertura de un PDO local sin transacción de negocio, asignaciones idempotentes de configuración de conexión, BEGIN que devolvió BUSY o **el mismo COMMIT** tras BUSY. No se repite el inicializador de esquema ni el callback de negocio. En BEGIN el callback aún no corre; el agotamiento de COMMIT produce rollback conocido. Dentro de una transacción activa no se reintenta admission: un BUSY de cuerpo/lectura sigue su rollback, se registra y devuelve contención controlada sin repetir el cuerpo. Después de un COMMIT confirmado, incluso un error con código 5 en el seam de respuesta sigue siendo HoldCommitUncertain, no un pretexto para reejecutar la escritura.

Si no puede establecerse rollback o se pierde la confirmación después de COMMIT, se invalida la conexión para sus adaptadores y se emite HoldCommitUncertain. Reconectar y repetir **la misma intención** mediante el motor abre BEGIN IMMEDIATE antes de decidir: recupera recibo/huella/hold ya confirmados, o solo adquiere si se demuestra ausencia tras serializar con el escritor anterior. Fallo de lectura no equivale a ausencia; no inventar otra clave o plazo. Se probaron pérdida simulada de respuesta y salida controlada de proceso antes/después de COMMIT; no se afirma pérdida física de energía ni fallo real del driver.

`beforeCommit`, `afterCommit` y `onBusy` son seams explícitos de este soporte de tests, para inyectar fallos/barreras. No son endpoints ni opciones productivas. No se anida atomic B1/B2 ni se prueba una transacción financiera conjunta.

## Propiedad y aislamiento de recursos

La ejecución requiere TEKORA_B222_LAB_PARENT: directorio canónico privado, modo 0700 y propietario POSIX actual, más TEKORA_B222_RUN_TOKEN de 64 hex nuevo por comando de pruebas. Cada base se crea en un subdirectorio aleatorio nuevo, modo 0700, archivo SQLite 0600 y manifiesto exclusivo 0600. Se registra identidad dev/inode del archivo creado; se verifican identidad, tipo, permisos, ruta, manifest, run y huella del esquema antes de reabrir. No seguir symlinks declarados, aceptar un DSN arbitrario, heredar PDO entre procesos o adoptar un recurso de otro run.

Los workers reabren únicamente la capability del archivo recién creado por el coordinador en esa prueba. Esta reapertura para concurrencia no significa reutilizar una base preexistente de instalación o de una ejecución anterior. Los PDO se registran por identidad en WeakMap; un PDO de otro laboratorio se rechaza. Se verifican FK/CHECK/journal, main y ausencia de TEMP/ATTACH no autorizados antes de operar.

Los recursos se **retienen**; no hay API de purga/limpieza, ni interacción con raíces históricas/W6.2e. Los controles de rutas/identidad sirven al laboratorio privado controlado: no se certifica inmunidad a un proceso host adverso del mismo usuario que cambie el filesystem entre comprobaciones. No se reproduce explotación de filesystem ni se atribuyen garantías de seguridad W6.2e a este prototipo. Windows requiere otro tratamiento de identidad; la fábrica falla de forma explícita si no hay POSIX.

## Ejecución y pruebas por niveles

En una copia aislada sin .env, instalaciones, medios o bases reales, con vendor disponible:

```sh
python3 tools/payments-b222/export_b21_reference.py /ruta/nueva/externa/b21
export TEKORA_B222_B21_REFERENCE=/ruta/nueva/externa/b21
# Crear un parent nuevo/propio, modo 0700. No usar una carpeta de instalaciones.
export TEKORA_B222_LAB_PARENT=/ruta/propia/ensayo/labs
# Repetir esta asignación antes de cada comando de pruebas.
export TEKORA_B222_RUN_TOKEN="$(php -r 'echo bin2hex(random_bytes(32));')"
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-single
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-two-connections
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-sqlite-processes
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-fix1
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php tests/HoldsSqlitePrototype --group b222-fix1-processes
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b222-bootstrap.php "$TEKORA_B222_B21_REFERENCE/tests"
```

El token se genera localmente; no publicar los tickets de workers. Para cada comando usar un run distinto. El exporter necesita los objetos Git exactos de #33; si no están disponibles, detener, no reconstruir otra implementación.

La suite estándar de main no descubre este directorio: **el comando explícito B2.2.2 es obligatorio**. La matriz de valores b222-contract-values está incluida en el conjunto completo. Las regresiones originales B2.1 siguen probando memoria; no se presentan como sustitución automática por SQL.

El grupo de procesos lanza dos PHP_BINARY con pipes y PIDs distintos, conexiones nuevas al mismo archivo. Barreras READY/RUN/LOCK_ACQUIRED/CONTINUE y BUSY/RETRY; un timeout de fase de 10 s y salida de 5 s falla el escenario, sin sustituirlo por sleeps o un doble de memoria. `display_errors=stderr` conserva los avisos del runtime fuera del canal JSON; no los silencia. Las trazas guardan fases, PID, resultados y stderr, sin tickets.

Casos: mismo intervalo/solape parcial y servicios distintos; consecutivos; otro prestador; misma intención; commit a deadline−1 us y a deadline; salida antes de COMMIT y después sin respuesta; release/replay de otro proceso. Una tercera conexión verifica los hechos y PRAGMA de integridad/FK. Un BUSY inicial solo acredita contención: tras liberarlo se comprueba el resultado semántico definitivo y la ausencia de parciales.

## Límites de aceptación

- #32: dependencia conceptual futura, sin prueba de aceptación financiera/autorización productiva.
- #33: contrato/motor/20 fuentes exactos; persistencia por valor y nuevos tests, sin cambiar originales.
- #34: lecciones P2/P3 y diseño transaccional aplicadas; no se carga su adaptador ni se reutiliza su base o se compone su atomic.
- No consultas o proyección a reservas históricas. Confirmaciones legacy actualmente no ven holds: la activación queda bloqueada hasta una autoridad común y cobertura de todos los recorridos web/API.
- Committed permanece terminal y bloqueante; cierre de ocupación tras cancelación/completado requiere un claim/puerto posterior. No se implementa en este incremento.
- WAL/BUSY_SNAPSHOT, recuperación física/pérdida de energía, fallo real de conexión durante COMMIT, Windows/PHP 8.2, mínimo SQLite 3.38, auditorías e integración productiva no están acreditados.
- Cotización B1 de 1.800 s independiente de hold 900 s. Vencimiento aplicable de quote al crear hold, antelación al servicio, revocaciones, cancelaciones, legal/fiscal/comercial/proveedor siguen pendientes. No se inventan reglas de pago ni se activa un proveedor.

Resultados actuales en [B222-FIX1-VALIDATION.md](B222-FIX1-VALIDATION.md) y [evidence/B222-fix1-validation.json](evidence/B222-fix1-validation.json). Los resultados originales y fallos de desarrollo se conservan como historia en [B222-VALIDATION.md](B222-VALIDATION.md) y [evidence/B222-validation.json](evidence/B222-validation.json), este último sin cambios. Se recomienda otra revisión independiente del commit correctivo antes de considerar publicación.
