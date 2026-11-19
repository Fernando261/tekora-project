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
| Reseña disponible/publicada/retirada | `review` con estado y registro propio; crear/retirar continúa solo web (`mobile_execution_available=false`) |
| Notificaciones | Endpoints M1 existentes, sin cambios |
| Pagos | Deshabilitados; no usar `/demo/checkout` como API |

Los códigos de espera (`interaction=wait`) no representan comandos. Las referencias de reseña y las sugerencias que indiquen `mobile_execution_available=false` deben presentarse como información; no inventar endpoints ni permisos. La equivalencia de lectura está implementada; escribir reseñas desde móvil sigue siendo un faltante previo, fuera del alcance de este GET.

Los estados informativos nunca autorizan una operación. Al enviar una confirmación se siguen usando la versión y clave idempotente del contrato B2; 409 requiere consultar otra vez. La realización solo aparece tras el registro explícito del prestador, no porque termine el horario. GET no cambia notificaciones, mensajes, reservas ni pagos.
