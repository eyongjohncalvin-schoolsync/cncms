#!/usr/bin/env bash
# CNCMS — note the commit just deployed, and the backup taken before it, in
# the deploy log. The last line of every deploy script. rollback.sh reads
# the log to know "the last working version" and which backup goes with it.
#
# Log: $DEPLOY_LOG (default ~/cncms-deploys.log) — outside the repo, so a
# rollback's `git reset` never touches it. One line per deploy:
#   <time> deploy <commit> <backup file, or "none">
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LOG="${DEPLOY_LOG:-$HOME/cncms-deploys.log}"
BACKUPS="${BACKUP_DIR:-$HOME/backups/cncms}"

cd "$APP_DIR"
SHA="$(git rev-parse HEAD)"

# The backup this deploy took (backup-db.sh runs first): the newest one, if
# it is from the last hour.
BACKUP="$(find "$BACKUPS" -maxdepth 1 -name 'cncms-*.sql.gz' -mmin -60 -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -n 1 | cut -d' ' -f2- || true)"

echo "$(date -Is) deploy ${SHA} ${BACKUP:-none}" >> "$LOG"
echo "Recorded deploy $(git rev-parse --short HEAD) in ${LOG}"
