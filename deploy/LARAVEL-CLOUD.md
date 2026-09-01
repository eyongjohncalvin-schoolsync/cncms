# CNCMS on Laravel Cloud

The bare-VPS kit (`../DEPLOYMENT.md`, `provision.sh`, `nginx/`, `systemd/`)
does **not** apply here — Laravel Cloud manages the web server, PHP, TLS,
deploys, the scheduler, and (as a resource you add) the queue worker. This
file is only the CNCMS-specific configuration.

---

## 1. Environment variables (dashboard → Environment)

Start from `../.env.production.example`. Laravel Cloud injects `APP_KEY` and
the database connection vars for its managed Postgres automatically — don't
set `DB_*` by hand unless you attached your own database.

Set these:

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | your Cloud URL, or the custom domain once attached (`https://…`) |
| `SESSION_DRIVER` | `database` (or `redis` if you add a Redis resource) |
| `SESSION_DOMAIN` | `.yourdomain` once on a custom domain; leave unset on the `*.laravel.cloud` URL |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | the host of `APP_URL` |
| `CACHE_STORE` | `database` (or `redis`) |
| `QUEUE_CONNECTION` | `database` (or `redis`) |
| `FILESYSTEM_DISK` | **see §4 — do not leave `local`** |
| `MAIL_*` | your SMTP provider |
| `GOOGLE_CLIENT_ID/SECRET` | from Google Console |
| `GOOGLE_REDIRECT_URI` | `https://<APP_URL host>/auth/google/callback` |
| `INERTIA_ENCRYPT_HISTORY` | `true` |

`CENTRAL_DOMAIN` is derived from `APP_URL` (see `config/tenancy.php`) — set
it only if the served host differs from `APP_URL`.

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
  php artisan queue:work --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=1800
  ```
  Without it, "Run Manuscript Calculation" and "Generate Bills" sit at
  `queued` forever (both dispatch `Bus::batch` jobs). One worker is fine to
  start.
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

## 5. Testing checklist

- [ ] `https://<cloud-url>/login` and `/register` load.
- [ ] A test sign-up lands on "awaiting approval".
- [ ] Grant yourself `is_landlord` (dashboard → Commands, or a one-off
      `tinker` run) → `/landlord/tenants` works → approve or delete the test
      tenant.
- [ ] Trigger a manuscript run → the Worker picks it up → it reaches
      `pending_review` → publish it.
- [ ] Generate bills → download the bulk PDF (works even on ephemeral
      storage within the same container lifetime; redeploy and it's gone
      unless `FILESYSTEM_DISK` is remote).
