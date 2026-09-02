# SWECOM real-data migration into production — task spec (NOT YET DONE)

Status: **Planned, own session.** Big task. Written 2026-09-01 so a future
session can execute it without re-deriving the shape.

## Goal

Get SWECOM PLC's **real** operational data — zones, customers, agents,
payment history, and the arrears/credit baseline — into the production
`swecom` tenant (on Supabase, per `deployment.md`), and in doing so
**prove every bulk-import path works end to end with real, messy data**.

## What actually exists today (read this first)

- **The dev box's `swecom` transactional data is SYNTHETIC.**
  `database/seeders/DemoTransactionalDataSeeder.php` says it outright:
  "THESE ARE NOT REAL SWECOM CUSTOMER RECORDS." ~549 factory customers, a
  simulated payment history, factory agents, then real `manuscript:calculate`
  runs over that history. Do **not** migrate the dev data — it's demo data.
- **Reference data** (the 29 real zones, expense categories, a company row)
  comes from `database/seeders/TenantDatabaseSeeder.php` and runs
  automatically on tenant creation. The zone *names* there are real.
- **`august_manuscript.csv`** (repo root, gitignored) — per
  `project-manuscript-monthly-cycle`, this is v1's real 2026-07-22 run
  figures, used as the 2026-08 baseline. It is real arrears/credit numbers
  but only ~446 rows and only 4 money columns.
- **The real source of truth is the owner's v1 (MariaDB) system** — an
  export from there is the input to this whole task, and it is **not in
  this repo/environment**. Step 0 is getting it.

## Import mechanisms — what exists, what's missing

| Data | Mechanism | Contract | Gap |
|---|---|---|---|
| **Zones** | `POST /zones/import` (`ZoneImportService`), template at `/zones/import/template` | `zone_upload.xlsx`: `name`, `town` | — (but reference zones already seeded; import would be additive/duplicate — dedupe or skip) |
| **Customers** | `POST /customers/import` (`CustomerImportService`), template at `/customers/import/template` | `customer_upload_main.xlsx`: `name`, `phone`, `level`, `location`, `zone` (matched by name → `zone_id`), `bill`, `others` (seed/opening balance — **the critical column**), `status`. Tracked in `uploads`; file → `imports/customers` on `local` disk. `arrears`/`Credit`/`Total Bill` columns are read-only, leave blank. | — |
| **Agents** | Manual entry only (`/agents`) | salary, photo, marital status, `zone_id`, `sync_token` | **NO bulk import — build one, or enter by hand** (SWECOM has a small number of agents, hand entry may be fine) |
| **Payment history** | — | — | **NO bulk import path.** Decision needed: import full history, or start from a baseline + only the current open period's payments? |
| **Manuscript / arrears-credit baseline** | `php artisan manuscript:import-august --tenant=swecom --file=<csv> --period=<YYYY-MM> [--force]` | CSV: `cus_id,name,zone,status,bill,total_arrears,credit,total_bill`. Pure inserts, `command_run_id = NULL` (stays out of any run's rollback scope). | `cus_id` must map to the imported customers' ids — so this runs **after** the customer import, and needs a CSV keyed correctly |
| **Live manuscript for the open period** | `php artisan manuscript:calculate <YYYY-MM> --tenant=swecom` | carries the baseline forward + applies verified payments | — |

## Proposed sequence

0. **Get the v1 export.** Ask the owner for: a customer list (name, phone,
   zone, bill, opening balance, status, level, location), a zone list, an
   agent list, and — if importing history — a payments export (customer,
   amount, date, frequency/months, verification state). Plus the current
   arrears/credit position per customer (the baseline).
1. **Provision the `swecom` tenant via the Landlord "Add Tenant" flow**
   (`/landlord/tenants` → creates it `registration_status = 'approved'`,
   bypassing the self-service pending gate). Tenant id: keep `swecom`.
   This fires the queued `TenantCreated` pipeline → schema + migrations +
   `TenantDatabaseSeeder` (zones, categories, company). **Queue worker must
   be running.**
2. **Zones** — the 29 reference zones are already seeded. If the v1 zone
   list differs (extra zones, renames), reconcile: import the delta, or
   fix `TenantDatabaseSeeder`'s list first. Verify `zones` count + names.
3. **Customers** — build `customer_upload_main.xlsx` from the v1 export.
   Scrub the known junk (`empty 1`..`empty N`, `TOTAL THREE CORNERS`,
   subtotal rows — see `database-schema.md`). Normalize phones (digits,
   leading 6, 9 long) — ~78% will be blank, that's expected. `others` =
   each customer's opening balance. Import via `/customers/import`. Check
   the `uploads` row's `succeeded_count` / `failed_count` / `errors` and
   fix + re-import failed rows.
4. **Agents** — enter by hand at `/agents` (or build a bulk import if the
   count is large), linking each to its zone.
5. **Baseline manuscript** — produce a CSV `cus_id,name,zone,status,bill,
   total_arrears,credit,total_bill` where `cus_id` = the **new** customer
   ids (query them after step 3), and the arrears/credit are the real v1
   closing position. Run `manuscript:import-august --tenant=swecom
   --file=... --period=<the period BEFORE the first live one>`.
6. **Payments** — decision point:
   - *Baseline-only (simpler):* skip history; the baseline already encodes
     net position. Only load the current open (unvalidated) period's real
     payments — via the Payments UI or a small one-off import command.
   - *Full history:* needs a payments bulk-import command (doesn't exist).
     Heavier; only worth it if the owner needs the payment ledger for
     audit/reporting from day one.
7. **First live run** — `manuscript:calculate <open period> --tenant=swecom`.
   Publish it. Compare its register against the owner's known-good v1
   numbers for that period (`august_manuscript.csv` is the reference shape).
8. **Reconcile** — spot-check 10–20 named customers end to end (zone, bill,
   arrears, credit, total). Check tenant-wide totals against v1.

## Decisions the owner must make

1. Is a real v1 export available, and in what format (SQL dump / xlsx / CSV)?
2. Agents: bulk-import worth building, or hand-enter?
3. Payment history: full ledger, or baseline + current period only?
4. Which period is the first *live* v2 period on production (and therefore
   what period does the baseline get written as)?
5. Keep tenant id `swecom`, or a fresh slug?

## Verification checklist (per step)

- [ ] `zones` — count and every name matches v1.
- [ ] `customers` — count matches (minus scrubbed junk); `uploads` row has
      0 failed, or every failure explained + fixed; 10 random customers
      spot-checked (zone link, bill, `others`, status).
- [ ] `agents` — count matches; each has a zone.
- [ ] baseline `manuscripts` — one row per active customer for the baseline
      period, `command_run_id IS NULL`, arrears/credit match v1 closing.
- [ ] first `manuscript:calculate` — 0 errors; register totals reconcile
      with v1; a few disconnected/frozen customers behave (0 total_bill).
- [ ] bill print + register export render for the real dataset.

## Rollback

The tenant schema is disposable during this exercise:
`php artisan tenants:migrate-fresh --tenants=swecom` (drops + rebuilds +
re-seeds reference data), then start the sequence again. Do NOT run
`tenants:prune-disposable` — its allowlist keeps `swecom`, but on a
production box you don't want that command anywhere near real tenants.

## Related

- `deployment.md` — where production runs.
- `project-manuscript-monthly-cycle` (memory) — the baseline/cycle logic.
- `database-schema.md` §"Known data quality issues" — the junk to scrub.
- `.claude/skills/cncms-context/references/self-service-onboarding.md` — the
  Landlord "Add Tenant" flow used in step 1.
