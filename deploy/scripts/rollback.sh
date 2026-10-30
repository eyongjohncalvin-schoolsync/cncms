#!/usr/bin/env bash
# CNCMS — put the last working version back. Run over SSH as the site user,
# from anywhere:
#   PHP_BIN=php8.5 bash ~/cncms.tech/deploy/scripts/rollback.sh             # back to the previous deploy
#   PHP_BIN=php8.5 bash ~/cncms.tech/deploy/scripts/rollback.sh 795c0154    # back to a chosen commit
#
# Code only, when the release being undone changed no migration: the site
# goes into maintenance, the old commit is checked out and rebuilt, and the
# site comes back — nothing anyone entered is lost.
#
# When it did change migrations, old code on the new database can break
# (e.g. refuse to save a payment), so the database must go back too. The
# script stops and prints the restore steps with the backup taken before
# the bad deploy; once restored, run it again with --database-restored.
# A restore loses everything entered since that backup — after staff have
# worked on the new version, prefer fixing forward (deploy/BLUE-GREEN.md §4).
#
# Afterwards Ploi's next deploy pulls `main` again — the broken version —
# until `main` is fixed (`git revert`). Keep Quick deploy off till then.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LOG="${DEPLOY_LOG:-$HOME/cncms-deploys.log}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-$PHP_BIN $(command -v composer || echo composer)}"

TARGET=""
DATABASE_RESTORED=false
ASSUME_YES=false

for arg in "$@"; do
  case "$arg" in
    --database-restored) DATABASE_RESTORED=true ;;
    --yes) ASSUME_YES=true ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    -*) echo "Unknown option: $arg" >&2; exit 1 ;;
    *) TARGET="$arg" ;;
  esac
done

cd "$APP_DIR"
CURRENT="$(git rev-parse HEAD)"
git fetch --quiet --prune origin || echo "(could not reach GitHub — using the commits already on the server)"

# No commit given: the newest deploy in the log that is not what runs now.
if [[ -z "$TARGET" ]]; then
  if [[ ! -s "$LOG" ]]; then
    echo "No deploys recorded in ${LOG} yet — name the commit to go back to (git log --oneline -10)." >&2
    exit 1
  fi
  TARGET="$(tac "$LOG" | awk -v current="$CURRENT" '$3 != current { print $3; exit }')"
  if [[ -z "$TARGET" ]]; then
    echo "Every recorded deploy is the version running now — name the commit to go back to (git log --oneline -10)." >&2
    exit 1
  fi
fi

if ! RESOLVED="$(git rev-parse --verify --quiet "${TARGET}^{commit}")"; then
  echo "Commit not found: ${TARGET}" >&2
  exit 1
fi
TARGET="$RESOLVED"

if [[ "$TARGET" == "$CURRENT" ]]; then
  echo "$(git rev-parse --short "$TARGET") is already running — nothing to do."
  exit 0
fi

echo "Running now : $(git log -1 --format='%h  %ad  %s' --date=short "$CURRENT")"
echo "Going back to: $(git log -1 --format='%h  %ad  %s' --date=short "$TARGET")"

# The database question: did anything between the two change a migration?
MIGRATIONS="$(git diff --name-only "$TARGET" "$CURRENT" -- database/migrations)"

if [[ -n "$MIGRATIONS" && "$DATABASE_RESTORED" != true ]]; then
  # The database as it was under the target: the backup taken by the first
  # deploy that replaced it (the first later deploy built on top of it).
  BACKUP=""
  AFTER_TARGET=false
  if [[ -s "$LOG" ]]; then
    while read -r _ kind sha file _; do
      [[ "$kind" == "deploy" ]] || continue
      if [[ "$sha" == "$TARGET" ]]; then AFTER_TARGET=true; BACKUP=""; continue; fi
      if [[ -z "$BACKUP" ]] && { [[ "$AFTER_TARGET" == true ]] || git merge-base --is-ancestor "$TARGET" "$sha" 2>/dev/null; }; then
        BACKUP="$file"
      fi
    done < "$LOG"
  fi
  [[ "$BACKUP" == "none" ]] && BACKUP=""
  STAMP="$(date +%Y%m%d%H%M)"
  cat <<EOF

STOP — the database changed between these versions:
$(echo "$MIGRATIONS" | sed 's/^/  /')

The old code needs the old database. To go back, restore the backup taken
before this deploy (everything entered since it is lost):
  backup: ${BACKUP:-not recorded — use the newest file in ~/backups/cncms from before the deploy}

  1. Landlord -> Maintenance -> start a window, and stop the queue daemon in Ploi.
  2. Restore into a NEW database (the live one stays, in case you change your mind):
       sudo -u postgres createdb -O <DB_USERNAME> cncms_rollback_${STAMP}
       gunzip -c <backup> | PGPASSWORD='<DB_PASSWORD>' psql -h 127.0.0.1 -U <DB_USERNAME> -d cncms_rollback_${STAMP} -q -v ON_ERROR_STOP=1
  3. Ploi -> site -> Environment: DB_DATABASE=cncms_rollback_${STAMP}, save.
  4. Run this again:
       PHP_BIN=${PHP_BIN} bash $0 $(git rev-parse --short "$TARGET") --database-restored
  5. Start the daemon, check, end the maintenance window.

Found after staff have been working on the new version? Prefer a fix
forward instead — see deploy/BLUE-GREEN.md section 4.
EOF
  exit 2
fi

if [[ "$ASSUME_YES" != true ]]; then
  read -r -p "Put $(git rev-parse --short "$TARGET") back now? The site is down for about 2 minutes. [y/N] " answer
  [[ "$answer" == "y" || "$answer" == "Y" ]] || { echo "Nothing changed."; exit 1; }
fi

echo "==> maintenance mode"
"$PHP_BIN" artisan down --retry=15 || true
trap '"$PHP_BIN" artisan up || true' EXIT

echo "==> code"
git reset --hard "$TARGET"

echo "==> composer"
$COMPOSER_BIN install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "==> frontend build"
npm ci --no-audit --no-fund
npm run build

echo "==> cache + workers"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart

echo "==> up"
"$PHP_BIN" artisan up
trap - EXIT

echo "$(date -Is) rollback ${TARGET} from:${CURRENT}" >> "$LOG"

cat <<EOF

Done: $(git rev-parse --short HEAD) is running.
  - Ploi -> server -> PHP -> restart PHP-FPM now (it caches the old files).
  - Ploi's next deploy pulls main again, the broken version. Keep Quick deploy
    off until main is fixed (on dev: git revert, push, check on preview).
EOF
