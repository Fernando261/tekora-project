# TEKORA Payments B1 — Precios y cotizaciones sintéticas

Base: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
Estado: implementación sintética sin activación ni persistencia de aplicación.

## Alcance implementado

- `ServicePricing::publish(User, serviceId, input)` añade versiones del precio;
  reutiliza la policy `Service::update`, propiedad y permisos actuales del prestador.
- Entrada admitida: `amount_cents`, `expected_version` (0 para primer precio) e
  `idempotency_key` UUID. MXN y 60 minutos son valores del servidor, no campos editables.
- `SyntheticQuotes::issue(User, inquiryId, input)` consulta el precio del servidor
  para una solicitud propia aceptada y un servicio público ajeno. Solo admite el UUID.
- `view` y `accept` comprueban identidad y permisos actuales del cliente. Aceptar
  no admite campos monetarios ni modifica la solicitud o la reserva.
- El snapshot es readonly, incluidos importe, moneda, duración, versión de precio,
  política comercial, tasas, participantes y fechas. Una aceptación es un registro
  separado, también inmutable. No se recalcula el precio ya cotizado.
- `PricingStore` es un puerto sin binding ni implementación productiva. La única
  implementación está en `tests/Support/InMemoryPricingStore.php` y vive por instancia.
  No añade tablas, rutas, formularios, comandos ni endpoints de pago a la instalación.

## Precisión y límites sintéticos

`CommercialAmount` solo admite enteros PHP o cadenas decimales positivas canónicas.
Rechaza float (incluso 1.0), notación científica, signo, ceros iniciales, espacios,
booleanos, NULL, cero y negativos. No hay conversión implícita de dinero inválido.

Techo técnico del prototipo: **1.000.000.000 centavos (10.000.000 MXN)**. Es un
límite de pruebas documentado, no un ticket máximo comercial aprobado.

Política: `synthetic-commercial-v1-15pct-half-up`; 1500 puntos básicos para Tekora
y 8500 para el prestador. La comisión se redondea al centavo, mitades hacia arriba:

```text
comisión = (total div 100) * 15 + ((total mod 100) * 15 + 50) div 100
prestador = total - comisión
```

Todo el cálculo utiliza enteros y mantiene `comisión + prestador = total`.
El prestador recibe el complemento: no se redondean ambos porcentajes de forma
independiente. Ejemplo de frontera: 10 centavos => 2 comisión + 8 prestador.
100.000 centavos => 15.000 + 85.000. El residuo de redondeo no es un cargo adicional.

Los importes son **comerciales sintéticos**, no un total fiscal ni una factura.
No se representa el impuesto pendiente como cero ni se calculan costos de procesador.
Tekora absorberá el procesamiento ordinario cuando exista la implementación aprobada;
B1 no descuenta costos del 85 %. Base fiscal, devengo, redondeo comercial definitivo,
ticket mínimo y tratamiento de devoluciones permanecen sujetos a aprobación.

## Vigencia y reintentos

La vigencia de cotización es configurable al construir el servicio (1..86400 s).
El default de **1800 segundos** es un parámetro sintético, pendiente de decisión
comercial; **no representa la retención de horario de B2**.

- El servidor fija ambas fechas UTC; el cliente no puede enviarlas.
- Una nueva aceptación requiere `now < expiresAt` y solicitud aún aceptada.
- Repetir el UUID de emisión devuelve el mismo snapshot, incluso vencido: no renueva.
- Repetir una aceptación ya registrada devuelve el mismo registro, aun después del
  vencimiento; no crea una nueva aceptación ni cambia su instante original.
- Un nuevo UUID permite emitir una nueva cotización con el precio vigente.
- UUID de cotización: ámbito cliente; mismo UUID para otra solicitud es conflicto.
- UUID de precio: ámbito servicio; mismo UUID con otro importe/versión es conflicto.
- Una edición con versión obsoleta se rechaza. Un replay antiguo no sustituye la
  versión vigente. Todos los replays vuelven a autorizar al actor.

El store sintético revierte sus arrays al fallar una operación y rechaza versiones,
IDs e intenciones repetidos. Esto **no demuestra concurrencia entre procesos ni
durabilidad SQLite**. Esas garantías requieren un adaptador y esquema autorizados.

## Compatibilidad y límites

`Service`, `ServiceRequest`, rutas, OpenAPI 1.1.0, sus 21 operaciones, las 37
migraciones y los workflows operativos permanecen intactos. Un servicio sin precio
sigue usando las funcionalidades existentes; solo falla la nueva cotización sintética.
La aceptación de una cotización no acepta ni confirma una reserva, no ocupa horario,
no inicia pago y no genera obligación financiera real.

El snapshot B1 referencia una solicitud aceptada, no una reserva ni un turno. B2
deberá resolver el vínculo exacto con la reserva al diseñar la aceptación del prestador,
sin mutar el snapshot ni reutilizar cotizaciones de otra contratación. Una cotización
aceptada deja constancia del importe sintético; no convierte por sí sola la experiencia
actual en una contratación financiera. No hay API, interfaz de edición de precios,
lectura pública de precios ni persistencia habilitadas en B1.

No hay retenciones, cobros, webhooks, efectivo, ledger, liquidaciones ni adaptador
bancario. W6.2e y los PR #28/#31 quedan fuera del trabajo.

## Persistencia pendiente

[MIGRATION-PROPOSAL-B1.md](MIGRATION-PROPOSAL-B1.md) presenta la nueva migración
aditiva para autorización. **No se ha creado un archivo de migración ejecutable ni
se ha aplicado ese esquema**, tampoco como atajo dentro de las pruebas.

## Uso sintético

Las pruebas instancian explícitamente `ServicePricing` y `SyntheticQuotes` con
`InMemoryPricingStore`. Los registros de identidad, catálogo, solicitudes y reservas
se crean con fixtures en SQLite `:memory:`. No se registra un binding global.

Los comandos y resultados actuales se registran en [VALIDATION-B1.md](VALIDATION-B1.md).
