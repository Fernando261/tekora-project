> Registro anterior de la Etapa 9B (`07cb6df`). Para la rama actual con seguridad incorporada, consultar [la integración de PR #16](INTEGRACION-SEGURIDAD-PR16.md) y [la guía Laragon actualizada](LARAGON-9B-SEGURIDAD.md).

# Avisos de Composer observados — 9B

Consulta real: `composer audit --locked --format=json`, 4 de noviembre de 2026. Resultado: **67 avisos en 18 paquetes**. Todos estos paquetes conservan exactamente la versión de `origin/main`; el único paquete añadido al lockfile es Sanctum 4.3.3, sin avisos en esta consulta. No se afirma que el backend esté libre de vulnerabilidades. Las actualizaciones de dependencias heredadas requieren validación separada. Composer 2.5.5 no incluyó severidad en esta salida.

| Paquete | Versión heredada | Avisos |
| --- | --- | --- |
| `dompdf/dompdf` | v3.1.4 | 6 |
| `firebase/php-jwt` | v6.11.1 | 1 |
| `guzzlehttp/guzzle` | 7.10.0 | 9 |
| `guzzlehttp/psr7` | 2.8.0 | 4 |
| `laravel/framework` | v12.19.3 | 5 |
| `league/commonmark` | 2.7.0 | 14 |
| `league/flysystem` | 3.29.1 | 1 |
| `phpseclib/phpseclib` | 3.0.49 | 5 |
| `psy/psysh` | v0.12.8 | 1 |
| `symfony/http-foundation` | v7.3.0 | 6 |
| `symfony/http-kernel` | v7.3.0 | 1 |
| `symfony/mailer` | v7.3.0 | 1 |
| `symfony/mime` | v7.3.0 | 2 |
| `symfony/polyfill-intl-idn` | v1.33.0 | 1 |
| `symfony/process` | v7.3.0 | 1 |
| `symfony/routing` | v7.3.0 | 3 |
| `phpunit/phpunit` | 11.5.24 | 1 |
| `symfony/yaml` | v7.3.0 | 5 |

## Referencias devueltas por la auditoría

### dompdf/dompdf

- [CVE-2026-59943](https://github.com/advisories/GHSA-j8qw-6jw8-r297): Dompdf: Embedded SVG images can leak existence of files and directories within the filesystem
- [CVE-2026-59942](https://github.com/advisories/GHSA-f5gf-2cj8-52g2): Dompdf: Denial of Service (DoS) via Resource Exhaustion using Oversized Image Bitmaps
- [CVE-2026-59941](https://github.com/advisories/GHSA-8hg6-c449-896m): Dompdf: Uncontrolled resource consumption based on declared BMP dimensions
- [CVE-2026-56722](https://github.com/advisories/GHSA-cx96-42px-69fm): Dompdf: Local file read due to improper file path validation in SVG images encoded as data-URI
- [CVE-2026-55555](https://github.com/advisories/GHSA-7x2p-4jvh-6384): Dompdf: File existence oracle via font-face stylesheet declaration
- [CVE-2026-55554](https://github.com/advisories/GHSA-wvh6-f5jh-8gw4): Dompdf: Chroot Validation Bypass

### firebase/php-jwt

- [CVE-2025-45769](https://github.com/advisories/GHSA-2x45-7fc3-mxwq): php-jwt contains weak encryption

### guzzlehttp/guzzle

- [CVE-2026-69246](https://github.com/advisories/GHSA-v5mv-p594-2x33): Guzzle: Noncanonical host can bypass host-based checks
- [CVE-2026-69245](https://github.com/advisories/GHSA-f7vp-7xgx-4w4r): Guzzle: Noncanonical cookie domain keeps subdomain scope
- [CVE-2026-67354](https://github.com/advisories/GHSA-h95v-h523-3mw8): Guzzle: URI fragments disclosed in redirect Referer headers
- [CVE-2026-67355](https://github.com/advisories/GHSA-wm3w-8rrp-j577): Guzzle: Host-only cookie scope is not preserved
- [CVE-2026-67353](https://github.com/advisories/GHSA-f283-ghqc-fg79): Guzzle: Unbounded response cookies risk denial of service
- [CVE-2026-59883](https://github.com/advisories/GHSA-g446-98w2-8p5w): Guzzle: Cookie Disclosure and Injection via IP-Address Domains
- [CVE-2026-67339](https://github.com/advisories/GHSA-94pj-82f3-465w): Guzzle: Proxy-Authorization headers can be sent to origin servers
- [CVE-2026-55767](https://github.com/guzzle/guzzle/security/advisories/GHSA-cwxw-98qj-8qjx): Dot-only cookie domains match all hosts
- [CVE-2026-55568](https://github.com/guzzle/guzzle/security/advisories/GHSA-wpwq-4j6v-78m3): Silent HTTPS proxy downgrade to cleartext

### guzzlehttp/psr7

- [CVE-2026-59882](https://github.com/advisories/GHSA-c2w2-prh8-qm98): guzzlehttp/psr7: Host Confusion via Weak URI Host Validation
- [CVE-2026-55766](https://github.com/guzzle/psr7/security/advisories/GHSA-vm85-hxw5-5432): CRLF injection in HTTP start-line serialization
- [CVE-2026-49214](https://github.com/guzzle/psr7/security/advisories/GHSA-hq7v-mx3g-29hw): CRLF injection via URI host component
- [CVE-2026-48998](https://github.com/guzzle/psr7/security/advisories/GHSA-34xg-wgjx-8xph): Host confusion via authority reinterpretation

### laravel/framework

- [CVE-2026-114509](https://github.com/advisories/GHSA-f9x3-7vq9-cph6): Laravel TokenGuard authentication bypass through non-string API credentials
- [CVE-2026-102279](https://github.com/advisories/GHSA-jh5r-qr3c-85q8): Laravel: XSS in Debug Page Information
- [PKSA-m5cs-t1y6-qpcs](https://github.com/advisories/GHSA-crmm-hgp2-wgrp): Laravel Framework: Temporary Signed URL Path Confusion
- [PKSA-3r5d-mb8f-1qw9](https://github.com/advisories/GHSA-5vg9-5847-vvmq): Laravel Framework: CRLF injection in default email rule
- [CVE-2026-48019](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq): Laravel CRLF injection in default email rule

### league/commonmark

- [PKSA-m2dq-1fhr-29b1](https://github.com/advisories/GHSA-97jj-33gv-5xf9): league/commonmark: DisallowedRawHtml bypassed when a disallowed tag name ends the raw-HTML literal
- [PKSA-m4t9-vsgq-8khn](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8): league/commonmark: Quadratic-time denial of service in the GitHub Flavored Markdown Table extension block-start scan
- [PKSA-zyf5-hrxv-hrd7](https://github.com/advisories/GHSA-8rr7-cvq3-gmfh): league/commonmark: Denial of service via distinctly-named attributes in the Attributes extension
- [PKSA-nv44-1b4d-6gjg](https://github.com/advisories/GHSA-jjv6-8j6v-6j52): league/commonmark: Denial of service in the SmartPunct and Attributes extensions
- [PKSA-kr3s-894t-g5w2](https://github.com/advisories/GHSA-f8fg-pg57-v4j8): league/commonmark XSS: `on*` event-handler filter in `AttributesExtension` bypassed with a U+000C form feed
- [PKSA-9q1p-3s19-bp1q](https://github.com/advisories/GHSA-j8pm-gj4c-rq4x): league/commonmark: Denial of service via crafted code fences, reference links, and emphasis delimiters
- [PKSA-5mzr-szzf-z6cn](https://github.com/advisories/GHSA-mj63-m3rc-8ppr): league/commonmark: Denial of service via deeply nested XML output
- [PKSA-cqd6-fg4n-nxpf](https://github.com/advisories/GHSA-mh25-x5hq-wrqp): league/commonmark: Denial of service via colliding heading slugs
- [PKSA-1q6p-sqkj-8mmj](https://github.com/advisories/GHSA-jfm3-95jq-q3rf): league/commonmark:  Denial of service via duplicate footnote definitions
- [PKSA-mc58-w91n-f5gv](https://github.com/advisories/GHSA-g2gp-3wwq-f4ph): league/commonmark: Denial of service via adjacent inline attribute blocks
- [CVE-2026-71488](https://github.com/advisories/GHSA-2q4p-g7hv-5rgv): league/commonmark: Quadratic-time denial of service when parsing crafted Markdown
- [CVE-2026-71478](https://github.com/advisories/GHSA-29pj-957v-52mc): league/commonmark: AttributesExtension href/src unsafe-link filter bypass via embedded control bytes
- [CVE-2026-33347](https://github.com/advisories/GHSA-hh8v-hgvp-g3f5): league/commonmark has an embed extension allowed_domains bypass
- [CVE-2026-30838](https://github.com/advisories/GHSA-4v6x-c7xx-hw9f): CommonMark has DisallowedRawHtml extension bypass via whitespace in HTML tag names

### league/flysystem

- [CVE-2026-102601](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr): Flysystem: WhitespacePathNormalizer's control-character (CorruptedPathDetected) check is bypassed by malformed UTF-8 in the path, affecting every adapter

### phpseclib/phpseclib

- [CVE-2026-84308](https://github.com/advisories/GHSA-q97c-8qh3-fpc6): phpseclib — non-constant-time X25519 scalar multiplication permits full private-key recovery
- [CVE-2026-55599](https://github.com/advisories/GHSA-m557-wrgg-6rp4): phpseclib: X.509 certificate validation sends attacker-controlled outbound requests (server-side request forgery) via Authority Information Access
- [CVE-2026-44167](https://github.com/advisories/GHSA-3qpq-r242-jqj7): phpseclib has a CVE-2024-27355 mitigation bypass — OID amplification DoS in ASN1::decodeOID()
- [CVE-2026-40194](https://github.com/advisories/GHSA-r854-jrxh-36qx): phpseclib has a variable-time HMAC comparison in SSH2::get_binary_packet() using != instead of hash_equals()
- [CVE-2026-32935](https://github.com/advisories/GHSA-94g3-g5v7-q4jg): phpseclib's AES-CBC unpadding susceptible to padding oracle timing attack

### psy/psysh

- [CVE-2026-25129](https://github.com/advisories/GHSA-4486-gxhx-5mg7): PsySH has Local Privilege Escalation via CWD .psysh.php auto-load

### symfony/http-foundation

- [CVE-2026-118514](https://symfony.com/cve-2026-118514): CVE-2026-118514: HEAD Requests Bypass the methods Option of security.access_control Rules and Firewalls: Incomplete Fix for CVE-2026-45075
- [PKSA-nxjs-s6hn-vb6m](https://github.com/symfony/symfony/security/advisories/GHSA-xg2q-4rrq-657q): The "private_ranges" Trusted Proxies Shortcut Trusts Clients Presenting an IPv4-Mapped, 6to4, Teredo or NAT64 Address as Reverse Proxies
- [PKSA-b2gg-vk1g-6d5q](https://github.com/symfony/symfony/security/advisories/GHSA-5mx8-mwrp-5693): Generated URLs Can Point to a Host Chosen by the Client
- [CVE-2026-108082](https://symfony.com/cve-2026-108082): CVE-2026-108082: ReDoS in HeaderUtils::split() Reachable Through the Cache-Control Request Header
- [CVE-2026-48736](https://symfony.com/cve-2026-48736): CVE-2026-48736: IpUtils::PRIVATE_SUBNETS Omits IPv6 Transition Forms (6to4, NAT64, Teredo, IPv4-compatible): SSRF Bypass in NoPrivateNetworkHttpClient
- [CVE-2025-64500](https://symfony.com/blog/cve-2025-64500-incorrect-parsing-of-path-info-can-lead-to-limited-authorization-bypass): CVE-2025-64500: Incorrect parsing of PATH_INFO can lead to limited authorization bypass

### symfony/http-kernel

- [PKSA-3gxv-7xhq-mgmz](https://github.com/symfony/symfony/security/advisories/GHSA-wg5q-m2ww-f3c9): Inline Fragments Let Untrusted Forwarded Headers Set the Host, Scheme and Port of the URLs Generated for the Main Request

### symfony/mailer

- [CVE-2026-45068](https://symfony.com/cve-2026-45068): CVE-2026-45068: Argument Injection in SendmailTransport via Dash-Prefixed Recipient Address

### symfony/mime

- [CVE-2026-45070](https://symfony.com/cve-2026-45070): CVE-2026-45070: Email Header Injection via Non-Token Characters in Mime Parameter Names
- [CVE-2026-45067](https://symfony.com/cve-2026-45067): CVE-2026-45067: Email Header / SMTP Command Injection via CRLF in Symfony\Component\Mime\Address

### symfony/polyfill-intl-idn

- [CVE-2026-46644](https://symfony.com/cve-2026-46644): CVE-2026-46644: symfony/polyfill-intl-idn accepts xn-- labels whose Punycode payload decodes to ASCII-only: insecure equivalence

### symfony/process

- [CVE-2026-24739](https://github.com/advisories/GHSA-r39x-jcww-82v6): Symfony's incorrect argument escaping under MSYS2/Git Bash can lead to destructive file operations on Windows

### symfony/routing

- [CVE-2026-118516](https://symfony.com/cve-2026-118516): CVE-2026-118516: Host Placeholders Can Match the Start of the Path in Compiled URL Matchers, Bypassing Path-Based Access Control
- [CVE-2026-48784](https://symfony.com/cve-2026-48784): CVE-2026-48784: UrlGenerator Dot-Segment Encoding Skips Every Other Chained `../` or `./` → Generated URL Collapses Off-Route Under RFC 3986 Normalization
- [CVE-2026-45065](https://symfony.com/cve-2026-45065): CVE-2026-45065: UrlGenerator Route-Requirement Bypass via Unanchored Regex Alternation → Off-Site //host URL Injection

### phpunit/phpunit

- [CVE-2026-24765](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p): Unsafe Deserialization in PHPT Code Coverage Handling

### symfony/yaml

- [PKSA-nktx-f1n1-b4yd](https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx): Exponential and Quadratic Parse Times in the YAML Parser Let a Single Document Hold a Process
- [CVE-2026-109413](https://symfony.com/cve-2026-109413): CVE-2026-109413: Unbounded Recursion in the YAML Inline Lexer Lets a Single Document Crash the Process
- [CVE-2026-45304](https://symfony.com/cve-2026-45304): CVE-2026-45304: YAML Parser Exponential Memory Allocation via Recursive Collection-Alias Expansion ("Billion Laughs")
- [CVE-2026-45305](https://symfony.com/cve-2026-45305): CVE-2026-45305: YAML Parser ReDoS via Catastrophic Backtracking in Parser::cleanup() Regex
- [CVE-2026-45133](https://symfony.com/cve-2026-45133): CVE-2026-45133: YAML Parser Stack Exhaustion via Unbounded Recursion in Nested Blocks, Sequences, and Mappings
