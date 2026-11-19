# B3.1-D + UX-C1 — Demostración opcional

Base: main `8e485c5a41f256a63cad4020f5bc3258145c5080`. Se reutiliza el parche B31-DEMO original, SHA-256 `6f35df8085e10af058a6fd9ca2f29238d024d3579fe6f66993e8dbd99f90741d`, conservando las entradas Vite, componentes y contratos de UX-C1. No es un módulo financiero.

El cliente propietario de una reserva efectivamente retenida y vigente puede abrir «Explorar el pago (DEMO)». Es un acceso secundario; la confirmación B2 original permanece visible, explícita e independiente. Requiere local/testing, loopback IP/host y ausencia de configuración cacheada. Visitantes, otros clientes y prestadores no reciben esa invitación. Las rutas demo también rechazan producción, staging, hosts/IP ajenos y caché de configuración.

El enlace es siempre `/demo/checkout`, sin query ni IDs, importe, identidad, token comercial o URL de retorno. `rel=noreferrer` y `referrerpolicy=no-referrer` impiden enviar la ruta de la solicitud como referente. En origen y destino se muestra:

> Simulación independiente; el resultado NO paga ni confirma esta reserva. Los importes mostrados son ficticios.

La demo conserva su ejemplo fijo de $1.000 MXN y comisión de $150 incluida. No representa el precio de la reserva del cliente. El retorno es una ruta fija al listado autenticado de solicitudes; desde allí se continúa el seguimiento normal. No hay retorno arbitrario ni callback que confirme reservas.

La tarjeta usa instrumentos ficticios predefinidos y estados del servidor. Efectivo queda `demo_awaiting_cash`, sin comprobante. Solo la capacidad privada e independiente del operador puede representar la recepción, registrada como `provider_demo`. El prestador comercial no adquiere esa capacidad por su rol. El cliente ordinario no puede registrar recepción. `demo_completed` nunca es paid/captured financiero.

`CheckoutStore` usa archivos JSON privados y flock; no consulta bases comerciales ni sesiones. Conserva idempotencia, recarga, recuperación temporal y exactamente 900 segundos sintéticos sin renovación. El plazo B2 también permanece en 900 segundos y no se amplía ni se sincroniza con el de ejemplo. Un recibo demo no permite confirmar una retención B2 vencida. También se puede confirmar B2 sin usar la demo: no se activa la exigencia de pago.

No se modifican migraciones, workflows, modelos/policies, Payments, roles, MobileClient, OpenAPI ni API UX-C1. La API móvil no devuelve capacidades ni enlaces demo. No hay PAN/CVV libres, pasarela, dinero, saldo, liquidación, webhook o pago sandbox.

## Repetir en Windows aislado

Usar una copia nueva del commit de entrega fuera de Laragon. No copiar `.env`, bases ni credenciales. Instalar dependencias fijadas con `composer install --no-scripts --prefer-dist --no-interaction`, `npm ci` y `npm run build`. Conservar logs y códigos de salida, y detenerse si falla cualquier comando.

Para la demo independiente sin reservas, seguir `docs/payments/B3.1-D-DEMO.md`: variables de proceso locales, SQLite `:memory:`, configuración sin caché, directorio privado nuevo, `php artisan demo:checkout-prepare <ruta-absoluta-nueva> --url=http://127.0.0.1:8097` y servidor en 127.0.0.1. No requiere migraciones ni acceso a bases. No compartir ni grabar el enlace de operador que imprime el comando. El directorio privado debe quedar accesible solo al usuario local (ACL de Windows).

Para probar el recorrido integrado, el paquete de evidencias incluye un arnés externo y su guía PowerShell. Genera cuentas y SQLite descartables nuevas exclusivamente dentro de un directorio temporal propio. Nunca apuntar ese arnés a una instalación o base existente. El runtime del checkout sigue en otro subdirectorio privado JSON; no se vincula a la SQLite de la fixture. El arnés usa un reloj de proceso controlado para llegar al final del servicio, reautenticando ambos actores cuando corresponde, sin alterar el reloj del equipo ni las reglas del producto.

Para PHPUnit, seguir también `docs/testing/UX-C1-POLISH-WINDOWS.md`: copia nueva, `.env.testing` vacío temporal, bootstrap que fuerza SQLite `:memory:` y retirada del temporal al finalizar. Ejecutar en cada PHP disponible:

```powershell
php vendor/bin/phpunit --filter 'CheckoutDemo|ContractingJourney|SlotHold|Reservation|WebPricing|PaymentsPricing' --log-junit <EVIDENCIAS>/affected.xml
php vendor/bin/phpunit --log-junit <EVIDENCIAS>/full.xml
```

Comprobar también Pint sobre los PHP del inventario, build, `git diff --check`, integridad y FK. La suite T1 es separada y no se incluye en la suite normal. Linux no certifica Windows/Laragon, lectores de pantalla reales ni app móvil nativa.

## Recorrido de presentación

Cliente y prestador en sesiones independientes: solicitud, mensajes, cotización aceptada, propuesta y aceptación del horario. El cliente ve su importe real acordado y la invitación opcional; al abrirla ve otro ejemplo claramente ficticio. Probar tarjeta exitosa y comprobar que B2 sigue retenida. Volver al listado y confirmar manualmente. Repetir con efectivo pendiente: únicamente el operador demo con su enlace privado representa la recepción. Comprobar otra vez que B2 no cambió. Probar rechazo, temporal/reintento, recarga y vencimiento sin renovación. Tras el horario, el prestador registra explícitamente la realización, cierra la solicitud y el cliente reseña.
