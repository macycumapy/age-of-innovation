#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"
export APP_SERVICE=app

build_directory="${1:?Usage: deploy.sh BUILD_DIRECTORY}"
test -s "$build_directory/manifest.json" || { echo "Frontend build is missing" >&2; exit 1; }

./vendor/bin/sail composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
./vendor/bin/sail artisan down --retry=60
trap './vendor/bin/sail artisan up' EXIT

./vendor/bin/sail artisan migrate --seed --force --no-interaction
./vendor/bin/sail artisan optimize:clear --no-interaction

# Keep old hashed assets available to pages opened before the deployment.
mkdir -p public/build
cp -a "$build_directory/." public/build/
rm -f public/hot

./vendor/bin/sail restart app horizon reverb
./vendor/bin/sail artisan up
trap - EXIT
rm -rf -- "$build_directory"
