# TEKORA Payments v1.1 — B2: retención exclusiva (SOLO ESPECIFICACIÓN)

## Decisión comercial confirmada por el propietario

La retención exclusiva dura **15 minutos (900 segundos)** desde el instante UTC
en que el servidor registra **atómicamente** la aceptación del prestador y la
retención correspondiente: `hold_expires_at = accepted_at + 900 segundos`.

Aceptar y obtener exclusividad son una operación indivisible. Si no se obtiene
el horario, no se registra una aceptación exitosa ni se solicita pago electrónico.
La garantía es nueva: no existe actualmente ni se implementa en B1.

## Invariantes de B2

- Refrescar, cerrar sesión, consultar, repetir peticiones o reconectar no reinician
  ni prolongan el plazo. El reloj del servidor es la autoridad.
- Capacidad inicial propuesta: un compromiso incompatible por prestador e intervalo
  UTC `[inicio, fin)`, contemplando retenciones y reservas confirmadas de todas las rutas.
- La expiración es una transición segura y serializada con aceptación, pago y nuevas
  reservas. El barrido periódico no es la única defensa contra retenciones vencidas.
- Pago en proceso o resultado bancario incierto no equivale a fallo. Registrar y
  conciliar el resultado; no iniciar otro cobro independiente por un timeout.
- Al vencer, liberar el horario conforme a la transición aprobada, considerando las
  operaciones en curso. No alargar silenciosamente la exclusividad por conciliación.
- Pago confirmado tardíamente no desplaza otra reserva ni reactiva automáticamente
  una retención vencida. Conservar el registro financiero y resolver devolución o
  discrepancia según política todavía pendiente.
- Convertir una retención vigente en reserva definitiva no deja una ventana de
  desocupación. El éxito del proveedor debe corresponder a reserva/cotización exactas.
- Un replay devuelve el estado e instantes originales; no crea una nueva aceptación.

## Pendiente antes de desarrollar B2

Definir el margen para iniciar checkout antes del vencimiento; relación con el
inicio del servicio; solución contractual de éxitos tardíos; cancelación en curso;
reglas del proveedor; permisos del sistema y tratamiento de estados inciertos.
La vigencia sintética de cotización de B1 es otro plazo, no una retención.

No se añaden en B1 estados, tablas, triggers, tareas, endpoints ni mecanismos de
retención. La implementación B2 y cualquier migración requieren autorización posterior.
