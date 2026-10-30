#!/usr/bin/env bash
# CNCMS — copy the backups OFF the server, to Backblaze B2, encrypted.
# Cron, as the site user, after the nightly backup-db.sh (deploy/PLOI.md §5c):
#   45 3 * * *  bash ~/cncms.tech/deploy/scripts/offsite-backup.sh
#
# Copies, encrypted with your passphrase before anything leaves the server:
#   - the database dumps in $BACKUP_DIR (default ~/backups/cncms)  -> db/
#   - storage/app (issued receipt PDFs, logos, voucher photos)      -> storage/
# It only ever adds: deleting a file on the server never deletes it on B2
# (old copies are removed by the bucket's lifecycle rule, see the runbook).
#
# Settings live in ~/.cncms-offsite.env (chmod 600), never in the repo:
#   B2_KEY_ID=…  B2_APP_KEY=…  B2_BUCKET=…  BACKUP_PASSPHRASE=…
#   HEALTHCHECK_URL=https://hc-ping.com/…   (optional: emails you when a night is missed)
# Lose BACKUP_PASSPHRASE and the copies can't be opened — keep it in your
# password manager too, with the app's APP_KEY.
#
# Other uses:
#   offsite-backup.sh --install          install rclone into ~/bin (no root needed)
#   offsite-backup.sh --list             what is on B2
#   offsite-backup.sh --fetch FILE DIR   download and decrypt one dump, e.g. db/cncms-2026-10-30-0315.sql.gz
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

for name in B2_KEY_ID B2_APP_KEY B2_BUCKET BACKUP_PASSPHRASE; do
  if [[ -z "${!name:-}" ]]; then
    echo "${name} is missing from ${SETTINGS}." >&2
    exit 1
  fi
done

# Two remotes from environment variables (no rclone.conf on disk): `b2` is the
# bucket, `vault` encrypts everything written through it into b2:<bucket>/cncms.
export RCLONE_CONFIG_B2_TYPE=b2
export RCLONE_CONFIG_B2_ACCOUNT="$B2_KEY_ID"
export RCLONE_CONFIG_B2_KEY="$B2_APP_KEY"
export RCLONE_CONFIG_VAULT_TYPE=crypt
export RCLONE_CONFIG_VAULT_REMOTE="b2:${B2_BUCKET}/cncms"
export RCLONE_CONFIG_VAULT_PASSWORD="$("$RCLONE" obscure "$BACKUP_PASSPHRASE")"
export RCLONE_CONFIG_VAULT_PASSWORD2="$("$RCLONE" obscure "cncms-salt-${BACKUP_PASSPHRASE}")"
# File names and contents are encrypted; the two folder names (db/, storage/)
# stay readable so the bucket's lifecycle rule can expire old dumps only.
export RCLONE_CONFIG_VAULT_DIRECTORY_NAME_ENCRYPTION=false

ping_health() {
  [[ -n "${HEALTHCHECK_URL:-}" ]] && curl -fsS -m 10 --retry 3 -o /dev/null "${HEALTHCHECK_URL}$1" || true
}

case "${1:-}" in
  --list)
    "$RCLONE" lsl vault: | sort -k2,3
    exit 0
    ;;
  --fetch)
    [[ -n "${2:-}" && -n "${3:-}" ]] || { echo "Usage: $0 --fetch db/<file>.sql.gz <directory>" >&2; exit 1; }
    "$RCLONE" copy "vault:$2" "$3"
    echo "Decrypted copy in $3/$(basename "$2")"
    exit 0
    ;;
  "") ;;
  *) echo "Unknown option: $1" >&2; exit 1 ;;
esac

trap 'log "FAILED (line $LINENO)"; ping_health /fail' ERR
ping_health /start

NEWEST="$(ls -1t "$BACKUPS"/cncms-*.sql.gz 2>/dev/null | head -n 1 || true)"
if [[ -z "$NEWEST" || -n "$(find "$NEWEST" -mmin +1500)" ]]; then
  # No dump from the last ~day: the nightly backup itself is not running.
  log "FAILED: no database dump newer than 25 hours in ${BACKUPS} — is backup-db.sh running?"
  ping_health /fail
  exit 1
fi

"$RCLONE" copy "$BACKUPS" vault:db --include 'cncms-*.sql.gz' --transfers 2 --retries 5 --low-level-retries 10
"$RCLONE" copy "$APP_DIR/storage/app" vault:storage --exclude '.gitignore' --transfers 4 --retries 5 --low-level-retries 10

# Proof it arrived: the newest dump is on B2 at the same size.
REMOTE_SIZE="$("$RCLONE" size "vault:db/$(basename "$NEWEST")" --json | sed -n 's/.*"bytes":\([0-9]*\).*/\1/p')"
LOCAL_SIZE="$(stat -c %s "$NEWEST")"
if [[ "$REMOTE_SIZE" != "$LOCAL_SIZE" ]]; then
  log "FAILED: $(basename "$NEWEST") is ${LOCAL_SIZE} bytes here but ${REMOTE_SIZE:-missing} on B2"
  ping_health /fail
  exit 1
fi

log "ok  $(basename "$NEWEST") ($(du -h "$NEWEST" | cut -f1)) and storage/app copied to B2"
ping_health ""
