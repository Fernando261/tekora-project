# V8.1 · Fundamentos MySQL 8.4

Este incremento prueba identidad y solicitudes sobre InnoDB. La instalación completa de Tekora sobre MySQL sigue **BLOCKED**. El diagnóstico previo queda en [PR #59](https://github.com/Fernando261/tekora-project/pull/59).

## Esquema y transacciones

Las 39 migraciones históricas permanecen intactas. El laboratorio selecciona explícitamente las primeras 32 identidades de migración, hasta el marcador de lectura de la conversación, y añade Sanctum, que no depende de los módulos comerciales. La ruta MySQL alternativa usa las mismas identidades para las tres variantes. El migrador ordinario conserva su selección existente y los guardas de los módulos SQLite.

- **Solicitudes.** Una columna generada invisible vale 1 para estados `pending` y `accepted`, y NULL para los terminales. El índice único sobre servicio, cliente y esa columna impide dos solicitudes activas a nivel de InnoDB. El estado tiene un CHECK con comparación binaria. El historial terminal permite múltiples filas y la columna generada queda fuera de `SELECT *`.
- **Mensajes y lecturas.** Las dos tablas conservan unicidad de envío y la FK compuesta que une el marcador a un mensaje de la misma conversación. MySQL limita los nombres a 64 caracteres, por lo que las variantes asignan nombres cortos. Los índices de mensajes se crean junto a su tabla. Cada variante crea su tabla en una sola operación DDL.
- **Escrituras.** El alta de solicitud conserva el bloqueo de servicio y el aviso en la misma transacción. MySQL bloquea la fila de solicitud antes de enviar el mensaje. SQLite mantiene su UPDATE condicional como admisión del escritor. El marcador MySQL actualiza ID y fecha solo al avanzar y relee la fila actual con bloqueo para respetar REPEATABLE READ. El orden de conversaciones sigue priorizando no leídos, luego ID del último mensaje e ID de solicitud, con la misma consulta para web y API.
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
| `2026_10_30_000002` disponibilidad | 1 | BLOCKED | CHECK civiles, intervalos sin solapamiento y revisiones |
| `2026_11_03_000000` reservas | 1 | BLOCKED | Ocupación por prestador, transiciones, integridad de participantes e historial |
| `2026_11_03_000001` reseñas | 1 | BLOCKED | Requiere reserva realizada y restricciones de registro/retirada |
| `2026_11_03_000002` avisos de actividad | 1 | BLOCKED | Destinatarios y unicidad de eventos de reserva/reseña |
| `2026_11_04_000000` Sanctum | 1 | PASS | Emitir Bearer, alcance móvil, invalidación y revocación |
| `2026_11_18_000000` precios y cotizaciones | 1 | BLOCKED | Tablas append-only, identidad del snapshot y adaptador MySQL |
| `2026_11_19_000000` retenciones B2 | 1 | BLOCKED | High-water UTC, 900.000.000 μs, exclusión, recibos y transiciones |

**Total MySQL:** 33 identidades ejecutadas en laboratorio, 6 pendientes. Un PASS DDL no certifica los casos de uso de ese tramo. SQLite continúa usando las 39 migraciones originales.

## Matriz de web y API

| Capacidad | MySQL V8.1 |
| --- | --- |
| Registro móvil y confirmación | PASS con aviso simulado dentro de PHPUnit |
| Login Bearer, `/me`, logout y revocación tras recuperar contraseña | PASS |
| Perfil móvil `GET/PATCH /me/profile` | PASS. Operaciones GET/POST de foto **NOT RUN** |
| Inicio web y fila de sesión InnoDB | PASS en kernel Laravel. Sesión a través de Nginx/FPM **NOT RUN** |
| Solicitudes `GET /inquiries`, `GET /inquiries/{id}`, alta desde servicio | PASS. Cancelación HTTP **NOT RUN** en MySQL |
| Decisiones de solicitud | PASS para aceptar/rechazar desde servicio de dominio. Cierre con realización **BLOCKED** por reservas |
| Conversaciones, listado, envío y lectura | PASS API. Vista web comparte orden SQL y se cubre en las regresiones SQLite |
| Categorías, catálogo y prestadores | Funcionalidad pública **NOT RUN** en este laboratorio. Proyecciones de precios o reseñas requieren los módulos pendientes |
| Disponibilidad y opciones para reservar | BLOCKED |
| Reservas, retenciones y conflictos / reintentos B2 | BLOCKED |
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

El destino de evidencias debe ser nuevo y estar fuera del checkout. El runner monta código y dependencias como solo lectura. MySQL y PHP usan `--network none` y comparten únicamente el socket Unix para el acceso a la base. El usuario del laboratorio recibe permisos en un esquema nuevo. El runner registra versión, motor, aislamiento, manifiesto Docker y JUnit. Reinicia el mismo contenedor, repite las migraciones y comprueba el identificador y aviso de una solicitud ya persistida. Al finalizar libera el contenedor y los volúmenes que creó.

La suite MySQL se ejecuta separadamente de PHPUnit normal, cuyo bootstrap sigue forzando SQLite desechable. Las pruebas diferencian 1062 por unicidad, 1451/1452 por FK, un lock wait 1205 y un deadlock real cuya víctima reintenta bajo Laravel. La prueba de deadlock comprueba que los efectos de su intento revertido no se duplican. Esa evidencia aplica a las transacciones probadas, no a B2.

## Siguiente incremento

Portar disponibilidad con bloqueos por perfil antes de leer o reemplazar intervalos, revisión comparada y restricciones de rango. Reservas requerirá adoptar un orden de adquisición único para admisión, solicitud y prestador, y demostrar exclusión usando intervalos semiabiertos. El reloj debe preservar el valor de 64 bits, impedir retrocesos y comprobar el límite exacto de 900 segundos. El adaptador de precios debe adquirir su propia transacción antes de leer versiones, conservar snapshots y calcular comisión con aritmética entera. Las garantías de reseñas y avisos dependerán de las transiciones y eventos InnoDB. Después de validar las 39 migraciones y el recorrido comercial completo, preparar el entorno VPS y probar backup/restore, SMTP, HTTPS y despliegue reversible de código.
