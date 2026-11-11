# ADR — Motor de base de datos de TEKORA

Fecha: 2026-11-11. Estado: **decisión provisional propuesta para revisión**. W6.2c-c, SOLO TEKORA.

Base exacta: `2441debb728982229d3a8a476ca9618c116b6172`. Esta entrega solo añade documentación. No autoriza cambio de motor, retirada real, fusión ni despliegue.

## Decisión y fundamento

**SQLite continúa como motor soportado mientras no exista un requisito explícitamente aprobado para MySQL.** Se mantienen sus restricciones, índices parciales, triggers y garantías transaccionales. El siguiente paso mínimo es ampliar el ensayo de retirada en SQLite aislado con datos sintéticos; no implementar una purga real.

La incompatibilidad observada en MySQL es un límite del soporte de ese motor, no un defecto demostrado de la implementación SQLite. Hacer compatible MySQL no es un requisito previo para retirar MindBloom de una instalación SQLite. Sí son requisitos los controles de integridad, dependencias, referencias fotográficas, historial, recuperación y autorización de la instalación que realmente vaya a intervenirse.

La [guía del repositorio](../../README.md) declara «Esta etapa solo valida SQLite» y exige `pdo_sqlite`. La [configuración de conexiones](../../config/database.php) usa SQLite como valor predeterminado; sus entradas genéricas para MySQL/MariaDB/PostgreSQL/SQL Server no certifican soporte funcional. Se inspeccionó el texto, sin evaluar configuración ni leer `.env`: **no se ha identificado el motor de ninguna instalación real**. Laragon tampoco implica MySQL.

Se revisaron las 37 migraciones históricas. Las 1–28 contienen infraestructura, las tablas antiguas y el inicio del dominio nativo; que su instalación haya pasado en MySQL no certifica los flujos TEKORA. Las 29, 33, 34, 35 y 36 detienen expresamente motores distintos de SQLite. Las 30–32 y 37 tienen construcciones Blueprint, pero su ejecución y su integración MySQL siguen sin demostrar. El [inventario por invariante](TEKORA-DB-INVARIANTS-MATRIX.md) identifica las garantías que no se pueden perder.

## Evidencia y límites

| Fuente | Resultado observado | Interpretación |
| --- | --- | --- |
| [W6.2c-a](../web/w6/W62C-A-PREFLIGHT.md), [validación](../web/w6/evidence/W62C-A-validation.json) | SQLite sintético: 35 tablas, nueve candidatas, 26 protegidas y 34 triggers; preflight 19 pruebas / 198 aserciones; suite 688 / 9.224 | Demostración histórica en el entorno de pruebas, sin certificar instalaciones reales ni recuperación física |
| [W6.2c-b, HEAD inmutable del PR #28](https://github.com/Fernando261/tekora-project/blob/ae638c358b475d5c6605cf2d0440c03b2225a2d0/docs/web/w6/W62C-B-MYSQL-LAB.md) y [validación](https://github.com/Fernando261/tekora-project/blob/ae638c358b475d5c6605cf2d0440c03b2225a2d0/docs/web/w6/evidence/W62C-B-validation.json) | MySQL 8.4.13/InnoDB aislado: 28/37 aplicadas, bloqueo en 29. PHPUnit: seis pruebas / 18 aserciones, cinco pasan y una falla | Instalación completa **BLOCKED**, resultado de la suite MySQL **FAIL**; no compatibilidad |
| Mismo experimento MySQL | 14 tablas protegidas presentes y 12 ausentes; retirada, recuperación y workflows nativos **NOT RUN** | Un esquema parcial no puede compararse como equivalente al esquema SQLite |
| Esta entrega W6.2c-c | Validaciones documentales; ninguna prueba ni aplicación ejecutada | Baseline histórica conservada; resultados de ejecución actuales **NOT RUN** |

El PR #28 permanece abierto, en borrador y sin modificar, con HEAD `ae638c358b475d5c6605cf2d0440c03b2225a2d0`. Sus tests de laboratorio no se incorporan a esta rama. La frase de su evidencia que exige compatibilidad MySQL para aceptar una retirada real debe entenderse como gate **del recorrido MySQL**. Esta propuesta descarta interpretarla como prerrequisito global para el recorrido SQLite; no cambia aquella evidencia ni abre los gates de intervención real de W6.2c-a.

Se conservan los antecedentes: W6.2c-a reconoce nueve lecturas accidentales de uploads y diez imágenes leídas para SHA-256, cuya publicación histórica se corrigió sin reescribir el historial. W6.2c-b reconoce selección inicial de `.env` por loaders normales y repeticiones aisladas. Esta entrega no vuelve a leer medios, recalcular hashes de medios, abrir bases ni ejecutar loaders. No se presenta el proceso histórico como cumplimiento de cero lecturas.

## Alternativas

| Dimensión | A — SQLite actualmente soportado (elegida provisionalmente) | B — Soporte oficial SQLite + MySQL | C — Migración futura SQLite → MySQL |
| --- | --- | --- | --- |
| Complejidad | Acotada: preservar garantías y ensayar retirada en el motor validado | Alta: dos implementaciones de restricciones y recorridos de instalación/upgrade | Alta: conversión de esquema y datos, sincronización y corte |
| Integridad | Conserva defensas existentes; depende todavía de verificar instalación, referencias y restauración | Riesgo de carreras, coerción de tipos, collation, estados/eventos divergentes; exige equivalencia por invariante | Riesgo de pérdida, duplicación, conversión e inconsistencias durante y después del corte |
| Mantenibilidad | Una matriz funcional y una línea de migraciones; pruebas del motor declarado | Dos matrices de integración, concurrencia, backup y evolución; responsables y presupuesto continuos | Proyecto con herramientas/validación propias y periodo de transición; no queda resuelto por un adaptador |
| Despliegue | Sin cambio de motor; validar versión/extensiones, permisos y almacenamiento SQLite del destino antes de cualquier futura release | Versiones/SQL mode/collation/privilegios fijados, drivers y CI independientes; rollback por motor | Plan explícito de origen/destino, ventana, bloqueos de escritura, puesta en servicio y reversión |
| Operación | Coordinar writers, snapshots coherentes y restauración SQLite; ensayar límites de bloqueo/WAL | Dos procedimientos de observabilidad, contención, recuperación y soporte | RPO/RTO aprobados, reconciliación y tratamiento de escrituras posteriores al corte |
| Compatibilidad | Soporte SQLite documentado, sin prometer toda versión/plataforma; MySQL fuera del soporte demostrado | Requiere autorización arquitectónica explícita y todos los invariantes, sin reducirlos | Requiere requisito de producto/operación y aprobación como proyecto independiente |

### A — Consecuencias de la decisión provisional

Preservar el soporte actual evita añadir un cambio de motor al objetivo SOLO TEKORA. No implica que el preflight en memoria pruebe bloqueo de un archivo SQLite en producción, ni que un restore lógico sea un backup físico operativo. El [siguiente PR propuesto](../web/w6/W62C-C-SQLITE-NEXT-PR.md) mantiene cerrada la ejecución real y completa ensayos sintéticos. Los gates MySQL se activarán si se decide soportar o migrar a ese motor, sin bloquear el trabajo aislado SQLite.

### B — Propuesta independiente de soporte MySQL, condicionada a aprobación

Antes de implementar, el responsable de arquitectura debe aprobar necesidad, versiones, nivel de protección y coste de soporte permanente. Luego, un PR independiente diseñaría una ruta explícita de instalación y upgrade por motor, conservando las 37 migraciones, y convertiría cada hipótesis de la matriz en una garantía probada.

El criterio de entrada a soporte oficial sería: esquema completo; casos positivos y negativos por SQL directo y por servicios; concurrencia de conexiones independientes con aislamiento/locks reales; rollback de reserva + evento + aviso; idempotencia y respuestas seguras; instalación nueva y upgrade; backup/restore y fallos DDL; suite funcional y contratos API en ambos motores. El runner MySQL debe ser aislado y opt-in, sin sustituir la suite SQLite habitual ni leer configuración o datos existentes. No basta con quitar guards, omitir la migración 29, traducir sintaxis ni pasar las primeras 28 migraciones.

Cada cambio posterior de negocio tendría que incluir pruebas y revisión en ambos motores. No hay aceptación MySQL hasta ejecutar ese conjunto y resolver las divergencias; hoy su instalación está **BLOQUEADO**, y las garantías posteriores están **NO DEMOSTRADO**.

### C — Proyecto de migración de motor independiente

Requiere inventario autorizado del origen y un contrato de transformación: identidad/IDs, tipos y afinidades, autoincrementos/secuencias, NULL, timestamps UTC y zonas civiles, collation/Unicode, morfos, tokens y relaciones. Hay que cotejar filas y relaciones transformadas, no confundir el checksum de código Git con la integridad de datos. Definir el destino limpio, adaptación de aplicación, transferencia consistente, ensayos, respaldo verificable y restauración de ambos lados.

El plan debe escoger una ventana con writers detenidos o una sincronización expresamente diseñada; no asumir dual-write. El corte exige criterios de consistencia, RPO/RTO, responsables y punto de no retorno. Antes de escrituras en destino puede contemplarse volver al origen validado; después hacen falta reconciliación de nuevas escrituras y una política explícita de pérdida aceptable. Un rollback Git no revierte datos convertidos. Nada de ello se ejecuta indirectamente durante W6.2c.

## Compatibilidad del historial de migraciones

| Estrategia | Instalación nueva | Instalación existente | Historial, reversibilidad y coste |
| --- | --- | --- | --- |
| Históricas intactas (A, recomendación actual) | Ejecutar las 37 en SQLite. Solo una futura release revisada añadiría el paso hacia adelante de retirada; este PR no lo crea | Verificar lista/batches y esquema esperado sin resetear; después, cuando se autorice, añadir el mismo paso futuro | Una línea mantenible; conservar nombres/contenido y comprobar equivalencia fresh/upgrade. Un `down` que recrea tablas no recupera filas ni fotografías |
| Baseline nueva por motor (posible B/C) | Snapshot versionado de esquema completo por motor, con identificador y manifiesto propios; validar arranque desde cero | Upgrade explícito desde cada versión reconocida; no tratar una instalación existente como nueva | Dos linajes y coste de demostrar convergencia; no rellenar el ledger como si se hubieran ejecutado migraciones omitidas. No usar baseline para ocultar el fallo 29 |
| Adaptadores/rutas explícitas de bootstrap (posible B/C) | Selección de motor/versiones validada antes del DDL; construcción completa de todas las garantías | Adaptación versionada y revisada por motor/estado inicial; rechazar mezcla de linajes o esquema desconocido | Conserva trazabilidad si el registro de versión distingue la ruta real; exige matriz de upgrades, rollback/restore y soporte de dos motores. Ningún skip silencioso |

El ledger estándar de Laravel registra nombre y batch; no demuestra por sí mismo checksums del contenido aplicado. Una futura política de integridad puede fijar un manifiesto de **fuentes de migraciones** por release (sin medios ni secretos), compararlo con artefactos de despliegue conocidos y comprobar también objetos reales del esquema. Nunca recalcular una supuesta historia aplicada desde el checkout actual para declarar una instalación conforme. Historial faltante, editado o divergente exige parar y revisar, no marcar migraciones como ejecutadas.

Para SQLite nuevo/existente, preservar tablas protegidas, índices parciales, FK, SQL de triggers, autoincrementos y ledger. No reescribir las 37, ejecutar `migrate:fresh` sobre existentes, editar batches, eliminar guards, efectuar `schema:dump --prune` ni crear aquí migraciones destructivas. El coste menor de A deriva de una sola semántica y un solo linaje; B/C solo se justifican por un requisito explícito, no por convertir un laboratorio fallido en verde.

## Riesgos y criterios de revisión

Aceptar provisionalmente A requiere mantener la matriz y sus límites visibles, no relajar invariantes ni autorizar eliminación real. Revisar la decisión si aparece un requisito aprobado de operación/capacidad para MySQL, una plataforma que exija otro motor o evidencia de que SQLite no satisface la carga requerida. Medir esos requisitos antes de elegir B o C; no se ha medido capacidad productiva aquí.

La retirada SQLite puede avanzar únicamente como ensayo sintético separado. Para un corte real siguen pendientes inventario autorizado, clasificación de referencias/medios, ausencia de writers/dependencias desconocidas, backup consistente restaurado, versión/motor comprobados, límites y ventana aprobados, además de revisión específica de cualquier mecanismo futuro. Estado actual de esa ejecución: **BLOCKED**. Validaciones de esta propuesta: [evidencia documental](../web/w6/evidence/W62C-C-documentary-validation.json).
