#!/usr/bin/env bash
# CNCMS — put a new Field Agent APK live, in the right order. Run on the
# server as the site user, from anywhere:
#
#   bash ~/cncms.tech/deploy/scripts/publish-apk.sh "<apk link from eas build>" 1.0.1
#
# 1. downloads the APK next to the live one and checks it is a real APK,
# 2. keeps the current file as a backup (the last 3 are kept),
# 3. swaps the new file in (one rename — a download in progress never sees
#    half a file),
# 4. only THEN sets AGENT_APP_VERSION / AGENT_APP_UPDATED_ON in .env and
#    refreshes Laravel's config cache — the in-app "new version" banner links
#    straight to the download, so the number must never go up before the file.
#
# Undo: the script prints the exact command for the backup it made.
set -euo pipefail

URL="${1:-}"
VERSION="${2:-}"
TODAY="$(date +%Y-%m-%d)"

if [[ -z "$URL" || -z "$VERSION" ]]; then
  echo "Usage: bash deploy/scripts/publish-apk.sh \"<apk link>\" <version, e.g. 1.0.1>" >&2
  exit 1
fi

if [[ ! "$URL" =~ ^https:// ]]; then
  echo "The link must start with https://" >&2
  exit 1
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "The version must look like 1.0.1 — the same as mobile/app.config.ts." >&2
  exit 1
fi

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DOWNLOADS="$APP_DIR/public/downloads"
LIVE="$DOWNLOADS/cncms-agent.apk"
NEW="$DOWNLOADS/cncms-agent.apk.new"
ENV_FILE="$APP_DIR/.env"
MIN_BYTES=$((20 * 1024 * 1024))

mkdir -p "$DOWNLOADS"
trap 'rm -f "$NEW"' EXIT

echo "Downloading the new APK…"
curl -fL --retry 3 --retry-delay 5 --progress-bar -o "$NEW" "$URL"

SIZE="$(stat -c %s "$NEW")"
if (( SIZE < MIN_BYTES )); then
  echo "Stopped: the download is only $((SIZE / 1024)) KB — not a real APK (expired or wrong link?). Nothing was changed." >&2
  exit 1
fi

# An APK is a zip archive: it starts with "PK" and holds AndroidManifest.xml.
if [[ "$(head -c 2 "$NEW")" != "PK" ]] || ! unzip -l "$NEW" AndroidManifest.xml > /dev/null 2>&1; then
  echo "Stopped: the download is not an APK. Nothing was changed." >&2
  exit 1
fi

if [[ -f "$LIVE" ]] && [[ "$(sha256sum < "$NEW")" == "$(sha256sum < "$LIVE")" ]]; then
  echo "Stopped: this is the same file that is already live. Nothing was changed." >&2
  exit 1
fi

BACKUP=""
if [[ -f "$LIVE" ]]; then
  OLD_VERSION="$(grep -E '^AGENT_APP_VERSION=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d '"' || true)"
  BACKUP="$DOWNLOADS/cncms-agent-${OLD_VERSION:-old}-$(date +%Y%m%d-%H%M).apk"
  cp -p "$LIVE" "$BACKUP"
  # Keep the 3 newest backups.
  ls -1t "$DOWNLOADS"/cncms-agent-*.apk 2>/dev/null | tail -n +4 | xargs -r rm -f
fi

mv -f "$NEW" "$LIVE"
trap - EXIT
echo "New APK is live: $(du -h "$LIVE" | cut -f1)"

# The file is in place — now announce the version.
set_env() {
  local key="$1" value="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}
set_env AGENT_APP_VERSION "$VERSION"
set_env AGENT_APP_UPDATED_ON "$TODAY"

cd "$APP_DIR"
php artisan config:cache > /dev/null

echo "Done: version $VERSION announced (updated $TODAY). Phones on an older build now see the update banner."
if [[ -n "$BACKUP" ]]; then
  echo "To undo:  cp \"$BACKUP\" \"$LIVE\"  — then set AGENT_APP_VERSION back to ${OLD_VERSION:-the old version} and run: php artisan config:cache"
fi
