#!/usr/bin/env bash
set -euo pipefail
umask 077

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
project=$(cd -- "$here/../.." && pwd -P)
source "$here/mysql-common.sh"

[[ $# -eq 2 || ( $# -eq 3 && ( "$3" == --verify || "$3" == --upgrade ) ) ]] || fail 'Uso: provision.sh admin.cnf migrator.json [--verify|--upgrade]'
mysql_defaults=$1
private_file "$mysql_defaults"
private_file "$2"
if [[ ${3:-} == --verify ]]; then
    exec php "$project/artisan" tekora:mysql:provision --credentials="$2" --verify
fi

# Serialize the administrative window for this installation on this host.
exec 9>"$(dirname -- "$(realpath -- "$mysql_defaults")")/.tekora-provision.lock"
flock -n 9 || fail 'Ya existe una ventana administrativa de Tekora.'
[[ $(mysql_local -e 'SELECT @@global.log_bin_trust_function_creators') == 0 ]] ||
    fail 'La ventana debe comenzar con log_bin_trust_function_creators=OFF.'
[[ $(mysql_local -e "SELECT COUNT(*) FROM mysql.global_grants WHERE USER='tekora_migrator' AND HOST='localhost' AND PRIV='SET_ANY_DEFINER'") == 0 ]] ||
    fail 'El migrador ya tiene un privilegio administrativo temporal; revisar antes de continuar.'
account_metadata_granted=0
session_metadata_granted=0
cleanup() {
    local status=$?
    trap - EXIT
    if ! mysql_local -e 'SET GLOBAL log_bin_trust_function_creators=OFF' >/dev/null ||
        [[ $(mysql_local -e 'SELECT @@global.log_bin_trust_function_creators') != 0 ]]; then
        printf '%s\n' 'CRÍTICO: no se pudo verificar log_bin_trust_function_creators=OFF.' >&2
        status=1
    fi
    local check="SELECT COUNT(*) FROM mysql.global_grants WHERE USER='tekora_migrator' AND HOST='localhost' AND PRIV='SET_ANY_DEFINER'"
    if [[ $(mysql_local -e "$check") != 0 ]]; then
        if ! mysql_local -e "REVOKE SET_ANY_DEFINER ON *.* FROM 'tekora_migrator'@'localhost'" >/dev/null ||
            [[ $(mysql_local -e "$check") != 0 ]]; then
            printf '%s\n' 'CRÍTICO: no se pudo verificar la retirada de SET_ANY_DEFINER.' >&2
            status=1
        fi
    fi
    if [[ "$session_metadata_granted" == 1 ]]; then
        mysql_local -e "REVOKE SELECT (PROCESSLIST_ID,PROCESSLIST_USER,PROCESSLIST_DB) ON performance_schema.threads FROM 'tekora_migrator'@'localhost'" || status=1
    fi
    if [[ "$account_metadata_granted" == 1 ]]; then
        mysql_local -e "REVOKE SELECT (User,Host,account_locked) ON mysql.user FROM 'tekora_migrator'@'localhost'" || status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mysql_local -e "GRANT SET_ANY_DEFINER ON *.* TO 'tekora_migrator'@'localhost'"
mysql_local -e 'SET GLOBAL log_bin_trust_function_creators=ON'
if [[ ${3:-} == --upgrade ]]; then
    [[ $(mysql_local -e "SELECT COUNT(*) FROM mysql.columns_priv WHERE User='tekora_migrator' AND Host='localhost' AND Db IN ('mysql','performance_schema')") == 0 ]] ||
        fail 'El migrador ya tiene permisos de metadatos; revisar antes de continuar.'
    mysql_local -e "GRANT SELECT (User,Host,account_locked) ON mysql.user TO 'tekora_migrator'@'localhost'"
    account_metadata_granted=1
    mysql_local -e "GRANT SELECT (PROCESSLIST_ID,PROCESSLIST_USER,PROCESSLIST_DB) ON performance_schema.threads TO 'tekora_migrator'@'localhost'"
    session_metadata_granted=1
    php "$project/artisan" tekora:mysql:provision --credentials="$2" --upgrade
else
    php "$project/artisan" tekora:mysql:provision --credentials="$2"
fi
