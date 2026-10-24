#!/usr/bin/env bash
# CNCMS — nightly database dump. Run by cron as the app user (Ploi: `ploi`;
# manual kit: `cncms` via /etc/cron.d/cncms). Writes a gzipped pg_dump to
# $BACKUP_DIR (default ~/backups/cncms) and keeps the last 14.
#
# THIS ONLY PROTECTS AGAINST DATA LOSS IF YOU COPY IT OFF THE SERVER.
# e.g. from your own machine:
#   rsync -az ploi@YOUR_DOMAIN:backups/cncms/ ~/cncms-backups/
set -euo pipefail

DEST="${BACKUP_DIR:-$HOME/backups/cncms}"
APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
KEEP=14
STAMP="$(date +%Y-%m-%d-%H%M)"
FILE="${DEST}/cncms-${STAMP}.sql.gz"

mkdir -p "$DEST"

# Reads DB creds from the app .env with Laravel's own parser (phpdotenv) —
# parse_ini_file() silently fails on ordinary Laravel .env lines and the
# script then fell back to the wrong user with no password.
cd "$APP_DIR"
env_value() {
  php -r 'require "vendor/autoload.php"; $e = Dotenv\Dotenv::createArrayBacked(getcwd())->load(); echo $e[$argv[1]] ?? $argv[2];' "$1" "$2"
}
export PGPASSWORD="$(env_value DB_PASSWORD '')"
DB="$(env_value DB_DATABASE cncms)"
USER="$(env_value DB_USERNAME cncms)"
HOST="$(env_value DB_HOST 127.0.0.1)"
PORT="$(env_value DB_PORT 5432)"

pg_dump --no-owner --no-privileges -h "$HOST" -p "$PORT" -U "$USER" "$DB" | gzip -9 > "$FILE"
unset PGPASSWORD

# Prune
ls -1t "${DEST}"/cncms-*.sql.gz | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "$(date -Is)  wrote $(du -h "$FILE" | cut -f1)  $FILE"
