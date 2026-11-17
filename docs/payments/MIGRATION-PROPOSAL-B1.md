# B1 — Propuesta de persistencia SQLite (NO AUTORIZADA / NO APLICADA)

Esta propuesta solicita aprobación específica antes de crear/aplicar una migración.
No contiene operaciones ejecutadas ni modifica las 37 migraciones históricas.

## Cambio propuesto

Una migración nueva, aditiva, crearía exclusivamente tres tablas, sus índices y
triggers propios. No añadiría columnas a servicios, solicitudes o reservas; no
haría backfill de precios ni asumiría un precio cero para los servicios existentes.

| Tabla | Columnas propuestas |
| --- | --- |
| `service_price_versions` | id UUID; service_id FK; provider_profile_id FK; version INTEGER; amount_cents INTEGER; currency TEXT; duration_minutes INTEGER; created_by FK users; idempotency_key UUID; created_at_utc TEXT |
| `synthetic_payment_quotes` | id UUID; service_inquiry_id FK; service_id FK; provider_profile_id FK; client_user_id FK; price_version_id FK; price_version INTEGER; amount_cents INTEGER; commission_cents INTEGER; provider_cents INTEGER; currency TEXT; duration_minutes INTEGER; commission_bps INTEGER; provider_bps INTEGER; policy_version TEXT; synthetic INTEGER; idempotency_key UUID; issued_at_utc TEXT; expires_at_utc TEXT |
| `synthetic_quote_acceptances` | quote_id PK/FK; client_user_id FK; accepted_at_utc TEXT |

No PAN, CVV, tokens, claves, datos bancarios ni payloads de proveedor.

## Restricciones requeridas antes de aprobar el SQL final

- Tipos SQLite explícitos: `typeof(amount_cents) = 'integer'` y equivalentes para
  importes/tasas/versiones; rangos positivos y techo técnico consistente con B1.
- Moneda MXN, duración 60, synthetic=1 y política comercial identificada.
- Unicidad `(service_id, version)`, `(service_id, idempotency_key)` y
  `(client_user_id, idempotency_key)` para emisión de cotización.
- Una aceptación por quote_id; cliente vinculado exactamente al de la cotización.
- Comisión y participación no negativas; suma igual al importe; cálculo entero
  exacto del 15 % con la regla de redondeo del prototipo.
- Correspondencia de servicio, prestador, solicitud, cliente y versión del precio
  mediante claves/restricciones/triggers, sin confiar solo en parámetros PHP.
- Fechas UTC canónicas; expires_at > issued_at; issued_at <= accepted_at < expires_at.
- Append-only para las tres tablas: bloquear UPDATE y DELETE, incluidos cambios de
  importe, fechas, identidad y aceptación. FKs con RESTRICT, nunca eliminación en cascada.
- Política explícita para precio actual: última versión confirmada; append con
  versión esperada mediante transacción de escritura SQLite y unicidad final.
- Incrementos de versión consecutivos, sin reemplazar versiones ni usar upsert para
  sobrescribir historia. SQL parametrizado y tratamiento de SQLITE_BUSY sin duplicar.

El adaptador deberá coordinar las comprobaciones actuales de autorización y estado
con las escrituras SQLite. `FOR UPDATE` solo no demuestra aislamiento en SQLite.
No habilitar persistencia si no se pueden garantizar esas condiciones.

## Implantación y comprobaciones propuestas

1. Obtener autorización del esquema y un alcance concreto para la nueva migración.
2. Preparar SQL revisable y un adaptador de `PricingStore`, sin binding productivo automático.
3. Probar únicamente en SQLite nuevo/sintético: constraints, triggers, atomicidad,
   dos conexiones, duplicados, fallos y preservación de las tablas existentes.
4. Validar sqlite_schema, foreign_key_check y regresiones en ese entorno aislado.
5. No ejecutar migraciones sobre instalaciones existentes sin autorización posterior.

Algunos tests actuales verifican un total exacto de 37 migraciones. Una autorización
posterior para añadir la número 38 debe incluir revisar explícitamente esas expectativas
y demostrar que los 37 archivos históricos conservan sus contenidos, sin omitir tests.
En B1 no se cambia ninguna expectativa ni se añade una migración ejecutable.

La retirada de estas tablas tampoco queda autorizada. No incluir una limpieza que
elimine cotizaciones aceptadas: reversión y retención histórica requieren decisión propia.

## Pendiente

Retención legal, relación fiscal, límites comerciales definitivos y vínculo de la
cotización con una reserva en B2. Las tablas propuestas no implementan pagos reales.
