#!/usr/bin/env bash

fail() { printf '%s\n' "$*" >&2; exit 1; }

private_file() {
    [[ -f "$1" && ! -L "$1" && -O "$1" ]] || fail 'Se requiere un archivo privado propio y regular.'
    local mode
    mode=$(stat -c %a -- "$1")
    (( (8#$mode & 077) == 0 )) || fail 'El archivo de conexión debe tener modo 0600 o 0400.'
}

mysql_local() {
    mysql --defaults-file="$mysql_defaults" --protocol=SOCKET --batch --skip-column-names "$@"
}
