# W6.2c-b · Laboratorio MySQL aislado y gates de corte

**SOLO TEKORA. CERO MINDBLOOM. CERO PURGA REAL.** Base obligatoria: `2441debb728982229d3a8a476ca9618c116b6172`, rama `codex/w62c-b-mysql-isolation`. Entrega exclusivamente mediante un PR en borrador. No fusionar, desplegar, activar auto-merge ni ejecutar la eliminación definitiva.

## Resultado y alcance

El estado de aceptación se registra en [validación](evidence/W62C-B-validation.json) y [escenarios](evidence/W62C-B-scenarios.json). Un laboratorio que solo acredita identidad/instalación no acredita retirada, conservación de 26 tablas ni restauración. El runner devuelve un estado global BLOCKED mientras esos gates no estén ejecutados, incluso si una instalación futura llegase a completar sus migraciones.

Se mantienen intactos aplicación, API, configuración existente, credenciales, locks, las 37 migraciones históricas y `ProfilePhotoReferences`/`LegacyPhotoReferences`. Solo se añaden herramientas **de pruebas separadas** y documentación/evidencias. La suite habitual no cambia de conexión ni incorpora tests MySQL. No se incluye helper de retirada de las nueve tablas que aún no puede verificarse contra el esquema nativo completo, ni comando Artisan, job, endpoint o migración destructiva.

Se revisaron PR #27 integrado, [preflight W62C-A](W62C-A-PREFLIGHT.md), [guía](W62C-A-LARAGON.md), sus matrices y `RetirementPreflightTest.php`. Los incidentes históricos de lecturas de medios y su aclaración se conservan sin reescribirlos. El inventario de diez imágenes no se vuelve a ejecutar; no se abren, copian, leen ni recalculan hashes de `public/img`, uploads o medios de Storage.

## Preflight y versiones

El checkout estaba limpio; `git ls-remote` confirmó el SHA exacto, después de fetch `HEAD` y `origin/main` coincidían. No hubo rebase. Espacio inicial: 52 GiB, 4.119.942 inodos libres. Host: PHP 8.4.27, Node 24.22.1, Docker 29.9.2; Laravel bloqueado: 12.71.0. El PHP del host no tiene pdo_mysql, por eso el cliente de integración usa un contenedor PHP con el driver compilado. La versión efectiva del worker y la del servidor constan en la evidencia real, no se atribuyen a Windows.

Imagen oficial MySQL ejecutada: `mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`. Las sondas de aprovisionamiento verificaron MySQL **8.4.13**, InnoDB. El worker se construyó desde `php@sha256:d63e8b80cd4c6cfd01cfa7c910522e299cdc7ca1a2b6d6563db48df3c17e6c55`, añadiendo únicamente pdo_mysql. Digest/ID y versión efectivos, UUID/hostname y sql_mode se conservan en [aislamiento](evidence/W62C-B-isolation.json) y [resultado del motor](evidence/W62C-B-mysql-result.json). No son datos de una instalación existente.

## Aislamiento y aprovisionamiento

`tests/Integration/run-mysql-lab.py` selecciona explícitamente el socket local Docker, elimina selectores remotos heredados para ese proceso y no cambia DOCKER_CONFIG ni credenciales existentes. Crea una red `--internal` nueva, con identidad aleatoria por ejecución y etiquetas de propiedad. Solo incorpora el servidor y el worker de ese ensayo; comprueba IDs, etiquetas, única red, ausencia de privilegios, bindings de puertos y montajes persistentes/host. No publica 3306 ni 33060. El servidor usa tmpfs en `/var/lib/mysql`, sin volumen de datos anónimo ni persistente.

La imagen oficial inicializa una base nueva `w62cblab<nonce>` y un usuario exclusivo con permisos únicamente sobre esa base. El nombre no contiene comodines SQL de GRANT. Contraseñas de root/cliente y marcador se generan aleatoriamente para el laboratorio en un directorio temporal 0700 y archivos 0600, nunca se imprimen ni se incorporan al repositorio. No se importa un `.env`. El aprovisionador verifica propiedad/red/montajes y hostname, UUID, versión e InnoDB antes del DDL de la tabla de marcador. Ese bootstrap no es una retirada ni un comando genérico de bases.

El worker es no privilegiado (UID 1000). Recibe por copia una allowlist de **código**: app/config/bootstrap/routes/migraciones/vendor/tests de integración, composer.json, schemas XSD y el entrypoint PHP de PHPUnit. Excluye directorios ocultos, symlinks, bootstrap/cache y extensiones ajenas a esa allowlist **antes de abrir archivos**. No recorre public o storage ni archivos DB/.env. El código se guarda en la capa temporal del contenedor, no en un montaje del host. Su configuración privada de laboratorio contiene la identidad esperada del servidor y el marcador. El bootstrap de Laravel fuerza una ruta y nombre de entorno inexistentes; correo/caché/sesión/colas son efímeros y sin efectos externos.

El test requiere `TEKORA_MYSQL_LAB_ENABLE=1` y `/run/tekora-lab.json`. Comprueba host fijo `mysql-lab`, base/usuario derivados del nonce, servidor/UUID/hostname, cuenta autenticada, versión 8.4, engine InnoDB, FK activas y marcador exacto. Examina GRANT sin publicarlo para rechazar privilegios fuera de la base. La instalación DDL está encapsulada en un helper privado y el callback Laravel vuelve a verificar la identidad **antes de cada sentencia DDL**. La conexión guardada nunca se sustituye por localhost:3306 o Laragon.

Los negativos cambian solo la identidad esperada (host, base, UUID, hostname, marcador): deben rechazarse sin DDL y sin abrir conexiones a los destinos rechazados. No simulan los negativos de FK, triggers, vistas o privilegios del esquema completo; esos escenarios dependen de instalar las 37 migraciones y permanecen explícitos en la matriz.

La limpieza elimina solo los IDs de contenedores creados por el runner que conservan su etiqueta, y su red etiquetada cuando ya está vacía. No ejecuta SQL de purga, rm global de contenedores, limpieza de volúmenes ni docker system prune. El código copiado y los datos tmpfs desaparecen con esos contenedores. Las imágenes descargadas/construidas quedan como caché reconstruible; no se eliminan recursos ajenos.

## Instalación histórica y compatibilidad

Las 37 fuentes se inspeccionaron sin modificarlas: [manifiesto y líneas específicas](evidence/W62C-B-source-preflight.json). El catálogo MySQL recoge columnas, nullability/defaults, índices, FK, motores y conteos de vistas/triggers/rutinas/eventos del esquema efectivamente alcanzado. La tabla adicional `w62cb_identity` es infraestructura del ensayo y nunca se considera una tabla del producto.

El catálogo SQLite previo de 35 tablas y 34 triggers es una referencia sintética, no prueba de igualdad con MySQL. MySQL representa tipos, enums, bools, timestamps, índices y collations de forma diferente. Cada diferencia requiere equivalencia semántica demostrada, no igualdad textual. Si una migración falla, el runner no salta a la siguiente ni marca la historia como completa.

**Bloqueos estáticos confirmados en fuentes:** solicitudes exigen SQLite y un índice único parcial; disponibilidad, reservas y reseñas contienen guards o SQL específicos del motor. Los triggers RAISE/ABORT y CHECK con typeof/strftime/unixepoch no son DDL MySQL equivalente. El primer fallo realmente ejecutado y el número de migraciones confirmadas se registran separadamente del análisis estático en el resultado del motor; no se atribuye ejecución a migraciones posteriores.

No se cambia una migración histórica ni se desactivan restricciones para continuar. No se carga un esquema alternativo de nueve tablas, no se crean fixtures incompletos y no se presenta una prueba de DROP aislada como validación de Tekora. Si falta parte del esquema, los 26 snapshots nativos, los fallos de retirada, la restauración y los workflows MySQL no pueden acreditarse.

## Escenarios de corte y recuperación aún gated

Las nueve candidatas siguen siendo likes, comments, posts, reminders, emotions, citas, profesionista_bloqueos, profesionista_disponibilidades y profesionistas. Las 26 tablas protegidas son las enumeradas en W62C-A; no se toca users, roles, perfiles, catálogo, solicitudes/mensajes, disponibilidad, reservas/eventos, reseñas, avisos, tokens o infraestructura.

Una entrega posterior, tras resolver compatibilidad, deberá ejecutar B–F completos sobre una instancia nueva: fixtures nativos y exclusivos con bytes sintéticos; manifiesto de filas/objetos/secuencias; inspección suficiente de FK, triggers protegidos, vistas, eventos, rutinas y funciones; writers/conexiones acotados; allowlist y orden hija→padre; snapshots deterministas de las 26 tablas antes/después. La identidad del servidor no sustituye esa inspección de dependencias.

Para fallos después de pasos 1, 3 y 9, registrar tablas realmente desaparecidas y recibos DDL confirmados. DDL MySQL implica commits; **no confiar en ROLLBACK de una secuencia de DROP**. Un fallo después del último DROP también requiere checkpoint/verificación y no debe etiquetarse como éxito automático. No reanudar sin comparar estado efectivo y manifiesto aprobado. Cada escenario necesita una base nueva o una restauración explícita.

El backup futuro debe hacerse con writers sintéticos detenidos y capturar tablas, filas, FK, índices, triggers, vistas, rutinas/eventos y autoincrementos; restaurarse en otro destino efímero y comprobar determinismo y workflows antes de darlo por válido. La consistencia de InnoDB no demuestra por sí sola consistencia de objetos/medios frente a writers concurrentes. **No se ha ejecutado ni certificado restauración MySQL cuando ese gate figura NOT RUN**. Tampoco una restauración sintética certifica recuperación de producción.

La protección fotográfica W5.1/LegacyPhotoReferences sigue intacta. Los ensayos SQLite conservan su cobertura con Storage::fake; no se atribuye esa cobertura a MySQL. Registro/reset/logout, roles, catálogo/servicios, conversaciones, reservas, avisos, API y fotos deben probarse con datos de negocio y autorizaciones reales de fixtures cuando sea posible instalar el esquema; HTTP 200 no es sustituto.

## Entrega separada propuesta

Primero revisar y autorizar una estrategia de soporte MySQL que preserve las garantías de unicidad, estados, ownership, fechas y concurrencia. Posibles diseños a evaluar: columnas generadas e índices únicos para unicidad condicional, CHECK soportados, triggers con SIGNAL y validación de transiciones, más pruebas de carreras y locks InnoDB. No son soluciones implementadas ni aprobadas aquí.

Añadir una migración al final no resuelve por sí solo el guard de una migración anterior en instalaciones nuevas. La estrategia para historia/bootstrap e instalaciones existentes debe aprobarse por separado, sin reescritura silenciosa ni omisión de garantías. Después repetir instalación, fixtures, todos los negativos, fallos parciales, backup/restauración y workflows antes de proponer una migración de retirada. No se amplía ese alcance en este PR.

Los gates reales siguen cerrados: autorización específica de lectura de la instalación, clasificación de datos/medios, manifiestos de límites, backup consistente restaurable, parada de writers, aprobación de la migración y de cada ejecución/recuperación. Revertir Git no restaura DB, fotos ni eventos externos.

## Ejecuciones preparatorias y alcance de lectura

Los intentos preparatorios constan en evidencia: el primero omitió el entrypoint sin extensión de PHPUnit; el segundo lo copió a tmpfs de /app pero docker cp no lo hizo visible al proceso; el tercero carecía de XSD de PHPUnit; el cuarto llegó a PHPUnit pero faltaban las rutas PHP que Laravel necesita al arrancar. Se corrigió solo el empaquetado permitido de código. Sus estados no acreditan instalación ni migraciones; los recursos de cada intento se retiraron por identidad.

Además, el primer build y la primera ejecución normal SQLite usaron los loaders normales de Vite/Laravel que seleccionaban el `.env` existente (lectura inferida del código de selección ejecutado, archivo presente y ausencia de `.env.testing`; no se abrió su contenido para investigar). Se declara esta incidencia de alcance: no se inspeccionó/imprimió su contenido ni se modificó; la conexión de tests permaneció :memory:. Esos resultados no se usan como prueba de aislamiento de entorno. Se repitió el build con envDir apuntando a un directorio temporal vacío, y las pruebas con un `.env.testing` temporal vacío creado de forma exclusiva y eliminado finalmente. No se copiaron ese `.env` ni sus valores al laboratorio MySQL. No se afirma cumplimiento absoluto de cero lecturas de configuración existente en toda la ejecución.

Los resultados activos corresponden al build y a las pruebas repetidas con ese aislamiento; auditorías anteriores no se reciclan como resultados nuevos. La guía de reproducción usa un clon nuevo sin `.env`, nunca copiar uno real. No se abrieron ni releyeron medios reales/versionados en W6.2c-b ni se recalcularon sus hashes.

## Alcance confirmado de esta ejecución MySQL

La instalación real confirmó **28 de 37 migraciones**. Se detuvo en la nº 29, `2026_10_28_000002_create_service_inquiries_table.php`, con RuntimeException: «Las solicitudes requieren SQLite y su índice único parcial. Revisar la estrategia antes de usar otro motor.» La última aplicada fue `2026_10_28_000001_create_services_table.php`. No se omite el guard: es una incompatibilidad de diseño reproducida sobre MySQL 8.4.13.

Se verificaron **76 sentencias DDL de instalación** mediante el guard. Al detenerse quedaron 23 tablas de aplicación (incluido migrations), más la tabla de marcador: 24 en total, todas InnoDB. Estaban presentes 14 de las 26 tablas protegidas y faltaban 12; no se puede comparar ni certificar la conservación de las 26. Había cero triggers, vistas, rutinas y eventos en ese esquema parcial; no equivale a los 34 triggers del esquema SQLite completo.

El intento de instalación duró **0,719 s** dentro de PHPUnit; la suite del worker duró **1,020 s**, sin incluir aprovisionamiento/compilación. Cinco casos negativos de identidad pasaron; el caso que exige las 37 migraciones falló: **6 tests / 18 aserciones / 1 fallo**, no una suite MySQL aprobada. Las duraciones de cada caso están en JUnit y JSON. No hubo DROP de retirada ni restauración; B–E, los negativos del esquema y los workflows/fotos MySQL quedan **NOT RUN**, bloqueados por A.

| Control | Estado y evidencia |
| --- | --- |
| Base, checkout inicial, disco/inodos | PASS |
| Dependencias desde locks, sin scripts de proyecto | PASS |
| Build repetido con envDir vacío | PASS |
| PHPUnit SQLite repetido con entorno vacío | PASS: 688 / 9.224 |
| W5.1, W62A, W62B, W62C-A repetidos | PASS: 49/459, 25/406, 34/586, 19/198 |
| Motor real / aislamiento de contenedores | PASS para la ejecución acreditada |
| Cinco negativos de identidad | PASS |
| Instalación completa MySQL | BLOCKED; test FAIL, 28/37 |
| Fixtures completos / retirada / fallos 1-3-9 / restore / workflows MySQL | NOT RUN |
| Composer validate / plataforma | PASS |
| Composer audit con/sin dev | BLOCKED: API Packagist, proxy 403 CONNECT |
| npm audit / Pint de integración / diff check | PASS |
| Windows | NOT RUN; guía preparada |
| Criterio de cero lecturas de configuración existente en toda la tarea | FAIL histórico: loaders iniciales; corregida la ejecución de aceptación y documentado sin ocultar |
| Autorización/certificación de eliminación real | BLOCKED |

Las imágenes y fuentes nativas, credenciales existentes, API, migraciones y lógica de aplicación no se modificaron. El registro de la incidencia de configuración impide afirmar que ningún recurso de configuración existente fue leído durante toda la tarea. No se accedió a DB reales ni medios reales. Los resultados no autorizan iniciar W6.2c real.
