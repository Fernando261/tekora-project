# UX-C1 — Seguimiento de contratación

El detalle de la solicitud es el punto de seguimiento web compartido por cliente y prestador. `ContractingJourney` proyecta los datos actuales dentro de una transacción de lectura; no persiste etapas ni sustituye a los workflows. Las cinco etapas son Solicitud, Acuerdo, Horario, Confirmación y Servicio. Cierre y reseña se consultan al final del mismo seguimiento.

## Decisiones

- La participación determina el contexto, también en cuentas duales. La API limita primero por propietario cliente; la policy de web conserva la participación del prestador.
- Cotización aceptada, propuesta, plazo de confirmación y reserva definitiva son distintos. El importe acordado procede de la cotización vinculada, no del precio publicado más reciente. Las reservas legacy conservan su protocolo original.
- La realización requiere el registro explícito existente y el cierre conserva las reglas de `inquiryCompletionBlocker`. El paso del tiempo jamás realiza un servicio. Las reseñas publicadas y retiradas conservan sus registros originales.
- Se mantienen conversación, formularios, historial de decisiones, cotizaciones y reservas. El resumen solo enlaza a acciones reales; no añade comandos de dominio. Ante falta de disponibilidad o permisos muestra una espera, no una acción ficticia.
- Los formularios iniciados desde el seguimiento propagan un indicador `_journey=1`; las pantallas intermedias usan `follow=1`. Solo se admite ese valor y el retorno se calcula en el servidor con el ID autorizado. No se aceptan URLs de retorno. Los indicadores se excluyen del comando de dominio; los demás parámetros desconocidos continúan rechazándose. Las rutas abiertas directamente conservan su redirección anterior.
- Cada comando sigue revalidando autorización, versión, idempotencia, disponibilidad y vigencia en su workflow. La proyección informativa no garantiza admisión y puede quedar desactualizada; se recarga al completar una acción.
- El GET móvil no reconcilia vencimientos ni marca mensajes leídos. La web conserva la reconciliación que ya realizaba. Los errores de almacenamiento o acuerdos inconsistentes no se presentan como progreso vacío.

## Confirmación y pagos futuros

El checkout futuro se integrará después de que el prestador acepte el horario y antes de la confirmación definitiva. UX-C1 utiliza exclusivamente las operaciones B2 vigentes y sus **900 segundos**, sin ampliarlos. No introduce botón de pago, estado financiero, tabla, migración ni dependencia.

B3.1 deberá definir la verificación bancaria antes de confirmar con tarjeta. La futura política de efectivo permitirá elegirlo al confirmar, con cobro pendiente hasta la entrega tras el servicio y la confirmación autorizada de recepción. Ninguna de esas políticas está activada aquí. **B3.1-D permanece aislado y no se importa ni se conecta a este recorrido**. La demo de checkout no es evidencia financiera.

## Comportamiento visual

Se reutilizan el layout, sidebar, tipografías, paleta y mejoras de movimiento de operaciones. CSS nuevo limitado a `.contracting-hub`/`.cj-*`. Navegación y formularios nativos funcionan sin JavaScript. El progreso incluye texto de estado, no solo color, y `aria-current` para la etapa actual. Se conserva foco visible y se respeta movimiento reducido. No hay formulario duplicado para confirmar una reserva.

## Validación y límites

Las pruebas de UX-C1 recorren comandos reales y comparan el modelo compartido con la web y API; comprueban propiedad, cuentas duales, estados alternativos, vigencia, reintentos, efectos y oráculos SQLite. Los resultados exactos, JUnit, capturas e inventario se entregan fuera del repositorio. La suite completa sigue separada del laboratorio T1.

No se certifican aquí dispositivos físicos, aplicación móvil nativa, lectores de pantalla reales ni Windows/Laragon. No se modifica configuración de bases existentes ni se activa ningún proveedor de pagos.
