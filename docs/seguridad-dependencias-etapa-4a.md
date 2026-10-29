# Tekora — Etapa 4A: seguridad de dependencias npm

## Base y resultado

La rama `codex/etapa-4a-seguridad-dependencias` parte de `origin/main` en `84b728db4a4984d21ed16d8f578f0b3d5da6679c`, con el PR #5 confirmado como integrado en Git y GitHub.

La auditoría completa del **28 de octubre de 2026, Ciudad de México**, pasa de **13 paquetes afectados (2 críticos, 10 altos, 1 moderado) a cero**. No se usan exclusiones para obtener ese resultado. `npm audit --omit=dev` también devuelve cero, pero ese resultado aislado ya era cero antes y no describe por sí solo la exposición del JavaScript publicado.

Se actualizan Axios/Vite dentro de sus majors y transitivas afectadas dentro de los rangos admitidos. Se retiran Tailwind y su plugin sin uso, junto con su única hoja de scaffolding no importada. No se agregan dependencias directas, overrides, frameworks o funcionalidades.

Laravel 12, PHP 8.2, Blade, reglas de negocio, autenticación, guards, formularios, rutas y datos permanecen intactos. No hay migraciones ni seeds nuevos; los 29 archivos existentes no cambian. No se modifican `.env`, `APP_KEY`, credenciales o bases persistentes.

## Auditoría reproducible antes y después

Los comandos se ejecutaron antes de modificar dependencias y nuevamente con la entrega final. Las instalaciones/compilaciones de evaluación se hicieron en copias temporales sin `.env` o base persistente. La instalación final npm también se realizó desde cero en el workspace; las pruebas PHP siempre usaron una copia aislada y SQLite en memoria.

| Comando | Antes | Después |
| --- | --- | --- |
| `npm ci` | Árbol inicial existente, comprobado con `npm ls --all` | Instalación limpia correcta, código 0 |
| `npm audit` | Código 1, 13 paquetes afectados | Código 0, `found 0 vulnerabilities` |
| `npm audit --json` | Código 1, 2 críticos / 10 altos / 1 moderado | Código 0, todos los contadores en cero |
| `npm audit --omit=dev` | Código 0, cero | Código 0, cero |
| `npm ls --all` | Código 0 | Código 0, sin dependencias requeridas inválidas o ausentes |
| `npm run build` | Correcto | Correcto |
| `php vendor/bin/phpunit --do-not-cache-result` | 143 pruebas, 1573 aserciones | **145 pruebas, 1591 aserciones** |

Evidencia versionada, sin credenciales:

- [Auditoría JSON completa inicial](auditorias/etapa-4a/npm-audit-before.json) y [final](auditorias/etapa-4a/npm-audit-after.json).
- [Árbol completo inicial](auditorias/etapa-4a/npm-ls-before.txt) y [final](auditorias/etapa-4a/npm-ls-after.txt).
- [Registro de versiones, exposición, rutas afectadas e identificadores GHSA](auditorias/etapa-4a/registro-vulnerabilidades.json).
- [Módulos realmente incluidos en el bundle del navegador](auditorias/etapa-4a/modulos-browser.json).
- [Comparación visual antes/después](auditorias/etapa-4a/comparacion-visual.json).

Los 13 paquetes iniciales contienen **77 identificadores GHSA distintos**, algunos con varios rangos afectados; no equivalen a solo 13 avisos. Los JSON conservan todos los IDs, severidades, títulos, URLs y rangos originales. La severidad por paquete de la tabla siguiente es la máxima reportada por npm, no una afirmación de que cada aviso sea explotable en esta aplicación.

`npm ls --all` enumera opcionales para otros sistemas/arquitecturas como no instalados en Linux. Eso no se oculta ni se confunde con dependencias requeridas rotas: ambos árboles terminan con código 0. El lock conserva las variantes Windows necesarias para que npm seleccione las adecuadas en Laragon.

## Vulnerabilidades y versiones

| Paquete | Versión inicial | Versión final | Severidad inicial | Exposición en este proyecto / resultado |
| --- | --- | --- | --- | --- |
| axios | 1.11.0 | **1.20.0** | Alta | Incluido en el navegador; avisos de distintos adaptadores. Actualizado, cero avisos reportados. |
| vite | 6.3.5 | **6.4.5** | Alta | Build/servidor de desarrollo, incluido tratamiento de rutas Windows y HMR. Actualizado dentro de Vite 6. |
| shell-quote | 1.8.3 | **1.12.0** | Crítica | `concurrently → shell-quote`; herramientas de desarrollo. Corregido dentro del rango admitido. |
| tar | 7.4.3 | **Retirado** | Crítica | `@tailwindcss/vite → @tailwindcss/oxide → tar`, herramienta sin uso. La cadena se elimina. |
| follow-redirects | 1.15.9 | **1.16.2** | Moderada | Adaptador HTTP de Axios para Node; no forma parte del bundle del navegador. |
| form-data | 4.0.4 | **4.0.6** | Alta | Multipart de Axios para Node; no se incluye en el bundle del navegador. |
| immutable | 5.1.3 | **5.1.9** | Alta | `sass → immutable`; compilación. |
| lodash | 4.17.21 | **4.18.1** | Alta | `concurrently → lodash`; herramienta de desarrollo. |
| nanoid | 3.3.11 | **3.3.20** | Alta | `vite → postcss → nanoid`; compilación. |
| picomatch | 2.3.1 / 4.0.3 | **2.3.2 / 4.0.7** | Alta | Watchers/globs de Laravel/Vite y tinyglobby; se corrigen ambas familias. |
| postcss | 8.5.6 | **8.5.32** | Alta | `vite → postcss`; procesamiento CSS/mapas de fuentes. |
| rollup | 4.46.2 | **4.59.0** | Alta | Bundler de Vite; primera versión corregida para el aviso inicial, dentro del rango aceptado. |
| source-map-js | 1.2.1 | **1.2.2** | Alta | Sass/PostCSS y la cadena Tailwind anterior; herramientas de compilación. |

Todos esos paquetes quedan sin avisos en la auditoría completa final; `tar` deja de existir en el árbol. No se encontraron paquetes afectados adicionales fuera de los trece iniciales en esta consulta. Las bases de avisos pueden cambiar, por lo que deben repetirse los comandos en futuras revisiones.

Ejemplos de IDs conservados: [shell-quote / GHSA-w7jw-789q-3m8p](https://github.com/advisories/GHSA-w7jw-789q-3m8p), [tar / GHSA-23hp-3jrh-7fpw](https://github.com/advisories/GHSA-23hp-3jrh-7fpw), [Rollup / GHSA-mw96-cpmx-2vgc](https://github.com/advisories/GHSA-mw96-cpmx-2vgc) y [Axios / GHSA-xx6v-rp6x-q39c](https://github.com/advisories/GHSA-xx6v-rp6x-q39c). El inventario JSON enlazado contiene el conjunto completo.

## Selección de cambios y compatibilidad

### Grupo 1: críticos y dependencias sin uso

Se retiran `@tailwindcss/vite`, `tailwindcss` y `resources/css/app.css`. Se revisaron imports, entradas Vite, referencias Blade, clases y CSS propios:

- Vite importa únicamente `laravel-vite-plugin`; sus entradas son `resources/sass/app.scss` y `resources/js/app.js`.
- Ninguna vista/import referencia la hoja `resources/css/app.css`; solo contenía la configuración inicial de Tailwind.
- Las clases `grid` de citas/PDF tienen estilos propios. `bg-white`, `shadow-sm` y `py-4` del layout clásico pertenecen al Bootstrap compilado. No hay generación Tailwind activa que se retire de la interfaz.
- Se conserva `laravel-vite-plugin` 1.3.0, necesario para manifest, `@vite` y desarrollo/HMR.

El grupo actualiza además `shell-quote` y Lodash dentro de los rangos de `concurrently` 9.2.0, utilizado por `composer dev`. La auditoría intermedia baja a **10 paquetes afectados, cero críticos, 9 altos y 1 moderado**. El build intermedio produce **CSS y JS idénticos byte por byte** a los iniciales, confirmando que retirar Tailwind no elimina estilos o código publicados.

### Grupo 2: frontend y transitivas afectadas

Se elevan los mínimos directos a `axios: ^1.20.0` y `vite: ^6.4.5`, conservando majors 1 y 6. Las transitivas de la tabla se resuelven con npm dentro de sus rangos compatibles; no se usa `--force`, `--legacy-peer-deps` ni `overrides`.

Rollup 4.59.0 queda seleccionado en el lock como versión corregida compatible con `vite → rollup ^4.34.9`. Mantiene Node >=18 y evita incorporar herramientas nativas opcionales de versiones posteriores que elevan requisitos de parches Node. No se añade Rollup como dependencia directa ni se fuerza fuera del rango de Vite. `npm ci` reproduce esa resolución.

El único salto mayor transitivo relevante es `proxy-from-env` **1.1.0 → 2.1.0**, requerido por Axios 1.20.0 junto con `https-proxy-agent` 5.0.1. Se evaluó individualmente: corresponde al adaptador Node de Axios, no hay uso de Axios del lado servidor en esta aplicación PHP y ninguno de esos módulos llega al bundle del navegador. No se incorpora una llamada de red o proveedor nuevo en la aplicación. La prueba de navegador verifica el contrato de Axios utilizado por el frontend; no se afirma haber probado servidores proxy de Node que el proyecto no utiliza.

Se mantienen sin actualización de versión instalada Bootstrap **5.3.7**, Sass **1.89.2**, Popper **2.11.8**, `laravel-vite-plugin` **1.3.0** y `concurrently` **9.2.0**. Bootstrap/Sass/Popper y Axios se conservan porque integran el pipeline o su contrato existente; `concurrently` sostiene `composer dev`. No se aprovecha esta etapa para una modernización general.

El lock se genera mediante npm y se comprueba con instalación limpia. No se editan manualmente integridades o dependencias para ocultar avisos. Conservar el lock y usar `npm ci`; un `npm update` posterior es una nueva selección de versiones que requiere revisión y pruebas.

## JavaScript y CSS publicados

`resources/js/app.js` sigue importando `bootstrap.js`; este mantiene `import 'bootstrap'`, `window.axios` y `X-Requested-With: XMLHttpRequest`. No se cambia ese código, las cabeceras de autenticación/CSRF o los scripts de menú y formularios.

Un build de verificación registró los módulos de Rollup sin cambiar la configuración del proyecto. El inventario confirma Axios en el navegador y excluye follow-redirects, form-data, https-proxy-agent, proxy-from-env y las herramientas npm afectadas. Eso distingue los avisos del adaptador HTTP de Node de los que pueden afectar al código browser; se actualizaron ambos, sin ignorar avisos por la clasificación `devDependencies`.

| Artefacto de producción | Antes | Después |
| --- | --- | --- |
| CSS de Sass/Bootstrap | 227404 bytes | 227404 bytes, **idéntico** |
| JavaScript | 117478 bytes, 38.69 kB gzip | 133422 bytes, 43.87 kB gzip |
| Axios observado en el bundle ejecutado | 1.11.0 | 1.20.0 |

SHA-256 común del CSS: `52eb5a312f5691eebeae2813013fd360fce8ff16e230a54630b7b400e139248b`. El incremento de JS procede de las dependencias actualizadas; no se añaden funciones de marketplace, red o proveedores a los fuentes de la aplicación.

Varias pantallas actuales cargan CSS público/JS propio y no el layout clásico `layouts/app.blade.php`. Por eso la verificación incluye también un fixture estático aislado que carga **los assets reales del manifest Vite**, ejecuta Axios y prueba los controles Bootstrap. No se añadió una ruta o pantalla de diagnóstico al producto ni se insertó el bundle en layouts que antes no lo cargaban.

## Pruebas ejecutadas

### PHP y contratos CSRF

Las **143 pruebas anteriores** continúan pasando sin modificarlas. Se agregan dos casos en `FrontendCsrfHeadersTest`, con CSRF realmente activo y SQLite en memoria:

1. Leer la cookie cifrada `XSRF-TOKEN` emitida por Laravel, usarla como `X-XSRF-TOKEN` en una petición AJAX y comprobar creación autorizada; rechazar la cabecera alterada sin más escrituras.
2. Validar `X-CSRF-TOKEN` y comprobar que una cabecera válida no evita las restricciones de rol, identidad ni validación de datos manipulados.

El resultado final es **145 pruebas y 1591 aserciones**, PHP 8.2.35 / PHPUnit 11.5.24. La suite cubre registro/login, roles, perfiles, servicios, catálogo/filtros, fichas públicas, solicitudes y todas sus decisiones, propiedad, privacidad y funciones antiguas. Se ejecutó sobre los archivos finales en una copia sin entorno privado o base persistente; no se alteraron fixtures, reglas o aserciones anteriores para acomodar cambios.

### Node y herramientas

- Node **24.19.0 / npm 11.9.0**: `npm ci`, auditorías completa/JSON/omit-dev, `npm ls --all` y build correctos, todos con código 0.
- Node **22.23.4 / npm 10.9.9** en imagen oficial: build correcto con el mismo lock y módulos instalados. No se presenta como una instalación limpia adicional bajo Node 22; la instalación limpia se verificó con Node 24.
- Vite dev y Laravel plugin: inicio en localhost, archivo hot correcto y cliente HMR con HTTP 200. La prueba se hizo en copia sin `.env` y se cerró el servidor.
- `concurrently`: dos procesos Node con argumentos entre comillas completan correctamente antes/después, comprobando el uso de shell-quote/Lodash sin arrancar procesos Laravel o workers contra datos reales.

Los requisitos efectivos de los paquetes conservan compatibilidad con las líneas Node 22/24 documentadas. Usar versiones mantenidas de esas líneas. No se cambia PHP, Composer, Laravel o su lock.

### Navegador y comparación visual

Chromium revisó 14 vistas a **1440×1000 y 390×844**, antes y después: **56 comprobaciones de renderizado y 28 comparaciones**. Se incluyeron login/registro, Cliente/Prestador, perfil, listado/creación de servicios, catálogo filtrado, detalle, ficha pública, solicitudes/formulario/panel recibido, cita heredada y el fixture Bootstrap del bundle.

No hubo errores JavaScript ni desbordamiento horizontal. Se comprobaron menú móvil, acceso profesional antiguo, tokens de formularios, collapse/dropdown de Bootstrap y selección de horarios mediante el `fetch` heredado.

El Axios compilado conserva envío de JSON, `X-Requested-With`, cookie de sesión y `X-XSRF-TOKEN` decodificado en el mismo origen. Una prueba con otro origen local, interceptada y sin tráfico externo, confirma que no envía esa cabecera CSRF. Las pruebas PHP complementan ese ensayo cliente con la aceptación/rechazo real de las cabeceras por Laravel.

Se estabilizaron fechas, fixtures y animaciones para comparar. **27 pares son idénticos píxel a píxel**. En el detalle de servicio de escritorio hubo 14 píxeles de borde con diferencia máxima de **1/255 por canal**, sin cambio de HTML visible o geometría; el CSS completo es idéntico. El comparador admite únicamente ese ruido mínimo de rasterización (máximo 100 píxeles con delta <=1), no cambios de diseño. Los valores observados están en el JSON de comparación, sin ocultar la diferencia.

Son vistas renderizadas por Laravel con fixtures en memoria y pruebas browser del bundle real, con red externa bloqueada. No se usó una base persistente. La ejecución nativa Windows/Laragon queda para revisión local; no se ha ejecutado en esta máquina Linux.

## Advertencias Sass y riesgos pendientes

La compilación sigue mostrando deprecaciones de Sass/Bootstrap: `@import`, funciones globales y funciones de color. No son las vulnerabilidades npm de la tabla. No se silenciaron con configuración nueva ni se reescribió Bootstrap. Resolverlas completamente requiere un cambio posterior de estilos/toolchain, fuera del alcance acotado de seguridad de esta etapa.

La auditoría npm completa está limpia **en la fecha de consulta**; se mantienen sus reportes y el lock para revisión. No se extiende ese resultado a vulnerabilidades futuras o a Composer, cuyas dependencias no se actualizaron aquí. Repetir audit al revisar/desplegar y tratar nuevos avisos con cambios controlados.

La validación de Windows y los detalles de sus binarios opcionales deben comprobarse con `npm ci` local. Se incluyeron sus entradas correctas en el lock y no se cambió el requisito Node a una versión mayor. No quedan avisos npm conocidos pendientes que requieran forzar un major o un override en esta entrega.

## Laragon / Windows con PowerShell

### Copia limpia de revisión

Abrir PowerShell desde Laragon o seleccionar su PHP 8.2+ en PATH. Usar Node 22 o 24 LTS mantenido y Composer 2. Clonar la rama en una carpeta nueva:

```powershell
Set-Location C:\laragon\www
git clone --branch codex/etapa-4a-seguridad-dependencias --single-branch https://github.com/Fernando261/tekora-project.git tekora-etapa4a
Set-Location .\tekora-etapa4a
where.exe php
php --version
php --ini
node --version
npm.cmd --version
composer install
npm.cmd ci
npm.cmd audit
npm.cmd audit --json
npm.cmd audit --omit=dev
npm.cmd ls --all
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
```

Resultado esperado: audit completo y omit-dev en cero, árbol/compilación correctos y 145 pruebas/1591 aserciones. `npm.cmd` evita depender de la política de ejecución de scripts PowerShell. Las pruebas PHP fuerzan SQLite en memoria; no necesitan `.env`, crear una base, migrar, sembrar o generar una clave.

Para reproducir el diagnóstico inicial, usar **otra carpeta**, clonar `main` y seleccionar el commit base `84b728db4a4984d21ed16d8f578f0b3d5da6679c`; repetir `npm ci`, audit y build con su lock. No sustituir el lock nuevo por el viejo en la instalación que se esté usando. Los JSON versionados permiten revisar el diagnóstico aunque después cambie la base de avisos npm.

### Actualizar una copia de trabajo de 3B

Revisar `git status` y conservar cambios locales. Probar primero en una copia aislada del entorno de 3B, con sus datos de prueba y las 29 migraciones ya aplicadas. Mantener entorno, base y `APP_KEY` existentes; revisar el PR antes de desplegar.

1. Seleccionar la rama revisada o la versión que posteriormente se integre en main. No mezclar cambios locales del lock ni instalar sobre un proceso Vite que esté ejecutándose.
2. Ejecutar `npm.cmd ci`, las auditorías completas y el build. No usar `npm update`, audit fix, `--force`, `--legacy-peer-deps` o borrar el lock para lograr una instalación.
3. Ejecutar PHPUnit directamente con el comando anterior; su bootstrap aísla la base en memoria.
4. Revisar las pantallas en la copia local configurada de 3B. No crear/modificar `.env`, clave o base para aplicar 4A: **esta etapa no requiere migraciones, seeds, reset o rollback**.
5. Al desplegar una versión aprobada, publicar el build generado junto con su manifest de manera consistente; no mezclar el JS antiguo con el manifest nuevo. Conservar un respaldo de código/build anterior para revertir código si hace falta; no revertir ni restaurar datos por un cambio npm.

### Recorrido manual y bundle

- Probar registro/login y acceso profesional heredado en la copia aislada, conservando los guards y el destino solicitado.
- Cambiar Cliente/Prestador; consultar/editar perfil y revisar menús en escritorio/móvil.
- Crear/publicar un servicio y buscarlo por categoría/modalidad; consultar detalle y ficha pública.
- Enviar una solicitud con otra cuenta; aceptar, rechazar/cancelar en casos separados y finalizar una aceptada. Probar rechazo a lectura/decisiones ajenas.
- Abrir el formulario de citas MindBloom y comprobar carga/selección de horarios sin modificar su integración.
- Revisar en DevTools que no haya errores JavaScript o recursos Vite ausentes.

Para inspeccionar el bundle sin añadir una ruta de diagnóstico, en DevTools de la **copia local** puede cargarse explícitamente su entrada publicada; algunas pantallas usan solo scripts propios:

```javascript
const manifest = await fetch('/build/manifest.json').then(response => response.json());
await import('/build/' + manifest['resources/js/app.js'].file);
console.log(window.axios.VERSION); // 1.20.0
console.log(window.axios.defaults.headers.common['X-Requested-With']); // XMLHttpRequest
await window.axios.get('/up');
```

Inspeccionar la petición al endpoint de salud existente en Network. Con cookie CSRF de Laravel emitida por la sesión de prueba, Axios debe incluir su cabecera del mismo origen. No sobrescribir cookies con tokens ficticios en una instalación real. Recargar la página revierte esta carga manual de módulos; no modifica fuentes, rutas o registros. El ensayo automatizado completo se hizo con cookies/endpoint interceptados exclusivamente de fixtures.

## Archivos modificados

- `package.json`: retira los dos paquetes Tailwind sin uso y eleva mínimos Axios/Vite.
- `package-lock.json`: resolución compatible, integridades y variantes de plataforma generadas por npm.
- `resources/css/app.css`: elimina la hoja de scaffolding Tailwind no importada.
- `tests/Feature/FrontendCsrfHeadersTest.php`: dos regresiones del contrato CSRF de AJAX.
- `README.md` y este documento: resultado vigente y validación Windows.
- `docs/auditorias/etapa-4a/`: siete evidencias de auditoría, árbol, módulos, avisos y comparación visual enlazadas arriba.

No se modifican código de negocio, migraciones, guards, rutas, vistas, JavaScript de aplicación, Sass/Bootstrap fuente, `.env` o credenciales. Sus hashes se compararon con la base de partida; la única hoja retirada es el scaffolding no utilizado descrito.
