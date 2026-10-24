# CNCMS on a Hostinger VPS, managed by Ploi

**The chosen production setup (decided 2026-10-22).** Laravel Cloud is no
longer used. One Hostinger VPS runs everything: Nginx, PHP-FPM, PostgreSQL,
the queue worker and the scheduler. Ploi provisions and manages it, and
deploys `main` from GitHub.

`DEPLOYMENT.md` (the manual runbook) is the fallback if Ploi is ever
dropped. Its **swecom cutover** section applies here unchanged.

Replace `YOUR_DOMAIN` with the real domain throughout.

---

## 1. Hostinger VPS

- Plan: 2 vCPU / 4–8 GB RAM is plenty for one operator (bill and receipt-book
  PDFs are the heaviest work).
- OS template: **plain Ubuntu 24.04**, not one with a control panel. Ploi
  needs a clean server.
- Note the IP and the root password / SSH key.
- DNS: an **A record** for `YOUR_DOMAIN` → the VPS IP. Do this first, because
  the HTTPS certificate needs it.

## 2. Connect the server to Ploi

Ploi → **Servers → Create server → Custom VPS**:

- Server type: **server** (web + database on one box).
- Webserver: **Nginx**. Database: **PostgreSQL** (latest Ploi offers).
- PHP: the newest version Ploi offers that satisfies `composer.json`
  (`^8.3`). Dev runs PHP 8.5.
- Run the install command Ploi shows, as root, on the VPS. Wait until Ploi
  reports the server as active.

Then, on the server in Ploi:

- **PHP extensions:** make sure `pgsql`, `pdo_pgsql`, `zip`, `gd`, `intl`,
  `bcmath`, `mbstring` are enabled. `zip` is needed by `tenant:import` and the
  XLSX exports.
- **Databases → create** a database `cncms` with its own user. Keep the
  password for `.env`. **Never put the `postgres` superuser in production
  `.env`:** a superuser bypasses every database permission (and any future
  row-level security), so a single app bug could reach everything.
- **PHP settings:** `memory_limit` at least 512M,
  `upload_max_filesize`/`post_max_size` at least 30M (customer imports).

## 3. The site

**Sites → Create site**: domain `YOUR_DOMAIN`, project type **Laravel**, web
directory `/public`.

- **Repository:** connect GitHub, this repo, branch **`main`**.
- **Environment** tab: paste [`.env.production.example`](./.env.production.example)
  and fill it in. Critical values:
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://YOUR_DOMAIN`
  - **`APP_KEY` = the dev machine's key** (copy it from the dev `.env`). Do
    NOT generate a new one: issued receipt security codes and encrypted
    settings depend on it, and the swecom archive only opens with it.
  - `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=cncms` + the
    user/password from step 2
  - `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`
  - `MAIL_*`: real SMTP. Receipts, voucher approvals, cash digests and
    maintenance notices all email.
- **Keep the test suite off the server (one time, after the first deploy).**
  `tests/` stays in Git — it guards every change — but production never needs
  it (it is outside `/public`, holds no secrets, and `--no-dev` skips PHPUnit).
  Sparse checkout makes every later `git pull` leave it out:

  ```bash
  cd {SITE_DIRECTORY}
  git sparse-checkout init --no-cone
  git sparse-checkout set '/*' '!/tests/' '!/phpunit.xml'
  ls tests 2>/dev/null || echo "tests/ not on the server"
  ```

  Undo with `git sparse-checkout disable`.
- **Deploy script** (site → Deploy script). Use Ploi's own variables as its
  editor names them:

  ```bash
  cd {SITE_DIRECTORY}
  git pull origin {BRANCH}
  {SITE_COMPOSER} install --no-interaction --prefer-dist --optimize-autoloader --no-dev
  npm ci && npm run build
  {SITE_PHP} artisan migrate --force
  {SITE_PHP} artisan tenants:migrate --force
  {SITE_PHP} artisan optimize
  {SITE_PHP} artisan queue:restart
  {RELOAD_PHP_FPM}
  echo "Deployed $(git rev-parse --short HEAD)"
  ```

  `tenants:migrate` is the one people forget: the central `migrate` never
  touches tenant schemas. A stale tenant schema has already caused a "menu
  shows only Dashboard" scare. Node must be on the server for `npm run
  build`; Ploi can install it (server → Manage → Node).
- **Quick deploy** (deploy on push to `main`): optional. Leaving it off
  means a merge to `main` never deploys by surprise.
- **SSL:** site → SSL → Let's Encrypt, after DNS resolves.

## 4. Queue worker — mandatory

Server → **Daemons → New daemon**, user `ploi`, directory = the site
directory, 1 process:

```
php artisan queue:work --queue=default,manuscripts,bills --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=1800
```

Without it, manuscript runs, bill generation, receipt-book PDFs and every
email sit `queued` forever. The queue order is the priority order.

## 5. Scheduler and backups

Site → **Scheduler** (or server → Cron jobs), as user `ploi`, every minute:

```
php {SITE_DIRECTORY}/artisan schedule:run
```

This drives `tasks:run-due` (every 15 min) and the 07:00 cash-alerts
digest. Both skip themselves during a maintenance window.

Nightly database backup: server → Cron jobs, `15 3 * * *`:

```
{SITE_DIRECTORY}/deploy/scripts/backup-db.sh
```

It writes to `~/backups/cncms` and keeps 14 days. **Copy those off the
server** (rsync from your own machine, or Ploi's backup feature if your
plan includes it), along with `storage/` (logos and issued receipt PDFs
can't be regenerated). A lost VPS without an off-server copy means lost data.

## 6. First deploy, then the data

1. Deploy once from Ploi. The site should load the login page.
2. **Do not register or create any user yet.** Imported swecom ids must be
   free.
3. Run the **swecom cutover** from `DEPLOYMENT.md`: maintenance window on
   dev → `tenant:export swecom` → copy the archive up
   (`scp swecom-….cncms ploi@YOUR_DOMAIN:~`) → in the site directory,
   `php artisan tenant:import ~/swecom-….cncms` → delete the archive.
4. swecom's users come across with their passwords, and the landlord flag
   comes with them. Log in as yourself and check the landlord area and
   swecom's dashboard.
5. Build the mobile app against `https://YOUR_DOMAIN`
   ([`MOBILE-DISTRIBUTION.md`](./MOBILE-DISTRIBUTION.md)).

## 7. Things that bite

- **opcache:** after any manual file change on the server, run `php artisan
  optimize` and reload PHP-FPM (Ploi: server → PHP → restart), or old code
  keeps running.
- **`tenants:prune-disposable`** is for test databases. Never run it in
  production.
- **Timezones:** the app and the database session run in UTC on purpose
  (`config/database.php`). Leave the server timezone alone.
