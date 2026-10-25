# Getting the CNCMS Field Agent app onto agents' phones

The mobile app (`mobile/`, React Native + Expo) is feature-complete: login,
customers list/detail, record payment, record expense, receipt photos,
manuscript view, disconnections/reconnections, complaints, arrears requests,
push notifications, offline SQLite + sync. It is **not on the Play Store**
— agents install a build directly (`/agent-app` page in the web admin hands
them the link + a QR + install steps).

Nothing here can happen until **the backend is reachable over the internet**.
Today it only runs on the dev box (`127.0.0.1`). That is the one hard
blocker — everything else is fast.

```
Phase A  Deploy the backend (public HTTPS URL)         ← the blocker
   │
Phase B  Point the app at that URL + build the APK     ← ~30 min once A is done
   │
Phase C  Publish the APK link on /agent-app            ← 2 env vars
   │
Phase D  Create agent accounts + assign roles          ← Users Control Center
   │
Phase E  Agents install and log in
```

Later JS-only fixes ship over the air (EAS Update) — no reinstall. Native
changes (new permissions, SDK bumps) need a new APK (Phase B again).

---

## Phase A — Deploy the backend

Production is a **Hostinger VPS managed by Ploi**. Follow [`PLOI.md`](./PLOI.md):
server, site, `.env` (with the dev `APP_KEY`), deploy script (includes
`tenants:migrate`), the **queue-worker daemon**, the scheduler, SSL, then the
swecom cutover (`tenant:export` on dev → `tenant:import` on the server) before
anyone registers.

The deliverable of Phase A is: **a public `https://…` URL where
`/login` loads and `/api/v1/auth/login` answers.**

---

## Phase B — Point the app at the API and build the APK

The API URL is **compile-time inlined** into the JS bundle
(`EXPO_PUBLIC_API_BASE_URL`, see `mobile/src/api/config.ts`). Changing it
later means a rebuild, not an env edit — so set it right before building.

### B1. One-time setup (already done on this project)

- Expo account exists (`@miskhan`), EAS project id is in
  `mobile/app.config.ts` (`extra.eas.projectId`).
- `npm i -g eas-cli` on whatever machine runs the build, then `eas login`.

### B2. Set the production API URL for the build

`mobile/.env` is gitignored (machine-specific), so pin the URL in
`mobile/eas.json` instead so the build is reproducible:

```jsonc
{
  "build": {
    "preview": {
      "distribution": "internal",
      "env": {
        "EXPO_PUBLIC_API_BASE_URL": "https://YOUR_DOMAIN/api/v1"
      }
    },
    "production": {
      "autoIncrement": true,
      "env": {
        "EXPO_PUBLIC_API_BASE_URL": "https://YOUR_DOMAIN/api/v1"
      }
    }
  }
}
```

(The `/api/v1` suffix matters — `config.ts` expects the full base.)

### B3. Build

```
cd mobile
eas build --platform android --profile preview
```

- `preview` profile = `distribution: internal` → produces a **plain
  installable `.apk`** (not an `.aab`), hosted on a build page at
  `https://expo.dev/accounts/<acct>/projects/cncms-mobile/builds/<id>`.
- First build takes ~10–20 min on EAS's servers (no local Android SDK
  needed). It generates an Android keystore and keeps it — reuse it for
  every later build so updates install over the top.
- When it finishes EAS prints the build page URL and a direct `.apk` URL.

### B4. Smoke-test the build yourself

Install it on one phone (scan the QR on the build page), log in with a real
account against the deployed backend, record a test payment, pull sync.
Confirm the API URL took (a wrong URL = "network error" on login).

---

## Phase C — Publish the download link

The web admin already has the distribution page at **`/agent-app`**
(`config/agent-app.php`, env-driven — no deploy needed to update). Set on
the **backend** host (Ploi → site → Environment):

```
AGENT_APP_ANDROID_URL=https://expo.dev/accounts/<acct>/projects/cncms-mobile/builds/<id>
AGENT_APP_VERSION=1.0.0
AGENT_APP_UPDATED_ON=2026-09-02
```

Then `php artisan optimize` (or redeploy from Ploi). The page now shows
the button + a QR agents scan with their phone camera. It's visible to
super / admin / manager / agent (same gate as Reports); workers don't see
the nav link.

**Alternative APK hosts** if you don't want to point agents at expo.dev:
- Download the `.apk` from the build page and drop it in
  `public/downloads/cncms-agent.apk` on the server, set
  `AGENT_APP_ANDROID_URL=https://<your-domain>/downloads/cncms-agent.apk`.
  (Use `public/`, never `storage/app` — it isn't web-served.)
- Or an S3/R2 bucket with a public object URL.

---

## Phase D — Agent accounts and roles

Each agent needs a CNCMS user in the workspace:

1. **Users Control Center → Users** → add each agent (name, email/username,
   password). They authenticate to the API with these same credentials.
2. Assign the **agent** role (or a custom role). The agent role is
   zone-fenced — an agent sees/acts only on their own zone's customers,
   including on mobile (`SyncService` applies the same zone fence to the
   pull). Set the agent's zone on their user record.
3. If an agent records payments for a tier that needs it, the
   `can_record_payments` flag is on the worker rows only — agents already
   have `payments.create`.

There is no self-service signup for agents — accounts are created by an
admin.

---

## Phase E — Agent install (what to tell them)

The `/agent-app` page spells this out, but in short:

1. On the phone, open **Settings → Security → Install unknown apps** and
   allow the browser (or Files app) you'll download with. Android blocks
   sideloaded APKs until you do this once.
2. Scan the QR on `/agent-app` (or open the link the admin sends).
3. Download the `.apk`, tap it, **Install**.
4. Open **CNCMS Field Agent**, log in with the credentials the admin set.
5. First launch pulls the agent's zone data; after that it works offline
   and syncs when there's signal.

Requirements: Android 7.0+, ~100 MB free, camera permission (receipt
photos), notifications permission (payment-verification alerts).

---

## Ongoing — updates

Over-the-air updates work from app version **1.0.1** on (`expo-updates`
added 2026-10-25 — builds before that have no updater and must install
1.0.1 by hand once).

| Change | How it ships | Agent action |
|---|---|---|
| JS/TS only (bug fix, copy, screen logic) | `cd mobile && npm run publish:update -- "what changed"` | none — downloads in the background, applies on the next app open |
| New native module, permission, SDK/Expo bump, app icon | bump `version` in `mobile/app.config.ts`, `eas build --platform android --profile preview`, replace `public/downloads/cncms-agent.apk`, then set `AGENT_APP_VERSION` + `AGENT_APP_UPDATED_ON` and `php artisan config:cache` | tap the blue "Version X is available" banner, install |

- Always publish with `npm run publish:update`, never a bare `eas update`:
  `eas update` ignores eas.json's build `env`, so a bare run would bake the
  development API address into the update and stop every phone syncing.
- The runtime version is the native **fingerprint**: an update only lands
  on builds with identical native code, so it can never crash an older APK.
- Order for a new APK: put the new file in place **first**, then raise
  `AGENT_APP_VERSION` — the banner links straight to the download.
- The banner compares the installed APK's own version (over-the-air updates
  never change it) with `AGENT_APP_VERSION`, sent on every API response as
  `X-App-Latest-Version` / `X-App-Download-Url` (`ResolveTenant`).

---

## iOS (deferred)

`ios_url` stays null and the `/agent-app` page hides the iOS option. iOS
needs an Apple Developer account (**$99/year, mandatory** — no free
sideloading like Android) plus a TestFlight or ad-hoc build. Android's
share of the Cameroon market is high enough that this was deliberately not
a v1 priority (`mobile-app-react-native.md` §1). Revisit only if a
specific user needs it.

## Cost summary

| Item | Cost |
|---|---|
| Android APK via EAS internal distribution | free (EAS free tier: limited concurrent builds, fine at this scale) |
| Google Play listing (optional, not needed) | $25 one-time |
| Apple Developer (only if iOS) | $99/year |
| Hostinger VPS + Ploi | the VPS plan + Ploi subscription; one box runs everything |
