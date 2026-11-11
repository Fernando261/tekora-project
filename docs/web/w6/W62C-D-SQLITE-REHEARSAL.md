# W6.2c-d — Ensayo reforzado de retirada SQLite

**SOLO TEKORA. CERO MINDBLOOM.** Ensayo privado PHPUnit, exclusivamente con SQLite `:memory:` y datos/bytes creados por los tests. No se crea un mecanismo de eliminación real ni se interviene una instalación. Base exacta de `main`: **`7c6b99823630fb457d7f53b7e73732da454e7b81`**. Rama `codex/w62c-d-sqlite-retirement-rehearsal`. El SHA final exacto se publica en la descripción del único PR en borrador y en la entrega; un commit no puede incorporar su propio hash en su árbol.

## Alcance y preflight

`git ls-remote`, fetch y checkout confirmaron la base obligatoria; estado inicial limpio, 44 GiB disponibles y 3.908.576 inodos libres. Se revisaron el [ADR](../../architecture/ADR-DB-ENGINE-TEKORA.md), la [matriz](../../architecture/TEKORA-DB-INVARIANTS-MATRIX.md), la [propuesta SQLite](W62C-C-SQLITE-NEXT-PR.md), [W6.2c-a](W62C-A-PREFLIGHT.md) y las pruebas obligatorias de retirada/fotografías. PR #28 continúa abierto, en borrador, HEAD `ae638c358b475d5c6605cf2d0440c03b2225a2d0`, sin cambios ni tests importados.

Solo se modifica [RetirementPreflightTest](../../../tests/Feature/RetirementPreflightTest.php), se añade el trait de pruebas [RetirementMemoryIntegrity](../../../tests/Support/RetirementMemoryIntegrity.php) y esta documentación/evidencia. El trait concentra captura, comparación y restauración privadas para no duplicar esas comprobaciones entre escenarios. No tiene entrada desde Artisan, HTTP, jobs o servicios; no recibe DSN ni rutas de DB. El orden de tablas solo admite prefijos del plan fijo, incluso en las llamadas internas de tests negativos.

Las 37 migraciones, código productivo, rutas/21 operaciones API, modelos, servicios, dependencias, locks y configuración permanecen intactos. `ProfilePhotoReferences`, `LegacyPhotoReferences`, W5.1 y los demás archivos de regresión se conservan.

## Aislamiento efectivo

El host no tiene PHP en PATH. Se reutilizó una imagen de desarrollo local con PHP 8.4.27 y SQLite 3.46.1; cada ejecución de PHPUnit usa un contenedor efímero con red deshabilitada y solo una copia temporal de fuentes como montaje. La copia se construyó desde una selección de rutas/extensiones, **filtrando antes de leer/copiar**: excluye `.env`, bases, Storage existente, imágenes, uploads y fuentes de medios. No se siguieron enlaces hacia medios. Dependencias PHP se copiaron como fuentes/recursos de código; Node usa las dependencias ya instaladas del lock.

El primer baseline reutilizó paquetes PHP por montaje de dependencias de solo lectura; las clases App/Tests se verificaron resueltas en la copia temporal. Las ejecuciones de aceptación posteriores ya solo montan la copia aislada, sin acceso al checkout original. Se creó únicamente un `.env.testing` vacío en esa copia. Ni ese archivo ni caches/build/runner auxiliares se incorporan al PR.

Los guards comprueban PHPUnit, ambiente testing, conexión efectiva SQLite, nombre `:memory:`, DB_URL vacío, Storage falso en raíz aleatoria propia, ausencia de `.env`, `PRAGMA database_list` sin archivos/adjuntos ajenos a main/temp y `foreign_keys=1`. Se ejecutan antes del primer DROP y de nuevo antes de **cada** DROP; se vuelve a cotejar catálogo, ledger y estado protegido entre pasos. `Http::preventStrayRequests()` prohíbe salidas del código de aplicación. Las auditorías de dependencias se ejecutaron aparte, con red/CA pública del entorno y directorios Composer nuevos, sin importar credenciales.

No hay sentencias para desactivar FK en el simulador ni en la restauración. El fixture negativo de huérfano usa validación diferida dentro de una transacción en memoria, con FK=1 observado y rollback; el caso de FK no activas usa un PDO nuevo, vacío, con su valor predeterminado, que se rechaza antes de DDL. Las suites históricas se ejecutan intactas: no se atribuye esta política nueva a cada fixture histórico de otros archivos.

## Manifiesto y datos preservados

La comparación es exacta para las 26 tablas protegidas, incluidas vacías. El caso completo y la restauración positiva las pueblan **todas**: 18 de dominio y ocho de infraestructura. Hay usuarios/roles, token ficticio, solicitudes, mensajes enviados/recibidos, cursor de lectura, horarios/fecha bloqueada, reserva completada, sus eventos/avisos y reseña, además de ledger, reset ficticio, sesión, caché/lock y colas sintéticas. No se ejecutan esos jobs.

| Elemento | Captura y comprobación |
| --- | --- |
| Tablas y columnas | Lista exacta, SQL de CREATE, `table_xinfo`: orden, tipos, nullability, defaults y PK |
| FK | Columnas/identidad compuesta, destinos, acciones ON UPDATE/DELETE y `foreign_key_check` |
| Índices | Unicidad/origen/parcialidad, SQL con predicado WHERE e `index_xinfo` con orden de claves |
| Triggers/otros objetos | Nombre, propietario y SQL íntegro; catálogo global y objetos temporales |
| Filas | Valores completos ordenados de forma determinista y tipos `typeof` de cada valor; NULL y fechas incluidos |
| Autoincrementos | Secuencia por tabla, incluso por encima de MAX(id); siguiente ID y rollback del ensayo de inserción restaurada |
| Historial | 37 nombres/IDs/batches exactos; ningún skip ni edición de ledger aceptados |

Única normalización de metadata: `PRAGMA index_list.seq` es posición de enumeración, no orden de claves. Se ordenan índices por nombre, conservando `index_xinfo.seqno`, propiedades y SQL. La restauración crea índices en orden canónico y compara todas sus garantías; no se ignoran columnas, tablas, filas, predicados ni restricciones para conseguir coincidencia. `schemaPart` separa datos del catálogo para el preflight; la comparación de preservación vuelve a incluir filas, tipos y secuencias completos.

Los casos de mutación detectan cambio de valor con igual conteo, NULL, fecha, BLOB frente a TEXT con el mismo valor PHP, secuencia, batch, tabla protegida vacía retirada y predicado parcial modificado. La evidencia publica esquema, conteos, ledger y digests **del estado sintético**, sin valores de usuarios, mensajes, passwords o tokens; no publica hashes de medios.

## Dependencias y retirada

El orden fijo es likes → comments → posts → reminders → emotions → citas → profesionista_bloqueos → profesionista_disponibilidades → profesionistas. Las FK históricas se clasifican hija→padre y no hay entrantes protegidas hacia candidatas en la referencia. El catálogo fresco de 37 migraciones contiene 35 tablas: nueve candidatas y 26 protegidas. Sus **34 triggers son protegidos; cero pertenecen a las nueve candidatas**. La comparación filtra objetos por su tabla propietaria al retirar una candidata; no exigiría que sobrevivieran sus objetos propios si una referencia futura los incorporase mediante revisión específica.

Paran antes del primer DROP: trigger nuevo, modificado o faltante; trigger `users` que escribe en `posts`; vista cruzada/objeto temporal; FK protegida hacia candidata; tabla/columna ausente, índice/tabla desconocidos; migración faltante/batch alterado; adjunto no previsto; referencia polimórfica de tipo desconocido. Se aceptan únicamente los tipos históricos Post/Comment declarados en el fixture; no se infiere identidad nativa ni exclusividad a partir de IDs. Se observa cero DROP y estado efectivo sin cambios después del rechazo. El trigger cruzado conservado se ejerce después del rechazo para demostrar que sigue activo.

También se inyecta deriva justo antes del segundo paso (metadata de conexión, trigger, fila protegida o adjunto en memoria): solo llegó a ejecutarse el primer DROP y el rollback restaura el estado. Ninguna prueba abre un archivo SQLite para provocar ese rechazo.

## Fallos, checkpoints y restauración

| Ensayo | Observación requerida y comprobada |
| --- | --- |
| Fallo tras 1, 3 y 9 | Dentro de la transacción desaparece el prefijo exacto; se registra qué DROP ocurrió y las 26 protegidas siguen iguales |
| Fallo justo antes de commit | Tras completar los nueve pasos y validar el resultado, se lanza el fallo todavía dentro de la transacción |
| Después de rollback | Manifiesto completo original idéntico: reaparecen las nueve tablas **y sus filas**, objetos y secuencias; nivel transaccional cero y FK activas |
| Parcial confirmado en memoria | Tres ausencias efectivas fuera de transacción; reintento sin confirmación, con manifiesto falsificado o con checkpoint obsoleto se rechaza sin otro DROP |
| Confirmación explícita válida | El caso histórico de continuación privada solo acepta checkpoint cuyo manifiesto coincide exactamente con el estado efectivo; nunca reanuda automáticamente |
| Restore a segundo PDO | Nueva DB `sqlite::memory:` independiente, sin compartir estado ni simular concurrencia; cotejo completo contra origen y relaciones nativas |
| Restore alterado | Fila, secuencia, índice, trigger u objeto extra causan rechazo; se observa rollback de DDL/filas del destino y origen intacto |
| Fuente no revisada | SQL de tabla, objeto o ledger alterados se rechazan antes de construir el destino |

La restauración crea tablas e índices únicos necesarios para FK compuestas **antes de cargar hijos**, inserta padres antes que hijos con FK siempre activas, preserva BLOB/NULL/tipos y high-water marks y crea después los triggers del catálogo revisado. No elimina ni desactiva triggers de una DB existente. Luego compara el manifiesto y ejecuta integrity/FK checks antes de commit. Se ejercen rechazo de evento alterado, solapamiento de disponibilidad y solicitud activa duplicada, junto con la siguiente identidad autoincremental. La FK compuesta de cursor→mensaje se prueba con conversaciones realmente pobladas.

Esto es restauración lógica en memoria. No demuestra caída física, fsync, durabilidad, copia de WAL ni backup operativo. Copiar DDL/filas a otro PDO no equivale a dos conexiones concurrentes a una misma base.

## Workflows y fotografías

La comparación de preservación termina **antes** de ejecutar operaciones nuevas. Escenarios independientes ejercen login/roles y denegación, alta/aceptación de solicitud, mensajería autorizada e idempotente, cursor monotónico con token viejo, revisión de disponibilidad, propuesta/replay, conflicto de versión/actor, confirmación/completado y eventos 1/2/3, reseña/retirada idempotentes, destinatarios/lectura de avisos y contratos API de identidad, mensajes y catálogo. Un fallo de aviso provocado por trigger revierte exactamente reserva + evento + aviso y devuelve conflicto seguro, sin SQL ni excepción previa expuestos. No se compara una DB a la que ya se añadieron datos nuevos contra la captura de preservación.

| Caso fotográfico | Resultado acotado |
| --- | --- |
| Nativo exclusivo | Referencia propia y bytes falsos conservados por la retirada |
| Antiguo exclusivo / soft-delete | Referencia detectada antes del corte; el simulador no limpia bytes aunque retire las filas |
| Compartido / URL externa / tablas antiguas ausentes | Reemplazo de foto conserva bytes referidos por otro usuario; ninguna URL se descarga |
| Ruta normalizada | Referencia con prefijo/case reconocida antes del corte y bytes retenidos |
| Tipo polimórfico desconocido | Plan bloqueado sin DROP ni limpieza de archivos |
| Error de consulta | El caller de fotos conserva el archivo anterior ante la incertidumbre del adaptador |
| Error de almacenamiento | Upload falla de forma segura; referencia y bytes previos permanecen |

Solo se crean/leen bytes sintéticos de `Storage::fake`. W5.1 y LegacyPhotoReferences siguen intactos. La prueba de archivo antiguo exclusivo no certifica una clasificación real ni autoriza su limpieza posterior: eliminar filas pierde referencias y requiere un inventario/manifiesto específico antes de un corte real.

## Resultados, evidencias y límites

Los resultados exactos, versiones y archivos están en [validación](evidence/W62C-D-validation.json); [casos](evidence/W62C-D-scenarios.json), [manifiestos](evidence/W62C-D-manifests.json), [rollback/restore](evidence/W62C-D-rollback-restore.json) y [calidad/auditorías](evidence/W62C-D-quality.json) contienen datos sanitizados. [Guía Windows/Laragon](W62C-D-LARAGON.md), preparada, no ejecutada.

Se volvió a ejecutar el baseline **688/9.224**. El ensayo crece de **19/198 a 70/626**, conservando todos sus métodos históricos. La suite completa final y los grupos se registran a partir de JUnit; el aumento no sustituye las garantías W5.1/W6.2a/W6.2b ni importa pruebas MySQL.

| Ejecución final | Estado | Pruebas / aserciones |
| --- | --- | --- |
| Suite completa | PASS | **739 / 9.652**, cero errores, fallos u omisiones |
| Ensayo ampliado | PASS | 70 / 626 |
| W5.1 | PASS | 49 / 459 |
| W6.2a | PASS | 25 / 406 |
| W6.2b | PASS | 34 / 586 |

**PASS:** ensayo sintético y regresiones consignadas, Pint de ambos archivos, Composer validate/plataforma, npm audit sin vulnerabilidades, build aislado y diff check. Build avisa de dos fuentes públicas no copiadas, sin intentar leer medios para resolverlas. **BLOCKED:** auditorías Composer con/sin dev, CONNECT 403 de Packagist. **NOT RUN:** Windows, runtime PHP 8.2, MySQL, contención de disco/WAL/múltiples writers, fsync, caída física, backup operativo e intervención real. Configuración Composer continúa declarando PHP ^8.2/plataforma 8.2.28; ejecutar sobre 8.4 no certifica por sí solo 8.2.

Se conserva el diagnóstico de preparación: una copia inicial incompleta de recursos de dependencias produjo errores de renderizado y una ejecución completa agotó 128 MB; no se usa como aceptación. Se completaron los recursos CSS/JS de código, se liberan snapshots al terminar cada caso y la ejecución de aceptación usa límite CLI 512 MB. Composer auxiliar 2.9.1 fallaba por HTTP/3 con el proxy; 2.11.2, verificado contra su release oficial, alcanza el bloqueo 403 real. No cambian dependencias ni configuración del producto. Los errores de desarrollo de fixtures/restore se corrigieron manteniendo constraints activas, incluida la carga de índices únicos antes de hijos.

El historial W6.2c-a sobre lecturas accidentales de medios y W6.2c-b sobre loaders `.env` permanece intacto; no se afirma cumplimiento histórico de cero lecturas. Esta fase no relee esos recursos ni recalcula sus hashes.

## Gates de una ejecución real — BLOCKED

Faltan autorización e identificación de instalación/motor/versión; inventario real de esquema, filas, morfos, medios y lectores/writers externos; clasificación exclusiva y límites aprobados; backup consistente DB + medios y restore operativo probado; ventana, responsables y criterios de reconciliación/reapertura; revisión y autorización separadas de cualquier mecanismo futuro. Ningún resultado de memoria abre estos gates. No borrar Users, transferir Profesionista→ProviderProfile, convertir citas, eliminar medios o actuar sobre Google.

Un futuro laboratorio opt-in de archivos SQLite sintéticos debe crear sus propios recursos en una raíz aleatoria marcada, sin rutas a bases existentes, con suites separadas y sin ampliar el guard de este simulador. Mientras tanto: **NOT RUN — requiere laboratorio SQLite persistente sintético separado**. No fusión, despliegue, auto-merge, migración de motor ni eliminación real. PR #28 no se modifica.
