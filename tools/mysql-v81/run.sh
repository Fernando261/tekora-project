#!/usr/bin/env bash
set -euo pipefail
umask 077

project=$(cd -- "$(dirname -- "$0")/../.." && pwd -P)
if [[ $# -ne 1 || ! -f "$project/vendor/autoload.php" || -e "$project/.env" || -e "$project/bootstrap/cache/config.php" ]]; then
    printf '%s\n' 'Use a fresh TEKORA checkout with locked Composer dependencies, no .env/config cache, and pass a new evidence directory.' >&2
    exit 2
fi
mkdir -m 700 -- "$1"
evidence=$(cd -- "$1" && pwd -P)
case "$evidence/" in "$project/"*) printf '%s\n' 'Evidence must be outside the checkout.' >&2; exit 2;; esac

mysql_image=mysql@sha256:cd05748e60471137face0ba0a256c9c48669198aee1f12132f413fc91fd8948f
php_image=${TEKORA_V81_PHP_IMAGE:-tekora-v81-lab-php:8.4.27}
suffix=$(openssl rand -hex 8)
lab=tekora-v81-$suffix
data=$lab-data
socket=$lab-socket
server_id=''
data_created=''
socket_created=''
cleanup() {
    if [[ -n "$server_id" ]]; then docker rm -f "$server_id" >/dev/null; fi
    if [[ -n "$socket_created" ]]; then docker volume rm "$socket" >/dev/null; fi
    if [[ -n "$data_created" ]]; then docker volume rm "$data" >/dev/null; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker volume create --label "tekora.v81.lab=$lab" "$data" >/dev/null
data_created=1
docker volume create --label "tekora.v81.lab=$lab" "$socket" >/dev/null
socket_created=1
export MYSQL_ROOT_PASSWORD MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 32)
MYSQL_DATABASE=tekora_v81_$suffix
MYSQL_USER=tekora_lab
MYSQL_PASSWORD=$(openssl rand -hex 32)
server_id=$(docker run -d --network none --name "$lab" --label "tekora.v81.lab=$lab" \
    --mount "type=volume,src=$data,dst=/var/lib/mysql" \
    --mount "type=volume,src=$socket,dst=/var/run/mysqld" \
    -e MYSQL_ROOT_PASSWORD -e MYSQL_DATABASE -e MYSQL_USER -e MYSQL_PASSWORD "$mysql_image" \
    --skip-networking --skip-log-bin --mysqlx=OFF --socket=/var/run/mysqld/mysql.sock)

wait_ready() {
    for attempt in {1..90}; do
        if docker exec "$server_id" sh -c '
            test "$(cat /proc/1/comm)" = mysqld &&
            MYSQL_PWD="$MYSQL_PASSWORD" mysql --socket=/var/run/mysqld/mysql.sock \
                --user="$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --skip-column-names -e "SELECT 1"
        ' >/dev/null 2>&1; then
            return
        fi
        sleep 1
    done
    docker logs "$server_id" > "$evidence/mysql-start.log" 2>&1
    printf '%s\n' 'BLOCKED: MySQL did not start.' >&2
    exit 1
}

export APP_KEY="base64:$(openssl rand -base64 32)"
export DB_PASSWORD="$MYSQL_PASSWORD"
export TEKORA_V81_RESTARTED=0
run_php() {
    docker run --rm --network none --read-only --workdir /project \
        --user "$(id -u):$(id -g)" \
        --mount "type=bind,src=$project,dst=/project,readonly" \
        --mount "type=bind,src=$evidence,dst=/evidence" \
        --mount "type=volume,src=$socket,dst=/run/tekora-mysql,readonly" \
        --tmpfs /tmp --tmpfs /project/bootstrap/cache:mode=1777 --tmpfs /project/storage/framework/views:mode=1777 \
        -e TEKORA_MYSQL_LAB=1 -e TEKORA_V81_RESTARTED -e APP_KEY -e DB_PASSWORD \
        -e "DB_USERNAME=$MYSQL_USER" -e "DB_DATABASE=$MYSQL_DATABASE" \
        -e DB_SOCKET=/run/tekora-mysql/mysql.sock "$php_image" php "$@"
}

isolation=$(docker inspect "$server_id" --format 'network={{.HostConfig.NetworkMode}} publish_all={{.HostConfig.PublishAllPorts}} bindings={{len .HostConfig.PortBindings}}')
printf '%s\n' "$isolation" | tee "$evidence/isolation.log"
if [[ "$isolation" != 'network=none publish_all=false bindings=0' ]]; then
    printf '%s\n' 'BLOCKED: MySQL must have no network or published port bindings.' >&2
    exit 1
fi
wait_ready
docker image inspect "$mysql_image" --format 'image={{.Id}} digests={{json .RepoDigests}}' | tee -a "$evidence/isolation.log"
docker image inspect "$php_image" --format 'test_runtime={{.Id}}' | tee -a "$evidence/isolation.log"
run_php vendor/bin/phpunit -c tests/MySqlV81/phpunit.xml --log-junit /evidence/mysql-junit.xml 2>&1 | tee "$evidence/mysql-phpunit.log"
# Explicitly exercise persistence across restart and idempotent migrations on that same volume.
docker restart "$server_id" >/dev/null
wait_ready
export TEKORA_V81_RESTARTED=1
run_php vendor/bin/phpunit -c tests/MySqlV81/phpunit.xml --filter test_foundation_migrations_are_idempotent \
    --log-junit /evidence/mysql-restart-junit.xml 2>&1 | tee "$evidence/mysql-restart.log"
