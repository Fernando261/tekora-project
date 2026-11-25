# V8.1 · Fundamentos MySQL 8.4

El fundamento V8.1 prueba identidad y solicitudes sobre InnoDB. V8.1.2 añadió disponibilidad, V8.1.3 el esquema de reservas, V8.1.4 las reseñas V8.1.5 los avisos de actividad y V8.1.6 los precios, cotizaciones y aceptaciones. La instalación completa de Tekora sobre MySQL sigue **BLOCKED**. El diagnóstico previo queda en [PR #59](https://github.com/Fernando261/tekora-project/pull/59).

## Esquema y transacciones

El laboratorio selecciona las primeras 38 identidades de migración hasta precios y cotizaciones, incluido Sanctum. La ruta MySQL alternativa usa las identidades históricas de sus ocho variantes. El migrador ordinario conserva las guardas de SQLite.

- **Solicitudes.** Una columna generada invisible vale 1 para estados `pending` y `accepted`, y NULL para los terminales. El índice único sobre servicio, cliente y esa columna impide dos solicitudes activas a nivel de InnoDB. El estado tiene un CHECK con comparación binaria. El historial terminal permite múltiples filas y la columna generada queda fuera de `SELECT *`.
- **Mensajes y lecturas.** Las dos tablas conservan unicidad de envío y la FK compuesta que une el marcador a un mensaje de la misma conversación. MySQL limita los nombres a 64 caracteres, por lo que las variantes asignan nombres cortos. Los índices de mensajes se crean junto a su tabla. Cada variante crea su tabla en una sola operación DDL.
- **Escrituras.** El alta de solicitud conserva el bloqueo de servicio y el aviso en la misma transacción. MySQL bloquea la fila de solicitud antes de enviar el mensaje. SQLite mantiene su UPDATE condicional como admisión del escritor. El marcador MySQL actualiza ID y fecha solo al avanzar y relee la fila actual con bloqueo para respetar REPEATABLE READ. El orden de conversaciones sigue priorizando no leídos, luego ID del último mensaje e ID de solicitud, con la misma consulta para web y API.
- **Disponibilidad.** MySQL bloquea el perfil antes de consultar la revisión, incluso durante el primer guardado. Zona IANA, revisión, intervalos y fechas se escriben en la misma transacción. La lectura pública comparte el bloqueo del perfil para obtener una configuración coherente también bajo READ COMMITTED. La fecha se conserva como un día civil del prestador.
- **Exclusión.** Los triggers InnoDB de INSERT y UPDATE bloquean el perfil de destino y hacen una lectura actual de intervalos. Se rechazan solapamientos de rangos semiabiertos y se admiten límites consecutivos. CHECK y claves foráneas restringen días, minutos, revisiones, fechas del calendario y propiedad de las filas. El caso de dos procesos verifica la espera de bloqueo y el rechazo tras confirmar, aun con una instantánea anterior.
- **Reservas.** El esquema conserva una reserva activa por solicitud y una clave idempotente por cliente y solicitud, incluso después de una transición terminal. Las fechas civiles y UTC permanecen en formato canónico, sin conversión de zona en el motor. Los CHECK rechazan fracciones en minutos y versiones, fechas inválidas y duraciones distintas de 60 minutos. La correspondencia de hora civil a UTC y los cambios DST se comprueban con el resolvedor PHP existente.
- **Locks de reservas.** El trigger adquiere la solicitud en exclusivo, el servicio en compartido y el perfil en exclusivo. La comprobación de intervalos UTC confirmados usa una lectura actual con `FOR SHARE` bajo el bloqueo del perfil. Los triggers de los padres protegen cierre e identidad con lecturas actuales. Un UPDATE directo de reserva puede llegar con su fila ya bloqueada, y un flujo que bloquea el servicio primero puede intercalarse. El laboratorio fuerza un deadlock y comprueba el reintento de la transacción revertida con un único evento final.
- **Historial de reservas.** Cada alta o transición escribe el evento en la misma sentencia. El historial valida origen, destino, versión, actor, motivo y hora, e impide cambios y borrados. El fallo del evento revierte el cambio de reserva. El laboratorio valida reintentos tras conflictos conocidos y rollback. El manejo de COMMIT incierto del futuro adaptador MySQL queda **NOT RUN**.
- **Reseñas.** Una restricción UNIQUE conserva una reseña por reserva, incluso después del retiro. Al publicar, el trigger verifica con una lectura actual la reserva completada y las identidades vinculadas. Al retirar, las identidades ya verificadas quedan protegidas por la inmutabilidad de la reseña, la reserva y sus vínculos. Los CHECK rechazan calificaciones fraccionarias o fuera de rango, comentarios con bytes nulos o más de mil caracteres, y retiros sin el identificador del cliente propietario. La policy verifica además la identidad del usuario autenticado. El historial admite una sola transición a retirada.
- **Concurrencia de reseñas.** La publicación bloquea primero la reserva y luego lee la reseña con bloqueo. El retiro bloquea solo la reseña. Los reintentos reconocen contenido idéntico y obtienen el estado y la fecha de retiro ya confirmados, incluso cuando la transacción abrió antes una instantánea REPEATABLE READ. MySQL clasifica sus códigos de conflicto sin aplicarles la máscara SQLite. Los listados públicos obtienen elegibilidad, promedio, recuento y página en una sola sentencia para compartir instantánea bajo READ COMMITTED. La proyección excluye la identidad del autor.
- **Avisos de actividad.** Los triggers emiten el aviso en la misma sentencia que escribe el evento de reserva, publica la reseña o la retira. Verifican destinatario, actor, evento y fecha. La FK compuesta usa `DECIMAL(49,30)` para la versión. La restricción de unicidad y los triggers protegen la identidad, el historial y el primer `read_at`. Si falla la inserción del aviso se revierte la operación que lo originó. Un duplicado del trigger MySQL se comunica como fallo de almacenamiento al publicar o retirar una reseña.
- **Bandejas.** MySQL usa sesión UTC y normaliza collation y representación de fecha antes de combinar solicitudes y actividad. El total y la página comparten una sentencia bajo READ COMMITTED. El marcado usa la fila y el alcance actuales tras bloquear, incluso si la transacción ya tiene una instantánea REPEATABLE READ. La bandeja web admite ambos roles y protege los enlaces con las reglas de cada recurso. La API móvil filtra avisos del solicitante antes de contar, paginar o marcar y devuelve una proyección mínima.
- **Límites.** DDL en MySQL puede confirmar implícitamente. Una base que haya sufrido una instalación interrumpida requiere inspección y recuperación explícita. La prueba de segunda ejecución cubre el esquema instalado correctamente. El laboratorio crea una base nueva para cada ejecución. El puerto TCP y el plugin X quedan apagados. La prueba de rollback usa un trigger de fallo en la base efímera, con binlog desactivado exclusivamente en el laboratorio.

## Precios y cotizaciones InnoDB

- El adaptador fija el PDO de escritura y acredita BEGIN antes de tomar en exclusivo `pricing_write_lock.id=1` con `SELECT … FOR UPDATE`. Cualquier precio, cotización, aceptación o recibo insertado por SQL toma esa misma admisión en su trigger. El nivel de transacción visible para Laravel dirige Eloquent al mismo PDO y desactiva sus reintentos por desconexión. Laravel no puede abrir savepoints, confirmar ni revertir esta unidad. La única participación anidada del módulo comparte la unidad propia, como en la creación de servicio con precio inicial.
- Después de la admisión, los triggers leen y bloquean en compartido las filas actuales de servicio y perfil al publicar, de solicitud, servicio y perfil al cotizar, y de cotización y solicitud al aceptar. La admisión también serializa la comprobación de versión y el recibo de creación. Los UPDATE sobre propietarios o participantes ya tienen el lock del padre y realizan una lectura actual del historial asociado, sin adquirir la admisión de precios. Así un resultado confirmado preserva las identidades incluso con una instantánea anterior. Los triggers de reservas y reseñas mantienen sus locks de solicitud, servicio, perfil y reserva. Un UPDATE directo puede llegar con su fila bloqueada o InnoDB puede elegir otro orden para un JOIN. Estas intercalaciones pueden producir deadlock; la aplicación solo permite un reintento explícito con la misma clave.
- Los importes se almacenan como dígitos decimales canónicos de centavos enteros. Validar el texto antes del CAST evita la coerción de fracciones por columnas INTEGER o DECIMAL de escala fija. Los CHECK calculan la comisión con `(total * 15 + 50) DIV 100` y el complemento por sustracción exacta. El adaptador valida los tipos devueltos por PDO, verifica el desglose y reconstruye el dominio con enteros. Las cifras llevan comisión incluida y el total conserva el máximo de 1.000.000.000 de centavos.
- Cotizaciones, precios, recibos y aceptaciones son append-only, con referencias restrictivas y UTC canónico a seis decimales. La vigencia de cotización es de 1–86.400 segundos, 1.800 por defecto. El límite de aceptación es exclusivo. Una aceptación ya persistida permite reintentos después de vencer. La retención B2 de 900 segundos permanece pendiente de portar.
- El laboratorio fuerza 1205 en admisión y después de escrituras parciales; acredita rollback propio antes de permitir el reintento. Fuerza también 1213 con dos procesos y comprueba el rollback implícito de InnoDB y una sola ejecución del callback. Pérdida simulada de la confirmación de BEGIN/COMMIT y fallo de rollback se prueban por inyección sobre PDO conectado al laboratorio. Una conexión incierta queda retirada y en cuarentena hasta apagar el proceso. Reiniciar el worker afectado y consultar el historial antes de emitir un reintento con la misma clave.

## Matriz de las 39 identidades de migración

| Archivo o tramo ordenado | Cantidad | MySQL V8.1 | Comprobación |
| --- | ---: | --- | --- |
| `0001_01_01_000000` a `0001_01_01_000002` | 3 | PASS | Instalación. Usuarios, tokens de recuperación y sesiones ejercitados |
| `2025_08_02_085013` a `2026_02_24_075954` | 21 | PASS DDL | Historial legado conserva tablas, índices y FK. Funciones legadas fuera de esta validación |
| `2026_10_23_000000` a `2026_10_28_000001` | 4 | PASS | Roles, perfiles, categorías y servicios. Incluye precios y reseñas públicas en las rutas probadas |
| `2026_10_28_000002` solicitudes | 1 | PASS | Variante InnoDB, CHECK, FK, exclusividad activa y carrera de altas |
| `2026_10_29_000000` avisos de solicitud | 1 | PASS | Unicidad y rollback conjunto de alta y decisión |
| `2026_10_30_000000` y `000001` mensajes y lecturas | 2 | PASS | Variantes InnoDB. FK compuesta, reintento, alcance, orden y marcador monótono |
| `2026_10_30_000002` disponibilidad | 1 | PASS | Fechas civiles, edición y lectura coherente, permisos, revisión, exclusión y rollback |
| `2026_11_03_000000` reservas | 1 | PASS esquema | Exclusión de reservas activas por solicitud, ocupación confirmada por prestador, transiciones, participantes e historial |
| `2026_11_03_000001` reseñas | 1 | PASS | Reserva completada, unicidad, identidad, contenido y retiro inmutables, concurrencia y persistencia |
| `2026_11_03_000002` avisos de actividad | 1 | PASS | FK compuesta, destinatarios, unicidad, historial, emisión atómica y primer marcado concurrente |
| `2026_11_04_000000` Sanctum | 1 | PASS | Emitir Bearer, alcance móvil, invalidación y revocación |
| `2026_11_18_000000` precios y cotizaciones | 1 | PASS | Cinco tablas InnoDB, invariantes de importe e identidad, admisión, concurrencia, historial y persistencia |
| `2026_11_19_000000` retenciones B2 | 1 | BLOCKED | High-water UTC, 900.000.000 μs, exclusión, recibos y transiciones |

**Total MySQL:** 38 identidades ejecutadas por el laboratorio, 1 pendiente. Un PASS DDL acredita la creación del esquema en ese tramo. SQLite continúa usando las 39 migraciones originales.

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
| Categorías, catálogo y prestadores | PASS para búsqueda y detalle web de servicio, precio publicado y reseña pública, y detalle básico de catálogo móvil. Opciones comerciales y de reserva siguen bloqueadas |
| Disponibilidad semanal, fechas bloqueadas y zona del prestador | PASS en el servicio compartido. PHP valida las zonas IANA y MySQL aplica límites de almacenamiento y fechas civiles |
| Opciones para reservar y proyecciones comerciales del catálogo | BLOCKED |
| Reservas e historial mediante SQL directo | PASS en el esquema y las políticas de participantes. Espera InnoDB, conflicto de versión, solapamiento, rollback y reintento probados |
| Web/API de reservas, vistas de ocupación y reservas activas, retenciones B2 | BLOCKED. `ReservationTransactions` sigue siendo SQLite y las vistas se crean en B2 |
| Cotizaciones | PASS para emitir y aceptar vía web, y para listar, emitir, aceptar y consultar detalle o aceptación vía API. El detalle web autenticado todavía carga el recorrido dependiente de B2 |
| Contexto de checkout | BLOCKED por B2 y el adaptador de reservas |
| Historial de contratación y dashboard | BLOCKED para las rutas que dependen de B2 |
| Reseñas privadas y públicas | PASS en el laboratorio con reservas completadas sembradas mediante el esquema. Rutas web dedicadas de reseñas y API pública y privada. La publicación y el retiro generan avisos al prestador |
| Bandeja y lectura de notificaciones | PASS en el laboratorio. Unión, orden, página y contador, autorización web y Bearer, doble rol y reintento tras bloqueo o deadlock |
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

El destino de evidencias debe ser nuevo y estar fuera del checkout. El runner monta código y dependencias como solo lectura. MySQL y PHP usan `--network none` y comparten únicamente el socket Unix para el acceso a la base. El usuario del laboratorio recibe permisos en un esquema nuevo. El runner registra versión, motor, aislamiento, manifiesto Docker y JUnit. Reinicia el mismo contenedor, repite las migraciones y comprueba la solicitud, su aviso, la disponibilidad, la reserva confirmada, sus dos eventos, la reseña retirada, sus avisos, el primer marcado, el precio versionado, la cotización y su aceptación persistidos. Al finalizar libera el contenedor y los volúmenes que creó.

La suite MySQL se ejecuta separadamente de PHPUnit normal, cuyo bootstrap sigue forzando SQLite desechable. Las pruebas diferencian 1062 por unicidad, 1451/1452 por FK, un lock wait 1205 y un deadlock real cuya víctima reintenta bajo Laravel. La prueba de deadlock comprueba que los efectos de su intento revertido no se duplican. Esa evidencia aplica a las transacciones probadas, no a B2.

## Cobertura local y GitHub Actions

| Ejecución | Qué acredita | Evidencia |
| --- | --- | --- |
| Docker local, `tools/mysql-v81/run.sh` | MySQL 8.4.13, InnoDB y PHP 8.4.27. Instalación de 38 migraciones, segunda ejecución, precios y cotizaciones, concurrencia, lock waits, deadlock, rollback y persistencia tras reiniciar | `isolation.log`, logs y dos JUnit en el directorio de evidencia elegido |
| [`MySQL 8.4 foundation`](../../.github/workflows/mysql-v81.yml) | Mismo runner y suites sobre el HEAD exacto del PR. Revisa que no haya puertos publicados, que ambos JUnit estén completos y que Docker libere los recursos | Artefacto `mysql-v81-<run_id>-<attempt>` y conclusión del job en el HEAD correspondiente |
| `Mobile API integration` y `T1 isolated laboratory` | Regresiones SQLite de API móvil y T1 en PHP 8.2 y 8.4 | Resultados de sus workflows en el HEAD correspondiente |
| PHPUnit completo, Pint, lint y build locales | Regresiones de la aplicación en SQLite, estilo PHP del incremento, sintaxis y assets | Logs locales independientes de los jobs de GitHub |

El job instala `composer.lock` sin plugins ni scripts antes de arrancar la base. Los contenedores de MySQL y PHPUnit carecen de red y de credenciales externas. MySQL también usa `--skip-networking` y desactiva el plugin X. El runner comprueba la configuración de red y el número de puertos publicados antes de ejecutar las pruebas. La migración interrumpida se trata como fallo del laboratorio y el esquema se descarta. La recuperación de una instalación parcial fuera del laboratorio sigue **NOT RUN**.

## Siguiente incremento

El adaptador de reservas requerirá fijar el orden de locks con la admisión B2 y probar recuperación de COMMIT incierto. El reloj debe preservar el valor de 64 bits, impedir retrocesos y comprobar el límite exacto de 900 segundos. El aprovisionamiento de triggers con binlog habilitado sigue **NOT RUN**, junto con la restauración de respaldos, SMTP, HTTPS y despliegue reversible de código en la VPS.
