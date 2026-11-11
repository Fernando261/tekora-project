# W6.2c-c — Propuesta del siguiente PR: ensayo de retirada SQLite

**Propuesta, no implementación ni autorización de corte.** Depende de la revisión del [ADR](../../architecture/ADR-DB-ENGINE-TEKORA.md). Objetivo: SOLO TEKORA, preservando íntegros los datos nativos. MySQL no es prerrequisito de este recorrido SQLite; su soporte queda como proyecto separado condicionado a aprobación.

## Alcance mínimo

Ampliar las pruebas de [W6.2c-a](W62C-A-PREFLIGHT.md) y su [simulador privado PHPUnit](../../../tests/Feature/RetirementPreflightTest.php). Conservar los controles actuales de conexión efectiva SQLite `:memory:`, FK activas y Storage falso; no extraerlo a un comando de purga, servicio productivo, endpoint ni migración ejecutable. Nada debe aceptar rutas de bases existentes o conexiones desde `.env`.

El nuevo PR contendría únicamente pruebas y documentación. La allowlist sintética mantiene el orden `likes`, `comments`, `posts`, `reminders`, `emotions`, `citas`, `profesionista_bloqueos`, `profesionista_disponibilidades`, `profesionistas`. Ya existe un ensayo en memoria: la ampliación debe cubrir los huecos siguientes, sin presentar como nuevos los 19 casos/198 aserciones existentes.

## Contrato de integridad

Comparar las **26 protegidas**, sin excluir una tabla por estar vacía:

- Dominio (18): `users`, `roles`, `role_user`, `provider_profiles`, `service_categories`, `services`, `service_inquiries`, `service_inquiry_messages`, `service_inquiry_reads`, `inquiry_notifications`, `provider_availability_settings`, `provider_weekly_intervals`, `provider_blocked_dates`, `service_reservations`, `reservation_events`, `service_reviews`, `activity_notifications`, `personal_access_tokens`.
- Infraestructura (8): `migrations`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

El manifiesto sintético antes/después debe comparar filas ordenadas por identidad, valores/NULL/tipos relevantes, credenciales y tokens ficticios, PK, columnas/defaults, FK/acciones, índices únicos/parciales y sus predicados, SQL de triggers, secuencias/autoincrementos aplicables y ledger. Las nueve tablas deben desaparecer solo en el simulador; las 26 deben coincidir salvo la expectativa explícita del mecanismo de ledger que se decida en una futura implementación (todavía no existe). No permitir una excepción genérica para cambios nativos.

Verificar los 34 triggers del esquema histórico confiable y sus definiciones, tanto los asociados a una candidata como los asociados a una tabla protegida. Añadir o conservar el caso `users` → `posts`: un trigger nuevo/modificado en `users` que referencia `posts` obliga a **parar antes del primer retiro**. No desactivar triggers, FK ni restricciones para obtener verde; no basta con mirar el nombre de la tabla propietaria del trigger o buscar cadenas en SQL. Dependencia no clasificable, vista desconocida, FK nueva, esquema adjunto inesperado o divergencia del manifiesto debe cerrar el gate. Probar también trigger faltante y alterado con nombre existente.

Para usuarios, reservas y conversaciones, ejecutar después los workflows sintéticos relevantes y comprobar identidades, versiones/eventos/avisos atómicos, lectura monotónica y contratos API; no basta comparar conteos. No convertir `citas` en reservas ni `profesionistas` en `provider_profiles`. No clasificar usuarios para borrado por rol ausente, email, edad o ID, ni borrar padres compartidos mediante cascadas.

Para fotografías conservar [W5.1](../../../tests/Feature/ProfilePhotoIntegrityTest.php), [desacoplamiento](../../../tests/Feature/LegacyPhotoDecouplingTest.php), `ProfilePhotoReferences` y `LegacyPhotoReferences`. Crear exclusivamente imágenes/bytes de prueba en Storage falso: referencia nativa, antigua, compartida, URL externa, ruta normalizada, soft-delete, morfo desconocido, tabla ausente y fallo de consulta. Preservar la política de retener ante duda/error. Comparar bytes **sintéticos** y referencias; ninguna lectura, copia, hash o eliminación de medios existentes. Eliminar filas antiguas pierde referencias: un corte futuro no podrá retirar el puente ni limpiar archivos hasta resolver un manifiesto real autorizado y preservar los compartidos.

## Errores, paradas y recuperación sintéticas

| Escenario propuesto | Resultado exigido antes de aprobar el ensayo |
| --- | --- |
| Guard de conexión, esquema o allowlist no coincide; FK inactivas; dependencia desconocida | Parada antes de la primera operación; cero cambios protegidos; error explícito |
| Inyección tras pasos 1, 3 y 9, antes de commit, y fallo al validar el resultado | Reversión transaccional comprobada; cotejar estado realmente observado y las 26 tablas, no asumir rollback por una excepción |
| Fallo de creación de evento/aviso o cambio de esquema protegido | Rechazo y restauración del estado nativo original; no desactivar triggers |
| Recuperación de estado parcial confirmado simulado | Restaurar a un destino sintético separado; comparar datos, esquema, FK, índices, triggers, secuencias y workflows antes de declararlo utilizable |
| Reintento o reinicio tras fallo | Parar ante estado no reconocido; nunca continuar automáticamente o marcar el lote como aplicado |
| Writer sintético concurrente | Bloqueo/conflicto explícito y ausencia de cambios perdidos; verificar quiescencia antes del corte simulado |

Mantener el restore lógico existente en un segundo PDO `sqlite::memory:`. Para estudiar backup operativo y contención de archivos, proponer un laboratorio adicional **opt-in**, con fixture de archivo SQLite creado por el propio test en un directorio temporal aleatorio, marcador de propiedad y sin rutas/DSN externos. No ampliar el guard del simulador actual para permitir bases persistentes arbitrarias. La futura revisión debe autorizar y verificar este laboratorio por separado antes de ejecutarlo; la suite habitual seguirá en memoria.

Ese laboratorio usaría conexiones independientes y modos journal/WAL declarados, backup coherente con escritores detenidos o una API SQLite de snapshot/backup validada, y restauración a otro archivo recién creado. Copiar únicamente el archivo principal durante escrituras/WAL no demuestra consistencia. Simular backup incompleto/corrupto, fallo de lock y caída tras commit; comprobar integridad SQLite, FK, secuencias y workflows restaurados. Toda limpieza debe limitarse a recursos creados y marcados por el test. Ninguna herramienta destructiva general ni acceso a DB/media real. Estado actual de estos nuevos ensayos: **NOT RUN**; no existen en esta entrega.

Esto tampoco certificará el procedimiento operativo de una instalación real: habrá que ensayar su versión, almacenamiento, permisos y backup consistente DB + medios, con autorización específica y destino aislado. Restore lógico en memoria, restore de archivo sintético y recuperación operativa son evidencias de alcance distinto.

## Instalaciones nuevas y existentes

Una instalación nueva SQLite sigue ejecutando las 37 migraciones intactas. Una futura release de retirada, aprobada por separado, añadiría un paso hacia adelante: probar la convergencia entre fresh + paso futuro y upgrade desde la historia completa, con el mismo esquema nativo y sin nueve tablas. El próximo PR de ensayo no añade ese paso real.

Una existente debe verificar motor/versión, historial/batches, deriva de esquema y dependencias antes de cualquier cambio autorizado. No usar reset, `migrate:fresh`, skip de 29, edición de historial ni recreación de usuarios. Los ensayos deben incluir variantes de historial incompleto o esquema alterado que se detienen; no convertirlas automáticamente en una instalación compatible. Un baseline por motor o adaptador sería otra decisión revisada según el ADR.

El rollback de código no recupera filas/medios. Una restauración real requeriría un punto consistente y tratamiento de escrituras posteriores: no sobrescribirlas con el backup. Definir responsables, ventana, límites, criterios de reapertura y reconciliación en el futuro plan operativo; este documento no autoriza esas acciones.

## Aceptación y límites

El siguiente PR deberá conservar como baseline histórica la suite **688 / 9.224**, W5.1 **49/459**, W6.2a **25/406**, W6.2b **34/586**, W6.2c-a **19/198**, las **21 operaciones API** y **37 migraciones intactas**. Al cambiar tests deberá ejecutar las pruebas afectadas y la suite completa en un entorno que no cargue `.env` existente, con evidencia nueva y estados PASS/FAIL/BLOCKED/NOT RUN explícitos. Los casos adicionales aumentarán conteos; no sustituir ni omitir regresiones.

Gates de un corte real que permanecen **BLOCKED**: acceso/inventario autorizado del destino, clasificación exclusiva de filas y referencias, ausencia de writers/lectores antiguos externos, backup y restore operativos, revisión específica del mecanismo, ventana y ejecución aprobadas. Una prueba verde no los abre. Las autorizaciones de este PR solo cubren documentación y publicación de un PR independiente en borrador. Sin fusión, despliegue, auto-merge, cambios en PR #28 ni retirada real.
