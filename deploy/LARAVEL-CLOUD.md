# CNCMS on Laravel Cloud

The bare-VPS kit (`../DEPLOYMENT.md`, `provision.sh`, `nginx/`, `systemd/`)
does **not** apply here — Laravel Cloud manages the web server, PHP, TLS,
deploys, the scheduler, and (as a resource you add) the queue worker. This
file is only the CNCMS-specific configuration.

---

## 0. This deployment uses external Supabase + Upstash

Not Laravel Cloud's managed Postgres/Redis — an external Supabase Postgres
and an external Upstash Redis. That means **you set `DB_*` / `REDIS_*` by
hand** (nothing is injected) and there are two mode/limit traps that break
CNCMS:

### Supabase — use the **Session** pooler, never the Transaction pooler

CNCMS is schema-per-tenant (Stancl) and runs `SET search_path` per request.
The Transaction pooler (port `6543`) does not keep session state between
statements, so tenancy silently breaks — the wrong schema, or `public`,
answers a tenant request. Use the **Session pooler** string (Dashboard →
Project Settings → Database → Connection string → *Session pooler*, port
`5432`, host `…pooler.supabase.com`). The direct connection also works but
is IPv6-only on newer projects.

```
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.<ref>:<pw>@aws-0-<region>.pooler.supabase.com:5432/postgres
DB_SSLMODE=require
```

- `config/database.php` already reads `DB_URL` — one var beats five.
- DB name is `postgres`, not `cncms`.
- The `postgres` role has `CREATE` on the DB, so `tenants:migrate` (schema
  per tenant) and self-service registration work. A locked-down role needs
  `GRANT CREATE ON DATABASE postgres TO <role>;`.

### Upstash — DB 0 only, and watch the free-tier command quota

```
REDIS_URL=rediss://default:<pw>@<endpoint>.upstash.io:6379
REDIS_CACHE_DB=0
REDIS_CLIENT=predis          # or phpredis + REDIS_SCHEME=tls (see below)
```

- **TLS.** Upstash is TLS-only.
  - `predis` (bundled via composer) reads the `rediss://` in `REDIS_URL`
    and just works. Simplest — set `REDIS_CLIENT=predis`.
  - `phpredis` does NOT infer TLS from `rediss://`; it opens a plaintext
    socket and you get `read error on connection to …:6379`. For phpredis
    you must also set **`REDIS_SCHEME=tls`** (added to `config/database.php`).
  - Without `predis/predis` installed you'd instead get
    "Class Predis\Client not found" — it's in composer now.
- **`REDIS_CACHE_DB=0`** — Laravel's cache connection defaults to DB 1 and
  Upstash only has DB 0, so without this every cache op fails.
- **Free tier ≈ 500K commands/month (~16.6K/day); at the cap, commands are
  rejected** (surfaces as cache errors, then slower). A `queue:work` Redis
  worker polls constantly, and CNCMS already spends ~8–12 commands per
  authenticated page (doubled by per-tenant cache tagging) plus the 20s
  notification poll from every open tab. So on the free tier keep the
  **queue on Postgres** and use Redis for **cache only**.

### Recommended split (Supabase + free Upstash)

```
SESSION_DRIVER=database      # low volume; also avoids `cache:clear` logging everyone out
CACHE_STORE=redis            # where Redis actually helps
QUEUE_CONNECTION=database    # avoids the Upstash daily limit; job_batches lives in Postgres anyway
```

On paid Upstash, flip `SESSION_DRIVER` + `QUEUE_CONNECTION` to `redis`.

---

## 1. Environment variables (dashboard → Environment)

Start from `../.env.production.example`. On **Laravel Cloud managed**
resources, `APP_KEY` and the DB vars are injected — but this deployment
uses external services (§0), so set `DB_*` / `REDIS_*` yourself.

Set these:

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — **must**; a stack trace on a tenant page leaks data shape |
| `APP_KEY` | your generated `base64:…` key |
| `APP_URL` | `https://cncms.laravel.cloud` (or the custom domain once attached) |
| `DB_CONNECTION` / `DB_URL` / `DB_SSLMODE` | §0 — Supabase session pooler |
| `REDIS_URL` / `REDIS_CACHE_DB` | §0 — Upstash |
| `SESSION_DRIVER` | `database` (see §0) |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_DOMAIN` | **do not set** on a `*.laravel.cloud` host — it's a public-suffix domain and browsers reject the cookie. Add `.yourdomain.com` only with a custom domain. |
| `SANCTUM_STATEFUL_DOMAINS` | `cncms.laravel.cloud` (the host of `APP_URL`) |
| `CACHE_STORE` | `redis` (see §0) |
| `QUEUE_CONNECTION` | `database` (see §0) |
| `FILESYSTEM_DISK` | `local` is fine for a test (ephemeral — regenerate bills / re-upload the logo after redeploys); §4 for real use |
| `MAIL_MAILER` | `log` for the test, or `smtp` + `MAIL_HOST/PORT/USERNAME/PASSWORD` |
| `MAIL_FROM_ADDRESS` | `no-reply@cncms.laravel.cloud` |
| `GOOGLE_CLIENT_ID/SECRET` | from Google Console, or leave blank to hide the button |
| `GOOGLE_REDIRECT_URI` | `https://cncms.laravel.cloud/auth/google/callback` |
| `INERTIA_ENCRYPT_HISTORY` | `true` |

**Do NOT set** `DB_HOST` / `DB_PORT` (use `DB_URL`), `SESSION_DOMAIN` (above),
`CENTRAL_DOMAIN` (derived from `APP_URL`), or any `AWS_*` (unused while
`FILESYSTEM_DISK=local`).

Tenancy resolves the tenant from the logged-in user, not the URL, so one
Cloud environment / one domain serves the admin app, the landlord area, and
registration. No per-tenant subdomains.

## 2. Deploy / release commands (dashboard → Deployments → Commands)

Laravel Cloud runs `composer install` and, because `package.json` has a
`build` script, `npm ci && npm run build` in the build step automatically.
Add these as **deploy commands** (run once per release, after the build):

```
php artisan migrate --force
php artisan tenants:migrate --force
php artisan optimize
```

`php artisan tenants:migrate --force` is the one people forget — the central
`migrate` does NOT touch tenant schemas.

## 3. Queue worker (REQUIRED) + scheduler

- **Worker**: add a **Worker** resource. Command:
  ```
  php artisan queue:work --queue=default,manuscripts,bills --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=1800
  ```
  `--queue` order is priority: tenant creation + small jobs (notifications,
  audit) on `default` are served before the heavy `manuscripts` / `bills`
  batches, so a registration never waits behind a bill render. One worker
  is fine to start. Without a worker, tenant creation, "Run Manuscript
  Calculation", and "Generate Bills" all sit `queued` forever.
- **Scheduler**: Laravel Cloud runs the scheduler for you — just make sure
  it's enabled for the environment. It ticks `routes/console.php` (currently
  `tasks:run-due` every 15 min).
- `job_batches` lives in the central schema only — nothing to configure,
  just don't be surprised it's not per-tenant.

## 4. File storage — the important one

Laravel Cloud containers have an **ephemeral filesystem**: anything under
`storage/app` is wiped on every deploy and on container recycling. CNCMS
writes real files there:

- **Generated bill-batch PDFs / ZIPs** — `BillBatchService` now writes to
  `config('filesystems.default')`, so setting `FILESYSTEM_DISK` fixes these.
- **Company logos** (Spatie Media Library), **payment/agent/expenditure
  receipt photos** — these still use the `public` disk explicitly in code
  (`Storage::disk('public')`) and are **not yet wired to a remote disk**.

**For a test environment** you can accept the loss: re-upload the logo and
regenerate bills after each deploy. **For real use**, attach Laravel Cloud
object storage (or an S3/R2 bucket), set:

```
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…
AWS_BUCKET=…
AWS_ENDPOINT=…            # for R2 / non-AWS
AWS_USE_PATH_STYLE_ENDPOINT=false
```

and file a follow-up to move the `public`-disk uploads onto the same bucket
(config `media-library.disk_name` + swapping the `Storage::disk('public')`
call sites for the default disk + signed URLs). Until then, logos/receipts
uploaded on Cloud won't survive a redeploy.

## 5. First landlord + the swecom data

**Landlord.** A fresh deploy has zero landlords — `users.is_landlord` has no
UI and isn't seeded. From the environment's **Commands** panel:

```
php artisan cncms:grant-landlord you@your-email.com --create --name="You" --password="pick-one"
```

Log in with that email/password → you land on `/landlord/tenants`. (Drop
`--create` if you already registered a normal account.)

**Getting into a workspace / fixing a role.** The Landlord "Add Tenant"
flow provisions a workspace with **no owner membership**, and self-service
registration makes the registrant `super`. To assign or change a role:

```
php artisan cncms:tenant-role <tenant-slug> you@your-email.com super
```

Company Info, Settings, and most admin screens need `super` or `admin` —
without it the Settings nav link doesn't even show.

**swecom's existing data.** The Supabase database starts empty; swecom's
~450 customers / manuscripts / payments live only in the dev box's local
Postgres (schema `tenantswecom`). Two-phase:

- *For this test:* don't migrate it. Validate the deploy with fresh
  self-service data + a small xlsx import. Faster, and nothing to lose.
- *For real production:* a scripted data migration — after `tenants:migrate`
  builds the empty `tenantswecom` schema on Supabase, load a
  **data-only** `pg_dump` of that schema plus the swecom-related `public`
  rows (`tenants`, `users`, `tenant_user_indexes`, `personal_access_tokens`,
  `domains`), then reset sequences. Do it **before creating any Cloud users**
  to avoid email/PK collisions, and rehearse it against a throwaway Supabase
  project first. Building `tenants:migrate` first then loading data-only
  sidesteps the PG18→Supabase version gap (only data moves, not DDL).

## 6. If it feels slow

CNCMS does several DB round-trips per request (session read/write,
`ResolveTenantWeb`'s membership lookups, tenancy `SET search_path`, then
the page's own queries) plus cache hits. Over the internet those add up.

1. **Co-locate all three services in the same region** — Laravel Cloud
   compute, Supabase, Upstash. This is by far the biggest factor; spread
   across regions means every request crosses oceans 4–6 times.
2. Use the Supabase **session pooler** connection string (not the direct
   one) — it holds fewer connections open and reconnects faster from
   ephemeral containers.
3. `APP_DEBUG=false` (debug mode has real overhead).
4. The first request after a deploy is a cold container / opcache warm-up —
   not representative; measure the 2nd+.
5. Only *after* co-locating: `SESSION_DRIVER=redis` + `CACHE_STORE=redis`
   cut the Supabase round-trips, but need Upstash off the free tier (the
   session churn would blow the free command cap).

## 7. Testing checklist

- [ ] `https://<cloud-url>/login` and `/register` load; a test sign-up
      lands on "awaiting approval".
- [ ] `cncms:grant-landlord` → `/landlord/tenants` works → approve the test
      workspace.
- [ ] Trigger a manuscript run → the Worker picks it up → it reaches
      `pending_review` → publish it.
- [ ] Generate bills → download the bulk PDF (works within a container
      lifetime; redeploy loses it unless `FILESYSTEM_DISK` is remote).
