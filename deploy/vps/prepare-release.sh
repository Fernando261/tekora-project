#!/usr/bin/env bash
set -euo pipefail
umask 027

here=$(cd -- "$(dirname -- "$0")" && pwd -P)
source "$here/mysql-common.sh"
[[ $# -eq 3 && "$3" =~ ^[0-9a-f]{40}$ ]] || fail 'Uso: prepare-release.sh /srv/tekora checkout-confiable SHA'
root=$(realpath -e -- "$1")
repo=$(realpath -e -- "$2")
sha=$3
"$here/preflight.sh" "$root"
[[ $(git -C "$repo" rev-parse "$sha^{commit}") == "$sha" ]] || fail 'El commit solicitado no está disponible.'
private_file "$root/shared/production.env"
[[ -d "$root/shared/storage/app/private" && -d "$root/shared/storage/app/public" ]] || fail 'Falta el almacenamiento compartido.'
release="$root/releases/$sha"
mkdir -m 750 -- "$release"
git -C "$repo" archive "$sha" | tar -xf - -C "$release"
cd -- "$release"
composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins --optimize-autoloader
npm ci --no-audit --no-fund
NODE_OPTIONS=--max-old-space-size=384 npm run build
# Only remove regenerable dependencies from the new release.
rm -rf -- "$release/node_modules"
test -s public/build/manifest.json
ln -s "$root/shared/production.env" .env
# Keep Laravel's original disk paths while preserving only the two data directories.
for disk in private public; do
    rmdir "storage/app/$disk" 2>/dev/null || {
        [[ -f "storage/app/$disk/.gitignore" && $(find "storage/app/$disk" -mindepth 1 -maxdepth 1 | wc -l) -eq 1 ]] ||
            fail 'Contenido inesperado en el directorio inicial de almacenamiento.'
        rm -- "storage/app/$disk/.gitignore"
        rmdir -- "storage/app/$disk"
    }
    ln -s "$root/shared/storage/app/$disk" "storage/app/$disk"
done
ln -s "$root/shared/storage/app/public" public/storage
php artisan package:discover --no-ansi
php artisan config:cache --no-ansi
php artisan route:cache --no-ansi
printf '%s\n' "$sha" > "$release/.tekora-release"
printf '%s\n' "Release preparado en $release. Pendientes: smoke local, permisos FPM y activación autorizada."
