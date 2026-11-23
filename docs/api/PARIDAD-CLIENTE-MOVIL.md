# Auditoría de paridad del cliente móvil

Base auditada para M2.9 `06a3a09a9efe662d7ee58e1966bffd15b26dda22`, `main` después de la PR #54. La comparación cubrió las rutas cargadas desde `routes/web.php`, `routes/api.php`, OpenAPI y los contratos previos hasta M2.8.

| Función | Web y dominio | API del solicitante | Fase y evidencia |
|---|---|---|---|
| Inicio, cierre e identidad solicitante | `AuthController`, `IdentityController` | `POST /auth/login`, `POST /auth/logout`, `GET /me` | Disponible. `MobileApiAuthTest` protege el contrato, el token y el rol vigente. |
| Registro, recuperación, nombre y foto | `RegisterUserRequest`, `RoleMemberships`, controladores web de contraseña y perfil | Registro por correo, recuperación, perfil propio y foto desde API. | M2.8 integrado. `MobileIdentityProfileTest`, `TekoraW5Test`, `ProfilePhotoIntegrityTest`. |
| Solicitudes y conversaciones | `routes/inquiries.php`, `routes/conversations.php`, `InquiryWorkflow`, `InquiryMessaging` | Solicitudes propias, apertura, cancelación, conversaciones, mensajes y lectura. M2.7 filtra ambos canales. | Disponible. `MobileOperationsInquiryTest`, `MobileOperationsMessagesTest`, `MobileContactPrivacyTest`. Las decisiones de prestador conservan la experiencia web. |
| Cotización, agenda de reserva y confirmación | `routes/quotes.php`, `routes/reservations.php` | Cotizaciones, aceptación, opciones, propuesta y confirmación B2, cancelación y lectura del recorrido y de la preparación. | Disponible para solicitante. `MobileQuotesApiTest`, `MobileOperationsReservationTest`, `MobileCheckoutHandoffContractTest`, `MobileConfirmationReadinessTest`. |
| Reseñas públicas de servicio y prestador | `ServiceCatalogController`, `PublicProviderController`, `PublicReviews` | M2.9 añade `GET /services/{service}/reviews` y `GET /providers/{provider}/reviews`. Los recursos previos de catálogo conservan su esquema. | M2.9 en revisión. `MobileReviewsTest`, `ReviewPublicVisibilityTest`. |
| Historial, detalle, publicar y retirar reseña propia | `routes/reviews.php`, `ServiceReviewController`, `ReviewWorkflow`, `ReviewInput` | M2.9 añade cuatro operaciones privadas. El recorrido habilita `write_review` y `view_review` para el cliente. | M2.9 en revisión. `MobileReviewsTest`, `ReviewManagementTest`, `ReviewConcurrencyTest`. |
| Catálogo público y disponibilidad | `routes/catalog.php`, `ProviderAvailability` | Listado y detalle de servicios y prestadores, filtros de servicios y disponibilidad pública. | Disponible. `MobileApiCatalogTest`. |
| Categorías ordenadas y servicios por prestador | `ServiceCatalogController` carga categorías activas y ordenadas. `PublicProviderController` pagina servicios publicados. | Los servicios incluyen su categoría, pero faltan el índice de categorías y la consulta por prestador. | Falta M3.0. Reutilizar los ámbitos de visibilidad y paginación existentes. |
| Resumen cliente | `AccountController::client`, `ClientDashboardSummary` | Las lecturas individuales existen. Falta el resumen con agenda, solicitudes recientes y contadores. | Falta M3.0. `ClientDashboardTest`. Los contadores y las referencias deben mantener alcance de cliente incluso con roles combinados. |
| Notificaciones | `routes/notifications.php`, `NotificationInbox` | Listado, contador y marca de lectura. | Disponible. `MobileNotificationsTest`, `MobileNotificationContractTest`. |
| Checkout B3.2 | Demostración web local protegida. | M2.0 ofrece contexto comercial privado de solo lectura. | El checkout local queda fuera de la API pública. `LocalIntegratedCheckoutBoundaryTest`. |

La [guía M2.9](M2.9-MOBILE-REVIEWS.md) documenta las reseñas del solicitante. M3.0 y la validación integral M3.1 quedan pendientes de la revisión de este incremento.
