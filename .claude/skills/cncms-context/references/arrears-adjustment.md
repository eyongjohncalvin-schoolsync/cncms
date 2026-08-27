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
| `approve`/`reject` at `status = 'pending'` | `super`/`admin`/`manager`, actor ≠ requester |
| `approve`/`reject` at `status = 'pending_second_approval'` | `super`/`admin` only, actor ≠ requester **and** actor ≠ first approver |
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

- **Mobile.** Confirmed via grep that `mobile/` has zero references to the request/approve/reject
  workflow (only unrelated arrears-*balance display* on a few screens). This should stay
  office/web-only — mobile field agents should not be able to write off arrears — and nothing here
  changes that.
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
