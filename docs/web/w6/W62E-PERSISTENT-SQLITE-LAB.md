# W6.2e — Laboratorio persistente SQLite

SOLO TEKORA. Base obligatoria verificada: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`, integración del PR #30; PR #29 aporta la decisión SQLite. Rama independiente `codex/w62e-sqlite-persistent-lab`. PR #28 no se consulta ni modifica. No se cambia motor soportado, producción, configuración, dependencias, 37 migraciones o 21 operaciones API.

Este laboratorio prueba archivos sintéticos reales y recuperación tras fallos **de proceso**. No acredita pérdida de energía, fallo del SO/hardware, durabilidad fsync, backup operativo de una instalación ni recuperación de medios. La publicación del PR no autoriza integración, despliegue o retirada real. La revisión final corresponde al propietario del repositorio.

## Dos capas, dos runtimes

`PersistentSqliteFixtureTest` arranca Laravel en el runner aislado sobre SQLite `:memory:`, aplica las 37 migraciones y crea filas exclusivamente sintéticas: nativas (usuarios/roles, disponibilidad, solicitud/mensaje, reserva/evento/aviso), infraestructura y las nueve candidatas. Exporta esquema/filas/secuencias por stdout solo con `TEKORA_W62E_FIXTURE=1`. No acepta DSN ni rutas de DB. El fixture contiene valores ficticios y no se publica como evidencia. Una fila BLOB se codifica explícitamente.

`tests/Support/w62e/persistent_lab.py` consume ese JSON por stdin. Usa Python stdlib, procesos `spawn` y conexiones SQLite independientes al mismo archivo. **No ejecuta el simulador :memory: sobre una ruta de disco** y no modifica sus guards. Los tests de disco y respaldo se ejecutaron con Python/SQLite distintos del runtime PHP; no se presentan como concurrencia física PDO/Laravel. No se añadió dependencia al producto.

La reproducción de transiciones nativas usa SQL protegido por los triggers históricos: reserva y evento se confirman completos o se revierten tras la salida abrupta del hijo. El retry/idempotencia de jobs+cache es lógica explícita del laboratorio. La cadena de notificaciones y los reintentos del servicio Laravel sobre disco no están certificados por esos dos casos SQL; sus regresiones históricas se ejecutan aparte en memoria.

## Propiedad y aislamiento

Cada test crea una raíz nueva aleatoria `tekora-w62e-*` mediante mkdtemp, modo 0700, y marcador exclusivo 0600 con token aleatorio y PID creador. Se cotejan device/inode/UID/tipo, permisos y marcador antes de abrir recursos y limpiar. Los nombres de recursos son internos y limitados; no se reciben rutas por CLI, `.env` o JSON. Se rechazan archivos existentes, enlaces, rutas externas, reemplazos, permisos abiertos, marcadores alterados y recursos desconocidos. Los argumentos CLI se rechazan. El DDL de fixture solo puede crear definiciones dentro de la DB nueva; no se acepta ATTACH/VACUUM como DDL ni extensiones cargables.

Los sidecars se registran inmediatamente tras la operación SQLite propia que los crea, nunca se adoptan por su nombre durante la limpieza. Se comprueba el inventario completo antes de borrar el primer recurso. Los hijos reciben únicamente el descriptor del laboratorio por `spawn`, sin heredar conexiones activas; registran PID y conexión. No hay terminación de procesos ajenos: solo join/timeout/terminate de objetos Process creados y registrados por el test. Los fallos controlados usan `os._exit` en el propio hijo, con códigos 73/74 y barreras Pipe.

La limpieza se registra con addCleanup antes de preparar el fixture, para cubrir fallos de setup. Se cierran conexiones y se eliminan solo recursos con identidad demostrada. Si hay un recurso desconocido, no se borra nada. Los probes adversos limpian sus archivos de ataque únicamente tras verificar la identidad que guardó su creador; no relajan la política del laboratorio ni adoptan archivos ajenos. No hay comandos globales de limpieza.

## Escenarios

- WAL efectivo, synchronous FULL (2), foreign_keys=1, busy_timeout=150 ms y autocheckpoint desactivado para observación controlada. FULL es configuración observada, no certificación de durabilidad ante apagón.
- Lector con snapshot abierto mientras otro writer confirma; checkpoint TRUNCATE bloqueado por el lector y completado tras liberarlo.
- Dos procesos con BEGIN IMMEDIATE: SQLITE_BUSY extendido observado, espera acotada y retry tras barrera explícita; una operación lógica confirmada y no duplicada. No se depende de sleeps para ordenar operaciones.
- Hijos que salen con transacción abierta antes del commit y después de confirmar, con todas las conexiones del padre cerradas antes de la salida; reapertura independiente sin conexiones supervivientes y preservación del último estado confirmado, sin filas parciales de jobs/cache.
- Reserva nativa y evento por trigger: rollback antes del commit y versión/evento completos después; CAS evita un segundo evento al repetir la transición.
- Backup online mediante `Connection.backup`, WAL activo, restore a destino nuevo exclusivo, comparación de manifiesto completo. No se copia solamente el archivo principal.
- Esquema/SQL, columnas, tipos de fila (incluidos NULL/BLOB/REAL), índices, FK, triggers y secuencias se cotejan. Se ejercen restricciones restauradas (unicidad, FK y solapamiento por trigger).
- Destinos existentes nunca se sobrescriben; backups incompletos o alterados se rechazan antes de crear el destino. Se verifica integridad y foreign_key_check.
- Retirada de nueve candidatas dentro de transacción privada, prefijo fijo, preflight antes de cada DROP, catálogo derivado del baseline revisado y no de una captura arbitraria posterior. Preservación de 26 protegidas, rollback/reapertura y retirada confirmada con lector activo/backup independiente.
- Dependencia sqliteX desconocida, deriva temp, secuencia corrupta/high-water mark, archivo sustituido y sidecar desconocido detienen el escenario antes del DROP o limpieza afectados.

## Evidencias y límites

Ver [validación](evidence/W62E-validation.json) y [escenarios físicos](evidence/W62E-physical.json). Solo versiones, PIDs propios, ajustes, resultados, bloqueos/checkpoints y conclusiones; sin token de propiedad, fixture crudo, credenciales/medios reales ni hashes de archivos existentes. Los manifiestos íntegros permanecen en memoria y se comparan sin exportar valores de usuario.

Se conservan incidencias de preparación: registro inicialmente ausente del SHM de backup (FAIL; el guard detuvo la apertura siguiente) y un validador que solo admitía nombres de tabla entre comillas (FAIL para DDL histórico legítimo sin comillas). La corrección admite solo el identificador exacto esperado, con/sin comillas, seguido de `(`; no permite DDL arbitrario. Se añadió addCleanup temprano. Dieciocho raíces de esos setup fallidos anteriores quedaron retenidas: no se conserva prueba suficiente de identidad de todos sus recursos para borrarlas automáticamente. Su limpieza queda **BLOCKED**; no se hizo una purga por prefijo. Los runs finales demuestran limpieza de sus propias raíces nuevas. No se afirma que se limpiaron todos los artefactos históricos.

No se atribuye PASS a auditorías Composer bloqueadas por proxy. Windows 11/Laragon/Docker Desktop, PHP PDO sobre archivo persistente, caída del SO, pérdida de energía y hardware/fsync: NOT RUN. MySQL sigue independiente. El historial 789/10.344 del PR #30 se conserva como referencia, separado de las ejecuciones nuevas en el JSON.

## Ejecución aislada

Usar una copia nueva de fuentes del SHA del PR, seleccionadas antes de checkout/copiar: app, bootstrap PHP, config, migraciones/factories/seeders, routes, resources de código, tests, docs y manifests de dependencias; excluir `.env*`, Storage existente, DB, uploads e imágenes. Crear solo `.env.testing` vacío y directorios cache/storage propios. No ejecutar servidores, workers, migrate:fresh ni scripts de instalación contra otra instalación. El bootstrap existente fuerza SQLite en memoria para generar el fixture.

Linux con PHP/extensiones del proyecto, Composer/Node compatibles con lock y Python 3.12 con sqlite3. Desde la copia nueva:

```sh
# Capturar en un archivo nuevo propio, no en una raíz de datos existente.
TEKORA_W62E_FIXTURE=1 php -d memory_limit=512M vendor/bin/phpunit tests/Feature/PersistentSqliteFixtureTest.php --colors=never > fixture-output.txt
python - <<'PY'
import base64, json, re
from pathlib import Path
text=Path('fixture-output.txt').read_text()
data=json.loads(base64.b64decode(re.search(r'W62E_FIXTURE=([A-Za-z0-9+/=]+)',text)[1]))
# Output de un fixture nuevo, no una DB ni un medio existente.
with open('fixture-private.json','x') as f: json.dump(data,f)
Path('fixture-private.json').chmod(0o600)
PY
PYTHONDONTWRITEBYTECODE=1 python tests/Support/w62e/persistent_lab.py < fixture-private.json > physical.json
PYTHONDONTWRITEBYTECODE=1 python tests/Support/w62e/adversarial_lab.py < fixture-private.json > adversarial.json
php -d memory_limit=512M vendor/bin/phpunit
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/RetirementPreflightTest.php
php -d memory_limit=512M vendor/bin/phpunit --group photo-integrity-w51
php -d memory_limit=512M vendor/bin/phpunit --group legacy-decoupling-w62a
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/RetiredProductTest.php
php vendor/bin/pint --test tests/Feature/PersistentSqliteFixtureTest.php
composer --no-plugins --no-scripts validate --strict
composer --no-plugins --no-scripts check-platform-reqs
composer --no-plugins --no-scripts audit --locked --format=json
composer --no-plugins --no-scripts audit --locked --no-dev --format=json
npm audit --package-lock-only --json
npm run build
git diff --check
```

Los logs de exportación/fixture son privados y sintéticos: no publicarlos como evidencia. Los scripts Python no aceptan rutas a archivos SQLite. No sustituirlos por instalaciones existentes para reproducir.
