# Customer record export

**Owner ask (2026-09-02):** "a downloadable record of a customer in the
entire system — we might need it for audit or verification."

One agent, one wave. Mostly additive.

## What it produces

A single downloadable file bundling **everything CNCMS holds about one
customer**, for an auditor or a dispute. Two formats from one button:

1. **PDF** — human-readable, company-headed (same header as the bill /
   manuscript / receipt), for printing / sharing.
2. **JSON or XLSX** — structured, for a spreadsheet or import into another
   tool. Coordinator picks JSON (simpler, complete) unless the owner has said
   XLSX; a multi-sheet XLSX (one sheet per section) is the nicer artifact if
   `maatwebsite/excel` or the existing xlsx import lib is already available —
   check `composer.json` first.

## Sections to gather

Pull by `customer_id`, newest-first within each section:

- **Profile** — every `customers` column incl. `others`, `level`, `status`,
  `bill`, zone (name + branch), created/updated, soft-delete state.
- **Payments** — all `payments` rows: amount, method, frequency, months,
  processed_at, expiration_date, credit, verification_status, plus each
  payment's `payment_verifications` (momo_ref, verified_by, verified_at) and
  its issued `payment_receipts` (number, issued_at) — once that table exists.
- **Manuscript history** — every `manuscripts` row (one per period):
  period, bill, total_arrears, credit, total_bill, payment_expiration, the
  `command_run` that produced it.
- **Arrears adjustments** — all `arrears_adjustments` for the customer:
  direction, amount, target period, reason, status, requested/approved by,
  timestamps.
- **Messages** — all `messages` rows (SMS/WhatsApp/bill notifications):
  content, type, status, sid, sent_at.
- **Complaints** — all `complaints`: category, description, status,
  escalation level, assignee, resolution, timeline.
- **Status / disconnection history** — if a history table exists; otherwise
  derive from `audit_logs`.
- **Audit trail** — every `audit_logs` row where `table_name = 'customers'`
  and `record_uuid = <customer uuid>`, PLUS related-row changes (payments,
  manuscripts for this customer) — old→new value diffs.

## Files (all new except the two noted)

- `app/Services/CustomerRecordExportService.php` — gathers all sections into
  a typed DTO / array. One place, heavily commented, so adding a future
  section is obvious.
- `app/Http/Controllers/CustomerRecordExportController.php` — `pdf()`,
  `data()` (json/xlsx). Signed / throttled (`throttle:exports`, same as
  `reports/export`).
- `resources/views/pdf/customer-record.blade.php`.
- `routes/web/customers.php` *(edit)* — add
  `customers/{customer}/record-export/{format}`.
- `resources/tsx/pages/Customers/Show.tsx` *(edit)* — an "Export full record"
  button (dropdown: PDF / data). Gate with a permission — **use RBAC v2**:
  `audit.view` OR a new `customers.export_record`; coordinator confirms
  against the catalog. Likely `super`/`admin` only.
- `app/Policies/CustomerPolicy.php` *(edit)* — `exportRecord()` method.
- Tests: `CustomerRecordExportTest` — every section present, respects
  soft-deleted customers, permission gate, throttle, a customer with zero
  payments/manuscripts doesn't error.

## Depends on

RBAC v2 wave 2 (for the permission gate) and ideally `payment_receipts`
(wave 1 of that plan) so the Payments section can include receipt numbers —
but it can ship without receipts and add that column later.

## Non-goals
Bulk export of many customers. Scheduled / emailed exports. A redaction
step (the auditor gets everything).
