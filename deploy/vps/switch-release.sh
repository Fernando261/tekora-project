#!/usr/bin/env bash
set -euo pipefail
umask 027

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
source "$here/mysql-common.sh"
[[ $# -eq 3 && "$2" =~ ^[0-9a-f]{40}$ && "$3" == --approved ]] || fail 'Uso: switch-release.sh /srv/tekora SHA --approved'
root=$(realpath -e -- "$1")
release="$root/releases/$2"
[[ -d "$release" && ! -L "$release" && -f "$release/.tekora-release" && $(cat "$release/.tekora-release") == "$2" &&
    -f "$release/bootstrap/cache/config.php" && -f "$release/public/build/manifest.json" ]] || fail 'El release requerido no está completo.'
[[ ! -e "$root/current" || -L "$root/current" ]] || fail 'current debe ser un enlace administrado.'
exec 9>"$root/.release.lock"
flock -n 9 || fail 'Otra activación está en curso.'
previous=$(readlink -- "$root/current" || true)
temp=$(mktemp -d "$root/.release-switch.XXXXXXXX")
trap 'rm -f -- "$temp/current"; rmdir -- "$temp"' EXIT
ln -s "$release" "$temp/current"
mv -Tf -- "$temp/current" "$root/current"
printf 'current=%s\nprevious=%s\n' "$release" "$previous"
