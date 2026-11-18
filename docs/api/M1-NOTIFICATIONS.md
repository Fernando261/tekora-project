# M1 — Notificaciones del solicitante

Base de este incremento: b04cf585b236c4eefbd4096e217cc46aaefef94e. Solo API del cliente; sin push, Firebase, correo, SMS ni funciones de prestador móvil. No cambia los eventos, workflows o tablas existentes.

## Contrato móvil

Bearer Sanctum existente, token mobile: con ability client:read y rol/permisos actuales de cliente. Una cookie web, otro tipo de token o conservar una relación roles cacheada no autoriza. Respuestas JSON data o error:{code,message,fields}, privadas/no-store, incluso sin Accept. El nombre histórico de la ability no convierte el token en permiso de prestador.

| Método | Ruta bajo /api/v1 | Cuerpo / parámetros |
| --- | --- | --- |
| GET | /notifications | page=1, per_page=12; máximo page=10000, per_page=50 |
| GET | /notifications/unread-count | Ninguno |
| POST | /notifications/{source_type}/{id}/read | Sin cuerpo o {}; ningún campo adicional |

Los GET usan el límite privado existente de 120/minuto por actor y 480/IP. POST añade mobile-read-writes (compartido con lectura de mensajes): 30/minuto por actor, 120/IP. Ante 429 respetar Retry-After. No existe lectura masiva ni endpoint de detalle adicional.

```http
GET /api/v1/notifications?per_page=12
Authorization: Bearer <token-local-sintetico>
```

```json
{
  "data": [{
    "id": "inquiry:2", "source_type": "inquiry", "source_id": 2,
    "event": "accepted", "label": "Solicitud aceptada",
    "created_at": "2030-01-01T12:00:00.000000Z", "read_at": null,
    "resource": {"type": "inquiry", "id": 93}
  }],
  "meta": {"current_page": 1, "per_page": 12, "last_page": 1, "total": 1}
}
```

GET /notifications/unread-count devuelve {"data":{"unread_count":1}}. POST /notifications/inquiry/2/read devuelve el mismo aviso bajo data con read_at UTC no nulo. Repetir conserva la primera fecha, incluso si otro dispositivo o la web ya lo leyeron. No requiere una clave adicional: su identidad idempotente es source_type + source_id. Los ejemplos completos de ambos orígenes y schemas están en openapi.json, operaciones listClientNotifications, countClientNotifications y readClientNotification.

Tratar id como opaco y usar ambos campos de origen para la ruta. inquiry:2 y activity:2 son avisos distintos. resource es una referencia tipada para navegar a los endpoints móviles ya existentes de solicitudes/reservas, que vuelven a autorizar; no contiene enlaces privados web. label es texto plano del enum existente, nunca HTML ni contenido de mensajes/reseñas. No se devuelven destinatarios, correos, nombres, relaciones completas o datos bancarios.

## Ámbito y consistencia

NotificationInbox mantiene su unión SQL de las dos tablas existentes. Su ámbito móvil aplica destinatario, rol cliente vigente, propiedad del recurso y eventos del cliente antes de ordenar, contar o paginar. Usa los scopes de MobileClientOperations, equivalentes a las ramas cliente de las policies; la identidad de ActivityNotificationPolicy también queda exigida por user_id. No filtra después de paginar.

- Inquiry: accepted, rejected y completed para una solicitud cuyo client_user_id sea el actor. created y cancelled pertenecen al prestador y se excluyen.
- Activity: reservation_confirmed, reservation_rejected, reservation_cancelled y reservation_completed para una reserva del actor y cuyo destinatario sea el actor. Una cancelación hecha por el cliente avisa al prestador, por lo que no se incluye como aviso cliente.
- reservation_requested y avisos de reseñas son del prestador: siempre excluidos, también en cuentas duales. No se inventan avisos cliente de reseña ni se alteran triggers.
- La autorización del recurso no depende de que el servicio siga publicado: se conserva la misma propiedad histórica de solicitudes/reservas que en web/API.

Orden: created_at DESC, source_type DESC, id DESC, como la web. El listado cuenta y obtiene su página en una transacción de lectura coherente. El contador es una sola consulta SQL con el mismo filtro y read_at nulo. Son solicitudes HTTP separadas: nuevas notificaciones o lecturas entre peticiones pueden cambiar el total; deduplicar por id compuesto y refrescar la primera página al volver a la pantalla. Página fuera del total: 404; primera página vacía: 200 con total=0.

La lectura vuelve a comprobar el ámbito dentro de su transacción y actualiza solamente read_at mediante WHERE read_at IS NULL, igual que la web. No modifica solicitudes, reservas, mensajes, reseñas, cotizaciones ni retenciones. El bookkeeping last_used_at de Sanctum sigue vigente. Fechas literales interpretadas en UTC; la zona configurada de la app no desplaza timestamps.

## Errores y recuperación

401 sin Bearer válido; 403 por rol/permiso/tipo de token; 404 para origen/ID malformado, ausente, ajeno, exclusivo de prestador o recurso no autorizado. Se comprueba ownership antes de validar el cuerpo. Parámetros inesperados y límites inválidos: 422. Los estados financieros, destinatario y fechas no pueden enviarse como campos de lectura.

Errores de almacenamiento de consulta: 503 sin data, nunca contador cero ni listado vacío ficticio. Conflicto de escritura: 409; otros fallos de almacenamiento: 503 sin afirmar lectura. Ante respuesta perdida o 409/503, volver a consultar y repetir la misma ruta; no simular éxito local definitivo. La unión consulta ambas tablas: una tabla no disponible no se oculta devolviendo solo la otra. El inbox web conserva su comportamiento anterior y puede seguir mostrando ambas experiencias de una cuenta dual.

Las regresiones protegen las 28 operaciones API del Git base mediante inventario exacto de métodos, URIs, acciones, middleware y restricciones, más únicamente las tres operaciones M1. Contratos comerciales B3.0, B2, B3.1, dependencias y 39 migraciones permanecen intactos. No se crean tablas ni se requieren migraciones nuevas. Validación nativa de app móvil y Windows: pendiente; los resultados ejecutados se entregan separadamente.
