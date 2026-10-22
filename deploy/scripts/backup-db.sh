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

# Reads DB creds from the app .env so there's one source of truth.
cd "$APP_DIR"
export PGPASSWORD="$(php -r '$e=parse_ini_file(".env"); echo $e["DB_PASSWORD"] ?? "";')"
DB="$(php -r '$e=parse_ini_file(".env"); echo $e["DB_DATABASE"] ?? "cncms";')"
USER="$(php -r '$e=parse_ini_file(".env"); echo $e["DB_USERNAME"] ?? "cncms";')"
HOST="$(php -r '$e=parse_ini_file(".env"); echo $e["DB_HOST"] ?? "127.0.0.1";')"

pg_dump --no-owner --no-privileges -h "$HOST" -U "$USER" "$DB" | gzip -9 > "$FILE"
unset PGPASSWORD

# Prune
ls -1t "${DEST}"/cncms-*.sql.gz | tail -n +$((KEEP + 1)) | xargs -r rm -f

echo "$(date -Is)  wrote $(du -h "$FILE" | cut -f1)  $FILE"
