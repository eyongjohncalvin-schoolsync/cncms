#!/usr/bin/env bash
# CNCMS — deploy the latest code. Run as the `cncms` user:
#   sudo -u cncms -H /var/www/cncms/deploy/scripts/deploy.sh
#
# Puts the site in maintenance mode, pulls, rebuilds, migrates (central +
# every tenant), re-caches, restarts the worker, brings it back up, and
# reloads PHP-FPM (opcache.validate_timestamps=0). On failure the site
# stays in maintenance mode — fix and re-run.
set -euo pipefail

APP_DIR=/var/www/cncms
BRANCH=prepayment-drawdown-credit   # change to `main` once merged
PHP_FPM=php8.4-fpm

cd "$APP_DIR"

echo "==> maintenance mode"
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up || true' EXIT

echo "==> pull ${BRANCH}"
git fetch --prune origin
git checkout "$BRANCH"
git reset --hard "origin/${BRANCH}"

echo "==> composer"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> frontend build"
npm ci --no-audit --no-fund
npm run build

echo "==> migrate"
php artisan migrate --force
php artisan tenants:migrate --force

echo "==> cache"
php artisan optimize:clear
php artisan optimize          # config + routes + views + events

echo "==> restart workers + reload FPM"
php artisan queue:restart
sudo systemctl reload "$PHP_FPM"

echo "==> up"
php artisan up
trap - EXIT

echo "==> done: $(git rev-parse --short HEAD) on ${BRANCH}"
