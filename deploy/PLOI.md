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
  - `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, and the cache on
    Redis (`CACHE_STORE=redis`, see §5b); `DB_CACHE_CONNECTION`,
    `DB_QUEUE_CONNECTION` and `SESSION_CONNECTION` all `pgsql`
  - `MAIL_*`: real SMTP. Receipts, voucher approvals, cash digests and
    maintenance notices all email.
- **The test suite stays off the server.** `tests/` stays in Git — it guards
  every change — but production never needs it (it is outside `/public`,
  holds no secrets, and `--no-dev` skips PHPUnit). The deploy script below
  sets a sparse checkout on every deploy, so `git pull` leaves it out. Check:
  `ls {SITE_DIRECTORY}/tests 2>/dev/null || echo "tests/ not on the server"`.
  Undo with `git sparse-checkout disable` (and remove that deploy line).
- **Deploy script** (site → Deploy script). Use Ploi's own variables as its
  editor names them:

  ```bash
  set -e   # any failed step (e.g. a migration) stops the deploy and Ploi marks it failed
  cd {SITE_DIRECTORY}
  PHP_BIN={SITE_PHP} bash deploy/scripts/backup-db.sh   # a fresh backup before every deploy — no backup, no deploy
  git sparse-checkout set --no-cone '/*' '!/tests/' '!/phpunit.xml'   # tests stay off the server (safe to repeat)
  git pull --no-stat origin {BRANCH}   # --no-stat: no list of changed files (it named tests even when they are not checked out)
  {SITE_COMPOSER} install --no-interaction --prefer-dist --optimize-autoloader --no-dev
  npm ci && npm run build
  {SITE_PHP} artisan migrate --force
  {SITE_PHP} artisan tenants:migrate --force
  {SITE_PHP} artisan optimize
  {SITE_PHP} artisan queue:restart
  PHP_BIN={SITE_PHP} bash deploy/scripts/publish-apk.sh --from-release-file   # new agent APK, if deploy/mobile-release.json names one
  {RELOAD_PHP_FPM}
  bash deploy/scripts/record-deploy.sh   # notes this commit + its backup for rollback.sh (§6b)
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
email sit `queued` forever. The queue order is the priority order — all
three queues must be listed: manuscript runs wait on `manuscripts`, bills
and receipt books on `bills`.

`DB_QUEUE_RETRY_AFTER` (default 1900) must stay above `--timeout=1800`, or a
long manuscript chunk is taken for dead and run twice. Don't set it lower in
`.env`.

With **site isolation** on, run the daemon (and the scheduler below) as the
site's own user (e.g. `cncms-eyyyd`), not `ploi` — the same reason as the
backup: the site's files and `.env` belong to that user.

## 5. Scheduler and backups

Site → **Scheduler** (or server → Cron jobs), as user `ploi`, every minute:

```
php {SITE_DIRECTORY}/artisan schedule:run
```

This drives `tasks:run-due` (every 15 min) and the 07:00 cash-alerts
digest. Both skip themselves during a maintenance window.

Nightly database backup: server → Cron jobs, `15 3 * * *`, **as the site
user** (with site isolation that is the site's own user, e.g.
`cncms-eyyyd`, not `ploi` — the script reads that user's `.env`):

```
bash {SITE_DIRECTORY}/deploy/scripts/backup-db.sh
```

It writes to `~/backups/cncms` and deletes backups older than 14 days
(nightly and deploy ones alike), always keeping the newest 3. Check the folder the
morning after setting it up: before 2026-10-25 the script misread `.env`
and failed every run without saying so. The deploy script above also takes
one before every deploy. **Copy those off the
server** — §5c does it every night, with `storage/` (logos and issued
receipt PDFs can't be regenerated). A lost VPS without an off-server copy
means lost data.

## 5b. Cache on Redis

With `CACHE_STORE=database` every cache read is a Postgres query, and the
request rate limiter adds ~5 statements to every request. Redis takes that
off the database. Sessions and the queue stay on the database.

1. **Redis running?** SSH in: `redis-cli ping` → `PONG`. If not:
   `sudo apt install -y redis-server && sudo systemctl enable --now redis-server`.
2. **Lock it down** in `/etc/redis/redis.conf`:
   ```
   bind 127.0.0.1 -::1
   requirepass A_LONG_RANDOM_PASSWORD
   maxmemory 256mb
   maxmemory-policy volatile-lru
   ```
   `volatile-lru` (not `allkeys-lru`): only keys with an expiry are evicted.
   The cache-version counters (App\Support\CacheVersion) never expire; were
   one evicted, versions would restart and old cached screens could return.
   Then `sudo systemctl restart redis-server` and check
   `redis-cli -a 'A_LONG_RANDOM_PASSWORD' ping`.
3. **PHP extension:** `php8.5 -m | grep redis`. Listed → `REDIS_CLIENT=phpredis`;
   not listed → `REDIS_CLIENT=predis` (already a composer dependency).
4. **Site → Environment:** `CACHE_STORE=redis`, `REDIS_CLIENT`, `REDIS_HOST=127.0.0.1`,
   `REDIS_PORT=6379`, `REDIS_PASSWORD`, `REDIS_DB=0`, `REDIS_CACHE_DB=1`,
   `REDIS_PREFIX=cncms-`. Keep `DB_CACHE_CONNECTION=pgsql` (used again if you
   ever switch back).
5. **Apply:** `php artisan optimize && php artisan queue:restart`, then reload
   PHP-FPM (or just deploy).
6. **Check:** `php artisan tinker --execute 'Cache::put("ping", "pong", 60); echo Cache::get("ping");'`
   prints `pong`; `redis-cli -a '…' -n 1 dbsize` is above 0 after opening a few pages.

Per-tenant isolation is automatic: App\Tenancy\CacheTenancyBootstrapper tags
every cache call with the tenant on a store that supports tags (Redis does).
**Undo:** `CACHE_STORE=database`, then step 5.

## 5c. Off-server backups (Backblaze B2, Google Drive)

The dumps above sit on the same VPS: lose the server and they go with it.
`deploy/scripts/offsite-backup.sh` copies them every night to Backblaze B2,
**encrypted on the server first**, with `storage/app` (issued receipt PDFs,
logos, voucher photos — none can be regenerated). It only adds files;
deleting on the server never deletes on B2.

**1. Backblaze** (backblaze.com → B2 Cloud Storage, first 10 GB free):
- **Buckets → Create a bucket:** a unique name (e.g. `cncms-backups-7k2q`),
  **Private**, **Object Lock: Enable** with a default retention of **30 days**
  (Governance). For 30 days nobody can delete a copy, not even someone who
  breaks into the server.
- **Lifecycle settings → custom rule:** prefix `cncms/db/`, hide files after
  **90** days, delete hidden files after **1** day. Old dumps go after 90
  days; `cncms/storage/` is kept.
- **Application Keys → Add a New Application Key:** only that bucket,
  **Read and Write**. Copy the **keyID** and **applicationKey** at once (the
  key is shown only once).

**2. On the server**, SSH in as the site user (`cncms-eyyyd`):

```bash
bash ~/cncms.tech/deploy/scripts/offsite-backup.sh --install   # rclone into ~/bin, no root
cat > ~/.cncms-offsite.env <<'EOF'
B2_KEY_ID=the keyID
B2_APP_KEY=the applicationKey
B2_BUCKET=cncms-backups-7k2q
BACKUP_PASSPHRASE=a long passphrase only you know
HEALTHCHECK_URL=
EOF
chmod 600 ~/.cncms-offsite.env
bash ~/cncms.tech/deploy/scripts/offsite-backup.sh          # first copy; ends with "ok …"
bash ~/cncms.tech/deploy/scripts/offsite-backup.sh --list   # what is on B2
```

**Keep `BACKUP_PASSPHRASE` and the app's `APP_KEY` in your password manager.**
Without the passphrase the copies can't be opened; without `APP_KEY` the
restored app can't read its own receipt codes.

**3. Ploi → server → Cron jobs**, as the site user, after the nightly dump:
`45 3 * * *` → `bash /home/cncms-eyyyd/cncms.tech/deploy/scripts/offsite-backup.sh`

**4. Be told when a night fails (optional, free):** healthchecks.io → add a
check, period **1 day**, grace **2 hours**, your email. Put its ping URL in
`HEALTHCHECK_URL`. A night that fails — or never runs — emails you.
Each run is also logged in `~/cncms-offsite.log`.

**A second copy on Google Drive (optional, free up to 15 GB, shared with
Gmail).** The same script copies to every destination in the settings file,
with the same passphrase. Drive has no lock and no lifecycle rule, so the
script deletes dumps older than `GDRIVE_KEEP_DAYS` (default 60) there. It
can only see the files it created, never the rest of the Drive.

1. **On your own computer** (the server can't open a browser): download
   rclone from rclone.org/downloads (Windows, Intel/AMD 64-bit), unzip it,
   open PowerShell in that folder and run:
   ```
   .\rclone.exe authorize "drive" "eyJzY29wZSI6ImRyaXZlLmZpbGUifQ=="
   ```
   A browser opens: sign in with the Google account to use, and allow. The
   terminal then prints a token between `Paste the following into your
   remote machine --->` and `<---End paste`: copy the whole `{…}`.
2. **On the server**, `nano ~/.cncms-offsite.env` and add, on one line, in
   single quotes:
   ```
   GDRIVE_TOKEN='{"access_token":"…","token_type":"Bearer","refresh_token":"…","expiry":"…"}'
   ```
3. Run `bash ~/cncms.tech/deploy/scripts/offsite-backup.sh`: two lines now,
   `ok  b2: …` and `ok  gdrive: …`. In Drive a `cncms-backups` folder appears
   (file names scrambled — encrypted). `--list gdrive` shows it decrypted.

If the Google access is ever withdrawn (password change, access removed in
the Google account), the night fails and healthchecks.io emails you: redo
steps 1–2.

**Restore** (any machine with rclone, or the new server after `--install`, with
the same `~/.cncms-offsite.env`; add `gdrive` at the end to restore from Drive):

```bash
bash offsite-backup.sh --list
bash offsite-backup.sh --fetch db/cncms-2026-10-30-0315.sql.gz ~/restore   # decrypted
bash offsite-backup.sh --fetch storage ~/cncms.tech/storage/app            # the uploaded files
```

Then load the dump as in [`BLUE-GREEN.md`](./BLUE-GREEN.md) §4 (into a new
database, point `DB_DATABASE` at it).

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

## 6b. Rollback — back to the last working version

Every deploy ends with `record-deploy.sh`, which notes the commit and the
backup taken before it in `~/cncms-deploys.log` (outside the repo). To go
back, SSH in as the site user and run:

```bash
rollback              # the previous deploy
rollback 795c0154     # or a chosen commit
```

`rollback` is a shortcut. Set it up once, as the site user:

```bash
echo "alias rollback='bash ~/cncms.tech/deploy/scripts/rollback.sh'" >> ~/.bashrc && source ~/.bashrc
```

The script uses the newest PHP on the server (php8.5, then 8.4, 8.3). If
the site ever runs an older one than what is installed, say so:
`PHP_BIN=php8.4 rollback`.

- **The release changed no migration** (most releases): it shows both
  versions, asks, then puts the site in maintenance, checks out the old
  commit, rebuilds and brings the site back (about 2 minutes). Nothing
  anyone entered is lost. Then restart PHP-FPM in Ploi (server → PHP).
- **It did change migrations:** it stops and prints the restore steps with
  the right backup — restore into a new database, point `DB_DATABASE` at it,
  then run the script again with `--database-restored`. Everything entered
  since that backup is lost, so after staff have worked on the new version,
  prefer fixing forward ([`BLUE-GREEN.md`](./BLUE-GREEN.md) §4).
- **Afterwards:** Ploi's Deploy button pulls `main` again, the broken
  version. Keep Quick deploy off until `main` is fixed (`git revert` on dev,
  push, check on preview).

Rolling back to a version from before this log existed works too: name the
commit (`git log --oneline -10`); the backup to restore is then the newest
file in `~/backups/cncms` from before the bad deploy.

## 7. Things that bite

- **opcache:** after any manual file change on the server, run `php artisan
  optimize` and reload PHP-FPM (Ploi: server → PHP → restart), or old code
  keeps running.
- **`tenants:prune-disposable`** is for test databases. Never run it in
  production.
- **Timezones:** the app and the database session run in UTC on purpose
  (`config/database.php`). Leave the server timezone alone.
