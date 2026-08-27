# Task Scheduler — Design Spec

Status: **Design, not yet implemented** | Owner ask: admin-configurable scheduling for recurring
system jobs — manuscript/bill generation on a set day, with a preview-before-publish step, plus
room to grow into report generation and bulk notification sending — instead of everything staying
manual-trigger-only forever.

---

## 1. Why one generic scheduler, not a bespoke one per feature

Two independent findings converged on the exact same missing piece:

- The owner asked for admin-configurable day/time scheduling for manuscript generation (and,
  generalized, bill/report generation and bulk notifications too — their own words: "there's no
  scheduler feature for tasks... it might sound like same thing above, just repeating myself").
- The complaint-desk escalation engine (`references/complaint-desk.md` §escalation) independently
  needs a recurring time check to advance complaints through escalation levels, and its own
  research confirmed: **this app has no live Laravel Scheduler wired at all today**
  (`bootstrap/app.php` has no `withSchedule()`/cron entries; `manuscript:calculate` is purely
  manual-invoke). `SettingsCommandRunController`'s own existing doc comment already anticipates
  this — "read-only history of manuscript:calculate (and any future scheduled command)
  executions" — the anticipation predates the actual scheduler existing.

Building a real, generic scheduler once — instead of a bespoke "manuscript day-of-month" checker
and a separate "escalation tick" checker — means every future recurring job (bill generation,
report generation, bulk notification sends) is a new *task type* plugged into one existing system,
not a new cron entry and a new admin settings page each time.

## 2. Data model

`scheduled_tasks` (tenant-scoped table):

- `id`, `uuid`
- `task_type` — closed enum/string, e.g. `manuscript_generation`, `complaint_escalation_check`
  (system-owned, always present, not admin-toggleable-off — see §5), and room for
  `bill_generation`, `report_generation`, `bulk_notification` as they're actually built (don't
  pre-create rows for task types that don't have real logic behind them yet)
- `enabled` — boolean, admin can turn a task on/off
- `schedule_config` — JSON, shape depends on `task_type`'s scheduling need: a day-of-month int
  (1-28, per the already-established manuscript-generation clamp reasoning — months can be shorter
  than 31 days), a specific run time, or (for the escalation checker specifically) nothing at all
  since it runs on a fixed short interval rather than a configurable date — see §5.
- `last_run_at`, `next_run_at` (computed/cached for display, not the source of truth for whether a
  run is due — the actual due-check re-derives this from `schedule_config` + `last_run_at` at tick
  time, so a manually-edited `next_run_at` can never desync the real schedule)
- `created_at`/`updated_at`

This lives alongside, and is displayed through, the **existing** `command_runs` table/UI
(`Settings/CommandRuns.tsx`) — a scheduled task's actual execution still logs a `command_runs` row
exactly like a manual run does, so there is one execution-history view for both manual and
scheduled runs, not two. `scheduled_tasks` is the *configuration* (when should this run);
`command_runs` remains the *history* (what actually happened when it ran) — don't conflate them
into one table.

## 3. The actual cron tick

One real Laravel Scheduler entry, registered in `routes/console.php` (or wherever this app's
Laravel 13 convention lives — confirm against the real installed version), running **every
15 minutes** (matches the granularity both the manuscript-scheduling and escalation-checking needs
actually require — nothing here needs sub-15-minute precision):

```
Schedule::command('tasks:run-due')->everyFifteenMinutes();
```

`tasks:run-due` (new artisan command, `namespace:action` convention already used by
`manuscript:calculate`) does, per tenant (reuse whatever tenant-iteration helper the rest of this
app already uses for cross-tenant scheduled work — check for a Stancl `runForMultiple()`-style
existing pattern before inventing a new one):

1. Query `scheduled_tasks WHERE enabled = true`.
2. For each, ask that task type's own due-check (`isDue(ScheduledTask $task): bool` — a small
   interface every task type implements) whether it should run right now, given `schedule_config`
   and `last_run_at`.
3. If due, dispatch that task type's actual job/handler (each task type owns its own execution
   logic — the scheduler's job is purely "is it time," never "how does manuscript generation
   work"), record a `command_runs` row, update `last_run_at`.

This mirrors `ManuscriptCalculate`'s per-tenant, per-record defensive execution style — one bad
tenant's misconfigured schedule must not block another tenant's due task from running in the same
tick.

## 4. First task type: `manuscript_generation`, with WYSIWYG preview/publish

This carries forward the design already worked out (and briefly, then deliberately, paused mid-
build to prioritize the mobile app — see prior session notes if resuming from a stale context) for
the original, narrower "scheduled manuscript generation" ask. The core guarantee, unchanged:

> **Whatever an admin sees in the preview must be exactly what gets published.** Publishing must
> NOT silently recompute against whatever the live data looks like at publish time — a payment
> that arrives between the scheduled run and the admin's review must not change the numbers they
> already reviewed and approved.

Concretely: the calculation logic splits into **compute** (runs the full per-customer arrears/
credit/total_bill/frozen-status calculation, does NOT write to the live `manuscripts` table, stores
the computed result set durably — a JSON column on `command_runs` or a small staging table,
whichever the calculation code's actual shape makes cleaner once read) and **publish** (takes that
exact stored computed result set — never a fresh recomputation — and commits it to `manuscripts`
via the same upsert logic `manuscript:calculate` already uses).

- Add a `status` to `command_runs` (`pending_review` / `published`, or reuse if something equivalent
  already exists — check before adding a new column) so a scheduled `manuscript_generation` run
  lands as `pending_review`.
- **This preview/publish gate applies to the scheduled path only.** The existing manual "run
  manuscript:calculate now" trigger stays exactly as it is today — immediate compute+commit, no
  review step. Don't retrofit the review gate onto manual runs; that changes established behavior
  nobody asked to change.
- Bills need no separate "generation" step — they already render on-demand from `manuscripts` data
  via the existing bill-template system. Publishing the manuscript data is sufficient for that
  period's bills to become correct/printable; no PDF pre-rendering/storage step is needed.
- `schedule_config` for this task type: `{"day_of_month": 25}` (the owner's own example). Clamp/
  document behavior for a day value that doesn't exist in a shorter month (recommend: run on the
  last day of that month instead of skipping entirely, so a "day 30" schedule still fires once in
  February rather than silently never firing that month).

**Admin UI**: extend the existing `SettingsTabs`/Command Runs settings page (not a new nav item —
this is the natural home given it's directly about this same feature) with: a schedule-config
control for `manuscript_generation` (day-of-month picker), and — separately, on the Command Runs
list itself — a distinct "Awaiting Review" visual state for `pending_review` runs, a "Preview" view
(the nicely-formatted summary this session already fixed the raw-JSON display for — reuse that
formatting work), and a "Publish" button gated to whatever role already triggers manual runs today.

### 4.1 Execution mechanism — chunked, queued, `Bus::batch()`, not a synchronous loop

**This matters at real scale and was flagged explicitly by the owner: a tenant with thousands of
customers must never have manuscript generation run as one long synchronous loop inside an HTTP
request or a single blocking artisan process.** That's exactly the failure mode this app has
already been bitten by once this session in a smaller form (`ManuscriptController::export()`'s
missing `ini_set('memory_limit', ...)` bump, flagged three separate times by different agents) —
one long unbounded operation with no chunking is fragile by construction: a timeout, a memory
ceiling, or one bad customer record partway through can take the whole run down with nothing to
show for it and nothing to resume from.

**Use `Bus::batch()`, not `Bus::chain()`.** Chain is for strictly sequential jobs where each step
depends on the previous one finishing (job 2 must not start until job 1 succeeds) — that's not this
shape. Batch is the correct primitive here: many independent chunk-jobs (customer 1-500 don't
depend on customer 501-1000's calculation finishing first) that the queue can run in parallel
across however many workers are available, with:

- **Built-in progress tracking** — Laravel creates a `job_batches` table automatically (id,
  `total_jobs`, `pending_jobs`, `failed_jobs`, `processed_jobs`, `finished_at`) — this is real,
  free execution-progress visibility, distinct from (and feeding into) the `command_runs` row a
  human actually looks at in Settings.
- **Partial-failure tolerance** — `allowFailures()` on the batch means one chunk job throwing an
  exception doesn't cancel the other chunks still processing. This is layered on top of, not a
  replacement for, `manuscript:calculate`'s existing per-customer defensive handling (a single bad
  customer record inside a successful chunk is already logged/skipped without halting that chunk,
  per the existing `errors`/`error_details` fields in `command_runs.metadata` — batch-level failure
  handling is for an entire chunk job crashing outright, a different, coarser failure mode).
- **A `then()`/`catch()`/`finally()` completion hook** — `then()` fires once every chunk succeeds:
  this is where the run transitions from "running" to `pending_review` (§4). If any chunk failed
  outright (not just per-customer application errors within a chunk), do **not** silently
  auto-transition to `pending_review` — surface the run as needing attention instead, since
  publishing a manuscript period built from incomplete chunk data would silently under-report real
  customers.

**Concrete shape**: the `manuscript_generation` task handler chunks the tenant's customer set
(e.g. 200-500 per chunk — tune to this app's real per-customer computation cost, don't guess a
number and never revisit it) into N jobs, each computing its slice's arrears/credit/total_bill
results and writing them into the same durable computed-result store §4 already specifies (the
JSON-column-or-staging-table decision), dispatched via `Bus::batch([...])->allowFailures()
->then(fn ($batch) => ...)->catch(fn ($batch, $e) => ...)->dispatch()`. The **manual** "run
manuscript:calculate now" trigger should also move to this same chunked/batched mechanism — the
robustness problem (thousands of customers, one long synchronous run) applies exactly as much to a
manually-triggered run as a scheduled one; only the preview/publish *review gate* stays
scheduled-path-only (§4's existing rule), not the execution mechanism itself.

**Prerequisite to verify, not assume**: confirm this app has a real, working queue connection
configured (`QUEUE_CONNECTION` in `.env`, and an actual queue worker process running — check
whatever this app's deployment target, per `deploying-laravel-cloud`, provides for background
workers) before building this — `QUEUE_CONNECTION=sync` (Laravel's default when nothing else is
configured) would silently defeat the entire point by running every "queued" job inline,
synchronously, immediately, which is the exact failure mode this section exists to avoid.

**This same chunked-batch pattern should generalize** to `bill_generation`/`report_generation`/
`bulk_notification` once those task types are actually built (§6) — not re-litigated per task type.

## 5. Second task type: `complaint_escalation_check`

System-owned — always present, always enabled, not something an admin can disable via this UI
(escalation correctness shouldn't be one settings toggle away from silently breaking). Its
`schedule_config` is effectively empty; `isDue()` simply always returns true on `tasks:run-due`'s
regular tick, since escalation-checking needs to run every tick, not on a configurable date. See
`references/complaint-desk.md` for what this task type's handler actually does (compare complaint
ages against the 4-level threshold table, fire notifications, log to `complaint_escalations`).

## 6. Future task types — not built now, just make room

`bill_generation` (likely redundant with `manuscript_generation` per §4's "bills render on-demand"
point — probably never needs to be its own task type, confirm before building it as a separate
thing), `report_generation` (reports in this app are already computed on-demand and cached with
short TTLs per `references/` reporting design — a "scheduled report generation" job would most
plausibly mean "pre-warm the cache" or "email/notify a monthly report summary," not generate a
static artifact; don't build this speculatively, wait for a concrete ask), `bulk_notification`
(ties into the *separate*, already-designed `references/bill-notifications.md` system — a
scheduled bulk-send job would slot in as a task type here once that system's bulk-Twilio mode is
actually built, not before).

## 7. Explicitly out of scope for v1

Cron-expression-level configurability (day-of-month + a fixed time is enough; don't build a full
crontab UI), per-branch schedules (one tenant-wide schedule per task type, matching how other
tenant-wide singleton settings in this app already work), and any task-type plugin system for
admins to define their own custom scheduled jobs — task types are code-defined, not admin-created,
matching this app's established "keep it simple, don't over-build for a scale this app doesn't
have" ethos.

---

## Addendum (2026-08-27): manuscripts uniqueness constraint + the "already safely runnable" rerun guard

By this date, everything above sections 1-7 describe was already built: `payments.processed_period`
makes same-period `manuscript:calculate` reruns arithmetically idempotent, and
`idx_command_runs_period_inflight` (a partial unique index on `command_runs(command, period) WHERE
status IN ('queued', 'pending_review')`) stops two SIMULTANEOUS runs from racing for the same
period. Two gaps remained, both closed in this pass:

**1. `manuscripts(customer_id, period)` had no real DB-level uniqueness constraint** — only the
non-unique `idx_manuscripts_customer_period` composite index existed. Every write path happened to
use `firstOrNew()`/upsert-by-(customer_id, period) semantics, but nothing at the database layer
actually enforced it. Added `uq_manuscripts_customer_period`, a genuine unique constraint, via
`database/migrations/tenant/2026_08_27_010000_add_unique_constraint_to_manuscripts_customer_period.php`.
Verified zero duplicate `(customer_id, period)` rows and zero NULL-period rows existed in any of
the 5 provisioned tenant schemas before adding it — no dedup step was needed. Migrated for all
tenants via `php artisan tenants:migrate --force`.

**2. `manuscript:calculate` had no "was this period already run?" check at all** — distinct from
the in-flight lock above, which only guards a transient queued/pending_review window. Once a run
for a period finished and published, nothing stopped it from being silently re-triggered (a stray
re-click, a cron misfire, a second CLI invocation days later) — arithmetically harmless thanks to
`processed_period`, but process-unsafe: a rerun with no signal to the operator that this period was
already done. Closed with a new, small guard —
`App\Services\ManuscriptRerunGuard::assertRerunAllowed(string $period, bool $override)` — used
identically by both entry points that can trigger `manuscript:calculate`:

- **`App\Services\ManuscriptGenerationBatchService::dispatch()`** now takes a `bool $override =
  false` parameter and calls the guard before doing any work (before even inserting the
  command_runs row). `App\Http\Controllers\ManuscriptController::calculate()` accepts a
  `confirmed_rerun` request field, validated as a real boolean (`sometimes`, `boolean` — a loose
  truthy string like `"yes"` is rejected, not coerced), and passes it through as `override`.
- **`App\Console\Commands\ManuscriptCalculate`** — deliberately NOT rewritten to route through
  `Bus::batch()`: ~15 existing tests rely on this command's synchronous, block-until-done behavior,
  and an operator running it directly on a server legitimately wants synchronous execution. Instead
  it now: (a) calls the same guard before starting `runForEveryCustomer()`, refusing with a clear
  CLI message unless a new `--force` option is passed (Laravel's `migrate --force` idiom); (b)
  inserts its OWN `command_runs` row with `status = 'queued'` *before* starting synchronous
  computation, then updates that same row to `status = 'published'` (with the real metadata) on
  success or `'failed'` on a fatal exception (never left stuck at `'queued'`). Because
  `idx_command_runs_period_inflight` only cares about a `command_runs` row's status at any given
  moment — not what code inserted it or whether it's sync or async — this closes the CLI's
  previous complete invisibility to that lock: a concurrent web/scheduled `dispatch()` OR a second
  simultaneous CLI invocation for the same period now correctly collides against this row too.

Both guards are complementary and both remain in place: `idx_command_runs_period_inflight` stops
two runs racing *right now*; `ManuscriptRerunGuard` stops a *finished* period from being silently
repeated. A small `App\Support\DetectsUniqueViolation` trait was extracted (SQLSTATE 23505
detection) so both `ManuscriptGenerationBatchService` and `ManuscriptCalculate` share one
implementation of "was this a unique_violation" rather than duplicating it.

New tests: `tests/Feature/ManuscriptGenerationBatchServiceTest.php` (guard refusal/override at the
`dispatch()` level), `tests/Feature/ManuscriptCalculateTest.php` (guard refusal/`--force` at the
CLI level, the queued-row concurrency-lock engagement, and a fatal-failure-marks-`failed` case),
and `tests/Feature/Web/ManuscriptTest.php` (`confirmed_rerun` refusal/override/boolean-validation
at the HTTP layer). Two pre-existing `ManuscriptCalculateTest` tests that intentionally rerun the
same period without any override were updated to pass `--force` on their rerun call — the guard's
whole point is that such a rerun now requires explicit confirmation.
