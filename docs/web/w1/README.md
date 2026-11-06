# Tekora Web W1 · Blue Obsidian

Actualización **W1.1** en el mismo PR #18: [giro cinematográfico, ambiente global y resultados actuales](W11.md). Las evidencias de `media/` y la validación W1 original se conservan como referencia; las nuevas están en `media/w11/` y `evidence/w11/`.

Base: `208ee914ccf0aa614058ad084ebbbab90339a296`, obtenida de `origin/main` antes de implementar. Rama: `codex/web-w1-identidad-cinematica-tekora`. Entrega para PR en borrador; integración y despliegue quedan pendientes de revisión independiente y decisión del usuario.

La portada `/` ahora es pública. Consulta categorías activas, hasta tres servicios publicados y tres prestadores con servicios visibles mediante los scopes existentes. La búsqueda envía `GET /servicios?q=…` y conserva la validación de 120 caracteres del catálogo. Los estados vacíos explican que aún no hay publicaciones. No se calculan métricas, precios, disponibilidad, estrellas ni pagos nuevos.

Login y registro conservan sus formularios, campos, POST, CSRF, autofill, validación y destinos `intended`. El acceso MindBloom de profesionista sigue separado y abre con `?role=prof`. Los errores aparecen sin animar el panel. Las páginas tienen navegación normal aunque JavaScript o View Transitions no estén disponibles.

MindBloom mantiene el nombre `home`, autenticado en `/mindbloom/inicio`. La portada usa `tekora.home`. Se corrigieron las tres imágenes relativas de la vista histórica y el enlace Inicio de citas para esa ubicación. Comunidad, emociones, citas, PDFs y ambos guards conservan su funcionamiento. Los dashboards y las pantallas interiores esperan W2/W3.

## Referencia e implementación

El ZIP `Tekora_Blue_Obsidian_Web_W1.zip` y sus demo HTML/CSS/JS fueron referencia visual. No se incorporaron sus pantallas de datos ficticios, botones de demo, instrucciones internas ni snippets literalmente. Los videos descritos en el encargo aportan la inspiración de vidrio, luz y movimiento; las capturas de esta carpeta muestran la implementación Laravel propia.

La marca geométrica, placas, anillos y ornamentos están hechos con CSS. El hero responde al puntero fino; táctil y movimiento reducido reciben una escena estática. No requiere WebGL, una descarga de escena o una cuenta externa. Una futura integración Spline queda como tarea opcional: exige una escena original, permiso, URL publicada y presupuesto de peso verificables. No se instaló un visor ni se inventó una URL.

Anime.js **4.5.0**, fijado en ambos manifests npm, usa imports v4 `animate`, `stagger`, `createTimeline`. Implementa entrada del hero, aparición de tarjetas y microinteracciones. W1.1 sustituye la entrada leve y View Transitions del acceso por una secuencia de **1100 ms**, con dos caras y un giro completo, que funciona también sin View Transitions. Solo mejora los dos enlaces GET explícitos; los POST siguen siendo nativos. El formulario de destino procede de Laravel y reemplaza al anterior mientras la cara frontal está oculta. No se duplican formularios interactivos ni se leen/copían valores de credenciales.

`tekora-ambient` aporta halos CSS de baja opacidad a los layouts de portada/acceso, catálogo/perfiles, cuenta y pantallas centrales. Los interiores conservan tipografía, tarjetas y lógica; se ajustan superficies de lectura para mantener contraste. MindBloom heredado mantiene sus estilos. Con movimiento reducido el fondo es estático; con transparencia reducida desaparece.

## Assets, fuentes y CSP

- Tokens `--tk-*` en `resources/css/tekora/tekora.css`: paleta solicitada, tipografías, radios y superficies. El texto secundario se ajustó a `#A3B6CC` para mejorar contraste.
- Entradas Vite específicas de Tekora. Se conservan `resources/js/app.js` y Sass/Bootstrap históricos; Anime no se importa en API, legacy o dashboards.
- Space Grotesk y DM Sans variables, subconjunto Latin con acentos españoles, servidas desde `public/fonts/tekora`, `font-display: swap`, fallback del sistema. Licencias SIL OFL 1.1 incluidas junto a los WOFF2.
- Fuentes originales: [Space Grotesk](https://github.com/google/fonts/tree/main/ofl/spacegrotesk), [DM Sans](https://github.com/google/fonts/tree/main/ofl/dmsans). Archivos obtenidos de Google Fonts; las páginas no consultan Google Fonts en ejecución.
- CSP limitada a GET portada/login/registro: scripts, fuentes y conexiones del mismo origen; sin scripts inline, objetos o frames. `style-src 'unsafe-inline'` permite los estilos que Anime y la inclinación necesitan. En `local` se emite Report-Only para Vite; en testing y producción se aplica. No es una política global para todo el legado.
- `nosniff`, política de referrer, escape Blade y selección limitada de columnas. No se exponen emails en tarjetas ni se leen/almacenan contraseñas con JS.

## Evidencias propias

Los nombres, categorías y servicios de estas capturas proceden exclusivamente del exportador visual con SQLite `:memory:`. Son fixtures sintéticos de revisión; no están incrustados en las vistas, migraciones o seeders de producción. La versión vacía también está capturada.

| Vista | Desktop | Móvil |
| --- | --- | --- |
| Portada | [Hero](media/home-hero.png) · [Completa](media/home-desktop.png) | [375 px](media/home-mobile.png) |
| Acceso | [Login](media/login-desktop.png) | [Login](media/login-mobile.png) |
| Registro | [Registro](media/register-desktop.png) | [Registro](media/register-mobile.png) |
| Estados | [Vacío](media/home-empty.png) · [Error](media/login-errors.png) | incluidos en la matriz responsive |
| Movimiento | [Vídeo desktop](media/w1-walkthrough.webm) | [Vídeo móvil](media/w1-mobile-walkthrough.webm) |

[Validación y límites](VALIDACION.md) · [Revisión en clon Laragon aislado](LARAGON.md)
