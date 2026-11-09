# W4 · Marca moderna y compatibilidad histórica

Base auditada: `8599cff7e95f1eecd3c6b38890e5b089feaf1f49`, merge del PR #20. Continúa el [inventario W3](../w3/W3-MINDBLOOM-INVENTORY.md); no implica una migración de datos. Se revisan fuentes, consumidores y rutas, sin consultar registros persistentes.

## Cambios de lenguaje

| Vista | Antes | W4 y motivo |
| --- | --- | --- |
| `provider-services/form` | «Este catálogo no recibe reservas ni citas de MindBloom» | Explica que las propuestas de horario Tekora parten de solicitudes aceptadas y que la agenda histórica permanece separada. Evita una descripción ambigua del sistema actual de reservas. |
| `account/provider` | Perfil que «no aparece todavía en el directorio de MindBloom ni recibe sus citas» | Explica la visibilidad según los servicios publicados en Tekora, ausencia de verificación y separación de registros anteriores. No afirma una migración. |
| `account/provider-show` | «No forma parte del directorio anterior de MindBloom» | Ficha Tekora según servicios publicados; los registros históricos conservan su procedencia. Esta es la vista privada enlazada desde la gestión del perfil. |

El sidebar y el footer privado siguen identificando el acceso **MindBloom anterior**. Son enlaces de procedencia y compatibilidad, no una segunda marca promocional del marketplace. No se renombran registros antiguos como si fueran actividad nueva ni se modifica el sidebar aprobado para esta limpieza.

## Consumidores compartidos

| Familia | Consumidores | Decisión |
| --- | --- | --- |
| `account-layout` | Dashboards, identidad, historial y páginas operativas | Prop `operations` explícita; el bundle W4 y su CSP meta solo se agregan con esa opción. El layout, navegación y carga de dashboards se conservan en las otras páginas. |
| `catalog-pagination` | W3, servicios, solicitudes, reservas, reseñas y notificaciones | Markup, parámetros, rel y enlaces idénticos; estilos W4 solo bajo `.tk-ops`. |
| `inquiry-status/actions/time/conversation/reservations` | Listados y detalle privados | Condiciones, consultas, eventos, campos hidden y formularios intactos. Presentación por CSS acotado. |
| `reservation-status/schedule/actions/review` | Reservas y seguimiento de solicitudes | Estados, fechas, versiones y permisos intactos. |
| `provider-availability` | W3 y propuesta de reserva | Fuente intacta; estilos por namespace para evitar alterar el marketplace. |
| `review-stars` | W3 y reseñas privadas | Fuente intacta; no se modifica el ancho que representa la valoración real ni el anonimato público. |
| `flash-messages` | Formularios y shell existentes | Sin cambios; errores de validación y `old()` se conservan. Los fallbacks de operación muestran su mensaje explícito y no consultan navegación. |
| `public/js/availability.js` | Editor de disponibilidad | Byte por byte igual; se conservan límites, índices, foco, botones y fallback sin JS. |

## Archivos conservados y eliminaciones

**No se elimina ningún archivo.** `account.css`, `catalog.css`, `inquiries.css`, `reservations.css`, `reviews.css`, `availability.css`, `notifications.css` y los recursos históricos permanecen. Las hojas específicas existentes se conservan en las páginas del shell operativo; el bundle W4 las complementa con alcance limitado. Los fallbacks autónomos cargan solo el CSS operativo en lugar del layout de acceso anterior. No se presume que un recurso sea prescindible por haber añadido una capa visual.

Los candidatos sin referencias estáticas del grupo B de W3 continúan conservados. Las funciones del grupo C —inicio anterior, comunidad, citas, directorio y login de profesionistas, guard separado, Google Calendar, PDF/emociones, medios y relaciones históricas— mantienen rutas, modelos y datos.

Los fallbacks dejan de depender del shell de cuenta: `inquiries/conflict`, `invalid-filters`, `message-error`, `rate-limited`, `conversations/read-error`, `availability/error`, `reservations/error`, `reviews/error` y `notifications/error` usan una vista autónoma. Los tres últimos ya evitaban contadores; se mantiene ese aislamiento y se extiende a los demás. Se conservan títulos, mensajes y enlaces de regreso. Las pruebas comprueban **cero consultas al renderizar los nueve fallbacks**.

## Retirada futura

La eventual retirada del legado requiere identificar consumidores dinámicos, enlaces guardados, referencias a medios y trazabilidad de registros; acordar un reemplazo; conservar exportaciones históricas; y probar guards, permisos, Calendar, descargas y relaciones antes de borrar nada. W4 no hace esa retirada ni altera credenciales, esquemas o bases.

[Invariantes verificadas](evidence/invariants.json) · [Comparación de consultas](evidence/query-comparison.json).
