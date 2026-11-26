#!/usr/bin/env bash
set -euo pipefail

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
source "$here/mysql-common.sh"
[[ $# -eq 1 ]] || fail 'Uso: preflight.sh /srv/tekora'
root=$1
[[ -d "$root" && $(uname -m) == x86_64 ]] || fail 'Falta el directorio de releases o la arquitectura x86_64.'
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 ]] || fail 'Se requiere Ubuntu 24.04.'
for command in php composer node npm mysql mysqldump nginx curl python3 git flock ss; do
    command -v "$command" >/dev/null || fail "Falta $command."
done
[[ $(getconf _NPROCESSORS_ONLN) -ge 2 ]] || fail 'Se requieren 2 vCPU.'
awk '/MemTotal:/ {ram=$2} /SwapTotal:/ {swap=$2} END {exit !(ram>=800*1024 && swap>=1024*1024)}' /proc/meminfo ||
    fail 'Memoria o swap insuficientes para este procedimiento.'
df -Pk "$root" | awk 'NR==2 {exit !($4 >= 2*1024*1024)}' || fail 'Se requieren 2 GiB libres antes de preparar el release.'
php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 && extension_loaded("pdo_mysql") && extension_loaded("mbstring") && extension_loaded("fileinfo") && extension_loaded("curl") && extension_loaded("dom") ? 0 : 1);' ||
    fail 'Revisar PHP 8.3 CLI y extensiones requeridas.'
[[ $(node -p 'process.versions.node.split(".")[0]') == 22 ]] || fail 'Se requiere Node.js 22.'
[[ -S /run/php/php8.3-fpm.sock ]] || fail 'El socket PHP-FPM esperado no está activo.'
for service in nginx php8.3-fpm mysql cloudflared; do
    systemctl is-active --quiet "$service" || fail "$service no está activo."
done
ss -H -ltn | awk '$4 ~ /:(3306|33060|9000|8083)$/ {
    if ($4 == "127.0.0.1:3306") mysql=1;
    else if ($4 == "127.0.0.1:8083") origin=1;
    else bad=1;
} END {exit !(mysql && origin && !bad)}' || fail 'Revisar listeners de MySQL, MySQL X, PHP-FPM y origen.'
printf '%s\n' 'Preflight de host conforme. Aún se requieren pruebas del release y la aprobación de publicación.'
