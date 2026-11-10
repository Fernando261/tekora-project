# W6.1 · Retirada de MindBloom como producto

Estado: **auditoría documental para revisión; ningún corte implementado**. Base exacta de `main`: `576c937fa3cbd31e6af95eedc1651cb59a16d898`. Rama: `codex/w61-mindbloom-decommission-audit`.

Entrega: [PR #24 en borrador](https://github.com/Fernando261/tekora-project/pull/24). SHA de implementación documental: `876fb061db277be0ef652da036365c24962661c1`. Un commit posterior registra estos metadatos y su verificación; el SHA final se publica en la descripción del PR y en la entrega, evitando un hash autorreferencial dentro del documento.

## Resultado propuesto

Tekora debe ser la única experiencia de producto. No se propone rediseñar MindBloom, crear otra comunidad, convertir la maqueta de conferencias en una agenda ni importar citas como reservas. Se propone preservar el historial y retirar progresivamente los accesos y superficies anteriores, después de resolver propiedad, compatibilidad y conservación.

La ingeniería aprovechable ya incluye autenticación central, `User`, roles y policies; solicitudes, mensajes privados, disponibilidad, reservas, reseñas y avisos nativos; componentes W1–W5 y el ciclo seguro de fotografías W5.1. Las relaciones de propiedad y la separación del historial son requisitos reutilizables del código anterior. El adaptador Google solo es una posibilidad condicionada a una necesidad real; su implementación actual no está lista para conectarse a reservas nativas.

Esta entrega contiene el [mapa de dependencias](W61-DEPENDENCY-MAP.md), [34 decisiones con sus quince campos](W61-DECISION-MATRIX.md), [secuencia de PRs](W61-CUTOVER-ROADMAP.md), [validación Windows sin aplicación ni `.env`](W61-LARAGON.md) y [evidencias de método](evidence/README.md). Las decisiones son propuestas; INVESTIGAR identifica información insuficiente.

## Qué aprovechar y qué evitar duplicar

| Capacidad | Decisión respaldada por código | Límite |
| --- | --- | --- |
| Identidad central y experiencia de cuenta | Reutilizar `User`, `RoleMemberships`, policies y componentes nativos; D23/D33 | `Profesionista` es otra entidad autenticable. No fusionar por email ni por ID. |
| Contratación y seguimiento | Reutilizar `InquiryWorkflow`, `InquiryMessaging`, `ProviderAvailability`, `ReservationWorkflow`, `ReviewWorkflow`; D33 | Las nuevas operaciones siguen en Tekora; no portar el flujo antiguo de Cita. |
| Perfil y medios personales | Conservar `ProfileController` W5.1 y sus pruebas; D24 | No copiar el borrado directo de archivos de comunidad. |
| Historial Cita | Adaptar lectura por propietario y procedencia; D02/D12/D13 | No insertar en métricas de solicitudes/reservas ni exigir un rol nuevo para recuperar acceso existente. |
| Calendar | Investigar un adaptador con propiedad, idempotencia y consistencia SQL/externa; D16/D17 | No activar OAuth, copiar tokens ni reutilizar el controlador auxiliar sin rutas. |
| Comunidad, likes, recordatorios y PDF de emociones | No hay necesidad Tekora confirmada; D06–D09/D21/D30 | Conversaciones privadas no sustituyen semánticamente un feed global. Una biblioteca disponible no justifica un módulo. |
| Conferencias y bienestar editorial | Retirar la experiencia visible cuando se apruebe compatibilidad; D19/D20/D22 | Mantener datos, archivos y procedencia hasta política de archivo aprobada. |
| CSS, fuentes y scaffolding | Selección por consumidores; D25–D28/D32 | `home.css` sigue en el shell moderno; licencias OFL se conservan; Vite aún compila el scaffolding. |

Las referencias exactas, riesgos y pruebas de cada decisión están en la matriz. Todas apuntan al SHA base, no a líneas móviles de `main`.

## Bloqueos que determinan el orden

1. **Historial y autorización:** `/citas` y `/cuenta/citas` usan `UserCitaController`, pero el segundo añade `client.appointments.view`. Una redirección indiscriminada puede impedir acceso a propietarios históricos sin ese permiso. El panel profesional solo lista pendientes: no constituye por sí mismo un archivo completo. D02/D12/D13.
2. **Identidad y sesiones:** `web` y `profesionista` tienen providers distintos, pero ambos logouts invalidan la sesión. No son dos contenedores de sesión garantizados. La retirada del segundo guard exige un mecanismo explícito de acceso a historial, no una equivalencia de email. D04/D18.
3. **Fotografías W5.1:** `ProfileController::photoMayBeReferenced` consulta `users`, `posts`, `comments` y `emotions`. Ocultar esas pantallas no libera las tablas. Si desaparecen, el tratamiento conservador de errores puede omitir limpieza/compensación de archivos. Debe sustituirse el catálogo de referencias antes de una retirada física. D24.
4. **Medios de comunidad:** se escriben directamente en `public/uploads/`, con borrado de archivo previo al soft delete y sin el protocolo W5.1 de referencias compartidas. Quitar rutas no despublica URLs de archivos. La vista de comentarios usa campos del post en su bloque de adjunto; no ver un archivo no demuestra que no exista. D07/D08/D29.
5. **Calendar:** la aceptación crea un evento antes de actualizar SQL; no se observa idempotencia ni atomicidad entre ambos sistemas. La consulta de disponibilidad puede renovar tokens y tolera fallos FreeBusy como ausencia de ocupación externa. Desconectar solo anula credenciales locales; no revoca Google ni borra eventos. D10/D13/D16.
6. **Código compartido:** eliminar `home.css`, permisos cuyo nombre incluye “appointments”, dependencias Google/PDF/DBAL o assets por un contador cero puede romper shell, instalación histórica, autenticación, build o licencias. D23/D25–D28/D32.

Son hallazgos estáticos y riesgos, no afirmaciones de explotación, uso real, cantidad de usuarios ni estado de cuentas Google.

## Superficies conocidas y alcance del inventario

El mapa cubre inicio `/mindbloom/inicio`, emociones públicas, PDF, comunidad y medios, citas y directorio, disponibilidad y solicitudes profesionales, Calendar activo y auxiliar, login/registro profesional, aliases, avisos, sidebar, footer, perfil, tarjeta de historial del cliente, maqueta de conferencias, CSS/JS/imágenes/fuentes, scaffolding y relaciones SQL. Incluye superficies sin la palabra MindBloom literal, como `emotion.show`.

Se localizaron **93 coincidencias de marca en fuentes**: 75 en fuentes de ejecución (incluidos comentarios, rutas y CSS) y 18 en pruebas. No equivalen a 93 textos visibles. El [inventario de coincidencias](evidence/branding-occurrences.json) identifica archivo y línea; los enlaces y consumers se interpretan en el mapa y la matriz. Los documentos y tools históricos no se cuentan como experiencia publicada.

Se analizaron **839 fuentes de texto versionadas**, dentro de un árbol de **1.381 entradas**. El inventario de assets contiene **62 entradas**, con **17 candidatas sin referencias literales de ejecución**. Esto no prueba inactividad: pueden existir referencias dinámicas, URLs guardadas, datos o exigencias de licencia. Solo se contó metadata Git de nueve objetos versionados bajo `public/uploads/`; no se abrió su contenido ni se publican sus nombres individuales. La cantidad de medios de una instalación real es desconocida.

Los antecedentes [W3](../w3/W3-MINDBLOOM-INVENTORY.md), [W4](../w4/W4-MINDBLOOM-CLEANUP.md) y [W5](../w5/W5-MINDBLOOM-INVENTORY.md) justificaban conservar compatibilidad en esas etapas. W6 cambia el objetivo de producto, sin convertir esa decisión en permiso para borrar. El defecto de fotografías descrito en W5 fue corregido en W5.1, ya incluido en esta base; no se registra aquí como defecto pendiente.

## Decisiones pendientes del propietario

| Pregunta | Resolución necesaria antes de ejecutar | Mientras tanto |
| --- | --- | --- |
| ¿Hay una necesidad concreta de feed/comunidad? | Caso de uso, audiencia, moderación, retención y diferencia frente a conversaciones; D06–D09 | INVESTIGAR. No construir comunidad nativa. |
| ¿Cómo debe consultarse el historial profesional y de clientes sin rol? | Política de acceso y prueba de propiedad, con todos los estados históricos; D02/D12/D18 | No cerrar acceso ni asignar roles automáticamente. |
| ¿Se congelarán nuevas citas/publicaciones antiguas y cómo se resuelven pendientes? | Fecha de corte, comunicación y contrato HTTP de escrituras; D07/D11/D13 | Ninguna escritura se cambia en W6.1. |
| ¿Qué datos/medios se archivan, quién los ve y por cuánto tiempo? | Política explícita que respete audiencia original, soft deletes, enlaces públicos y exportación; D20/D21/D29 | No borrar, mover, republicar ni inspeccionar datos. |
| ¿Calendar aporta valor confirmado a las reservas Tekora? | Dueño de calendario, scopes, conflictos, revocación, eventos existentes y trazabilidad; D16 | Sin llamadas, transferencia de tokens ni reactivación del controlador auxiliar. |
| ¿Se necesita vincular una identidad profesional antigua? | Proceso de reclamación verificado, consentimiento, conflictos y recuperación; D18 | Email coincidente e IDs iguales no son prueba. |
| ¿Hay consumidores fuera del repositorio? | Inventario autorizado de URLs guardadas, integraciones, instalaciones y datos dinámicos; D26/D29/D32 | Cero referencia estática no habilita retirada. |

Responder a estas preguntas puede requerir en el futuro una inspección de datos expresamente autorizada y delimitada. No se ha realizado en esta auditoría. Las ramas condicionadas del roadmap no suponen respuesta afirmativa.

## Criterios para declarar eliminado MindBloom como producto

- Toda entrada y navegación pública/privada ofrece una sola plataforma Tekora; desaparecen menú, footer, accesos de login y tarjetas que abren una experiencia alternativa.
- Inicio anterior, conferencias, directorio, comunidad y flujos profesionales dejan de ofrecerse como producto independiente. Las URLs conservadas resuelven conforme a una tabla de compatibilidad aprobada y probada.
- El historial necesario está dentro de Tekora, marcado con procedencia, con propiedad verificable y sin inflar métricas ni aparentar reservas nuevas. El guard profesional se retira solo tras resolver su acceso y recuperación.
- Ninguna operación nueva depende de pantallas, modelos de workflow ni integración Calendar antiguos. La retirada técnica puede conservar adaptadores de lectura o tablas de archivo explícitas, sin mantener un producto paralelo.
- Los formularios antiguos no se redirigen para ejecutar operaciones nuevas; callback OAuth y enlaces públicos tienen transición explícita. No se pierde información ni se republica contenido eliminado.
- API, contratos de autorización, W1–W5, fotografías W5.1 y métricas reales pasan las regresiones correspondientes. Las instalaciones nuevas conservan sus migraciones históricas hasta un plan distinto aprobado.
- Código y assets retirados tienen consumidores resueltos y reversión probada. La retirada de datos tiene autorización separada y no es requisito para ocultar el producto.

Estos criterios **aún no se cumplen por esta entrega documental**. W6.2 no se ha iniciado.

## Verificación de W6.1

Solo se añaden documentos y evidencias bajo `docs/web/w6/`. No se ejecutó Laravel, PHPUnit, Composer, npm, rutas HTTP, OAuth, migraciones ni acceso a almacenamiento de una instalación. No se modificaron `.env`, APP_KEY, cuentas, sesiones, credenciales, bases persistentes, fotos ni medios. No se abrieron contenidos de medios.

La baseline **626 pruebas / 8.258 aserciones** es la validación Windows previamente aprobada por el usuario, **no una ejecución nueva**. Las **21 operaciones API** y **37 migraciones** se comprobaron estáticamente y sus fuentes permanecen idénticas al SHA base. El [informe de verificación](evidence/verification.json) registra alcance, modos de archivo y comprobaciones documentales. La lista exacta de entrega figura en [delivery-files.json](evidence/delivery-files.json).

El PR debe permanecer en borrador para revisión independiente. No fusionar, desplegar, activar auto-merge ni iniciar la siguiente etapa sin aprobación de W6.1.
