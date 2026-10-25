#!/usr/bin/env bash
# CNCMS — put a new Field Agent APK live, in the right order. Two ways:
#
#   In the deploy script (Ploi), every deploy:
#     bash deploy/scripts/publish-apk.sh --from-release-file
#   Reads deploy/mobile-release.json (version + APK link, committed with each
#   new build). Already live -> says so and does nothing; newer -> publishes
#   it. Safe to run on every deploy.
#
#   By hand, for a one-off:
#     bash ~/cncms.tech/deploy/scripts/publish-apk.sh "<apk link from eas build>" 1.0.1
#
# Publishing:
# 1. downloads the APK next to the live one and checks it is a real APK,
# 2. keeps the current file as a backup (the last 3 are kept),
# 3. swaps the new file in (one rename — a download in progress never sees
#    half a file),
# 4. only THEN sets AGENT_APP_VERSION / AGENT_APP_UPDATED_ON in .env and
#    refreshes Laravel's config cache — the in-app "new version" banner links
#    straight to the download, so the number must never go up before the file.
#
# Any failure stops with a message and changes nothing (and, under the deploy
# script's set -e, stops the deploy). Undo: the script prints the command.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
# The site's PHP when the server has several (e.g. PHP_BIN=php8.5).
PHP_BIN="${PHP_BIN:-php}"
DOWNLOADS="$APP_DIR/public/downloads"
LIVE="$DOWNLOADS/cncms-agent.apk"
NEW="$DOWNLOADS/cncms-agent.apk.new"
ENV_FILE="$APP_DIR/.env"
RELEASE_FILE="$APP_DIR/deploy/mobile-release.json"
MIN_BYTES=$((20 * 1024 * 1024))
TODAY="$(date +%Y-%m-%d)"
FROM_RELEASE_FILE=0

current_version() {
  grep -E '^AGENT_APP_VERSION=' "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' || true
}

if [[ "${1:-}" == "--from-release-file" ]]; then
  FROM_RELEASE_FILE=1
  if [[ ! -f "$RELEASE_FILE" ]]; then
    echo "APK: no deploy/mobile-release.json — nothing to publish."
    exit 0
  fi
  URL="$("$PHP_BIN" -r '$r = json_decode((string) file_get_contents($argv[1]), true); echo is_array($r) ? ($r["url"] ?? "") : "";' "$RELEASE_FILE")"
  VERSION="$("$PHP_BIN" -r '$r = json_decode((string) file_get_contents($argv[1]), true); echo is_array($r) ? ($r["version"] ?? "") : "";' "$RELEASE_FILE")"
  if [[ -z "$URL" || -z "$VERSION" ]]; then
    echo "Stopped: deploy/mobile-release.json needs a \"version\" and a \"url\"." >&2
    exit 1
  fi
  if [[ "$(current_version)" == "$VERSION" && -f "$LIVE" ]]; then
    echo "APK $VERSION already live — nothing to do."
    exit 0
  fi
else
  URL="${1:-}"
  VERSION="${2:-}"
  if [[ -z "$URL" || -z "$VERSION" ]]; then
    echo "Usage: bash deploy/scripts/publish-apk.sh \"<apk link>\" <version, e.g. 1.0.1>" >&2
    echo "   or: bash deploy/scripts/publish-apk.sh --from-release-file" >&2
    exit 1
  fi
fi

if [[ ! "$URL" =~ ^https:// ]]; then
  echo "Stopped: the APK link must start with https://" >&2
  exit 1
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Stopped: the version must look like 1.0.1 — the same as mobile/app.config.ts." >&2
  exit 1
fi

mkdir -p "$DOWNLOADS"
trap 'rm -f "$NEW"' EXIT

echo "Downloading APK $VERSION…"
if ! curl -fL --retry 3 --retry-delay 5 --progress-bar -o "$NEW" "$URL"; then
  echo "Stopped: the APK could not be downloaded (an expired link? Expo keeps builds for a limited time — rebuild and update deploy/mobile-release.json). Nothing was changed." >&2
  exit 1
fi

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

BACKUP=""
OLD_VERSION="$(current_version)"

if [[ -f "$LIVE" ]] && [[ "$(sha256sum < "$NEW")" == "$(sha256sum < "$LIVE")" ]]; then
  # The file is already the live one. By hand that's a mistake; from the
  # release file it only means the version number still needs announcing.
  if (( FROM_RELEASE_FILE == 0 )); then
    echo "Stopped: this is the same file that is already live. Nothing was changed." >&2
    exit 1
  fi
  rm -f "$NEW"
  echo "APK file already in place."
else
  if [[ -f "$LIVE" ]]; then
    BACKUP="$DOWNLOADS/cncms-agent-${OLD_VERSION:-old}-$(date +%Y%m%d-%H%M).apk"
    cp -p "$LIVE" "$BACKUP"
    # Keep the 3 newest backups.
    ls -1t "$DOWNLOADS"/cncms-agent-*.apk 2>/dev/null | tail -n +4 | xargs -r rm -f
  fi

  mv -f "$NEW" "$LIVE"
  echo "New APK is live: $(du -h "$LIVE" | cut -f1)"
fi
trap - EXIT

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
"$PHP_BIN" artisan config:cache > /dev/null

echo "Done: APK $VERSION announced (updated $TODAY). Phones on an older build now see the update banner."
if [[ -n "$BACKUP" ]]; then
  echo "To undo:  cp \"$BACKUP\" \"$LIVE\"  — then set AGENT_APP_VERSION back to ${OLD_VERSION:-the old version} and run: $PHP_BIN artisan config:cache"
fi
