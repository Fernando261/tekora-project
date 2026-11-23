# UX-C1 — Recorrido del cliente móvil

`GET /api/v1/inquiries/{inquiry}/journey`

Requiere `Authorization: Bearer <token móvil Sanctum>` y `Accept: application/json`. Sin cuerpo ni query. Mantiene `MobileClient`, rol/permisos vigentes y el límite `mobile-private`. Solo una solicitud propia **como cliente**, incluso si la cuenta también es prestador. Otra propiedad o ID inexistente: 404; token ausente: 401; rol/permisos inválidos: 403; parámetros inesperados: 422; límite: 429; almacenamiento/incertidumbre: 503 sin `data`.

Ejemplo de petición:

```http
GET /api/v1/inquiries/42/journey HTTP/1.1
Accept: application/json
Authorization: Bearer <token móvil del cliente>
```

El contrato completo es `ContractingJourney` en `openapi.json`. La respuesta tiene envoltura `data`. Ejemplo **parcial** del momento posterior a la aceptación del horario:

```json
{
  "data": {
    "viewer_role": "client",
    "next_action": {
      "code": "confirm_reservation",
      "stage": "confirmation",
      "label": "Confirma tu contratación",
      "description": "El prestador aceptó el horario. Confírmalo antes de que termine el plazo; todavía no es una reserva definitiva.",
      "reference": {"type": "reservation", "id": 17},
      "interaction": "navigate",
      "mobile_execution_available": true
    },
    "payments": {
      "enabled": false,
      "required_for_confirmation": false,
      "future_checkout_point": "after_provider_acceptance_before_final_confirmation"
    }
  }
}
```

Usar `code` y `reference`, no interpretar textos ni construir URLs a partir de ellos. Las fechas de instantes son UTC con `Z`; `local_date`, `local_start`, `local_end` y `timezone` describen el horario acordado. `server_now` y el vencimiento permiten dibujar el contador; el servidor decide su vigencia. Una recarga/reintento no amplía los 900 segundos. `agreed_amount` puede ser null: no sustituirlo por cero. Comisión del 15 % incluida, nunca sumar otra comisión.

## Matriz de seguimiento

| Cliente web | API cliente |
|---|---|
| Servicio solicitado, participantes y estado | `inquiry`, `participants`, `viewer_role` |
| Cinco etapas y siguiente paso | `progress`, `next_action` |
| Acuerdo e importe conservado | `commercial`; listado/detalle/aceptación en endpoints B3.0 existentes |
| Horario actual y plazo | `reservation`, `hold`, `server_now`; proponer/confirmar/cancelar en endpoints B2 existentes |
| Historial de solicitud y horario | `history.inquiry`, `reservation.events`, `reservation.hold_events`, últimos cinco en `history.reservations`; listado de reservas existente para el resto |
| Conversación y no leídos | `conversation`; mensajes, paginación y marcado explícito en endpoints existentes |
| Realización y cierre operativo | `closure`, estado `completed`; no existe acción móvil de prestador |
| Reseña disponible/publicada/retirada | `review` con estado y registro propio; desde M2.9, crear, consultar y retirar mediante la [API de reseñas](M2.9-MOBILE-REVIEWS.md) |
| Notificaciones | Endpoints M1 existentes, sin cambios |
| Pagos | Deshabilitados; no usar `/demo/checkout` como API |

Los códigos de espera (`interaction=wait`) no representan comandos. Presenta como información las sugerencias con `mobile_execution_available=false`. Desde M2.9, `write_review` enlaza al formulario con el ID de reserva y `view_review` a la reseña propia con su ID.

Los estados informativos nunca autorizan una operación. Al enviar una confirmación se siguen usando la versión y clave idempotente del contrato B2; 409 requiere consultar otra vez. La realización solo aparece tras el registro explícito del prestador, no porque termine el horario. GET no cambia notificaciones, mensajes, reservas ni pagos.

## Nota de integración posterior a B3.2 (M2.0)

La web B3.2 integrada en `main` **solo permite métodos de prueba en una SQLite temporal local**. No agrega acciones de pago a `/api/v1`: `payments.enabled=false` y `CheckoutContext.future_payment.enabled=false` continúan describiendo la API móvil. El ejemplo `confirm_reservation` de arriba representa el protocolo B2 general; su `mobile_execution_available=true` no es una autorización de cobro ni asegura que el comando pueda completarse en la fixture B3.2.

**Diferencia operativa a considerar:** dentro de la fixture B3.2, el servidor exige primero la selección del método ficticio **a través del flujo web local**. Como no existe endpoint móvil para registrar ese método, la app nativa no debe anunciar que puede completar esa misma demostración desde `/api/v1`, ni intentar saltarse el requisito por petición directa. Esta brecha deberá abordarse en un incremento posterior con controles y pruebas independientes, no exponiendo una base sintética a Internet.

El mapeo seguro y los estados que debe mostrar el cliente móvil están en `docs/api/M2.0-MOBILE-CHECKOUT-HANDOFF.md`. No se ha probado una aplicación nativa en este incremento.

## M2.1 — Preparación explícita para confirmar

`confirmation_readiness` añade el estado operativo de la reserva actual como cliente (o null sin reserva). Para ofrecer commit, consultar `can_offer_mobile_confirmation`, versión e instante del nuevo objeto; no reutilizar `mobile_execution_available` como autorización. Ese booleano y las demás propiedades conservan su semántica. El objeto procede del mismo checkout-context y comparte `server_now`; no ejecuta escrituras. Estados, compatibilidad, errores y límites: [M2.1-MOBILE-CONFIRMATION.md](M2.1-MOBILE-CONFIRMATION.md).
