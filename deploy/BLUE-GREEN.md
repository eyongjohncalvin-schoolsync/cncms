# Blue-green on one VPS: `preview` → `main`

How to ship a release safely with the two branches you already have.
Production setup is in [`PLOI.md`](./PLOI.md). This guide adds a **preview
site** and the routine for every release.

> **Values only you have.** These are marked `<LIKE_THIS>`. What's already
> known (check it in Ploi): server **schosync**, production site
> **cncms.tech** (site user `cncms-eyyyd`, directory `~/cncms.tech`, PHP 8.5).
> Find `<PROD_DB>` / `<PROD_USER>` in Ploi → cncms.tech → **Environment**
> (`DB_DATABASE` / `DB_USERNAME`).

---

## 1. What "blue-green" means here

| | Production ("blue") | Preview ("green") |
|---|---|---|
| Ploi site | `cncms.tech` | `preview.cncms.tech` (new) |
| Git branch | `main` | `preview` |
| `.env` | its own | its own (different from production, see §2.3) |
| Database | `<PROD_DB>` (live) | `cncms_preview`: **a copy** of production |
| Queue worker / scheduler | its own daemon + scheduler | its own daemon; scheduler normally off |
| Who uses it | staff, agents' phones | only you, and testers you invite |

### The database question: keep them separate

Textbook blue-green runs two copies of the code on **one shared live
database** and flips traffic between them. **Don't do that here.**

- Releases change the schema. This one (branches v2) adds `branch_id` to
  about 25 tables, fills it in, then makes it **NOT NULL**. After that, the
  old code can't save a payment, because it doesn't fill in `branch_id`. So
  you can't flip back to "blue" once the migrations have run on the shared
  database. Flipping back would itself be an outage.
- Pointing preview at the live database means every test click (a test
  payment, a closed cash day, an approved salary) is **real money data**.
- Preview runs migrations on push. If it shared the live database, an
  unfinished branch would migrate production.

**What this app does instead:**

1. **Preview = a rehearsal** on a fresh copy of production data, restored
   from a production dump. There you run the migrations, time them, and
   click through the release.
2. **Production = a short, announced maintenance window** using the app's
   own feature (Landlord → Maintenance). Staff wait on a countdown page and
   the mobile app queues its work offline. You (a landlord) can still get in.
   Inside the window: back up, deploy `main`, check, and reopen.
3. **Rollback = the backup taken inside the window**, plus the previous
   commit.

Don't use `php artisan down`: it locks out landlords too. The app's window
(`App\Support\MaintenanceMode`) gives advance notice, a countdown and
landlord access.

---

## 2. One-time setup: the preview site

### 2.1 DNS and site
1. DNS (at your domain registrar): add an **A record** `preview` → the VPS IP. `<YOUR DNS PROVIDER>`
2. Ploi → server **schosync** → **Sites → Create site**:
   - Domain `preview.cncms.tech`, project type **Laravel**, web directory `/public`.
   - System user: a **new isolated user**, like production's. Write it down as `<PREVIEW_SITE_USER>`.
   - **Never** run Ploi's root install command again (the server is already managed and runs another app).
3. **Repository**: this repo, branch **`preview`**. **Quick deploy ON** is fine
   for preview (it must stay **OFF** on production).
4. **SSL**: site → SSL → Let's Encrypt (after DNS resolves).

### 2.2 Database and user
Ploi → server → **Databases**: create database `cncms_preview` with its **own
user** `cncms_preview` (`<PREVIEW_DB_PASSWORD>`).
- Never use the `postgres` superuser in `.env`, and never use the production user.
- The preview user must **own** the database so it can recreate the tenant
  schemas on each restore. Run this once over SSH:
  ```bash
  sudo -u postgres psql -c 'ALTER DATABASE cncms_preview OWNER TO cncms_preview;'
  ```

### 2.3 Preview `.env` (Ploi → preview site → Environment)
Start from production's `.env`, then change only these values:

```ini
APP_ENV=staging
APP_DEBUG=false                # it holds real customer data; read the logs instead
APP_URL=https://preview.cncms.tech
APP_KEY=<SAME AS PRODUCTION>   # required: the copied data has encrypted
                               # settings and receipt security codes
LOG_LEVEL=debug

DB_DATABASE=cncms_preview
DB_USERNAME=cncms_preview
DB_PASSWORD=<PREVIEW_DB_PASSWORD>

SESSION_DOMAIN=
SESSION_COOKIE=cncms_preview_session
SANCTUM_STATEFUL_DOMAINS=preview.cncms.tech

MAIL_MAILER=log                # every email lands in storage/logs, nobody receives it
GOOGLE_CLIENT_ID=              # blank: Google sign-in is wired to production
GOOGLE_CLIENT_SECRET=
EXPO_ACCESS_TOKEN=
AGENT_APP_ANDROID_URL=
```

Notes:
- **Outgoing messages.** Email is the only automatic channel. WhatsApp is a
  manual `wa.me` link and Twilio isn't built. **Push notifications have no
  off switch in `.env`.** The copied database contains real staff phones'
  push tokens, so every restore must wipe them (step 3 in §3.2).
- **`APP_ENV=staging` is stricter than production.** The app turns on
  `preventLazyLoading` outside production. A page can therefore show an
  error on preview that production wouldn't show. Look in the log for
  `LazyLoadingViolationException`. That's a real slow-query bug to fix, but
  it's not a production outage.
- **No staging banner exists** in the app. The `preview.` address is the
  only visual hint.
- **Keep search engines out.** `public/robots.txt` allows everything, and
  both sites share it. In Ploi → preview site → **Nginx configuration**,
  inside `server { … }`, add
  `add_header X-Robots-Tag "noindex, nofollow" always;` and save. Don't add
  HTTP password protection if you'll test the mobile app: it clashes with
  the app's `Authorization: Bearer` header.

### 2.4 Deploy script, worker, scheduler
- **Deploy script**: copy production's script from [`PLOI.md` §3](./PLOI.md).
  It already includes `tenants:migrate`.
- **Keep tests off the server**: after the first deploy, run the same
  sparse-checkout commands as `PLOI.md` §3, in the preview site directory.
- **Daemon** (server → Daemons): user `<PREVIEW_SITE_USER>`, directory = the
  preview site directory, same command as production:
  ```
  php artisan queue:work --queue=default,manuscripts,bills --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=1800
  ```
  You need it to test bill generation and manuscript runs. Stop it when
  you're not testing to save RAM on the shared box.
- **Scheduler**: leave it **off**. Turn it on only to test scheduled jobs
  (`tasks:run-due`, the 07:00 cash digest, `expenditures:close-months`).
- **Nightly backup cron**: **no**, the preview database is disposable.

---

## 3. Every release

### 3.1 Push to preview
On your dev machine:
```bash
git checkout preview && git merge main     # or merge the feature branch
git push origin preview
```
Ploi deploys preview automatically (or click **Deploy**). The deploy runs
migrations on the *old* copy. That's fine, because the next step replaces it.

### 3.2 Restore a fresh copy of production into preview
SSH into the server as the sudo user Ploi gives you (`ploi`):

```bash
PROD_DB=<PROD_DB>
STAMP=$(date +%Y%m%d-%H%M)

# 0. stop the preview daemon first (Ploi → Daemons → stop), so nothing holds the DB open

# 1. dump production (read-only, safe while staff are working)
sudo -u postgres pg_dump --no-owner --no-privileges "$PROD_DB" | gzip > ~/prod-copy-$STAMP.sql.gz

# 2. wipe and recreate the PREVIEW database only (check the name twice)
sudo -u postgres dropdb --force --if-exists cncms_preview
sudo -u postgres createdb -O cncms_preview cncms_preview

# 3. load it as the preview user (central tables + every tenant schema, e.g. tenantswecom)
gunzip -c ~/prod-copy-$STAMP.sql.gz \
  | PGPASSWORD='<PREVIEW_DB_PASSWORD>' psql -h 127.0.0.1 -U cncms_preview -d cncms_preview -q -v ON_ERROR_STOP=1

# 4. clean the copy: no queued prod jobs, no prod logins, no pushes to real phones
PGPASSWORD='<PREVIEW_DB_PASSWORD>' psql -h 127.0.0.1 -U cncms_preview -d cncms_preview <<'SQL'
TRUNCATE jobs, failed_jobs, job_batches, sessions, personal_access_tokens, cache, cache_locks;
DO $$ DECLARE s text; BEGIN
  FOR s IN SELECT table_schema FROM information_schema.tables WHERE table_name = 'device_push_tokens' LOOP
    EXECUTE format('TRUNCATE %I.device_push_tokens', s);
  END LOOP;
END $$;
SQL

rm ~/prod-copy-$STAMP.sql.gz      # it holds customer data and password hashes
```

`pg_dump` copies **all** schemas, so every tenant (`tenant<id>`) comes
across with the central tables.

**Optional: copy uploaded files** (logos on receipts and bills). Run it
read-only from production:
```bash
sudo rsync -a /home/cncms-eyyyd/cncms.tech/storage/app/ /home/<PREVIEW_SITE_USER>/preview.cncms.tech/storage/app/
sudo chown -R <PREVIEW_SITE_USER>: /home/<PREVIEW_SITE_USER>/preview.cncms.tech/storage/app
```

If production was in a maintenance window when you dumped it, preview will
show it too. End it in preview's Landlord → Maintenance.

### 3.3 Run and time the migrations (preview)
```bash
sudo -iu <PREVIEW_SITE_USER>
cd ~/preview.cncms.tech
php artisan migrate:status | tail -20        # pending central migrations
time php artisan migrate --force
time php artisan tenants:migrate --force      # the one that matters: every tenant schema
php artisan optimize:clear && php artisan optimize
php artisan queue:restart
exit
```
Then start the preview daemon again in Ploi.

**Write down both times.** Your production window should be at least
**3× (sum of both) + 30 minutes**. Any error here means **stop**: fix it on
`main`, push to preview, and restore again.

### 3.4 Smoke-test preview (this release: branches v2)
Log in at `https://preview.cncms.tech` with your normal production account
(users are copied).

- [ ] **Branches page:** one branch, **Main Branch**, marked **HQ**. Its details and prices are shown.
- [ ] **Customers / zones:** counts match production. Open a few customers; balances are the same as on production.
- [ ] **Bills:** generate bills for one zone. The worker must be running. Amounts and numbering match production's last batch.
- [ ] **Payments and receipts:** record a test payment and verify it. The receipt is issued and its number continues the existing sequence.
- [ ] **Cash book:** accounts and balances match production. Open and close a cash day.
- [ ] **Branch transfers** (Cash → branch transfers): the page loads. With only one branch there's nothing to send. That's expected.
- [ ] **Salaries tab** (Settings → Salaries): create a grade, set a salary on a user, approve it.
- [ ] **Users page:** users show their branch and assignment history. Editing a role still works.
- [ ] **Expenditures, vouchers, complaints, audit log:** each list loads and shows the existing rows.
- [ ] **Manuscript run** for the current month on one zone completes.
- [ ] **Landlord area:** dashboard, tenants and maintenance page load.
- [ ] **Logs are clean:** `tail -100 ~/preview.cncms.tech/storage/logs/laravel-*.log` (as the preview site user). There are no `ERROR` lines, and no failed jobs show up in `php artisan queue:failed`.
- [ ] **Mobile (§5):** log in, sync, record one payment.

All green? Go to production. Anything red? Fix it, push to `preview`, and
repeat 3.2–3.4.

### 3.5 Production, in a maintenance window

**Before the day**
1. Merge to `main` and push. Production doesn't deploy yet (quick deploy is off).
2. Pick a quiet time: not during bill printing or the month-end manuscript cycle.
3. Production → **Landlord → Maintenance → schedule**: set the start and end
   (from 3.3), write a message, and tick **Email every tenant user**. Email
   only goes out if SMTP is set in production's `.env`. The countdown banner
   shows on web and mobile regardless.

**At the start time** (staff are now held on the countdown page; you can still get in)
1. Check that nothing heavy is running: no bill generation or manuscript run
   in progress in the app. Check the queue is empty:
   ```bash
   sudo -u postgres psql <PROD_DB> -c "select queue, count(*) from jobs group by 1;"
   ```
2. **Stop the production daemon** (Ploi → Daemons). Otherwise the old worker
   code keeps running against the new schema mid-deploy.
3. **Record the current commit** (this is your rollback target):
   ```bash
   sudo -iu cncms-eyyyd
   cd ~/cncms.tech && git rev-parse --short HEAD     # write it down: <OLD_SHA>
   ```
4. **Back up**, still as `cncms-eyyyd`:
   ```bash
   ~/cncms.tech/deploy/scripts/backup-db.sh          # prints the file path: <BACKUP_FILE>
   ```
   Copy it off the server too: `scp` it to your own machine.
5. Ploi → `cncms.tech` → **Deploy**. Read the whole deploy log, not just the
   status. It must contain **no** error lines and must end with
   `Deployed de6f300e` (or the new commit). The current deploy script has no
   `set -e`, so an error in the middle may not stop it.
6. **Start the production daemon** again.
7. **Check as landlord.** Run a short version of the 3.4 list on real data:
   branches page, open a customer, cash book, the users page, and the log
   file. Don't record test payments on production.
8. **Landlord → Maintenance → End now.** Staff are back in, and phones sync
   their offline queue.
9. Watch the next 30–60 min: `storage/logs`, `php artisan queue:failed`, and
   the first mobile syncs.

---

## 4. Rollback

### Decision points
| When | What you do | Data lost |
|---|---|---|
| Migration or deploy fails, **window still on** | Full rollback (code + DB) | none (nobody wrote anything) |
| Your check (3.5 step 7) fails, **window still on** | Full rollback, or extend the window and fix quickly | none |
| Problem found **after you reopened** | **Fix forward** (hotfix → preview → production). Only restore if money is being recorded *wrongly*, because a restore deletes everything entered since the backup. | everything since the backup |

**This release can't be rolled back by code alone.** After its migrations
the old code can't save payments (`branch_id` is NOT NULL). Code rollback and
DB rollback go together. For a future release *without* migrations, a code
rollback alone is enough.

The migrations do have `down()` methods, but **restoring the backup is
safer**. A `down()` removes columns. It doesn't reliably undo data
backfills, and a backfill that failed halfway leaves a state `down()` was
never tested against.

`deploy/scripts/rollback.sh` ([`PLOI.md`](./PLOI.md) §6b) does the code
part and tells you whether the database has to go back too, naming the
backup. The steps below are what it prints.

### Full rollback (inside the window)
1. Turn **Quick deploy OFF** on production. Don't press Deploy until `main` is fixed.
2. Stop the production daemon.
3. **Restore the backup into a new database.** Don't overwrite the
   live one, so you can still change your mind:
   ```bash
   sudo -u postgres createdb -O <PROD_USER> cncms_rollback_<DATE>
   gunzip -c <BACKUP_FILE> \
     | PGPASSWORD='<PROD_DB_PASSWORD>' psql -h 127.0.0.1 -U <PROD_USER> -d cncms_rollback_<DATE> -q -v ON_ERROR_STOP=1
   ```
4. Ploi → cncms.tech → **Environment**: set `DB_DATABASE=cncms_rollback_<DATE>` and save.
5. **Put the old code back**, as `cncms-eyyyd` in `~/cncms.tech`:
   ```bash
   git fetch origin && git reset --hard <OLD_SHA>
   composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
   npm ci && npm run build
   php artisan optimize:clear && php artisan optimize
   php artisan queue:restart
   ```
   Then Ploi → server → PHP → **restart** PHP-FPM.
6. Start the daemon, check as landlord, and **End** the maintenance window.
7. Later, on dev: fix it, or `git revert -m 1 <merge commit>`, and push `main`.
   Rehearse on preview again before deploying.
   Drop the abandoned database only once you're sure:
   `sudo -u postgres dropdb <PROD_DB>`.

---

## 5. Mobile app

- The app calls `…/api/v1`. The address is **baked into the APK at build
  time** (`EXPO_PUBLIC_API_BASE_URL`), and there's no version check between
  app and server. Agents' phones only ever talk to **https://cncms.tech**.
  Nothing you do on preview reaches them.
- **This release has no mobile changes.** The branches v2 API changes are
  additions only (plan §9). The current APK should keep working, but still
  check one login, sync and payment against preview.
- **Name clash.** In `mobile/eas.json`, the build profile and update branch
  called **`preview`** point at **production** (`https://cncms.tech/api/v1`).
  That's the channel agents' phones use. It has nothing to do with the
  preview *site*. Never run `eas update --branch preview` with the preview
  server's address.
- **Testing the app against the preview server** needs a separate test build
  (a developer task, not set up yet):
  - Add an EAS profile `staging` with its own update channel and
    `EXPO_PUBLIC_API_BASE_URL=https://preview.cncms.tech/api/v1`, then run
    `eas build --profile staging`.
  - Install it on a **spare phone**. It has the same app id as the real app,
    so on an agent's phone it would *replace* the production app. Giving it
    its own app id in `app.config.ts` avoids that.
  - Alternative for a developer: run `npx expo start` with that variable set.
- **During the window** the app shows the maintenance banner, gets
  `503 MAINTENANCE`, and queues work offline. When you end the window it
  syncs. Keep windows short so agents don't pile up offline work.

---

## 6. Cheat sheet

```bash
# who/where (production)
sudo -iu cncms-eyyyd ; cd ~/cncms.tech ; git rev-parse --short HEAD

# backup now (production)
~/cncms.tech/deploy/scripts/backup-db.sh

# migrations by hand (either site, as its site user, in its directory)
php artisan migrate:status
php artisan migrate --force
php artisan tenants:migrate --force
php artisan optimize:clear && php artisan optimize && php artisan queue:restart

# health
tail -f storage/logs/laravel-$(date +%F).log
php artisan queue:failed
sudo -u postgres psql <DB> -c "select queue, count(*) from jobs group by 1;"

# refresh preview from production  → §3.2
```

## 7. Pre-flight checklist (production day)

- [ ] Preview was restored from production **today or yesterday**, migrated cleanly, and the times are written down.
- [ ] Every item in the 3.4 smoke test is green, and the preview log is clean.
- [ ] `main` == the commit you tested on `preview` (`git log -1 origin/main origin/preview`).
- [ ] Production **Quick deploy is OFF**.
- [ ] The maintenance window is scheduled, its length comes from the measured time, and staff are told.
- [ ] No bill printing or month-end cycle is due during the window.
- [ ] You know `<PROD_DB>`, `<PROD_USER>` and the password, and you have SSH access as `ploi`.
- [ ] Disk space for the backup: `df -h ~`.
- [ ] Off-server copy of the backup planned (scp to your machine).
- [ ] You've read §4 and know your rollback decision before you start.
