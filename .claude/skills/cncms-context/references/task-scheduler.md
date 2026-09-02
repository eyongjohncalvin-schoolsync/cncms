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

---

## Addendum (2026-08-27, stage 2): the self-lockout fix — cancelling a permanently-stuck `queued` run

Confirmed real gap from the security review, independent of stage 1's fixes above: nothing anywhere
in this app could ever clear a `command_runs` row genuinely stuck at `status = 'queued'`. Stage 1's
`manuscript:calculate` CLI fix (the "insert 'queued' before computing, flip to 'published'/'failed'
after" change described above) only covers an exception PHP actually gets to run — a `kill -9`'d
CLI process, or a queue worker that crashes mid-batch, never reaches a `catch` block and leaves its
row stuck at `queued` forever, permanently blocking `idx_command_runs_period_inflight` for that
exact `(command, period)` pair — every future run for that period would keep colliding with a dead
row indefinitely.

Fixed with a manual, `super`/`admin`-gated "Cancel" action: `POST
/settings/command-runs/{run}/cancel` — `App\Http\Controllers\SettingsCommandRunController::cancel()`
— flips a `queued` row's `status` to `'failed'` (never any other transition; a row at
`pending_review` or any terminal status is refused unchanged) and stamps `metadata.cancelled_by`/
`cancelled_at`/`cancel_reason`. Confirmed by reading the index's own `WHERE` clause
(`WHERE status IN ('queued', 'pending_review')`, `2026_08_26_020000_add_inflight_unique_index_to_command_runs_table.php`)
that this flip frees the period **immediately** — a `'failed'` row simply falls outside the partial
index the instant the update commits, no separate release step needed.

Gated to `CommandRunPolicy::publish()` — reused deliberately rather than adding a new ability: it is
already exactly "the same roles as `ManuscriptPolicy::calculate()`" (`super`/`admin`) acting on this
same `command_runs` row's lifecycle. `manageSchedule()` was the other candidate but is conceptually
about `ScheduledTask` config, a different (if same-role-gated) surface.

**No time threshold added** (e.g. "only cancellable after N minutes with no progress") — deliberate,
reasoned judgment call: at this app's real scale (~6 users, all `super`/`admin` for this page), a
human noticing a stuck run and choosing to cancel it already IS the safety mechanism, the same
same-role judgment every other admin-only destructive action here (bulk disconnect, publish) relies
on with no extra cooldown. A minimum-age gate would add real complexity (what counts as "no
progress" — `job_batches` progress? a second timestamp column?) for negligible benefit, and could
actively block a legitimate immediate cancel (an admin who realizes within seconds they fat-fingered
the period). Matches section 7's "keep it simple, don't over-build for a scale this app doesn't
have" ethos.

A small `App\Models\CommandRun::isQueued()` helper was added alongside the pre-existing
`isPendingReview()`/`isPublished()`. `SettingsCommandRunController::index()` now also exposes a
`canCancel` Inertia prop (same underlying ability as `canPublish`, exposed under its own name so the
frontend doesn't have to infer the gate from an unrelated prop name).

**Frontend note for whoever builds the UI next**: this stage is backend-only — no `.tsx` file was
touched. `Settings/CommandRuns.tsx` needs a "Cancel" row action wired to this route, offered only
when a row's `status === 'queued'` (gated client-side on the new `canCancel` prop, exactly like the
existing `canPublish`-gated "Publish" button), before this action is actually usable by staff.

New tests: `tests/Feature/Web/CommandRunCancelTest.php` (authorization per role, and refusal for
every non-`queued` status via a data provider) and
`tests/Feature/CommandRunCancelUnblocksDispatchTest.php` (reproduces the lock with a manually-inserted
orphaned `queued` row, confirms a real `dispatch()` is rejected while it stands, then confirms
cancelling it immediately unblocks a subsequent real `dispatch()` for the same period — mirrors
`ManuscriptGenerationBatchServiceTest`'s real-committed-fixture style since a successful `dispatch()`
here runs real chunk jobs that cycle the tenant DB connection).

---

## Addendum (2026-08-27, stage 3): manual/scheduled convergence, the pre-run review UI, and the missing Cancel button

Three pieces, closing out everything stage 2 explicitly flagged as still needed:

**1. `ManuscriptController::calculate()` now dispatches with `autoPublish: false`** — the manual
"Run Manuscript Calculation" trigger no longer commits immediately. It lands at `pending_review`
behind the exact same gate the scheduled path already used, converging manual and scheduled runs
onto one identical execution+review mechanism (they already shared `dispatch()`'s chunked
`Bus::batch()` execution per section 4.1; only the review *gate* itself differed before this
change). The reasoning: an admin standing there and clicking Run is not a substitute for actually
looking at the computed numbers before they're committed — those are two different acts, and the
old "manual = auto-publish" behavior let the first stand in for the second.

Because the review gate is no longer optional for the manual path, `calculate()` no longer redirects
back to the Manuscripts list — it redirects to a **new, one-click review screen** keyed on the
`CommandRun` `dispatch()` just created: `GET /manuscripts/runs/{run}`
(`ManuscriptController::runReview()`, Inertia component `Manuscripts/RunReview.tsx`). This is
deliberately NOT a redirect to Settings > Command Runs — that page is built for reviewing a run from
hours ago among many; an admin who just clicked Run is standing there waiting for THIS one, a
different need. `runReview()` is gated to `ManuscriptPolicy::calculate()` (the same ability as
triggering the run at all — this is that action's own follow-through screen, not a general
command-run browsing surface) and 404s if the `CommandRun` isn't a `manuscript:calculate` row.

The screen: polls (Inertia's `usePoll` — the same primitive `AppLayout.tsx`'s notification bell
already used, `usePoll(20000, { only: ['notifications'] })`) while `status === 'queued'`, showing
`job_batches` progress; once `status === 'pending_review'`, shows the computed summary, the pre-run
review list (below) as still-relevant context, and a Publish button hitting the exact same
`POST /settings/command-runs/{run}/publish` endpoint `Settings/CommandRuns.tsx` already uses; once
`published` or `failed`, shows a terminal state with a link back.

A small `App\Support\ResolvesCommandRunBatchProgress` trait was extracted from
`SettingsCommandRunController`'s previously-private `batchProgress()` method (used unchanged there)
so `ManuscriptController::runReview()` reads `job_batches` progress identically rather than
duplicating that lookup.

A small `resources/tsx/components/manuscripts/ManuscriptRunSummary.tsx` component was extracted from
`Settings/CommandRuns.tsx`'s preview-modal JSX (the `computed_result_summary` grid) — now shared,
unchanged in appearance, between that modal and the new `RunReview.tsx` screen. Confirmed
`Settings/CommandRuns.tsx`'s own Preview modal still renders identically after the extraction.

**2. The pre-run review list (stage 2's `GET /manuscripts/pre-run-review`) is now surfaced in the UI**
— previously backend-only. `Manuscripts/Index.tsx`'s Calculate confirm modal now fetches it on-demand
(only while the modal is open, via a new `resources/tsx/hooks/usePreRunReview.ts` hook — plain
`fetch()`, not an Inertia visit, since this is a small on-demand JSON call feeding a modal, not a
page navigation) and shows the flagged count/total exposure prominently, above the existing warning.
A shared `resources/tsx/components/manuscripts/PreRunReviewPanel.tsx` component (reused by both the
modal and `RunReview.tsx`'s pending_review state) renders:

- **≤15 flagged customers** (chosen threshold — a modal-context list past this starts forcing heavy
  internal scrolling in the existing `Modal` component's `max-w-md` panel; 15 keeps it comfortably
  inline): a compact inline mini-table (this page's existing `Table`/`Th`/`Td` components at reduced
  padding/font size, not new ad-hoc markup).
- **>15**: collapses to summary-only with a "Review full list" link opening a NEW, full
  Disconnections/Index.tsx-shaped board — `GET /manuscripts/pre-run-review/full`
  (`ManuscriptController::preRunReviewFull()`, Inertia component `Manuscripts/PreRunReviewList.tsx`)
  — paginated table + zone filter, mirroring that page's exact established shape rather than
  inventing a new list-page pattern. Pagination is in-memory over the already-computed flagged
  collection (`LengthAwarePaginator` over a plain array) rather than a paginated Eloquent query,
  since `ManuscriptPreRunReviewService`'s flagging logic (credit/prepaid-window exclusion) isn't a
  single SQL WHERE clause and this tenant's real scale (~550 customers total) keeps computing the
  full list up front cheap.

Every flagged customer's name is a `window.open(...)` link to their profile — deliberately NOT
Inertia's `<Link>`, mirroring `Manuscripts/Index.tsx`'s own pre-existing WhatsApp "Send Bill" link
pattern — so fixing a miss opens in a new tab, leaving the modal/panel's already-fetched list (and,
for the modal case, the Calculate confirmation itself) alive in the original tab. A "Refresh list"
action re-fetches the same endpoint.

This is advisory, not a hard per-row gate: the submit button only waits for the review list's FIRST
load attempt to settle (data OR an error — either counts as "the admin has now seen it") before
becoming reachable, never for every flagged name to be individually dismissed — a legitimately long
list early in the month must stay usable, not become friction.

**Escalation for an already-published rerun**: if `submitCalculate()`'s POST comes back with a
validation error on `period` (the `ManuscriptRerunGuard` rejection — stage 1), the same modal
escalates to show that message plus a required checkbox ("I understand this period was already
calculated and published, and I want to recompute it anyway"). Only checking it and clicking again
resubmits with `confirmed_rerun: true` — the frontend never sets this automatically on the admin's
behalf.

**Test fallout from the `autoPublish: false` flip**: three existing `tests/Feature/Web/ManuscriptTest.php`
tests asserted the old immediate-auto-publish behavior and needed updating —
`test_admin_can_run_the_manuscript_calculation` now asserts the run lands `pending_review` with NO
`manuscripts` row yet, then explicitly publishes via the real `POST /settings/command-runs/{run}/publish`
endpoint before asserting the `manuscripts` row exists (exercising the actual new two-step flow an
admin now uses, not just the first half of it); the two rerun-guard tests
(`test_admin_rerunning_an_already_published_period_is_rejected_without_confirmed_rerun` and
`test_admin_can_rerun_an_already_published_period_with_confirmed_rerun_true`) now explicitly publish
the first run before attempting the second `calculate()` call, since `ManuscriptRerunGuard` only
fires against a genuinely `published` prior run and the manual trigger no longer reaches that status
on its own.

New tests: `tests/Feature/Web/ManuscriptRunReviewTest.php` (authorization, the shaped `run` prop, the
different-command 404 guard) and three new cases appended to
`tests/Feature/Web/ManuscriptPreRunReviewTest.php` for the `/full` board (authorization, the flagged
fixture appearing in `customers.data`, zone filtering).

**3. The Cancel button** stage 2 flagged as backend-only and still needing a frontend: added to
`Settings/CommandRuns.tsx`'s row actions, shown only when `run.status === 'queued' && canCancel`.
Uses a lightweight `confirm()`-gated `router.post`, not a full confirmation modal — this page has no
modal component of its own to reuse for it, and (per stage 2's own "no time threshold, same-role
human judgment is the safety mechanism" reasoning) the action doesn't warrant introducing one; this
matches the lighter confirm-gated pattern already established elsewhere in this app (e.g.
`Agents/Index.tsx`'s "Remove agent").

**Left unresolved / flagged for the coordinator**: no dedicated HTTP-level test previously covered
`POST /settings/command-runs/{run}/publish` at all (only `ManuscriptGenerationBatchService::publish()`
was tested directly) — this stage's `ManuscriptTest.php` updates now exercise it incidentally via the
new two-step flow, but a focused test file for that endpoint's own authorization/state-guard behavior
(mirroring `CommandRunCancelTest.php`'s shape) would still be a reasonable gap to close later.

---

## Stage 4 addendum (2026-08-27): N+1 / query-efficiency audit of stages 1–3

Dedicated audit of every piece stages 1–3 touched or added, at this tenant's real scale
(~446–549 customers). **Result: no genuine N+1s found — nothing needed fixing.** Every hot path
already followed the "batch-resolve once, never per-customer" discipline its own doc comments
claimed. Ground truth (not just code-reading inference) came from a throwaway diagnostic test that
seeded 450 customers/manuscripts/payments on the real `swecom` tenant schema, wrapped
`ManuscriptPreRunReviewService::reviewList()` in `DB::enableQueryLog()`, and asserted the query
count stays flat. Deleted after the audit; not part of the permanent suite.

**1. `ManuscriptPreRunReviewService::reviewList()`** — confirmed 5 flat queries total regardless of
customer count (measured: 450 seeded customers, 496 flagged, still 5 queries): (1) the active-customer
list via `CustomerRepository::activeWithLatestManuscript()`, (2) its eager-loaded `zones` batch, (3)
its eager-loaded `latestManuscript` batch (a `latestOfMany()`-style subquery join), (4) one
`whereIn(...)->eligibleForPeriod($period)->pluck('customer_id')` for the "who has an eligible
payment" exclusion — a single set query, not a per-customer loop, and (5) one
`selectRaw('customer_id, MAX(created_at)...')->groupBy('customer_id')` aggregate for
`last_payment_date` — exactly the `withMax`-equivalent pattern the brief asked to confirm, not a
per-customer fetch. The prepaid-window and credit exclusions run in-memory against the
already-eager-loaded `latestManuscript` relation (no query at all). Nothing to fix here.

**2. `Payment::scopeEligibleForPeriod()`** — checked all three call sites
(`ManuscriptChunkDataResolver::resolve()`, `ManuscriptCalculate::runForEveryCustomer()`,
`ManuscriptPreRunReviewService::reviewList()`): every one applies the scope as a single
`whereIn('customer_id', $customerIds)->eligibleForPeriod($period)` over the whole candidate set,
never inside a per-customer loop. Spreading the predicate to a third caller did not spread an N+1 —
the extraction stayed single-query at every site.

**3. `Manuscripts/RunReview.tsx` polling + `ManuscriptController::runReview()` +
`ResolvesCommandRunBatchProgress`** — each poll tick (`usePoll(3000, { only: ['run'] })`, active only
while `status === 'queued'`, stopped via a `useEffect` the instant it leaves `queued`) triggers a
single Inertia partial reload that does exactly one `CommandRun` lookup plus one
`DB::table('job_batches')->whereIn('id', [...])->get()` call — bounded and cheap regardless of
customer count. The expensive pre-run review list (`usePreRunReview`) is fetched once when the run
reaches `pending_review` (an `active` boolean gate, not the poll interval) — it is never re-fetched
on a timer.

**4. `CustomerManuscriptRecalculationService::recalculateOne()`'s `CommandRun::create()` and the
actor/adjustment-id threading through `RecalculateCustomerManuscriptsForwardJob`** — the job fetches
`$customer` once via `Customer::query()->find()` **before** its `while` loop over periods; the loop
body only reads `$this->arrearsAdjustmentId`/`$this->triggeredByUserId`, both plain scalar
constructor properties serialized onto the job, never re-queried per iteration. The one new query per
iteration is `CommandRun::create()` itself — an intentional one-INSERT-per-period audit trace, bounded
by "periods since the adjustment's target period" (genuinely O(periods), not O(customers), per this
job's own design doc), not an N+1.

**5. `PreRunReviewList.tsx` / `ManuscriptController::preRunReviewFull()`** — confirmed this
paginates **in-memory** over `reviewList()`'s already-computed flagged collection (a manually
constructed `LengthAwarePaginator` around `$items->forPage($page, $perPage)`), not a database-level
`->paginate()`. Checked `DisconnectionsController::paginate()` (its own eligibility board) and found
the **identical** pattern already in production there — same `forPage()`-into-`LengthAwarePaginator`
shape. This is a **pre-existing, pattern-level choice** (not something stage 3 introduced), it's
correctly bounded at this tenant's real scale (~550 customers total, so at most that many flagged
rows ever materialize in PHP), and `preRunReviewFull()`'s own doc comment already states the
rationale explicitly. Left alone; flagging for a separate future pass only if either board's
candidate set ever stops being tenant-bounded (e.g. if it started spanning multiple tenants at once).

**6. General sweep** — grepped every added/changed line across all three stage commits touching
`Manuscript`/`Payment`/`Customer`/`CommandRun` for relation access (`->zone`, `->latestManuscript`,
etc.) outside an eager-loaded context. The only lazy-relation accesses found were the ones in
`ManuscriptPreRunReviewService` already covered by area 1's `with(['zone', 'latestManuscript'])`
eager load; the remaining matches were test-file zone lookups (`$this->zone()`), not production code.
`ManuscriptRerunGuard::assertRerunAllowed()` (new in stage 1) issues exactly one `CommandRun` lookup
per `dispatch()` call, not per customer.

**Tests**: no production code was changed, so no regression risk was introduced. Ran the full
relevant sweep anyway to confirm the baseline is intact: `ManuscriptCalculateTest.php` +
`ManuscriptGenerationBatchServiceTest.php` (31 tests), `ManuscriptPreRunReviewTest.php` +
`ManuscriptRunReviewTest.php` + `CommandRunCancelTest.php` + `CommandRunCancelUnblocksDispatchTest.php`
+ `ArrearsAdjustmentCommandRunAuditTest.php` + `CustomerManuscriptRecalculationServiceTest.php` (24
tests) — all 55 passing. Separately noted (not caused by, or related to, this audit):
`ManuscriptTest.php`'s pre-existing `test_manager_can_export_the_manuscript_register_as_a_pdf`
(present since `c1b297b6`, well before stage 1) crashes this environment's PHPUnit process-isolated
subprocess with a dompdf `Allowed memory size of 134217728 bytes exhausted` fatal — a CLI
`memory_limit` environment issue, unrelated to query efficiency; flagged for a separate pass rather
than fixed here.

## Stage 5 addendum (2026-08-27): caching audit of stages 1–4 — one real bug found, outside the diff

Prompted by a real bug fixed earlier the same day elsewhere in this codebase:
`CustomerStatusService::forgetCache()` and `CustomerManuscriptRecalculationService`'s cache-forget
call both used to forget a bare `"customers:show:{uuid}"` key while
`CustomerService::findOrFail()` actually caches under `'customers:show:'.$uuid.':'.branchId` (`:all`
or a real branch id) — the forgets were silent no-ops. This audit checked whether the identical bug
class (a `Cache::` SET with one key shape, a FORGET with a different, non-matching shape) exists
anywhere in the stage 1–4 manuscript-run-safety code, and whether the new manuscript code interacts
correctly with this app's existing caching generally.

**1. `ManuscriptService::forgetSummaryCache($period)`** — every new/changed write path that should
invalidate it does, with the correct `$period`: `ManuscriptCalculate::handle()` (after
`runForEveryCustomer()` succeeds, before marking the row `published`), `ManuscriptGenerationBatchService::publish()`
(after the commit transaction, keyed off `$commandRun->period`), and
`CustomerManuscriptRecalculationService::recalculateOne()` (called from both real callers —
`ArrearsAdjustmentService::applyLedgerEffect()`'s synchronous current-period call, and
`RecalculateCustomerManuscriptsForwardJob`'s per-period loop, which passes that iteration's own
`$periodString`, not a captured/stale value). No mismatched-period call site found. One pre-existing,
unchanged-by-these-stages gap noted for completeness: if `ManuscriptCalculate::runForEveryCustomer()`
throws a genuinely fatal exception (outside its own per-customer try/catch), the command's
`forgetSummaryCache()` call is skipped even though earlier chunks in that run may have already
committed manuscript writes — this was already true before stage 1 (the pre-stage code had no
try/catch around that call either) and is a "leaves cache stale until its 10-minute TTL, on a rare
fatal-failure path" edge case, not a stage 1–4 regression; left alone.

**2. The `customers:show:{uuid}:{branchId}` fix from earlier today** — confirmed byte-for-byte
intact and untouched by stages 1–4: `git diff` across all four stage commits shows
`CustomerManuscriptRecalculationService.php`'s `Cache::forget('customers:show:'.$customer->uuid.':all')`
line is unchanged context, not a diff hunk. Stage 2's new `$trigger`/`$auditContext` parameters on
`recalculateOne()` sit entirely above this line in the method body and don't touch it. No regression.

**3. `ManuscriptGenerationBatchService`'s own caching** — none. `computed_result`/batch progress are
plain `command_runs` columns, never wrapped in `Cache::remember`/`put`. A refused rerun
(`ManuscriptRerunGuard::assertRerunAllowed()` throwing inside `dispatch()`) throws *before* any
`CommandRun::create()`, so nothing is ever created and `publish()` (the only method that calls
`forgetSummaryCache()`) is never reachable — a refused rerun touches zero cache state, correctly.

**4. `Manuscripts/RunReview.tsx` + `ManuscriptController::runReview()`** — confirmed NOT cached.
`runReview()` receives `CommandRun $run` via plain Eloquent route-model binding (no
`resolveRouteBinding()` override, no `Cache::` call anywhere in `ManuscriptController.php` or
`ResolvesCommandRunBatchProgress`) — every poll tick (`usePoll(3000, { only: ['run'] })`, active only
while `status === 'queued'`) triggers a fresh Inertia partial reload that re-runs a fresh DB query.
No staleness risk.

**5. `ManuscriptPreRunReviewService::reviewList()`** — confirmed NOT cached (no `Cache::` call in the
file, and its `CustomerRepositoryInterface::activeWithLatestManuscript()` dependency has none either
— none of `app/Repositories/**` uses `Cache::` anywhere). This is correct and must stay this way: the
feature's entire purpose is showing current, real-time "who hasn't paid" state so a just-recorded
payment is visible before a run locks the period — any TTL, however short, would defeat that. No
change made.

**6. General sweep** — `git diff` of every stage-1–4 commit for `Cache::` (SET or FORGET) across all
changed `.php` files returned **zero matches**: none of the four stages added, removed, or touched a
single `Cache::` call anywhere. Item 6's "SET/FORGET correspondence within the new code" check is
therefore vacuously satisfied — there is no new cache surface in stages 1–4 to audit for mismatches.

**One real bug found and fixed, outside the stage 1–4 diff** — while cross-referencing every
`Cache::` call in `app/` against `CustomerService`'s established `{key}:{branchId-or-'all'}` pattern
(per this audit's own instructions), `PaymentVerificationService::verify()` and `attachReceipt()`
(pre-existing since `c1b297b6`, never touched by any of the four stages) both called
`Cache::forget("payments:show:{$payment->uuid}")` — a bare key — while
`PaymentService::findOrFail()` actually caches under `"payments:show:{$uuid}:".($branchId ?? 'all')`.
The exact same bug class as the customer one, in payments: an approve/reject or receipt-attach
silently failed to invalidate the payment detail page's cache for up to its 30s TTL. Fixed by adding
a `forgetShowCache()` private method to `PaymentVerificationService` that forgets both the
branch-scoped key and the `:all` key, mirroring `PaymentService::forgetShowCache()`'s exact pattern —
both `verify()` and `attachReceipt()` now call it instead of the bare `Cache::forget()`. Verified via
`tests/Feature/Web/PaymentTest.php` + `tests/Feature/Api/PaymentTest.php` (61 tests, 367 assertions,
covering the `verify()`/bulk-verify/receipt paths this touches) — all passing.

**Tests run for this stage**: `PaymentVerificationTest.php` (Api) +
`CustomerManuscriptRecalculationServiceTest.php` + `ManuscriptCalculateTest.php` +
`ManuscriptGenerationBatchServiceTest.php` + `ArrearsAdjustmentCommandRunAuditTest.php` +
`CommandRunCancelTest.php` + `ManuscriptPreRunReviewTest.php` + `ManuscriptRunReviewTest.php` +
`CommandRunCancelUnblocksDispatchTest.php` + `LiveManuscriptRecalculationAndBatchConsistencyTest.php`
+ `ManuscriptPublishStaleRaceTest.php` (67 tests, 544 assertions); `ManuscriptTest.php` in full,
including both PDF-export tests, run directly via `phpunit` with a raised `-d memory_limit` to work
around the same pre-existing dompdf environment ceiling stage 4's addendum already noted (15 tests,
134 assertions); `PaymentTest.php` (Web + Api) for the `PaymentVerificationService` fix (61 tests, 367
assertions). 143 tests / 1045 assertions total, all passing.

## Addendum (2026-08-27): closing the real-swecom-fixture-corruption gap in `ManuscriptGenerationBatchServiceTest`/`ManuscriptPublishStaleRaceTest`

**The incident.** Earlier the same day, 1,509 bogus manuscript rows for nonsense future periods
(`2031-01`/`2031-02`) were found permanently committed against all 446 real `swecom` customers,
created in one 16-second window, with **zero trace in `command_runs`** — proving it bypassed every
normal tracked path. Traced to `tests/Feature/ManuscriptGenerationBatchServiceTest.php` and
`tests/Feature/ManuscriptPublishStaleRaceTest.php`: unlike every other feature test in this suite
(which wraps fixtures in `DatabaseTransactions`/`RefreshDatabase` and rolls back), these two
**committed real, uncommitted-by-transaction fixtures straight into the live `swecom` tenant
schema**, relying entirely on a bare `finally { ... }` block to manually delete them again. A killed
test process, a timeout, or an exception thrown mid-cleanup skipped that `finally` entirely — which
is exactly what happened.

**Why these two files couldn't just use the normal transaction pattern.** Stancl's
`DatabaseManager::connectToTenant()` unconditionally purges and recreates the `tenant` PDO
connection on every `tenancy()->initialize()` call — including the ones Stancl's
`QueueTenancyBootstrapper` triggers automatically for **every queued job**, confirmed to fire even
under `QUEUE_CONNECTION=sync` in tests (`Illuminate\Queue\SyncQueue` still emits
`JobProcessing`/`JobProcessed`). That purge silently disconnects/rolls back an open outer
transaction's uncommitted fixtures before a `Bus::batch()` chunk job (§4.1) ever gets to read them —
a real, load-bearing constraint, not a shortcut someone forgot to fix. This is genuinely a different
problem from the ordinary tests in this file's neighborhood.

**Options considered, in the order the investigation actually weighed them:**

1. **A more crash-resistant PHPUnit lifecycle hook** (e.g. something that runs even after a fatal
   error) — investigated and **ruled out as insufficient on its own**: PHPUnit's `tearDown()` does
   not run after a truly fatal PHP error, a `SIGKILL`, an OS-level process kill, or a hard crash
   either — only after a normal return, a caught exception, or an assertion failure. Any fix relying
   solely on a better-behaved `tearDown()` would still have a gap for the exact "process killed"
   scenario this incident represents.
2. **A safety-net sweep/cleanup command** (`debug:check-babila`-shaped tooling: detect and remove
   rows tagged as belonging to this test pattern) — **deliberately not built**. This is exactly the
   shape of tooling that just caused a separate incident (`debug:check-babila`, removed the same day)
   — a blanket "delete anything matching a pattern" tool touching a real tenant schema is a standing
   risk even when scoped carefully, and the option below removes the *need* for any such tool rather
   than adding one more piece of code trusted to run correctly.
3. **A dedicated, disposable tenant SCHEMA per test, instead of the real `swecom` schema — chosen.**
   Implemented via a new `Tests\Feature\Concerns\UsesDisposableTenant` trait, reusing a pattern
   already proven safe elsewhere in this exact codebase
   (`tests/Feature/Web/LandlordTest.php::test_store_provisions_a_working_tenant`): `Tenant::create()`
   runs Stancl's `CreateDatabase -> MigrateDatabase -> SeedDatabase` pipeline **synchronously**
   (`TenancyServiceProvider` pins `shouldBeQueued(false)` on `TenantCreated`), and `$tenant->delete()`
   runs `DeleteDatabase` synchronously too — a real `DROP SCHEMA "..." CASCADE` on Postgres. Both test
   classes' `setUp()`/`tearDown()` now provision a uniquely-named, throwaway tenant per test method
   (`zmgb<timestamp><random>` / `zpsr<timestamp><random>`) and drop its schema in `tearDown()`. Every
   fixture these tests commit now lives in a schema unique to that one test invocation — never in
   `swecom` or any other real tenant. This also let the per-test `finally { cleanUp(...) }`
   boilerplate in both files be deleted entirely: dropping the whole schema is total, unconditional
   cleanup regardless of what a test created or how far it got, so there is nothing left for a
   `finally` block to do.

**The actual safety property.** If the test process is killed, times out, or crashes mid-test, the
worst case is now an orphaned `tenant<id>` schema sitting unused in the test database — harmless
clutter, not corrupted real customer data. This does **not** depend on any cleanup code successfully
running; the data was simply never written to a real tenant in the first place. Orphaned schemas from
this exact pattern already existed in this database before this fix (`tenantzreg2026...`, from
unrelated self-service-onboarding tests dated 2026-08-25/26) — confirming "occasional schema
pruning" is an already-accepted, already-occurring cost in this codebase, not a new one introduced
here.

**Empirical proof (not just code review).** A scratch test deliberately called `exit(1)` mid-test,
immediately after committing real fixtures (zone, customer, payment, a full `dispatch()`/publish
cycle) to a disposable tenant, but before `tearDown()` could run — the closest reproduction of "a
killed test process" achievable from inside PHP (PHPUnit reported `Fatal error: Premature end of PHP
process`, confirming `tearDown()` never executed). A separate verification pass then confirmed:
the disposable schema was left orphaned and **did** contain the real fixture (proving the kill
happened after a real commit, not before); and `swecom` showed **zero** manuscripts and **zero**
`command_runs` for the test's period, with its customer count unchanged at exactly 446. This directly
demonstrates the failure mode this incident represents no longer touches real tenant data, rather
than just reasoning that it shouldn't.

**Cost accepted.** Provisioning (`CreateDatabase`+`MigrateDatabase`+`SeedDatabase`, ~62 tenant
migrations) plus dropping a schema measured at ~4.3s per cycle. Both files now provision one
disposable tenant per test method (13 methods total between the two files), adding roughly
20–30s to their combined run time versus the old real-`swecom` approach — verified: both files
together now run in ~28s stand-alone. A shared-tenant-per-class optimization was considered (to pay
that cost once per file instead of once per method) but not built: Laravel's `TestCase` tears down
and rebuilds the entire application container between every test method, so cheaply sharing one
provisioned tenant across a class's methods would require manually re-bootstrapping the framework
outside its normal per-test lifecycle in `tearDownAfterClass()` — exactly the kind of nonstandard,
easy-to-get-subtly-wrong mechanism this fix is deliberately avoiding elsewhere. The simple,
already-proven, per-method pattern was preferred over a faster but novel one.

**What this preserves.** Both files still exercise the real thing they were written to prove:
`ManuscriptGenerationBatchServiceTest` still runs real `Bus::batch()` chunked jobs (job_batches rows,
real chunk counts, real per-chunk isolation) against a real, fully-migrated tenant schema — not a
mock or an in-memory substitute — so the queued-job/tenancy-purge interaction is still genuinely
exercised, just against a disposable schema instead of `swecom`. `ManuscriptPublishStaleRaceTest`
still exercises the real `recalculateOne()`-vs-`publish()` race with real writes. No test assertions
were weakened or removed to make this fix — the far-future test periods (`2031-01`..`2032-02`) were
originally chosen to avoid colliding with real historical demo data in `swecom`; that reasoning is
now moot (a disposable schema has no history to collide with) but the periods were left as-is since
changing them carried no benefit and some risk of an unrelated typo.

**Residual risk: substantially mitigated, not zero, and the remaining gap is a different one.**
Real customer/tenant data (`swecom`) can no longer be corrupted by a killed/crashed run of either of
these two test files — that specific incident is closed. What remains, and is out of scope for this
fix: (1) an orphaned disposable schema from a killed run is genuinely harmless but does require
occasional manual pruning (`DROP SCHEMA "tenant<id>" CASCADE` for `tenantz*` schemas older than any
real tenant) — no tooling was built for this per the reasoning in option 2 above; a landlord-only
admin script could be added later if the clutter becomes a real problem, scoped narrowly to schemas
matching this test-fixture naming convention only. (2) This fix covers exactly the two files
identified in this incident. Any *future* test that needs to commit real, transaction-unsafe fixtures
for the same tenancy-purge reason should use `Tests\Feature\Concerns\UsesDisposableTenant` from the
start — the trait is written to be reused, but nothing enforces that a future author won't reach for
the old real-tenant-plus-`finally` pattern again by copying an old test as a template. (3) Four
pre-existing `command_runs` rows were found in `swecom` during this investigation (ids 807/769/865/
1266, periods matching this file's old far-future convention, `metadata.trigger = "unspecified"` with
a single `customer_id`) — audit-log-only residue (zero associated manuscripts, customer count exactly
446), left over from **before** this fix, not created by it. Not deleted as part of this task —
touching real `swecom` rows autonomously is exactly the category of action this fix exists to
prevent; flagged here for the product owner to review and remove if confirmed safe.

---

## Addendum (2026-08-28): manuscript-run management — cancel/delete/rollback, gated by one flat "is this period locked" rule

Product owner ask, verbatim intent: an index for managing manuscript RUNS (`command_runs` rows,
`command = 'manuscript:calculate'`) — cancel a running process, delete it if not published,
roll it back — with a single hard constraint: **the current period is mutable; any period that has
already passed is fully locked, read-only, no exceptions.** This mirrors how payments already work
in this app (past payments are immutable) — manuscripts get the identical guarantee, for the
identical reason.

### What "current period" means here (read from business-rules.md + the actual code, not assumed)

business-rules.md never describes period advancement as "last published period + 1" — nothing in
this codebase computes it that way. Every real entry point that means "the billing period right
now" — `ManuscriptController::index()/calculate()/preRunReview()/preRunReviewFull()`,
`ManuscriptCalculate`'s CLI default, `ManuscriptService`, `ArrearsAdjustmentService`,
`RecalculateCustomerManuscriptsForwardJob` — uses the identical bare expression
`Carbon::now()->format('Y-m')`, a plain calendar-month string compared lexicographically. "Current
period" for this feature is defined identically, in exactly one place:
`App\Services\ManuscriptRunLockService::currentPeriod()`. A period is locked
(`isPeriodLocked(string $period): bool`) when it is anything other than that string. In practice
this only ever means "in the past," since every entry point that accepts a `period` already rejects
a future one outright before any `command_runs` row for it can exist — so plain inequality is both
correct and the simplest possible implementation of "the current period is mutable, everything
else is locked."

Locking is decided **purely by period, never by the individual `command_run`'s own status** — a
stale/abandoned `queued` or `failed` row against a period that has since passed (an old, orphaned
row from a prior month, never resolved) is just as locked as a `published` one. This was the
one thing explicitly called out as a trap to avoid: "not yet published" and "period has passed" are
two different conditions, and only the second one locks.

### What already existed vs. what was built new

Already existed and reused as-is: `SettingsCommandRunController::cancel()` (the 2026-08-27 "unstick
a stuck queued row" action, flips `queued` -> `failed`), `CommandRunPolicy::publish()` (the
super/admin ability gating both `publish()` and `cancel()`), the `Settings/CommandRuns.tsx` page
itself (already the de facto "index for managing manuscript runs" — no new page was created; this
is the only place a `command_runs` row's lifecycle is ever acted on, so extending it rather than
building a second, competing surface was the deliberate choice, matching this app's established "no
new nav item, extend the existing settings page" precedent from section 4's own admin-UI note), and
the `Dropdown`/`DropdownItem`/`DropdownDivider` kebab-menu components (already built for
Customers/Index.tsx and Disconnections/Index.tsx — reused verbatim, not reinvented, replacing that
page's previous bare inline buttons).

Built new:

1. **`App\Services\ManuscriptRunLockService`** — the single source of truth described above.
   `currentPeriod()` + `isPeriodLocked(string $period): bool`. Every action below calls this one
   method; the comparison is never reimplemented inline anywhere else.
2. **`manuscripts.command_run_id`** (migration
   `2026_08_28_010000_add_command_run_id_to_manuscripts_table.php`, nullable FK,
   `nullOnDelete()`) — required because `manuscripts` had no linkage back to whichever
   `command_runs` row wrote it. Without it, a delete/rollback scoped by `period` alone would also
   delete a sibling run's rows against the identical period string (a real, named scenario: a
   run re-computed and re-published after a data fix creates a NEW `command_runs` row for the same
   period, per `ManuscriptGenerationBatchService::publish()`'s own existing "multiple historical
   rows per period are expected" comment). Set on every write path that actually commits to
   `manuscripts`: `ManuscriptGenerationBatchService::publish()` and
   `ManuscriptCalculate`'s CLI `runForEveryCustomer()` (the CLI bypasses the review gate and writes
   directly — see the 2026-08-27 addendum above — so it needed the same linkage independently, not
   inherited from `publish()`). Pre-migration historical rows are left NULL (no reliable way to
   attribute them retroactively) — they are simply never a rollback candidate, the safe default.

   One real interaction this required handling: `CustomerManuscriptRecalculationService::
   recalculateOne()` (the arrears-adjustment single-customer recalculation path) can overwrite a
   manuscript row a bulk `manuscript:calculate` run previously wrote, via the same
   `firstOrNew()->fill()->save()` shape. Left alone, that write would silently preserve the OLD
   `command_run_id` (since `fill()` only touches keys actually passed), so rolling back the
   original bulk run later would delete a row that had since been legitimately corrected by an
   unrelated, more authoritative mechanism. Fixed by having `recalculateOne()` explicitly set
   `command_run_id => null` on every write — it deliberately does NOT attribute the row to its own
   `manuscript:recalculate-one` audit-trace row either, since that command has no batch/review
   lifecycle and was never meant to be a rollback target (see next point).
3. **`SettingsCommandRunController::rollback()`** — `POST /settings/command-runs/{run}/rollback`
   (`settings.command-runs.rollback`). Gated to `CommandRunPolicy::publish()` (same reuse rationale
   as `cancel()` — one existing ability, not a new one), restricted to
   `command === 'manuscript:calculate'` (404 otherwise, mirroring `ManuscriptController::
   runReview()`'s identical guard — a `manuscript:recalculate-one` row is a single-customer
   audit-trace side effect, not a "manuscript process" in the sense of this feature). Order of
   checks: authorize -> command guard -> `ManuscriptRunLockService::isPeriodLocked()` (refused with
   a clear message if locked) -> `CommandRun::isRollbackable()` (true for `pending_review`,
   `published`, or `failed` — false for `queued`, which is `cancel()`'s job, and `rolled_back`,
   terminal). Inside a `DB::transaction()`: `Manuscript::query()->where('command_run_id',
   $run->id)->delete()` (a real, precisely-scoped `DELETE`, never by `period` alone), then
   `$run->update(['status' => 'rolled_back', 'metadata' => [...'rolled_back_by', 'rolled_back_at']])`.
   Also calls `ManuscriptService::forgetSummaryCache($run->period)` afterward, matching every other
   manuscript-mutating path's existing cache-invalidation discipline.

   A `pending_review` run genuinely has zero `manuscripts` rows at the point of rollback (only
   `publish()` ever writes them — compute only populates `command_runs.computed_result`), so
   rolling one back deletes nothing; the action is still meaningful there — it discards the
   computed result and frees the period/marks the attempt abandoned rather than leaving it sitting
   forever as an un-actioned `pending_review` row. A `failed` run CAN have real partial rows: the
   CLI path (`ManuscriptCalculate`) commits each customer inside its own per-customer transaction
   as it goes, so a fatal exception partway through `runForEveryCustomer()`'s loop can leave some
   customers' rows already committed under that run's `command_run_id` before the row is marked
   `failed` — rollback cleans those up too.

   `command_runs.status` has no DB check constraint (confirmed — it's a plain `string(20)` column,
   `idx_command_runs_period_inflight`'s partial index is the only thing that cares about specific
   status values), so `'rolled_back'` needed no schema migration to introduce — added a
   `CommandRun::isRollbackable()`/`isRolledBack()` model helper alongside the existing
   `isQueued()`/`isPendingReview()`/`isPublished()`.
4. **`cancel()` gained the same lock check**, run first (before the existing `isQueued()` check) —
   an old orphaned `queued` row against a period that has since passed must stay locked, not become
   cancellable purely because it never resolved. This is the literal "stale/abandoned row from
   months ago" case flagged as a trap in the original ask.
5. **Frontend**: `Settings/CommandRuns.tsx`'s Actions column now sends `is_locked` (computed
   server-side via the lock service, never re-derived in React — a display hint only, the backend
   enforces the same check independently on every POST regardless of what renders) and a new
   `canRollback` prop (same underlying ability as `canPublish`/`canCancel`, exposed under its own
   name for the same "don't infer the gate from an unrelated prop" reason those two already were).
   A locked row renders a plain read-only "Locked" badge and **no action menu at all** — never a
   disabled/hidden menu. An unlocked row's previously-separate inline Publish/Cancel buttons are
   now bundled into one `Dropdown`/`DropdownItem`/`DropdownDivider` kebab menu (mirroring
   Customers/Index.tsx and Disconnections/Index.tsx's established per-row actions-menu pattern),
   showing whichever of Publish/Cancel/Delete-Rollback actually apply to that row's status; a
   Preview link (read-only) stays outside the menu and available even on a locked row, since the
   lock is about mutation, not visibility.

### Test fallout

`CommandRunCancelTest.php`'s one success-path test (`test_an_admin_can_cancel_a_stuck_queued_run`)
used a hardcoded future literal (`'2032-01'`) purely to avoid colliding with real seeded manuscript
history under the old, real-`swecom`-in-a-transaction pattern — never load-bearing to "not the
current month." Updated to `now()->format('Y-m')` since `cancel()` now requires the current,
unlocked period to succeed at all. A new
`test_cancel_is_refused_for_a_queued_run_against_a_past_period` test covers the locked case
directly. The existing `nonQueuedStatuses` data-provider test needed no change — it only asserts an
error was returned and the status is unchanged, which holds regardless of whether the lock check or
the status check is what actually fired first.

New file `tests/Feature/Web/CommandRunRollbackTest.php` — deliberately uses
`Tests\Feature\Concerns\UsesDisposableTenant` rather than `CommandRunCancelTest.php`'s
`InteractsWithTenantRoles` (real `swecom`, wrapped in a rolled-back transaction): this feature's
tests need the new `manuscripts.command_run_id` column, and the real `swecom` schema is
deliberately never altered as part of building/testing this feature (the migration was written but
never run against it) — a freshly-provisioned disposable tenant is the only schema in the test run
guaranteed to have that column, which also happens to double as live proof the migration itself
applies cleanly. Covers: rollback of a published current-period run deletes only that run's own
rows, never a sibling run's rows against the identical period (the two-runs-same-period fixture is
the direct proof of the `command_run_id`-scoped delete); rollback of a `pending_review` run
succeeds and marks `rolled_back` despite deleting zero rows; rollback refused for a past period
regardless of the run's own status (`pending_review`/`published`/`failed`, via a data provider —
the literal "locked by period, not by publish-status" case); rollback refused for a still-`queued`
run (must use Cancel instead); authorization refused for `manager`/`agent` roles.

### Deliberately left out

- **No UI/backend distinction between "Delete" and "Rollback" as two separate actions** — the ask
  used both words somewhat interchangeably for what turned out to be exactly one operation once the
  actual write paths were traced (delete the linked `manuscripts` rows, mark the run
  `rolled_back`). Introducing two separately-worded actions with identical backend behavior would
  add UI surface without adding a real distinction.
- **No reversal of `payments.processed_period`/`arrears_adjustments.processed_period` stamps** on
  rollback. Confirmed unnecessary by reading `Payment::scopeEligibleForPeriod()`: a payment stamped
  `processed_period = $period` remains eligible for a fresh `manuscript:calculate` run against that
  *same* period (the existing "re-stampable-on-republish guard" `publish()` already relies on) — so
  a subsequent re-run of the now-rolled-back current period naturally re-includes everything without
  any additional cleanup. This is exactly the "cheap to recompute" property the original ask leaned
  on.
- **No time threshold / cooldown on rollback**, for the identical reasoning `cancel()`'s own
  2026-08-27 doc comment already gives for itself: at this app's real scale, a human choosing to
  roll back a run they can see is already the safety mechanism, matching every other admin-only
  destructive action here.
- **No per-role exceptions or override cascade on the lock rule itself** — `isPeriodLocked()` has
  exactly one implementation, called identically by `cancel()` and `rollback()`, with no
  role-conditional bypass. This was an explicit standing constraint for this feature, not an
  oversight.
- **`manuscript:recalculate-one` rows were left out of this feature entirely** — no lock check, no
  rollback route reachable for them (404 via the command guard). They're a single-customer audit
  trace of an arrears-adjustment side effect with no batch/review lifecycle, not a "manuscript
  process" in the sense the product owner described; folding them in would have blurred what this
  feature actually manages.

---

## Addendum (2026-08-28): closing `ManuscriptCalculateTest`'s real-swecom-fixture-corruption gap — the SECOND occurrence of the 2026-08-27 incident class

**The incident.** A second occurrence of the same incident class documented in the 2026-08-27
addendum above: 2,230 orphaned manuscript rows found committed against real `swecom` customers,
across periods `2033-04`/`2035-01`/`2035-02`/`2035-04` (plus the real `2026-08` period, deleted
separately by the product owner). Traced precisely to `tests/Feature/ManuscriptCalculateTest.php`,
which — unlike the two files fixed on 2026-08-27 — was never touched by that fix: its class doc
explicitly documented committing real fixtures into `swecom` via a manual
`DB::connection('tenant')->beginTransaction()` / `beforeApplicationDestroyed(fn () =>
DB::connection('tenant')->rollBack())`-shaped pattern, not `UsesDisposableTenant`. Six of its
tests invoke the real `manuscript:calculate` artisan command directly, which — exactly like the
2026-08-27 incident's `Bus::batch()` chunk jobs — calls `tenancy()->initialize()`/`tenancy()->end()`
internally, purging the outer manual transaction and forcing these six tests onto the same
real-swecom-plus-`finally`-cleanup pattern that produced the first incident.

**Worse than "only a killed test leaks data."** `manuscript:calculate` has no way to scope itself
to a customer subset — `Customer::query()->chunkById()` processes *every* customer belonging to
whatever tenant `--tenant` points at. All six artisan-invoking tests ran the command against the
real `swecom` tenant, so *every successful, non-crashed* run of this file already wrote a
manuscript row for every real `swecom` customer at that test's period — and five of those six
tests' `finally` blocks deleted only that test's own single fixture customer's manuscript row,
never the hundreds of real customers' rows the command also touched (the sixth,
`test_an_unrelated_customers_manuscript_is_unaffected_by_another_customers_payment_change_on_rerun`,
was the one test that got this right, deleting every manuscript row for its periods rather than
just its own fixtures — proving the gap was an inconsistency across tests, not a fundamental
limitation). The 2,230-row incident is consistent with exactly this mechanism: roughly one row per
real customer, per leaked period, left behind by ordinary green test runs — not merely by
interrupted ones, unlike the 2026-08-27 incident.

**The fix.** Exactly the pattern already proven on 2026-08-27, applied to this file too: converted
to `Tests\Feature\Concerns\UsesDisposableTenant` (prefix `zmct`), provisioning a throwaway tenant
schema per test in `setUp()`/dropping it in `tearDown()`, no new mechanism invented. The class
doc's claim that "all fixtures are created fresh per test via factories — none of the real seeded
29 zones / 9 expense categories / company row are read or modified" was verified true for every
one of the file's 19 tests (checked for hardcoded UUIDs/zone names/customer counts — none found;
the only real-tenant-specific literal anywhere was the string `'swecom'` itself, now replaced with
`$this->tenant->getTenantKey()`).

**The six artisan-invoking tests specifically.** All six now run `manuscript:calculate --tenant=
<disposable tenant id>` instead of `--tenant=swecom`. Since the disposable schema starts with zero
customers (only the seeded 29 zones / 9 expense categories / company row from
`Database\Seeders\TenantDatabaseSeeder`), the command now only ever touches each test's own
fixtures — the "delete every manuscript row for the period, not just this test's own customer"
workaround one test needed under real `swecom` became unnecessary entirely; `tearDown()`'s schema
drop is the only cleanup needed for all six. One test-specific wrinkle not present in the
2026-08-27 fix: the test that stubs `ManuscriptService::forgetSummaryCache()` via
`$this->app->bind()` to force a fatal-failure path needed an extra
`$this->app->forgetInstance(\Illuminate\Contracts\Console\Kernel::class)` call before invoking
`$this->artisan()` — `provisionDisposableTenant()`'s `Tenant::create()` call in `setUp()` runs
Stancl's `MigrateDatabase`/`SeedDatabase` jobs, which call `Artisan::call('tenants:migrate'/
'tenants:seed')` internally. That is the *first* `Artisan::call` of the test, which bootstraps and
caches the console `Kernel`'s Artisan `Application` and resolves (and caches) every discovered
command — including `manuscript:calculate` — against whatever `ManuscriptService` binding was
current *at that moment* (the real one, since this happens in `setUp()`, before the test body's
own `bind()` override). Forgetting the `Kernel` singleton forces the next `$this->artisan()` call
to rebuild the console application from scratch, honoring the override. This is a side effect
specific to disposable-tenant provisioning triggering `Artisan::call` before a test's own
container overrides run; it did not affect the 2026-08-27 fix because neither file fixed that day
uses `$this->app->bind()` immediately before an `artisan()` call.

**An unrelated, pre-existing production bug found (and fixed) while empirically verifying this
file.** `app/Console/Commands/ManuscriptCalculate.php`'s `runForEveryCustomer()` — modified
earlier the same day as part of this file's `command_run_id` linkage work — passes `$commandRun`
into the outer `Customer::query()->chunkById()` closure's nested `DB::transaction()` closure via
`use ($commandRun, ...)`, but never added `$commandRun` to the *outer* closure's own `use (...)`
list. PHP closures never inherit an enclosing scope's variables implicitly, only what their own
`use()` imports, so `$commandRun` was genuinely undefined inside the outer closure — every
customer processed threw "Undefined variable $commandRun" (converted to a thrown `ErrorException`
by Laravel's error handler), counted as a per-customer error, and none of the six artisan-invoking
tests could pass. One-line fix: added `$commandRun,` to the outer closure's `use (...)` list. Flagged
here explicitly because it is outside this task's scope (a production bug, not a test-tenancy
pattern) — it was fixed only because it blocked empirically proving the tenancy fix works at all,
and whoever owns the `command_run_id` linkage work should be aware it was silently broken until now.

**Empirical proof (not just code review).** Same method as 2026-08-27: a scratch, temporary
`exit(1)` was inserted mid-test in `test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run`,
immediately after the first real `manuscript:calculate` run committed a customer, a payment, a
manuscript row, and a published `command_runs` row to the disposable schema — before `tearDown()`
could run. PHPUnit reported `Fatal error: Premature end of PHP process`, confirming `tearDown()`
never executed. Direct `psql` verification afterward against the real database:
- **Disposable schema** (`tenantzmct202608280125203191`) held the orphaned fixture in full: the
  customer row, its `2026-05` manuscript (`total_bill = 2500.00`), a `published` `command_runs`
  row, and the payment stamped `processed_period = '2026-05'`.
- **Real `tenantswecom` schema**: zero customers matching the fixture's UUID, zero `command_runs`
  rows for period `2026-05` from this test, and `total_customers` unchanged at exactly 446
  (`manuscripts` already had 446 real rows for period `2026-05` from genuine May-2026 production
  billing — pre-existing, untouched, unrelated to this test).

The orphaned disposable schema and its `tenants` row were then cleaned up manually (the schema
drop itself was delayed by unrelated lock contention from a concurrent process on the same shared
dev database — see below — but eventually completed; the accepted "needs occasional manual
pruning" tradeoff from the 2026-08-27 addendum applied here exactly as documented).

**Test results.** All 19 tests in `ManuscriptCalculateTest.php` pass (169 assertions), confirmed
directly (`php artisan test tests/Feature/ManuscriptCalculateTest.php`) after both the tenancy fix
and the one-line `ManuscriptCalculate.php` fix above. A regression sweep of
`ManuscriptGenerationBatchServiceTest`, `ManuscriptPublishStaleRaceTest`, `Api/ManuscriptTest`,
`Web/ManuscriptTest`, `Web/ManuscriptPreRunReviewTest`, and `Web/ManuscriptRunReviewTest` was
attempted but could not be completed in this session: the shared dev Postgres instance was under
heavy, sustained lock contention from a concurrent process (an apparently-hung `php artisan test`
run covering `CommandRunCancelTest`/`CommandRunRollbackTest`, holding an `idle in transaction`
session for 15+ minutes) for the remainder of the session. That contention was left alone
deliberately rather than resolved by terminating another session's database backend or process —
the regression sweep should be re-run once the database is confirmed idle.

**Residual risk: this is NOT the last file with this pattern.** A grep across all of `tests/` for
`DB::connection('tenant')->beginTransaction()` combined with `Tenant::find('swecom')` found six
files using this manual-transaction-against-real-`swecom` shape. Four
(`tests/Feature/Api/AuthTest.php`, `tests/Feature/Api/Concerns/InteractsWithTenantRoles.php`,
`tests/Feature/PrepaidPausePreservationTest.php`, `tests/Feature/Web/SettingsTest.php`) only
initialize tenancy once, in `setUp()`, and never re-initialize it mid-test — they lack the specific
mechanism (a queued job or artisan command purging the outer transaction) that turns this pattern
into a real corruption risk, so they were not investigated further here. The other two are
**genuinely vulnerable to the exact same incident mechanism and are still unfixed**:

- **`tests/Feature/Web/ManuscriptTest.php`** — `test_admin_can_run_the_manuscript_calculation`
  drives `manuscript:calculate` indirectly through the real HTTP endpoints
  (`POST /manuscripts/calculate` → `POST /settings/command-runs/{run}/publish`), against the real
  `swecom` tenant, with the identical manual-transaction-release-plus-`finally`-cleanup shape —
  its own comment explicitly says this is "the same workaround against the raw command" as this
  file's now-fixed test. Its own doc comment notes the command "processes every real customer in
  the tenant schema (~550 rows)."
- **`tests/Feature/Api/AuditLogTest.php`** — calls `$this->artisan('manuscript:calculate', [...])`
  directly against real `swecom`, same manual-transaction-plus-`finally` shape.

Both are out of scope for this task (scoped explicitly to `ManuscriptCalculateTest.php`) and were
not modified. They should receive the identical `UsesDisposableTenant` treatment in a follow-up
task before they produce a third occurrence of this incident class.
