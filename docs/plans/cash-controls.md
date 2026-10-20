# Cash controls — theft-proof receipts, daily remittance, cash book, vouchers

**Owner ask (2026-10-20):** stop forged receipts and undocumented money.
The system — not the agent — creates every receipt number so it can always
be authenticated; agents will carry Bluetooth thermal printers; a daily
income form every collector fills; every franc out goes through a numbered
voucher ("bon") saying who signed, who received, and why. MTN MoMo and
Orange Money are both accepted. Cashier is a role. Voucher limits are
configurable per company: above the single-voucher limit **or** above the
daily payout limit, **every investor must approve** (no investors → the
owner/super). Customer confirmations go by **email** for now (SMS/WhatsApp
later).

Branch `cash-controls`, one commit per wave, nothing pushed until the owner
says. Tests run only on `cncms_testing`, one `phpunit` process at a time.

## Principles

1. **The system issues every receipt number.** Numbers are handed out in
   blocks to a named person; each number ends `used`, `cancelled` (with a
   reason) or it is *unaccounted* — an alert, never silence.
2. **Every handover is a numbered document confirmed by both sides**
   (agent → cashier, cashier → bank/safe, cashier → payee). Maker ≠ checker.
3. **Append-only money records.** Corrections are reversal rows, never
   edits or deletes.
4. **The customer is an auditor** — every receipt carries a security code +
   QR they can check, and an email the moment money is recorded.
5. **Exceptions surface themselves** (alerts page + daily email digest).
6. **Keep enforcement simple** (owner's standing rule): a handful of catalog
   permissions, one per-company limits row, investor = the existing
   `tenant_users.is_investor` flag. No override cascades.

## Permissions (added to `App\Auth\Permission`)

| Permission | Meaning | Seeded to |
|---|---|---|
| `receipts.manage_stock` | allocate/print receipt books, cancel leaves, see stock & accountability | admin, manager |
| `cash.view` | cash book, every remittance, alerts | admin, manager, cashier |
| `cash.remit` | submit own daily remittance | admin, manager, agent, cashier |
| `cash.receive` | receive remittances, deposits/transfers, close the till's day | admin, cashier |
| `vouchers.request` | request a voucher / cash advance | admin, manager, agent, cashier |
| `vouchers.approve` | approve vouchers within the limits | admin, manager |
| `vouchers.pay` | disburse an approved voucher | admin, cashier |

New system role **`cashier`** (seeded via `DefaultRolesSeeder`, created on
existing tenants by migration): customers.view, payments.view,
payments.create, payments.issue_receipt, cash.view, cash.remit,
cash.receive, vouchers.request, vouchers.pay, expenditures.view.

Investors approve above-limit vouchers through `is_investor` (no
permission) — same additive pattern as `ReportPolicy::view()`.

## Data model (tenant schema)

```
document_counters   series (RB|RM|BC|DP|TR), year, next_number   -- row-locked, like receipt_counters
payments            + method (cash|mtn_momo|orange_money|bank) default cash, + method_reference (txn id)
payment_receipts    + security_code, + receipt_leaf_id, + field_device_id, + source (office|digital|book)
companies           + voucher_approval_limit, voucher_daily_limit (null = no limit),
                      remittance_deadline_hours (default 24), advance_settlement_days (default 7)
field_devices       user_id, name, secret (encrypted), registered_at, last_seen_at, revoked_at
receipt_blocks      number RB-, kind (digital|book), holder_user_id, field_device_id?, first/last seq,
                      status (open|closed|returned), allocated_by
receipt_leaves      receipt_block_id, number (RCP-YYYY-NNNNNN), status (unused|used|cancelled),
                      payment_receipt_id?, cancelled_reason, cancelled_by, scanned_unused_at
cash_accounts       kind (till|safe|bank|mobile_money), name, provider (mtn|orange)?, account_number?,
                      holder_user_id? (till), is_active
cash_movements      cash_account_id, direction (in|out), amount, kind, source_type/id, reference,
                      business_date, recorded_by, reversal_of_id     -- immutable
remittances         number RM-, collector_user_id, business_date, status (submitted|received|disputed),
                      expected_{cash,mtn,orange}, declared_cash, denominations jsonb, received_cash,
                      variance, received_by/at, till_account_id, notes
remittance_lines    remittance_id, payment_id UNIQUE, method, amount, reference, wallet_confirmed
cash_day_closes     cash_account_id, business_date UNIQUE per account, expected, counted, denominations,
                      variance, note, closed_by/at, reopened_by/at, reopen_reason
vouchers            number BC-, kind (expense|advance), payee_name, purpose, expense_category_id,
                      amount, needed_on, status (pending|approved|rejected|cancelled|paid|settled),
                      approval_tier (standard|stakeholders), required_approver_ids jsonb,
                      requested_by, paid_by/at, paid_from_account_id, method, reference,
                      received_by_name, signed_copy_path, spent_amount, returned_amount,
                      settled_by/at, expenditure_id, rejection_reason
voucher_approvals   voucher_id, user_id, decision (approved|rejected), note, decided_at
```

## Key rules

- **Receipt numbers**: one series `RCP-YYYY-NNNNNN` (the existing counter).
  Office receipts take one number at issue (today's behaviour); digital
  blocks reserve a contiguous range for one agent + device; books reserve
  a range printed as numbered leaves with a QR each.
- **Security code**: `XXXX-XXXX`, Crockford base32 of
  HMAC-SHA256(key, `number|customer_uuid|amount|issued_at_unix`). Office
  receipts: server key (derived from APP_KEY + tenant). Digital field
  receipts: the device's secret, so a phone can sign while offline. Book
  leaves have no code (amount is handwritten) — verification shows what was
  recorded so the customer can compare.
- **Public verify**: `/r/{tenant}/{number}?c={code}` → Genuine · Amount
  mismatch · Not yet recorded (alerts the office, stamps
  `scanned_unused_at`) · Cancelled/VOID · Unknown. Throttled. The old
  signed verify links keep working.
- **Field receipt = issued at collection**, not at verification: the receipt
  row is created when the payment arrives (sync or online), even while the
  payment is still pending. A later rejection voids it as today.
- **Email confirmation**: every newly issued receipt is emailed to a
  customer with an address when email is enabled (existing
  `SendReceiptEmailJob`), regardless of preferred channel.
- **Remittance**: expected = the collector's not-yet-remitted, not-rejected
  payments up to the business date, split by method. Cashier counts cash,
  ticks each MoMo/Orange line seen in the company wallet; receipt writes
  movements (cash → till, MoMo → wallet accounts). Collector ≠ receiver
  except super. Negative variance = shortage owed by the collector.
- **Day close**: per till per date; expected from movements, counted with
  denominations; afterwards movements on that till/date are refused until a
  supervisor reopens with a reason.
- **Voucher tier** (computed at request, re-checked at each decision):
  `stakeholders` if amount > single limit OR (approved+paid total for
  `needed_on` + amount) > daily limit; else `standard`. Standard = one
  `vouchers.approve` holder ≠ requester. Stakeholders = every active
  investor (snapshot ∩ current; requester excluded); none → super. Any
  rejection rejects. Pay = `vouchers.pay` holder ≠ requester (super
  excepted); paying an expense creates the Expenditure; an advance is
  settled later with spent + returned amounts (Expenditure created then).

## Waves

1. **Foundations** — permissions, cashier role + grants, payment method &
   reference (web/API/sync; the mobile form is wave 3), company cash limits + Settings → Cash
   controls, document counters.
2. **Receipt stock** — devices, blocks/leaves, field receipt issuance
   (API/sync), receipt books PDF + assignment, receipt redesign (security
   code, amount in words FR/EN, short verify URL/QR), public verify page,
   unreported-scan alerts, email at collection, stock/accountability page.
3. **Mobile** — device registration, block top-up, offline numbering +
   HMAC signing, on-screen receipt, method fields, cancel a number.
   (Bluetooth printing later: ESC/POS printers render the QR natively.)
4. **Cash book** — accounts, movements, daily remittance (web + mobile),
   cashier receive with wallet confirmation, deposits/transfers, day close.
5. **Vouchers** — request/approve/pay/settle, limits & stakeholder tier,
   investor approval page + emails, printable bon PDF, Expenditure link.
6. **Alerts** — alerts page, daily email digest, dashboard counts.

## Status

| Wave | Status |
|---|---|
| 1 Foundations | ✅ done |
| 2 Receipt stock | ✅ done |
| 3 Mobile | ✅ done (printing via Bluetooth later) |
| 4 Cash book | ✅ done |
| 5 Vouchers | ✅ done |
| 6 Alerts | in progress |
