#!/usr/bin/env bash
set -euo pipefail
umask 077

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
source "$here/mysql-common.sh"
[[ $# -eq 3 ]] || fail 'Uso: backup.sh backup.cnf almacenamiento-compartido directorio-nuevo'
mysql_defaults=$1
private_file "$mysql_defaults"
storage=$(realpath -e -- "$2")
[[ -d "$storage/app/private" && -d "$storage/app/public" ]] || fail 'Faltan los directorios de archivos compartidos.'
[[ -z $(find "$storage/app/private" "$storage/app/public" ! -type f ! -type d -print -quit) ]] ||
    fail 'El respaldo solo admite archivos regulares y directorios.'
[[ $(mysql_local -e 'SELECT CURRENT_USER()') == tekora_backup@localhost ]] || fail 'Se requiere la cuenta de respaldo.'
mkdir -m 700 -- "$3"
backup=$(realpath -e -- "$3")
[[ "$backup/" != "$storage/"* && "$storage/" != "$backup/"* ]] || fail 'El destino y el almacenamiento deben ser independientes.'
mysqldump --defaults-file="$mysql_defaults" --protocol=SOCKET --single-transaction --skip-lock-tables \
    --no-tablespaces --set-gtid-purged=OFF --routines --triggers --events --hex-blob \
    --skip-add-drop-table --skip-add-locks --skip-disable-keys --column-statistics=0 tekora | gzip -c > "$backup/mysql.sql.gz"
tar -C "$storage" -czf "$backup/files.tar.gz" app/private app/public
(
    cd -- "$backup"
    sha256sum mysql.sql.gz files.tar.gz > SHA256SUMS
)
printf '%s\n' 'Respaldo local cerrado con SHA-256.'
