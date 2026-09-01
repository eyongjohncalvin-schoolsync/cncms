# Deployment, Hosting & the Landlord Context — prep notes

Status: **Deployment kit BUILT 2026-09-01 — not yet run against a real server.** The landlord
context is still unfinished (§3).

**Decisions made (2026-09-01):** bare Ubuntu 24.04 VPS, manual setup (no Forge/Ploi) · one box
for everything · PostgreSQL **on the same server** · files on local disk, nightly `pg_dump`
copied **off-server** by the owner · the owner **has a domain** (value not yet in the repo —
`YOUR_DOMAIN` placeholder throughout).

**The kit lives in the repo:** `DEPLOYMENT.md` (the runbook) + `deploy/` (provision.sh,
deploy.sh, backup-db.sh, nginx/cncms.conf, systemd/cncms-worker.service, cron/cncms.cron,
sudoers.d/cncms, .env.production.example). `config/tenancy.php`'s `central_domains` now reads
`CENTRAL_DOMAIN` / the `APP_URL` host so the domain is env-only.

Web + API resolve the tenant from the **logged-in user's membership**
(`ResolveTenant`/`ResolveTenantWeb` via `TenantUserIndex`), NOT the request domain — so ONE
domain serves the app, the landlord area, and registration. No per-tenant subdomains needed.

Deploy target branch is `prepayment-drawdown-credit` until it's merged to `main`
(`deploy/scripts/deploy.sh` has the branch name).

---

## 1. What "deployment and hosting" has to cover (all handled in the kit unless noted)

The app runs only locally today (`127.0.0.1:8000`, Herd on the owner's Windows box; standalone
PostgreSQL 18 at `C:\Program Files\PostgreSQL\18`, NOT Herd's bundled one). To go live:

- **Server / host** — undecided. Cameroon-hosted vs. a VPS (Hetzner/DO/etc.) matters for
  customer-facing latency and for MOMO/SMS provider reachability. Ask the owner.
- **PHP 8.4 + PostgreSQL 18** on the host (the codebase is on 8.4 despite SKILL.md's older
  "8.3 / PG 16" line — the `php84` Herd binary is what everything runs against).
- **Queue worker** — `QUEUE_CONNECTION=database`. A supervised `php artisan queue:work` is
  **mandatory in production**, not optional: the manuscript run (`Bus::batch` via
  `ManuscriptGenerationBatchService`) and async bill generation (`BillBatchService`) both need
  it. Without a worker, "Run Manuscript Calculation" and "Generate Bills" sit at `queued`
  forever. Plan supervisor/systemd + `--stop-when-empty` cron fallback, or Horizon.
- **Scheduler** — `php artisan schedule:run` every minute (cron) for `tasks:run-due` and any
  Laravel scheduled tasks. See `task-scheduler.md`.
- **Storage** — `local` disk (`storage/app`) currently holds bill-batch PDFs
  (`bill-batches/{tenantId}/{uuid}/…`) and Spatie media (company logos). On a multi-server or
  ephemeral host this needs S3-compatible object storage or a persistent volume. Decide before
  scaling past one box.
- **Tenancy** — schema-per-tenant in one physical DB. `central_domains` in `config/tenancy.php`
  currently `[…]` (check). Real tenants resolve by domain (`swecom.localhost` today → a real
  domain). The landlord area must NOT be on a tenant domain (see §3).
- **Assets** — `npm run build` (Vite) for production; there is no SSR server, Inertia is
  client-rendered from the build manifest. Confirm the deploy pipeline runs the build.
- **.env** — `APP_ENV=production`, `APP_DEBUG=false`, real `APP_KEY`, mail transport, session
  driver, MOMO/Twilio creds (per-tenant Twilio is design-only — see `bill-notifications.md`).
- **Backups** — `backup-strategy.md` is design-only. A real `pg_dump` schedule + offsite copy
  is a launch blocker for a system holding a cable operator's billing history.
- **HTTPS** — Sanctum SPA mode is cookie/session based; needs `SESSION_SECURE_COOKIE`,
  correct `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` for the real domain(s), and the mobile
  app's `EXPO_PUBLIC_API_BASE_URL` pointing at the production API (it's compile-time inlined —
  a change needs an EAS rebuild, not just an env edit).

## 2. Pre-launch checklist items already handled this cycle

- Orphaned disposable test tenants dropped (`php artisan tenants:prune-disposable --force`,
  commit `fd2f73ab`). Only `swecom` and `multimedia-digital-cable-network` remain. The command
  hard-codes that 2-tenant allowlist and is dry-run by default — safe to re-run if test
  tenants accumulate again before launch.
- `.gitignore` hardened for mobile `.env` (commit `b0004988`).
- September 2026 manuscript is published on `swecom`; the monthly cycle is live (see
  `project-manuscript-monthly-cycle` memory).

## 3. The Landlord context — what exists, what's missing

The landlord ("central / platform") area is where ShalomTech staff manage the `tenants` table
itself — distinct from each tenant's own admin panel.

**Built:**
- `users.is_landlord` (boolean, central `users` table, migration `2026_08_24_040147`), plus
  `landlord_granted_by` / `landlord_granted_at`. **Deliberately NOT in `User`'s `#[Fillable]`**
  — must be set by direct property assignment in an explicit audited action.
- `App\Http\Middleware\EnsureLandlord` — `abort 403` unless `$user->is_landlord`. Never
  initializes tenancy.
- `routes/web/landlord.php` — `['auth','landlord']`, prefix `landlord`:
  `GET/POST tenants`, `GET tenants/create`, `GET tenants/{tenant}/edit`, `PATCH tenants/{tenant}`,
  `POST tenants/{tenant}/approve`, `POST tenants/{tenant}/reject`.
- `App\Http\Controllers\Landlord\TenantController` — index (filter by
  `registration_status` = pending/approved/rejected), create, store, edit, update
  (`is_active` toggle + `bulk_whatsapp_enabled` entitlement), approve, reject (with reason).
  `registration_status` / `is_active` / `rejection_reason` / `bulk_whatsapp_enabled` are Stancl
  `VirtualColumn` attributes on `tenants.data` JSON.
- `resources/tsx/pages/Landlord/Tenants/{Index,Create,Edit}.tsx`.
- `AuthController` redirects a non-landlord away from an intended `/landlord…` path.
- `HandleInertiaRequests` exposes `auth.user.is_landlord` to the frontend.
- Self-service tenant onboarding (`self-service-onboarding.md`, `WorkspaceProvisioningService`)
  creates tenants in `registration_status = 'pending'` for a landlord to approve.

**Missing / "finish the landlord context" (confirm scope with the owner):**
- **No way to grant `is_landlord` from the UI.** No admin action, no seeded landlord user on a
  fresh install. Today it is a manual `tinker` write (the owner lost access mid-session and had
  to be given the tinker one-liners). Needs either a seeder for the first platform admin + init
  flow, or a landlord-only "platform staff" management screen (grant/revoke `is_landlord`,
  audited like the Investor grant in `SettingsUserController`).
- **No landlord layout / nav / dashboard.** The landlord pages reuse the tenant `AppLayout`?
  Check. A platform-level shell (tenant count, pending approvals, recent signups, per-tenant
  health) is the natural home.
- **Landlord auth entry point.** The landlord logs in through the same tenant login page today.
  Decide whether the landlord area gets its own host/subdomain (it should NOT be reachable on a
  tenant's domain) and its own login.
- **Per-tenant entitlements** beyond `bulk_whatsapp_enabled` — the Investor tier, feature flags,
  billing/plan. `bill-notifications.md` §3 argues against a `tenant_entitlements` table until
  several flags accumulate; revisit at launch.
- **Landlord audit** — tenant approve/reject/deactivate should be audited. Check whether
  `AuditableObserver` covers the central `tenants` table (it is tenant-scoped by design; the
  landlord acts outside tenancy).

## 4. Open questions for the owner

1. Where is it hosted, and in which country?
2. One server to start, or already planning multi-server (→ decides storage: volume vs S3)?
3. Custom domain(s)? One apex for the landlord + subdomains per tenant, or a domain per tenant?
4. Who is the first/only platform admin (`is_landlord`)? Seed them how?
5. Managed Postgres or self-managed? Backup retention target?
6. Queue: plain `queue:work` under supervisor, or Horizon (needs Redis)?
