# TEKORA B2.2.1-fix1 — Corrección P2/P3 y validación local

Estado: **READY FOR INDEPENDENT REVIEW**. No es READY FOR PR ni aprobación de publicación/integración.

Rama: `codex/payments-b221-sqlite-pricing-prototype`.
Commit original conservado: `417fbbeea08e1222eedd362dc6af09b1b47c5b8b`.
Base arquitectónica: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
La corrección se conserva en un commit local nuevo cuyo padre es el original, sin amend, rebase o merge. El SHA corregido se identifica en Git y en la entrega local.

## Hallazgos originales y correcciones

La revisión independiente del commit original concluyó **CHANGES REQUIRED**. Sus pruebas externas dieron 22 pruebas / 72 aserciones, con nueve fallos: ocho variantes P2 y una P3. El informe, código externo y JUnit fallido se conservan en `/workspace/tekora-b221-review-l0rky3me/review`. No se modificaron esas expectativas ni sus archivos.

### P2 — Representación completa de UUID y UTC

SQLite length(TEXT) podía medir solo el prefijo anterior a NUL. La base admitía valores con bytes adicionales que PricingValues rechazaba al reconstruir. Las verificaciones estructurales seguían dando integrity_check=ok y foreign_key_check vacío.

Se reforzaron **las diez columnas**, no solo las ocho reproducciones aisladas:

| Tabla | UUID (36 bytes) | UTC (26 bytes) |
| --- | --- | --- |
| b221_price_versions | id, idempotency_key | created_at_utc |
| b221_quotes | id, price_version_id, idempotency_key | issued_at_utc, expires_at_utc |
| b221_acceptances | quote_id | accepted_at_utc |

Cada CHECK añade tipo TEXT, rechazo explícito `instr(columna,char(0))=0` y longitud completa `length(CAST(columna AS BLOB))=36/26`. Conserva los controles anteriores de longitud de caracteres, ASCII/hexadecimal, posiciones, calendario, fracciones y rangos. Las bases nuevas de la fábrica usan UTF-8, verificado en el sondeo.

No se amplía el formato aprobado: UUID hexadecimal minúsculo y UTC ASCII `YYYY-MM-DD HH:MM:SS.ffffff`. No se trunca, limpia o normaliza un registro inválido para aceptarlo. Las validaciones PHP y todos los DTO originales permanecen byte por byte intactos.

Las ocho inserciones malformadas originales ahora se rechazan por SQL. Los CHECK instalados se evalúan además directamente como predicados con valores parametrizados: diez positivos canónicos y ocho formas inválidas por columna (NUL con/sin sufijo, NUL interior, tabulador, salto de línea, control C0, carácter invisible multibyte y representación ASCII no canónica). Se comprueba su acuerdo con el validador PHP.

Esa evaluación aislada es necesaria para price_version_id/quote_id: una FK o un trigger anterior puede rechazar la inserción antes de llegar a su CHECK. Se extrae el CHECK real de sqlite_schema y se evalúa sin deshabilitar FK, triggers o restricciones; no se sustituye por una copia del predicado escrito en el test.

### P3 — Identidad de prestador y claves alternativas

El guard b221_fixture_providers_no_replace ahora rechaza colisiones tanto por `id` como por `user_id`. La reproducción `(30,130)` seguida de REPLACE `(31,130)` conserva la fila original y devuelve error. No depende de activar recursive_triggers ni de una FK que casualmente impida eliminar el prestador.

Se probaron tres colisiones (mismo ID/usuario, mismo ID/otro usuario y otro ID/mismo usuario), preservación de la identidad e inserción posterior de una pareja distinta válida. El pragma recursive_triggers se conserva sin cambios; su valor efectivo fue 0.

Auditoría de patrones equivalentes:

| Tabla | Claves únicas relevantes | Cobertura del guard |
| --- | --- | --- |
| fixture_users | PK id | id, ya cubierto |
| fixture_providers | PK id, user_id, (id,user_id) | id o user_id; corregido |
| fixture_services | PK id, (id,provider_profile_id) | id, ya cubierto |
| fixture_inquiries | PK id, (id,service_id,client_user_id) | id, ya cubierto |
| price_versions | id, (service_id,version), (service_id,idempotency_key), tupla con id | guard cubre id/versión/intención; sin cambios |
| quotes | id, (client_user_id,idempotency_key), (id,client_user_id) | id/intención de cliente; sin cambios |
| acceptances | quote_id | quote_id, ya cubierto |

Se añadieron también tres regresiones de REPLACE por claves financieras alternativas: versión de precio, intención de precio e intención de quote. Se conserva el historial completo en cada rechazo.

## Alcance exacto del cambio

Solo cambia la lógica SQL de los diez CHECK de texto y del guard de prestador. Al retirar mecánicamente esos añadidos y los dos comentarios nuevos, el SQL restante coincide exactamente con el original: importes, comisión/residual, rangos, versiones, FK, índices, otros guards y reglas de historia no se modifican.

Se añadió un único archivo PHP de regresiones; ningún PHP anterior se cambió. Los 111 casos originales B2.2.1 conservan sus fuentes y sus 360 aserciones. El manifest/exportador B1 y las doce fuentes B1 verificadas siguen intactos, sin merge/copia versionada de B1 ni B2.1.

## Resultados efectivos de fix1

| Validación | Estado | Resultado |
| --- | --- | --- |
| Nuevas regresiones P2/P3, grupo específico | PASS | 24 pruebas / 261 aserciones |
| B2.2.1 completo | PASS | 135 / 621; incluye los 111 / 360 originales |
| Pruebas externas de revisión, código/expectativas idénticos | PASS | 22 / 72; antes nueve fallos, ahora cero |
| B1 original | PASS | 82 / 4.353 |
| Suite histórica main | PASS | 789 / 10.344 |
| Pint --test | PASS | 11 PHP; solo el test nuevo fue formateado |
| Build offline | PASS con avisos | 67 módulos |
| integrity_check / foreign_key_check | PASS | ok / []; también en teardown de cada fixture con base |
| Configuración de controles | PASS | foreign_keys=1, ignore_check_constraints=0, recursive_triggers=0 sin alterar |
| Esquema | PASS | 7 tablas STRICT, 21 triggers, 9 índices explícitos/21 totales |
| Integridad de fuentes | PASS | 14 fuentes ejecutadas coinciden; 12 fuentes originales sin cambios aparte del SQL; 47 fuentes protegidas intactas |
| Migraciones/API | PASS estático | 37 migraciones históricas y 21 operaciones OpenAPI 1.1.0 intactas |

Todas las ejecuciones finales PHPUnit terminaron con **0 errores, 0 fallos y 0 omitidos**. El grupo de 24 está incluido en las 135 pruebas; las 22 externas, B1 y main son ejecuciones separadas. No se presenta la suma como una suite integrada de producción.

Runtime efectivo: PHP **8.4.27 x64**, SQLite/PDO SQLite **3.46.1**, PHPUnit **11.5.57**, Node **24.22.1**, npm **11.9.0**, Vite **6.4.5**. UTF-8, journal DELETE, synchronous FULL, busy_timeout=0. Suite main: 01:31.315 y 130,50 MB.

## Entorno y reproducción

Copia nueva: `/workspace/tekora-b221-fix1-check-m59m8_z_`.
Referencia B1 nueva, externa y de solo lectura: `/workspace/tekora-b221-fix1-check-m59m8_z_-b1`.
Se exportaron fuentes del commit original más el SQL/test corregidos, sin .env*, medios, uploads, storage de instalación o bases existentes. Cache/storage del ensayo nuevos; Docker efímero sin red, usuario 1000:1000 y vendor de solo lectura.

Las bases persistentes del prototipo se crean exclusivamente en subdirectorios nuevos de review/labs mediante su fábrica. Las regresiones main y B1 funcionales usan sus fixtures SQLite :memory:. No se abren bases anteriores ni se borran raíces históricas.

Comandos dentro de la copia aislada (prefijo Docker y argumentos exactos en el JSON de evidencia):

```sh
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype --group pricing-sqlite-fix1 --log-junit /app/review/final-regressions.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype --log-junit /app/review/final-b221.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php review/IndependentSqlReviewTest.php --log-junit /app/review/final-external.xml
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php /b1/tests --log-junit /app/review/final-b1.xml
npm --offline run build
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/review/final-main.xml
php vendor/bin/pint --test tests/Support/PricingSqlite tests/PaymentsSqlitePrototype tests/payments-b221-bootstrap.php
php review/runtime.php
```

Las pruebas externas de revisión siguen fuera del commit. La nueva regresión versionada está en `tests/PaymentsSqlitePrototype/PricingSqliteFix1Test.php`. La suite estándar main no descubre ese directorio, por lo que el comando B2.2.1 específico sigue siendo obligatorio.

Avisos conservados: proc_open/posix_spawn del helper Git de sebastian/version; Vite conserva dos referencias woff2 para resolución en runtime. Los comandos finales terminan con exit 0 y no se ha certificado carga visual de fuentes. No se modificó configuración o frontend para ocultar avisos.

## Historia y evidencias

- `docs/payments/evidence/B221-validation.json` original se conserva **sin cambios**, como evidencia del commit original y sus 111 pruebas.
- `B221-VALIDATION.md` queda identificado como registro histórico, incluyendo su revisión posterior CHANGES REQUIRED.
- El informe independiente, sus nueve fallos y su código externo se conservan sin modificaciones. Se registra la huella de ese código y del JUnit fallido.
- La evidencia actual está en [evidence/B221-fix1-validation.json](evidence/B221-fix1-validation.json), con comandos, fuente ejecutada, controles efectivos y resultados antes/después.
- Logs, JUnit, metadatos de índices y bases sintéticas propias se retienen en la copia nueva. No se calculan hashes de medios o datos reales.

## Inventario del commit de corrección: seis archivos

1. Modificado: `tests/Support/PricingSqlite/schema.sql` — dos correcciones SQL y comentarios.
2. Nuevo: `tests/PaymentsSqlitePrototype/PricingSqliteFix1Test.php` — 24 regresiones.
3. Modificado: `docs/payments/B221-SQLITE-PROTOTYPE.md` — reglas corregidas y referencia de fix1.
4. Modificado: `docs/payments/B221-VALIDATION.md` — etiqueta histórica y registro CHANGES REQUIRED.
5. Nuevo: `docs/payments/B221-FIX1-VALIDATION.md` — este informe.
6. Nuevo: `docs/payments/evidence/B221-fix1-validation.json` — evidencia actual.

La documentación y evidencia pertenecen directamente a la corrección. No se modifican archivos de aplicación, migraciones históricas, configuración, dependencias, rutas/API o ReservationWorkflow. No se añade binding, pago, proveedor o persistencia de holds.

## Hallazgos y límites

| Severidad | Estado |
| --- | --- |
| P0/P1 | Ninguno adicional confirmado en el alcance probado |
| P2 original | Corrección implementada y probada; aceptación independiente pendiente |
| P3 original | Corrección implementada y probada; aceptación independiente pendiente |
| Otros hallazgos | Ninguno adicional confirmado |

FAIL actuales: ninguno. BLOCKED en las verificaciones obligatorias: ninguno. Los fallos históricos no se borran ni se reclasifican retrospectivamente como PASS.

**NOT RUN:** nueva revisión independiente de aceptación, concurrencia entre procesos, WAL/BUSY_SNAPSHOT, recuperación física/pérdida de energía, pérdida real de conexión durante COMMIT, Windows/PHP 8.2, SQLite 3.38 mínimo declarado, integración/autorización productiva con el adaptador, auditorías Composer/npm, GitHub CI y proveedores/cobros. Las pruebas de dos conexiones de B2.2.1 siguen siendo dentro de un proceso y no acreditan esas garantías pendientes.

No se fusionan B1/B2.1 ni se intervienen los PR #28/#31/#32/#33. No hay push o PR nuevo, main permanece intacto y B2.2.2 no se implementa. Las validaciones legales, fiscales y del proveedor financiero siguen pendientes.

**Recomendación: READY FOR INDEPENDENT REVIEW del nuevo commit local.** No publicar ni declarar READY FOR PR antes de una nueva revisión independiente y autorización posterior del propietario.
