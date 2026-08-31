# Bill Printing — slips, the register, the N-up grid, and async bulk generation

Status: **Implemented** (iterating on the compact N-up template) | Owner ask: print each
customer's monthly bill, print the full monthly register, and hand agents a stack of bills
grouped by zone — without a web request timing out on a thousands-customer tenant.

This is the print side of the monthly cycle (see `business-rules.md` and
`project-manuscript-monthly-cycle` memory): validate month N-1 payments → run
`manuscript:calculate` for month N → **print/generate bills for month N** → they reach
customers before month N begins.

---

## 1. The three surfaces

| Surface | Route | Renders | Who |
|---|---|---|---|
| **Single bill slip** | `GET /customers/{customer}/bill/print` (`CustomerController::printBill`) and `GET /api/v1/bills/{customer}/print` (`Api\BillController::print`) | one A4 slip, tenant's chosen template | super/admin/manager/agent (`CustomerPolicy::printBill`) |
| **Monthly register** | `GET /manuscripts/export` (`ManuscriptController::export`) — `?format=xlsx` for Excel, `?orientation=portrait\|landscape` for PDF | the whole billing register, one row per customer | super/admin/manager (`ManuscriptPolicy::export`) |
| **Bulk bills (async)** | `POST /manuscripts/bills/generate` (`BillBatchController::generate`) → background job → download artifacts | every active customer's slip, tiled N-up, ordered by zone | super/admin/manager (`ManuscriptPolicy::export`) |

Single-slip and register are synchronous. Bulk bills are queued (see §4) because rendering
hundreds of DomPDF slips in one request needs `memory_limit` at 1024M — fine for ~450 customers,
not for thousands.

## 2. Bill slip templates

`companies.bill_template` picks one of three, configured at **Settings → Bill Printing**
(`SettingsBillPrintingController`, `Settings/BillPrinting.tsx`; preview route
`GET /settings/bill-printing/preview/{template}` streams a one-off sample via
`ManuscriptService::sampleBillData()`):

- `resources/views/pdf/bills/classic.blade.php` — full A4, "Kumba Classic".
- `resources/views/pdf/bills/modern.blade.php` — full A4, "Kumba Modern".
- `resources/views/pdf/bills/compact.blade.php` — "Kumba Compact", receipt-style, TOTAL DUE
  reversed white-on-black (survives weak toner / photocopying). **This is the template the N-up
  grid always forces when `bills_per_page > 1`**, regardless of `bill_template`.

`resources/views/pdf/bills/show.blade.php` is the single-slip wrapper (picks the template,
passes `is_sample` for the watermark).

All slip data comes from `ManuscriptService::billData(Customer, ?period)` /
`billDataForCustomers(iterable, ?period)` / `sampleBillData()`, which all funnel through the
one private `buildBillData()` so the single-print flow and the bulk grid render identically
shaped data: `company, customer, manuscript, period, period_label, deadline, account_code,
bill_number, logo_data_uri`.

- **`account_code`** — `{ZONE-PREFIX}-{customer id, 4 digits}`, e.g. `THR01-0042`. A readable,
  dictatable invention (there is no `customers.code` column); display-only.
- **`bill_number`** — `{TENANT}-{YYYYMM}-{customer id, 6 digits}`, e.g. `SWECOM-202609-000042`.
  Fully derived, no sequence table.
- **`period_label`** — `Carbon::createFromFormat('!Y-m', $period)->format('F Y')`. The `!` is
  load-bearing — see §6.
- **Active-only**: `billData()` throws `ValidationException` for a non-active customer (a
  disconnected/suspended/passive customer is frozen with `total_bill = 0`; a slip for them is
  wrong). `billDataForCustomers()` trusts the caller to have filtered — that filter lives in
  `ManuscriptService::billRecipients()` (§4).

## 3. The monthly register PDF/Excel

`ManuscriptController::export()` (web + `Api\ManuscriptController::export()`):

- `?format=xlsx` → `App\Exports\ManuscriptRegisterExport` (Maatwebsite Excel).
- otherwise → `resources/views/pdf/manuscript.blade.php` via DomPDF, `ini_set('memory_limit','1024M')`.
- **`?orientation=portrait|landscape`**, default **portrait** (owner's call — portrait fits more
  rows per page); `landscape` is the wide alternative. Validated (`abort 422` on anything else).
  The Manuscripts index Export menu offers "Download PDF (Portrait)", "Download PDF (Landscape)",
  "Download Excel".
- Blade uses `table-layout: fixed` with per-`<th>` `width` percentages (DomPDF **silently
  ignores `<colgroup><col width>`** — an early version did that and every column came out
  equal). Per-orientation width sets + base font size.
- **Columns**: No, Name, Code, Zone, Bill, Arrears, Credit, Total Bill, **Paid** (a blank column
  the manager hand-writes the collected amount into after downloading), Status, Expiry.
- **Name / Zone** are truncated in PHP with `Str::limit()` — DomPDF does not clip overflowing
  cell text (`overflow:hidden` / `text-overflow` are no-ops on its table cells).
- **Status** is abbreviated for display only: `disconnected → disc`, `suspended → susp`.

## 4. Async bulk bill generation (`BillBatch`)

Owner's 2026-08-30 ask. Migration `2026_08_30_120000_create_bill_batches_tables.php` (tenant),
**already run** on `swecom` and `multimedia-digital-cable-network`.

**Tables**
- `bill_batches` — one row per generation run: `uuid, period, status, density, template,
  filters(json), total_bills, total_zones, generated_by (→ public.users, ON DELETE SET NULL),
  batch_id (Illuminate\Bus\Batch id), error_message, started_at, completed_at`.
  `status`: `queued → processing → (completed | partial | failed | cancelled)` — a plain
  `varchar(20)`, no DB enum.
- `bill_batch_files` — the downloadable artifacts: `kind` = `zone` (one per zone, `zone_id` set),
  `bulk` (the single all-zones PDF, `zone_id` null), or `zip` (convenience ZIP of the zone PDFs).
  `disk, path, bill_count, page_count, size_bytes`, denormalized `zone_name`.

**Flow** (`App\Services\BillBatchService`)
1. `dispatch(period, filters, userId)` — `ManuscriptService::billRecipients($filters)` returns
   the **active customers with a manuscript for that period, ordered by zone name then customer
   name** (case-insensitive, `!Y-m`-safe). Throws a friendly `ValidationException` if empty.
2. Snapshots `density`/`template` from `Company::cached()` (so a later Settings change never
   retroactively mislabels a finished artifact), groups recipients by zone.
3. Creates the `bill_batches` row (`queued`), then `Bus::batch([...])`:
   - one `GenerateZoneBillsJob` per zone → `bill-batches/{tenantId}/{uuid}/zone-{zoneId}.pdf`
   - one `GenerateBulkBillsJob` → `.../bulk.pdf` (a single DomPDF render of the whole
     zone-ordered list — **not** a merge; there is no PDF-merge library, only barryvdh/dompdf)
   - `->allowFailures()`, `->catch()` records the first error, `->finally()` calls `finalize()`.
   - Batch-callback closures are primitive-only and resolve `app(BillBatchService::class)` at
     run time. Tenancy re-inits on the worker via Stancl's `QueueTenancyBootstrapper` — no
     tenant id threaded through the jobs. Same pattern as `ManuscriptGenerationBatchService`.
4. Each job flips `queued → processing` (first wins), raises memory/time generously (that's the
   whole point of being off the web request).
5. `finalize()` (always runs) builds the ZIP, then sets the terminal status: `failed` (no bulk
   PDF, or zero zone PDFs), `partial` (bulk + ≥1 zone PDF but a job failed / fewer zone PDFs
   than `total_zones`), else `completed`. Jobs are idempotent on retry (`updateOrCreate` keyed
   on batch+kind+zone).

**Cancel / clear** (owner's follow-up — "I made an error, need to regenerate")
- `BillBatchService::cancel(BillBatch)` — cancels the Bus batch so pending jobs no-op (each
  guards on `$this->batch()?->cancelled()`), sets `cancelled`, discards partial artifacts.
  No-op once terminal.
- `BillBatchService::delete(BillBatch)` — deletes the whole per-batch storage directory + the
  row (`bill_batch_files` cascade). The "clear it and regenerate from scratch" path.
- **`job_batches` lives ONLY in the central schema** (`0001_01_01_..._create_jobs_table.php`),
  never a tenant schema. `Bus::findBatch()` reads it on the default (tenant-scoped) connection
  and errors. `cancelBusBatch()` pins the `cancelled_at` write to
  `DB::connection(config('tenancy.database.central_connection'))` — same hazard/fix as
  `App\Support\ResolvesCommandRunBatchProgress`.

**Routes** (`routes/web/bills.php`, inside the `['auth','tenant.web','throttle:web']` group,
each also `throttle:exports`)
- `POST manuscripts/bills/generate` → `generate`
- `GET manuscripts/bills/batches/{billBatch}/files/{billBatchFile}` → `download`
  (`Storage::disk->download`, verifies the file belongs to the batch)
- `POST manuscripts/bills/batches/{billBatch}/cancel` → `cancel`
- `DELETE manuscripts/bills/batches/{billBatch}` → `destroy`

**UI**
- `Manuscripts/RunReview.tsx` — the `published` card gets a "Generate bills for {period}" button.
- `Manuscripts/Index.tsx` — Export menu "Generate Bills (background)"; a "Generated Bills —
  {period}" panel (`billBatches` prop, newest 10 for the period, folded into
  `ManuscriptController::index()` — no separate list endpoint) with status badges, per-file
  download links, and **Cancel** (queued/processing) / **Clear** (terminal) buttons. Polls with
  `usePoll(4000, { only: ['billBatches'] })` while any batch is queued/processing.
- Copy tells the user generation runs in the background and the **queue worker must be running**
  (`QUEUE_CONNECTION=database` — `php artisan queue:work`).

The old synchronous `GET /manuscripts/bills` (`ManuscriptController::downloadBills`) that this
replaced has been **removed**. `ManuscriptService::billRecipients()` stayed (the async path uses
it).

## 5. The N-up grid (`_grid.blade.php`)

`resources/views/pdf/bills/_grid.blade.php` takes `$bills` (array of `buildBillData()` shapes),
`$density` (1/2/3/4 = `companies.bills_per_page`), `$template`, and tiles them:

- Chunked `collect($bills)->chunk($density)` — one chunk per physical sheet, `ceil(N/density)`
  pages. Ragged last chunk padded with null cells (ragged rows destabilize DomPDF table layout).
- Page breaks: `page-break-after: always` on a wrapping `<div>`, computed per-chunk in PHP (not
  a `:last-child` selector) so no trailing blank page. `page-break-inside: avoid` is NOT used
  (unreliable in DomPDF — silently drops explicit column widths).
- **Layout (current, after the owner rejected a horizontal-strips experiment)**: the original
  vertical grid — `density 2 → 1 col × 2 rows`, `3 → 1 col × 3 rows`, `4 → 2 col × 2 rows`,
  dashed cell borders as the cut lines.
- **Cell height is content-hugging (82mm), NOT an equal page slice.** The compact slip is
  ~78mm; dividing the page into equal halves/thirds left each slip stranded at the top of a much
  taller cell with a wide white band beneath ("the spacing between the bills is too wide"). The
  bottom of the sheet is now just blank and trimmed after cutting. `N × (82 + ~3.4mm chrome)`
  stays well under 297mm. DomPDF 3.x has **no `box-sizing`** — `height` on a `<td>` is the
  CONTENT box; padding + border add on top.
- **Still being tuned.** Below ~76mm the compact footer (RCCM/NIU, conditional) starts to clip.
  The compact template itself (`compact.blade.php`) is the lever for going tighter.
- `SettingsBillPrintingController::edit()` exposes `bills_per_page` (`Company::BILLS_PER_PAGE_OPTIONS`
  = 1/2/3/4).

## 6. The `!Y-m` gotcha (fixed 2026-08-31, commit `c5e762de`)

`Carbon::createFromFormat('Y-m', '2026-09')` keeps **today's day-of-month**. Run on Aug 31 it
builds `2026-09-31`, which normalizes to `2026-10-01` → a September bill labelled "October 2026".
The owner hit this generating bills on the 31st. A trailing `->startOfMonth()` does NOT save you —
the overflow already happened in the parse.

**Rule: every `YYYY-MM` period parse in this codebase uses `Carbon::createFromFormat('!Y-m', …)`**
— the `!` resets day/time to the epoch base (day 01, 00:00) before year/month are applied. Fixed
at all 7 sites: bill labels (`ManuscriptService`, `BillNotificationService`), report labels +
P&L month bounds (`ReportService`), Resources P&L bounds (`ResourcesDashboardService`),
collection-rate window (`ManuscriptRepository`), prepaid expiry label (`Manuscript::expiryLabel`),
and `ManuscriptReconcilePrepaidBaseline`. `Carbon::now()->format('Y-m')` and
`->addMonthNoOverflow()->format('Y-m')` are fine — the hazard is only `createFromFormat`.

## 7. Related bill rules

- **Active-only everywhere**: printed slips, WhatsApp "Send Bill" (`BillNotificationService::
  composeMessage()` returns null for non-active), and the bulk grid all filter to active
  customers. Owner decision, 2026-08.
- **Bill print deadline**: 5th of the month (`deadline` = `"05 {period_label}"`).
- WhatsApp / SMS / Email bill delivery: see `bill-notifications.md` (manual `wa.me` mode is
  live; bulk Twilio is design-only).
