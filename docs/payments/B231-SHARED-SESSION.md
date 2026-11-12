# B2.3.1 — Laboratorio conjunto SQLite

Implementación exclusivamente sintética, para revisión independiente. No hay bindings Laravel, migraciones de aplicación, endpoints, pagos ni retenciones operativas. La decisión de publicación/integración sigue siendo del propietario. B2.3.2 no se inicia.

## Entradas y dependencias exactas

Base `ac999cea8f592ce7b94481ee30f602b67d9f5bea`. Se leyeron el diseño principal, las doce secuencias A–L, README y matrices de binding, pruebas, riesgos e inventario en `/workspace/tekora-b231-design-bgdjt1ax/`. Su autorización documental anterior queda sustituida por la autorización limitada de implementación B2.3.1. Las referencias GitHub se verificaron antes de programar:

| PR | HEAD | Uso |
| --- | --- | --- |
| #32 | `a7efd0e353435c46c633f02120d131507bdc7516` | DTO B1 y contrato PricingStore |
| #33 | `815879d448e17ef8955354c6a275201ba6f350fe` | DTO B2.1, estados, intervalos, contrato HoldStore |
| #34 | `7775938dda5440c03964009847d4a8d9d63b0805` | Codec puro PricingValues |
| #35 | `c3a3943c96d702bff3e1a9afbb13f57be789e137` | Codec puro HoldValues |

`references.json` fija 68 fuentes de código, esquema, manifest y pruebas por commit y SHA-256. El exportador solo extrae esos blobs a un destino nuevo externo. El loader verifica **todos** los archivos del manifest y el archivo de definición de cada clase canónica mediante ReflectionClass; no hay fallback a otra rama. No se copian los módulos originales al commit. Sus fábricas/stores/atomic se ejecutan únicamente en regresiones separadas.

## Una conexión y una transacción

`OwnedIntegrationLab` crea una SQLite nueva en un directorio 0700, archivo 0600 mediante `x+b`, manifiesto privado y capability por run. Verifica ruta canónica, dev/inode, UID, permisos, nlink, objetos desconocidos y symlinks. No admite una ruta de instalación. Los procesos hijos reciben el permit del mismo ensayo y abren su propia conexión. No se borran recursos automáticamente, journals ni raíces W6.2e.

Perfil probado: Linux POSIX, PHP x64, PDO SQLite, UTF-8, DELETE/FULL, FK y CHECK activas, recursive_triggers=0 y busy_timeout=0. Se bloquean SQLite <3.38, catálogo distinto, TEMP no autorizado y ATTACH. No se certifican carreras de filesystem contra procesos hostiles con el mismo UID ni inmunidad TOCTOU de PDO; esta limitación no se presenta como garantía resuelta.

`SharedSqliteUnitOfWork` es el único propietario fuerte del PDO de escritura. Abre BEGIN IMMEDIATE, toma el tiempo después del lock, crea una lease registrada por **identidad de objeto** en WeakMap, PID y epoch, y entrega WriteSession. Esta solo expone participantes y contexto. El ejecutor interno usa una referencia débil al propietario y verifica la lease en **cada** uso; no se entrega PDO/DSN/SQL/BEGIN/COMMIT al escenario. Los SQL provienen de métodos fijos de los repositorios; los valores se ligan explícitamente como INTEGER/TEXT/NULL. El constructor público de un puerto falso no lo registra como sesión autorizada.

Los puertos compañeros tienen métodos in-session, sin atomic propio:

- Pricing: contexto, versiones, quote/snapshot y consentimiento B1; igualdad completa del codec original.
- ProposalBinding: snapshot, relación financiera y CAS de la proyección sintética.
- HoldHistory: DTO/historia B2.1 completa. Proyecta explícitamente las 19 columnas que exige HoldValues y valida las relaciones adicionales mediante SQL; no suaviza el codec.
- Occupancy: claims compartidos para todos los servicios del prestador y legacy simulado, reservas y cierres.
- Command: búsqueda, fingerprint y sellado append-only de intención.

No se implementan las interfaces originales fingiendo compartir sus atomic: se añaden puertos exclusivos del laboratorio. No se llama Eloquent/Gate, ServicePricing, SyntheticQuotes, SyntheticHoldEngine ni ReservationWorkflow dentro de la UOW. Autenticación y autorización reales no quedan demostradas por los actores del fixture.

Se rechaza reentrada, otra UOW activa en el mismo proceso, sesión de otro propietario, contexto PDO desconocido y uso de puertos revocados. Antes de COMMIT se revocan los participantes; se conserva el guard global hasta terminar. close hace rollback de un contexto conocido activo, nunca COMMIT implícito. Después de incertidumbre se destruye la referencia al PDO y no se reutiliza.

## Comandos, binding y dinero

Cada comando tiene su actor legítimo: el prestador publica; el cliente emite quote y acepta/presenta propuesta; el prestador acepta esa propuesta; system convierte con evidencia financiera **solo sintética**. No se agrupan consentimientos de personas distintas para aparentar una sola operación comercial.

Precio → quote exacta → aceptación cuya PK es quoteId → propuesta expresa → binding inmutable → aceptación del prestador → hold/claim/proyección/recibo. Las identidades centrales del fixture derivan prestador/cliente/servicio/inquiry. FK compuestas y guards rechazan vínculos cruzados. Los hechos previamente confirmados no se revierten por fallar un comando posterior.

MXN, 60 minutos y centavos INTEGER de 1 a 1.000.000.000. Comisión B1 del 15 % con half-up y participación residual: `(total/100)*15 + ((total%100)*15+50)/100` con operandos INTEGER en SQL; el codec PHP original usa intdiv. No se usan floats monetarios ni se redondea independientemente el 85 %. No se calculan impuestos o procesamiento ni se representan como cero. Una versión nueva de precio no cambia una quote aceptada.

UUID TEXT minúsculo canónico, representación completa en bytes y rechazo de NUL; fechas B1 TEXT UTC `Y-m-d H:i:s.u`, rango 2000–2100, calendario normalizado exacto. Hold/intervalos usan INTEGER UTC microsegundos con el mismo rango. La conversión entre ambos formatos es entera y se mantiene el codec original.

## Tiempo y estados

Políticas **provisionales para el laboratorio**, pendientes de aprobación comercial productiva:

- Quote: 1800 segundos; al admitir la propuesta del prestador se exige `t0 < expiresAt` (S0). A igualdad se expira la propuesta y se devuelve requote_required, sin hold/activación/claim. No se exige S900 ni se renueva consentimiento.
- Hold: exactamente 900 segundos desde t0 registrado bajo lock y confirmado. Aceptar la inquiry o presentar la propuesta no crea hold.
- Quote válida en t0 conserva su importe durante el hold, aunque venza después. Nueva quote requiere nuevo consentimiento/propuesta/IDs; no sustituye historia.
- A los 899.999999 segundos puede convertirse; a los 900.000000 se expira. El reloj persistido rechaza regresiones.

Hold: `held → committed | released | expired`, versión 1→2. Committed es terminal. Claim: `held → reserved → closed`, o `held → closed`; tiene su propia versión. Proyección: requested→awaiting_payment→confirmed, con ramas expired/cancelled y cierre completed simulado. SnapshotVersion, StateVersion, HoldVersion y ClaimVersion son distintos.

Una cancelación posterior a committed cierra claim/proyección y conserva el hold committed v2 y todos sus eventos. Una finalización básica después del fin hace lo mismo. No se deduce reembolso real. El intervalo semiabierto `[start,end)` dura 3600 segundos: consecutivos permitidos; cualquier solape del mismo prestador entre servicios compite. Legacy confirmed solo se crea durante la construcción de un fixture nuevo, con manifiesto sellado y claim reserved, sin fabricar precio/consentimiento/hold. No existe flag legacy de petición.

## SQL realmente utilizado

[fixture-schema.sql](../../tests/Support/PaymentsIntegration/fixture-schema.sql) es el DDL íntegro ejecutado por la fábrica. No está conectado a Artisan. Contiene tablas STRICT/WITHOUT ROWID, PK/FK/UNIQUE/CHECK, índices de consulta y triggers propios del fixture. El inventario y conteos efectivos están en la evidencia.

- Claims apuntan al contexto/intervalo inmutable de propuesta; Head y Claim tienen FK compuestas recíprocas de identidad/fase, diferibles. CHECK condicionales impiden eludirlas mediante NULL.
- Activación, hold y claim se vinculan recíprocamente. Estado esperado del hold se deriva de la fase/motivo del claim. Legacy es una rama explícita del manifiesto cerrado.
- Cada cabecera refiere a su evento actual mediante FK diferible; triggers generan historia y comprueban cadena. Los eventos históricos no apuntan a una versión mutable de cabecera.
- Todo efecto lleva commandId con FK diferible. El recibo se inserta al final, valida referencias/actor/resultado y sella ese commandId. No se pueden añadir efectos con un recibo ya sellado.
- Guards impiden UPDATE/DELETE de historia y REPLACE por PK o claves alternativas. No se desactivan FK, CHECK ni guards para lograr resultados; recursive_triggers=0 es el perfil fijo y los guards BEFORE INSERT impiden REPLACE aun en ese perfil.
- Exclusión SQL en claims activos, más consulta bajo BEGIN IMMEDIATE; no basta UNIQUE de hora. Un writer sin claim no puede confirmar una proyección. Se conserva una sola propuesta activa por inquiry en el fixture.

La construcción de la fábrica, incluidas identidades y legacy opcional, es un proceso separado de los comandos comerciales. No hay trigger ficticio de COMMIT, flag genérico session_active, excepciones a FK ni cascadas de borrado.

## BUSY, errores y recuperación

1–3 intentos **por fase**, pausas 1/2 ms, no deadline global del driver. Solo el entero SQLite **5** permite retry de connect/admission/BEGIN/mismo COMMIT. Se conservan fase, intento y código; nunca SQL, bindings ni capability. LOCKED=6, IOERR=10, constraint=19, códigos string y extendidos como 517 no se convierten en BUSY genérico. Los extendidos de WAL requieren otra política y pruebas.

El cuerpo se invoca como máximo una vez por llamada. BUSY activo exige rollback conocido y reintento explícito del llamador; no se repite el callback. Error de observador conserva su excepción. Las denegaciones de negocio se devuelven explícitamente y pueden confirmar expiraciones legítimas junto a un recibo de rechazo; un throw estructural revierte todo.

Antes del sello y de cada COMMIT se comprueba el deadline aplicable. BUSY que cruza el límite produce aborto conocido sin cambiar t0. Un COMMIT iniciado a tiempo pero devuelto después puede estar confirmado: el resultado marca pendingTimeResolution para un hold aún held, sin anunciar elegibilidad. Resolver expiración es otra operación identificada, no otro commit presentado como parte del anterior. Committed/reserved no caduca por ese deadline.

Acuse perdido inyectado, error no reconocido de COMMIT o rollback no establecido invalidan la sesión y generan IntegrationUncertain. No se anuncia rollback seguro ni se repite trabajo. resolve obtiene nueva coordinación sobre el mismo archivo/permit/intención: recibo presente→histórico y estado actual separados; ausente con integridad válida→not_committed; conflicto/corrupción→parada. El replay puede registrar una expiración interna con identidad estable, sin nueva aceptación. Mismo key/payload conserva IDs/plazo; distinto fingerprint falla. Una denegación queda fija en su key.

Una invocación manual después de rollback demostrado puede volver a ejecutar trabajo sin efectos externos. Se demuestra unicidad de efectos confirmados, no ejecución exactamente una vez de un callback en toda su vida. No hay salida de pagos, notificaciones reales ni red.

## Compatibilidad y salida

Las 37 migraciones históricas, 21 operaciones OpenAPI 1.1.0, reservas, triggers, rutas, notificaciones, dependencias y PR existentes permanecen intactos. No se incorporan los casos externos de revisiones anteriores. Las nuevas pruebas son propias de B2.3.1; las originales se ejecutan desde blobs exactos en procesos separados, sin cambiar expectativas ni sumarlas como suite integrada.

Próximos trabajos requieren autorización propia: revisión independiente de esta entrega; después B2.3.2 para ampliar ocupación/carreras, B2.3.3 para todos los writers/gestor Illuminate/autorización real, migraciones/cohortes, web/API v2 y notificaciones. La API v1 de enums cerrados no recibirá alias ocultos. Compatibilidad real con reservas históricas, Windows/PHP 8.2, mínimo SQLite, WAL, recuperación física y driver COMMIT real siguen pendientes. Composer audit no está aprobado: antecedente HTTP 403 sin resolver. Validaciones legales, fiscales, comerciales y del proveedor pendientes.
