# W7.1 · Recorrido web de contratación

La demostración usa dos cuentas sintéticas con sesiones independientes. Las decisiones pasan por los formularios existentes de Laravel. B3.2 registra resultados de prueba en una SQLite temporal y B2 conserva la confirmación definitiva por separado.

| Paso | Actor y pantalla | Resultado verificable |
| --- | --- | --- |
| Preparar prestador | Registro, acceso, activación en `/cuenta` y perfil profesional | La cuenta conserva su rol de solicitante y obtiene el de prestador explícitamente |
| Publicar disponibilidad | Prestador, `/cuenta/disponibilidad` | Horario semanal y zona civil guardados desde el formulario |
| Publicar | Prestador, `/cuenta/servicios/crear` | Servicio visible en el catálogo y precio publicado en MXN |
| Solicitar y conversar | Cliente, `/servicios` → detalle → solicitud. Ambos, detalle en `/cuenta/solicitudes/{id}` | Solicitud pendiente y mensajes privados de ambos actores |
| Acordar | Prestador acepta la solicitud. Cliente consulta y acepta desde Cotizaciones | Importe acordado conservado. Para $1.000 MXN, $150 de comisión incluida y $850 de complemento |
| Proponer | Cliente, formulario de horario desde la solicitud | Propuesta con cotización aceptada, hora civil y revisión de disponibilidad |
| Aceptar horario | Prestador, Reservas de la solicitud | Retención de 900 segundos. Aún queda pendiente la confirmación del cliente |
| Probar checkout | Cliente, `/cuenta/reservas/{id}/checkout` | Tarjeta ficticia aprobada, rechazada o con fallo temporal. Efectivo pendiente hasta el registro del prestador |
| Registrar efectivo de prueba | Prestador, enlace «Consultar recepción de efectivo» | Resultado sintético de recepción. El estado de la reserva sigue su propio flujo |
| Confirmar | Cliente, «Confirmar definitivamente mi reserva» | B2 revalida plazo, versión y clave de envío. El estado cambia a confirmada tras el comando |
| Realizar y cerrar | Prestador, detalle de la solicitud | Después del horario, marcar como realizado y luego finalizar |
| Reseñar | Cliente, «Comparte tu experiencia» | Reseña vinculada a un servicio realizado. Historial y conversación quedan disponibles |
| Verificar actividad | Prestador y cliente, paneles de cada rol | La reserva realizada aparece en los indicadores y el historial |

## Preparar una copia aislada

Instala las dependencias del lock en una copia nueva, fuera de Laragon y sin `.env`, caché de configuración ni base existente. El lanzador B3.2 crea sus cuentas y esquema en memoria mediante PHPUnit, exporta una SQLite nueva con marcador privado y escucha en `127.0.0.1`. Mantén ese límite durante toda la demostración.

En PowerShell, desde la raíz de esa copia:

```powershell
composer install --no-scripts --prefer-dist --no-interaction
npm.cmd ci
npm.cmd run build
powershell.exe -NoProfile -File .\tools\tekora-b32-launch-windows.ps1
```

Abre `http://127.0.0.1:8097/login` en dos perfiles separados. Usa las credenciales temporales que entrega el lanzador y mantenlas fuera de capturas y registros. El lanzador prepara una solicitud pendiente y una reserva ya retenida para mostrar el checkout. Para recorrer desde catálogo, publica un servicio con precio usando la disponibilidad del prestador ya sembrada y elige un horario distinto del reservado por la fixture. El plazo de la reserva precargada comienza al generarla. Si vence, crea otra fixture o recorre una nueva propuesta. Detén el servidor con Ctrl+C.

En una presentación manual, completa el servicio después de su hora de término. El arnés automatizado adelanta un reloj de prueba exclusivo de su proceso para acreditar realización y reseña en una sola ejecución.

## Repetir la validación

Con el build generado y PHP con PDO SQLite, ejecuta:

```sh
php vendor/bin/phpunit --filter 'LocalIntegratedCheckout|LocalCheckoutAttemptStore|ContractingJourney|WebPricingTest|Reservation.*Test|Review.*Test|MobileConfirmationReadiness|MobileCheckoutHandoff' --log-junit /ruta/privada/w71-focal.xml
php vendor/bin/phpunit --log-junit /ruta/privada/w71-completo.xml
php vendor/bin/pint --test app/Payments/LocalCheckoutAttemptStore.php app/Http/Controllers/LocalIntegratedCheckoutController.php tests/Feature/LocalIntegratedCheckoutHttpTest.php
```

La suite fuerza SQLite en memoria. Las pruebas integradas crean sus propios archivos temporales y ejecutan comprobaciones de integridad y FK. Para advertencias de carga del entorno en Windows, aplica el procedimiento temporal de [WINDOWS-ISOLATED.md](../../testing/WINDOWS-ISOLATED.md).

Para Chromium en Linux, con Playwright Python, Chromium y `tzdata` disponibles:

```sh
python3 tools/tekora-w71-browser.py --php php --chromium /usr/bin/chromium --evidence /ruta/nueva/w71-browser
```

El arnés usa el exportador oficial y dos identidades que interactúan por formularios reales. El prestador se registra desde la web, activa su rol y publica su perfil, disponibilidad y servicios. El cliente usa una de las cuentas de la fixture. Las dos identidades hacen también una segunda contratación para probar la selección y recepción ficticia de efectivo con JavaScript desactivado. El filtro del panel del prestador y la tabla diaria se prueban con JavaScript desactivado.

Una conexión independiente de solo lectura comprueba el resultado persistido y los PRAGMA. El arnés guarda capturas y JSON sin incluir la base ni contraseñas o tokens. Comprueba el ancho del documento a 320, 375, 430, 768, 1024, 1440 y 2048 px. Las verificaciones de foco y movimiento reducido cubren el recorrido automatizado. Queda pendiente una auditoría formal de accesibilidad.

## Hallazgos

- **P2.** El paso principal invitaba a confirmar cuando B3.2 requería primero una selección ficticia. Ahora utiliza la preparación M2.1 y lleva al checkout en ese caso.
- **P2.** El resultado de tarjeta decía «¡Pago realizado con éxito!» antes de confirmar B2. El checkout ahora identifica el resultado de prueba y muestra por separado el estado de la reserva. Cuando vence el plazo, conserva el resultado histórico y orienta al detalle.
- **P2.** El prestador veía el botón de recepción ante efectivo pendiente con retención vencida. La vista reutiliza la comprobación temporal del comando y conserva el estado pendiente en el historial.
- **P2.** Con JavaScript desactivado, seleccionar efectivo enviaba además campos de tarjeta y se rechazaba en servidor. El formulario de respaldo usa el mismo POST con CSRF, método e idempotencia. Los campos de tarjeta conservan su validación original.
- **P3.** El checkout mueve el foco al resultado o al formulario de reintento. El bloqueo visual de doble envío y la restauración del contador mantienen el plazo original.
- **P3.** README usaba una rama histórica como instalación actual. La instalación apunta a main y las referencias históricas siguen disponibles.

## Resultados sobre la base actual

Base `1f24bd4fbb20959c771e7092383bbc7e63fe8fc7`. PHP 8.4.27, PHPUnit 11.5.57, SQLite 3.46.1 y Chromium 155.0.8059.79. Las verificaciones se ejecutaron en Linux con datos sintéticos.

| Comprobación | Resultado |
| --- | --- |
| Suite Laravel completa | 1.369 pruebas y 23.937 aserciones |
| Regresiones W7.1, B3.2 y flujos relacionados | 373 pruebas y 5.617 aserciones |
| Contratos móviles, incluida la instalación M3.1 | 204 pruebas y 6.633 aserciones |
| Instalación nueva con caché de base de datos | 39 migraciones, reloj registrado automáticamente, `integrity_check=ok`, claves foráneas activas y sin infracciones |
| Pint, diffs y build Vite | Salida 0. Vite resuelve dos fuentes en ejecución desde `public/fonts/tekora`. |
| Chromium | 126 capturas a siete anchos, sin desbordamiento del documento ni errores inesperados de página, consola o HTTP |

[Perfil profesional en tablet](evidence/00-perfil-profesional-768.png) · [Tarjeta ficticia aprobada y reserva aún pendiente en móvil](evidence/09-tarjeta-aprobada-375.png) · [Reserva confirmada en tablet](evidence/10-reserva-confirmada-768.png) · [Efectivo pendiente en el prestador, sin JavaScript](evidence/14-efectivo-prestador-375.png) · [Historial al vencer](evidence/16-retencion-vencida-375.png) · [Panel del prestador en escritorio](evidence/17-dashboard-prestador-1440.png) · [Mediciones Chromium](evidence/chromium.json) · [Comprobaciones de SQLite](evidence/sqlite.json)

## Orden propuesto para las PR #51 y #58

La PR #57 de validación integral M3.1 está integrada en la base. La PR #58 de extensión del checkout móvil sigue abierta, con SHA `25d502629ba9a9a4b4284c6e17f677835901e2f1`. Añade el GET/POST `/api/v1/reservations/{reservation}/demo-checkout`, fechas de comprobante y una vista del prestador propietario. Ambas propuestas reutilizan `CheckoutContext`, la misma reserva y cotización y `LocalCheckoutAttemptStore`, con B3.2 limitado a la SQLite marcada de loopback.

La simulación de los dos árboles detectó un archivo compartido, `LocalIntegratedCheckoutHttpTest.php`, sin conflicto textual. En una copia temporal de la combinación pasaron 28 pruebas con 935 aserciones. Dos comprobaciones adicionales demostraron que la solicitud del prestador puede mostrar dos enlaces de recepción de efectivo y que el POST móvil admite parámetros en la URL aunque OpenAPI documenta un cuerpo JSON.

1. Revisar y autorizar primero **#51**, que corrige la experiencia web y el formulario de efectivo sin scripts. Los contratos móviles quedan en las 49 operaciones de la base durante esa revisión.
2. Actualizar **#58** sobre el resultado de #51. Conservar un solo enlace de recepción para el prestador según el estado autorizado, exigir un cuerpo JSON sin campos en la URL en el POST móvil y actualizar el inventario y las guías del laboratorio a las 51 operaciones propuestas. La aplicación móvil solo debería usar esas dos rutas en el laboratorio autorizado.
3. Antes de autorizar #58, probar selección web y lectura móvil, selección móvil y consulta del prestador, reintentos y vencimiento exacto. Confirmar que «Pagado (prueba)» se vincula a la cotización exacta y a una reserva confirmada o realizada, que el efectivo pendiente sigue pendiente hasta la recepción autorizada y que las cancelaciones conservan el comprobante histórico sin atribuir un reembolso. Mantener las comprobaciones fuera de loopback, sin marcador y con otra cuenta.

Quedan pendientes el ensayo manual en Windows o XAMPP sobre una copia nueva, la prueba de Expo Web para #58 en el laboratorio local autorizado y el ajuste en W7.2 del texto recortado en el selector de género a anchos pequeños.
