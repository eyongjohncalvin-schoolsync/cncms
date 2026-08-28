# CNCMS Business Rules & Billing Logic

---

## 1. Customer Lifecycle

```
Register -> Active -> [missed payments] -> Disconnected
                 -> [manual action]   -> Suspended
                 -> [voluntary stop]  -> Passive

Disconnected/Suspended -> [pay arrears + optional 2,000 FCFA fine + verified] -> Active
```

**Status rules:**
- `active` — receiving service, billed monthly
- `passive` — on file but not generating charges (dormant account)
- `disconnected` — service cut, arrears frozen, reconnection fine is optional (see section 6)
- `suspended` — temporary hold, arrears frozen (same as `disconnected`) —
  payments are also blocked while suspended, so leaving arrears accruing
  produced unpayable debt

**Who can change status:** admin, manager, super roles for suspend/reconnect.
**Disconnect** (2026-08 mobile field-ops widening) additionally admits an
`agent`, scoped to their own zone (`App\Policies\CustomerPolicy::disconnect()`)
— agents can cut a non-paying customer on the spot while walking their zone
and record it immediately from the mobile app, no office round-trip.
Reconnect stays office-only because it also moves money (arrears/fine
collection); suspend stays office-only because its reasons are
non-payment-unrelated and carry the prepaid-pause admin choice (see
`prepaid-pause-handling.md` section 5) — neither is a field-triggered
decision. Bulk disconnect (the `/disconnections` workboard) is unchanged:
office/manager-gated only, no mobile route to it.

**v2 addition:** Status changes are now audit-logged in `audit_logs` with full
before/after state capture.

---

## 2. Monthly Billing Cycle

The billing cycle is driven by the `manuscript:calculate` Artisan command.

### Trigger
Run manually or via scheduler at month-end (historically last week of each month).
Recorded in `command_runs` with `period = 'YYYY-MM'`.

### 2026-08-28 correction: what `period` actually means

**A run's `period` is the month it GOVERNS, not the month it EXECUTES in.**
Because the trigger fires near month-end and no new run happens again until
the following month-end, a run executed on (say) 2026-07-22 produces the
bill customers actually owe throughout **August**, not July — and stays the
operative "current" bill for the entire month until the next run (executed
near end-August) takes over and governs September.

This was previously a real, confirmed source of confusion (not just
appearance): `manuscript:calculate`/the scheduled task/the web trigger all
used to default an omitted period to `now()->format('Y-m')` — the month the
run executes in — mislabeling every run one month behind what it actually
governs. Fixed (owner-confirmed 2026-08-28: the underlying arrears/credit/
total_bill MATH was always correct — this was purely a labeling/timing bug,
not a calculation bug) by defaulting to `now()->addMonthNoOverflow()->
format('Y-m')` instead, everywhere a period is auto-computed for a NEW run
(`ManuscriptCalculate` command, `ManuscriptGenerationTaskType::run()`,
`ManuscriptController::calculate()`/`preRunReview()`/`preRunReviewFull()`,
and the Manuscripts/Index.tsx "Run Calculation" trigger's own default). Each
of those three controller actions' "period cannot be in the future" guard
was widened to match — `period > now()->addMonthNoOverflow()->format('Y-m')`
— since generating next month's bill in advance is now the NORMAL case, not
a mistake to reject.

**Everywhere ELSE `now()->format('Y-m')` appears — viewing/filtering
defaults (Manuscripts index, dashboards), `ManuscriptRunLockService`'s
"current period" lock rule, the arrears-adjustment forward-recalculation
sweep boundary, `StoreArrearsAdjustmentRequest`'s target-period validation —
was deliberately left UNCHANGED.** Once periods are labeled by the month
they govern, "the period governing right now" correctly equals today's
calendar month again — the fix at the generation source is what restores
that simple equivalence everywhere else, rather than needing a scattered
+1-month offset patched into every consumer.

**Real data as of this fix**: the real `swecom` tenant's most recent
manuscript run (`command_runs.id=14`) is labeled `period='2026-07'` (executed
2026-07-22) under the OLD, pre-fix labeling — meaning it is, in fact, the
bill currently governing August 2026, mislabeled as July. Whether to relabel
that existing row (and its 446 `manuscripts` rows, and any `payments.
processed_period` stamps referencing it) to `2026-08` to match the corrected
convention, versus leaving historical data as-is and only applying the fix
going forward, is a decision for the product owner — not made unilaterally
as part of this code fix.

### Calculation per customer

```
-- Step 1: Find unprocessed, verified payments
INCOME = SUM(payments.amount)
         WHERE customer_id = X
           AND processed_at IS NULL
           AND verification_status = 'verified'

-- Step 2: Determine bill due
BILL_DUE = customers.bill (monthly rate, DECIMAL 12,2)

-- Step 3: Check prepaid status
IF payment_expiration IS NOT NULL AND payment_expiration > TODAY
    THEN total_bill = 0, credit = credit, skip billing
    GOTO step 6

-- Step 4: Calculate arrears and credit
total_arrears = previous total_arrears + BILL_DUE - INCOME
                (clamped to 0 minimum; credit absorbs the rest)

credit = MAX(0, INCOME - BILL_DUE - previous_arrears)

-- Step 5: Calculate total bill
total_bill = customers.bill + total_arrears - credit
             (minimum 0; can't be negative)

-- Step 6: Write manuscript record
UPSERT manuscripts SET
    bill = BILL_DUE,
    total_arrears = total_arrears,
    credit = credit,
    total_bill = total_bill,
    payment_expiration = payment_expiration,
    period = 'YYYY-MM',
    updated_at = NOW()

-- Step 7: Mark payments as processed
UPDATE payments SET processed_at = NOW()
WHERE customer_id = X AND processed_at IS NULL
  AND verification_status = 'verified'

-- Step 8: Log the run
INSERT INTO command_runs (command, period, metadata)
VALUES ('manuscript:calculate', 'YYYY-MM', '{"rows": N, "skipped_rejected": M}')
```

### Key difference from v1: Payment verification gate

The manuscript calculation now **only processes verified payments**. Payments with
`verification_status = 'pending'` or `'rejected'` are excluded from income calculation.
This prevents unverified offline payments from inflating the income figure and
gives the admin a verification checkpoint before billing closes.

### Rejected payments handling

Payments marked as `rejected` are:
- Excluded from manuscript calculation
- Still visible in the payments list with a red "Rejected" badge
- Can be re-submitted by an agent with new evidence
- All rejection actions are audit-logged

---

## 3. Bill Print Logic

Generated at `GET /bills/print` (web) or `GET /api/v1/bills/print` (API).
Outputs one bill slip per active customer. Format:

```
BILL: [Month Year]
From: SWECOM PLC -- [location] -- Phone: [tech_number]
To: [customer name]
Zone: [zone name]
Tel: [customer phone]
Payment Deadline: 05 [Month Year]
Code: [customer UUID]                          <-- Changed from ID to UUID
Location: [customer location]
Warning: Pay before the due date's done, skip the 2000 FCFA reconnect fine!
Technical/Billing: [tech_number]
MOMO NUMBERS: [momo_number], [momo_name]

Current Account Details:
Net Monthly Bill: [bill] FCFA
Credit: [credit]
Arrears: [total_arrears] FCFA
Total: [total_bill] FCFA
```

Deadline is always the 5th of the current month. MOMO numbers and company details
come from the `companies` table. Customer code is now the UUID (first 8 chars
for brevity on printed slips), not the integer ID.

---

## 4. Manuscript Report

The monthly manuscript is a tabular register of all customers:

```
No | Name | Code | Phone | Zone | Level | Bill | Arrears | Credit | Expiry | Total Bill | Status | Paid
```

- `Code` = customer UUID (first 8 characters, for compact display)
- `Expiry` = payment_expiration (shown as 'MMM YY' or '-' if none)
- `Paid` column = blank (to be filled by hand during collection rounds)
- Disconnected customers show status as `discon...`
- Generated as a printable document (ZIP of JPEG pages + text files)
- 8 pages covering ~521 active manuscript entries

---

## 5. Payment Entry Rules

Payments can be entered by any user with `agent` role or above, via the web UI
or the mobile API.

### Auto-verify rule (2026-08 revision — role/scope-based, not channel-based)

`verification_status` is decided purely by whether the CREATING actor could
also verify this exact payment (mirrors `PaymentPolicy::verify()` exactly —
see `App\Services\PaymentService::create()`), regardless of whether it was
recorded via the web UI or synced from a mobile device, online or offline:

| Creating actor | Result |
|---|---|
| super / admin / manager | Auto-verified (`verification_status = 'verified'`) unconditionally |
| agent, customer in agent's own zone | Auto-verified — the agent already holds zone-fenced verify power for this customer today, whether the entry came in live or offline |
| agent, customer OUTSIDE agent's own zone | Pending — needs a review from someone actually authorized for that zone |
| worker (even with the `can_record_payments` flag) | Always pending — a worker never has verify power under any condition |

`recorded_offline`/`recorded_by_device` are still stored (set by
`App\Services\SyncService`'s mobile-sync path; the web UI never sends them)
purely for the Payments Index/Show pages' "Offline"/"Office" display badge
and audit trail — they no longer gate verification_status. Channel/location
is deliberately not a trust signal here; trust is a property of the role
(and, for `agent`, the zone match) doing the recording.

### Frequency selection
- `monthly` — default; one month at a time
- `months` — enter N; system calculates expiration as `TODAY + N months`
- `yearly` — equivalent to `months` with N=12 but tracked separately

### Amount validation
- Amount should be >= `customers.bill` for a full monthly payment
- Partial payments are allowed (recorded, but arrears will persist)
- Overpayments are allowed (become `credit` on next manuscript run)
- Credit field on payment records an optional extra prepaid credit amount
- All amounts stored as DECIMAL(12,2) — no floating-point rounding

### Verification flow (v2)

```
Agent records payment (web or mobile)
        |
        v
payment.verification_status = 'pending'
payment.recorded_offline = TRUE/FALSE
payment.recorded_by_device = 'device_fingerprint'
        |
        v
Agent optionally attaches receipt photo
        |
        v
payment_verifications row created:
    - receipt_photo_path = '/receipts/...'
    - momo_ref = NULL (or filled by agent)
    - status = 'pending'
        |
        v
Admin/Manager reviews (web dashboard or API)
        |
        +---> APPROVE:
        |       payment.verification_status = 'verified'
        |       payment_verifications.status = 'approved'
        |       payment_verifications.verified_by = admin_user_id
        |       payment_verifications.verified_at = NOW()
        |       audit_logs: action='update', old/new values captured
        |
        +---> REJECT:
                payment.verification_status = 'rejected'
                payment_verifications.status = 'rejected'
                payment_verifications.notes = 'reason for rejection'
                audit_logs: action='update', old/new values captured
                (Agent can re-submit with new evidence)
```

### What triggers a customer status change
- Disconnection: manual admin action (no automatic disconnect by arrears threshold currently)
- Reconnection: manual admin action after confirming payment + fine + verification

---

## 6. Reconnection Fine

**Amount:** 2,000 FCFA flat fee (admin-configurable — `companies.reconnection_fine`)
**Who pays:** Optionally, a customer being reconnected from either `disconnected` or `suspended`
**How recorded:** As a separate payment entry, only when the office chooses to include it
**Verification required:** Yes — when charged, the fine payment is auto-verified like any reconnection payment

**Admin-discretion opt-in, not automatic (2026-08 owner decision):** the fine
is NEVER charged automatically. It's an explicit "Include reconnection fine"
checkbox in the reconnect UI, unchecked by default — the office decides,
per reconnection, whether to include it. This applies uniformly to both
`disconnected` and `suspended` reconnections; there is no status-based
distinction on the fine anymore (an earlier iteration of this rule tied the
fine to `disconnected` only, treating it as punitive for non-payment
specifically — the owner overrode that: it is discretionary for every
reconnection, regardless of why the customer was disconnected or suspended).
`disconnected` and `suspended` still differ on the *freeze* mechanics
(section 1, `ManuscriptCalculator`) — payment_expiration carry-forward and
arrears-freezing behave identically for both — but not on whether a fine
applies. See `App\Services\CustomerStatusService::reconnectOne()`
(`$includeFine` parameter) and the `include_fine` request field on
`ReconnectCustomerRequest`/`BulkReconnectCustomersRequest`.

---

## 7. Multi-Month & Yearly Prepaid Logic

Example: Customer pays 6 months at 2,500 FCFA/month

```
Payment: amount=15000, frequency='months', months=6
expiration_date = created_at + 6 months  (e.g. 2026-06-28 -> 2026-12-28)
```

On each monthly `manuscript:calculate` run until expiration_date:
- `total_bill = 0`
- `payment_expiration` on manuscript = 2026-12-28
- Customer is shown as PAID in the manuscript

After expiration_date:
- Customer resumes normal monthly billing
- Previous arrears (if any) reactivate

### 2026-08-28 addendum — v2 baseline + planned move to draw-down credit

**Baseline repair (done).** The v1 register never persisted `payment_expiration`
on manuscripts, so `ManuscriptImportAugust` seeded every 2026-08 row NULL.
`manuscript:reconcile-prepaid-baseline` (one-off, safe to delete) backfilled
`payment_expiration` onto the 22 `tenantswecom` customers whose prepaid window
still covers 2026-09+, from each customer's latest verified future
`expiration_date`. Money columns unchanged; a synthetic `published`
`command_runs` row for 2026-08 (id 1438) now blocks an accidental
`manuscript:calculate 2026-08` from rebuilding the baseline off `customers.others`.
5 already-lapsed multi-month payments were left alone (their window is over —
the customer is billed normally from 2026-09) but still need `processed_period`
stamped by the payment reconciliation or 2026-09 re-reads their amount as income.

**Direction (owner-approved, NOT yet implemented).** New `months`/`yearly`
payments should stop setting `expiration_date` and route their full `amount`
through the normal income path, so the existing ledger draws them down as
`credit` one month at a time — no freeze branch, no date special-casing. A rate
change must not shorten already-purchased coverage.

**Blocker.** The cutover is held on a pre-existing ledger defect:
`total_bill = bill + total_arrears - credit` bills the full amount in the period
that exhausts a credit exactly (`net == 0`) — so an N-month payment yields only
N-1 free months. Documented in
`ManuscriptCalculateTest::test_credit_is_consumed_before_arrears` and
`::test_a_months_payment_with_no_expiration_date_draws_down_as_credit`. The
freeze model does not have this bug. Fixing it is a core `ManuscriptCalculator`
change that needs owner sign-off and a parallel-run verification before the
draw-down cutover ships. Until then, `months`/`yearly` keep the freeze branch.

---

## 8. `others` Field (Initial Balance Seeding)

When customers are imported via xlsx, the `others` column seeds a pre-existing balance.

**How it's used:**
- On first `manuscript:calculate` run for that customer, `others` is factored into
  `total_arrears` as a starting debt
- After first run, `others` is no longer referenced in calculations
- `others` can be positive (pre-existing debt) or 0 (clean start)

**Migration note:** During MariaDB -> PostgreSQL migration, `others` is converted from
FLOAT to DECIMAL(12,2). Values like 10000.0 become 10000.00 exactly.

---

## 9. Zone Assignment Rules

- Each customer belongs to exactly one zone (`zone_id` FK, NOT NULL)
- Each agent covers one zone (`zone_id` FK on agents table)
- Zone name must match exactly in upload files (case-sensitive lookup)
- Zones cannot be deleted if customers or agents are linked to them
- Zone changes are audit-logged

---

## 10. User Role Permissions

| Action | super | admin | manager | agent | worker |
|---|---|---|---|---|---|
| Record payment (web) | YES | YES | YES | YES | -- |
| Record payment (mobile) | YES | YES | YES | YES | -- |
| Verify payment | YES | YES | YES | -- | -- |
| Reject payment | YES | YES | YES | -- | -- |
| Add/edit customer | YES | YES | YES | -- | -- |
| Change customer status | YES | YES | YES | -- | -- |
| Run manuscript:calculate | YES | YES | -- | -- | -- |
| Manage users | YES | YES | -- | -- | -- |
| Manage agents | YES | YES | YES | -- | -- |
| View manuscripts | YES | YES | YES | YES | -- |
| Print bills | YES | YES | YES | YES | -- |
| Upload bulk files | YES | YES | -- | -- | -- |
| View audit logs | YES | YES | YES | -- | -- |
| Export data | YES | YES | YES | -- | -- |
| Manage tenants (landlord) | YES | -- | -- | -- | -- |
| Record expense | YES | YES | YES | YES | -- |
| Edit/delete expense | YES | YES | -- | -- | -- |
| View P&L dashboard | YES | YES | YES | -- | -- |
| Manage categories | YES | YES | -- | -- | -- |
| Set budgets | YES | YES | YES | -- | -- |

---

## 11. Offline Sync Rules

Field agents operate in areas with intermittent connectivity. The mobile app must
function fully offline and sync when connectivity is restored.

### What can be done offline
- Record payments (stored in local SQLite)
- Record expenditures (stored in local SQLite)
- View customer list (cached locally)
- View own payment history (cached locally)

### Sync protocol (see references/offline-sync-strategy.md for full spec)

```
Mobile app detects connectivity
    |
    v
1. Push local changes to server (sync_queue direction='up')
   - Server receives payload, assigns UUID v7, creates records
   - Server returns mapping: local_uuid -> server_uuid
   - Mobile replaces local_uuid with server_uuid
    |
    v
2. Pull server changes (sync_queue direction='down')
   - Server sends changes since last_sync_at
   - Mobile applies changes to local SQLite
    |
    v
3. Update sync timestamp
   - agent.last_sync_at = NOW()
   - device.last_sync_at = NOW()
```

### Conflict resolution
- If same entity was modified on both client and server: **server wins**
- New entities from both sides are merged (no conflict)
- Failed sync items are retried with exponential backoff (max 5 attempts)
- Permanently conflicted items are flagged for manual admin review

---

## 12. Financial Precision Rules

All monetary values in the v2 system use `DECIMAL(12,2)`:

| Field | v1 Type | v2 Type | Max Value |
|---|---|---|---|
| customers.bill | FLOAT | DECIMAL(12,2) | 99,999,999,999.99 FCFA |
| customers.others | FLOAT | DECIMAL(12,2) | same |
| payments.amount | FLOAT | DECIMAL(12,2) | same |
| payments.credit | FLOAT | DECIMAL(12,2) | same |
| manuscripts.bill | FLOAT | DECIMAL(12,2) | same |
| manuscripts.total_arrears | FLOAT | DECIMAL(12,2) | same |
| manuscripts.credit | FLOAT | DECIMAL(12,2) | same |
| manuscripts.total_bill | FLOAT | DECIMAL(12,2) | same |
| agents.salary | DECIMAL(8,2) | DECIMAL(12,2) | same |
| expenditures.amount | N/A | DECIMAL(12,2) | same |
| budgets.amount | N/A | DECIMAL(12,2) | same |

**Rule:** Never use FLOAT/REAL for any money column. Always DECIMAL(12,2) or higher.
DECIMAL(12,2) supports amounts up to ~100 billion FCFA with 2 decimal places — more
than sufficient for any LCO operation.

**Migration note:** When converting existing FLOAT values to DECIMAL, rounding may
occur. Run a comparison query before migration to identify any values that differ
after conversion:

```sql
-- Find payments where FLOAT and DECIMAL values differ
SELECT id, amount::FLOAT AS float_val, amount::DECIMAL(12,2) AS decimal_val
FROM payments
WHERE amount::FLOAT != amount::DECIMAL(12,2)::FLOAT;
```

---

## 13. Key Business Metrics to Monitor

These are standard LCO (Local Cable Operator) health indicators:

- **Collection rate** = verified payments received / total bills issued in period
- **Verification rate** = verified payments / total payments submitted
- **Offline sync health** = sync success rate, average sync lag, failed sync count
- **Churn rate** = new disconnections / total active customers
- **Reconnection rate** = reactivations / total disconnected customers
- **Arrears aging** = how many customers have arrears > 1 month, > 3 months, > 6 months
- **ARPU** (Average Revenue Per User) = total monthly collections / active customers
- **Zone performance** = collection rate per zone (identifies underperforming agents)
- **Monthly net** = total income - total expenditures (P&L, see Resources module)
- **Agent productivity** = payments recorded per agent per day, including offline
