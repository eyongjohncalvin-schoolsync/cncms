# Arrears Adjustment (Write-Off) — Reference

Status: **Live.** A maker-checker workflow letting office staff manually correct one customer's
arrears balance for one billing period — a goodwill credit, a billing-error fix, or a negotiated
partial write-off — as a deliberate, audited, discretionary action distinct from a normal payment.
Referenced in passing by `references/prepaid-pause-handling.md` §6 ("that's what the separate
`references/arrears-adjustment.md`-style write-off feature, if/when built, is for") — this is that
doc, written after the fact. No separate "design doc" exists anywhere else; the code comments across
this feature repeatedly cite "this feature's design doc" as their source of truth, but that
document was never actually committed to this repo — this file is now the closest thing to one, and
`.claude/skills/cncms-context/SKILL.md`'s "Reference Files" list has been updated to point here.

**Where to actually use this feature** (the answer to "I can't find any place to work on it"):

- **Request a write-off / adjustment**: open any customer's detail page (`/customers/{uuid}`) and
  click the purple **"Adjust Arrears"** button in the action row, next to Suspend/Disconnect/
  Reconnect/Print Bill/Edit. Every one of the 5 roles can do this. As of §8 (2026-08-27), the same
  request form is also one click away from `/manuscripts` (each row's Actions dropdown — "Adjust
  Arrears") and from `/payments/{uuid}` (a header button next to Edit/Delete Payment) — no need to
  navigate to the customer's own page first.
- **Approve / reject pending requests**: the **Audit Log** nav item (sidebar, cyan icon — visible to
  `super`/`admin`/`manager` only) → the **"Arrears Adjustments"** sub-tab at the top of that page
  (`/audit/logs?view=arrears_adjustments`).
- **At-a-glance signal that something is waiting**: the Dashboard's **"Pending Arrears Adjustments"**
  stat card links straight to that same sub-tab — added because, before this doc, the Service method
  that computes this count (`ArrearsAdjustmentService::dashboard()`) was already fully built but
  wired to nothing outside the Audit Log sub-tab itself, so there was no top-level pointer anywhere
  in the app telling anyone that queue existed. See §6 for the full "why couldn't the owner find
  this" diagnosis.
- **See a customer's adjustment history**: still on `/customers/{uuid}`, below the action row — a
  table of that customer's recent requests with their current status.

---

## 1. Data model

`ArrearsAdjustment` (tenant-scoped table `arrears_adjustments`, dual-key `id`/`uuid`,
`#[Fillable]`, `#[RouteKey('uuid')]`, `use Auditable` — every status transition gets a permanent
`audit_logs` row, which is why this model has no separate `rejected_by`/`rejected_at` columns of
its own):

| Column | Notes |
|---|---|
| `customer_id` | FK to `customers`, restrict-on-delete |
| `target_period` | `'YYYY-MM'`, the billing period this correction applies to — must not be in the future |
| `direction` | `enum('decrease','increase')` — `decrease` is a write-off/credit (subtracts from what the customer owes); `increase` is a billing-error correction the other way, or clawing back a credit that should never have been granted |
| `amount` | `DECIMAL(12,2)`, always stored positive — `direction` carries the sign |
| `reason_category` | `enum('legacy_migration_error','billing_error','goodwill_service_outage','bad_debt_writeoff','credit_clawback','other')` |
| `reason_note` | required, free text — a permanent audit record of why |
| `arrears_snapshot` | `DECIMAL(12,2)`, the customer's `total_arrears` for `target_period` captured at request time — purely so `approve()` can detect the figure has since drifted (see §3) |
| `status` | `enum('pending','pending_second_approval','approved','rejected')` |
| `requested_by` | cross-schema FK to `public.users.id` (raw `DB::statement`, same pattern as `complaints.submitted_by`) — not fillable, set from `auth()->id()` in `ArrearsAdjustmentRepository::create()` |
| `approved_by`, `approved_at` | first approval |
| `second_approved_by`, `second_approved_at` | second approval, only populated when required — see §2 |
| `rejection_reason` | required non-empty when rejecting |
| `complaint_id` | nullable FK to `complaints`, null-on-delete — an adjustment can optionally originate from a logged complaint |
| `processed_at`, `processed_period` | idempotency marker, identical semantics to `payments.processed_at`/`processed_period` — see §4 |

## 2. The two-approver (maker-checker) workflow

Every request starts at `status = 'pending'` regardless of amount/reason — the decision to require a
second approver is evaluated **fresh at approval time**, in `ArrearsAdjustmentService::
requiresSecondApproval()`, never baked in at request time:

- `amount` exceeds `companies.arrears_second_approval_threshold` (tenant-configurable, default
  `20,000.00` FCFA — `ArrearsAdjustment::DEFAULT_SECOND_APPROVAL_THRESHOLD` is the defensive
  fallback if that single company-settings row is somehow missing), **or**
- `reason_category = 'legacy_migration_error'` — always requires the extra scrutiny of a second,
  more senior approver. Deliberate, conservative judgment call: the intent (per the code's own
  comments) was to scope this to "a customer whose record wasn't part of the original legacy import
  cohort," but this schema has no reliable signal to distinguish that cohort from any other customer
  — `customers.imported_by` is set by *any* bulk import, not specifically the original migration, and
  `created_at` isn't a trustworthy proxy either. Rather than guess at a fragile heuristic, every
  `legacy_migration_error` adjustment always requires second approval. A real
  `customers.legacy_import_cohort` flag could narrow this later, **or**
- this customer already had another **approved** adjustment within the last 90 days.

**First approval** (`status = 'pending'`): `super`/`admin`/`manager`, and the actor must not be the
requester. If a second approval isn't required, this single approval moves the adjustment straight
to `'approved'` and applies the ledger effect (§4) immediately. If it is required, this only records
`approved_by`/`approved_at` and moves to `'pending_second_approval'` — **zero ledger effect yet**.

**Second approval** (`status = 'pending_second_approval'`): narrower — `admin`/`super` only — and the
actor must differ from **both** the requester and whoever gave the first approval. This approval is
what actually applies the ledger effect.

Rejection is symmetric with approval: whoever may approve at the current stage may also reject at
that same stage (`ArrearsAdjustmentPolicy::reject()` just delegates to `approve()`'s gate). A
rejected request is never editable/resubmittable in place — it stays as a permanent audit artifact;
a fresh request is a new row.

**Staleness re-check**: at approval time (either stage), the service re-fetches the customer's
*current* arrears figure for `target_period` and compares it against `arrears_snapshot` with
`bccomp()`. If they don't match — meaning something changed the customer's arrears between the
request and this approval — the approval is refused with an explanation, rather than silently
applying against stale numbers. The reviewer has to reject and ask for a fresh request instead.

**Row-level race close**: `approve()`/`reject()` both re-fetch the `ArrearsAdjustment` row with
`lockForUpdate()` inside `DB::transaction()` before checking `isPending()`, rather than trusting the
in-memory object the caller passed in. Without this, two requests that both loaded the same
`'pending'` row before either committed a decision could both pass the pending check and one could
silently double-apply the ledger effect — caught by
`ArrearsAdjustmentTest::test_the_services_own_stale_read_guard_refuses_a_second_decision_on_the_same_row()`,
fixed 2026-08-27.

## 3. Permissions (`ArrearsAdjustmentPolicy`)

Not explicitly registered anywhere — relies on Laravel's naming-convention policy auto-discovery
(`App\Models\ArrearsAdjustment` → `App\Policies\ArrearsAdjustmentPolicy`), the same as
`CustomerPolicy`/`PaymentPolicy`/every other policy in this app except `ReportPolicy` (which needs
explicit registration only because `Report` isn't backed by a real table). This is correct, not a
gap — do not add a `Gate::policy()` call for it.

| Ability | Who |
|---|---|
| `viewAny`/`view` | any authenticated tenant user |
| `create` | any authenticated tenant user, all 5 roles — ungated, same as `ComplaintPolicy::create()` |
| `approve`/`reject` at `status = 'pending'` | `super`/`admin`/`manager`, actor ≠ requester — **except `super`, who may act on their own request (see §13)** |
| `approve`/`reject` at `status = 'pending_second_approval'` | `super`/`admin` only, actor ≠ requester **and** actor ≠ first approver — **except `super`, exempt from both identity checks (see §13)** |
| `approve`/`reject` on any other status | always `false` — nothing left to decide |

The Policy is the single source of truth for "who may act right now" — `ArrearsAdjustmentService`
trusts it already ran and only re-derives the *business* question ("does this need a second
approval," "has the figure drifted") at approval time, never the *authorization* question.

## 4. How the ledger effect actually lands (the key design decision)

**Never a direct write to `manuscripts`.** Per `ManuscriptCalculator`'s ownership rule (manuscript
rows are owned exclusively by `manuscript:calculate`/`ManuscriptCalculator`, confirmed by that
class's own doc comments), an approved adjustment cannot just poke `total_arrears` directly — it has
to flow through a real calculator run, the same way every other arrears change in this system does.

`ManuscriptCalculator::calculate()` already had, and fully implements, first-class support for this:
it takes a `Collection<ArrearsAdjustment> $eligibleAdjustments` parameter and folds it into the
ledger as a signed term:

```
net = previousNet + (bill - income) ± adjustmentTotal
```

— `decrease` subtracts from net (reduces what's owed), `increase` adds to it. This applies in
**every** branch of the calculator, including the frozen ones (disconnected/passive/suspended/active
prepaid window) — a frozen customer's stale, wrong arrears figure is real and must be fixable
without first reconnecting them, which is this feature's central use case.

Eligibility uses the identical idempotency mechanism as `payments.processed_at`/`processed_period`:
an adjustment is eligible for period P when `status = 'approved' AND target_period = P AND
(processed_period IS NULL OR processed_period = P)`.

Because no per-customer, single-period calculator entry point existed anywhere in this codebase
before this feature (every existing caller processes the whole tenant's customer set), one was
added: `CustomerManuscriptRecalculationService::recalculateOne(Customer, period)` — a thin wrapper,
not new calculation logic, reusing `ManuscriptChunkDataResolver` for eligibility resolution so it
stays in lockstep with the manual/scheduled paths. `ArrearsAdjustmentService::applyLedgerEffect()`
uses it twice:

1. **Synchronously, for the current period** — so the approver sees the effect immediately.
2. **Queued (`RecalculateCustomerManuscriptsForwardJob`), for every period from `target_period`
   through the current period** — because an adjustment can target a *past* period, and every
   period after that one was computed carrying forward the old (wrong) net figure; the forward
   sweep re-runs each of those periods in order so the correction actually propagates through to
   today's balance, not just sitting inert on one historical row.

## 5. Notifications

On approval: the requester gets an in-app notification ("your request was approved and applied"),
and every agent assigned to the customer's zone gets one too ("check the current balance before your
next visit"). On rejection: the requester is notified with the rejection reason. There is
deliberately **no** notification when a request is first submitted (matches
`ComplaintService::create()`'s identical "no notify on create" convention in this codebase) — the
Dashboard stat card (see the top of this doc) is what surfaces pending work to reviewers instead.

## 6. Diagnosis: why the product owner couldn't find this feature

Every layer was, in fact, already fully built and correctly wired before this investigation:
migration (applied to both real tenant schemas), model, DTOs, repository, service, policy,
controller, routes, form requests, and a complete frontend — the "Adjust Arrears" modal on every
customer page, and the "Arrears Adjustments" review sub-tab on the Audit Log page. 16 new feature
tests (`tests/Feature/Web/ArrearsAdjustmentTest.php`) exercise create/approve/reject, the two-approver
threshold, the 90-day-repeat rule, every policy self-block, the staleness re-check, and — the part
that matters most — confirm an approval genuinely moves a real `manuscripts.total_arrears` figure via
`ManuscriptCalculator`, not a direct write. All pass against the real `swecom` tenant schema.

So this was never a broken-backend problem. It was a **pure discoverability gap**: the feature has
no dedicated page and no nav entry of its own (by design — see `ArrearsAdjustmentController`'s class
doc), which is reasonable, but nothing anywhere in the app *pointed toward* either of its two entry
points unless you already knew to look for them. The clearest evidence: `ArrearsAdjustmentService::
dashboard()` — returning `pending_approval`/`applied_this_month`/`total_written_off` — existed
specifically to feed a summary surface, but was wired to nothing except the Audit Log sub-tab's own
header stats. The fix (§ top of this doc, "Dashboard" stat) closes that: a clickable "Pending Arrears
Adjustments" card, visible to everyone the same way "Pending Verification" already is, that links
straight to the review queue.

## 7. Deliberately out of scope

- **Mobile approve/reject.** The two-approver review/decision workflow stays office/web-only —
  there is still no way to approve or reject a request from the mobile app, and nothing here changes
  that. (The mobile *request* side shipped 2026-08-28 — see §10 below; this bullet is scoped
  narrower than it originally was, which covered mobile entirely.)
- **No new nav item, no dedicated index page.** The existing customer-page-modal +
  Audit-Log-sub-tab shape already meets "a staff member can create an adjustment and see its
  status," and a full index page would duplicate the Audit Log sub-tab's own paginated/filterable
  list. The Dashboard stat card is the minimal, convention-consistent fix for discoverability instead
  of a UI restructure.
- **No `legacy_import_cohort` flag.** See §2's note on the `legacy_migration_error` second-approval
  rule — flagged as a known follow-up, not built here.

## 8. Addendum, 2026-08-27: two more entry points

The modal (`ArrearsAdjustmentModal`) is now reachable from two more places staff
already are, without navigating to the customer's page first — still no new nav
item, still the same maker-checker workflow and policy above, purely more
launch points for the identical component:

- **`Manuscripts/Index.tsx`** — the monthly billing register's per-row
  **Actions** column. That column already existed as a bare "Send Bill"
  WhatsApp pill (visible only to `canSendBill` roles); it's now a proper
  `Dropdown`/`DropdownItem`/`DropdownDivider` kebab menu (the same pattern
  `Customers/Index.tsx`'s Actions column uses) so "Adjust Arrears" (always
  present — `ArrearsAdjustmentPolicy::create()` is ungated) and "Send Bill"
  (still role-gated, still opens `wa.me` in a new tab) both live under one
  menu per row. Each `Manuscript` row already carries `customer_uuid`/
  `customer_name`/`total_arrears`, mapped trivially into the modal's
  `{uuid, name, manuscript: {total_arrears}}` shape.
- **`Payments/Show.tsx`** — a plain "Adjust Arrears" button in the header
  actions row, next to Edit/Delete Payment but (unlike those two) rendered
  unconditionally, matching the policy's ungated `create()`. This page's
  `Payment` prop didn't carry the customer's current arrears figure, so
  `PaymentController::show()` now also eager-loads `customer.latestManuscript`
  (alongside the `customer.zone`/`verification.verifier` it already loaded)
  and `formatPayment()` exposes it as one extra field, `customer_total_arrears`
  — null wherever that relation isn't loaded (e.g. `Payments/Index.tsx`'s list
  rows, `edit()`), by design.

**How `ArrearsAdjustmentModal` was widened, not forked**: it gained one
optional prop, `trigger?: (open: () => void) => ReactNode` — a render prop
letting a caller swap in its own trigger element while every other line of
the component (state, form, the actual `Modal`) stays exactly as it was.
Omit it and the component renders its original full-size purple button
unchanged, which is exactly what `Customers/Show.tsx` and the new
`Payments/Show.tsx` usage both do; `Manuscripts/Index.tsx` is the only
caller that supplies `trigger`, to get a compact `DropdownItem` instead.

**Why the "Send Bill" WhatsApp item couldn't just become a `DropdownItem
href=...`**: `DropdownItem`'s `href` branch always renders through Inertia's
`<Link>`, which intercepts every click via `router.visit()` regardless of a
`target="_blank"` prop — wrong for an external, cross-origin `wa.me` URL (the
exact reason the original markup used a plain `<a>`). The converted item
stays a plain `onClick` `DropdownItem` that calls `window.open()` itself,
preserving the original "opens in a new tab" behavior without touching
`ui/Dropdown.tsx`.

## 9. Addendum, 2026-08-27: closed the audit-trace gap on `recalculateOne()`

Confirmed finding from a security review: §4's
`CustomerManuscriptRecalculationService::recalculateOne()` mutated `manuscripts` rows with **zero**
run-level trace — no `command_runs` row at all — unlike every tenant-wide `manuscript:calculate` run,
which always logs one. The queued path made this worse: `App\Jobs\RecalculateCustomerManuscriptsForwardJob`
runs on a queue worker, where `auth()->id()` is already gone by the time `handle()` executes, so even
if a row had been logged there was no way to know which admin's approval caused it.

Fixed by having `recalculateOne()` itself always create a lightweight `command_runs` row —
`command = 'manuscript:recalculate-one'`, `period` = the period just recalculated, `status =
'published'` immediately (this path writes synchronously with no `pending_review` gate, matching its
pre-existing behavior — see §4's "no compute/publish preview gate" note), `metadata` carrying at
minimum `customer_id` and `trigger`. **No concurrency lock or rerun guard was added** — this
single-customer/single-period path is already protected by `ArrearsAdjustment::approve()`/`reject()`'s
own `lockForUpdate()` (§2), a genuinely different shape from `idx_command_runs_period_inflight`'s
tenant-wide `(command, period)` key; this fix is purely about closing the audit-trace gap, not a new
safety mechanism.

`recalculateOne()` gained two new, explicit parameters — `string $trigger = 'unspecified'` and
`array $auditContext = []` — merged into the new row's `metadata` alongside `customer_id`. Both real
callers now pass `trigger: 'arrears_adjustment'` plus an `$auditContext` carrying the WHO/WHAT:

- **`ArrearsAdjustmentService::applyLedgerEffect()`** (the synchronous, current-period call) now
  takes the approving admin's `$actorId` as a parameter (threaded from `approve()`, which already has
  a real `User $actor`) and passes `['arrears_adjustment_id' => $adjustment->id, 'triggered_by_user_id'
  => $actorId]`.
- **`RecalculateCustomerManuscriptsForwardJob`** gained two new nullable constructor properties,
  `$arrearsAdjustmentId`/`$triggeredByUserId` — captured by `applyLedgerEffect()` *before* dispatch,
  while a real `auth()` context still exists, and carried on the job's serialized state so `handle()`
  can still attribute every period it recalculates in its forward sweep back to the adjustment and
  admin that caused it, even though it runs on a queue worker with no `auth()->id()` of its own.

The `unspecified` default on `$trigger` exists only so a caller with no real trigger label yet (a
test, or a not-yet-built feature — see `tests/Feature/CustomerManuscriptRecalculationServiceTest.php`'s
own "shape a not-yet-built live-recalculate-on-payment-verification feature would take" calls, which
predate this fix and call `recalculateOne()` with no context) doesn't need to invent one; every real
production caller passes an explicit trigger rather than relying on it. That existing test's fixture
cleanup was extended to also purge the new `command_runs` rows every `recalculateOne()` call now
creates (by `(command, period)`, safe since that file only ever uses fictional far-future periods).

New tests: `tests/Feature/Web/ArrearsAdjustmentCommandRunAuditTest.php` — a direct `recalculateOne()`
call with explicit trigger/context (row exists, correct metadata), the `unspecified`-default case, and
a full end-to-end `POST /arrears-adjustments/{uuid}/approve` request confirming the resulting
`command_runs` row carries both the real `arrears_adjustment_id` and the actually-authenticated
approver's user id.

## 10. Addendum, 2026-08-28: the mobile REQUEST side shipped (still no mobile approve/reject)

Closed the gap §7 used to describe as "mobile — should stay office/web-only." The product owner
clarified: mobile field agents should be able to *request* a write-off (matching the pattern already
established for payments/expenditures/complaints — mobile creates, office reviews), just not
*approve* one — the two-approver review workflow itself is still exclusively web (Audit Log's
"Arrears Adjustments" sub-tab), unchanged.

**Backend — a new JSON API surface, zero duplicated logic.** `POST /arrears-adjustments` (this doc's
existing route) was confirmed to be Inertia/web-session-only — `ArrearsAdjustmentController::store()`
returns a `RedirectResponse` via `back()->with(...)`, not JSON, and there was no `Api\`-namespaced
counterpart. Added one, mirroring `Api\ComplaintController`'s exact "JSON counterpart of the web
controller" shape:

- **`POST /api/v1/arrears-adjustments`** — `App\Http\Controllers\Api\ArrearsAdjustmentController::store()`.
  Reuses `StoreArrearsAdjustmentRequest` (the identical FormRequest the web controller already used —
  same validation, same `ArrearsAdjustmentPolicy::create()` gate via `authorize()`, so mobile cannot
  bypass anything the web form enforces) and `ArrearsAdjustmentService::create()` unchanged — no
  business logic was duplicated or forked. Returns a new `App\Http\Resources\ArrearsAdjustmentResource`
  (201 Created) instead of a redirect. Registered in its own `routes/api/arrears-adjustments.php`,
  required from `routes/api.php` alongside `complaints.php`.
- **Deliberately `store()` only.** No `approve()`/`reject()` JSON routes exist or were added — the
  controller's own class doc states this explicitly, so a future agent isn't tempted to "complete the
  CRUD" by adding them. This is the one deliberate, permanent scope boundary of this addendum.
- **New tests**: `tests/Feature/Api/ArrearsAdjustmentTest.php` — every one of the 5 roles can request
  via the JSON endpoint (mirroring the existing web test's identical per-role loop), plus the same
  three validation-rejection cases the web `StoreArrearsAdjustmentRequest` already covered (future
  target period, blank reason note, non-positive amount) — confirming the API endpoint enforces
  identically, not a looser copy. Run via `php artisan test --filter=ArrearsAdjustmentTest` (matches
  both the web and API test classes; all 20 pass, no collision) after confirming no other test process
  was already running (`Get-CimInstance Win32_Process -Filter "Name='php.exe'"` showed only
  `artisan serve`).

**Mobile — `app/adjust-arrears/[uuid].tsx`.** A new per-customer, online-only modal route (same shape
family as `reconnect/[uuid].tsx`/`disconnect/[uuid].tsx`), reachable from Customer Detail
(`app/(tabs)/customers/[uuid].tsx`)'s "Other actions" cluster as a new, *unconditional* button — unlike
Disconnect (gated to active/passive status) or WhatsApp (gated to having a phone on file), Adjust
Arrears has no visibility gate at all, matching `ArrearsAdjustmentPolicy::create()` being ungated for
every role and every customer status (a disconnected customer's frozen, wrong arrears figure is this
feature's own central use case per §4 above).

Mirrors `ArrearsAdjustmentModal.tsx`'s fields/copy closely: reason-category chips (6 options),
direction chips (decrease/increase), a `YYYY-MM` target-period text field pre-filled with the current
period, an amount field, a required notes field, the identical "This does not record a payment…"
explanatory note, and the same current-balance/balance-after read-only guidance block (client-side
`balanceAfter` calc, explicitly labeled "guidance only" — the real figure is only ever set by a real
`ManuscriptCalculator` run once approved, exactly as on web). The current-arrears figure is fetched
fresh via the same `fetchCustomerDetail()` call `reconnect`/`disconnect` already use — online-only, no
offline queue, no local SQLite cache of this figure — for the same reason those two screens give: this
needs the real current server-side number, not a stale local value.

**Confirmation copy is deliberately NOT "confirmed."** Unlike Reconnect/Disconnect's green
`synced`-tone success screens (those are immediate, already-applied server changes), this screen's
success view uses `Badge tone="pending"` with the label "Submitted — pending approval" and body copy
stating plainly that the customer's balance has not changed yet and still needs office approval — the
one place this build was most careful not to imply an immediate effect that doesn't exist yet.

**New accent color: `colors.accent.arrears` (`#5B21B6`, violet-800, ~8.98:1 white-on-fill, verified via
the same relative-luminance script the 2026-08-27 rebrand pass used).** Checked existing tokens first,
per this app's own convention: the web modal's purple-600 is described in that component's own doc
comment as "the one genuinely unclaimed color" on `Customers/Show.tsx` *specifically* — a page-local
choice, not the web nav's own `NAV_ACCENTS.purple` (which is `Agents`, not this feature). On mobile,
plain purple is already claimed by `colors.accent.expense` (Record Expense / Resources) — reusing it
for an unrelated feature would blur two feature areas under one hue, against this app's own
"color used with restraint to mean something specific" rule. Violet is a genuinely distinct,
previously-unclaimed Tailwind hue family, so a new token was the correct call, not a reuse. Also added
`arrears: colors.accent.arrears` to `StatCard.tsx`'s `toneColors` map (a mechanical fix — `StatCardTone`
is derived from `AccentKey`, so adding the new accent key was a required, not optional, follow-up for
`npx tsc --noEmit` to stay clean; no `StatCard` on this screen actually uses the `arrears` tone yet).

**Not wired into `app/manuscript.tsx`.** Considered per the task brief's explicit invitation, then
deliberately left out: that screen's own class doc states outright "Rows are deliberately
non-interactive plain Cards — no drill-down into Customer Detail, no bill-send action; this is a
glance, not a workflow" — adding a third per-row action there would directly contradict a design
decision that screen already made for itself, not just lack of room. Customer Detail is the complete,
single v1 entry point.

**Verification**: `cd mobile && npx tsc --noEmit` — clean except the two pre-existing
`src/api/devices.ts` errors. `npm test` — 100/100 passing (95 pre-existing + 5 new
`validateArrearsAdjustmentForm` cases in `src/utils/__tests__/validation.test.ts`).

**Deliberately left out of this pass**: any change to `arrears-adjustment.md`'s own approve/reject
model, policy, or service code — this addendum is additive (one new controller, one new resource, one
new route file, one new mobile screen, one new accent token) and touches none of the maker-checker
logic §2–§6 above describe. See `mobile-app-react-native.md`'s own dated addendum for the mobile-side
UI/navigation details in full.

## 11. Addendum, 2026-08-28: "Clear all arrears" quick-fill (web + mobile)

Product owner request, verbatim: a faster way to fill the single most common case — writing off a
customer's ENTIRE current balance — "not that it will eliminate the current implementation... every
previous implementation remains the same." This is a pure pre-fill convenience on both request
surfaces. **Nothing about §1–§10 above changed**: every adjustment, however initiated, still lands as
a `pending` row through the identical `ArrearsAdjustmentService::create()` call, still needs the same
maker-checker approval, and the request-time `arrears_snapshot` is still always captured fresh,
server-side, in that same method — regardless of what amount the client happened to send.

- **Web** (`ArrearsAdjustmentModal.tsx`): a new "Clear all arrears (X)" chip rendered above the Amount
  field, visible only when `customer.manuscript.total_arrears > 0` (hidden — nothing to clear — when
  it's `0` or the customer has no manuscript yet). `clearAllArrears()` sets `direction: 'decrease'` and
  `amount: currentBalance` via `setData`'s functional form (matching `Complaints/Create.tsx`'s and
  `Payments/Create.tsx`'s existing precedent for multi-field updates); `reason_category` and
  `reason_note` are left exactly as the user already had them — no default note is invented, since a
  write-off still needs a real justification, and `reason_note` stays a required field on submit.
  Clicking the chip does not submit; **Submit Request** is still a separate, explicit click.
- **Mobile** (`mobile/app/adjust-arrears/[uuid].tsx`): an identical chip above the amount field,
  styled with the same violet accent already established for this screen
  (`colors.accent.arrears`/`#F3E8FF`, matching `chipActive`'s treatment), sized to the 48dp touch-
  target floor (not the 56dp primary-action size — this is a secondary convenience, not the screen's
  main CTA, which stays Submit Request at `size="large"`). `clearAllArrears()` sets `direction` and
  `amountText` from the screen's existing `currentBalance` state and clears any prior amount
  validation error.

**Why neither surface added a new "fetch fresh" API call for this feature specifically**: the brief's
"needs current server truth, don't trust a stale cached figure" principle is already satisfied by each
surface's *existing* architecture, not by anything new added here —

- Mobile's screen already fetches the customer (and `manuscript.total_arrears`) fresh via
  `fetchCustomerDetail()` on every focus (see §10 above) specifically because that screen has no other
  source of customer data; the quick-fill reuses that already-fresh `currentBalance` state rather than
  issuing a second, redundant fetch.
- Web's modal receives `customer.manuscript.total_arrears` as an Inertia page prop and already uses
  that exact same value, unmodified, for its own "Current balance" display line directly below the
  Amount field — the quick-fill reads from the identical prop, not a separately-cached copy. Building a
  new live-fetch mechanism into this modal (which has never had one) would have been new
  architecture the brief did not ask for, and — more importantly — would not have closed any real gap:
  `ArrearsAdjustmentService::create()` always re-derives `arrears_snapshot` itself, server-side, via
  `arrearsFor()`, independent of whatever amount the request body carries (§1), and
  `ArrearsAdjustmentService::approve()`'s staleness re-check (§2) compares against *that* snapshot, not
  against anything the frontend sent. A quick-filled amount and a hand-typed amount are equally
  protected by that same server-side check — clicking the chip carries no more (and no less) staleness
  risk than typing the number by hand already did.

**No backend changes.** No new route, controller method, validation rule, or policy ability — this is
UI-only on both surfaces, confirmed by re-reading `ArrearsAdjustmentController`,
`Api\ArrearsAdjustmentController`, `StoreArrearsAdjustmentRequest`, and `ArrearsAdjustmentPolicy`
unchanged.

## 12. Addendum, 2026-08-28: the audit-trail request — already built, one field wired in

Product owner request, verbatim: "I hope there's a log table for arrears adjustment... for
auditing... if there's none, assign an agent to work on that feature." Investigated before building
anything, per the brief's explicit instruction not to assume a gap exists.

**Finding: no new table was needed, and none was built.** `arrears_adjustments` already IS the audit
log for this feature, and has been reviewable on a real page since before this addendum — §"Where to
actually use this feature" at the top of this doc, and §6's diagnosis, both already describe the
**Audit Log page's "Arrears Adjustments" sub-tab** (`/audit/logs?view=arrears_adjustments`,
`AuditLogController::arrearsAdjustmentsTabData()`, rendered by `Audit/Index.tsx`'s
`ArrearsAdjustmentsTab`). Before this addendum, that sub-tab already showed, per row: date, customer,
target period, signed amount (direction-aware), reason category, requester, both approvers (with the
first-approval name kept visible even after a second approval supersedes it), status, rejection reason
when rejected, and inline Approve/Reject actions gated by the same `ArrearsAdjustmentPolicy` used
everywhere else in this feature (§3) — server-resolved per row via `can_approve`/`can_reject`, never
re-derived client-side. Separately, because `ArrearsAdjustment use`s the `Auditable` trait (§1), every
create/approve/reject transition on this table *also* already flows into the general `audit_logs`
table and is visible, filterable, and expandable (old/new values) on the same page's "All Activity"
tab — a second, independent trail that already existed and needed no changes here either.

**The one genuine gap, closed**: the sub-tab's table showed the adjustment's *amount and direction*
(the delta) but not the customer's arrears balance immediately *before* the change — the one piece
needed to answer "what changed" without opening a separate customer page. `arrears_snapshot` (§1) was
already captured on every row for an unrelated reason (the approval-time staleness re-check) but was
never surfaced to this payload. Fixed by:

- `AuditLogController::arrearsAdjustmentsTabData()` now includes `arrears_snapshot` in each row's
  payload (one new array key — no query shape change, the column was already selected as part of the
  Eloquent model).
- `resources/tsx/types/index.ts`'s `ArrearsAdjustmentAuditRow` gained the matching `arrears_snapshot:
  string` field.
- `Audit/Index.tsx` gained a new **Balance** column (via a small `BalanceChange` component) between
  Amount and Reason, showing the "before" figure always, and a "before → after" figure **only for
  `status === 'approved'` rows** — deliberately not for pending/rejected ones, since
  `arrears_snapshot ± amount` is only the real, already-applied resulting `total_arrears` for
  `target_period` once §2's approval flow has actually run `ManuscriptCalculator` against it (§4); for
  anything not yet approved, showing a computed "after" figure would misrepresent this table's own
  "what actually happened" contract as a projection, so those rows only ever display the stored
  "before" value. (A guidance-only preview of what the request *would* do already exists separately,
  unchanged, in the request form itself — `ArrearsAdjustmentModal`'s own `balanceAfter` calc.)

**Gating**: unchanged — the whole Audit Log page, both tabs, stays behind `AuditLogPolicy::viewAny()`
(`super`/`admin`/`manager` only). No new permission or gate was added for this addendum; the new
column is just one more field on a payload already restricted the same way every other field on that
page already is.

**Verification note**: this addendum's backend touches one controller method (a single added array
key, no new query) and no service/model/migration code, and the frontend touches one new small
presentational component plus one type addition — reviewed by re-reading every changed file in full,
but the backend PHP test suite could not be *executed* in this pass: a leaked session from an earlier,
unrelated agent run (`idle in transaction` for 45+ minutes, blocking a stuck `DROP SCHEMA ... CASCADE`
and, transitively, ordinary `SELECT`s against `public.users`) had the shared Postgres instance
genuinely gridlocked for the entirety of this work, confirmed via `pg_stat_activity` before and after
attempting a bounded test run. Per this task's own instructions, that session was left untouched rather
than force-terminated. `tests/Feature/Web/ArrearsAdjustmentTest.php`'s existing audit-tab assertion
(`test_the_audit_log_arrears_adjustments_tab_lists_pending_and_decided_requests_with_stats`) only
asserts the `arrears_adjustments.stats`/`.adjustments.data` keys exist and the row count — it does not
enumerate row fields, so it was not expected to (and structurally cannot) break from the new
`arrears_snapshot` key. A follow-up pass should re-run
`php artisan test --filter=ArrearsAdjustmentTest` once the stuck session has cleared.

## 13. Addendum, 2026-08-29: the `super` self-approval carve-out

**Problem it fixes.** This is a ~6-person, owner-operated business. The owner (`super`) is the only
person with unconditional authority, and is also the person who most often raises a small ledger
correction. The maker≠checker rule in §2/§3 gave the owner no way out: an adjustment they requested
themselves could be approved by *nobody* if no other `super`/`admin`/`manager` was around to act —
a permanent deadlock on the owner's own routine corrections. (Concrete case that surfaced this:
`swecom` adjustment #414 — owner-requested, `pending`, 500 FCFA, `billing_error` — with no
Approve/Reject buttons rendering for the owner.)

**The fix (deliberately minimal).** `ArrearsAdjustmentPolicy::approve()` (and therefore `reject()`,
which still just delegates) waives the maker≠checker identity checks **for the `super` role only**,
at **both** stages:

- `pending`: `super`/`admin`/`manager` as before, but the "actor ≠ requester" clause is now
  `(actor ≠ requester OR context is super)`.
- `pending_second_approval`: `super`/`admin` as before, but the two identity checks are now
  `(context is super OR (actor ≠ requester AND actor ≠ first approver))`.

`admin` and `manager` are **completely unaffected** — they remain fully bound by maker≠checker and
the two-senior-approver identity rules, exactly as §2/§3 describe. There is **no config flag, no new
table, no settings screen** — configurable per-tenant permissions are a separate, later effort; this
is a hardcoded role carve-out and nothing more. The two-approver *threshold* logic
(`requiresSecondApproval()` — amount / 90-day-repeat / `legacy_migration_error`) is untouched: a
large owner-requested adjustment still goes to `pending_second_approval`; the carve-out only means
the same `super` can also give that second approval.

**UI — the bypass is explicit, never silent.** `AuditLogController::arrearsAdjustmentsTabData()` now
emits `is_own_request` (`bool`, `requested_by === current user id`) per row, added to the
`ArrearsAdjustmentAuditRow` type. In `Audit/Index.tsx`, clicking **Approve** on a row where
`is_own_request` is true opens a confirmation modal first (same lightweight fixed-overlay pattern as
the existing reject modal) — heading "Approve your own request?", body explaining the
second-reviewer bypass and that it is recorded in the audit log, confirm button "Approve anyway".
Rows that are not the user's own request approve immediately as before. The reject flow's existing
modal already covers self-reject. As always, every transition still writes an `audit_logs` row via
the `Auditable` trait (§1), so a `super` self-approval is fully traceable after the fact.

**Tests** (`tests/Feature/Web/ArrearsAdjustmentTest.php`): a `super` can approve their own request
and it reaches the ledger via a real recalculation; an `admin` and a `manager` still cannot approve
their own; an unrelated `admin` still approves a `super`'s request (maker-checker path unbroken); at
the second stage a `super` who raised *and* first-approved can still give the second approval, while
an `admin` first approver still cannot. Full `--filter ArrearsAdjustment` run green (31 tests).

**No change to** §4's ledger mechanism, the staleness re-check, the row-lock race close, the
mobile surfaces (§10 — still no mobile approve/reject), or the API surface (§10). Purely a policy
clause + one UI confirmation step.

## 14. Addendum, 2026-08-30: credit-target corrections + the delta-vs-recalc rule

### The incident this fixes

The owner imported a fixed **August 2026 manuscript baseline** into the real `swecom` tenant —
`manuscripts` rows for `period = '2026-08'`, `command_run_id = NULL`, figures copied verbatim from
the v1 register (`august_manuscript.csv` / `ManuscriptImportAugust`). These rows have **no v2
payment-processing history behind them** — no `payments.processed_period` was ever stamped for the
historical v1 payments they summarise.

Then two arrears adjustments were approved: **#414 MA TE (customer 24, −500, `billing_error`)** and
**#518 FON CHRISTINA (customer 39, −2500, `bad_debt_writeoff`)**. `ArrearsAdjustmentService::
applyLedgerEffect()` responded the way §4 describes — `CustomerManuscriptRecalculationService::
recalculateOne($customer, '2026-08')` (current period) plus the forward job. But `recalculateOne()`
**recomputes the period from scratch**: `net = previousNet + (bill - income) ± adjustment`, where
`income` is the sum of every not-yet-consumed verified payment. With no prior manuscript row and
`customers.others = 0`, `previousNet` was 0 and `income` was **~42,000 FCFA of historical v1
payments** re-counted as fresh August income. `net` went hugely negative, `splitNet()` turned it
into a bogus `credit` of **40,000 (MA TE)** and **32,500 (FON CHRISTINA)**. The correct baseline
`credit` for both is **0**.

The synthetic guard `command_runs` row (id 1438, `manuscript:calculate` / `2026-08` / `published`,
`metadata.synthetic = true`) that `ManuscriptReconcilePrepaidBaseline` inserts blocks a *tenant-wide*
`manuscript:calculate 2026-08` rerun via `ManuscriptRerunGuard` — but **`ManuscriptRerunGuard` is
not consulted by `recalculateOne()` or by the arrears-adjustment path at all**, and neither is
`ManuscriptRunLockService`. Nothing in `recalculateOne()` / `ManuscriptCalculator` has any notion of
"this period's row is a locked/imported baseline — don't re-derive it from payments." That is the
gap this addendum closes.

**Root lesson: a ledger correction must NOT trigger a from-scratch recompute of a period whose
manuscript row is an imported baseline (`command_run_id IS NULL`) or whose period has already
closed. That re-reads history that was already settled in v1.**

### `target` — arrears vs credit

`arrears_adjustments` gains a `target` column: `'arrears'` (default — every existing row and every
existing code path unchanged) or `'credit'`. `net = arrears - credit`, so an adjustment already
moves both sides via the sign; `target` just picks which **column** the correction lands on:

| `target` | `direction` | effect on the `manuscripts` row |
|---|---|---|
| `arrears` (default) | `decrease` | `total_arrears -= amount` (clamped at 0) — write-off |
| `arrears` | `increase` | `total_arrears += amount` — correct up |
| `credit` | `increase` | `credit -= amount` (clamped at 0) — **claw back** a credit that should not exist (the incident case) |
| `credit` | `decrease` | `credit += amount` — grant credit |

New `reason_category` values, credit-only: `credit_correction`, `duplicate_credit`,
`migration_credit_error` (the original arrears categories stay valid for `target = 'arrears'`). The
column has **no DB CHECK constraint** — `StoreArrearsAdjustmentRequest`'s `Rule::in(...)` is the
single enforcement point (the migration drops the `enum()`-generated check on `reason_category`).

`credit_snapshot` (nullable `decimal(12,2)`) is the credit-side counterpart of `arrears_snapshot`,
captured at request time for **every** request. `ArrearsAdjustmentService::approve()`'s
approval-time staleness re-check now runs on the dimension the adjustment targets: a `target =
'credit'` adjustment is refused if the customer's credit for `target_period` has drifted since the
request; `target = 'arrears'` keeps the exact original arrears check.

### Scope: loose `credit` only

A credit adjustment touches **only** the loose `manuscripts.credit` figure. The prepayment draw-down
model (`references/prepayment-drawdown.md`) also represents prepaid coverage as
`prepaid_months_remaining × prepaid_rate` — **correcting those is explicitly out of scope**. Noted in
the request modal's UI ("Corrects only the loose credit figure — not prepaid coverage") and here.

### The delta-vs-recalc branch (the key rule)

`ArrearsAdjustmentService::applyLedgerEffect()` now inspects the `manuscripts` row for the
adjustment's `target_period` (customer + period) and picks one of three paths:

1. **Imported-baseline row — `command_run_id IS NULL`, row present:** apply the correction as a
   **bounded, audited DELTA** to that one row — `total_arrears` / `credit` moved by exactly
   `amount` (clamped at 0), `total_bill` recomputed as `max(0, bill + total_arrears - credit)` —
   through an Eloquent `->update()` so the `Auditable` trait records old/new `manuscripts` values.
   The adjustment is stamped `processed_at` / `processed_period`. **`recalculateOne()` is NOT called
   and the forward job is NOT dispatched.** This is `applyDirectDelta()`.
2. **Closed period whose row was finalised by a real run — `command_run_id` set AND
   `ManuscriptRunLockService::isPeriodLocked(target_period)`:** approval is **refused** with a clear
   `ValidationException` ("period is closed and its manuscript was finalised by a published
   calculation run … have the owner apply a manual, audited ledger fix"). The request row stays as a
   permanent artifact; the reviewer sees the error via the controller's existing flash handling.
3. **Everything else — no row yet, or a live row in the current / next period:** the **original**
   recalc path from §4, entirely unchanged (synchronous current-period `recalculateOne()` + queued
   `RecalculateCustomerManuscriptsForwardJob`).

"Imported baseline" is detected purely by `command_run_id IS NULL` on the target-period row —
`recalculateOne()` itself clears `command_run_id` to NULL (see its doc), and `ManuscriptImportAugust`
never sets it, so a baseline row stays NULL-linked even after one audited correction on top.

**Residual risk (documented, not closed here):** a forward sweep triggered by a *different*
adjustment against an *earlier* period still runs `recalculateOne()` for every period up to today,
including a NULL-linked baseline period in that range — which would recompute it from scratch. The
`command_run_id IS NULL` guard lives in `applyLedgerEffect()`, not in `recalculateOne()` /
`RecalculateCustomerManuscriptsForwardJob`. For `swecom` this is contained by the synthetic guard row
+ the fact that `2026-08` is the earliest period; a fuller fix would push the baseline check down
into `recalculateOne()`.

### "Clear credit" quick-fill

Mirrors §11's "Clear all arrears": a chip in `ArrearsAdjustmentModal` (visible when `credit > 0`)
that pre-fills `target = 'credit'`, `direction = 'increase'`, `amount =` the customer's current
credit, then stops. Still a full maker-checker request → approve, never a one-click bypass.

### UI

- `ArrearsAdjustmentModal.tsx` — a target toggle (Arrears / Credit) showing both current figures;
  target-aware direction labels ("Claw back (reduce credit)" / "Grant (increase credit)"),
  reason-category menu, amount label, and current/after guidance block; the scope note; the "Clear
  credit" chip.
- Audit Log → Arrears Adjustments sub-tab (`Audit/Index.tsx`, `AuditLogController::
  arrearsAdjustmentsTabData()`) — `target` + `credit_snapshot` in the row payload; `BalanceChange`
  branches on `target` (credit "before → after" from `credit_snapshot`); a "credit" tag on the
  amount cell; the details panel's Direction/Target/"at request time" lines are target-aware.
- Customer Show page adjustments list (`CustomerController::shapeArrearsAdjustments()`) — a Target
  column.
- `ArrearsAdjustmentResource` (JSON API) — `target` + `credit_snapshot` added.
- TS types (`resources/tsx/types/index.ts`) — `ArrearsAdjustmentTarget`, the three new reason
  categories, `ArrearsAdjustment.target`, `ArrearsAdjustmentAuditRow.credit_snapshot`.

### The `swecom` correction (deliver, do not auto-run)

`php artisan arrears:fix-baseline-credit-corruption` (`App\Console\Commands\
ArrearsFixBaselineCreditCorruption`) — restores the correct `2026-08` figures for customers 24 (MA
TE) and 39 (FON CHRISTINA): `bill 2500, total_arrears 2500, credit 0, total_bill 5000` for both
(CSV baseline arrears 3000/5000 minus the approved −500/−2500 of #414/#518). Each write goes through
the Eloquent model → `audit_logs` row. Idempotent (a row already at target is skipped; both →
"nothing to do"), guarded (refuses any tenant ≠ `swecom` without `--force`, aborts if a row is
linked to a `command_run` or missing), dry-run by default. Run:
`php artisan arrears:fix-baseline-credit-corruption --apply`. Safe to delete once run.

### Migration / test note

The migration (`database/migrations/tenant/2026_08_30_000000_add_target_and_credit_snapshot_to_
arrears_adjustments_table.php`) is additive/nullable/backfill-safe. It must be applied
(`php artisan tenants:migrate`) before `phpunit --filter ArrearsAdjustment` — the new tests, and the
existing ones via the factory/`create()` path, reference `target` / `credit_snapshot`. New tests:
credit-target reduces only `manuscripts.credit`; a credit adjustment against a baseline period
applies as a direct delta and creates **no** `manuscript:recalculate-one` `command_run` and does
**not** consume payments; an arrears adjustment against a baseline period does the same (the literal
incident shape); the credit staleness re-check trips on drift; "clear credit" produces
`amount == current credit`.
