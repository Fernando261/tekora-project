# TEKORA B2.2.1 — Persistencia SQLite B1 sintética

Prototipo aislado, sin binding de aplicación. Corrección local **B2.2.1-fix1**, pendiente de nueva revisión independiente; resultados en [B221-FIX1-VALIDATION.md](B221-FIX1-VALIDATION.md).

Base de rama: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
Referencias verificadas: PR #32 `a7efd0e353435c46c633f02120d131507bdc7516`; PR #33 `815879d448e17ef8955354c6a275201ba6f350fe`. Ambos seguían abiertos/en borrador y no fusionados al preparar el trabajo.

Se consultó `/workspace/tekora-b22-design-6gqtanqr/TEKORA-B22-DESIGN.md`. Esta entrega implementa solo persistencia de precios, quotes y aceptaciones B1, conforme a la autorización limitada posterior. No implementa las ocho entidades ocupacionales del diseño, HoldStore, claims, coordinador, evolución de reservas o API v2.

## Dependencia exacta de B1 sin fusionarlo ni copiarlo al historial

Main no contiene App\Payments. El prototipo implementa **el PricingStore real del PR #32**, cargado únicamente durante tests desde una exportación externa de sus blobs Git exactos.

- `tools/payments-b221/export_b1_reference.py` exporta nueve clases B1 y tres fuentes de pruebas originales a un directorio nuevo fuera del repositorio; rechaza sobrescribir un destino existente.
- `tests/Support/PricingSqlite/b1-reference.json` fija commit y doce SHA-256 de código; no contiene datos o medios.
- `B1Reference` comprueba todas las huellas antes de registrar un autoloader limitado. Falta de referencia o alteración detiene el bootstrap, sin fallback a otra versión.
- No hay copias versionadas de App\Payments, cherry-pick, merge ni redefiniciones locales de sus DTO/contratos. La exportación temporal de fuentes para validación no forma parte del commit.
- Las pruebas B1 originales se ejecutan sin modificarlas y conservan InMemoryPricingStore; validan el núcleo/autorización B1 con fixtures operativos **RAM**, no una integración del nuevo adaptador con solicitudes reales.
- Las pruebas del adaptador usan solo identidades inventadas en tablas b221_fixture_*. No consultan Eloquent, Gate, ReservationWorkflow o tablas operativas.

## Arquitectura y almacenamiento

Todo el PHP nuevo vive en `tests/`, sin imports desde app/routes/providers. El esquema real del prototipo se encuentra en [schema.sql](../../tests/Support/PricingSqlite/schema.sql). No es una migración Laravel y no se registra en Artisan.

| Componente | Responsabilidad |
| --- | --- |
| SyntheticPricingDatabase | Crea directorio aleatorio nuevo y archivo exclusivo, inicializa solo ese esquema, genera conexiones propias. No recibe DSN/ruta de base existente. |
| SqlitePricingStore | Implementa todos los métodos PricingStore: transacción, precio vigente/por intención, append de precio, quote/por intención, append de quote, aceptación y append de aceptación. |
| PricingValues | Reconstituye snapshots y valida UUID, tipos almacenados, metadatos comerciales y UTC sin casts monetarios con pérdida. |
| SqlitePricingBusy | Identifica agotamiento de contención y fase begin/body/commit. |
| PricingFixture | Identidades sintéticas y comprobaciones integrity_check/foreign_key_check. |

La conexión debe proceder de la misma fábrica, registrada por identidad de objeto en un WeakMap. Un PDO ajeno —incluso perteneciente a otro fixture nuevo— se rechaza. Se comprueba identidad de archivo antes de reabrir; no se adopta ni se recupera una ruta de instalación. Los directorios/bases creados se retienen para inspección; no existe API de purga, rollback de esquema o limpieza de raíces históricas. La fábrica no pretende demostrar seguridad del filesystem frente a procesos host ajenos.

## SQL efectivo

Siete tablas **STRICT** en main:

1. `b221_fixture_users`.
2. `b221_fixture_providers`.
3. `b221_fixture_services`.
4. `b221_fixture_inquiries`.
5. `b221_price_versions`.
6. `b221_quotes`.
7. `b221_acceptances`.

Las cuatro primeras son exclusivamente referencias sintéticas, no tablas de la aplicación ni una copia de sus migraciones. Su identidad también es append-only, para no reinterpretar después los snapshots.

- IDs UUID/keys en TEXT COLLATE BINARY, canónicos minúsculos. Los seis campos UUID exigen tipo TEXT, ausencia de NUL y exactamente 36 bytes además del formato previo. PK declaradas NOT NULL y UNIQUE de servicio/versión, servicio/intención y cliente/intención.
- FK RESTRICT entre fixtures, precio, quote y aceptación, incluidas claves compuestas de precio/importe/servicio/prestador y de inquiry/servicio/cliente.
- Propietario del precio coincide con usuario del prestador sintético. Cliente no puede ser prestador de su propia quote.
- MXN, duración 60, synthetic=1, política B1 fija, 1.500/8.500 bps y límite monetario técnico B1. Ese límite no se aplica a los IDs históricos.
- Comisión entera: `(total / 100) * 15 + (((total % 100) * 15 + 50) / 100)` con operandos INTEGER acotados; prestador es residual. CHECK conserva total y redondeo half-up. No impuestos/costos inventados como cero.
- UTC de 26 caracteres ASCII `YYYY-MM-DD HH:MM:SS.ffffff`, rango técnico 2000..2100, calendario y seis microsegundos validados. Los cuatro campos exigen tipo TEXT, ausencia de NUL y 26 bytes completos. El adaptador normaliza offsets a UTC conservando el instante; ese formato aprobado no se amplía.
- Quote emitida no antes del precio; vigencia entre 1 y 86.400 segundos conforme al constructor B1. Fixture ordinario: 1.800 s. Aceptación cumple `issued <= accepted < expires`.
- Guard de precio exige exactamente MAX(version)+1. Guards de inserción rechazan sustituciones por ID/intención; el de prestadores sintéticos cubre tanto id como user_id. Los de UPDATE/DELETE impiden modificar o borrar historia. El rechazo de REPLACE no depende de activar recursive_triggers; se auditaron las claves únicas alternativas.
- Nueve índices explícitos de consulta/FK, además de los índices automáticos de PK/UNIQUE. Ningún trigger se crea sobre tablas históricas.

STRICT/typeof valida el valor persistido; la afinidad de SQLite no certifica el tipo original de entrada. PricingValues valida además los DTO y tipos reconstituidos. No se presenta la fábrica de fixtures como autoridad de autenticación real.

## Transacciones y contención

`atomic()` posee una única transacción `BEGIN IMMEDIATE … COMMIT`, verifica FK activas y rechaza reentrada, transacciones externas y conexiones marcadas no recuperables. No se integra con el contador de transacciones de Laravel.

- El bloqueo se adquiere antes de llamar al callback.
- BEGIN con SQLITE_BUSY: hasta tres intentos, busy_timeout=0 y backoff acotado de 1 y 2 ms. El callback aún no se ejecuta.
- COMMIT con SQLITE_BUSY: reintenta **el mismo COMMIT**, nunca el cuerpo. Al agotarse, hace rollback conocido y devuelve SqlitePricingBusy.
- Error de cuerpo: rollback integral. BUSY de cuerpo se distingue y no se vuelve a ejecutar automáticamente código arbitrario; el consumidor debe reintentar la intención completa.
- Otros códigos (incluido LOCKED) no se reclasifican ciegamente como BUSY. Una restricción de inserción se convierte en PricingConflict.
- Si no puede establecerse rollback, se invalida la conexión para todos sus adaptadores, se rechaza volver a adoptarla y se exige reconectar; no se declara éxito ni rollback demostrado.
- La política no llama servicios de red y no incorpora outbox/efectos externos. Atomic devuelve el valor del callback solo después de commit.

Se usa journal DELETE y synchronous FULL en las bases nuevas. Las pruebas de dos conexiones provocan locks reales y BUSY tanto en BEGIN como en COMMIT, con intercalado controlado. No son dos procesos concurrentes ni pruebas WAL/recuperación física.

## Inmutabilidad e idempotencia

El store no sustituye al servicio B1: append rechaza duplicados; el consumidor B1 recupera primero por la clave de intención. Esa consulta y la posible inserción se realizan dentro de atomic. Al reabrir otra conexión se recuperan el mismo ID, versión, importes y fechas.

`latestPrice` usa ORDER BY version DESC, no orden de inserción accidental. Una quote se compara con el precio registrado **por todos sus valores**, incluyendo metadatos/fechas, no por identidad de objeto PHP. Una nueva instancia reconstituida válida es aceptada; una con el mismo ID y metadatos alterados es rechazada.

`quoteByKey(client,key)` mantiene el aislamiento del ámbito cliente. `quote(id)` y `acceptance(id)` son métodos internos del contrato, no endpoints de autorización; la autenticación/ownership real continúa pendiente de integración. La aceptación histórica se conserva aunque la quote haya vencido; una segunda inserción no cambia su instante.

## Relación futura con B2.1

- `SyntheticQuote.id` real es UUID; `AcceptedQuoteReference.id` deberá tomar exactamente ese ID, validado contra aceptación B1.
- QuoteAcceptance se identifica por quote_id y cliente; no genera otra identidad comercial ni equivale a aceptación del prestador.
- Inquiry/cliente/servicio/prestador se derivarán de referencias autoritativas; aquí solo se demuestran en fixtures b221_*.
- La quote no incorpora reserva ni horario. Un futuro binding append-only relacionará quote aceptada, reserva concreta y proposalVersion con intervalo UTC exacto, sin modificar el snapshot B1.
- ProviderProfile y su usuario no son IDs intercambiables. El contrato de referencia B2.1 contiene ambos; deberá comprobarse su identidad real al integrar.
- Ni quote ni aceptación crean un hold en este prototipo. Los 1.800 s de quote siguen independientes de los 900 s del hold; no se ha decidido aquí elegibilidad adicional de quote en T, antelación mínima o cancelaciones.

No se añade HoldStore ni persistencia de retenciones. B2.2.2 no está implementado. Las condiciones legales, fiscales y del proveedor financiero permanecen pendientes.

## Reproducción

Ejecutar solo en una copia de fuentes sin .env*, medios, storage o bases de la instalación. El entorno requiere PHP x64, PDO SQLite >=3.38, dependencias existentes y el commit B1 disponible en Git. No instalar dependencias ni ejecutar Composer scripts que migren la aplicación como parte de este protocolo.

```sh
python3 tools/payments-b221/export_b1_reference.py /ruta/externa/nueva/b1-reference
export TEKORA_B221_B1_REFERENCE=/ruta/externa/nueva/b1-reference
# Opcional: parent existente preparado exclusivamente para este ensayo;
# la fábrica siempre crea un subdirectorio nuevo, nunca abre una base recibida.
export TEKORA_B221_LAB_PARENT=/ruta/temporal/propia
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php tests/PaymentsSqlitePrototype
php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b221-bootstrap.php "$TEKORA_B221_B1_REFERENCE/tests"
php -d memory_limit=1G vendor/bin/phpunit
php vendor/bin/pint --test tests/Support/PricingSqlite tests/PaymentsSqlitePrototype tests/payments-b221-bootstrap.php
```

**La suite estándar de main no descubre el directorio PaymentsSqlitePrototype.** Es intencional para mantener este laboratorio fuera del arranque operativo: el primer comando es obligatorio para validar B2.2.1. El segundo prueba B1 original sin adaptar su store. El tercero reproduce las regresiones históricas compatibles; no se declara que los tres sean una ejecución combinada de integración.

Resultados históricos originales en [B221-VALIDATION.md](B221-VALIDATION.md); correcciones P2/P3, regresiones y resultados fix1 en [B221-FIX1-VALIDATION.md](B221-FIX1-VALIDATION.md). Las bases de pruebas no son archivos versionados ni se incluyen en el commit.
