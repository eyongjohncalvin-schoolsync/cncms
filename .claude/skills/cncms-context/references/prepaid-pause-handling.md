# Prepaid-Time Preservation Across Suspend/Disconnect — Design Spec

Status: **SUPERSEDED (2026-08-29) by `references/prepayment-drawdown.md` — do not build.**
Under draw-down credit, a frozen customer's `prepaid_months_remaining` / `prepaid_rate` /
`credit` are simply carried forward untouched and not decremented while frozen (that doc's
PD-8), so the customer resumes with exactly what they had — no `status_changed_at`
arithmetic, no `prepaid_paused` flag, no reconnect-time date extension. This entire spec
exists only to patch the calendar-date freeze model that draw-down replaces. Kept for
historical context; the sections below describe a problem draw-down does not have.

---

Original status: **Design, not yet implemented — build after the in-flight suspended-freeze fix lands**
(that fix adds `suspended` to `ManuscriptCalculator`'s freeze branch; this feature is a distinct,
more precise mechanism layered on top of it, not a variation of it — see §1 for why they're
different problems). Owner ask, verbatim reasoning: a customer who prepaid 6 months and used only
4 before being suspended/disconnected must get their remaining 2 months back once they return,
"because there are edge cases like that, and that's why is good to be flexible... this creates
confidence."

---

## 1. Why this is a different problem from the freeze fix, not a duplicate of it

The freeze fix (suspended now behaves like disconnected: arrears/credit/`payment_expiration` all
carry forward unchanged, no new charges accrue) solves **safety** — a frozen customer is never
retroactively billed for time they didn't have service. It does not solve **fairness** for prepaid
time specifically: `payment_expiration` is an absolute calendar date, carried forward *unchanged*
during a freeze. If the freeze lasts longer than however much prepaid time was left when it began,
that absolute date quietly passes *while still frozen*. On reconnection, normal billing simply
resumes from "now" — the customer isn't overcharged, but the unused portion of what they already
paid for is silently forfeited, not honored. A customer with 2 paid-for months left who's suspended
for 3 gets zero benefit from those 2 months under the freeze fix alone.

This feature closes that gap: **track exactly how much prepaid time remained when the freeze
began, and on reconnection, extend `payment_expiration` forward by the freeze's actual duration**
— so the customer always gets the full prepaid window they paid for, regardless of how long the
freeze lasted.

## 2. Disconnect vs. suspend — genuinely different handling, confirmed by the owner

- **Disconnect: automatic, unconditional preservation. No admin choice offered.** Disconnection is
  a non-payment enforcement action — there's no legitimate scenario where a disconnected (usually
  for non-payment) customer's already-paid-for time should keep silently counting down while they
  can't use the service at all. Every disconnected customer with an active prepaid window gets it
  extended by the exact disconnection duration on reconnect, no exceptions, no prompt.
- **Suspend: admin is asked, defaults to preserving.** Suspension is often voluntary or
  service-related (a customer traveling, a technical/service-side issue, a temporary hold the
  customer themselves requested) — there's a real, legitimate case for "let it keep counting down
  as normal, the customer chose this and doesn't mind." So at the moment of suspending a customer
  who has an active, unexpired `payment_expiration`, the admin is shown an explicit choice:
  - **"Pause prepaid countdown" — the default, pre-selected option.**
  - **"Continue prepaid countdown"** — an explicit, deliberate opt-out.
  If the customer being suspended has NO active prepaid window, this choice never appears at all —
  nothing to choose, suspend proceeds as a plain status change.

## 3. Data model additions

- `customers.status_changed_at` (nullable timestamp) — **a genuinely new field; confirmed via grep
  that nothing like this currently exists anywhere on `Customer`.** Set whenever `status` changes
  (disconnect, suspend, reconnect, and ordinary active/passive transitions too, for consistency —
  though only the disconnect/suspend timestamps are load-bearing for this feature). This is the
  "freeze began at" anchor the duration calculation needs; today status changes are only visible
  via `audit_logs`, which is correct for history but too indirect/expensive to query on every
  reconnect calculation.
- `customers.prepaid_paused` (nullable boolean) — set at suspend time ONLY when the admin's
  choice (or the customer having no active prepaid window, which makes it moot) applies; null/false
  otherwise. Read once, at reconnect time, to decide whether to extend `payment_expiration`; cleared
  back to null on reconnect regardless of outcome, since it's a one-suspension-cycle flag, not a
  standing customer property.
- No new field needed for disconnect — its extension is unconditional, so `status_changed_at`
  alone is sufficient to compute the duration to add.

## 4. Calculation, at reconnect time (in `CustomerStatusService::reconnectOne()`)

- **From `disconnected`**: if `customer.payment_expiration` is set (an active or lapsed-during-
  freeze prepaid window exists), compute `frozenDuration = now() - customer.status_changed_at` and
  set `payment_expiration = payment_expiration + frozenDuration` (date arithmetic, not a fixed
  day-count constant — a 3-year freeze extends by 3 years, a 3-week one by 3 weeks). Always,
  unconditionally, whenever a `payment_expiration` exists.
- **From `suspended`**: same calculation, but only performed **if `customer.prepaid_paused` was
  true** for this suspension. If it was false (admin chose "continue"), `payment_expiration` is
  left exactly as-is — today's simple carry-forward behavior, which is the CORRECT behavior for
  that choice (the customer explicitly agreed their prepaid clock should keep running during the
  hold).
- Reuse the exact `Carbon` date-arithmetic conventions already established elsewhere in
  `ManuscriptCalculator`/`CustomerStatusService` — this is calendar-date math, not something that
  touches bcmath money calculations at all.

## 5. Admin-facing UI — notes, instructions, and the actual options requested

**At suspend time**, when the customer being suspended has an active `payment_expiration`, the
suspend modal (extending `CustomerStatusActions.tsx`'s existing suspend flow) must show:

- A short, plain-language notice explaining WHY this choice matters, e.g.: *"This customer has
  prepaid service through [date]. Choose what happens to that remaining time while suspended:"*
- **Option A — "Pause the prepaid countdown" (pre-selected, labeled Recommended)** — helper text:
  *"Their remaining prepaid days are preserved and resume exactly where they left off once
  reconnected, no matter how long the suspension lasts."*
- **Option B — "Let it continue as normal"** — helper text: *"The prepaid window keeps counting
  down during the suspension. If it runs out while still suspended, normal monthly billing will
  begin the moment they're reconnected."*
- If the customer has NO active prepaid window, skip this entirely — the suspend flow proceeds
  exactly as it does today, no extra step, no confusing choice with nothing behind it.

**At disconnect time**, no choice is offered (per §2), but if the customer has an active prepaid
window, show a **purely informational** note, not a prompt: *"This customer has [N] days of
prepaid service remaining — it will be preserved and resumed automatically once reconnected."*
This keeps the "creates confidence" goal visible to the admin at the moment they act, not just as
an invisible backend guarantee.

**On the customer's own record** (`Customers/Show.tsx`), while suspended/disconnected with an
active prepaid window, surface the same fact passively — e.g. a small note near the status badge:
*"Prepaid through [original date] — paused since [suspension date]"* (suspend+paused) or *"Prepaid
time will resume on reconnection"* (disconnect, or suspend+paused) vs. *"Prepaid clock is still
running"* (suspend+continue) — so any staff member looking at the customer's page understands
which of the two states currently applies without having to check settings/logs.

## 6. Explicitly out of scope

- No per-suspension editable "add N extra bonus days" admin override — the mechanism is purely
  "give back exactly what was paused," not a discretionary goodwill-credit tool (that's what the
  separate `references/arrears-adjustment.md`-style write-off feature, if/when built, is for).
- No retroactive application to customers already reconnected before this feature ships — this
  only governs suspend/disconnect/reconnect cycles that happen after it's live. Don't attempt to
  "fix" historical reconnections retroactively; that's a data-migration decision, not something to
  assume as part of this feature's scope.
- Multiple overlapping suspend/disconnect cycles are already handled correctly by construction:
  each cycle's `status_changed_at` is overwritten fresh at the start of that cycle, so nested or
  sequential freezes each compute their own correct duration independently — no special-casing
  needed for a customer who's been suspended, reconnected, then disconnected, then reconnected
  again over the years.
