# W6.2e — Windows 11 / Docker Desktop / Laragon

**NOT RUN.** No se afirma soporte nativo Windows ni Laragon para el harness físico. Usa APIs POSIX de identidad/permisos/O_NOFOLLOW y Python spawn; no deben relajarse para conseguir PASS en Windows.

La vía propuesta, pendiente de ejecutar, es un entorno Linux aislado en WSL2 o un contenedor Linux de Docker Desktop, con PHP y extensiones del lock, Node/npm y Python 3.12/sqlite3. Crear una copia nueva de fuentes sin `.env`, DB, medios ni Storage existentes, como indica la [guía del laboratorio](W62E-PERSISTENT-SQLITE-LAB.md). No montar volúmenes de una instalación, carpetas de fotografías o perfiles de credenciales. No usar una instalación Laragon existente. Dentro del Linux propio, crear solamente un `.env.testing` vacío nuevo y ejecutar los comandos de la guía.

El archivo SQLite del ensayo se crea exclusivamente bajo mkdtemp dentro de ese Linux. No pasar rutas Windows ni carpetas de usuario a los scripts. Registrar versiones efectivas de Python/sqlite3 y PHP/PDO SQLite por separado, filesystem/montajes y cada exit code. SQLite sobre bind mount Windows, ext4/WSL o overlay Docker puede presentar diferencias de locking y sync: repetir escenarios y documentar el filesystem sin extrapolar desde el host cloud.

Conservar FAIL/BLOCKED/NOT RUN. Fallos de permisos o identidad deben detener el escenario afectado; no desactivar guards, FK o triggers. Un resultado de proceso no certifica apagón, fallo del SO/hardware, fsync ni recuperación operativa. No fusionar ni desplegar; la decisión final corresponde al propietario de TEKORA.
