# Tekora — preparación de VPS, MySQL 8.4 y API móvil (fase 0)

**Estado: BLOCKED PARA PRODUCCIÓN.** Auditoría de compatibilidad y criterios de aceptación; esta fase **no implementa compatibilidad MySQL** ni acredita que exista un despliegue funcional.

- Base examinada: `main` = `2aabf7a0e64648b6ce3378fc1013c11a77f80072` (23-11-2026).
- Laravel 12 / Blade / Sanctum; OpenAPI `docs/api/openapi.json` v1.7.0, **51 operaciones**; 39 migraciones de aplicación e históricas.
- La aplicación móvil de solicitantes está en **otro repositorio**: `Jose5938/Tekora_movil`. No modificarlo desde esta rama.
- Ocho PR históricos siguen abiertos (#28 y #31–37); **no fusionarlos** para intentar completar la portabilidad sin contrastarlos con el dominio actual.

## 1. Bloqueos confirmados en código

| Zona | Dependencia existente | Requisito antes de habilitar MySQL |
|---|---|---|
| Solicitudes | `2026_10_28_000002_create_service_inquiries_table.php`: guard SQLite e índice parcial de solicitud activa | Unicidad equivalente en InnoDB incluso bajo solicitudes simultáneas |
| Disponibilidad | `2026_10_30_000002_create_provider_availability_tables.php`: DDL, CHECK y triggers SQLite | Validar rango horario, intervalos no solapados y revisión concurrente |
| Reservas | `2026_11_03_000000_create_service_reservations.php`: SQL y triggers de transición, ocupación y eventos | Identidad, permiso, unicidad de reserva activa, exclusividad horaria, historial atómico |
| Reseñas y notificaciones | Migraciones `2026_11_03_000001` y `2026_11_03_000002`: triggers SQLite | Registro inmutable y efectos de notificación transaccionales e idempotentes |
| Precio y cotizaciones | `2026_11_18_000000_create_web_pricing_and_quotes.php` y `SqlitePricingStore` | Adaptador real MySQL, precio inmutable, importes enteros y comisión incluida del 15 % |
| Retenciones B2 | `2026_11_19_000000_add_reservation_slot_holds.php`, `ReservationTransactions`, `BookingClock` | Reloj UTC en microsegundos, high-water, hold exactamente 900 s, locks, receipt/idempotencia y recuperación |

Además de las migraciones, `ReservationTransactions` y `SqlitePricingStore` rechazan otros drivers; `PricingServiceProvider` enlaza incondicionalmente el adaptador SQLite. **Cambiar solo `DB_CONNECTION=mysql` no funciona**. No eliminar guardas para que las migraciones “pasen”: sus restricciones evitan dobles contrataciones y corrupción de estados.

MySQL 8.4 puede utilizar columnas generadas y claves únicas con `NULL` para ciertos equivalentes de índices parciales; esto **no sustituye** automáticamente triggers ni locks de intervalos. Diseñar soluciones con serialización/locks InnoDB reales y probar carreras entre procesos; no confiar solo en un SELECT anterior al INSERT.

## 2. Plan de trabajo (backend exclusivamente)

**Fase A — Paridad MySQL real, en laboratorio efímero**

1. Congelar la referencia `main` actual. Auditar **todas** las migraciones históricas y SQL raw; clasificar DDL, CHECK, triggers, vistas, fecha/hora, collations, FKs y serialización por motor.
2. Añadir implementaciones por driver **sin degradar SQLite**, manteniendo la historia migratoria. Diseñar el camino de **instalación limpia** MySQL 8.4; cualquier migración de datos de SQLite existente es un proyecto **distinto**, con backup, mapeo y reconciliación.
3. Implementar `PricingStore` MySQL e infraestructura de reservas con transacciones, `SELECT ... FOR UPDATE`, reintentos definidos y protección de las invariantes **también en la base**. Conservar precisiones UTC, 15 % en centavos y 900 s exactos.
4. CI con MySQL 8.4 auténtico/InnoDB, base y usuario exclusivos efímeros y sin puertos publicados al host; no utilizar MySQL de Docker personal ni producción. Verificar motor, versión, sql_mode, charset/collation, índices, triggers, FK y esquema. Comprobar 39/39 migraciones (o inventario actualizado), segunda instalación idempotente, suite focal y flujo cliente/prestador/API.
5. Regresiones adversas: dos clientes mismo horario, doble POST, pérdida de respuesta, operación repetida con misma UUID, versión obsoleta, reloj hacia atrás, expiración a los 900 s, rollback antes/después de commit, mensajes/notificaciones, retiros de reseña y bloqueo de datos ajenos. Comparar MySQL y SQLite en resultados e invariantes.

**Puerta A:** no aceptar `READY` si falla instalación desde cero, si una regresión pierde invariantes o si MySQL solo pasa con guardas desactivadas.

**Fase B — Preparar release de VPS, sin publicarla**

1. Proponer VPS Linux mantenida (p. ej. Ubuntu 24.04), Nginx y PHP-FPM para la versión de PHP soportada por `composer.lock`, MySQL 8.4 privado, HTTPS con certificado válido y renovación automática.
2. Diseñar `public/` como única raíz del sitio. Sin acceso público a `.env`, código fuente, `vendor`, `storage`, pruebas, dumps, `phpMyAdmin` o puerto 3306. Usuario Linux/DB con mínimo privilegio; SSH por llave, firewall 22 restringido y 80/443 públicos.
3. Entorno `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://DOMINIO_VALIDADO`, `DB_CONNECTION=mysql`, `DB_URL` vacío, zona de negocio y UTC consistentes. No registrar secretos ni contraseñas en el repositorio, logs o PR.
4. Instalación desde locks, assets de Vite, `config:cache`, `route:cache` si es compatible, `view:cache`, permisos mínimos, `storage:link` verificado, colas (Supervisor/systemd), scheduler y correo transaccional autenticado; validar enlaces de recuperación y fotos.
5. Backups cifrados de MySQL y archivos, restauración probada en otra instancia, logs/retención/rotación, supervisión del healthcheck, alertas y plan de rollback de código **sin prometer rollback automático de migraciones**.
6. Proteger `main` con PR, revisiones y controles CI obligatorios. Desplegar primero en **staging HTTPS** con usuarios sintéticos y luego producción solo mediante aprobación posterior.

**Puerta B:** sin exposición pública hasta que cierren paridad DB, validación de staging, seguridad y restauración.

**Fase C — Integración con app móvil (otro equipo/repositorio)**

- Contrato único `https://DOMINIO_VALIDADO/api/v1`, mismo backend que la web; Sanctum Bearer para clientes móviles, cookies web solo en navegador. Producción **no acepta HTTP**.
- Configurar `EXPO_PUBLIC_API_BASE_URL` en build móvil; esta variable es pública, sin credenciales. Comprobar login, registro, restablecimiento por correo, foto autenticada, catálogo, solicitudes, cotizaciones, mensajes, reservas, idempotencia, 401/403/404/409/422/429/503, notificaciones y reseñas.
- Probar **Android físico** contra staging HTTPS con certificados válidos, no únicamente Chromium/Expo Web. Asegurar no filtrar Bearer a redirecciones, otros orígenes, logs ni caché de otra cuenta.
- Los endpoints `/reservations/{reservation}/demo-checkout` siguen siendo **exclusivos de SQLite B3.2 marcada y loopback**. En la VPS MySQL deben permanecer inaccesibles. El flujo comercial B2 de reservas funciona **sin pago real**. No prometer checkout productivo ni pagos; activarlos requeriría proyecto separado, proveedor autorizado y controles financieros.

## 3. Pruebas y evidencia mínimas de salida

| Control | Actual | Criterio de aceptación |
|---|---|---|
| SQLite CI/T1, API Linux | Verde en PR recientes | Debe permanecer verde en HEAD final |
| MySQL 8.4 fresh install | **BLOCKED** (guardas SQLite) | `PASS` sin excepciones ni hacks, inventario completo |
| Invariantes de dominio y concurrencia MySQL | **NOT RUN** | Pruebas de procesos, rollback, fechas, cents, FK, locks, 900 s |
| Integración web y API sobre MySQL | **NOT RUN** | Recorrido cliente/prestador y móvil ejecutado con MySQL real |
| Windows y VPS staging | **NOT RUN** | Evidencia verificable, versiones y estado |
| Dispositivo Android real con HTTPS | **NOT RUN** | Login y recorrido sin HTTP ni sesiones cruzadas |
| Restauración de respaldo | **NOT RUN** | Restore real en instancia aislada |
| Pagos reales | **FUERA DE ALCANCE** | No presentarlos como listos |

## 4. Entrega esperada de Codex

Trabajar en incrementos/PRs en borrador desde `main` actualizado; justificar cambios de esquema y aplicación, publicar resultados con SHA y versiones, y detenerse ante P0/P1 o pérdida de invariantes. No desplegar, no migrar ni retirar datos reales, no tocar la app móvil, no fusionar ramas antiguas ni cambiar `main` sin revisión y autorización del propietario.

**Esta fase 0 únicamente deja documentado el bloqueo y las puertas de salida para la preparación de VPS. No constituye certificación de producción.**
