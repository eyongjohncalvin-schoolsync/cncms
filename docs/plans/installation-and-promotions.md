# Installation fees, free months (promotions & compensation), technician fees

**Owner ask (2026-10-22):** adding a customer has no place for the
installation amount or the free months given with it. The marketing strategy
gives new clients free months; some clients pay installation *and* pay in
advance. The office must also be able to give free months to compensate for
poor service. Every gap must be covered. Designed from three independent
brainstorms (money/ledger, product/operations, adversarial gap hunt) and the
owner's answers below.

Work happens on short-lived branches merged to `main` (and `preview`) per
phase. Tests run only on `cncms_testing`, one `phpunit` process at a time.
Never touch the live `cncms` database.

## Owner decisions (2026-10-22)

| # | Decision |
|---|---|
| D1 | Unpaid installation is a **separate balance**, shown on the bill, never part of arrears and **never a reason to disconnect**. |
| D2 | Installation may be paid in **instalments**. |
| D3 | Free months that come with installation start **only once installation is fully paid** (a waiver counts as fully paid). Until then the customer is **connected and billed normally**; the free months start the month after the last installation payment. |
| D4 | **Start rule ("Option X"):** the month the customer joins is not billed; free months always start the **month after** installation is fully paid, whatever day it is — even if that next month's bills were already generated. No accidental extra months. |
| D5 | Free months run **by calendar** — they do not pause while disconnected/suspended (unlike paid months). |
| D6 | **Order:** free months first, then paid months, then normal billing. |
| D7 | Free months are **per service** (e.g. TV 2 months, Internet 1 month). Each month only services whose free months are used up are charged. Advance payments cover exactly what is charged each month; the screen computes the amount. |
| D8 | **Max 3 free months** per grant (company setting). Managers pick standard offers; anything beyond (more months, lower fee, full waiver) needs **admin/owner approval**, maker ≠ checker. |
| D9 | Installation is **non-refundable by default**, but a **"Refund installation"** action exists (approval + voucher payout) for when the company decides to refund. Cancelling: unused paid months refundable at the locked rate; free months forfeited. |
| D10 | Field agents may register customers **offline** → "pending activation" until the office activates (phase 3). |
| D11 | No bill slips for free months. The free period and first-bill month are printed **on the installation receipt** (no separate slip). |
| D12 | **Compensation free months:** the office can grant free months for poor service — alone, alongside an arrears write-off (existing arrears adjustments), or to a customer who already paid in advance. Never income. Approval + reason. All or some services. |
| D13 | **Reconnection fine = technician motivation fee.** Not company income, never part of the bill, arrears or disconnection — **silent**. Recorded only for the audit trail and the customer's receipt. The agent hands it to the cashier with the day's cash; the cashier pays the technician and that payout is recorded. |
| D14 | **Past fines** (which were wrongly credited to customers' subscriptions) are left as they are; the fix applies from now on. |

## Phase 2 revision — owner decisions (2026-10-23)

After seeing the first Phase 2 build, the owner simplified how months are chosen.
These supersede D4 (automatic "join month not billed") and the offer-driven form.

| # | Decision |
|---|---|
| D15 | **Months are chosen explicitly per service at registration.** For each service ticked (TV, Internet, …) the form offers ☐ **Free months** — month chips starting from the installation month — and ☐ **Paid upfront** — a number of months that follow straight after the free months, at that service's own price. Each service gets its own timeline: free → paid → billed. |
| D16 | The **installation month is ticked as free by default**, with a hint/tooltip to add more. The user's choice settles whether the installation month is free (untick it and it is billed). Free months are one **consecutive** run starting at the installation month or later. Max 3 free months (company setting) unless an admin/owner approves more; reason + approver recorded. |
| D17 | Free months chosen at registration apply **only if installation is fully paid at registration**, or an admin/owner approves them (replaces D3's "wait until paid"). |
| D18 | When a service's free and paid months end, the customer is **billed exactly like every other customer** — same ledger, same pay-ahead rule (business-rules step 5). There is **no separate month-by-month rule** for new customers. |
| D19 | **Installation offers stay as presets**: picking one ticks the boxes (fee, free months per service), which the user can still change (changes beyond the preset need approval as in D8). |
| D20 | **Compensation months** (D12) are added to the chosen service's timeline after its existing free/paid months, so prepaid value is never lost. |

## Bugs this fixes

- **Reconnection fine credited back to the customer.** `CustomerStatusService::reconnectOne()`
  records the fine as an ordinary `monthly` payment with no matching charge, so
  `ManuscriptCalculator` counts it as subscription money (lower arrears / credit).
- **Accidental free months.** A customer created after the next month's run was
  generated gets no row for that month (first charge a month later), with nobody
  deciding it.
- Any payment counts as subscription income (`Payment::scopeEligibleForPeriod`,
  `ResourcesDashboardService::incomeFor`, `ReportService`), so installation money
  would become free months of credit and inflate subscription income.

## Phase 1 — money correctness (foundation)

1. **`payments.purpose`**: `subscription` (default — safe for old mobile builds) ·
   `installation` · `technician_fee`. Immutable once verified, receipted or handed
   over (corrections = reject/void and re-record).
2. **Billing only sees subscription money.** `Payment::scopeEligibleForPeriod()`
   (shared by the calculator, the chunk resolver, the pre-run review and
   `recalculateOne`) filters `purpose = subscription`; non-subscription payments
   never get `processed_period` stamps; publish/unpublish unaffected.
3. **`customer_charges`** (tenant): one-off amounts a customer owes — `kind`
   (`installation` now), `amount`, `standard_amount`, `status`
   (`open|paid|waived|refunded`), reason, requested/approved by, uuid, Auditable.
   `payments.customer_charge_id` links installation payments (instalments).
   Balance = amount − verified installation payments. Shown on the customer page
   and as a separate line on bill slips; **not** in `total_bill`/arrears, **not**
   in disconnection eligibility (D1).
4. **Technician fee (D13).** `reconnectOne()` records the fine as
   `purpose = technician_fee`. Receipt titled "Technician motivation fee". It is in
   the agent's remittance/handover as its own subtotal (so the cashier counts it);
   the cashier records the payout to the named technician (a cash-book outflow
   linked to the fee payments — no voucher approval chain, it is pass-through).
   Excluded from bills, arrears, income, collection rate, dashboards (silent).
5. **Receipts.** Title per purpose (subscription / installation / technician fee),
   including the thermal template. `balanceAfter()` and `coveredPeriods()` use
   subscription payments only. Installation receipt shows fee, paid, balance.
6. **Reports.** Income split by purpose everywhere (daily/weekly/monthly, P&L,
   investor views, zone/agent/branch): subscription income, installation income;
   technician fees shown only as pass-through in the cash book. Collection rate
   and ARPU use subscription only.
7. **API + mobile:** purpose exposed read-only on payment resources; mobile record
   payment stays subscription.
8. **No retroactive change to past fines (D14).**

## Phase 2 — free months, offers, add-customer

1. **Installation offers** (Settings → Installation offers): name, fee, valid
   from/to, active, one default, **free months per service** (offer ↔ services,
   capped by `companies.max_free_months`, default 3). Copied onto the customer at
   creation (later offer edits never change past customers).
2. **Customer fields:** `connected_on`, `billing_starts_period` (D4: month after
   joining; if that month's run is already generated/published when the customer
   is created, the system computes this customer's row itself so nothing is
   skipped or double-given).
3. **Free-month grants** (`free_month_grants`, Auditable, maker-checker for
   anything beyond a standard offer): customer, service (or all services),
   months, reason (`installation_offer | compensation`), status
   (`awaiting_installation_payment | pending_approval | approved | rejected`),
   start period, requested/approved by. Installation grants become active the
   month after the installation charge is fully paid or waived (D3).
   Compensation grants (D12) start the next unlocked period. Never backdated into
   a locked period.
4. **Engine (per service, D5–D7).** Each month: services with free months left
   are not charged (counter decrements by calendar, even while frozen); the
   remaining charge is covered by paid prepaid months (draw-down) if any, else
   billed normally. A paid month covers exactly the charged part of that month.
   Free months are zero-value: never income, arrears, credit, deferred revenue or
   refundable, unaffected by rate changes. Every engine touchpoint carries the new
   state: calculator branches (normal, draw-down, legacy freeze), chunk resolver,
   CLI, publish/unpublish stamping, `recalculateOne`, forward recalculation,
   pre-run review.
5. **Add-customer (web + mobile online):** section "Installation & first payment":
   offer, fee (override → reason → approval), amount paid now, method/reference,
   advance months, connection date, **live preview** ("Collect now: 17,500 · TV free
   Nov–Dec · Internet free Nov · first bill Jan 2027"). One save, one DB
   transaction: customer, installation charge + payment, optional advance payment,
   grants; a receipt per payment. The installation receipt carries the free period
   and first-bill month (D11).
6. **Compensation grant action** on the customer page (D12), approval + reason,
   all/some services; works with the existing arrears adjustment.
7. **Refund installation** (D9): approval + voucher payout; status `refunded`.
8. **Displays & messaging:** customer page coverage line ("TV free until Dec ·
   paid until Mar · first bill Apr"); register shows FREE; free-month bill slips
   skipped; reminders / WhatsApp skip customers with nothing due; eligibility,
   arrears ageing, statement (`InvoiceService` must not bill free months);
   coverage labels distinguish FREE from PREPAID; mobile pull carries promo state.
9. **Existing customers:** installation "not applicable" (never "unpaid"); an
   Opening Balances column "free months remaining" (zero-value, not locked at the
   bill) for anyone mid-promo.
10. **Reports:** installations this month (by offer), installation income, promo
    cost (free months × price, as foregone revenue), customers on promo, promos
    ending soon, grants/waivers by user.
11. **Duplicate guard:** warn on matching phone/name when creating a customer;
    offers apply to new installations only.

### Phase 2 status (2026-10-23, branch `installation-phase-2`, not merged)

Built: items 1–11. Free months are stateless — `App\Support\Billing\FreeMonthPlan`
derives every month from approved `free_month_grants` (calendar windows per
service) + `customers.billing_starts_period`; nothing is counted down on the
manuscript, so reruns/publish/unpublish/forward recalculation stay consistent.
New installations are billed month by month (first bill = exactly one month);
customers from before phase 2 keep the original ledger. Deviations: a third
grant reason `opening_balance` and a `cancelled` status (refund forfeits); a
grant for only some services on a customer who still has paid months starts
after those paid months (so a paid month is never spent on a half-free month);
the duplicate guard warns (web + mobile require a tick) but the server never
blocks, for older app builds. Owner to set: Settings → Installation offers.

## Phase 3 — offline field installations (agents)

Agents (own zone) register a customer + take installation (and advance) money
offline: one sync item carrying a phone-generated customer id and its payments,
receipts from the device's receipt block, idempotent on sync. The customer lands
as **pending activation** (not billed, no reminders); the office confirms zone,
offer and connection date and activates, or rejects (payments voided, refund
voucher). Zones and offers cached on the phone; duplicate detection at sync.

## Out of scope / later

Relocation and re-installation fees (same charge model, kind `relocation`),
"pay 6 get 1" advance-payment promos, SMS/WhatsApp promo-ending notices.
