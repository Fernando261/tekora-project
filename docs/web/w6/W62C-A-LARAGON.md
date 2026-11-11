# W6.2c-a · Validación Windows/Laragon aislada

Procedimiento preparado, no ejecutado desde Linux. PR exclusivamente en borrador; no fusionar ni desplegar. No necesita una instalación en servicio, credenciales, base persistente ni medios reales.

Crear una carpeta nueva. No copiar `.env`, SQLite, uploads o Storage de otra instalación. Usar PHP 8.2 compatible y sus extensiones PDO SQLite/SQLite3, DOM/XML, mbstring, fileinfo, OpenSSL, etc., según README.

```powershell
git clone --branch codex/w62c-a-retirement-preflight --single-branch https://github.com/Fernando261/tekora-project.git tekora-w62ca-review
Set-Location .\tekora-w62ca-review
git status --short
git ls-remote origin refs/heads/main
php --version
php --ini
php -m
composer install --no-scripts
# No ejecutar scripts de creación de proyecto ni key:generate.
# El clon debe continuar sin .env; bootstrap.php usa una clave efímera.
if (Test-Path .\.env) { throw 'Usar un clon nuevo sin .env; no borrar ni importar un entorno existente.' }
composer validate --strict
composer check-platform-reqs
npm.cmd ci
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result tests/Feature/RetirementPreflightTest.php
php .\vendor\bin\phpunit --do-not-cache-result tests/Feature/ProfilePhotoIntegrityTest.php
php .\vendor\bin\phpunit --do-not-cache-result --group legacy-decoupling-w62a
php .\vendor\bin\phpunit --do-not-cache-result tests/Feature/RetiredProductTest.php
php .\vendor\bin\phpunit --do-not-cache-result
composer audit
composer audit --no-dev
npm.cmd audit
php .\vendor\bin\pint --test tests/Feature/RetirementPreflightTest.php
git diff --check
```

Comprobar que el historial del PR parte de `ce7cf62670a1c559247fa6993bcd26e73a334c80`; si main cambió antes de iniciar trabajo, detenerse y revisar la nueva base. El bootstrap falla antes de migrar si pierde SQLite `:memory:`. Los únicos DROP del ensayo están en un método privado del test; no extraerlo a scripts ni ejecutar SQL manualmente. No usar migrate:fresh ni un archivo SQLite para esta prueba.

Comparar con la [validación cloud](evidence/W62C-A-validation.json), registrando versión local y resultados reales, no atribuyendo a Windows los resultados Linux. La instalación con `--no-scripts` evita crear `.env`; si package discovery requiriera regeneración, usar únicamente el entorno efímero del bootstrap de PHPUnit, sin introducir secretos ni conexiones reales. El build precede a la suite porque los previews nativos exigen un manifiesto real.

Revisar que las 37 migraciones y 21 operaciones API permanecen intactas; los tests históricos W5.1/W62A/W62B mantienen sus garantías. Cada test reconstruye memoria: comprobar nueve tablas ausentes dentro del caso no implica que se haya cambiado una instalación ni que desaparezcan de una siguiente ejecución.

Una red que bloquee Packagist debe registrar el error de auditoría. No cambiar locks o reglas de seguridad para silenciarlo. MySQL queda como gate futuro sobre una instalación sintética aislada del motor/versión objetivo, sin copiar secretos ni datos privados; estas pruebas SQLite no certifican ese motor. La autorización de lectura, backup y corte reales se obtiene por separado conforme al [plan](W62C-A-PREFLIGHT.md).
