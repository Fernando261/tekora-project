#!/usr/bin/env bash
set -euo pipefail
umask 077

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
source "$here/mysql-common.sh"
[[ $# -eq 5 && "$5" == --isolated-empty-target ]] ||
    fail 'Uso: restore.sh admin-destino.cnf directorio-respaldo almacenamiento-nuevo migrator-destino.json --isolated-empty-target'
mysql_defaults=$1
private_file "$mysql_defaults"
private_file "$4"
backup=$(realpath -e -- "$2")
storage=$(realpath -m -- "$3")
[[ ! -e "$storage" && ! -L "$storage" ]] || fail 'El destino de archivos ya existe.'
(
    cd -- "$backup"
    for file in SHA256SUMS mysql.sql.gz files.tar.gz; do private_file "$file"; done
    cmp -s SHA256SUMS <(sha256sum mysql.sql.gz files.tar.gz) || fail 'El checksum del respaldo no coincide.'
)
# Validate names, types and duplicates before extracting to a private empty directory.
python3 - "$backup/files.tar.gz" <<'PY'
import sys
import tarfile
from pathlib import PurePosixPath

with tarfile.open(sys.argv[1], "r:gz") as archive:
    seen = set()
    for item in archive:
        p = PurePosixPath(item.name)
        if p.parts[:2] not in (("app", "private"), ("app", "public")) or ".." in p.parts:
            sys.exit("Ruta no permitida en el archivo de respaldo.")
        if p in seen or not (item.isfile() or item.isdir()):
            sys.exit("Tipo de archivo o entrada duplicada no permitida.")
        seen.add(p)
PY
[[ $(mysql_local -e "SELECT CONCAT(VERSION(),':',@@global.log_bin,':',@@global.binlog_format,':',@@global.log_bin_trust_function_creators)") =~ ^8\.4\.13(-commercial)?:1:ROW:0$ ]] ||
    fail 'El destino debe ser MySQL 8.4.13, con binlog ROW y la ventana administrativa cerrada.'
[[ $(mysql_local -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='tekora' AND default_collation_name='utf8mb4_unicode_ci'") == 1 ]] ||
    fail 'El administrador debe crear el esquema canónico vacío.'
[[ $(mysql_local -e "SELECT COUNT(*) FROM mysql.user WHERE user='tekora_definer' AND host='localhost' AND account_locked='Y'") == 1 ]] ||
    fail 'El DEFINER bloqueado debe existir antes de restaurar.'
[[ $(mysql_local -e "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tekora')
    +(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='tekora')
    +(SELECT COUNT(*) FROM information_schema.events WHERE event_schema='tekora')") == 0 ]] ||
    fail 'El destino ya tiene objetos. No se aplicarán cambios.'
mkdir -m 700 -- "$storage"
gzip -dc -- "$backup/mysql.sql.gz" | mysql_local --database=tekora
"$here/provision.sh" "$mysql_defaults" "$4" --verify
mysql_local < "$here/mysql-runtime-grants.sql"
if [[ $(mysql_local -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tekora' AND table_name='integrated_checkout_attempts'") == 1 ]]; then
    mysql_local < "$here/mysql-checkout-grants.sql"
fi
mysql_local --database=tekora < "$here/mysql-check-foreign-keys.sql"
tar --extract --gzip --no-same-owner --no-same-permissions --file="$backup/files.tar.gz" --directory="$storage"
printf '%s\n' 'Restauración verificada en el destino indicado.'
