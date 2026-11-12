# B2.2.1-fix2 — Transacciones SQL externas en PDO SQLite / PHP 8.2

Corrección exclusiva del P2 de compatibilidad del prototipo sintético B2.2.1.
Base exacta: `7775938dda5440c03964009847d4a8d9d63b0805` (HEAD de referencia de #34).
Rama local independiente: `codex/payments-b221-php82-fix`.
El SHA del commit correctivo se entrega por separado para evitar un hash autorreferencial.
No se modifica #34 ni se publica esta rama.

## Causa raíz confirmada y reproducción

Con las fuentes exactas de referencia y las dependencias de `composer.lock`:

| Runtime | SQLite | PHPUnit | Suite B2.2.1 original |
| --- | --- | --- | --- |
| PHP 8.2.35 / PDO_SQLITE 8.2.35 | 3.40.1 | 11.5.57 | **FAIL:** 134 PASS, 1 ERROR; 135 tests / 620 assertions |
| PHP 8.4.27 / PDO_SQLITE 8.4.27 | 3.40.1 | 11.5.57 | PASS: 135 tests / 621 assertions |

El error es `PricingSqliteConcurrencyTest::test_manual_transaction_is_not_adopted_or_rolled_back_by_the_store`:
`SQLSTATE[HY000]: General error: 1 cannot start a transaction within a transaction`.

Un sondeo PDO independiente, sin Laravel ni PHPUnit y usando exclusivamente `:memory:`,
confirma que `BEGIN`, `BEGIN IMMEDIATE`, `BEGIN EXCLUSIVE` y un `SAVEPOINT` exterior
crean un contexto SQLite real. Un segundo BEGIN es rechazado por SQLite en ambos runtimes.
Sin embargo, `PDO::inTransaction()` devuelve **false en PHP 8.2** y **true en PHP 8.4**
para esas cuatro formas SQL; `PDO::beginTransaction()` devuelve true en ambos.
Los dos runtimes tienen el mismo `sqlite_source_id`; no se atribuye la diferencia al motor SQLite.

El adaptador anterior confiaba exclusivamente en `inTransaction()` para detectar ese contexto
externo. En PHP 8.2 alcanzaba un segundo BEGIN y propagaba PDOException en lugar del rechazo
LogicException previsto. La aserción original sobre `inTransaction()` tampoco era portable.
Es independiente del P1 de procesos/fork de B2.3.1; esta corrección no implementa ese laboratorio.

## Mecanismo y propiedad de la transacción

Se conserva el guard preventivo de propiedad de conexión, invalidación, reentrada e
`inTransaction()`. Como respaldo, al ejecutar el **BEGIN IMMEDIATE del adaptador** se reconoce
únicamente la combinación completa:

- fase `begin`;
- SQLSTATE `HY000`;
- código nativo entero exactamente `1`;
- diagnóstico SQLite exactamente `cannot start a transaction within a transaction`.

Solo ese caso produce LogicException, conservando la PDOException original como `previous`.
No se usa únicamente el código general SQLITE_ERROR=1 ni una búsqueda parcial en el mensaje.
El callback no se ha ejecutado; `$active` todavía no se ha establecido y no se ha entrado en
el bloque de COMMIT/ROLLBACK de una transacción propia. Por ello el adaptador no adopta,
confirma, revierte ni invalida la transacción del llamador. No hay sondeos destructivos,
SAVEPOINT internos, COMMIT o ROLLBACK de prueba para detectar una transacción ajena.

Los errores del cuerpo conservan su tipo e identidad, incluso un segundo BEGIN que produzca
el mismo diagnóstico desde dentro del callback. Los demás errores de admisión se propagan.
Los caminos de BEGIN IMMEDIATE, rollback propio, SQLITE_BUSY, reintentos finitos, callback
una vez por invocación, invalidación e idempotencia permanecen y tienen regresiones ejecutadas.
No fue necesario cambiar `SyntheticPricingDatabase`, su registro WeakMap ni su esquema.

## Regresión original y nuevas pruebas portables

La regresión original conserva su nombre y exige LogicException. Sustituye la dependencia
de `inTransaction()` por evidencia conductual más fuerte: el llamador inserta un precio sin
confirmarlo, el callback cuenta cero ejecuciones, la conexión propia conserva el precio y una
segunda conexión no lo ve. El llamador ejecuta su ROLLBACK y ambas conexiones ven la ausencia.
La misma conexión puede después confirmar una operación normal visible desde el observador.

`PricingSqlitePhp82Test.php` añade **10 casos / 88 assertions**, incluidos en la suite completa
y ejecutados sin ramas condicionales ni skips en ambos runtimes:

1. BEGIN manual diferido: rechazo, invisibilidad, propiedad y rollback del llamador.
2. SAVEPOINT exterior: las mismas garantías, sin liberarlo ni adoptarlo.
3. Transacción iniciada mediante PDO: las mismas garantías.
4. BEGIN EXCLUSIVE: rechazo y conservación del bloqueo real hasta el rollback del llamador.
5. Tabla inexistente: SQLITE_ERROR=1 original, rollback propio y reconexión observadora.
6. Error sintáctico: SQLITE_ERROR=1 original, sin reclasificarlo.
7. BEGIN anidado desde el cuerpo: mismo código/mensaje de SQLite, conservando PDOException.
8. BEGIN sobre conexión query_only: error nativo SQLITE_READONLY=8, callback cero, recuperación.
9. Operación normal: el BEGIN obtiene el lock antes de cualquier escritura; un competidor
   recibe SQLITE_BUSY=5 real y no ejecuta su callback; puede operar tras la liberación.
10. Conexión invalidada: dos adaptadores previos rechazan lecturas/trabajo; la fábrica no la
    readopta, la transacción externa persiste y el llamador puede revertirla; conexión nueva válida.

Contra el adaptador anterior, los nuevos casos detectaron **3 ERROR** en PHP 8.2 (BEGIN manual,
SAVEPOINT y EXCLUSIVE), sin alterar las expectativas para obtener PASS.
La suite completa conserva los casos previos de BUSY en BEGIN/COMMIT, rollback e idempotencia.
Los grupos incluidos no se suman como suites independientes.

## Validación final

Los resultados y huellas de evidencia se registran en
[B221-fix2-validation.json](evidence/B221-fix2-validation.json).

| Comprobación | PHP 8.2.35 | PHP 8.4.27 |
| --- | --- | --- |
| B2.2.1-fix2 completo | PASS: 145 tests / 714 assertions | PASS: 145 tests / 714 assertions |
| Regresiones nuevas, incluidas | PASS: 10 / 88 | PASS: 10 / 88 |
| B1 original, fuentes exactas | PASS: 82 / 4.353 | PASS: 82 / 4.353 |
| Histórica compatible | PASS: 789 / 10.344 | PASS: 789 / 10.344 |
| Integridad de las 248 bases nuevas de las dos suites | PASS: ok / FK vacías | PASS: ok / FK vacías |

Pint `--test` del prototipo con vendor exacto: PASS, 12 archivos.
Build offline con dependencias exactas: PASS, Vite 6.4.5, 67 módulos; dos avisos woff2 que se
resuelven en runtime. No se certifica la carga visual de fuentes.

## Aislamiento, historia de preparación y reproducción

Las pruebas usan copias estáticas del commit y de los tres archivos PHP correctivos, sin `.env`,
bases, medios, uploads ni Storage existentes. Contenedores efímeros con red deshabilitada,
UID 1000, vendor y referencia B1 de solo lectura. B1/histórica usan SQLite `:memory:`;
B2.2.1 crea exclusivamente archivos privados nuevos retenidos para inspección.
Los runtimes históricos tienen árboles y caches separados. No se eliminan raíces previas.

La referencia B1 se exportó desde `a7efd0e353435c46c633f02120d131507bdc7516`; se verifican las
12 fuentes originales contra `b1-reference.json`, sin modificarlas ni fusionar esa rama.
Composer se instaló con `--no-scripts` en un directorio externo; las 113 versiones coinciden con
el lockfile. Los manifiestos y lockfiles del repositorio permanecen intactos.

Se retienen los intentos de preparación: vendor preinstalado incompatible (sin Sanctum), caches
generadas con paquetes anteriores (DomPDF) y ejecución histórica sin assets Vite. Los intentos
históricos sin assets se detuvieron; no se presentan como validación completa ni se ocultan.
Se corrigió únicamente el arnés: dependencias fijadas, rutas APP_PACKAGES_CACHE y
APP_SERVICES_CACHE nuevas, build y directorios aislados. Un primer build con node_modules
de solo lectura no pudo crear `.vite-temp`; se añadió un montaje temporal de escritura para
ese cache, manteniendo las dependencias de solo lectura. Los logs no se sobrescriben.

Artefactos locales: `/workspace/tekora-b221-fix2-cbam31uy/`, con `run.py`, sondeo, referencias,
copias, recursos y `logs/` con comandos, salidas y JUnit. No son enlaces públicos portables.
Los hashes publicados corresponden solo a fuentes y evidencia sintética, no a datos reales.

Comandos esenciales, desde una copia nueva aislada y con dependencias del lockfile:

```sh
python tools/payments-b221/export_b1_reference.py <destino_B1_nuevo_externo>
# Definir TEKORA_B221_B1_REFERENCE y TEKORA_B221_LAB_PARENT con recursos nuevos.
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype --group pricing-sqlite-fix2
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php <destino_B1_nuevo_externo>/tests
npm --offline run build
php -d memory_limit=1G vendor/bin/phpunit
php vendor/bin/pint --test tests/Support/PricingSqlite tests/PaymentsSqlitePrototype tests/payments-b221-bootstrap.php
```

## Alcance residual y entrega

Posible regresión principal: confundir un SQLITE_ERROR no relacionado o alterar la propiedad
de una transacción. Las pruebas cubren errores sintácticos, tabla inexistente, el mismo mensaje
dentro del cuerpo, READONLY, BUSY y preservación real de cambios externos.
El reconocimiento depende del diagnóstico canónico SQLite observado: si otro driver lo cambia,
se propagará PDOException sin adoptar ni revertir el contexto ajeno; no se declarará ese perfil compatible.

NOT RUN: otros PHP, Windows/NTFS, mínimo SQLite 3.38, WAL/BUSY_SNAPSHOT, procesos concurrentes,
recuperación física, fallos reales de driver/OS en COMMIT, auditorías Composer/npm y recorridos
productivos. El antecedente Composer audit HTTP 403 no se da por resuelto.
Solo se acredita el comportamiento probado en Linux/PHP 8.2.35 y 8.4.27/PDO_SQLite 3.40.1;
no se declara compatibilidad integral de Tekora con PHP 8.2.

No cambian main, migraciones, ReservationWorkflow, API, SQL histórico, B1 original ni los PR
#28/#31/#32/#33/#34/#35/#36. No hay push, PR nuevo, merge, despliegue, pagos ni bases reales.
No se inicia B2.3.2.

**READY FOR INDEPENDENT REVIEW**, exclusivamente para esta corrección del prototipo sintético.
FAIL final: ninguno. BLOCKED final: ninguno en las comprobaciones solicitadas.
La revisión independiente y cualquier publicación requieren un paso posterior; no es READY FOR PR.
