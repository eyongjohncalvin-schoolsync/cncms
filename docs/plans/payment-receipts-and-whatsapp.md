# Payment receipts + WhatsApp send

**Owner ask (2026-09-02):** "after payment, receipt implementation… and the
receipt should also be such that we can send it via WhatsApp."

## Scope clarification

This is a **generated receipt the business issues to the customer** for a
recorded payment — NOT the existing `payment_verifications.receipt_photo_path`
(that's proof-of-payment evidence an agent uploads *during verification*, kept
as-is). New concept, new table.

Reuse the WhatsApp model already specced in
`.claude/skills/cncms-context/references/bill-notifications.md`:
- **Manual mode** (always free, no Twilio): a `wa.me/<phone>?text=<message>`
  deep link a staff member clicks. Cannot attach a file, so the message is
  text + a **signed public URL** to the receipt PDF.
- **Bulk mode** (Twilio, gated by `notification_settings` + the landlord
  `bulk_whatsapp_enabled` entitlement): can send the PDF as media. Optional —
  only wire it if manual mode is fully done and time allows.

Match the bill layout: company logo, MOMO numbers, contact — same header as
`resources/views/pdf/bill.blade.php` and the manuscript register.

## Data model (tenant schema)

```
payment_receipts
  id             bigserial pk
  uuid           uuid v7
  payment_id     fk payments, unique          -- one receipt per payment
  receipt_number varchar unique per tenant    -- e.g. RCP-2026-000123, sequential, gap-free-ish
  issued_at      timestamp
  issued_by      fk users null                -- who generated it (null = system, on auto-issue)
  amount         decimal(12,2)                -- snapshot at issue time
  pdf_path       varchar null                 -- cached render on the configured disk; regenerable
  pdf_disk       varchar null
  snapshot       jsonb                        -- customer name/phone/zone, period(s) covered, method, expiry — frozen at issue
  sent_log       jsonb default '[]'           -- [{channel:'whatsapp_manual', at, by, to}]
  created_at / updated_at
```

Receipt number: a per-tenant counter (`DB` sequence or a `receipt_counters`
row locked `for update` at issue) — must not collide under concurrent
verification. Snapshot everything shown on the receipt so a later customer
edit / manuscript recalc never changes an issued receipt.

## When a receipt is issued

Auto-issue when a payment reaches `verification_status = 'verified'`
(`PaymentVerificationService` — the same choke point that already triggers
manuscript-relevant side effects). Also a manual "Issue / re-issue receipt"
action on the payment detail page for `payments` recorded before this ships
and for corrections. Rejected payments never get a receipt; if a verified
payment is later rejected, mark the receipt `void` (don't delete — audit).

## Waves

### Wave 1 — model, generation, PDF  — ✅ BUILT (awaiting coordinator commit)

Delivered:
- `database/migrations/tenant/2026_09_04_000000_create_payment_receipts_table.php`
  — `payment_receipts` + `receipt_counters` (per-year, `FOR UPDATE`-locked
  allocator). Run on all tenant schemas.
- `database/migrations/tenant/2026_09_04_000100_grant_issue_receipt_permission.php`
  — idempotent top-up of `payments.issue_receipt` onto admin + manager.
- `app/Models/PaymentReceipt.php`, `app/Services/PaymentReceiptService.php`
  (`issueFor` / `void` / `voidForPayment` / `pdf`), `resources/views/pdf/receipt.blade.php`.
- `App\Auth\Permission::PaymentsIssueReceipt` (`payments.issue_receipt`) +
  `DefaultRolesSeeder` (manager set; admin gets it via `Permission::values()`).
- Auto-issue / void hook wired in `PaymentVerificationService::verify()`
  (approve branch issues, reject branch voids — both inside the status-write
  transaction).
- `app/Console/Commands/BackfillPaymentReceipts.php`
  (`cncms:backfill-payment-receipts {tenant?} --no-dry-run`; dry-run default).
- Tests: `tests/Feature/PaymentReceiptTest.php` (11),
  `tests/Feature/PaymentReceiptBackfillTest.php` (2). Regression:
  Web/Api `PaymentTest`, `Api/PaymentVerificationTest` all green.

Notes for Wave 2: `PaymentReceiptService::pdf()` returns a disk-relative
path on `config('filesystems.default')`; build the signed URL / download
route on top of it. The receipt is reachable as `$payment->receipt` once
that relation is added (Wave 2). Logo is fetched live at render, everything
else is frozen in `snapshot`. `sent_log` (jsonb `[]`) is ready for Wave 3
to append `{channel, at, by, to}`.

Original scope:
Owns: `database/migrations/tenant/*_create_payment_receipts_table.php`,
`app/Models/PaymentReceipt.php`, `app/Services/PaymentReceiptService.php`
(issue / reissue / void, receipt-number allocation, snapshot build),
`resources/views/pdf/receipt.blade.php`, hook into
`app/Services/PaymentVerificationService.php` (auto-issue on verify).
`app/Policies/PaymentReceiptPolicy.php` — **use RBAC v2 permissions**
(`payments.view` to see, a new `payments.issue_receipt` or reuse
`payments.verify` to issue — coordinator confirms against the catalog).
Backfill command `cncms:backfill-payment-receipts` (dry-run default) for
existing verified payments — idempotent, safe on `tenantswecom`.
Tests: `PaymentReceiptTest` (issue on verify, number uniqueness under a
simulated race, snapshot immutability, void on later rejection).

### Wave 2 — view / download (web + API + mobile) — ✅ BUILT (awaiting coordinator commit)

Delivered:
- `app/Models/Payment.php` — `receipt(): HasOne`.
- `app/Policies/PaymentReceiptPolicy.php` (registered explicitly in
  `AppServiceProvider`): `view` → `payments.view`, `issue` →
  `payments.issue_receipt`.
- `app/Http/Controllers/PaymentReceiptController.php` — `downloadPdf`
  (auth staff PDF stream), `issue` (manual issue/re-issue, refuses a
  non-verified payment), `sharedPdf` (the signed public link).
- `app/Support/PaymentReceiptLink.php` — `shared(PaymentReceipt)` builds the
  `URL::temporarySignedRoute` (7-day expiry). **Wave 3's WhatsApp message
  builder should call this** — do not re-implement.
- `app/Http/Controllers/PaymentController.php::show()` — `receipt` prop
  (number, issued_at, status, amount, `download_url`, `shared_url`) +
  `can_issue_receipt`; `index()`/`show()` list rows carry a trimmed
  `receipt` indicator (`uuid`, `receipt_number`, `status`); `customer_phone`
  added to `formatPayment`.
- `routes/web/payments.php` — `payment-receipts/{receipt}/pdf`,
  `payments/{payment}/receipt/issue`.
- `routes/web/payment-receipts-public.php` (new top-level require in
  `routes/web.php`, OUTSIDE `['auth','tenant.web']`) —
  `payment-receipts/{receiptUuid}/pdf/shared`, middleware
  `['signed','throttle:receipt-share']`.
- **Signed-public-route tenant resolution:** the route has no user, so
  ResolveTenantWeb never runs. The tenant key travels in the signed URL as
  `?tenant=<key>` (part of the signature — tamper breaks it), and
  `sharedPdf()` calls `tenancy()->initialize()` from it before any
  tenant-schema query. `{receiptUuid}` is a plain string param, NOT
  route-model binding (binding would query the central connection first).
  A voided receipt 404s regardless of a still-valid signature.
- `config/rate-limits.php` + `AppServiceProvider` — new `receipt-share`
  limiter (IP-keyed, 30/min default, env-tunable).
- API: `app/Http/Resources/PaymentReceiptResource.php` (uuid,
  receipt_number, status, issued_at, amount, `pdf_url` token endpoint,
  `shared_pdf_url`), `app/Http/Controllers/Api/PaymentReceiptController.php`
  (`show` by payment uuid → 404 when none; `downloadPdf` by receipt uuid),
  `routes/api/payment-receipts.php` (new require in `routes/api.php`).
- Web frontend: `resources/tsx/pages/Payments/Show.tsx` Receipt card
  (number / issued date / amount / status pill / Download PDF; Issue /
  Re-issue button when `can_issue_receipt` && verified && no live receipt;
  "no phone on file" note; **no WhatsApp button — Wave 3**);
  `resources/tsx/pages/Payments/Index.tsx` receipt column (check icon +
  number linking to the payment, struck-through when void);
  `resources/tsx/types/index.ts` (`PaymentReceipt`, `PaymentReceiptSummary`).
- Mobile: `mobile/src/api/paymentReceipts.ts` (`fetchPaymentReceipt`,
  `openReceiptPdf` → `Linking.openURL(shared_pdf_url)` — the app has no
  PDF/file-system libs), `mobile/app/receipt/[uuid].tsx` (read-only view by
  payment uuid, gated on `can('payments.view')`, "View PDF" action),
  registered in `mobile/app/_layout.tsx`; a "View receipt" link on
  `mobile/app/(tabs)/customers/[uuid].tsx`'s Last-payment card (shown once
  the payment has a `server_uuid` and is `verified`).
- Tests: `tests/Feature/Web/PaymentReceiptViewTest.php` (12),
  `tests/Feature/Api/PaymentReceiptTest.php` (5). Regression: Web/Api
  `PaymentTest` + Wave 1 `PaymentReceiptTest` all green (72).

Notes for Wave 3: the signed public PDF route + `PaymentReceiptLink::shared()`
already exist — Wave 3's `wa.me` message just embeds that URL alongside the
receipt number + amount. `sent_log` (jsonb `[]`) is still untouched and ready
to append `{channel, at, by, to}`. The web Show page has `receipt.shared_url`
already in its payload and a `customer_phone` field for the no-phone guard;
the "Send via WhatsApp" button is the only missing UI. Mobile can reuse
`buildWhatsAppBillLink` / `normalizeCameroonPhoneForWhatsapp`
(`mobile/src/utils/whatsapp.ts`).

Original scope:
Owns: `app/Http/Controllers/PaymentReceiptController.php` (show, downloadPdf),
`routes/web/payments.php` (+ receipt routes), `app/Http/Controllers/Api/`
receipt endpoint, `app/Http/Resources/PaymentReceiptResource.php`,
`resources/tsx/pages/Payments/Show.tsx` (receipt card: number, issued date,
Download PDF, Send via WhatsApp button — button disabled w/ tooltip if the
customer has no phone), `resources/tsx/pages/Payments/Index.tsx` (a receipt
column / icon), `mobile/src/api/` + a receipt view screen in
`mobile/app/`.
Signed URL: `Storage::temporaryUrl` or a signed route
(`payment-receipts/{uuid}/pdf?signature=…`, ~7-day expiry) for the
WhatsApp-shareable link — must work for an unauthenticated recipient.
Tests: `PaymentReceiptViewTest`, API test, `cd mobile && npm test`.

### Wave 3 — WhatsApp send
Owns: `app/Services/ReceiptWhatsAppService.php` (or extend
`BillNotificationService` — coordinator decides), the `wa.me` link builder
(phone normalisation to E.164 for Cameroon +237, pre-filled message with
receipt number + amount + signed PDF link), the `POST
payment-receipts/{uuid}/send-whatsapp` route that records to `sent_log` and
returns the `wa.me` URL for the client to open, `resources/tsx` wiring of the
Send button, and (optional, only if bulk mode infra is ready) the Twilio
media path behind the existing entitlement check.
Tests: `ReceiptWhatsAppTest` (link format, phone normalisation, sent_log
append, entitlement gate for the Twilio path, no-phone rejection).

## Non-goals
Editing an issued receipt. Email delivery (separate, later). SMS receipts.
A customer-facing receipt portal.
