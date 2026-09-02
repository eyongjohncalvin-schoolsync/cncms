# Services — company service catalogue & per-customer subscriptions

Status: **SPEC — not built.** Requested by the owner 2026-09-02: "the company
can offer different services… TV supply is just one, internet, VOD, satellite
hosting, etc. but the default would be TV services, and in the customer add
form we can make the services tickable — by default TV would already be
ticked. Each service carries its own rate and the bill is the sum."

---

## 1. What this is

Today a customer has a single `customers.bill` (a flat monthly rate typed in
by hand). This feature makes that rate the **sum of the services the customer
subscribes to**, where each service has its own price.

- A **service** is something the operator sells: *TV Service* (the default),
  *Internet*, *Video on Demand*, *Satellite Hosting*, … The set is
  company-specific and editable — every tenant manages its own catalogue.
- A **customer** subscribes to one or more services. Each subscription
  carries the price actually charged for it (defaults to the service's
  catalogue price, but can be overridden per customer — a negotiated rate,
  a promo, a grandfathered price).
- `customers.bill` is kept, but becomes **derived**: it is recomputed to
  `sum(customer_service.price)` every time a customer's service set or any
  of its prices change. Nothing downstream of `customers.bill` changes —
  the manuscript engine, bill printing, `CustomerResource`, arrears, all
  keep reading `customers.bill` exactly as before.

## 2. Why `customers.bill` stays (do not remove the column)

`manuscript:calculate` does `total_bill = customers.bill + total_arrears -
credit` (business-rules.md §2). Bill printing, `CustomerResource`, the
dashboard KPIs, `BulkUpdateCustomerBillRequest`, `CustomerImportService`,
`CustomerRecordExportService`, and the arrears engine all read
`customers.bill` directly. Making it a live `SUM()` everywhere is a large,
risky change for no functional gain. Instead: **`customers.bill` is a cached
projection**, always written in the same service-layer transaction that
writes the pivot. Treat any code path that writes `customers.bill` without
also reconciling the pivot as a bug.

## 3. Schema (tenant)

### `services`

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | |
| `uuid` | uuid v7, unique | external id (HasUuid) |
| `name` | varchar(60) | "TV Service", "Internet" — unique per tenant (case-insensitive) |
| `slug` | varchar(60), unique | lowercased/kebab, stable id for the seed + the `is_default` lookup |
| `price` | decimal(12,2) | catalogue monthly price; the default charged when a customer ticks it |
| `is_default` | boolean, default false | exactly one row true (partial unique index `uq_services_single_default`), pre-ticked on the add form |
| `active` | boolean, default true | inactive = hidden from the add form, kept for history; existing subscriptions untouched |
| `description` | varchar(255), nullable | shown as helper text on the tick list |
| `sort_order` | smallint, default 0 | display order on the form / catalogue |
| timestamps | | |

`Auditable` trait (every mutation audit-logged, like `Customer`).

### `customer_service` (pivot)

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | own key — the pivot is Auditable and route-bound in places |
| `customer_id` | FK → customers.id, cascade | |
| `service_id` | FK → services.id, restrict | can't delete a service that's in use — deactivate instead |
| `price` | decimal(12,2) | the price actually charged this customer for this service; seeded from `services.price` at subscribe time, editable |
| timestamps | | `created_at` = subscribed-at |

Unique `(customer_id, service_id)` — a customer holds a service once.

### Seed migration `2026_09_..._create_services_and_seed_defaults`

Seeds four services so the feature is usable immediately (the owner named
these). Idempotent (`firstOrCreate` by `slug`), runs for every tenant on
`tenants:migrate` and on provisioning — same pattern as
`DefaultRolesSeeder` / the seed-scheduled-task migration.

| slug | name | is_default | active | price (seed) |
|---|---|---|---|---|
| `tv` | TV Service | **true** | true | `0.00` — operator sets the real price in the catalogue screen |
| `internet` | Internet | false | true | `0.00` |
| `vod` | Video on Demand | false | true | `0.00` |
| `satellite-hosting` | Satellite Hosting | false | true | `0.00` |

Seed prices are `0.00` on purpose — a real price is operator-specific and
set in the catalogue UI. The add form warns if a ticked service has a
`0.00` price ("set a price for this service in Settings → Services").

### Backfill migration `2026_09_..._backfill_customer_services_from_bill`

For **every existing customer** (including archived — `withTrashed()`):
insert one `customer_service` row → the `tv` (default) service, with
`price = customers.bill`. This preserves every current customer's bill
exactly (`SUM` of one row = that row). Idempotent: skip a customer who
already has any `customer_service` row. Runs once, after the create+seed
migration.

## 4. Model / service layer

- `App\Models\Service` — `HasUuid`, `Auditable`, `RouteKey('uuid')`.
  `customers()` belongsToMany. Scopes `active()`, `ordered()`. Static
  `Service::default()` (memoised, the `is_default` row).
- `Customer::services()` — `belongsToMany(Service::class)->withPivot('price')->withTimestamps()`.
- `App\Data\CustomerServiceSelection` — a tiny DTO: `serviceId` (or uuid) +
  `price`.
- `CustomerData` gains `?array $services` (list of `CustomerServiceSelection`).
  `null` = "don't touch subscriptions" (bulk bill update, status changes);
  an array = the full desired set (add/edit form).
- **`App\Services\CustomerSubscriptionService`** — the single writer:
  - `sync(Customer $customer, array $selections): void` — diff the pivot to
    match `$selections` (attach new, update changed prices, detach removed),
    then `recomputeBill($customer)`. One DB transaction. Audit-logged.
  - `recomputeBill(Customer $customer): void` — `customer->bill =
    customer->services->sum('pivot.price'); customer->save();`. Also called
    by the backfill and by any future service-price change that cascades.
  - Guard: refuse an empty `$selections` (a customer must hold ≥1 service —
    a customer with no services would have `bill = 0`, which the billing
    engine treats as "free", almost never intended). Validation message:
    "Select at least one service."
- `CustomerService::create()` / `update()` call
  `CustomerSubscriptionService::sync()` when `CustomerData->services` is
  non-null, inside the same transaction that writes the customer. A
  brand-new customer with no `services` key in the request defaults to
  `[{ service: default, price: default.price }]`.

## 5. Requests / API

- `StoreCustomerRequest` / `UpdateCustomerRequest`:
  - `bill` is **no longer accepted from the client** — remove the rule (or
    keep it `prohibited`). The server computes it. (Keep the column; just
    stop trusting client input for it.)
  - `services` — `required, array, min:1`
  - `services.*.service_uuid` — `required, uuid, exists:services,uuid` and
    must be `active` (custom rule) unless the customer already holds it
    (edit form keeping an now-inactive service)
  - `services.*.price` — `required, numeric, gte:0, max:999999999.99, decimal:0,2`
  - no duplicate `service_uuid` (custom rule)
- `resources/tsx/types` + `CustomerResource` (`app/Http/Resources`): add
  `services: [{ uuid, name, slug, price }]` and keep `bill` (the sum) for
  back-compat with every existing consumer, web and mobile.
- Mobile: `SyncService::upsertedCustomers()` payload gains `services` (bare
  `[{slug, name, price}]`); the mobile customer detail screen shows them.
  Read-only on mobile for v1 (agents don't edit subscriptions in the
  field).

## 6. Catalogue management UI

New screen — **Settings → Services** (`/settings/services`), not a new
top-level nav item (it's operator config, like Company Info / Bill
Printing). List + create + edit + activate/deactivate. Delete only when
`customers()->count() === 0` (else 422 "N customers subscribe to this
service — deactivate it instead", mirroring
`CustomerService::delete()`'s history guard).

- Editing a service's catalogue `price` does **not** retro-change existing
  subscriptions (their pivot `price` is independent). Offer an explicit
  "apply new price to all N current subscribers" button that runs
  `customer_service.price = services.price` for that service + recomputes
  every affected customer's `bill` (queued if N is large — reuse the
  bulk-bill-update batching pattern).
- `is_default` is a radio across the list (exactly one). Changing it only
  affects which service is pre-ticked on future add forms.

### RBAC

New permission `App\Auth\Permission::ServicesManage = 'services.manage'`
(Company area). Seeded to **admin** only (super bypasses) via the same
idempotent top-up tenant migration pattern as `customers.export_record` /
`payments.issue_receipt`. Add to `DefaultRolesSeeder` for new tenants.
`manager` is NOT granted it (catalogue pricing is an admin decision) —
confirm with owner if they want manager too. Viewing the catalogue on the
customer form needs no permission (any user who can create a customer sees
the tick list).

## 7. Customer add / edit form (the visible change)

`resources/tsx/pages/Customers/Create.tsx` + `Edit.tsx`:

- Replace the single **Monthly bill** number input with a **Services**
  block:
  - a checkbox row per `active` service (name + description), the
    `is_default` one checked on Create
  - when a service is ticked, reveal a price input next to it, prefilled
    with that service's catalogue `price`
  - a live **Total monthly bill: X FCFA** line = sum of ticked prices,
    read-only, visually where the old `bill` field was
  - inline warning on a ticked service whose price is `0.00`
- Edit: prefill from `customer.services` (ticked + each pivot `price`). An
  inactive service the customer still holds shows ticked with a muted
  "(inactive)" tag and stays editable/removable.
- `Customers/Show.tsx`: a "Services" card — each service, its price, and
  the total; shown near the billing summary.

## 8. Interactions with existing bill-writing paths

| Path | Behaviour |
|---|---|
| `CustomerImportService` (xlsx) | `bill` column still imported. After creating the customer, attach one `tv`-service subscription at `price = bill` (same as the backfill). Document in the import template notes. A future "services columns in the xlsx" is out of scope. |
| `BulkUpdateCustomerBillRequest` / `CustomerService::adjustBillsForZone()` | Only acts on customers holding **exactly one** service: adjust that pivot's `price`, then recompute. Customers with 2+ services are **skipped with a reason** ("has multiple services — adjust per service in the catalogue"). Update the preview/plan shape + the Zone bulk-bill modal copy. |
| `ArrearsAdjustmentService` | Untouched — it adjusts `total_arrears`/`credit`, never `bill`. |
| `manuscript:calculate` | Untouched — reads `customers.bill`. |
| `CustomerRecordExportService` | Add a `services` section (each service + price + subscribed-at) to the gathered record. |

## 9. Tests

- `services` + `customer_service` migrations apply; seed creates 4 services,
  one `is_default`; `uq_services_single_default` holds.
- Backfill: an existing customer with `bill = 7500` ends up with one
  `tv` subscription at `7500` and `bill` unchanged.
- `CustomerSubscriptionService::sync()` — attach/detach/reprice diff;
  `bill` recomputed each time; empty selection rejected.
- Store customer with `[TV 5000, Internet 3000]` → `bill = 8000`, two pivot
  rows. `bill` in the request is ignored/prohibited.
- Update: untick Internet → `bill = 5000`, pivot row gone.
- Catalogue CRUD: `services.manage` gate (super+admin 200, manager 403);
  delete blocked while subscribers exist; "apply price to all" recomputes.
- `CustomerResource` + sync payload carry `services` and the summed `bill`.
- Bulk zone bill update skips multi-service customers with the right reason.
- Regression: `ManuscriptCalculateTest`, `CustomerTest`, `CustomerImportTest`,
  `BulkUpdateBill*` all still green.

## 10. Out of scope for v1 (note, don't build)

- Per-service billing lines on the manuscript / bill slip (bill stays a
  single figure on the slip; services are itemised only on the customer
  page and the record export).
- Service-level proration when a customer adds/drops mid-month.
- Editing subscriptions from the mobile app.
- Service bundles / package pricing.
- xlsx import of per-service columns.
