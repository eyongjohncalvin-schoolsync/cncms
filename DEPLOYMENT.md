# CNCMS — Production Deployment Runbook

> **Deploying to Laravel Cloud instead?** Ignore this file — see
> **`deploy/LARAVEL-CLOUD.md`** (Cloud manages the server, TLS, scheduler,
> and deploys; you just configure env, the worker resource, deploy
> commands, and — importantly — remote file storage).

Target: **one bare Ubuntu 24.04 LTS VPS**, everything on it (Nginx + PHP-FPM +
PostgreSQL + the queue worker + the scheduler). Database on the same box.
Backups = nightly `pg_dump` on the server, copied off-server by you.

Decisions already made (2026-09-01): bare VPS / manual setup · you own the
domain · Postgres local · local disk for files + off-server DB dump copy.

Replace **`YOUR_DOMAIN`** with your real domain everywhere below and in the
files under `deploy/`. App code assumes a UNIX user **`cncms`** and app path
**`/var/www/cncms`** — change consistently if you use different ones.

Stack versions: **PHP 8.4**, **PostgreSQL 18**, **Node 20 LTS**, Composer 2.

---

## 0. Before you touch the server

- [ ] A VPS with a public IPv4, root or sudo SSH access (2 vCPU / 4 GB RAM is
      plenty for one operator; 2 GB works).
- [ ] DNS: an **A record** for `YOUR_DOMAIN` (and `www` if you want it)
      pointing at the VPS IP. Do this first — Let's Encrypt needs it to
      resolve.
- [ ] Real values ready for: SMTP (transactional mail), Google OAuth client
      (Console → Credentials → Web application, redirect
      `https://YOUR_DOMAIN/auth/google/callback`). Both are optional to boot
      but sign-up-via-Google and password-reset mail won't work without them.

---

## 1. Provision the server (once)

Copy `deploy/` to the box (`scp -r deploy root@SERVER:/tmp/`) then:

```bash
sudo bash /tmp/deploy/scripts/provision.sh
```

That script (read it first) installs: `ondrej/php` PPA + PHP 8.4 FPM/CLI and
the extensions CNCMS needs (`pgsql mbstring xml curl zip gd bcmath intl
opcache`), the PGDG repo + PostgreSQL 18, Nginx, Composer, Node 20, `certbot`,
`unzip git`, creates the `cncms` user, and creates the `cncms` database +
role.

**It prints a generated DB password — save it**, you need it in `.env`.

---

## 2. Get the code

```bash
sudo -u cncms -H bash
cd /var/www
git clone <YOUR_REPO_URL> cncms          # deploy the `prepayment-drawdown-credit` branch for now
cd cncms
git checkout prepayment-drawdown-credit
```

> The current production-candidate work is on `prepayment-drawdown-credit`.
> Merge it to `main` and deploy `main` once you're happy — then change the
> branch in `deploy/scripts/deploy.sh`.

---

## 3. Configure `.env`

```bash
cp .env.production.example .env
nano .env
```

Fill in, at minimum:

| Key | Value |
|---|---|
| `APP_URL` | `https://YOUR_DOMAIN` |
| `APP_KEY` | leave blank now; step 4 generates it |
| `DB_PASSWORD` | the password `provision.sh` printed |
| `SESSION_DOMAIN` | `.YOUR_DOMAIN` |
| `SANCTUM_STATEFUL_DOMAINS` | `YOUR_DOMAIN` |
| `MAIL_*` | your SMTP provider |
| `GOOGLE_CLIENT_ID/SECRET` | from Google Console (or leave blank) |
| `GOOGLE_REDIRECT_URI` | `https://YOUR_DOMAIN/auth/google/callback` |

`CENTRAL_DOMAIN` is derived from `APP_URL` automatically (see
`config/tenancy.php`); set it explicitly only if the app is served from a
host that differs from `APP_URL`.

---

## 4. First build + migrate

```bash
cd /var/www/cncms
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
php artisan storage:link

npm ci
npm run build

php artisan migrate --force            # central schema: users, tenants, jobs, job_batches, cache, sessions
php artisan tenants:migrate --force    # every tenant schema
php artisan optimize                   # config + route + view + event cache
```

There is **no seeder for production** — real data comes in via the import
flows and self-service registration. The two existing tenants (`swecom`,
`multimedia-digital-cable-network`) only exist on your dev box; production
starts empty. If you are migrating `swecom`'s real data, that's a separate
`pg_dump`/restore of the `tenantswecom` schema plus the relevant `public`
rows — ask before doing it, it's not part of this runbook.

---

## 5. Nginx + HTTPS

```bash
sudo cp /tmp/deploy/nginx/cncms.conf /etc/nginx/sites-available/cncms
sudo sed -i 's/YOUR_DOMAIN/actual.domain.com/g' /etc/nginx/sites-available/cncms
sudo ln -sf /etc/nginx/sites-available/cncms /etc/nginx/sites-enabled/cncms
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d YOUR_DOMAIN            # add -d www.YOUR_DOMAIN if used
```

Certbot rewrites the server block to add the 443 listener + a HTTP→HTTPS
redirect, and installs a renewal timer. Re-run `nginx -t && systemctl reload
nginx` after.

---

## 6. The queue worker (MANDATORY)

`QUEUE_CONNECTION=database`. **"Run Manuscript Calculation" and "Generate
Bills" both dispatch `Bus::batch` jobs — without a running worker they sit at
`queued` forever.** This is not optional.

```bash
sudo cp /tmp/deploy/systemd/cncms-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now cncms-worker
sudo systemctl status cncms-worker
```

Logs: `journalctl -u cncms-worker -f`.

After every deploy, `deploy.sh` runs `php artisan queue:restart` so the
worker picks up new code (systemd restarts it).

---

## 7. The scheduler

`Schedule::command('tasks:run-due')->everyFifteenMinutes()` (see
`routes/console.php`) needs Laravel's scheduler ticked every minute:

```bash
sudo cp /tmp/deploy/cron/cncms.cron /etc/cron.d/cncms
sudo sed -i 's/YOUR_DOMAIN/actual.domain.com/g' /etc/cron.d/cncms   # only if the file references it
sudo chmod 644 /etc/cron.d/cncms
sudo systemctl restart cron
```

`/etc/cron.d/cncms` runs `schedule:run` every minute and `backup-db.sh`
nightly, both as the `cncms` user.

---

## 8. Verify

- [ ] `https://YOUR_DOMAIN/login` loads over HTTPS, no cert warning.
- [ ] `https://YOUR_DOMAIN/register` loads; a test sign-up creates a
      workspace (it lands on "awaiting approval" — a landlord approves it).
- [ ] Grant yourself landlord access, then delete the test tenant:
      ```bash
      php artisan tinker --execute="\$u=App\Models\User::where('email','YOU@YOUR_DOMAIN')->firstOrFail(); \$u->is_landlord=true; \$u->landlord_granted_at=now(); \$u->save();"
      ```
      Then `/landlord/tenants` works.
- [ ] `sudo systemctl is-active cncms-worker` → `active`.
- [ ] `php artisan queue:work --once` processes a job (or watch the journal
      while you trigger a manuscript run).
- [ ] `storage/` and `bootstrap/cache/` are writable by `cncms` and
      `www-data` (provision.sh sets group `www-data` + `g+w`).

---

## 9. Ongoing deploys

```bash
sudo -u cncms -H /var/www/cncms/deploy/scripts/deploy.sh
```

It: `php artisan down` → `git pull` → `composer install --no-dev -o` →
`npm ci && npm run build` → `migrate --force` → `tenants:migrate --force` →
`optimize` → `queue:restart` → `php artisan up`, and reloads PHP-FPM.

If a deploy fails mid-way the site stays in maintenance mode — fix, re-run,
it ends with `artisan up`.

---

## 10. Backups & restore

**Backup** (automatic, nightly via cron): `deploy/scripts/backup-db.sh`
writes `/var/backups/cncms/cncms-YYYY-MM-DD-HHMM.sql.gz` and keeps 14 days.

**Copy it off-server** — a lost VPS = lost DB otherwise. Either:
- a cron on your own machine: `rsync -az cncms@YOUR_DOMAIN:/var/backups/cncms/ ~/cncms-backups/`
- or `scp` after any big change.

Generated bill-batch PDFs and company logos live on the server disk
(`storage/app/`). The PDFs are regenerable from the data; logos are not, so
include `storage/app/public/` in an occasional `rsync` too.

**Restore** (into a fresh box that's been through steps 1–4):
```bash
sudo -u postgres dropdb cncms && sudo -u postgres createdb -O cncms cncms
gunzip -c cncms-2026-09-01-0300.sql.gz | sudo -u cncms psql cncms
php artisan optimize:clear && php artisan optimize
```

---

## 11. Things that bite (from the dev history)

- **`tenants:prune-disposable`** exists to drop orphan test-tenant schemas.
  It only ever keeps `swecom` + `multimedia-...`. **Do not run it in
  production** once real tenants exist unless you update its allowlist.
- **Route/opcache staleness**: `provision.sh` sets `opcache.validate_timestamps=0`
  for speed, so **new code isn't live until `php artisan optimize` + a
  PHP-FPM reload** — `deploy.sh` does both. If you hand-edit a file on the
  server, `php artisan optimize:clear && php artisan optimize && sudo systemctl reload php8.4-fpm`.
- **`job_batches` is central-schema only** — nothing to do at deploy time,
  just don't be surprised it's not in a tenant schema.
- **Mobile app**: `EXPO_PUBLIC_API_BASE_URL` is compiled into the build.
  Point it at `https://YOUR_DOMAIN` and do a fresh EAS build — an env edit
  alone does nothing.
- **`APP_DEBUG=false`** in production, always. A stack trace on a tenant
  page can leak another tenant's data shape.

---

## 12. Still open (decide later)

- Merge `prepayment-drawdown-credit` → `main`; deploy `main`.
- Finish the landlord context (grant-`is_landlord` UI, landlord dashboard) —
  see `.claude/skills/cncms-context/references/deployment.md` §3.
- A second queue worker if manuscript runs feel slow (edit the systemd unit
  to `--max-jobs` / run `cncms-worker@1` `@2` instances).
- Fail2ban / UFW hardening (`provision.sh` opens 22/80/443 via UFW; SSH
  key-only auth and fail2ban are recommended, not scripted here).
