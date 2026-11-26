# Actualización MySQL V8.1.8 → V8.1.10

Este procedimiento describe una ventana futura de administración. Los scripts requieren MySQL 8.4, socket Unix, UTC, InnoDB, binlog ROW y los usuarios restringidos de V8.1.8. La configuración del origen y los puertos 8082, 8083 y 8443 se coordinan por separado. Consultar [operación y publicación](V818-MYSQL-PRODUCTION-READINESS.md).

## Antes de abrir la ventana

- Disponer del SHA revisado, los archivos privados root-owned y un espacio compatible con la retención de copias. Mantener `APP_KEY`, el almacenamiento compartido y la cuenta DEFINER bloqueada.
- Inventariar release activo, workers, scheduler, cron, conexiones y sus responsables. Registrar qué supervisores deben detenerse y cómo restaurarlos. Probar en destino aislado la recuperación de base, archivos privados, objetos y watermark.
- Ejecutar `deploy/vps/preflight.sh` siguiendo sus argumentos en el runbook V8.1.8. Mantener cerrado el acceso público al candidato.
- Detener todas las escrituras, incluidos PHP-FPM, workers, scheduler y administración paralela de la base. Bloquear la cuenta de aplicación y drenar sus sesiones existentes. No ejecutar los ejemplos hasta autorizar la ventana en el servidor correcto.

## Ventana y verificación

Ejecutar desde el checkout versionado con Composer instalado. Las variables apuntan a archivos privados existentes y a un directorio dedicado, con acceso restringido. El respaldo se cifra y guarda fuera del servidor conforme a la política local, mediante un destino de recuperación ya probado.

```sh
# Admin por socket. Confirmar cuentas, sesiones y esquema antes de copiar.
mysql --defaults-file="$admin" --protocol=SOCKET -e "ALTER USER 'tekora_app'@'localhost' ACCOUNT LOCK"
mysql --defaults-file="$admin" --protocol=SOCKET -e "SELECT PROCESSLIST_ID, PROCESSLIST_USER FROM performance_schema.threads WHERE PROCESSLIST_USER IN ('tekora_app','tekora_migrator')"

deploy/vps/provision.sh "$admin" "$migrator" --verify
deploy/vps/backup.sh "$backup" /srv/tekora/shared/storage "$snapshot"
(umask 077; tar -C "$snapshot" -cf - SHA256SUMS mysql.sql.gz files.tar.gz |
  age -R "$recovery_recipients" -o "$encrypted_snapshot")
sha256sum "$encrypted_snapshot"
# Confirmar copia externa y verificación con el responsable de restauración.
deploy/vps/provision.sh "$admin" "$migrator" --upgrade
deploy/vps/provision.sh "$admin" "$migrator" --verify
mysql --defaults-file="$admin" --protocol=SOCKET < deploy/vps/mysql-checkout-grants.sql
```

El primer sello esperado es 39/39, con el lote base inalterado. El segundo es 40/40 y añade una sola migración en el lote 2. La verificación compara digests, objetos, ledger y reloj. La actualización usa el mismo bloqueo administrativo y MySQL que el instalador anterior. Comprueba que no haya sesiones del migrador, de la aplicación ni del esquema, y exige la cuenta de aplicación bloqueada. El wrapper abre y cierra los permisos temporales de creación y lectura de metadatos. La cuenta de aplicación obtiene únicamente SELECT, INSERT y UPDATE de estado y fecha en la tabla nueva. El migrador Artisan ordinario continúa bloqueado.

Configurar `APP_ENV=production`, `APP_DEBUG=false` y `TEKORA_CHECKOUT_PROCESSOR=simulated` en el archivo privado. Preparar un release con `deploy/vps/prepare-release.sh /srv/tekora "$trusted_checkout" "$sha"` y validar cachés, rutas y assets. Asegurar que el código antiguo esté detenido antes de desbloquear `tekora_app@localhost` para el smoke privado. Verificar tarjeta, efectivo, vencimiento, confirmación explícita y acceso 404 a los fixtures locales. Incluir pruebas de clientes web y API según el [contrato](../payments/V8.1.10-INTEGRATED-SIMULATED-CHECKOUT.md).

En una instalación nueva, aplicar `mysql-runtime-grants.sql` y `mysql-checkout-grants.sql` tras verificar 40/40. Durante la actualización desde V8.1.8, la primera lista de permisos ya existe.

## Interrupción y recuperación

El DDL de MySQL confirma implícitamente. Una marca `installing` o `upgrading`, o un sello distinto, detiene el proceso sin reanudarlo ni aceptar un esquema desconocido. Mantener los escritores detenidos y la cuenta bloqueada. Verificar `log_bin_trust_function_creators=OFF` y que los permisos temporales del migrador hayan sido retirados. Preservar los logs y exportar metadatos para revisión. Con el respaldo anterior, reconstruir un destino vacío y aislado usando `deploy/vps/restore.sh "$restore_admin" "$snapshot" "$restore_storage" "$restore_migrator" --isolated-empty-target`. Allí verificar versión, sello, historial y watermark antes de decidir un cambio bajo autorización administrativa.

Un release anterior desconoce el marcador de nuevas propuestas. Si se revierte el código después de registrar checkout, mantener el servicio en mantenimiento hasta verificar compatibilidad y preservar los intentos y reservas creados desde el último respaldo. La reversión de código no modifica por sí sola el esquema. La apertura del origen público requiere decisión independiente.
