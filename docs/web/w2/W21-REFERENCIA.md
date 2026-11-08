# W2.1 · Auditoría del MP4 original

Análisis realizado antes de editar la aplicación, el 8 de noviembre de 2026. Fuente: `WhatsApp Video 2026-11-05 at 11.53.00 AM.mp4`, adjunto del usuario; 720 × 1280, 30 fps, 5,533 s. La interfaz ocupa aproximadamente x=36, y=396, ancho=650, alto=366. Los fotogramas de esta carpeta se extrajeron directamente con FFmpeg y ese recorte: excluyen título externo, créditos y código. No se utilizaron imágenes generadas como referencia.

## Lectura de la referencia

1. **Estructura:** ventana compacta de cristal gris oscuro; barra de marca/cuenta, fila de navegación y área de módulos. El fondo se percibe tras las superficies, sin bordes de neón dominantes.
2. **Navegación:** opciones pequeñas en una única línea. Un solo fondo azul identifica la opción activa; permanece al pasar entre secciones.
3. **Composición:** Home y Energy tienen cuatro resúmenes horizontales; debajo hay un protagonista ancho y un panel secundario. Rooms y Media cambian a bloques de distinta proporción. La jerarquía no consiste en diez tarjetas grandes iguales.
4. **Cápsula:** en el fotograma de 1,15 s se observa su elongación entre las primeras opciones. El fragmento de código del MP4 identifica bordes independientes y duraciones 0,36/0,55 s; esas duraciones guían la implementación, no son una medición independiente de cada fotograma.
5. **Transiciones:** barra superior y navegación permanecen; paneles salen/entran con desplazamientos y composiciones distintas. No desaparece toda la página ni se produce un giro de autenticación.
6. **Color:** predominan carbón, gris y verde petróleo; los cuatro resúmenes incorporan azul, verde azulado, verde oliva y coral apagado. Cian en controles/series; acentos sin saturar toda la pantalla.
7. **Tipografía:** título principal contenido, valores claros, descripciones breves. El texto diminuto del video no se copiará a escala: Tekora utilizará texto de 13–16 px y títulos de 28–34 px.
8. **Profundidad:** imagen doméstica difusa detrás de paneles translúcidos y límites discretos. Tekora traducirá esa profundidad a luces y geometría local original; no copiará la vivienda ni sus fotografías.
9. **Correspondencias:** barra compacta, cápsula, resumen en cuatro, módulo protagonista, listas secundarias y controles integrados se aplican a datos existentes de Tekora.
10. **Exclusiones:** temperatura, dispositivos, consumo, dinero, reproductor, personas y habitaciones de la referencia no existen en estos contratos; no se incorporarán ni se simularán.

## Mapa a Tekora

| Referencia | Fotograma original | Adaptación de Tekora |
| --- | --- | --- |
| Home, cuatro resúmenes y módulos inferiores | [0,50 s](media/w21/reference/reference-home.png) | Cliente: cuatro KPI propios y próxima reserva/solicitudes. Prestador: pendientes, próximas, publicados y mensajes, conservando sus DTO originales |
| Cápsula en movimiento | [1,15 s](media/w21/reference/reference-pill-right.png) | Un indicador recortado con bordes delantero/trasero independientes, 360/550 ms, dirección reversible y coordenadas medidas |
| Rooms, protagonista y panel lateral | [1,80 s](media/w21/reference/reference-rooms.png) | Sección Reservas/Agenda: agenda confirmada protagonista y seguimiento real; sin fotos o estados inventados |
| Energy, gráfica y bloques de detalle | [3,00 s](media/w21/reference/reference-energy.png) | Actividad del prestador: misma serie diaria, rectángulos y tabla; seis KPI secundarios compactos |
| Media, composición distinta con lista | [4,60 s](media/w21/reference/reference-media.png) | Solicitudes propias/recibidas: listas autorizadas y accesos existentes; sin reproductor o datos domésticos |

## Decisiones de implementación

La sidebar global se conserva, más secundaria. La barra compacta incorpora Tekora y cuenta. La subnavegación local mantiene enlaces ancla cuando no hay JavaScript; al mejorarla crea botones de tab con flechas/Home/End y selección accesible. Un panel persistente contiene módulos del servidor compartidos entre composiciones, sin duplicar KPI, SVG ni formularios. Su nombre accesible sigue la pestaña seleccionada.

La cápsula utiliza `clip-path: inset(... round ...)`, calculado desde los rectángulos reales; el extremo delantero recorre su distancia en 360 ms y el trasero en 550 ms. El cambio de contenido coordina salida corta, movimiento FLIP por traslación de módulos que permanecen y entrada de módulos nuevos. No se recalculan datos ni se interceptan los GET/POST existentes.

El fondo será neutro con luz azul/verde/violeta lenta y geometría tenue original. Los cuatro KPI principales alternan azul, menta, ámbar y coral/violeta apagados. Los seis secundarios conservan etiquetas, notas, enlaces y `data-kpi`, disponibles en Actividad y como contenido normal sin JS. La gráfica conserva toda su geometría; cualquier línea/área añadida unirá exclusivamente los mismos puntos diarios, sin segunda serie ficticia.

Con movimiento reducido se selecciona instantáneamente; con transparencia reducida se usan paneles opacos. Si falla Anime.js permanecen la selección y los datos. W1.1 Login/Registro, servicios de lectura, autorización, API, migraciones y workflows quedan fuera de esta corrección.

La comparación final con capturas de implementación, mediciones y compromisos funcionales se documentará en `W21.md`. La fidelidad se evalúa por composición, continuidad, contraste de módulos y movimiento de cápsula; no por copiar contenido de domótica ni reducir la legibilidad al tamaño del MP4.
