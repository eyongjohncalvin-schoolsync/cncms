#!/usr/bin/env bash
# CNCMS — nightly database dump. Run by cron as the app user (Ploi: `ploi`;
# manual kit: `cncms` via /etc/cron.d/cncms). Writes a gzipped pg_dump to
# $BACKUP_DIR (default ~/backups/cncms), keeps 14 days (never fewer than 3
# files). The deploy script calls it too, before every deploy.
#
# THIS ONLY PROTECTS AGAINST DATA LOSS IF IT IS COPIED OFF THE SERVER —
# offsite-backup.sh does that every night (deploy/PLOI.md §5c).
set -euo pipefail

DEST="${BACKUP_DIR:-$HOME/backups/cncms}"
APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
KEEP_DAYS=14
KEEP_MIN=3
STAMP="$(date +%Y-%m-%d-%H%M)"
FILE="${DEST}/cncms-${STAMP}.sql.gz"

mkdir -p "$DEST"

# The site's PHP when the server has several (e.g. PHP_BIN=php8.5).
PHP_BIN="${PHP_BIN:-php}"

# Reads DB creds from the app .env with Laravel's own parser (phpdotenv) —
# parse_ini_file() silently fails on ordinary Laravel .env lines and the
# script then fell back to the wrong user with no password.
cd "$APP_DIR"
env_value() {
  "$PHP_BIN" -r 'require "vendor/autoload.php"; $e = Dotenv\Dotenv::createArrayBacked(getcwd())->load(); echo $e[$argv[1]] ?? $argv[2];' "$1" "$2"
}
export PGPASSWORD="$(env_value DB_PASSWORD '')"
DB="$(env_value DB_DATABASE cncms)"
USER="$(env_value DB_USERNAME cncms)"
HOST="$(env_value DB_HOST 127.0.0.1)"
PORT="$(env_value DB_PORT 5432)"

pg_dump --no-owner --no-privileges -h "$HOST" -p "$PORT" -U "$USER" "$DB" | gzip -9 > "$FILE"
unset PGPASSWORD

# Prune by age, not count: deploy backups would otherwise push the nightly
# ones out. The newest $KEEP_MIN always stay, even if the cron stops running.
ls -1t "${DEST}"/cncms-*.sql.gz | tail -n +$((KEEP_MIN + 1)) \
  | while read -r old; do
      if [[ -n "$(find "$old" -mtime +"$KEEP_DAYS")" ]]; then rm -f "$old"; fi
    done

echo "$(date -Is)  wrote $(du -h "$FILE" | cut -f1)  $FILE"
