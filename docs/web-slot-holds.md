# Retención web de horarios (B2)

## Flujo y estados

Los servicios que ya tienen precio publicado requieren una cotización aceptada de la misma solicitud, servicio, cliente y prestador para cada propuesta nueva. La aceptación de la solicitud y de la cotización no ocupan el horario. El prestador acepta **una propuesta concreta** y crea una retención de exactamente 900 segundos UTC; después el cliente confirma antes del vencimiento y del inicio del servicio. No hay pagos.

| Estado efectivo | Acción permitida | Resultado |
| --- | --- | --- |
| `requested` | Prestador acepta | `held`; empieza el único plazo de 900 s |
| `requested` | Rechazar o cancelar, según rol | Terminal; sin retención |
| `held` | Cliente confirma | `confirmed`, conversión atómica |
| `held` | Cancelar, según rol | `hold_released`; horario liberado |
| `held` al alcanzar el vencimiento | Reconciliación del servidor | `hold_expired`; horario libre |
| `confirmed` | Cancelar antes del inicio | `cancelled`; horario libre |
| `confirmed` después de terminar | Prestador marca realizado | `completed`; habilita las reglas vigentes de cierre y reseña |

Un estado terminal no se reactiva. El contador es orientativo: el servidor vuelve a comprobar la vigencia. Recargas, claves repetidas y otros dispositivos no modifican `accepted_at_us` ni `expires_at_us`. La vigencia de emisión de cotizaciones es independiente: una cotización ya aceptada conserva importe, versión, comisión del 15 % y snapshot aunque cambie el precio publicado.

## Autoridad y persistencia

La nueva migración es aditiva; las 38 anteriores permanecen idénticas. Añade vínculo inmutable `quote_id`, cuatro tablas y dos vistas, y sustituye restricciones históricas mediante nuevos triggers. Las filas históricas conservan `quote_id=NULL` y su flujo anterior, incluso si después se publica un precio. Los servicios sin precio conservan la confirmación directa del prestador.

`reservation_occupancy` reúne reservas confirmadas y retenciones vigentes de **todos** los servicios del prestador, con intervalos UTC semiabiertos `[inicio, fin)`. Workflow, vistas y triggers usan esa autoridad. La confirmación y el cambio de estado de la retención ocurren en la misma sentencia/transacción; un fallo revierte ambos. Los triggers también impiden bypass mediante escritores anteriores o SQL directo que conserve las restricciones.

Los estados de retención son derivados: una propuesta física `requested` con hold puede mostrarse `held`, `hold_expired` o `hold_released`; no se reconstruye la tabla histórica. Consultar `effectiveStatus()`/scope `active()`, no interpretar por separado el estado físico. El historial de holds conserva actor, instante y versión, además del historial de reservas y sus notificaciones. La confirmación comercial notifica al prestador.

Requiere SQLite, PHP de 64 bits, FK habilitadas y la función de reloj registrada por `ReservationServiceProvider` en cada conexión. PDO SQLite recibe microsegundos como texto decimal y SQL los convierte a entero para evitar truncamiento de 32 bits. Una conexión SQL externa sin esa función falla cerrada. Un watermark persistido evita retroceder tras una hora ya admitida; las escrituras se bloquean si el reloj retrocede. No elimina la necesidad operativa de un reloj del servidor correcto.

## Transacciones y recuperación

Cada comando obtiene primero el bloqueo de escritura, luego valida y ejecuta una vez. No se repite el callback por fallos de BEGIN/COMMIT. Una transacción manual/raw PDO ajena se rechaza sin confirmarla ni revertirla. Para composición existente con Illuminate se usa exclusivamente un savepoint: el llamador conserva el COMMIT/rollback físico y la custodia de su conexión. El retorno de un comando anidado no prueba que el llamador haya confirmado.

En transacción raíz, un COMMIT incierto o una limpieza fallida retiran la conexión; no se permite reconectar ese objeto. Una PDO incierta se retiene en cuarentena. En workers persistentes se debe retirar/reiniciar el worker ante esa incertidumbre; la cuarentena no es un pool reutilizable. En composición con savepoint, la incertidumbre se comunica al propietario de la transacción raíz, que debe retirar su conexión/worker. No hay recuperación física automática.

Las decisiones comerciales usan UUID de idempotencia y versión. Reintentar desde una conexión nueva **con la misma clave y contenido** permite consultar el recibo y estado persistido; si el primer COMMIT ocurrió, no duplica trabajo ni renueva el hold. Cambiar contenido con la misma clave se rechaza. Ante 503 incierto, consultar la reserva antes de crear otra operación.

## API móvil y compatibilidad

Contrato OpenAPI **1.2.0**, `docs/api/openapi.json`. Las opciones de reserva incluyen identificadores de cotizaciones aceptadas y URL web para consultar/aceptar cotizaciones; no se inventa una API móvil de precios. La propuesta comercial envía `quote_id`; las decisiones comerciales envían `version` e `idempotency_key`. El cliente finaliza mediante `PATCH /api/v1/reservations/{reservation}/commit` después de la aceptación del prestador en web. La representación comercial añade estado efectivo, hold, vencimiento y URL de confirmación; la representación histórica/sin precio se conserva.

Un móvil antiguo que no envíe `quote_id` recibe validación explícita antes de crear una propuesta comercial. Debe actualizarse o usar la web; no existe una ruta antigua que evite la retención. Mensajería, reseñas y cierre de solicitudes mantienen sus reglas. Los inventarios de esquema y restauración incorporan las nuevas tablas, vistas y triggers.

## Aplicación y límites

La migración comprueba integridad, FK y ausencia de solapamientos confirmados antes de adaptar los guards. Su reversión se niega si existe cualquier propuesta comercial o historial de retención, incluso vencido. Este incremento **no aplica migraciones en instalaciones existentes**; requieren respaldo y revisión antes de una autorización posterior. Se han usado únicamente SQLite nuevas/en memoria y datos sintéticos.

No incluye pagos, cron obligatorio, despliegue, pruebas de Windows, certificación WAL ni recuperación ante caída física. La evidencia reproducida y las versiones reales se entregan fuera del repositorio. La suite T1 aislada conserva sus archivos y dependencias; no forma parte de la suite funcional Laravel.
