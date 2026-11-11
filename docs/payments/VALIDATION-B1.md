# B1 — Validación

Estado: PASS del núcleo sintético y regresiones; persistencia de aplicación pendiente
de autorización y desarrollo. No hay activación financiera.

## Preparación verificada

- Main remoto: `ac999cea8f592ce7b94481ee30f602b67d9f5bea`.
- Rama local nueva: `codex/payments-b1-pricing-quotes`; sin cambios previos.
- Espacio inicial en /workspace: 42 GiB disponibles; 3.715.166 inodos libres.
- No existe ejecutable PHP/Composer en el host. Runtime reutilizado: imagen local
  `tekora-w62cb-php:local`, PHP 8.4.27, SQLite 3.46.1, PHPUnit 11.5.57.
- Copia aislada nueva de fuentes autorizadas, excluyendo `.env*`, uploads, storage,
  bases persistentes y medios rasterizados. Storage/cache del ensayo nuevos.
- Docker sin red, con vendor existente montado de solo lectura; no monta la
  instalación original, salvo las dependencias. PHPUnit fija SQLite `:memory:`.
- No se creó ni aplicó ninguna migración nueva; la propuesta está en documentación.
- Consulta de la conexión sintética: PRAGMA database_list devuelve main con file vacío.
- Los 12 archivos PHP ejecutados coinciden byte por byte con los de la rama entregada.
- 52 fuentes protegidas comparadas con la base (37 migraciones, rutas, Service,
  ServiceRequest, ReservationWorkflow y OpenAPI): ninguna diferencia. Tampoco hay
  modificaciones de otros archivos previamente versionados.

## Estados

- Aprobación legal/fiscal/comercial definitiva: PENDING.
- Adaptador SQLite persistente y concurrencia entre procesos: NOT RUN; requiere
  autorización del esquema y nueva implementación, no se infiere del store en memoria.
- Windows 11 / PHP 8.2: NOT RUN.
- Auditorías Composer/npm: NOT RUN en B1; no se declaran aprobadas. Sin cambios de
  dependencias, locks o configuración. No se ha evaluado el proxy de auditoría.
- Proveedor financiero, sandbox, cobros y retenciones B2: NOT RUN / fuera de alcance.

## Comandos de pruebas

Dentro de la copia aislada, en el runtime descrito:

```sh
php vendor/bin/phpunit --group payments-b1 --log-junit /app/payments-b1-junit.xml
php -d memory_limit=1G vendor/bin/phpunit --group payments-b1 --log-junit /app/payments-b1-junit.xml
php -d memory_limit=1G vendor/bin/phpunit --log-junit /app/full-junit.xml
php vendor/bin/pint --test app/Payments tests/Support/InMemoryPricingStore.php tests/Unit/CommercialAmountTest.php tests/Feature/PaymentsPricingQuotesTest.php
npm --offline run build
```

## Resultados efectivos

| Verificación | Estado | Resultado |
| --- | --- | --- |
| B1 final | PASS | 82 tests / 4.353 aserciones, 0 errores, 0 fallos, 0 omitidos |
| PHPUnit completo | PASS | 871 / 14.697, 0 errores, 0 fallos, 0 omitidos; 132,50 MB |
| Baseline anterior, dentro de la suite completa | PASS | 789 / 10.344; todas las pruebas históricas conservadas |
| W5.1, incluido en la suite completa | PASS | 49 / 459 |
| W6.2a, incluido en la suite completa | PASS | 25 / 406 |
| W6.2b, incluido en la suite completa | PASS | 34 / 586 |
| Ensayo W6.2c-d, incluido en la suite completa | PASS | 120 / 1.318 |
| Pint de los archivos nuevos | PASS | 12 archivos |
| Build aislado | PASS con avisos | Node 24.22.1, npm 11.9.0, Vite 6.4.5; 67 módulos |
| 37 migraciones y 21 operaciones API conservadas | PASS | Sin alteración de sus fuentes |

Los subconjuntos históricos se extraen del JUnit de la suite completa; no se
atribuyen a comandos de grupo que no se ejecutaron por separado en B1.
El JUnit final desglosa B1 en 43 tests unitarios / 4.126 aserciones y 39 tests
de integración / 227 aserciones. Resumen y huellas de fuentes en
[evidence/B1-validation.json](evidence/B1-validation.json).

## Incidencias conservadas y avisos

1. Primer grupo B1: PASS, 78 / 4.342. Después se añadieron cuatro casos sobre
   separación de UUID entre clientes y vigencias inválidas, más el marcador sintético.
2. Primer intento de suite completa: FAIL / incompleto. La copia aislada carecía
   de public/build/manifest.json y las vistas fallaban; el proceso terminó con exit
   255 por el límite de memoria de 128 MiB. No hay conteo final válido de esa corrida.
3. Se creó otra copia aislada con los mismos filtros, se generaron sus assets y se
   ejecutó la versión final con memory_limit=1G. No se alteraron expectativas históricas,
   controles ni código productivo para resolver el problema del entorno.
4. El build informa que las dos fuentes woff2 públicas quedan como referencias
   para resolución en runtime. No es una prueba visual ni de carga de fuentes en navegador.
5. El runtime emite un aviso proc_open/posix_spawn de sebastian/version al buscar
   información Git. Se conserva en los logs; PHPUnit termina con exit 0 y los
   conteos indicados. El SHA se verificó mediante Git del host y GitHub, no por ese helper.

Logs/JUnit locales de la ejecución final: copia aislada
`/workspace/tekora-payments-b1-plxbvj2o` (full.log, full-junit.xml,
payments-b1.log, payments-b1-junit.xml, pint.log, check-results.json).
No contienen datos de instalaciones reales. No se publican ni se crea un PR.

## Límites de aceptación

El PASS valida el prototipo en memoria y la ausencia de regresiones en este runtime.
No valida persistencia de cotizaciones, concurrencia SQLite nueva, fiscalidad,
contratación, una interfaz financiera web, un contrato financiero móvil ni pagos.
La retención B2 de 900 segundos está únicamente documentada y no se ejecutó.
