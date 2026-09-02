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

Pick **one** path. Both are already documented in this folder; this is just
which one to run.

### A1. Laravel Cloud (recommended to "start now")

Fastest route to a public HTTPS URL. Full config in
[`LARAVEL-CLOUD.md`](./LARAVEL-CLOUD.md). Short version:

1. The app is already uploaded to Laravel Cloud. In the environment's
   **Environment** panel set the variables from
   [`.env.production.example`](./.env.production.example) — critically:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY=base64:…`
   - `APP_URL=https://<your-cloud-url>` (or a custom domain once attached)
   - `DB_URL=` Supabase **session pooler** string (port 5432 — *not* the
     transaction pooler, it breaks schema-per-tenant tenancy)
   - `SANCTUM_STATEFUL_DOMAINS=<host of APP_URL>` (web admin only; the
     mobile app uses Bearer tokens and doesn't need this)
   - `QUEUE_CONNECTION=database`, `CACHE_STORE=redis` (Upstash), `SESSION_DRIVER=database`
2. Deploy commands (dashboard → Deployments → Commands):
   ```
   php artisan migrate --force
   php artisan tenants:migrate --force
   php artisan optimize
   ```
   `tenants:migrate` is the one people forget — central `migrate` does not
   touch tenant schemas.
3. Add a **Worker** resource:
   ```
   php artisan queue:work --queue=default,manuscripts,bills --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=1800
   ```
   Without it, manuscript runs and bill generation sit `queued` forever.
4. Make the first landlord + assign yourself a role in the workspace
   (Commands panel):
   ```
   php artisan cncms:grant-landlord you@example.com --create --name="You" --password="pick-one"
   php artisan cncms:tenant-role swecom you@example.com super
   ```
5. Run the testing checklist at the bottom of `LARAVEL-CLOUD.md`.

**swecom's live data** (≈450 customers, manuscripts, payments) stays on the
dev box for now — validate the deploy with a small xlsx import first. The
real data migration is a separate scripted job (`LARAVEL-CLOUD.md` §5).

### A2. Bare Ubuntu VPS

More control, more steps. Full runbook in [`../DEPLOYMENT.md`](../DEPLOYMENT.md)
+ the scripts in this folder (`provision.sh`, `deploy.sh`, `nginx/`,
`systemd/`, `cron/`). Decisions already made: Ubuntu 24.04, one box,
PostgreSQL on the same server, nightly `pg_dump` copied off-server.

Either way, the deliverable of Phase A is: **a public `https://…` URL where
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
        "EXPO_PUBLIC_API_BASE_URL": "https://<your-cloud-url>/api/v1"
      }
    },
    "production": {
      "autoIncrement": true,
      "env": {
        "EXPO_PUBLIC_API_BASE_URL": "https://<your-cloud-url>/api/v1"
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
the **backend** host (Laravel Cloud env panel, or the VPS `.env`):

```
AGENT_APP_ANDROID_URL=https://expo.dev/accounts/<acct>/projects/cncms-mobile/builds/<id>
AGENT_APP_VERSION=1.0.0
AGENT_APP_UPDATED_ON=2026-09-02
```

Then `php artisan config:clear` (VPS) / redeploy (Cloud). The page now shows
the button + a QR agents scan with their phone camera. It's visible to
super / admin / manager / agent (same gate as Reports); workers don't see
the nav link.

**Alternative APK hosts** if you don't want to point agents at expo.dev:
- Download the `.apk` from the build page and drop it in
  `public/downloads/cncms-agent.apk` on the server, set
  `AGENT_APP_ANDROID_URL=https://<your-domain>/downloads/cncms-agent.apk`.
  (Use `public/`, never `storage/app` — that's wiped on Cloud redeploys.)
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

| Change | How it ships | Agent action |
|---|---|---|
| JS/TS only (bug fix, copy, screen logic) | `eas update --branch preview` | none — picked up on next app open |
| New native module, permission, SDK/Expo bump, app icon | `eas build` again (Phase B) + update `AGENT_APP_ANDROID_URL` (Phase C) | reinstall the APK |

Bump `version` in `mobile/app.config.ts` and `AGENT_APP_VERSION` together
so the `/agent-app` page shows agents whether they're current.

For EAS Update to work, the `preview` build profile and the update branch
name must match (both `preview` here) — that's already the case.

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
| Laravel Cloud + Supabase + Upstash | free tiers work for the pilot; see `LARAVEL-CLOUD.md` §0/§6 for when to upgrade |
