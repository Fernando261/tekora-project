#!/usr/bin/env bash
set -euo pipefail
umask 077

project=$(cd -- "$(dirname -- "$0")/../.." && pwd -P)
if [[ $# -ne 1 || ! -f "$project/vendor/autoload.php" || -e "$project/.env" || -e "$project/.env.testing" || -e "$project/bootstrap/cache/config.php" ]]; then
    printf '%s\n' 'BLOCKED: se requiere checkout aislado, Composer instalado y directorio de evidencias nuevo.' >&2
    exit 2
fi
mkdir -m 700 -- "$1"
evidence=$(realpath -e -- "$1")
[[ "$evidence/" != "$project/"* ]] || exit 2
private=$(mktemp -d)
lab=tekora-v818-$(openssl rand -hex 8)
image=mysql@sha256:cd05748e60471137face0ba0a256c9c48669198aee1f12132f413fc91fd8948f
php_image=tekora-v818-lab-php:8.3.37
app_key="base64:$(openssl rand -base64 32)"
export TEKORA_V818_PROJECT="$project" TEKORA_V818_PRIVATE="$private" TEKORA_V818_EVIDENCE="$evidence"
export TEKORA_V818_LAB="$lab" TEKORA_V818_PHP_IMAGE="$php_image"
export TEKORA_V818_INSTANCE=source
cleanup() {
    local status=$?
    trap - EXIT
    for instance in source target; do
        local name="$lab-$instance"
        if docker inspect "$name" >/dev/null 2>&1; then
            docker logs "$name" > "$evidence/mysql-$instance.log" 2>&1
            docker rm -f "$name" >/dev/null || status=1
        fi
        for volume in "$name-socket"; do
            if docker volume inspect "$volume" >/dev/null 2>&1; then
                docker volume rm "$volume" >/dev/null || status=1
            fi
        done
    done
    # The container user owns its initialized files; remove via the isolated image.
    docker run --rm --network none --mount "type=bind,src=$private,dst=/lab" "$image" sh -c 'rm -rf /lab/source-data /lab/target-data' || status=1
    rm -rf -- "$private" || status=1
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Keep native passwords and option files in the private mount, outside artifacts and container arguments.
# The stock entrypoint creates a temporary password-less socket during bootstrap; wait for mysqld PID 1.
start() {
    local instance=$1 server_id=$2
    local name="$lab-$instance"
    openssl rand -hex 32 > "$private/$instance.root"
    {
        printf '[client]\nuser=root\nsocket=/var/run/mysqld/mysql.sock\npassword='
        cat "$private/$instance.root"
    } > "$private/$instance-admin.cnf"
    mkdir "$private/$instance-data"
    docker volume create --label "tekora.v818.lab=$lab" "$name-socket" >/dev/null
    docker run -d --network none --name "$name" --label "tekora.v818.lab=$lab" \
        --mount "type=bind,src=$private/$instance-data,dst=/var/lib/mysql" \
        --mount "type=volume,src=$name-socket,dst=/var/run/mysqld" \
        --mount "type=bind,src=$private,dst=$private,readonly" \
        -e "MYSQL_ROOT_PASSWORD_FILE=$private/$instance.root" "$image" \
        --skip-networking --mysqlx=OFF --socket=/var/run/mysqld/mysql.sock --server-id="$server_id" \
        --log-bin=/var/lib/mysql/tekora-bin --binlog-format=ROW --default-time-zone=+00:00 \
        --innodb-redo-log-capacity=104857600 --innodb-buffer-pool-size=134217728 --max-connections=20 >/dev/null
    local isolation
    isolation=$(docker inspect "$name" --format 'network={{.HostConfig.NetworkMode}} publish_all={{.HostConfig.PublishAllPorts}} bindings={{len .HostConfig.PortBindings}}')
    printf '%s %s\n' "$instance" "$isolation"
    [[ "$isolation" == 'network=none publish_all=false bindings=0' ]]
    wait_ready "$instance"
}

wait_ready() {
    local instance=$1
    for attempt in {1..90}; do
        if docker exec "$lab-$instance" sh -c 'test "$(cat /proc/1/comm)" = mysqld' 2>/dev/null &&
            mysql --defaults-file="$private/$instance-admin.cnf" --protocol=SOCKET -e 'SELECT 1' >/dev/null 2>&1; then
            return
        fi
        if [[ $(docker inspect "$lab-$instance" --format "{{.State.Status}}") == exited ]]; then break; fi
        sleep 1
    done
    printf '%s\n' 'BLOCKED: MySQL no inició en el laboratorio.' >&2
    exit 1
}

# Run the versioned VPS scripts verbatim, routing each native command only to the matching lab instance.
mkdir "$private/bin"
cat > "$private/bin/mysql" <<'CLIENT'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
    "--defaults-file=$TEKORA_V818_PRIVATE/source-"*) instance=source ;;
    "--defaults-file=$TEKORA_V818_PRIVATE/target-"*) instance=target ;;
    *) exit 2 ;;
esac
exec docker exec -i "$TEKORA_V818_LAB-$instance" "${0##*/}" "$@"
CLIENT
cp "$private/bin/mysql" "$private/bin/mysqldump"
cat > "$private/bin/php" <<'PHP'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
    "$TEKORA_V818_PROJECT/"*) script=${1#"$TEKORA_V818_PROJECT/"}; shift; set -- "$script" "$@" ;;
esac
case "$TEKORA_V818_INSTANCE" in source|target) ;; *) exit 2;; esac
mounts=()
case "$1" in
    artisan)
        credential="$TEKORA_V818_PRIVATE/$TEKORA_V818_INSTANCE-migrator.json"
        mounts=(--mount "type=bind,src=$credential,dst=$credential,readonly")
        ;;
    vendor/bin/phpunit)
        credential="$TEKORA_V818_PRIVATE/$TEKORA_V818_INSTANCE-app.json"
        mounts=(--mount "type=bind,src=$credential,dst=$credential,readonly")
        ;;
    --version) ;;
    *) exit 2 ;;
esac
exec docker run --rm --network none --read-only --workdir /project \
    --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$TEKORA_V818_PROJECT,dst=/project,readonly" \
    --mount "type=bind,src=$TEKORA_V818_EVIDENCE,dst=/evidence" \
    "${mounts[@]}" \
    --mount "type=volume,src=$TEKORA_V818_LAB-$TEKORA_V818_INSTANCE-socket,dst=/run/tekora-mysql,readonly" \
    --tmpfs /tmp --tmpfs /project/bootstrap/cache:mode=1777 --tmpfs /project/storage/framework/views:mode=1777 \
    -e APP_ENV=testing -e APP_KEY -e TEKORA_V818_PRIVATE -e TEKORA_V818_INSTANCE \
    "$TEKORA_V818_PHP_IMAGE" php "$@"
PHP
chmod 700 "$private/bin/"*
export PATH="$private/bin:$PATH"
export APP_KEY="$app_key"

admin() { mysql --defaults-file="$private/$1-admin.cnf" --protocol=SOCKET --batch --skip-column-names "${@:2}"; }
accounts() {
    local instance=$1 account secret
    admin "$instance" < "$project/deploy/vps/mysql-accounts.sql" > "$private/$instance-passwords"
    for account in migrator app backup; do
        secret=$(awk -F '\t' -v name="tekora_$account" '$1 == name && $2 == "localhost" {print $3}' "$private/$instance-passwords")
        [[ -n "$secret" && "$secret" != *$'\n'* ]]
        printf '%s' "$secret" | python3 -c 'import json,sys; json.dump(dict(socket="/run/tekora-mysql/mysql.sock",password=sys.stdin.read()),sys.stdout)' > "$private/$instance-$account.json"
        if [[ "$account" == backup ]]; then
            # MySQL option files require escaping backslashes and double quotes.
            printf '%s' "$secret" | python3 -c 'import json,sys; print("[client]\nuser=tekora_backup\nsocket=/var/run/mysqld/mysql.sock\npassword="+json.dumps(sys.stdin.read()))' > "$private/$instance-backup.cnf"
        fi
    done
    rm -- "$private/$instance-passwords"
}
administrative_window_closed() {
    [[ $(admin "$1" -e "SELECT CONCAT(@@log_bin,':',@@binlog_format,':',@@log_bin_trust_function_creators,':',
        (SELECT COUNT(*) FROM mysql.global_grants WHERE USER='tekora_migrator' AND HOST='localhost' AND PRIV='SET_ANY_DEFINER'))") == '1:ROW:0:0' ]]
}

cd -- "$project"
exec > >(tee "$evidence/runner.log") 2>&1
start source 81501
accounts source
admin source tekora -e "CREATE TABLE tekora_provisioning (id TINYINT PRIMARY KEY, state VARCHAR(16)); INSERT INTO tekora_provisioning VALUES (1,'installing')"
provision="$project/deploy/vps/provision.sh"
if "$provision" "$private/source-admin.cnf" "$private/source-migrator.json"; then
    printf '%s\n' 'FAIL: aceptó una instalación interrumpida.' >&2
    exit 1
fi
[[ $(admin source tekora -e 'SELECT state FROM tekora_provisioning') == installing ]]
administrative_window_closed source
printf '%s\n' 'Instalación interrumpida rechazada; marca intacta y privilegios temporales retirados.'
# Only this fixture created above is explicitly removed by the owner of the disposable lab.
admin source tekora -e 'DROP TABLE tekora_provisioning'
"$provision" "$private/source-admin.cnf" "$private/source-migrator.json"
administrative_window_closed source
"$provision" "$private/source-admin.cnf" "$private/source-migrator.json"
administrative_window_closed source
admin source < "$project/deploy/vps/mysql-runtime-grants.sql"
admin source tekora -e 'CREATE TABLE altered_schema_fixture (id INT PRIMARY KEY)'
if "$provision" "$private/source-admin.cnf" "$private/source-migrator.json" --verify; then
    printf '%s\n' 'FAIL: se aceptó un esquema con drift.' >&2
    exit 1
fi
[[ $(admin source tekora -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tekora' AND table_name='altered_schema_fixture'") == 1 ]]
admin source tekora -e 'DROP TABLE altered_schema_fixture'
printf '%s\n' 'Drift rechazado y objeto de la prueba intacto.'

php --version
# Exercise completion after the actual business deadline using a historical clock only in this
# disposable source. Restore the original definition and verify its digest before native-clock tests.
admin source tekora --raw -e 'SHOW CREATE FUNCTION tekora_booking_now_us' |
    python3 -c 'import sys; print(sys.stdin.read().split("\t", 2)[2].rsplit("\t", 3)[0])' > "$private/clock.sql"
{
    printf 'DROP FUNCTION tekora_booking_now_us;\nDELIMITER //\n'
    sed '/RETURN TIMESTAMPDIFF/i\    IF @tekora_v818_lab_clock IS NOT NULL THEN RETURN @tekora_v818_lab_clock; END IF;' "$private/clock.sql"
    printf '//\nDELIMITER ;\n'
} | admin source tekora
admin source < "$project/deploy/vps/mysql-runtime-grants.sql"
php vendor/bin/phpunit -c tests/MySqlV818/phpunit.xml --filter test_historical_journey --log-junit /evidence/mysql-completed.xml
{
    printf 'DROP FUNCTION tekora_booking_now_us;\nDELIMITER //\n'
    cat "$private/clock.sql"
    printf '//\nDELIMITER ;\n'
} | admin source tekora
admin source < "$project/deploy/vps/mysql-runtime-grants.sql"
"$provision" "$private/source-admin.cnf" "$private/source-migrator.json" --verify
php vendor/bin/phpunit -c tests/MySqlV818/phpunit.xml --filter 'test_runtime|test_ordinary|test_live_clock|test_identity' --log-junit /evidence/mysql-provisioned.xml
mkdir -p "$private/source-storage/app/private" "$private/source-storage/app/public/photos"
printf 'private synthetic fixture\n' > "$private/source-storage/app/private/fixture.txt"
printf 'public synthetic fixture\n' > "$private/source-storage/app/public/photos/fixture.txt"
watermark=$(admin source tekora -e 'SELECT high_water_us FROM reservation_hold_clock WHERE id=1')
[[ "$watermark" =~ ^[1-9][0-9]*\.0+$ ]]
"$project/deploy/vps/backup.sh" "$private/source-backup.cnf" "$private/source-storage" "$private/snapshot"

docker restart "$lab-source" >/dev/null
wait_ready source
[[ $(admin source tekora -e 'SELECT high_water_us FROM reservation_hold_clock WHERE id=1') == "$watermark" ]]
"$provision" "$private/source-admin.cnf" "$private/source-migrator.json" --verify
php vendor/bin/phpunit -c tests/MySqlV818/phpunit.xml --filter test_persisted_journey --log-junit /evidence/mysql-restarted.xml

start target 81502
accounts target
export TEKORA_V818_INSTANCE=target
"$project/deploy/vps/restore.sh" "$private/target-admin.cnf" "$private/snapshot" "$private/restored-storage" "$private/target-migrator.json" --isolated-empty-target
administrative_window_closed target
[[ $(admin target tekora -e 'SELECT high_water_us FROM reservation_hold_clock WHERE id=1') == "$watermark" ]]
printf '%s\n' 'Watermark idéntico tras reinicio y restauración.'
diff -r -- "$private/source-storage" "$private/restored-storage"
php vendor/bin/phpunit -c tests/MySqlV818/phpunit.xml --filter test_persisted_journey --log-junit /evidence/mysql-restored.xml
