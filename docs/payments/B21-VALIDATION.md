# TEKORA B2.1 — Validaciones y límites

Base: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
Estado final: **READY FOR REVIEW**, limitado al motor sintético B2.1.

## Preparación

- Rama independiente desde main: `codex/payments-b21-synthetic-slot-holds`.
- Diseño local consultado y alcance B2.1 confirmado antes de programar.
- Sin dependencias de B1: PR #32 no está en la base y no se copiaron sus fuentes.
- Solo archivos nuevos; no binding de HoldStore, rutas, migraciones o proveedores.
- Pruebas en una copia aislada nueva sin .env*, uploads, bases persistentes o storage
  de la instalación. Vendor existente de solo lectura; Docker sin red.
- Tests nuevos de dominio no acceden a una base. Test de compatibilidad y suite
  histórica usan únicamente SQLite :memory: con storage/cache nuevos.

## Registro de incidencias

Primera ejecución focalizada: 56 tests / 269 aserciones, 2 errores. Dos fixtures
reutilizaban el mismo UUID para una liberación y una aceptación nueva del mismo
actor. El motor rechazó correctamente la intención distinta. Se asignó otro UUID
a la nueva intención, sin relajar expectativas ni cambiar la regla de idempotencia.
El log inicial se conserva en el entorno de pruebas.

Durante la revisión del código se añadieron casos de identidad usuario/perfil y
de lectura del reloj antes de la decisión final. No se afirma que esas pruebas
sintéticas demuestren concurrencia o comportamiento físico de SQLite.

El checkpoint de 62 pruebas / 289 aserciones y suite 851 / 10.633 pasó antes de
incorporar reconstrucción de snapshots. Después pasó 80 / 323 y 869 / 10.667.
Una comprobación transitoria de ausencia de clases B1 fue sustituida por comprobar
la versión contractual API 1.1.0: un test permanente no debe impedir una fusión
legítima futura de B1. La ausencia de copias/imports B1 se verifica por linaje y
diff de esta entrega. Se volvió a ejecutar el grupo y la suite completa sobre
esa versión final. Ningún test histórico fue modificado ni omitido.

## Resultados efectivos

Runtime: PHP **8.4.27**, SQLite **3.46.1**, PHPUnit **11.5.57**; Docker local sin red.
El sondeo independiente de PDO creó otra conexión :memory: y database_list mostró
main con file vacío. No se consultó una base existente.

| Verificación | Estado | Tests / aserciones |
| --- | --- | --- |
| Motor B2.1 | PASS | 80 / 323 |
| Suite completa desde main + B2.1 | PASS | 869 / 10.667 |
| Baseline main dentro de la suite | PASS | 789 / 10.344 |
| W5.1, incluido en la suite completa | PASS | 49 / 459 |
| W6.2a, incluido en la suite completa | PASS | 25 / 406 |
| W6.2b, incluido en la suite completa | PASS | 34 / 586 |
| W6.2c-d, incluido en la suite completa | PASS | 120 / 1.318 |
| Pint | PASS | 20 archivos PHP nuevos |
| Build aislado | PASS con avisos | Node 24.22.1, npm 11.9.0, Vite 6.4.5; 67 módulos |
| Compatibilidad de fuentes | PASS | Solo archivos nuevos; 37 migraciones y 21 operaciones intactas |

Todas las ejecuciones finales PHPUnit terminaron con 0 errores, 0 fallos y 0
omitidos. Los grupos históricos se extraen del JUnit de la suite completa, no de
comandos independientes que no se ejecutaron. Pico reportado por PHPUnit completo:
130,50 MB. Los archivos PHP entregados coinciden con los ejecutados.

Comandos dentro de la copia aislada:

```sh
php -d memory_limit=1G vendor/bin/phpunit --group slot-holds-b21 --log-junit /app/b21-final.xml
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/full-final.xml
php vendor/bin/pint --test app/Scheduling/Holds tests/Support/InMemorySlotHoldStore.php tests/Support/MutableSlotHoldClock.php tests/Unit/SyntheticSlotHoldEngineTest.php tests/Unit/UtcServiceIntervalTest.php tests/Unit/SlotHoldReconstitutionTest.php tests/Feature/SyntheticHoldIsolationTest.php
npm --offline run build
```

Los comandos PHP se ejecutaron en una copia nueva de fuentes sin .env* ni datos
de instalación. El esquema histórico se crea solo dentro de PHPUnit en RAM; no
se creó ni aplicó un esquema/migración B1 o B2. El test de aislamiento registró
cero consultas SQL durante las operaciones del motor y conservó su fixture de reserva.

Logs/JUnit nuevos: `/workspace/tekora-b21-check-k90zsedb`.
Se conservan b21-first.log, b21-first.xml, b21-final.log, b21-final.xml,
full-final.log, full-final.xml, pint-final.log, build.log y los checkpoints previos.
Resumen portable y huellas: [evidence/B21-validation.json](evidence/B21-validation.json).

Avisos del entorno:

- PHPUnit muestra proc_open/posix_spawn del helper de versión Git. Los comandos
  finales terminan con exit 0; el commit/base se verifican con Git del host/GitHub.
- Vite conserva dos referencias woff2 para resolución en runtime; no se verificó
  carga visual de fuentes. No se alteró frontend ni configuración para ocultarlo.

## NOT RUN / fuera de alcance

- B1 y sus 82 pruebas: no forman parte de esta rama; no se atribuyen resultados.
- Persistencia financiera/ocupacional, migración B1/B2, concurrencia entre procesos,
  WAL, recuperación física y bases existentes.
- Integración de ReservationWorkflow, rutas web, API móvil o permisos Laravel reales.
- Pasarelas, webhooks, cobros, reembolsos, efectivo y liquidaciones.
- Windows/PHP 8.2 y auditorías Composer/npm, salvo ejecución específica posterior.
- Aprobaciones legales, fiscales, comerciales pendientes y proveedor financiero.

## Hallazgos y aceptación

No se presentan los reintentos secuenciales como prueba de dos escritores concurrentes.
El contrato requiere un adaptador persistente todavía inexistente. El modo committed
es conceptual y no es prueba de pago, confirmación real ni reserva productiva.

| Severidad | Estado | Hallazgo / resolución |
| --- | --- | --- |
| P0/P1 | Sin hallazgos pendientes confirmados | Dentro del alcance sintético autorizado. |
| P2 | Resuelto durante autorrevisión | Muestrear reloj antes de decidir, no usar solo la hora de entrada; prueba de cruce de vencimiento al convertir. |
| P2 | Resuelto durante autorrevisión | Incorporar reconstrucción validada sin Reflection ni renovación de ID/plazo; prueba de estados, datos corruptos y vínculos reemplazados. |
| P3 | Resuelto | Dos fixtures reutilizaban UUID para intenciones distintas; se corrigieron datos, no las reglas. |
| P3 | Resuelto | No condicionar la suite a que B1 permanezca ausente en futuras integraciones. |

La autorización real, durabilidad y concurrencia no se clasifican como defectos
resueltos: quedan expresamente NOT RUN / no implementadas por alcance. El engine
no valida calendario futuro, políticas fiscales o aceptación B1 contra datos reales.

No hay modificaciones de archivos existentes, publicación remota, nuevo PR ni
cambios a PR #28/#31/#32. La rama local B1 sigue en
`a7efd0e353435c46c633f02120d131507bdc7516`; main remoto se volvió a verificar en la base.

Próximo incremento recomendado: autorizar una revisión específica B2.2 del esquema,
reconstitución y adaptador persistente, con pruebas sintéticas de dos conexiones.
La integración al producto y cualquier migración necesitan autorización posterior.

## Inventario completo de archivos nuevos

- `app/Scheduling/Holds/AcceptedQuoteReference.php`
- `app/Scheduling/Holds/HoldActor.php`
- `app/Scheduling/Holds/HoldClock.php`
- `app/Scheduling/Holds/HoldEvent.php`
- `app/Scheduling/Holds/HoldReceipt.php`
- `app/Scheduling/Holds/HoldState.php`
- `app/Scheduling/Holds/HoldStore.php`
- `app/Scheduling/Holds/HoldTime.php`
- `app/Scheduling/Holds/HoldViolation.php`
- `app/Scheduling/Holds/ReservationProposal.php`
- `app/Scheduling/Holds/SlotHold.php`
- `app/Scheduling/Holds/SyntheticHoldEngine.php`
- `app/Scheduling/Holds/SystemHoldClock.php`
- `app/Scheduling/Holds/UtcInterval.php`
- `docs/payments/B21-SYNTHETIC-HOLDS.md`
- `docs/payments/B21-VALIDATION.md`
- `docs/payments/evidence/B21-validation.json`
- `tests/Feature/SyntheticHoldIsolationTest.php`
- `tests/Support/InMemorySlotHoldStore.php`
- `tests/Support/MutableSlotHoldClock.php`
- `tests/Unit/SlotHoldReconstitutionTest.php`
- `tests/Unit/SyntheticSlotHoldEngineTest.php`
- `tests/Unit/UtcServiceIntervalTest.php`
