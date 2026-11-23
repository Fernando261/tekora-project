# V8.1 · Fundamentos MySQL 8.4

El fundamento V8.1 prueba identidad y solicitudes sobre InnoDB. V8.1.2 añadió disponibilidad de prestadores y V8.1.3 incorpora el esquema de reservas e historial al mismo laboratorio. La instalación completa de Tekora sobre MySQL sigue **BLOCKED**. El diagnóstico previo queda en [PR #59](https://github.com/Fernando261/tekora-project/pull/59).

## Esquema y transacciones

Las 39 migraciones históricas permanecen intactas. El laboratorio selecciona las primeras 34 identidades de migración, hasta reservas, y añade Sanctum, que no depende de los módulos comerciales. La ruta MySQL alternativa usa las mismas identidades para las cinco variantes. El migrador ordinario conserva su selección existente y las guardas de los módulos SQLite.

- **Solicitudes.** Una columna generada invisible vale 1 para estados `pending` y `accepted`, y NULL para los terminales. El índice único sobre servicio, cliente y esa columna impide dos solicitudes activas a nivel de InnoDB. El estado tiene un CHECK con comparación binaria. El historial terminal permite múltiples filas y la columna generada queda fuera de `SELECT *`.
- **Mensajes y lecturas.** Las dos tablas conservan unicidad de envío y la FK compuesta que une el marcador a un mensaje de la misma conversación. MySQL limita los nombres a 64 caracteres, por lo que las variantes asignan nombres cortos. Los índices de mensajes se crean junto a su tabla. Cada variante crea su tabla en una sola operación DDL.
- **Escrituras.** El alta de solicitud conserva el bloqueo de servicio y el aviso en la misma transacción. MySQL bloquea la fila de solicitud antes de enviar el mensaje. SQLite mantiene su UPDATE condicional como admisión del escritor. El marcador MySQL actualiza ID y fecha solo al avanzar y relee la fila actual con bloqueo para respetar REPEATABLE READ. El orden de conversaciones sigue priorizando no leídos, luego ID del último mensaje e ID de solicitud, con la misma consulta para web y API.
- **Disponibilidad.** MySQL bloquea el perfil antes de consultar la revisión, incluso durante el primer guardado. Zona IANA, revisión, intervalos y fechas se escriben en la misma transacción. La lectura pública comparte el bloqueo del perfil para obtener una configuración coherente también bajo READ COMMITTED. La fecha se conserva como un día civil del prestador.
- **Exclusión.** Los triggers InnoDB de INSERT y UPDATE bloquean el perfil de destino y hacen una lectura actual de intervalos. Se rechazan solapamientos de rangos semiabiertos y se admiten límites consecutivos. CHECK y claves foráneas restringen días, minutos, revisiones, fechas del calendario y propiedad de las filas. El caso de dos procesos verifica la espera de bloqueo y el rechazo tras confirmar, aun con una instantánea anterior.
- **Reservas.** El esquema conserva una reserva activa por solicitud y una clave idempotente por cliente y solicitud, incluso después de una transición terminal. Las fechas civiles y UTC permanecen en formato canónico, sin conversión de zona en el motor. Los CHECK rechazan fracciones en minutos y versiones, fechas inválidas y duraciones distintas de 60 minutos. La correspondencia de hora civil a UTC y los cambios DST se comprueban con el resolvedor PHP existente.
- **Locks de reservas.** El trigger adquiere la solicitud en exclusivo, el servicio en compartido y el perfil en exclusivo. La comprobación de intervalos UTC confirmados usa una lectura actual con `FOR SHARE` bajo el bloqueo del perfil. Los triggers de los padres protegen cierre e identidad con lecturas actuales. Un UPDATE directo de reserva puede llegar con su fila ya bloqueada, y un flujo que bloquea el servicio primero puede intercalarse. El laboratorio fuerza un deadlock y comprueba el reintento de la transacción revertida con un único evento final.
- **Historial de reservas.** Cada alta o transición escribe el evento en la misma sentencia. El historial valida origen, destino, versión, actor, motivo y hora, e impide cambios y borrados. El fallo del evento revierte el cambio de reserva. El laboratorio valida reintentos tras conflictos conocidos y rollback. El manejo de COMMIT incierto del futuro adaptador MySQL queda **NOT RUN**.
- **Límites.** DDL en MySQL puede confirmar implícitamente. Una base que haya sufrido una instalación interrumpida requiere inspección y recuperación explícita. La prueba de segunda ejecución cubre el esquema instalado correctamente. El laboratorio crea una base nueva para cada ejecución. El puerto TCP y el plugin X quedan apagados. La prueba de rollback usa un trigger de fallo en la base efímera, con binlog desactivado exclusivamente en el laboratorio.

## Matriz de las 39 identidades de migración

| Archivo o tramo ordenado | Cantidad | MySQL V8.1 | Comprobación |
| --- | ---: | --- | --- |
| `0001_01_01_000000` a `0001_01_01_000002` | 3 | PASS | Instalación. Usuarios, tokens de recuperación y sesiones ejercitados |
| `2025_08_02_085013` a `2026_02_24_075954` | 21 | PASS DDL | Historial legado conserva tablas, índices y FK. Funciones legadas fuera de esta validación |
| `2026_10_23_000000` a `2026_10_28_000001` | 4 | PASS | Roles, perfiles, categorías y servicios. Catálogo público depende además de pricing/reseñas |
| `2026_10_28_000002` solicitudes | 1 | PASS | Variante InnoDB, CHECK, FK, exclusividad activa y carrera de altas |
| `2026_10_29_000000` avisos de solicitud | 1 | PASS | Unicidad y rollback conjunto de alta y decisión |
| `2026_10_30_000000` y `000001` mensajes y lecturas | 2 | PASS | Variantes InnoDB. FK compuesta, reintento, alcance, orden y marcador monótono |
| `2026_10_30_000002` disponibilidad | 1 | PASS | Fechas civiles, edición y lectura coherente, permisos, revisión, exclusión y rollback |
| `2026_11_03_000000` reservas | 1 | PASS esquema | Exclusión de reservas activas por solicitud, ocupación confirmada por prestador, transiciones, participantes e historial |
| `2026_11_03_000001` reseñas | 1 | BLOCKED | Requiere reserva realizada y restricciones de registro/retirada |
| `2026_11_03_000002` avisos de actividad | 1 | BLOCKED | Destinatarios y unicidad de eventos de reserva/reseña |
| `2026_11_04_000000` Sanctum | 1 | PASS | Emitir Bearer, alcance móvil, invalidación y revocación |
| `2026_11_18_000000` precios y cotizaciones | 1 | BLOCKED | Tablas append-only, identidad del snapshot y adaptador MySQL |
| `2026_11_19_000000` retenciones B2 | 1 | BLOCKED | High-water UTC, 900.000.000 μs, exclusión, recibos y transiciones |

**Total MySQL:** 35 identidades ejecutadas por el laboratorio, 4 pendientes. Un PASS DDL acredita la creación del esquema en ese tramo. SQLite continúa usando las 39 migraciones originales.

## Matriz de web y API

| Capacidad | MySQL V8.1 |
| --- | --- |
| Registro móvil y confirmación | PASS con aviso simulado dentro de PHPUnit |
| Login Bearer, `/me`, logout y revocación tras recuperar contraseña | PASS |
| Perfil móvil `GET/PATCH /me/profile` | PASS. Operaciones GET/POST de foto **NOT RUN** |
| Inicio web y fila de sesión InnoDB | PASS en kernel Laravel. Sesión a través de Nginx/FPM **NOT RUN** |
| Solicitudes `GET /inquiries`, `GET /inquiries/{id}`, alta desde servicio | PASS. Cancelación HTTP **NOT RUN** en MySQL |
| Decisiones de solicitud | PASS para aceptar/rechazar desde el servicio de dominio. Cierre con realización **BLOCKED** por el adaptador y B2 |
| Conversaciones, listado, envío y lectura | PASS API. Vista web comparte orden SQL y se cubre en las regresiones SQLite |
| Categorías, catálogo y prestadores | Funcionalidad pública **NOT RUN** en este laboratorio. Proyecciones de precios o reseñas requieren los módulos pendientes |
| Disponibilidad semanal, fechas bloqueadas y zona del prestador | PASS en el servicio compartido. PHP valida las zonas IANA y MySQL aplica límites de almacenamiento y fechas civiles |
| Opciones para reservar y proyecciones comerciales del catálogo | BLOCKED |
| Reservas e historial mediante SQL directo | PASS en el esquema y las políticas de participantes. Espera InnoDB, conflicto de versión, solapamiento, rollback y reintento probados |
| Web/API de reservas, vistas de ocupación y reservas activas, retenciones B2 | BLOCKED. `ReservationTransactions` sigue siendo SQLite y las vistas se crean en B2 |
| Listar, emitir, aceptar y consultar cotizaciones; contexto de checkout | BLOCKED |
| Historial de contratación y dashboard | BLOCKED por lecturas comerciales pendientes |
| Reseñas privadas y públicas | BLOCKED |
| Bandeja y lectura de notificaciones | BLOCKED. La unión incluye actividad. Persistencia de avisos de solicitud validada por separado |
| Checkout B3.2 web y endpoints `demo-checkout` | Se verifican los 404 de la demo visual, GET/POST del checkout web y GET/POST móvil en entorno MySQL con configuración de producción simulada |
| Pagos reales | Fuera de alcance |
| HTTPS, dispositivo móvil, Windows, SMTP real y restauración de respaldos | NOT RUN |
| Entrega VPS versionada | BLOCKED hasta la paridad comercial e instalación completa |

## Repetir en Docker efímero

En una copia nueva de Tekora, instalar `composer install --no-scripts --prefer-dist --no-interaction` con las dependencias de desarrollo. Sin `.env` ni caché de configuración. Requiere Docker, Bash y OpenSSL.

```sh
docker pull mysql@sha256:cd05748e60471137face0ba0a256c9c48669198aee1f12132f413fc91fd8948f
docker build -t tekora-v81-lab-php:8.4.27 tools/mysql-v81
bash tools/mysql-v81/run.sh /tmp/tekora-v81-evidence
```

El destino de evidencias debe ser nuevo y estar fuera del checkout. El runner monta código y dependencias como solo lectura. MySQL y PHP usan `--network none` y comparten únicamente el socket Unix para el acceso a la base. El usuario del laboratorio recibe permisos en un esquema nuevo. El runner registra versión, motor, aislamiento, manifiesto Docker y JUnit. Reinicia el mismo contenedor, repite las migraciones y comprueba la solicitud, su aviso, la disponibilidad, la reserva confirmada y sus dos eventos. Al finalizar libera el contenedor y los volúmenes que creó.

La suite MySQL se ejecuta separadamente de PHPUnit normal, cuyo bootstrap sigue forzando SQLite desechable. Las pruebas diferencian 1062 por unicidad, 1451/1452 por FK, un lock wait 1205 y un deadlock real cuya víctima reintenta bajo Laravel. La prueba de deadlock comprueba que los efectos de su intento revertido no se duplican. Esa evidencia aplica a las transacciones probadas, no a B2.

## Cobertura local y GitHub Actions

| Ejecución | Qué acredita | Evidencia |
| --- | --- | --- |
| Docker local, `tools/mysql-v81/run.sh` | MySQL 8.4.13, InnoDB y PHP 8.4.27. Instalación de 35 migraciones, segunda ejecución, operaciones HTTP del fundamento, concurrencia de reservas, deadlock, rollback y persistencia tras reiniciar | `isolation.log`, logs y dos JUnit en el directorio de evidencia elegido |
| [`MySQL 8.4 foundation`](../../.github/workflows/mysql-v81.yml) | Mismo runner y suites sobre el HEAD exacto del PR. Revisa que no haya puertos publicados, que ambos JUnit estén completos y que Docker libere los recursos | Artefacto `mysql-v81-<run_id>-<attempt>` y conclusión del job en el HEAD correspondiente |
| `Mobile API integration` y `T1 isolated laboratory` | Regresiones SQLite de API móvil y T1 en PHP 8.2 y 8.4 | Resultados de sus workflows en el HEAD correspondiente |
| PHPUnit completo, Pint, lint y build locales | Regresiones de la aplicación en SQLite, estilo PHP del incremento, sintaxis y assets | Logs locales independientes de los jobs de GitHub |

El job instala `composer.lock` sin plugins ni scripts antes de arrancar la base. Los contenedores de MySQL y PHPUnit carecen de red y de credenciales externas. MySQL también usa `--skip-networking` y desactiva el plugin X. El runner comprueba la configuración de red y el número de puertos publicados antes de ejecutar las pruebas. La migración interrumpida se trata como fallo del laboratorio y el esquema se descarta. La recuperación de una instalación parcial fuera del laboratorio sigue **NOT RUN**.

## Siguiente incremento

El adaptador de reservas requerirá fijar el orden de locks con la admisión B2 y probar recuperación de COMMIT incierto. El reloj debe preservar el valor de 64 bits, impedir retrocesos y comprobar el límite exacto de 900 segundos. El adaptador de precios debe adquirir su propia transacción antes de leer versiones, conservar snapshots y calcular comisión con aritmética entera. Las garantías de reseñas y avisos dependerán de las transiciones y eventos InnoDB. El aprovisionamiento de triggers con binlog habilitado sigue **NOT RUN**, junto con la restauración de respaldos, SMTP, HTTPS y despliegue reversible de código en la VPS.
