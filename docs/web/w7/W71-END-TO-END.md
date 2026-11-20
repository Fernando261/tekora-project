# W7.1 · Recorrido web de contratación

La demostración usa dos cuentas sintéticas con sesiones independientes. Las decisiones pasan por los formularios existentes de Laravel. B3.2 registra resultados de prueba en una SQLite temporal y B2 conserva la confirmación definitiva por separado.

| Paso | Actor y pantalla | Resultado verificable |
| --- | --- | --- |
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

El arnés usa el exportador oficial, dos sesiones, formularios reales y una conexión independiente de solo lectura para comprobar el resultado persistido y los PRAGMA. Guarda capturas y JSON sin incluir la base ni las credenciales. Comprueba el ancho del documento a 320, 375, 430, 768, 1024, 1440 y 2048 px. La revisión de foco y movimiento reducido es una comprobación puntual de navegador y queda pendiente una auditoría formal de accesibilidad.

## Hallazgos

- **P2.** El paso principal invitaba a confirmar cuando B3.2 requería primero una selección ficticia. Ahora utiliza la preparación M2.1 y lleva al checkout en ese caso.
- **P2.** El resultado de tarjeta decía «¡Pago realizado con éxito!» antes de confirmar B2. El checkout ahora identifica el resultado de prueba y muestra por separado el estado de la reserva. Cuando vence el plazo, conserva el resultado histórico y orienta al detalle.
- **P2.** El prestador veía el botón de recepción ante efectivo pendiente con retención vencida. La vista reutiliza la comprobación temporal del comando y conserva el estado pendiente en el historial.
- **P3.** El checkout mueve el foco al resultado o al formulario de reintento. El bloqueo visual de doble envío y la restauración del contador mantienen el plazo original.
- **P3.** README usaba una rama histórica como instalación actual. La instalación apunta a main y las referencias históricas siguen disponibles.

El perímetro local B3.2, las rutas, API M2.0/M2.1, migraciones y reglas comerciales se conservan. La validación final y sus evidencias quedan en el PR W7.1. Windows nativo requiere una repetición independiente.
