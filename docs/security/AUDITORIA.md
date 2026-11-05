# Auditoría inicial y final de Composer

Fecha: **4 de noviembre de 2026**. Base: `f3d6c9462f9cdf88d1ada1cb1e6ace138a3856c6`, `origin/main` actualizado. Consultas nuevas con Composer **2.8.11**; los conteos se midieron, no se copiaron de la etapa anterior.

| Ámbito | Inicial | Final | Resueltos |
| --- | --- | --- | --- |
| Completo (producción y desarrollo) | 67 avisos / 18 paquetes | **0 avisos** | 67 |
| Sin desarrollo | 61 avisos / 16 paquetes | **0 avisos** | 61 |

Inicial completo: 1 crítico, 18 altos, 31 medios, 10 bajos y **7 sin severidad informada**. Inicial sin desarrollo: 1 crítico, 16 altos, 31 medios, 7 bajos y 6 sin severidad informada. No se inventan severidades para registros que no las contienen. Ambas auditorías finales devuelven código **0**, sin paquetes abandonados reportados.

**Laravel 12.19.3 → 12.71.0** corrige `CVE-2026-114509` (intervalo vulnerable `<12.68.0`) y los otros cuatro avisos aplicables. El mínimo directo queda en `^12.69`, que también excluye el intervalo vulnerable del aviso adicional corregido en 12.69.0. No se selecciona Laravel 13.

Las versiones nuevas de las tablas son las versiones corregidas **utilizadas en este lock**, no necesariamente el mínimo de todas las ramas mantenidas. Los intervalos vulnerables, identificadores PKSA/GHSA, enlaces, fechas y severidades originales están en los [JSON iniciales](evidence/audit-initial.json), [iniciales sin desarrollo](evidence/audit-initial-no-dev.json), [finales](evidence/audit-final.json) y [finales sin desarrollo](evidence/audit-final-no-dev.json).

## Paquetes afectados y exposición

| Paquete | Anterior | Nueva | Severidades iniciales | Resultado | Exposición en Tekora |
| --- | --- | --- | --- | --- | --- |
| `dompdf/dompdf` | v3.1.4 | v3.1.6 | medium: 4, low: 2 | 6 → 0 avisos | Descarga web de tarjetas de emociones; HTML/recursos procesados por Dompdf. |
| `firebase/php-jwt` | v6.11.1 | v7.2.1 | low: 1 | 1 → 0 avisos | Dependencia de Google API Client y Socialite; no existe un emisor JWT propio de Tekora. La explotación concreta no se ha demostrado ni se descarta. |
| `guzzlehttp/guzzle` | 7.10.0 | 7.16.1 | high: 1, medium: 8 | 9 → 0 avisos | Transporte HTTP de OAuth, renovación y Calendar; respuestas de servicios externos. |
| `guzzlehttp/psr7` | 2.8.0 | 2.13.2 | medium: 4 | 4 → 0 avisos | Mensajes HTTP usados por Guzzle y Google; cabeceras y URI. |
| `laravel/framework` | v12.19.3 | v12.71.0 | critical: 1, low: 1, medium: 1, high: 1, sin informar: 1 | 5 → 0 avisos | Framework de todas las rutas web, autenticación, validación y procesamiento de peticiones. |
| `league/commonmark` | 2.7.0 | 2.10.7 | medium: 5, high: 9 | 14 → 0 avisos | Conversión Markdown disponible a través de Laravel; no se encontró un endpoint propio de Markdown arbitrario. Exposición indirecta. |
| `league/flysystem` | 3.29.1 | 3.36.0 | low: 1 | 1 → 0 avisos | Archivos y disco público empleados por fotos y medios. |
| `phpseclib/phpseclib` | 3.0.49 | 3.0.58 | medium: 2, high: 2, low: 1 | 5 → 0 avisos | Criptografía transitiva de Google API Client; firmas y manejo de claves. |
| `phpunit/phpunit` | 11.5.24 | 11.5.57 | high: 1 | 1 → 0 avisos | Solo desarrollo/pruebas según lock; no debe desplegarse el directorio de pruebas como aplicación pública. |
| `psy/psysh` | v0.12.8 | v0.12.24 | medium: 1 | 1 → 0 avisos | Consola Tinker (dependencia de producción del manifiesto); no hay ruta HTTP de consola. |
| `symfony/http-foundation` | v7.3.0 | v7.4.23 | sin informar: 3, high: 2, medium: 1 | 6 → 0 avisos | Parsing y construcción de peticiones/respuestas del backend. |
| `symfony/http-kernel` | v7.3.0 | v7.4.23 | sin informar: 1 | 1 → 0 avisos | Ciclo HTTP de Laravel. |
| `symfony/mailer` | v7.3.0 | v7.4.22 | medium: 1 | 1 → 0 avisos | Infraestructura de correo; el transporte real depende de la instalación. |
| `symfony/mime` | v7.3.0 | v7.4.22 | medium: 1, high: 1 | 2 → 0 avisos | MIME y cabeceras usados por correo. |
| `symfony/polyfill-intl-idn` | v1.33.0 | v1.43.0 | low: 1 | 1 → 0 avisos | Normalización de nombres de dominio a través de dependencias HTTP/correo. |
| `symfony/process` | v7.3.0 | v7.4.22 | medium: 1 | 1 → 0 avisos | Procesos de herramientas/framework; no se encontró un endpoint propio que ejecute comandos arbitrarios. |
| `symfony/routing` | v7.3.0 | v7.4.23 | sin informar: 1, medium: 2 | 3 → 0 avisos | Resolución y generación de rutas web. |
| `symfony/yaml` | v7.3.0 | v7.4.23 | sin informar: 1, high: 1, low: 3 | 5 → 0 avisos | Solo desarrollo en el grafo bloqueado (Sail); procesamiento YAML de herramientas. |

## Identificadores y resolución por aviso

| Paquete | Aviso / referencia | Severidad | Versión final / resultado |
| --- | --- | --- | --- |
| `dompdf/dompdf` | [CVE-2026-59943](https://github.com/advisories/GHSA-j8qw-6jw8-r297) · `PKSA-cv56-2228-pzr6` | medium | v3.1.6 · resuelto |
| `dompdf/dompdf` | [CVE-2026-59942](https://github.com/advisories/GHSA-f5gf-2cj8-52g2) · `PKSA-6r8f-nxsb-67bq` | medium | v3.1.6 · resuelto |
| `dompdf/dompdf` | [CVE-2026-59941](https://github.com/advisories/GHSA-8hg6-c449-896m) · `PKSA-gh7h-hhy4-byg7` | medium | v3.1.6 · resuelto |
| `dompdf/dompdf` | [CVE-2026-56722](https://github.com/advisories/GHSA-cx96-42px-69fm) · `PKSA-mwt3-h9tv-kx78` | medium | v3.1.6 · resuelto |
| `dompdf/dompdf` | [CVE-2026-55555](https://github.com/advisories/GHSA-7x2p-4jvh-6384) · `PKSA-hp6n-n4kz-21wk` | low | v3.1.6 · resuelto |
| `dompdf/dompdf` | [CVE-2026-55554](https://github.com/advisories/GHSA-wvh6-f5jh-8gw4) · `PKSA-mckv-s5hg-868k` | low | v3.1.6 · resuelto |
| `firebase/php-jwt` | [CVE-2025-45769](https://github.com/advisories/GHSA-2x45-7fc3-mxwq) · `PKSA-y2cr-5h3j-g3ys` | low | v7.2.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-69246](https://github.com/advisories/GHSA-v5mv-p594-2x33) · `PKSA-gcrk-3vtt-1r14` | high | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-69245](https://github.com/advisories/GHSA-f7vp-7xgx-4w4r) · `PKSA-cnw1-2ytm-cgr8` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-67354](https://github.com/advisories/GHSA-h95v-h523-3mw8) · `PKSA-fy2t-3c5f-827y` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-67355](https://github.com/advisories/GHSA-wm3w-8rrp-j577) · `PKSA-qxvb-2bpp-dnk6` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-67353](https://github.com/advisories/GHSA-f283-ghqc-fg79) · `PKSA-bbs6-q5q9-f3t4` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-59883](https://github.com/advisories/GHSA-g446-98w2-8p5w) · `PKSA-bcdd-5xc7-gwfb` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-67339](https://github.com/advisories/GHSA-94pj-82f3-465w) · `PKSA-pwsk-hy21-4gby` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-55767](https://github.com/guzzle/guzzle/security/advisories/GHSA-cwxw-98qj-8qjx) · `PKSA-93qv-9n9h-6k6p` | medium | 7.16.1 · resuelto |
| `guzzlehttp/guzzle` | [CVE-2026-55568](https://github.com/guzzle/guzzle/security/advisories/GHSA-wpwq-4j6v-78m3) · `PKSA-k22t-f949-t9g6` | medium | 7.16.1 · resuelto |
| `guzzlehttp/psr7` | [CVE-2026-59882](https://github.com/advisories/GHSA-c2w2-prh8-qm98) · `PKSA-vznr-tgp9-fd7d` | medium | 2.13.2 · resuelto |
| `guzzlehttp/psr7` | [CVE-2026-55766](https://github.com/guzzle/psr7/security/advisories/GHSA-vm85-hxw5-5432) · `PKSA-7qs6-zvnz-h66r` | medium | 2.13.2 · resuelto |
| `guzzlehttp/psr7` | [CVE-2026-49214](https://github.com/guzzle/psr7/security/advisories/GHSA-hq7v-mx3g-29hw) · `PKSA-gm5x-j3mz-71n9` | medium | 2.13.2 · resuelto |
| `guzzlehttp/psr7` | [CVE-2026-48998](https://github.com/guzzle/psr7/security/advisories/GHSA-34xg-wgjx-8xph) · `PKSA-jj5t-2zs1-dcfm` | medium | 2.13.2 · resuelto |
| `laravel/framework` | [CVE-2026-114509](https://github.com/advisories/GHSA-f9x3-7vq9-cph6) · `PKSA-b41t-wbzk-9q51` | critical | v12.71.0 · resuelto |
| `laravel/framework` | [CVE-2026-102279](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) · `PKSA-d5tc-s1qs-h781` | low | v12.71.0 · resuelto |
| `laravel/framework` | [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) · `PKSA-m5cs-t1y6-qpcs` | medium | v12.71.0 · resuelto |
| `laravel/framework` | [GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq) · `PKSA-3r5d-mb8f-1qw9` | high | v12.71.0 · resuelto |
| `laravel/framework` | [CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq) · `PKSA-mdq4-51ck-6kdq` | sin informar | v12.71.0 · resuelto |
| `league/commonmark` | [GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9) · `PKSA-m2dq-1fhr-29b1` | medium | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8) · `PKSA-m4t9-vsgq-8khn` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-8rr7-cvq3-gmfh](https://github.com/advisories/GHSA-8rr7-cvq3-gmfh) · `PKSA-zyf5-hrxv-hrd7` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-jjv6-8j6v-6j52](https://github.com/advisories/GHSA-jjv6-8j6v-6j52) · `PKSA-nv44-1b4d-6gjg` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-f8fg-pg57-v4j8](https://github.com/advisories/GHSA-f8fg-pg57-v4j8) · `PKSA-kr3s-894t-g5w2` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-j8pm-gj4c-rq4x](https://github.com/advisories/GHSA-j8pm-gj4c-rq4x) · `PKSA-9q1p-3s19-bp1q` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-mj63-m3rc-8ppr](https://github.com/advisories/GHSA-mj63-m3rc-8ppr) · `PKSA-5mzr-szzf-z6cn` | medium | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-mh25-x5hq-wrqp](https://github.com/advisories/GHSA-mh25-x5hq-wrqp) · `PKSA-cqd6-fg4n-nxpf` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-jfm3-95jq-q3rf](https://github.com/advisories/GHSA-jfm3-95jq-q3rf) · `PKSA-1q6p-sqkj-8mmj` | high | 2.10.7 · resuelto |
| `league/commonmark` | [GHSA-g2gp-3wwq-f4ph](https://github.com/advisories/GHSA-g2gp-3wwq-f4ph) · `PKSA-mc58-w91n-f5gv` | high | 2.10.7 · resuelto |
| `league/commonmark` | [CVE-2026-71488](https://github.com/advisories/GHSA-2q4p-g7hv-5rgv) · `PKSA-t21r-vtr5-3mdz` | high | 2.10.7 · resuelto |
| `league/commonmark` | [CVE-2026-71478](https://github.com/advisories/GHSA-29pj-957v-52mc) · `PKSA-scnn-p8mm-jbft` | medium | 2.10.7 · resuelto |
| `league/commonmark` | [CVE-2026-33347](https://github.com/advisories/GHSA-hh8v-hgvp-g3f5) · `PKSA-21fb-n1x5-5nf7` | medium | 2.10.7 · resuelto |
| `league/commonmark` | [CVE-2026-30838](https://github.com/advisories/GHSA-4v6x-c7xx-hw9f) · `PKSA-2cx9-ynrq-qdk3` | medium | 2.10.7 · resuelto |
| `league/flysystem` | [CVE-2026-102601](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr) · `PKSA-w9tt-7782-78jx` | low | 3.36.0 · resuelto |
| `phpseclib/phpseclib` | [CVE-2026-84308](https://github.com/advisories/GHSA-q97c-8qh3-fpc6) · `PKSA-12sz-bcny-pk4m` | medium | 3.0.58 · resuelto |
| `phpseclib/phpseclib` | [CVE-2026-55599](https://github.com/advisories/GHSA-m557-wrgg-6rp4) · `PKSA-432p-hv1d-chf7` | medium | 3.0.58 · resuelto |
| `phpseclib/phpseclib` | [CVE-2026-44167](https://github.com/advisories/GHSA-3qpq-r242-jqj7) · `PKSA-smrh-yx37-92ws` | high | 3.0.58 · resuelto |
| `phpseclib/phpseclib` | [CVE-2026-40194](https://github.com/advisories/GHSA-r854-jrxh-36qx) · `PKSA-zh4j-by9m-7mz8` | low | 3.0.58 · resuelto |
| `phpseclib/phpseclib` | [CVE-2026-32935](https://github.com/advisories/GHSA-94g3-g5v7-q4jg) · `PKSA-km2b-zc3b-mjm3` | high | 3.0.58 · resuelto |
| `phpunit/phpunit` | [CVE-2026-24765](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p) · `PKSA-z3gr-8qht-p93v` | high | 11.5.57 · resuelto |
| `psy/psysh` | [CVE-2026-25129](https://github.com/advisories/GHSA-4486-gxhx-5mg7) · `PKSA-4s4z-t146-6123` | medium | v0.12.24 · resuelto |
| `symfony/http-foundation` | [CVE-2026-118514](https://symfony.com/cve-2026-118514) · `PKSA-24ph-xkrh-y79j` | sin informar | v7.4.23 · resuelto |
| `symfony/http-foundation` | [PKSA-nxjs-s6hn-vb6m](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q) · `PKSA-nxjs-s6hn-vb6m` | sin informar | v7.4.23 · resuelto |
| `symfony/http-foundation` | [PKSA-b2gg-vk1g-6d5q](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693) · `PKSA-b2gg-vk1g-6d5q` | sin informar | v7.4.23 · resuelto |
| `symfony/http-foundation` | [CVE-2026-108082](https://symfony.com/cve-2026-108082) · `PKSA-xmwy-rmx5-m7h4` | high | v7.4.23 · resuelto |
| `symfony/http-foundation` | [CVE-2026-48736](https://symfony.com/cve-2026-48736) · `PKSA-y6py-qpv1-h52p` | medium | v7.4.23 · resuelto |
| `symfony/http-foundation` | [CVE-2025-64500](https://symfony.com/blog/cve-2025-64500-incorrect-parsing-of-path-info-can-lead-to-limited-authorization-bypass) · `PKSA-365x-2zjk-pt47` | high | v7.4.23 · resuelto |
| `symfony/http-kernel` | [PKSA-3gxv-7xhq-mgmz](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9) · `PKSA-3gxv-7xhq-mgmz` | sin informar | v7.4.23 · resuelto |
| `symfony/mailer` | [CVE-2026-45068](https://symfony.com/cve-2026-45068) · `PKSA-28rh-rzzn-djk4` | medium | v7.4.22 · resuelto |
| `symfony/mime` | [CVE-2026-45070](https://symfony.com/cve-2026-45070) · `PKSA-wtxr-p26d-nn42` | medium | v7.4.22 · resuelto |
| `symfony/mime` | [CVE-2026-45067](https://symfony.com/cve-2026-45067) · `PKSA-2n2k-66v2-bwg3` | high | v7.4.22 · resuelto |
| `symfony/polyfill-intl-idn` | [CVE-2026-46644](https://symfony.com/cve-2026-46644) · `PKSA-dwsq-ppd2-mb1x` | low | v1.43.0 · resuelto |
| `symfony/process` | [CVE-2026-24739](https://github.com/advisories/GHSA-r39x-jcww-82v6) · `PKSA-rkkf-636k-qjb3` | medium | v7.4.22 · resuelto |
| `symfony/routing` | [CVE-2026-118516](https://symfony.com/cve-2026-118516) · `PKSA-y8c5-1b7f-r1gj` | sin informar | v7.4.23 · resuelto |
| `symfony/routing` | [CVE-2026-48784](https://symfony.com/cve-2026-48784) · `PKSA-bf7t-jnpz-492k` | medium | v7.4.23 · resuelto |
| `symfony/routing` | [CVE-2026-45065](https://symfony.com/cve-2026-45065) · `PKSA-yc7t-91v9-99xs` | medium | v7.4.23 · resuelto |
| `symfony/yaml` | [PKSA-nktx-f1n1-b4yd](https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx) · `PKSA-nktx-f1n1-b4yd` | sin informar | v7.4.23 · resuelto |
| `symfony/yaml` | [CVE-2026-109413](https://symfony.com/cve-2026-109413) · `PKSA-xz3r-9gp4-4m1z` | high | v7.4.23 · resuelto |
| `symfony/yaml` | [CVE-2026-45304](https://symfony.com/cve-2026-45304) · `PKSA-v5yj-8nmz-sk2q` | low | v7.4.23 · resuelto |
| `symfony/yaml` | [CVE-2026-45305](https://symfony.com/cve-2026-45305) · `PKSA-ft77-7h5f-p3r6` | low | v7.4.23 · resuelto |
| `symfony/yaml` | [CVE-2026-45133](https://symfony.com/cve-2026-45133) · `PKSA-b14r-zh1d-vdrc` | low | v7.4.23 · resuelto |

## Todos los cambios del lockfile

**31 actualizaciones y 2 incorporaciones transitivas; sin eliminaciones.** Las otras versiones permanecen iguales. Google API Client, Google Auth y JWT se actualizaron como grupo separado después de comprobar su compatibilidad.

| Paquete | Antes | Después |
| --- | --- | --- |
| `dompdf/dompdf` | v3.1.4 | v3.1.6 |
| `firebase/php-jwt` | v6.11.1 | v7.2.1 |
| `google/apiclient` | v2.15.0 | v2.20.1 |
| `google/auth` | v1.50.0 | v1.55.3 |
| `guzzlehttp/guzzle` | 7.10.0 | 7.16.1 |
| `guzzlehttp/promises` | 2.3.0 | 2.6.0 |
| `guzzlehttp/psr7` | 2.8.0 | 2.13.2 |
| `laravel/framework` | v12.19.3 | v12.71.0 |
| `league/commonmark` | 2.7.0 | 2.10.7 |
| `league/flysystem` | 3.29.1 | 3.36.0 |
| `nikic/php-parser` | v5.5.0 | v5.9.0 |
| `phpseclib/phpseclib` | 3.0.49 | 3.0.58 |
| `psy/psysh` | v0.12.8 | v0.12.24 |
| `symfony/http-foundation` | v7.3.0 | v7.4.23 |
| `symfony/http-kernel` | v7.3.0 | v7.4.23 |
| `symfony/mailer` | v7.3.0 | v7.4.22 |
| `symfony/mime` | v7.3.0 | v7.4.22 |
| `symfony/polyfill-intl-idn` | v1.33.0 | v1.43.0 |
| `symfony/polyfill-php83` | v1.32.0 | v1.43.0 |
| `symfony/polyfill-php84` | — (nuevo) | v1.43.0 |
| `symfony/polyfill-php85` | — (nuevo) | v1.43.0 |
| `symfony/process` | v7.3.0 | v7.4.22 |
| `symfony/routing` | v7.3.0 | v7.4.23 |
| `myclabs/deep-copy` | 1.13.1 | 1.14.0 |
| `phpunit/php-code-coverage` | 11.0.10 | 11.0.12 |
| `phpunit/php-file-iterator` | 5.1.0 | 5.1.1 |
| `phpunit/phpunit` | 11.5.24 | 11.5.57 |
| `sebastian/comparator` | 6.3.1 | 6.3.3 |
| `sebastian/exporter` | 6.3.0 | 6.3.2 |
| `sebastian/recursion-context` | 6.0.2 | 6.0.3 |
| `sebastian/type` | 5.1.2 | 5.1.3 |
| `symfony/yaml` | v7.3.0 | v7.4.23 |
| `theseer/tokenizer` | 1.2.3 | 1.3.1 |

Los polyfills `symfony/polyfill-php84` y `symfony/polyfill-php85` son requisitos de Laravel 12.71.0 (`^1.34`), no un aumento del requisito de PHP. `config.platform.php=8.2.28` limita la resolución; se comprobaron además requisitos y pruebas con PHP **8.2.28 real**.

No quedan bloqueos de dependencias sin resolver en las auditorías consultadas. El bloqueo inicial de Google y su resolución sin forzar requisitos incompatibles se documentan en [DEPENDENCIAS-BLOQUEADAS.md](DEPENDENCIAS-BLOQUEADAS.md). Los ensayos de Google usan HTTP simulado: no se afirma haber probado OAuth con una cuenta real. Una auditoría limpia no demuestra ausencia de vulnerabilidades desconocidas ni valida la configuración de producción. No se ha aprobado integración o despliegue.
