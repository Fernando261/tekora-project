# Checkout visual — fase 1

Ruta local: **`/demo/checkout`**, nombre `demo.checkout`. Solo `APP_ENV=local` o `testing`; otros entornos responden 404. Compilar con `npm run build` y abrir la ruta mediante el servidor Laravel local habitual. Usa assets compilados del mismo origen: la CSP de esta demo no admite Vite HMR. No necesita migraciones, seeds, cuentas ni una reserva real.

## Datos y estados

El controlador crea únicamente un ejemplo de presentación. Reutiliza `CommercialAmount` sin transacciones: total **$1.000 MXN**, comisión incluida **$150 (15 %)** y prestador **$850 (85 %)**. No suma la comisión otra vez. Servicio, prestador, fecha, cotización y horario son ficticios; no se aceptan identificadores ni importes del query string. Los pasos de cotización/horario son contexto visual, no estados leídos o modificados de B2.

Todos los controles son selectores de tokens predefinidos. No hay entradas de texto, PAN completo, CVV numérico, formularios enviables, API, SDK financiero, persistencia, cookies de sesión o telemetría. Los únicos números visibles de tarjeta son sufijos de cuatro dígitos tras una máscara. El código muestra `•••` o `DEMO`. No introducir ni sustituir estos controles por campos bancarios.

Flujo: `ready → leaving → processing → success | declined | temporary`. La recuperación temporal vuelve a procesar **otra simulación**, que termina en éxito ficticio. Reiniciar conserva las elecciones controladas, restaura el formulario y devuelve el foco. Doble activación durante procesamiento se bloquea. Recargar descarta el estado visual. El éxito dice **«Simulación completada»**; no acredita cobro, no confirma reserva ni extiende un hold.

La ruta GET prescinde exclusivamente de sesión, errores de sesión y cookie CSRF porque no tiene operación de escritura ni formulario. Los demás endpoints conservan su middleware. CSP: `connect-src 'none'`, `form-action 'none'`, `frame-ancestors 'none'`; scripts, fuentes y estilos compilados son locales. No se añade ninguna dependencia: Anime.js 4.5.0 ya estaba fijado en main.

## Comparación con `pagos.mp4`

Se revisó el video completo: **5,386708 s, 360×640, 30 fps, 161 fotogramas**. Se estudió la interfaz central; se excluyeron el título de presentación, el bloque de código, branding y dorados. Los tiempos originales son aproximaciones acotadas por fotogramas de 33 ms.

| Etapa | Referencia observada | Implementación Tekora |
| --- | --- | --- |
| Inicial | 0–0,77 s: tarjeta a la izquierda, formulario a la derecha, marco de cristal y diagonales | Misma jerarquía en escritorio; tarjeta biselada, cristal azul y reflejos cian. Selector de escenarios separado del formulario principal. Resumen ficticio bajo el marco. |
| Giro | ~0,80–1,03 s: rotación sobre Y con perspectiva | Foco en código: CSS 3D y Anime.js, 280 ms, easing simétrico; reflejo y caras separadas. |
| Reverso | ~1,03–1,57 s: banda y código visibles | Banda y código controlado; reverso estable mientras el control tiene foco. Sin capturar un CVV. |
| Regreso | ~1,60–1,80 s: vuelta al frente | 280 ms al dejar el control de código o cambiar de campo; también al pasar con Tab a la acción. |
| Centrado/proceso | ~2,13–2,83 s: formulario se desvanece y tarjeta se centra; después indicador inferior | Salida escalonada ~0–360 ms, centrado 700 ms con curva cúbica (0,4 / 0 / 0,2 / 1), indicador inferior. Transformación menta a los 2.100 ms desde la activación. |
| Transformación/éxito | ~4,23 s: verde; ~4,43–4,67 s: confirmación | Menta durante 260 ms, reflejo y check dibujado; confirmación tipográfica y acción de reinicio. Sin afirmar pago. |

Se usan gradientes y textura CSS ligera en capas que se pueden componer por separado. Se evitaron filtros SVG de ruido, grandes sombras rasterizadas y desenfoque del marco que reducían la cadencia del renderizador software de escritorio. Esto conserva la geometría, los reflejos y la iluminación sin introducir dependencias.

Las pausas de interacción no se reproducen automáticamente: cada movimiento responde al foco y a la acción del usuario. La referencia solo muestra éxito; rechazo, error temporal y recuperación son extensiones sintéticas con movimiento contenido. En móvil la tarjeta precede al formulario y el marco adapta su altura para mantener visibles resultados/acciones. No es una reproducción píxel a píxel de la presentación grabada.

`prefers-reduced-motion` elimina giro animado, inclinación, desplazamiento, giro del indicador y transiciones; cambia de cara instantáneamente y mantiene los resultados. Hay foco visible, controles nativos de teclado, región de estado y retorno de foco. La tarjeta decorativa se excluye de la lectura duplicada.

## Validación

La evidencia de esta entrega se conserva fuera del repositorio: pruebas PHP, comparación por etapas en **320, 375, 430, 768, 1024 y 1440 px**, estados negativos, recuperación, teclado, movimiento reducido y cambios de viewport/preferencia durante transiciones; también una grabación con tiempo real. Los fotogramas deterministas de navegador usan solo su reloj virtual, sin alterar el reloj de Laravel, B2 ni el sistema.

No se modifican cotizaciones, reservas, holds, API móvil, dependencias ni migraciones. Las pruebas usan SQLite nuevas/en memoria. No se han ejecutado pasarelas, pagos, despliegue ni pruebas en Windows, Safari o dispositivos físicos. La presentación no prepara un backend de pagos ni certifica cumplimiento de procesamiento de tarjetas.
