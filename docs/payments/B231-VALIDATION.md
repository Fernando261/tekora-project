# B2.3.1 — Validación de implementación

> **fix1:** el contenido original conserva la validación histórica de `6121f35`. La corrección de procesos, T07 y el diagnóstico separado PHP 8.2 se documentan en [B231-FIX1.md](B231-FIX1.md), con evidencia nueva por runtime. No se ejecuta nuevamente el fork inseguro.

**READY FOR INDEPENDENT REVIEW**, exclusivamente para el laboratorio sintético. Esta es la validación de implementación y no sustituye una nueva revisión independiente, autorización de publicación, integración o activación productiva.

Rama local: `codex/payments-b231-sqlite-integration-lab`. Base exacta: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`. El SHA local final se entrega por separado para evitar incluir un hash autorreferencial en su propio commit. Las huellas de las fuentes efectivamente ejecutadas están en [B231-validation.json](evidence/B231-validation.json).

## Alcance entregado

[Arquitectura, contratos y políticas](B231-SHARED-SESSION.md); [SQL íntegro](../../tests/Support/PaymentsIntegration/fixture-schema.sql); [inventario completo](B231-INVENTORY.md); [45 casos planificados](B231-TEST-RESULTS.csv); [cinco P1 y cinco P2](B231-RISK-STATUS.csv).

Se implementaron propietarios/leases de sesión, cinco puertos compañeros sin atomic, una UOW BEGIN IMMEDIATE, coordinador de actores sintéticos, binding comercial persistido, ocupación común, eventos y recibos, cierre posterior a committed y recuperación por la misma intención. No se conectaron los stores originales privados al mismo PDO: se reutilizaron sus DTO/codec exactos y se adaptaron los contratos exclusivamente en nuevos archivos de tests. Esta diferencia arquitectónica es deliberada y está documentada.

El SQL ejecutado contiene **21 tablas STRICT/WITHOUT ROWID, 112 triggers y cuatro índices explícitos**, además de PK/UNIQUE. user_version=231. El esquema mínimo funcional para este ensayo incluye identidades, precio/quote/consentimiento, propuesta/binding, activación, hold, claim, cabeceras/eventos/recibos, reloj, evidencia financiera sintética y manifiesto legacy sellado. No es unión indiscriminada de los esquemas 221/222 ni propuesta de migración productiva. Su tamaño merece revisión independiente de las relaciones diferibles y guards.

## Entorno y aislamiento

PHP **8.4.27 x64**, PDO SQLite **3.46.1**, PHPUnit **11.5.57**, Node **24.22.1**, npm **11.9.0**, Vite **6.4.5**. UTF-8, DELETE, synchronous FULL, foreign_keys=1, ignore_check_constraints=0, recursive_triggers=0, busy_timeout=0.

Copia nueva de fuentes estáticas autorizadas, sin `.env*`, bases, medios, uploads ni Storage existentes. Contenedores efímeros **sin red**, UID 1000, vendor y referencias montados de solo lectura; Storage/cache nuevos. El nombre de la imagen de runtime de pruebas no implica utilizar instalaciones productivas. Main/B1 construyen sus fixtures históricos únicamente en SQLite `:memory:`; los prototipos y B2.3.1 crean archivos SQLite nuevos propios. No se ejecutó migración sobre instalación existente. No hay limpieza automática, DROP de tablas de instalaciones, adopción ni eliminación de las 18 raíces W6.2e.

La comprobación de sintaxis preliminar de SQL también utilizó Python SQLite 3.53.1 en RAM. **Los resultados de integración y concurrencia aquí informados son de PHP/PDO 3.46.1**, no se atribuyen a Python.

## Resultados finales realmente ejecutados

| Ejecución | Estado | Pruebas / aserciones | Resultado |
| --- | --- | --- | --- |
| B2.3.1 | PASS con un subcaso BLOCKED | 78 / 584 | 77 PASS, 1 omitido, 0 errores/fallos |
| A: contratos/sesión | PASS + BLOCKED fork | 10 / 63, incluidos | 1 omitido por pcntl_fork ausente |
| B: integración comercial | PASS | 19 / 144, incluidos | Binding, importes y tiempo |
| C: retenciones/ocupación | PASS | 14 / 105, incluidos | Estados, exclusividad y cierre |
| D: rollback | PASS | 12 / 115, incluidos | Abortos, constraints y recibos |
| D: contención/errores | PASS | 17 / 105, incluidos | BUSY por fase y límites |
| E: recuperación/procesos | PASS | 6 / 52, incluidos | Tres casos con procesos reales y tres de incertidumbre inyectada |
| Procesos PHP independientes | PASS | 3 / 31, incluidos en E | Carrera y dos escenarios de resolución |
| B1 original | PASS | 82 / 4.353 | Suite separada, sin modificar fuentes |
| B2.1 original | PASS | 80 / 323 | Suite separada, sin modificar fuentes |
| B2.2.1 original | PASS | 135 / 621 | Incluye fix1 |
| B2.2.2 original | PASS | 125 / 765 | Incluye fix1 y procesos originales |
| Histórica main | PASS | 789 / 10.344 | Suite separada |
| W5.1 | PASS | 49 / 459, incluidos en main | Extraídos de JUnit, no comando separado |
| W6.2a | PASS | 25 / 406, incluidos en main | Dos clases originales |
| W6.2b | PASS | 34 / 586, incluidos en main | RetiredProductTest |
| W6.2c-d | PASS | 120 / 1.318, incluidos en main | RetirementPreflightTest |
| Pint --test | PASS | 28 PHP nuevos | Exit 0 |
| Build offline | PASS con avisos | 67 módulos | Exit 0 |
| integrity_check / foreign_key_check | PASS | `ok` / `[]` | En cada teardown ordinario y sondeo nuevo |
| Fuentes y diff | PASS | 68 referencias; 45 fuentes protegidas | 37 migraciones y 21 operaciones OpenAPI 1.1.0 intactas |

No sumar grupos incluidos, reejecuciones ni suites separadas como un total integrado. Las nuevas pruebas no entran en la suite estándar Unit/Feature de main: requieren el bootstrap y directorio explícitos.

El subcaso de fork heredado no se ejecutó porque falta `pcntl_fork`. No se habilitó una extensión para presentar PASS. Sí se verificaron por separado rechazo de serialización de lease, leases revocadas, proceso/epoch del propietario y conexiones/permits propios desde **PIDs PHP diferentes mediante proc_open**. Eso no sustituye la prueba omitida sobre una sesión heredada por fork.

Los 45 renglones del plan se trazan individualmente en CSV: 38 PASS dentro del modelo sintético, T07 BLOCKED en su subcaso heredado, T40–T44 NOT RUN y T45 con regresiones PASS/auditorías NOT RUN. T36 solo acredita cierre básico del fixture; no certifica las políticas financieras o la cobertura ampliada de B2.3.2.

## Evidencia de atomicidad, procesos y recuperación

- Un mismo objeto PDO y epoch aparecen en todos los participantes de cada comando. No es la única prueba: el observador independiente ve cero holds/claims antes de COMMIT y el conjunto completo después.
- Los fallos en publicación, quote, consentimiento, binding, activación, hold, claim, cabecera y después del sello revierten todos los efectos del comando. Las filas previamente confirmadas conservan valores/tipos/IDs/versiones; no se agrupan actores distintos en una transacción ficticia.
- COMMIT forzado sin recibo, claim/cabecera recíprocos o fase coherente falla por constraints. No se desactivaron guards ni se alteraron expectativas originales.
- En la carrera, A conserva BEGIN IMMEDIATE mientras B llega desde otro PID. B agota BEGIN sin invocar trabajo. Tras liberar A, el reintento **explícito** de B lee la ocupación confirmada y devuelve slot_conflict. A y B invocan sus cuerpos una sola vez en las invocaciones que llegan a Active. Una tercera conexión verifica un único hold/claim y cero pares solapados, integridad/FK e historial.
- Pérdida de acuse inyectada después del COMMIT: nuevo PID resuelve el mismo intent, conserva IDs/t0/deadline y devuelve el estado expired al llegar a 900 segundos, sin callback de aceptación. Con otro writer activo la resolución queda BUSY; después del rollback del writer, la ausencia de recibo se demuestra bajo BEGIN IMMEDIATE como not_committed.
- COMMIT detrás de un lector real prueba éxito tras liberar y agotamiento con rollback; el cuerpo no se repite. Los errores de cuerpo, observador, códigos no BUSY y regresiones de reloj se prueban por separado. El COMMIT que responde tarde se distingue de una aceptación vigente.

Las cuatro trazas JSON conservadas de carrera/recovery pertenecen a dos pases del mismo conjunto; **no son cuatro pruebas adicionales**. El JUnit final es la autoridad de conteos.

## Comandos reproducibles

1. Verificar base y las cuatro referencias contra los SHA de `references.json`.
2. Exportar a una ruta inexistente: `python tools/payments-b231/export_references.py <repo> <destino_nuevo>`.
3. Usar una copia estática aislada de main más estos archivos nuevos, vendor conocido de solo lectura, sin env/medios/DB/Storage existentes; definir `TEKORA_B231_REFERENCES=<destino_nuevo>`, `TEKORA_B231_RUN_ROOT=<parent_privado_nuevo>`. Opcional: `TEKORA_B231_TRACE_ROOT=<evidencia_nueva>`.
4. Ejecutar `php -d memory_limit=1G vendor/bin/phpunit --bootstrap tests/payments-b231-bootstrap.php tests/PaymentsIntegrationPrototype --log-junit <nuevo.xml>`.
5. Ejecutar Pint sobre los 28 PHP nuevos y `npm --offline run build` antes de la suite histórica. Esta última usa memory_limit=1G.
6. Para los originales, exportar sus archivos exactos a la copia de ejecución y usar sus propios bootstraps, variables B221/B222 y directorios, en procesos separados. No superponer los loaders originales dentro del bootstrap conjunto. Los comandos concretos están en el JSON; no exportar datos/medios ni copiar árboles completos de instalaciones.

El run real se conserva en `/workspace/tekora-b231-validation-pqd8s6pu/`: scripts de ejecución, copia segura, referencias externas, recursos nuevos retenidos y `logs/` con salidas/JUnit/metadata/trazas/huellas. Son rutas locales, no enlaces públicos portables. No se versionan permits ni tokens. Todas las fuentes ejecutadas coinciden byte a byte con las entregadas y con los blobs exactos de dependencias. La evidencia no incluye hashes de medios ni datos de usuarios.

## Registro histórico de errores y correcciones de implementación

Se conservan logs y JUnit iniciales, sin sobrescribirlos:

- FAIL inicial: helper `count` del arnés colisionaba con método final de PHPUnit; renombrado. JUnit vacío retenido.
- P2 corregido: consulta de resultado posterior al COMMIT tenía `hold_id` ambiguo. Se cualificó `h.hold_id`. El código devolvió incertidumbre y no reejecutó una escritura ya confirmada. El pase con cuatro errores se conserva.
- Se reforzaron guards de propuesta activa por inquiry, relación versión/activación del hold, calendario UTC completo, sello/referencias/actor de recibos y manifiesto legacy cerrado. Sus regresiones se ejecutaron con el esquema final.
- Fallos del arnés: contador/sesión capturados por valor en arrow functions y array de pipes no inicializado para proc_open. Se corrigieron las observaciones e inicialización; **las expectativas no se rebajaron**.
- El guard de inquiry rechazó correctamente un fixture que intentaba crear una propuesta para la misma inquiry histórica. El caso de ocupación pasó a usar otra inquiry sintética del mismo prestador, manteniendo el mismo horario y la expectativa slot_conflict.
- Pint detectó una línea en blanco faltante al añadir una prueba; se corrigió y repitió --test.

No quedan FAIL finales de las comprobaciones ejecutadas. El BLOCKED de fork y los NOT RUN permanecen explícitos. Avisos: proc_open/posix_spawn del helper Git sebastian/version; PHPUnit termina con el estado arriba indicado y los workers reales sí ejecutaron. Vite conserva dos referencias woff2 para runtime; no se verificó visualmente la carga de fuentes.

## Hallazgos y diez riesgos

No se confirma un P0/P1/P2 bloqueante restante **dentro del laboratorio validado**. El P2 de la consulta posterior al COMMIT está corregido y cubierto por los recorridos/recovery; no equivale a aprobación independiente. Las limitaciones de plataforma, contención finita y complejidad de constraints deben revisarse. Los defectos de arnés/formato son P3 resueltos y su historia permanece.

Los cinco P1 y cinco P2 originales no se cierran globalmente: I1–I8 tienen mitigaciones sintéticas y riesgos productivos abiertos; I9 sigue OPEN; I10 es parcial. La matriz registra causa, estado inicial, cambio, evidencia, residual y estado final. Escribir código no se considera evidencia de cierre.

## Pendientes y siguiente decisión

**NOT RUN:** Windows/PHP 8.2, mínimo SQLite 3.38, WAL/BUSY_SNAPSHOT, recuperación física, pérdida real del driver/OS durante COMMIT, auditorías Composer/npm, gestor Illuminate y autorización real, instalación/migración/cohortes reales, recorridos web/API, reservas históricas reales, integración productiva/financiera. Composer audit no está aprobado: el antecedente HTTP 403 permanece sin resolver, no se volvió a ejecutar aquí.

Pendientes legales/fiscales/comerciales y del proveedor: S0/freeze productivos, margen al inicio, cancelaciones/refunds/conciliación, impuestos/costos/liquidaciones y proveedor. Las evidencias financieras son construcciones de system del fixture y no validación bancaria.

Siguiente paso recomendado: **nueva revisión técnica independiente de este commit local**, especialmente ownership/leases, FK diferibles/sellos, clasificación de COMMIT y políticas temporales. Publicación, B2.3.2 o integración requieren autorización posterior. No se hizo push, no se abrió PR, no se fusionó ni desplegó. Main y PR #28/#31/#32/#33/#34/#35 permanecen intactos; los cuatro PR de dependencia siguen abiertos en borrador con los SHA autorizados.
