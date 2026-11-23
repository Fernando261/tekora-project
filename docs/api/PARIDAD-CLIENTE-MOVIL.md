# Auditoría de paridad del cliente móvil

Base auditada `54647c3cd803667f14b8875acfa2ea6c3efad895`, `main` después de la PR #53. La comparación cubrió las rutas cargadas desde `routes/web.php`, `routes/api.php`, OpenAPI y los contratos M2.0, M2.1 y M2.7.

| Función | Web y dominio | API en la base | Fase y evidencia |
|---|---|---|---|
| Inicio, cierre e identidad solicitante | `AuthController`, `IdentityController` | `POST /auth/login`, `POST /auth/logout`, `GET /me` | Disponible. `MobileApiAuthTest` protege el contrato, el token y el rol vigente. |
| Registro, recuperación, nombre y foto | `RegisterUserRequest`, `RoleMemberships`, controladores web de contraseña y perfil | Identidad resumida en `/me`. Faltan alta, recuperación y operaciones de perfil. | M2.8. `TekoraW5Test`, `ProfilePhotoIntegrityTest`, `LegacyPhotoDecouplingTest`. |
| Solicitudes y conversaciones | `routes/inquiries.php`, `routes/conversations.php`, `InquiryWorkflow`, `InquiryMessaging` | Solicitudes propias, apertura, cancelación, conversaciones, mensajes y lectura. M2.7 filtra ambos canales. | Disponible. `MobileOperationsInquiryTest`, `MobileOperationsMessagesTest`, `MobileContactPrivacyTest`. Las decisiones de prestador conservan la experiencia web. |
| Cotización, agenda de reserva y confirmación | `routes/quotes.php`, `routes/reservations.php` | Cotizaciones, aceptación, opciones, propuesta y confirmación B2, cancelación y lectura del recorrido y de la preparación. | Disponible para solicitante. `MobileQuotesApiTest`, `MobileOperationsReservationTest`, `MobileCheckoutHandoffContractTest`, `MobileConfirmationReadinessTest`. |
| Reseñas públicas de servicio y prestador | `ServiceCatalogController`, `PublicProviderController`, `PublicReviews` | Los recursos de servicio y prestador carecen del listado de reseñas. | Falta M2.9. `ReviewPublicVisibilityTest`. |
| Historial, detalle, publicar y retirar reseña propia | `routes/reviews.php`, `ServiceReviewController`, `ReviewWorkflow`, `ReviewInput` | Faltan las operaciones del solicitante. | Falta M2.9. `ReviewManagementTest`, `ReviewConcurrencyTest`. |
| Catálogo público y disponibilidad | `routes/catalog.php`, `ProviderAvailability` | Listado y detalle de servicios y prestadores, filtros de servicios y disponibilidad pública. | Disponible. `MobileApiCatalogTest`. |
| Categorías ordenadas y servicios por prestador | `ServiceCatalogController` carga categorías activas y ordenadas. `PublicProviderController` pagina servicios publicados. | Los servicios incluyen su categoría, pero faltan el índice de categorías y la consulta por prestador. | Falta M3.0. Reutilizar los ámbitos de visibilidad y paginación existentes. |
| Resumen cliente | `AccountController::client`, `ClientDashboardSummary` | Las lecturas individuales existen. Falta el resumen con agenda, solicitudes recientes y contadores. | Falta M3.0. `ClientDashboardTest`. Los contadores y las referencias deben mantener alcance de cliente incluso con roles combinados. |
| Notificaciones | `routes/notifications.php`, `NotificationInbox` | Listado, contador y marca de lectura. | Disponible. `MobileNotificationsTest`, `MobileNotificationContractTest`. |
| Checkout B3.2 | Demostración web local protegida. | M2.0 ofrece contexto comercial privado de solo lectura. | El checkout local queda fuera de la API pública. `LocalIntegratedCheckoutBoundaryTest`. |

La PR de M2.8 se limita a identidad y perfil. M2.9, M3.0 y la validación integral M3.1 quedan pendientes de revisión y de sus propios incrementos.
