#!/usr/bin/env bash
# CNCMS — copy the backups OFF the server, encrypted, to Backblaze B2 and/or
# Google Drive. Cron, as the site user, after the nightly backup-db.sh
# (deploy/PLOI.md §5c):
#   45 3 * * *  bash ~/cncms.tech/deploy/scripts/offsite-backup.sh
#
# Copies, encrypted with your passphrase before anything leaves the server:
#   - the database dumps in $BACKUP_DIR (default ~/backups/cncms)  -> db/
#   - storage/app (issued receipt PDFs, logos, voucher photos)      -> storage/
# to every destination set up in the settings. It only adds: deleting a file
# on the server never deletes it off the server. Old dumps go by B2's
# lifecycle rule, and on Google Drive after GDRIVE_KEEP_DAYS (Drive has none).
#
# Settings live in ~/.cncms-offsite.env (chmod 600), never in the repo:
#   BACKUP_PASSPHRASE=…                                   (always)
#   B2_KEY_ID=…  B2_APP_KEY=…  B2_BUCKET=…                (Backblaze B2)
#   GDRIVE_TOKEN='{"access_token":…}'                     (Google Drive — from `rclone authorize "drive"`)
#   GDRIVE_FOLDER=cncms-backups  GDRIVE_KEEP_DAYS=60      (optional)
#   HEALTHCHECK_URL=https://hc-ping.com/…                 (optional: emails you when a night is missed)
# Lose BACKUP_PASSPHRASE and the copies can't be opened — keep it in your
# password manager too, with the app's APP_KEY.
#
# Other uses:
#   offsite-backup.sh --install                  install rclone into ~/bin (no root needed)
#   offsite-backup.sh --list [b2|gdrive]         what is there
#   offsite-backup.sh --fetch FILE DIR [b2|gdrive]   download and decrypt, e.g. db/cncms-2026-10-30-0315.sql.gz
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SETTINGS="${OFFSITE_SETTINGS:-$HOME/.cncms-offsite.env}"
BACKUPS="${BACKUP_DIR:-$HOME/backups/cncms}"
LOG="${OFFSITE_LOG:-$HOME/cncms-offsite.log}"
RCLONE="${RCLONE_BIN:-$(command -v rclone || echo "$HOME/bin/rclone")}"

log() { echo "$(date -Is)  $*" | tee -a "$LOG"; }

if [[ "${1:-}" == "--install" ]]; then
  case "$(uname -m)" in
    x86_64) arch=amd64 ;;
    aarch64|arm64) arch=arm64 ;;
    *) echo "Unknown CPU $(uname -m) — install rclone by hand: https://rclone.org/install/" >&2; exit 1 ;;
  esac
  tmp="$(mktemp -d)"
  curl -fsSL -o "$tmp/rclone.zip" "https://downloads.rclone.org/rclone-current-linux-${arch}.zip"
  unzip -q "$tmp/rclone.zip" -d "$tmp"
  mkdir -p "$HOME/bin"
  install -m 755 "$tmp"/rclone-*-linux-"${arch}"/rclone "$HOME/bin/rclone"
  rm -rf "$tmp"
  "$HOME/bin/rclone" version | head -n 1
  exit 0
fi

if [[ ! -x "$RCLONE" ]]; then
  echo "rclone is not installed — run: bash $0 --install" >&2
  exit 1
fi

if [[ ! -r "$SETTINGS" ]]; then
  echo "No settings file ${SETTINGS} — see the top of this script and deploy/PLOI.md §5c." >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a; source "$SETTINGS"; set +a

if [[ -z "${BACKUP_PASSPHRASE:-}" ]]; then
  echo "BACKUP_PASSPHRASE is missing from ${SETTINGS}." >&2
  exit 1
fi

# rclone has no settings file of its own here — everything comes from the
# environment variables below (its "config file not found" notice is harmless).

# Every destination gets an encrypting remote on top. File names and
# contents are encrypted; the two folder names (db/, storage/) stay readable
# so B2's lifecycle rule can expire old dumps only.
PASSWORD="$("$RCLONE" obscure "$BACKUP_PASSPHRASE")"
PASSWORD2="$("$RCLONE" obscure "cncms-salt-${BACKUP_PASSPHRASE}")"
DESTINATIONS=()

encrypted() { # name, remote it writes into
  local upper="${1^^}"
  export "RCLONE_CONFIG_${upper}_TYPE=crypt"
  export "RCLONE_CONFIG_${upper}_REMOTE=$2"
  export "RCLONE_CONFIG_${upper}_PASSWORD=$PASSWORD"
  export "RCLONE_CONFIG_${upper}_PASSWORD2=$PASSWORD2"
  export "RCLONE_CONFIG_${upper}_DIRECTORY_NAME_ENCRYPTION=false"
}

if [[ -n "${B2_KEY_ID:-}" || -n "${B2_APP_KEY:-}" || -n "${B2_BUCKET:-}" ]]; then
  for name in B2_KEY_ID B2_APP_KEY B2_BUCKET; do
    [[ -n "${!name:-}" ]] || { echo "${name} is missing from ${SETTINGS}." >&2; exit 1; }
  done
  export RCLONE_CONFIG_B2_TYPE=b2
  export RCLONE_CONFIG_B2_ACCOUNT="$B2_KEY_ID"
  export RCLONE_CONFIG_B2_KEY="$B2_APP_KEY"
  encrypted vault "b2:${B2_BUCKET}/cncms"   # `vault` = B2, the name the first setup used
  DESTINATIONS+=(b2)
fi

if [[ -n "${GDRIVE_TOKEN:-}" ]]; then
  export RCLONE_CONFIG_GDRIVE_TYPE=drive
  # Only the files rclone itself creates — never the rest of the Drive.
  export RCLONE_CONFIG_GDRIVE_SCOPE=drive.file
  export RCLONE_CONFIG_GDRIVE_TOKEN="$GDRIVE_TOKEN"
  encrypted gvault "gdrive:${GDRIVE_FOLDER:-cncms-backups}"
  DESTINATIONS+=(gdrive)
fi

if [[ ${#DESTINATIONS[@]} -eq 0 ]]; then
  echo "No destination in ${SETTINGS} — set the B2_… lines, GDRIVE_TOKEN, or both." >&2
  exit 1
fi

remote_of() { [[ "$1" == "gdrive" ]] && echo gvault || echo vault; }

# --list / --fetch: the destination named last, else the first set up.
pick() {
  local wanted="${1:-${DESTINATIONS[0]}}"
  [[ " ${DESTINATIONS[*]} " == *" ${wanted} "* ]] || { echo "${wanted} is not set up in ${SETTINGS}." >&2; exit 1; }
  remote_of "$wanted"
}

ping_health() {
  [[ -n "${HEALTHCHECK_URL:-}" ]] && curl -fsS -m 10 --retry 3 -o /dev/null "${HEALTHCHECK_URL}$1" || true
}

case "${1:-}" in
  --list)
    remote="$(pick "${2:-}")"   # on its own line: a refusal stops the script here
    "$RCLONE" lsl "${remote}:" | sort -k2,3
    exit 0
    ;;
  --fetch)
    [[ -n "${2:-}" && -n "${3:-}" ]] || { echo "Usage: $0 --fetch db/<file>.sql.gz <directory> [b2|gdrive]" >&2; exit 1; }
    remote="$(pick "${4:-}")"
    "$RCLONE" copy "${remote}:$2" "$3"
    echo "Decrypted copy in $3/$(basename "$2")"
    exit 0
    ;;
  "") ;;
  *) echo "Unknown option: $1" >&2; exit 1 ;;
esac

ping_health /start

NEWEST="$(ls -1t "$BACKUPS"/cncms-*.sql.gz 2>/dev/null | head -n 1 || true)"
if [[ -z "$NEWEST" || -n "$(find "$NEWEST" -mmin +1500)" ]]; then
  # No dump from the last ~day: the nightly backup itself is not running.
  log "FAILED: no database dump newer than 25 hours in ${BACKUPS} — is backup-db.sh running?"
  ping_health /fail
  exit 1
fi

# One destination failing never stops the other; the night fails if either did.
copy_to() {
  local destination="$1" remote
  remote="$(remote_of "$destination")"

  "$RCLONE" copy "$BACKUPS" "${remote}:db" --include 'cncms-*.sql.gz' --transfers 2 --retries 5 --low-level-retries 10 || return 1
  "$RCLONE" copy "$APP_DIR/storage/app" "${remote}:storage" --exclude '.gitignore' --transfers 4 --retries 5 --low-level-retries 10 || return 1

  # Proof it arrived: the newest dump is there at the same size.
  local remote_size local_size
  remote_size="$("$RCLONE" size "${remote}:db/$(basename "$NEWEST")" --json | sed -n 's/.*"bytes":\([0-9]*\).*/\1/p')"
  local_size="$(stat -c %s "$NEWEST")"
  if [[ "$remote_size" != "$local_size" ]]; then
    log "FAILED ${destination}: $(basename "$NEWEST") is ${local_size} bytes here but ${remote_size:-missing} there"
    return 1
  fi

  # Drive keeps everything forever and shares its 15 GB with Gmail: old dumps go.
  if [[ "$destination" == "gdrive" ]]; then
    "$RCLONE" delete "${remote}:db" --min-age "${GDRIVE_KEEP_DAYS:-60}d" --drive-use-trash=false || return 1
  fi

  log "ok  ${destination}: $(basename "$NEWEST") ($(du -h "$NEWEST" | cut -f1)) and storage/app"
}

failed=0
for destination in "${DESTINATIONS[@]}"; do
  copy_to "$destination" || { log "FAILED ${destination}"; failed=1; }
done

if [[ $failed -ne 0 ]]; then
  ping_health /fail
  exit 1
fi

ping_health ""
