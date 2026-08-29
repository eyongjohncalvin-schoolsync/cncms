# Prepayment as Draw-Down Credit — Design Spec

Status: **Approved, rulings complete, ready to implement.** Owner decision
2026-08-29 following a two-round design deliberation. All four open questions
resolved (§8). This supersedes the "freeze branch" handling of `months`/`yearly`
payments in `business-rules.md` §7 and retires `prepaid-pause-handling.md` (§9).
First implementation step is the §7 boundary-bug fix (its own PR); then §10.

Owner's reasoning, verbatim intent: *"go with the draw-down, it's the best approach
to avoid long-term problems"* and, on rate changes: *"if some buy six months, then
midway rates change, the rate change should only be effective for that client after
the six months elapse — that's the honest approach for the real business world."*

---

## 1. The problem with today's model

`ManuscriptCalculator` has **two** unrelated ways money is tracked:

- **Monthly payments** flow through the arrears/credit ledger:
  `net = previousNet + (bill − income) ± adjustments`, split into
  `total_arrears` / `credit` (`ManuscriptCalculator.php` class doc, ~L46-74).
- **`months`/`yearly` payments** bypass the ledger entirely. `PaymentService::
  computeExpirationDate()` turns them into a calendar `expiration_date`; the
  calculator's "prepaid freeze" branch (`ManuscriptCalculator.php:178-203`) then
  sets `total_bill = 0` while that date is in the future, `income = '0.00'`, and
  the payment's `amount` **never enters the ledger** — its value lives only in the
  date.

Every known prepayment defect traces to that split:

| # | Defect | Cause |
|---|---|---|
| 1 | Coverage vs. income conflated | freeze piggybacks on `Payment::scopeEligibleForPeriod`, a consume-once mechanism |
| 2 | Overpayment on a multi-month payment is **forfeited** | `income = '0.00'` in the freeze branch; only the expiring payment is marked processed (`:196`, `:200`) |
| 3 | A prepayment never reduces existing arrears | same — arrears freeze and reactivate |
| 4 | Stacked renewals under-credit the customer | `max(expiration_date, previousExpiration)` (`:181-184`) — buy 6 while 3 remain, get 6 not 9 |
| 5 | Freeze test uses wall-clock `now()` not the period being computed (`:140`, `:186`) | a historical recalc re-evaluates against today, changing past rows |
| 6 | No refund / cancellation path | the money is represented nowhere the ledger can see |

---

## 2. The model — prepaid months at a locked rate, backed by credit

A `months = N` (or `yearly`, N = 12) payment of amount `A`, made while the
customer's bill is `R`:

1. **`A` is recorded as `credit`** on the account — real money on the ledger,
   visible in the register, refundable.
2. **`N` prepaid months are recorded**, each **locked to rate `R`** (the rate at
   purchase). Stacking a second block *adds* to the remaining count.
3. Any overpayment (`A − N·R`, if positive) is just extra `credit`, drawn at the
   **current** rate once the prepaid months are exhausted.

Each monthly `manuscript:calculate` run, for a customer with prepaid months left:

- effective charge this period = **the locked prepaid rate** `R`, *not* `customer.bill`
- `credit −= R`; `prepaid_months_remaining −= 1`
- `total_bill = 0`

Once `prepaid_months_remaining` hits 0, the customer is an ordinary customer with
whatever `credit` is left, drawn down at `customer.bill` (the current rate) exactly
like any monthly overpayment — and then normal arrears accrual.

This satisfies both owner decisions at once: the money is on one ledger
(draw-down), and the rate is locked for the duration the customer paid for
(rate-lock).

### Where the state lives

Carried forward on the manuscript row, the same mechanism `payment_expiration`
uses today (`ManuscriptCalculator.php:154` reads it off `previousManuscript`):

- `manuscripts.credit` — already exists (`decimal(12,2)`).
- `manuscripts.prepaid_months_remaining` — **new**, `smallint`, default 0.
- `manuscripts.prepaid_rate` — **new**, `decimal(12,2)`, nullable (null when
  `prepaid_months_remaining = 0`).

`payment_expiration` becomes a **derived display value** only — recomputed each run
as roughly `end-of-period + prepaid_months_remaining months` — kept so the register
PDF and the API keep showing a "covered through" date. Nothing reads it back as
logic. `payments.frequency` / `payments.months` stay as the record of what was
bought; `payments.expiration_date` stops being written for new payments.

---

## 3. Numbered rules

**PD-1.** A `months`/`yearly` payment credits `amount` to `manuscripts.credit`,
records `months` prepaid periods, and locks `prepaid_rate` to the customer's bill
at the moment of payment. If the payment's `clear_arrears_first` flag is set
(Q1), `min(amount, previousArrears)` is applied to arrears first and the prepaid
month count is derived from what's left (`floor(remainder / R)`), remainder to
credit.

**PD-1a.** Locked state is immutable (Q4): once a period is locked / its
`command_run` published / a manuscript row carries a `prepaid_rate`, no code path
rewrites it. Corrections are forward-only — a new payment or an arrears adjustment
that lands in the current or a future period, never a rewrite of a past one.
*Reconcile with `RecalculateCustomerManuscriptsForwardJob` during implementation:*
its forward-replay must start at the earliest **unlocked** period, never re-touch
a locked row.

**PD-2.** While `prepaid_months_remaining > 0`, each run charges `prepaid_rate`,
draws it from `credit`, decrements the counter, and sets `total_bill = 0`. The
customer's *current* `bill` is irrelevant to them until the counter reaches 0.

**PD-3.** A bill-rate change (`CustomerService::update()`, bulk bill update) does
**not** affect a customer with `prepaid_months_remaining > 0`. The new rate applies
from the first period after their prepaid months are exhausted. No compensating
adjustment, no top-up — the locked rate already handles it.

**PD-4.** When `prepaid_months_remaining` reaches 0, any remaining `credit` is
drawn down at the current `customer.bill`, then normal arrears accrual resumes —
identical to a monthly overpayment today.

**PD-5.** Stacking: a second `months`/`yearly` payment *adds* to
`prepaid_months_remaining` and re-locks `prepaid_rate` to the newest purchase's
rate for all remaining prepaid months (see Q2). Its `amount` adds to `credit`.

**PD-6.** No proration. A prepaid month is a whole month. When the counter lapses
mid-calendar-month there is no residual-day credit or charge (goodwill), matching
`business-rules.md` §7.

**PD-7.** An N-month payment yields exactly N `total_bill = 0` months — the §7
boundary bug must be fixed for this to hold.

**PD-8.** Disconnect / suspend / passive: no charge accrues and the counter does
**not** decrement while frozen (`ManuscriptCalculator.php:159-176` already returns
before any charge logic). On reconnection the customer resumes with the same
`prepaid_months_remaining` / `prepaid_rate` / `credit` they had — their prepaid
time is preserved automatically, no date arithmetic. This is what retires most of
`prepaid-pause-handling.md`.

**PD-9.** Refund / early cancellation: the unused value is
`prepaid_months_remaining · prepaid_rate` (+ any non-prepaid `credit`), visible
directly on the ledger. Pay it out (Resources/expenditure) + a `credit_clawback`
arrears adjustment to zero the balance. No new mechanism.

**PD-10.** Rejected / un-verified `months`/`yearly` payment: no credit, no prepaid
months — same exclusion as income today (`business-rules.md` §2, §9).

---

## 4. Worked examples

Bill `R = 2,500`. Customer pays `15,000`, `months = 6`.

| Period | prepaid left (start) | rate used | credit before → after | total_bill |
|---|--:|--:|--:|--:|
| 1 | 6 | 2,500 (locked) | 15,000 → 12,500 | 0 |
| 2 | 5 | 2,500 | 12,500 → 10,000 | 0 |
| 3 | 4 | 2,500 | 10,000 → 7,500 | 0 |
| 4 | 3 | 2,500 | 7,500 → 5,000 | 0 |
| 5 | 2 | 2,500 | 5,000 → 2,500 | 0 |
| 6 | 1 | 2,500 | 2,500 → 0 | 0 |
| 7 | 0 | 3,000 (current) | 0 → — | 3,000 |

**Rate raised to 3,000 after period 2:** periods 3-6 still charge 2,500 (locked,
PD-3), period 7 charges 3,000. The customer got exactly the 6 months they paid for
at the price they paid.

**Overpaid — pays 20,000 for "6 months":** `6 · 2,500 = 15,000` → 6 prepaid months
at 2,500; the extra `5,000` sits in `credit` and covers ~2 more months at the
then-current rate after period 6 (PD-4).

**Renews early — 3 prepaid months left, pays another 15,000 for 6:**
`prepaid_months_remaining` becomes `3 + 6 = 9` (PD-5); `credit += 15,000`. Nine
covered months, not six.

**Had arrears — owes 5,000, pays exactly 15,000 for 6 months:** see Q1.

---

## 5. Bill / register display

- Prepaid month prints **"PREPAID — N month(s) left (through MMM YY)"**, not a
  `Net Monthly Bill … Total: 0` line that reads as an error.
- The register's `Expiry` column keeps working from the derived
  `payment_expiration`.
- `manuscripts.credit` becomes a **meaningful moving number** on the register
  instead of near-always-zero.
- New reportable figure for the planned Resources / P&L module: **deferred
  revenue** = `Σ prepaid_months_remaining · prepaid_rate` across active customers —
  prepaid cash that isn't yet earned income.

---

## 6. What changes in code

- **`PaymentService::computeExpirationDate()`** — stop writing `expiration_date`
  for `months`/`yearly` (return null). Forward-only: legacy rows keep their stored
  date.
- **`PaymentService::create()` / a new prepaid step** — on a verified
  `months`/`yearly` payment, set the customer's next manuscript's
  `prepaid_months_remaining` / `prepaid_rate` seed. (Mechanically: the calculator
  reads eligible payments already; it can derive the counter from a payment's
  `months` + the customer's bill-at-payment-time. Storing `prepaid_rate` on the
  payment row itself — a new nullable column — is the clean way to capture
  "bill at payment time" without reconstruction.)
- **`ManuscriptCalculator::calculate()`** — replace the freeze branch
  (`:178-203`) with the PD-2 draw-down: read `prepaid_months_remaining` /
  `prepaid_rate` off `previousManuscript`, decrement, charge the locked rate
  against credit. Delete the `$asOf` / `candidateExpiration` date logic. Keep the
  status-freeze branch (`:159-176`) untouched.
- **`Manuscript`** — two new columns + migration (tenant); add to `#[Fillable]`
  and `toManuscriptAttributes()`.
- **`ManuscriptCalculationResult`** — carry `prepaidMonthsRemaining` /
  `prepaidRate`.
- **`Payment`** — new nullable `prepaid_rate` column (bill snapshot at payment) +
  `clear_arrears_first` boolean (Q1, default false, only meaningful for
  `months`/`yearly`).
- **`StorePaymentRequest` / `PaymentData` / the payment form** — accept
  `clear_arrears_first`; the form previews the resulting split (months covered +
  arrears cleared/remaining) before submit.
- **`CustomerService::update()` bill change** — no special handling needed (PD-3
  falls out of the locked rate) beyond confirming the calculator ignores
  `customer.bill` while the counter is non-zero.
- **Pre-run review** (`ManuscriptPreRunReviewService`) — the prepaid-window check
  (rule 3) becomes "`prepaid_months_remaining > 0`"; the credit-coverage check
  (rule 4) already covers the rest.
- **Frontend** — `Manuscripts/Index` / `Customers/Show` show the "N months left"
  line; `formatMonthYear` already added for the derived date.
- **Docs** — rewrite `business-rules.md` §7; mark `prepaid-pause-handling.md`
  superseded (§9).

---

## 7. BLOCKER — the ledger exhaustion-boundary bug

`total_bill = bill + total_arrears − credit` (`ManuscriptCalculator.php:217`)
bills the **full amount** in the period a credit is exhausted exactly (`net == 0`),
because at that point `credit` has already been spent to 0 and no longer offsets
the fresh bill. Result: an N-month payment currently yields **N−1** free months.

- Confirmed: `ManuscriptCalculateTest::test_a_months_payment_with_no_expiration_date_draws_down_as_credit`.
- Long acknowledged as a "known wart" in
  `::test_credit_is_consumed_before_arrears` (see its period-3 comment).
- The freeze model does not have this bug (dates, not arithmetic), which is why it
  was tolerable to leave — but draw-down makes it hit **every** prepaid customer.

**Fix direction:** when the customer is square or ahead for the period
(`net ≤ 0`), `total_bill` must be `0`, not `bill`. This is a small change to one
formula, but it shifts every credit-exhaustion by a period across the whole
customer base and every historical recalculation — so it needs the Q4 ruling, a
before/after diff of the existing arrears/credit tests, and a check of the printed
bill.

---

## 8. Owner rulings (2026-08-29) — resolved

**Q1 — arrears + prepayment → an agent choice at payment time, not a fixed rule.**
The payment form carries a **"clear outstanding arrears first"** toggle (only shown
for `months`/`yearly`). The agent sets it in agreement with the customer.
- **Toggle ON:** the payment first pays down `previousArrears`
  (`cleared = min(amount, previousArrears)`); the remainder establishes
  `floor(remainder / R)` prepaid months + any leftover as credit. Fewer prepaid
  months if the amount didn't also cover the debt.
- **Toggle OFF (default):** full `amount` → credit + `months` prepaid months; the
  arrears carry forward untouched and show on the bill as *"arrears X still
  outstanding, not covered by prepayment"*.
- The form must **preview the split** before submit — "covers 4 months + clears
  5,000 arrears" vs "covers 6 months, 5,000 arrears still due" — so the agent and
  customer see exactly what the toggle does. Store the toggle on the payment
  (`clear_arrears_first` boolean) for the audit trail.

**Q2 — stacked blocks: single rate.** All remaining prepaid months use the newest
purchase's rate; `prepaid_rate` is re-locked on each new `months`/`yearly` payment
(PD-5). One field, no per-block tracking — the owner accepted this as an edge case
not worth the complexity.

**Q3 — overpayment: ordinary credit.** `amount − N·R > 0` (or the leftover under
Q1-ON) → plain `credit`, drawn at the current rate once prepaid months are
exhausted (PD-4). Owner: *"the honest business approach — customers trust the
system, they're not being cheated."*

**Q4 — a covered period shows `total_bill = 0`.** Fix the §7 boundary bug so a
period where the customer is square or ahead (`net ≤ 0`) bills 0, not `bill`.
**No retroactive concern:** the owner's standing rule is *no historical
recalculations* — once a period/payment/manuscript carries a locked marker
(`prepaid_rate`, a published `command_run`, a locked period) it is **immutable,
for data integrity**. The boundary fix therefore only has to be correct from the
cutover forward; it never rewrites a locked past row.

---

## 9. What this retires

- **The freeze branch** (`ManuscriptCalculator.php:178-203`) — after the last
  legacy `expiration_date` payment lapses (≤ 12 months from cutover), delete it
  along with `computeExpirationDate()` and the `$asOf` date comparison.
- **`prepaid-pause-handling.md`** — mostly unnecessary. Under draw-down, a frozen
  customer's `prepaid_months_remaining` / `credit` are carried forward untouched
  (PD-8), so they resume with exactly what they had — no `status_changed_at`
  arithmetic, no `prepaid_paused` flag, no reconnect-time date extension. Mark it
  superseded; do not build it.
- **Defect list in §1** — 1, 3, 4, 5 fixed outright; 2 fixed by PD-4/Q3; 6 made
  trivial by PD-9.

---

## 10. Rollout — forward-only, parallel-run verified

1. Fix the §7 boundary bug (own PR, own review, own before/after diff).
2. Add the two manuscript columns + the payment `prepaid_rate` column (tenant
   migrations).
3. New `months`/`yearly` payments stop setting `expiration_date`; start seeding
   the prepaid counter. Legacy rows keep riding the freeze branch.
4. **Parallel-run check** against a non-`swecom` tenant (or a disposable schema
   seeded from real-ish data): run `manuscript:calculate` for several periods both
   ways, diff the registers, confirm N months = N months and the rate-lock holds.
5. Migrate the 22 currently-prepaid `swecom` customers: for each, derive
   `prepaid_months_remaining` from `payment_expiration` vs. now and
   `prepaid_rate` from the establishing payment; seed onto their current manuscript
   row via an audited one-off command (like `manuscript:reconcile-prepaid-baseline`).
6. Once the last legacy `expiration_date` has lapsed, do the §9 deletions.

**Do not** run `manuscript:calculate` on `swecom` during any of this — the owner
runs September on the current (freeze) model after validating August payments.
Draw-down applies from a later cutover the owner picks.
