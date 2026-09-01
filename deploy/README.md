# deploy/

Manual-VPS deployment kit for CNCMS. Full walkthrough: **`../DEPLOYMENT.md`**.

**On Laravel Cloud instead?** → **`LARAVEL-CLOUD.md`** (none of the scripts /
Nginx / systemd files below apply; Cloud manages all that).

| Path | What it is |
|---|---|
| `scripts/provision.sh` | One-time Ubuntu 24.04 setup: PHP 8.4, PostgreSQL 18, Nginx, Composer, Node 20, certbot, the `cncms` user, the `cncms` DB + role, opcache tuning, UFW. Prints a generated DB password. |
| `scripts/deploy.sh` | Every-deploy script: maintenance mode → pull → composer → `npm run build` → migrate (central + tenants) → `optimize` → `queue:restart` → PHP-FPM reload → up. |
| `scripts/backup-db.sh` | Nightly `pg_dump | gzip` to `/var/backups/cncms`, keeps 14. **You must copy these off-server.** |
| `nginx/cncms.conf` | Nginx server block (HTTP; certbot adds HTTPS). PHP-FPM socket, 30M uploads, hashed-asset caching, security headers. |
| `systemd/cncms-worker.service` | The queue worker. **Mandatory** — manuscript runs and bill generation are queued. |
| `cron/cncms.cron` | `schedule:run` every minute + the nightly backup. Goes to `/etc/cron.d/cncms`. |
| `sudoers.d/cncms` | Lets `deploy.sh` (as `cncms`) reload PHP-FPM / bounce the worker, nothing else. |

Replace `YOUR_DOMAIN` and (if you change them) the `cncms` user / `/var/www/cncms`
path consistently across all of these.
