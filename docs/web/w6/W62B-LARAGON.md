# W6.2b · Revisión aislada en Windows/Laragon

Este procedimiento está preparado; no se ejecutó Windows desde el entorno cloud. W6.2b no añade migraciones ni autoriza retirar datos. Mantener el PR en borrador, sin fusionar ni desplegar.

Usar un directorio nuevo que no contenga otra instalación. No copiar `.env`, bases, usuarios, tokens o medios reales. No regenerar claves existentes ni ejecutar `migrate:fresh`.

```powershell
git clone --branch codex/w62b-tekora-only --single-branch https://github.com/Fernando261/tekora-project.git tekora-w62b-review
Set-Location .\tekora-w62b-review
git ls-remote origin refs/heads/main
git status --short
php --version
php --ini
php -m
composer install
composer validate --strict
composer check-platform-reqs
npm.cmd ci
npm.cmd run build
php .\vendor\bin\phpunit --do-not-cache-result
composer audit
composer audit --no-dev
npm.cmd audit
```

La base de implementación debe ser `00798bfab31f6608760ec252ac20e15573f8e5ce`. Comprobar también el historial del PR en GitHub: un clon con `--single-branch` no crea necesariamente `origin/main`. La suite requiere PDO SQLite, SQLite3, DOM/XML y las demás extensiones del README. Su bootstrap usa una clave efímera y SQLite `:memory:`; no necesita una base persistente ni Google/SMTP. La primera instalación Composer puede crear `.env` desde el ejemplo solo dentro de este clon nuevo; no importar valores reales.

Resultado cloud de referencia: **669 pruebas / 9.026 aserciones**, PHP 8.4.27; el requisito Composer permanece PHP `^8.2` y su plataforma `8.2.28`. Registrar el resultado real local, versión PHP, fallos y omisiones; no atribuir a Windows el resultado cloud.

Para revisar la UI sin backend de escritura, usar los exporters y routers estáticos de [W62B](W62B.md). Comprobar login/registro central, los paneles cliente/prestador, perfil/foto/nombre, administración según permisos, menú móvil/teclado y logout POST. Las 32 operaciones anteriores deben responder 404; no deben crear registros ni redirigir a reservas o perfiles nativos. El callback Google también queda retirado. No introducir credenciales en el preview ni ejecutar flujos externos.

El contraste de avisos Composer en cloud tuvo el bloqueo de API explicado en W62B. En una red donde esté permitido, registrar los resultados completos de ambos `composer audit` sin modificar el lock para silenciar avisos.
