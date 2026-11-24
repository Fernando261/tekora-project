# V8.2-pre · Preparación de VPS y staging HTTPS

**Estado: plantillas para revisión, NOT RUN en VPS.** Base de referencia `0c88b5b4eef7f64a7acce2fcc397f6bc20f8e354` (PR #62 integrado). El laboratorio MySQL acredita 35/39 migraciones; el flujo comercial completo y el migrador ordinario MySQL siguen **BLOCKED**. Este trabajo no cambia PHP de aplicación, migraciones, OpenAPI ni el repositorio móvil.

## Decisiones provisionales

- Propuesta: VPS Linux (Ubuntu LTS), Nginx + PHP-FPM 8.4, Composer y Node para construir assets desde sus locks. Verificar la disponibilidad y soporte de paquetes del proveedor antes de instalar; Laravel declara PHP ^8.2.
- Dos entornos separados: staging con SQLite desechable y usuarios sintéticos; producción futura con MySQL 8.4/InnoDB. Staging no se convierte en producción ni se reutiliza su base. Si comparten servidor, aislar usuarios, pools FPM, almacenamiento, claves, sesiones, bases, procesos y dominio; preferir servidores separados.
- La app consume `https://DOMINIO_AUTORIZADO/api/v1`; nunca accede a la DB. El otro equipo valida Android con ese origen, certificado válido y cuentas sintéticas.
- No se activa ningún pago real ni se ofrece el checkout de laboratorio B3.2 por Internet.

## Archivos preparados

- `deploy/staging/env.example`: SQLite privada, cookies HTTPS, debug off, APP_ENV=production **en una instancia separada de staging**. La condición de CheckoutDemoOnly rechaza este modo; no se habilita su raíz de datos.
- `deploy/staging/nginx.conf.example`: TLS, raíz exclusivamente public/, allowlist de QA de ejemplo que no concede acceso real, ejecución exclusiva del front controller, denegación adicional de dos familias de rutas demo. Laravel sigue siendo la autoridad de autorización.
- `deploy/staging/tekora-queue.service.example`: worker opcional bajo identidad dedicada. Validar timeout inferior a retry_after, permisos y tareas antes de habilitar.

Las plantillas contienen dominios .invalid, rutas y socket de ejemplo. No son una configuración instalable sin adaptación. No contienen secretos, contraseñas de prueba ni un script que despliegue automáticamente.

## Puerta S0 — confirmar infraestructura

Recabar proveedor, distribución/versión, arquitectura CPU, RAM, almacenamiento, dominio, acceso SSH y mecanismo de acceso de QA. Asegurar que el equipo puede entrar mediante llave SSH y una sesión de recuperación antes de cambiar firewall. No compartir llave privada, tokens, APP_KEY o contraseñas en chat/GitHub.

Configurar firewall: SSH restringido a administradores, HTTPS limitado a QA (IP/VPN), ningún puerto DB público. Resolver DNS y obtener certificado válido; usar validación DNS o revisar por separado el listener ACME. El ejemplo solo abre HTTPS de la app. Configurar un virtual host por defecto que rechace dominios desconocidos; no confiar en cabeceras forwarded de clientes. Si hay CDN/proxy, revisar explícitamente confianza de proxies y TLS antes de aprobar.

## Puerta S1 — staging separado

1. Crear identidad de servicio y directorios dedicados bajo /srv/tekora-staging. Código de releases solo lectura para FPM/worker, salvo storage y bootstrap/cache con permisos mínimos; base SQLite y sus sidecars deben permanecer en un directorio privado escribible por el servicio. Nunca debajo de public/. Clonar una revisión autorizada en un release independiente.
2. Instalar desde composer.lock y package-lock.json; verificar PHP real y extensiones con `composer check-platform-reqs`, construir con `npm ci` y `npm run build`. Evitar ejecutar comandos de instalación como root. Confirmar que no existe public/hot en el release y que el manifest Vite está presente.
3. Preparar .env solo en el servidor con permisos restrictivos, URL real HTTPS, APP_DEBUG=false, APP_ENV=production, APP_KEY nueva **solo para este entorno** y SQLite en ruta absoluta nueva. No copiar configuración/caché de otro entorno. Verificar la conexión efectiva con `php artisan about` y `php artisan migrate:status` (una DB nueva puede indicar tabla migrations inexistente). No registrar secretos en las evidencias.
4. **Solo con identidad SQLite y ruta nueva confirmadas y aprobación del entorno sintético**, crear el archivo y ejecutar `php artisan migrate --force`; verificar las 39 identidades, `PRAGMA integrity_check` y `PRAGMA foreign_key_check`, y segunda ejecución sin pendientes. No usar migrate:fresh, db:wipe ni seeders genéricos. Revisar y ejecutar únicamente el seeder de categorías si procede; preparar cuentas QA mediante flujos aprobados, sin datos reales.
5. Verificar almacenamiento público/privado y link de storage antes de crearlo; nunca exponer storage/app/private. Revisar `php artisan config:cache`, `php artisan route:cache` (solo si pasa), `php artisan view:cache`. Verificar que el entorno efectivo y la clave sean correctos después de cachear.
6. Crear pool FPM con socket dedicado y acceso de Nginx, verificar permisos y configuración, adaptar virtual host, comprobar `nginx -t` y habilitar solo después de aprobar TLS/acceso QA. No enviar credenciales por HTTP. Revisar logs para que no capturen Bearer, contraseñas, códigos, cuerpos de mensajes o datos personales; mantener acceso limitado y rotación. Configurar SMTP hacia buzones sintéticos autorizados sin usar mailer log en entorno remoto.
7. Validar tareas de cola y scheduler mediante `php artisan schedule:list`; actualmente routes/console.php no define tareas programadas de negocio. Habilitar procesos únicamente si procede y documentar supervisión y reinicio al cambiar release. /up comprueba el arranque de Laravel, no acredita disponibilidad de DB ni recorrido comercial.

## Puerta S2 — smoke tests y Android

Desde un dispositivo autorizado, validar certificado normal (sin -k), `GET /up` y `GET /api/v1/categories` con 200, login con Bearer, ausencia de redirecciones inesperadas, cookies seguras en web, registro/recuperación y recepción real en buzón QA, fotos privadas y logout/revocación. Probar acceso denegado desde fuera de la allowlist y entre cuentas, error de credenciales y límites de intentos.

Inspeccionar `php artisan route:list` y verificar por HTTP los límites de TODAS las rutas demo: con sesión web y con Bearer válidos, GET y POST deben permanecer inaccesibles (404 según el contrato de rutas demo; no aceptar éxito, y distinguir middleware de auth). Verificar que la app distribuida tampoco solicite demo-checkout. No introducir datos de tarjetas.

Recorrido esperado, sujeto a ejecutar la prueba real: cliente Android crea solicitud → prestador web la recibe y responde → cotización aceptada → propuesta/aceptación de horario → confirmación B2 según readiness del servidor → servicio realizado → reseña/avisos. Confirmación de reserva no es un cargo. Comprobar conflictos/reintentos y caducidad. Si aparece web_checkout_required, registrar el bloqueo y no falsificar éxito. No publicar la URL hasta que la instancia exista, esté restringida y tenga TLS verificado.

## Puerta P — futura producción MySQL (BLOCKED)

Requiere portar reseñas, actividad, precios/cotizaciones y retenciones B2; adaptar transacciones de reservas y vistas; demostrar migración ordinaria completa (39/39 o inventario vigente), instalación interrumpida recuperable, flujo comercial y concurrencia, privilegios de triggers con binary logging activo, restore comprobado, SMTP, pruebas web/API/Android y seguridad. La existencia de variantes de laboratorio no autoriza cambiar DB_CONNECTION=mysql en producción.

Definir usuario de migración distinto del usuario de ejecución; el usuario de aplicación no debe poder modificar esquema ni triggers. MySQL debe escuchar solo local/privado. Validar backups cifrados de datos y archivos, conservación segura de APP_KEY, restauración en otra instancia y procedimiento de rollback de release sin asumir que revertir código revierte el esquema. Proteger main y definir checks de integración obligatorios antes de publicar. No ejecutar migraciones reales sin autorización específica.

## Evidencia de esta entrega

| Control | Estado |
| --- | --- |
| Revisión de configuración Laravel y middleware en el HEAD de referencia | Revisión estática |
| Plantillas staging env, Nginx, systemd | Preparadas, sin probar en servidor |
| nginx -t, FPM, TLS, firewall, instalación SQLite en staging | NOT RUN |
| SMTP, recorrido real, Android físico | NOT RUN |
| MySQL completo, recuperación y despliegue de producción | BLOCKED |

No se ha contratado ni accedido a una VPS, instalado certificados, creado bases, configurado DNS, iniciado workers ni desplegado la aplicación. No fusionar este borrador como certificación de preparación de producción.
