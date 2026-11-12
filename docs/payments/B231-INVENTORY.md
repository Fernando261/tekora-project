# B2.3.1 — Inventario final

37 archivos nuevos; cero modificaciones históricas y cero eliminaciones. Base `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.

El presupuesto de diseño era 30 rutas orientativas. Se entregan 37: se añaden clases de errores, reloj/arnés y peer de procesos explícitos y matrices/inventario de evidencia para revisión; no se amplía a componentes productivos.

| N.º | Archivo |
| --- | --- |
| 1 | `docs/payments/B231-INVENTORY.md` |
| 2 | `docs/payments/B231-RISK-STATUS.csv` |
| 3 | `docs/payments/B231-SHARED-SESSION.md` |
| 4 | `docs/payments/B231-TEST-RESULTS.csv` |
| 5 | `docs/payments/B231-VALIDATION.md` |
| 6 | `docs/payments/evidence/B231-validation.json` |
| 7 | `tests/PaymentsIntegrationPrototype/ContentionTest.php` |
| 8 | `tests/PaymentsIntegrationPrototype/JointRollbackTest.php` |
| 9 | `tests/PaymentsIntegrationPrototype/OccupancyHistoryTest.php` |
| 10 | `tests/PaymentsIntegrationPrototype/QuoteBindingTest.php` |
| 11 | `tests/PaymentsIntegrationPrototype/RecoveryProcessesTest.php` |
| 12 | `tests/PaymentsIntegrationPrototype/SharedSessionTest.php` |
| 13 | `tests/Support/PaymentsIntegration/CommandEnvelope.php` |
| 14 | `tests/Support/PaymentsIntegration/FixtureClock.php` |
| 15 | `tests/Support/PaymentsIntegration/IntegrationBusy.php` |
| 16 | `tests/Support/PaymentsIntegration/IntegrationTestCase.php` |
| 17 | `tests/Support/PaymentsIntegration/IntegrationUncertain.php` |
| 18 | `tests/Support/PaymentsIntegration/IntegrationWorker.php` |
| 19 | `tests/Support/PaymentsIntegration/OwnedIntegrationLab.php` |
| 20 | `tests/Support/PaymentsIntegration/ProcessPeer.php` |
| 21 | `tests/Support/PaymentsIntegration/ReferenceLoader.php` |
| 22 | `tests/Support/PaymentsIntegration/ScenarioFixture.php` |
| 23 | `tests/Support/PaymentsIntegration/SessionLease.php` |
| 24 | `tests/Support/PaymentsIntegration/SessionSqlExecutor.php` |
| 25 | `tests/Support/PaymentsIntegration/SharedCommandRepository.php` |
| 26 | `tests/Support/PaymentsIntegration/SharedHoldHistoryRepository.php` |
| 27 | `tests/Support/PaymentsIntegration/SharedOccupancyRepository.php` |
| 28 | `tests/Support/PaymentsIntegration/SharedPricingRepository.php` |
| 29 | `tests/Support/PaymentsIntegration/SharedProposalBindingRepository.php` |
| 30 | `tests/Support/PaymentsIntegration/SharedSqliteUnitOfWork.php` |
| 31 | `tests/Support/PaymentsIntegration/SqliteOutcomePolicy.php` |
| 32 | `tests/Support/PaymentsIntegration/TransactionOutcome.php` |
| 33 | `tests/Support/PaymentsIntegration/WriteSession.php` |
| 34 | `tests/Support/PaymentsIntegration/fixture-schema.sql` |
| 35 | `tests/Support/PaymentsIntegration/references.json` |
| 36 | `tests/payments-b231-bootstrap.php` |
| 37 | `tools/payments-b231/export_references.py` |

28 PHP nuevos bajo tests (incluido bootstrap), un SQL de fixture, un manifest de referencias JSON y un exportador Python; seis documentos/matrices/evidencias. Ningún archivo de app, rutas, migraciones, dependencias o configuración productiva. Las fuentes B1/B2.1/B2.2.1/B2.2.2 se exportan fuera del commit.
