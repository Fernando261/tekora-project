# Tekora V8.1.3 — Portabilidad del esquema de reservas a MySQL 8.4

> **Estado: especificación inicial — aún NO implementado.** No acredita paridad comercial ni autoriza despliegue en VPS. Preparado después de integrar PR #61; HEAD base `f7ab348815c051187e33f9500b82bf268c4ded1c`.

## Alcance acotado

Portar a InnoDB la identidad de migración `2026_11_03_000000_create_service_reservations.php` en `database/mysql/migrations/`, con tablas `service_reservations` y `reservation_events`, protección de integridad de identidad/participantes, historial, reglas de transición, exclusividad de reserva activa por solicitud, prevención de solapamientos de reservas confirmadas y bloqueo de alteraciones/destrucción de historial.

**No** quitar el guard SQLite de la migración histórica. No ejecutar estas variantes con el migrador ordinario sin una estrategia de selección por driver auditada. El laboratorio MySQL seleccionado debe crecer de **34 a 35 identidades**, preservando su socket aislado, autenticación efímera, `--network none`, cero puertos publicados, binlog desactivado **solo en laboratorio** y checks de JUnit.

La aplicación actual depende además de otras migraciones aún pendientes: cotizaciones/precios, avisos de actividad, reseñas y holds B2. Por tanto, crear el esquema de reservas **no demuestra** todavía el recorrido completo de contratación ni permite anunciar la VPS como lista. Si se desea probar provisionalmente flujos de reservas que aún dependen de esas tablas, limitarse a pruebas de integridad del esquema y operaciones soportadas, indicando explícitamente qué queda BLOCKED.

## Inventario de invariantes SQLite a conservar

1. `service_inquiry_id`, `service_id`, `client_user_id` y `provider_profile_id` deben pertenecer al mismo servicio/solicitud y a participantes válidos; no confiar en el cliente.
2. Se exige solicitud aceptada para una reserva activa; la vinculación de una solicitud con su servicio y cliente y la del servicio con su prestador no se pueden cambiar después de reservar.
3. En cada solicitud, solo una reserva `requested` o `confirmed` puede coexistir. Permitir historial terminal sin duplicar la activa. Usar un constraint InnoDB válido, p. ej. columna generada condicional + UNIQUE, y probar colisiones entre procesos.
4. Una misma disponibilidad de prestador no puede tener dos reservas **confirmadas** superpuestas. Intervalos UTC semiabiertos: `a.start < b.end && b.start < a.end`; tramos contiguos son válidos. Un `SELECT` común antes del insert no basta: definir y demostrar un orden de mutex/transacciones InnoDB por prestador y solicitud, además de restricciones/triggers relevantes ante escrituras SQL directas.
5. Campos civiles y UTC: zona IANA validada en la aplicación, fecha civil del prestador, minuto de inicio, duración exactamente 60 minutos, coherencia de UTC, rangos y formato de tiempo. Evitar conversiones silenciosas de zona horaria de `TIMESTAMP`; preferir tipos y sesión UTC que preserven la semántica exacta. Probar cambio de fecha local, DST y límites horarios.
6. Alta inicial: `requested`, versión 1, actor cliente y motivo `proposal`. Las transiciones permitidas y el actor autorizado deben reflejar el esquema original (`confirm`, `reject`, `cancel`, `complete`, `inquiry_completed`), versiones estrictamente crecientes, timestamps monotónicos e invariantes temporales.
7. La identidad y los atributos originales de propuesta son inmutables. No borrar reservas ni reescribir/borrar eventos. Insertar un evento de historial en la misma transacción que cada alta o transición; obligar a que coincidan actor, versión, estado y momento.
8. Bloquear cerrar una solicitud aceptada mientras conserve una reserva activa; no permitir alterar identidad de solicitud, servicio o prestador cuando existan reservas.
9. Reintentos: idempotencia `(client_user_id, service_inquiry_id, idempotency_key)`, errores de unicidad y claves foráneas, deadlock/retry cuando proceda. No duplicar eventos ante timeout/reintento.
10. Rollback atómico de reserva + evento + avisos que apliquen. Distinguir incertidumbre de commit vs rollback confirmado y no dar al usuario un éxito inventado.

## Precauciones de implementación

- `ReservationTransactions` es **solo SQLite** actualmente, también tiene una admisión basada en `reservation_hold_clock` (todavía no existe en MySQL). No cambiarla a MySQL por eliminar la condición del driver. Diseñar el adaptador de transacciones y el orden de locks antes de habilitar `ReservationWorkflow` completo. No prometer un flujo productivo si todavía depende de retenciones/cotizaciones.
- `ServiceReservation::scopeActive()` utiliza `reservation_active_bookings` (vista creada por la migración B2 posterior). El modelo puede existir antes de esa vista, pero **las lecturas de reservas activas permanecen bloqueadas en MySQL** hasta portar B2 y sus vistas.
- El motor de trigger que verifica no solapamiento debe leer el estado actual incluso bajo una instantánea anterior y no permitir bypass por SQL directo. Documentar el riesgo de deadlock y el orden de serialización entre `service_inquiries`, `provider_profiles`, `service_reservations` y sus eventos.
- `CHECK` y claves en InnoDB deben reproducir garantías sin basarse en coerciones permisivas de MySQL; usar `STRICT_TRANS_TABLES`, charset y collations correctos.
- En MySQL el DDL puede hacer commit implícito. El laboratorio en esquema nuevo se elimina en caso de fallo; fuera del lab requerirá un protocolo explícito de recuperación de instalación parcial. No alterar bases reales.

## Cobertura mínima de laboratorio

- Instalación fresca **35/39** con ruta de variantes seleccionada; segunda migración idempotente; reinicio de contenedor con reserva/eventos persistentes.
- Pruebas de escritura SQL directa: identidad ajena, solicitud no aceptada, estados iniciales incorrectos, transición no autorizada, inserción de evento inconsistente, modificación de campos inmutables y DELETE rechazado.
- Pruebas entre procesos: dos propuestas activas de la misma solicitud, dos confirmaciones solapadas del mismo prestador, confirmaciones con horarios contiguos, dos transiciones/versiones concurrentes, bloqueo de identidad y cierre de solicitud.
- Transacción que falla tras escribir la reserva y antes de confirmar el historial; no debe persistir efecto parcial. Deadlock real con retry controlado cuando aplique.
- Repetir fundamentos MySQL 8.4 existentes y todas las regresiones SQLite, API móvil, T1, Pint, lint, Vite y CI.
- Extraer los controles de triggers de un entorno con `log_bin` activo para la fase de aprovisionamiento VPS (separado del laboratorio sin red). No declarar validado hasta comprobarlo realmente.

## Entrega y puerta de salida

Entregar en **un PR en borrador** y únicamente después de implementar código y pruebas: SHA, base, migración de variante InnoDB, control de transacciones cuando aplique, JUnit (MySQL y SQLite), matriz actualizada, riesgos y resultados `PASS / FAIL / BLOCKED / NOT RUN`.

Decisión admitida: `READY FOR REVIEW` para **esquema de reservas 35/39**, aunque el dominio comercial completo continúe `BLOCKED`.

Sin pagos reales, sin checkout B3.2 remoto, sin modificar el repositorio móvil, sin desplegar y sin fusionar por cuenta propia.
