# Backup & Restore — Design Spec

Status: **Design, not yet implemented** | Owner ask: a real backup process for this system's real
customer billing data (payments, arrears, manuscripts) — there is currently **no backup mechanism
of any kind**. Converged from four independent research passes (data/infrastructure, restore/DR
testing, security/storage/access control, automation/monitoring), each covering a distinct angle
with no overlap, then reconciled into this one spec.

---

## 1. Current state (confirmed, not assumed)

- No backup command, package, or config exists anywhere in this codebase. `app/Console/Commands/`
  has no backup command; `composer.json` has `spatie/laravel-medialibrary` but not
  `spatie/laravel-backup` or any other backup package; `routes/console.php` registers exactly one
  schedule entry (`tasks:run-due`, every 15 minutes).
- This is **schema-per-tenant on a single Postgres database**, not database-per-tenant
  (`config/tenancy.php` uses `PostgreSQLSchemaManager`, explicitly not
  `PostgreSQLDatabaseManager`) — one `pg_dump -Fc` against the single physical database captures
  the central `public` schema (tenants, users) plus every `tenant<id>` schema in one file. No
  per-tenant fan-out needed.
- **Receipt photos are a separate backup target a plain DB dump would silently miss.** They live on
  local disk (`config/filesystems.php`'s `public` disk, `storage_path('app/public')`), not S3 — an
  `s3` disk is defined in config but never configured (`.env.example` ships blank
  `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_BUCKET`). Any backup mechanism must capture both
  the database and this storage path, not just one.
- Data volume is small — roughly 549 customers, 2,600 payments, 521+ manuscripts as of this design
  pass. A compressed nightly dump+receipts archive is likely low tens of MB. Storage cost is not a
  real constraint here; don't over-engineer for scale this system doesn't have.

## 2. Mechanism

Adopt **`spatie/laravel-backup`** rather than hand-rolling `pg_dump` invocation, compression, and
cleanup. It already wraps a `pg_dump`-based database dump, zips the configured storage
directories (the receipts path above) into the same archive, and handles retention/pruning out of
the box — cheaper to adopt correctly than to reinvent, and it's the one piece of this design that
directly solves "the database and the receipts must be backed up together" without extra plumbing.

No confirmed Laravel Cloud managed-backup feature was found in this project's deployment docs — if
one exists it's unconfirmed defense-in-depth, not a substitute for the app-level mechanism below.
Ship this regardless of eventual deploy target.

## 3. Scheduling — deliberately NOT the existing admin-configurable scheduler

This app already has a real, working, tenant-scoped `ScheduledTask`/`TasksRunDue` system
(`references/task-scheduler.md`) — an admin can toggle a task's `enabled` flag on/off. **Backup
must not be wired through that system.** A database backup is a whole-instance ops concern, not
tenant business logic, and it must never be something a non-technical admin can accidentally
switch off via a settings page — exactly the class of mistake this project's own audit history
already found and fixed elsewhere (see the manuscript-calculation period-validation fix).

Instead: a separate, hardcoded entry in `routes/console.php`, next to the existing
`Schedule::command('tasks:run-due')` line:

```php
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
```

Code-only. No `enabled` database row, no Settings UI toggle, no way for anyone to turn it off
short of editing and redeploying code.

**Additionally**, trigger a backup immediately before the monthly manuscript-generation batch
specifically (call the backup runner at the top of `ManuscriptGenerationTaskType::run()`, before it
dispatches) — a pre-batch safety snapshot tied to whatever day-of-month each tenant has configured
for that risky, tenant-wide operation, at zero extra scheduling cost.

## 4. Storage & encryption

- **Offsite, not local disk.** A backup stored on the same machine as the live database protects
  against nothing but accidental row deletion — it does not survive a disk failure, a compromised
  server, or theft of the machine. Populate the app's already-defined-but-unused `s3` disk against
  a cheap S3-compatible provider appropriate for this scale — Backblaze B2 (~$6/TB/month, no
  egress fees) or DigitalOcean Spaces ($5/mo flat for 250GB) are both concrete, low-cost options.
  Use a dedicated bucket distinct from any app-media bucket, with write-only credentials for the
  app and a separate read-capable key held only by the owner, kept out of `.env` entirely.
- **Encrypted with its own key — never `APP_KEY`.** `APP_KEY` is already used for live
  session/cookie (and some column) encryption and lives in the same `.env` a compromised app
  server leaks first; reusing it for backups means anyone who owns the app server also owns every
  backup it ever produced. Each dump must be encrypted client-side before it ever leaves the
  server, using a dedicated `BACKUP_ENCRYPTION_KEY`/keypair that lives outside the app's `.env` and
  outside the app server entirely (a hosting provider's secrets manager, or an offline vault the
  owner controls) — only ciphertext should ever reach the bucket.

## 5. Access control & audit trail

- No existing policy in this app is pure `super`-only today — the strictest existing precedent is
  `isAnyOf('super', 'admin')` (e.g. `CompanyPolicy`, `BranchPolicy`, `CommandRunPolicy`). Backup
  access should deliberately break that precedent: creating/listing/downloading a backup, and
  especially **triggering a restore, must be `super`-only** — a restore is "replace all live
  financial data," and a backup download is "exfiltrate every customer's payment history in one
  file," both strictly more consequential than anything currently gated at `admin`. Add a
  `BackupPolicy` with `create`/`viewAny`/`download`/`restore` abilities all checking
  `isAnyOf('super')`, and require fresh re-authentication (password confirm) before restore.
- Follow the existing `AuditLog`/`AuditLogService` append-only pattern: write an entry for
  `backup.create`, `backup.download`, and `backup.restore`, each with `user_id`, `ip_address`,
  `user_agent`, and `new_values` containing the backup filename/checksum — **never file contents**.
  A restore should log both a pre-restore marker and a completion entry, since it is rare and
  destructive enough to warrant its own explicit audit rows rather than relying on generic
  model-level auditing.
- An unencrypted dump is a portable, ungoverned copy of every customer's name/phone/payment
  history that bypasses every access control the live app enforces the moment it exists outside
  the app (emailed, synced to a laptop, left in a downloads folder). Mitigations: encrypt before it
  ever leaves the server (§4), forbid any workflow that emails/manually copies a dump (download
  must go through the audited, `super`-only controller action only), and enforce a short rolling
  retention window on both local staging copies and the offsite bucket so any one exposure has a
  bounded shelf life.

## 6. Restore process (concrete sequence)

Tenant provisioning is schema-per-tenant (`Tenant::create()` fires Stancl's
`CreateDatabase → MigrateDatabase → SeedDatabase` pipeline, schema named `tenant<slug>`). Restore
mirrors that shape:

1. Put the tenant in maintenance (`tenants.is_active = false`, an existing flag).
2. **Don't destroy first** — rename the live schema aside as a safety net:
   `ALTER SCHEMA tenant<id> RENAME TO tenant<id>_pre_restore`.
3. `CREATE SCHEMA tenant<id>`, then `pg_restore --schema=tenant<id> -d cncms <dump file>`.
4. **Catch up to current code**: run tenant migrations (`database/migrations/tenant/`) — the
   restored dump's own `migrations` table shows exactly which ones postdate the backup and are
   still missing.
5. **Reconcile in-flight state** — restore must not assume a clean shutdown. Any `command_runs` row
   with `status IN ('queued', 'pending_review')` older than the restore point gets explicitly
   marked `failed` with a `metadata.note` explaining it was abandoned by the restore. Any
   incomplete `job_batches` row is treated the same way — **never auto-resumed**, since resuming
   would compute/publish against a mix of pre- and post-restore data, violating the same
   compute/publish integrity guarantee `references/task-scheduler.md` already establishes for the
   scheduler. An admin must manually re-trigger anything abandoned this way.
6. Flip `is_active` back to true, resume queue workers, notify the admin, and keep
   `tenant<id>_pre_restore` around for a few days before dropping it.

## 7. Proving backups actually work — the restorability drill

A backup nobody has ever restored from is not a real backup. Add `backup:test-restore` as a
monthly, off-hours job: restore the latest dump into a throwaway schema, assert row counts
(customers, manuscripts, payments) and the latest manuscript period match expectations, then drop
the throwaway schema. Log the result as a `CommandRun` (`command = 'backup:test-restore'`,
`metadata = {success, row_counts, duration_ms}`) exactly like `manuscript:calculate` already does —
this reuses the existing Command Runs admin view with zero new UI. A failed drill must actively
alert (§9) — an unread failed row in a list is indistinguishable from nobody having checked.

## 8. Recovery targets

Sized to what this actually is — single-office, monthly-batch billing with daily trickle payments,
not a bank or hospital:

- **RPO: 24 hours.** A nightly `pg_dump` is sufficient; continuous WAL/point-in-time-recovery
  archiving is a nice-to-have, not required at this scale — losing at most one night's transactions
  on failure is an acceptable, understood risk here.
- **RTO: 4 business hours**, next-morning if the incident happens overnight or on a weekend.

## 9. Failure detection & alerting

- **Two independent checks**, because one alone can't cover both failure classes:
  - *Inside* `backup:run`: check `pg_dump`'s exit code AND the resulting file size against a sane
    floor — this catches "ran but produced garbage" (disk full, truncated write) that a bare
    exit-code check would miss.
  - *Outside*, on a separate later tick: a watchdog asking "was there a successful backup record
    for today?" — this is the check that matters most, since it's the only one that catches the
    scheduler itself never firing at all (a deployment/cron misconfiguration), which nothing
    *inside* `backup:run` could ever detect because it never ran.
- **Alert via the existing in-app emergency notification channel**
  (`NotificationService::broadcastToRole('admin', 'backup.failed', 'emergency', ...)`), **not**
  WhatsApp. This app's WhatsApp bill reminders are explicitly manual-click-to-send with no
  automated dispatch path (`BillNotificationService` composes a `wa.me` link a human must open and
  send) — reusing that channel for an unattended 2am failure would just relocate the manual step to
  a process with nobody there to click it. The in-app emergency banner is the only channel in this
  app that is actually server-initiated today, and the owner already drives this app directly, so
  it will be seen at next login — acceptable latency against the RTO in §8.

## 10. Retention

Daily × 14, weekly × 8, monthly × 12 — the monthly tier aligned to the billing cycle so any past
manuscript-generation period remains recoverable for a full year. Applies to both the offsite
bucket and any local staging copy; nothing lingers past its tier without being pruned.

---

## Build order, when this gets picked up

1. `composer require spatie/laravel-backup`, configure DB dump + the receipts storage path.
2. Offsite `s3`-compatible disk credentials + dedicated bucket (§4).
3. `backup:run` command + hardcoded schedule entry (§3), pre-batch trigger hook.
4. Backup-specific encryption key generation/storage, wired into the dump step (§4).
5. `BackupPolicy` + audited controller actions for create/list/download/restore (§5).
6. Restore runbook/command implementing §6's exact sequence.
7. `backup:test-restore` monthly drill + `CommandRun` logging (§7).
8. Watchdog + in-app emergency alert wiring (§9).
