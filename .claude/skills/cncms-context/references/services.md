# Services — company service catalogue & per-customer subscriptions

Status: **BUILT & TESTED end to end 2026-09-06** — schema, models,
`CustomerSubscriptionService`, variant support, the `services.manage`
permission (sections 3–5), the request/controller wiring, the customer
add/edit form's tickable-services UI, the Show page's Services card, and
the Settings → Services catalogue screen with per-service "options"
(variants) sub-CRUD (sections 6–8). The legacy `bill`-only path
(`CustomerSubscriptionService::defaultSelection()`'s `$bill` parameter)
stays as permanent backward compatibility for `CustomerImportService` (xlsx
import still sends raw `bill`, by design — section 9), not as a
transitional stopgap.

**Deferred (not built, fast-follow candidates, no open design question):**
the API `CustomerResource` and `SyncService::upsertedCustomers()` don't
carry `services` yet — the web Inertia payload (`CustomerController::
shapeCustomer()`) does, which was the actual ask. Add these two only if a
concrete need for the mobile customer-detail screen or an external API
consumer to see per-service line items comes up.

Requested by the owner: "the company can offer different services… TV supply is just one,
internet, VOD, satellite hosting, etc. but the default would be TV
services, and in the customer add form we can make the services tickable —
by default TV would already be ticked. Each service carries its own rate
and the bill is the sum." Extended the same day once building started: a
service can itself have **priced sub-options** — the concrete case named
was TV channel broadcasts ("someone might want you to broadcast their
channel," at its own price) — resolved below in section 4 after a 4-lens
design pass (data model / architecture fit / admin UX / billing &
field-ops), converged without needing a full custom per-user matrix or any
new hierarchy concept beyond what's already here.

---

## 1. What this is

Today a customer has a single `customers.bill` (a flat monthly rate typed in
by hand). This feature makes that rate the **sum of the services (and, where
relevant, service sub-options) the customer subscribes to**, where each one
has its own price.

- A **service** is something the operator sells: *TV Service* (the default),
  *Internet*, *Video on Demand*, *Satellite Hosting*, … The set is
  company-specific and editable — every tenant manages its own catalogue.
- A service can optionally offer **variants** — priced sub-options under it
  (a specific channel broadcast under TV; a speed tier under Internet,
  later). See section 4.
- A **customer** subscribes to one or more services (and, for a service
  that has them, zero or more of its variants). Each subscription carries
  the price actually charged for it (defaults to the catalogue price, but
  can be overridden per customer — a negotiated rate, a promo, a
  grandfathered price).
- `customers.bill` is kept, but becomes **derived**: it is recomputed to
  `sum(customer_service.price)` every time a customer's subscription set or
  any of its prices change. Nothing downstream of `customers.bill` changes
  — the manuscript engine, bill printing, `CustomerResource`, arrears, all
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
| `name` | varchar(60) | "TV Service", "Internet" — unique per tenant, case-insensitive (`uq_services_name_ci` on `lower(name)`) |
| `slug` | varchar(60), unique | lowercased/kebab, stable id for the seed + the `is_default` lookup |
| `price` | decimal(12,2) | catalogue monthly price; the default charged when a customer ticks it (irrelevant for a subscription made via a *variant* — see section 4) |
| `is_default` | boolean, default false | exactly one row true (partial unique index `uq_services_single_default`), pre-ticked on the add form |
| `active` | boolean, default true | inactive = hidden from the add form, kept for history; existing subscriptions untouched |
| `description` | varchar(255), nullable | shown as helper text on the tick list |
| `sort_order` | smallint, default 0 | display order on the form / catalogue |
| timestamps | | |

`Auditable` trait (every mutation audit-logged, like `Customer`).

### `service_variants` (section 4)

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | |
| `uuid` | uuid v7, unique | |
| `service_id` | FK → services.id, cascade delete | the parent service this is a priced sub-option of |
| `name` | varchar(80) | "Local News Channel", "20 Mbps" — unique per (service, lower(name)) |
| `price` | decimal(12,2) | this variant's own catalogue price — **independent of the parent service's `price`**, see section 4 |
| `active` | boolean, default true | |
| `sort_order` | smallint, default 0 | |
| timestamps | | |

`Auditable`.

### `customer_service` (pivot)

| column | type | notes |
|---|---|---|
| `id` | bigserial PK | own key — the pivot is Auditable and route-bound in places |
| `customer_id` | FK → customers.id, cascade | |
| `service_id` | FK → services.id, restrict | can't delete a service that's in use — deactivate instead |
| `service_variant_id` | FK → service_variants.id, restrict, **nullable** | `NULL` = this row is the plain base service; set = this row is one specific variant of `service_id` (see section 4's invariant) |
| `price` | decimal(12,2) | the price actually charged this customer for this row; seeded from `services.price` (base row) or `service_variants.price` (variant row) at subscribe time, editable |
| timestamps | | `created_at` = subscribed-at |

Unique `NULLS NOT DISTINCT (customer_id, service_id, service_variant_id)` —
Postgres 15+'s `NULLS NOT DISTINCT` modifier (CNCMS runs PG18) makes two
`NULL`s collide for uniqueness purposes, so this gives, per customer: **at
most one base row per service** (both NULLs → collide → unique) **and at
most one row per distinct variant** (each variant id is its own value →
naturally unique). Without `NULLS NOT DISTINCT` a customer could hold the
same service's *base* subscription twice, since plain Postgres UNIQUE
treats every NULL as distinct from every other NULL.

### Seed migration `2026_09_06_000000_create_services_and_customer_service_tables`

Seeds four services so the feature is usable immediately (the owner named
these). Idempotent (`firstOrCreate`-equivalent by `slug`), runs for every
tenant on `tenants:migrate` and on provisioning — same pattern as
`DefaultRolesSeeder` / the seed-scheduled-task migration.

| slug | name | is_default | active | price (seed) |
|---|---|---|---|---|
| `tv` | TV Service | **true** | true | `0.00` — operator sets the real price in the catalogue screen |
| `internet` | Internet | false | true | `0.00` |
| `vod` | Video on Demand | false | true | `0.00` |
| `satellite-hosting` | Satellite Hosting | false | true | `0.00` |

Seed prices are `0.00` on purpose — a real price is operator-specific and
set in the catalogue UI. No variants are seeded — the operator adds channels
under TV themselves once the feature ships (there's no universal default
channel list to guess at).

### Backfill migration `2026_09_06_000100_backfill_customer_services_from_bill`

For **every existing customer** (including archived — `withTrashed()`):
insert one `customer_service` row → the `tv` (default) service, `NULL`
variant, with `price = customers.bill`. This preserves every current
customer's bill exactly (`SUM` of one row = that row). Idempotent: skip a
customer who already has any `customer_service` row. Runs once, after the
create+seed migration.

## 4. Service variants — priced sub-options under a service (e.g. TV channels)

### The problem this answers

"TV Service" at one flat price is the base cable package. But a customer
might separately want a **specific channel broadcast carried for them** —
naturally priced on its own, on top of the base package (the concrete case
the owner named). The same shape recurs anywhere a service naturally comes
in more than one priced flavor — Internet speed tiers is the obvious next
one, even though it isn't built today.

### Why this shape, and what else was considered

Four angles were weighed before landing here:

1. **Data model.** Three shapes were on the table: (a) let `services` be a
   self-referential tree (`services.parent_service_id`), so a "channel" is
   just another `services` row pointing at TV; (b) a `service_channels`
   table scoped narrowly to TV only; (c) a generic `service_variants` table,
   one level deep, attached to *any* service. (a) buys generality but opens
   a recursive-tree can of worms (depth limits, "is a variant allowed to
   have its own variants?", cascading catalogue-price edits down a tree)
   for a need that is, concretely, one level deep. (b) solves today's case
   but doesn't generalize to the obviously-analogous next one (Internet
   speed tiers) without inventing a second special table just like it.
   (c) — **chosen** — is exactly one level deep (a variant belongs to a
   service, full stop, no variant-of-a-variant), reuses the exact same
   "priced, tickable, subscribable" pattern already built for services, and
   costs one new table instead of a schema-wide generalization.
2. **Architecture fit.** Nothing about the already-built `services` /
   `customer_service` / `CustomerSubscriptionService` layer needed to
   change shape — a variant selection is still just a `customer_service`
   row, still summed the same way into `bill`. The only new invariant is
   "a variant's base service must also be selected" (section 4's rule
   below), enforced in the one place that already owns every pivot write
   (`CustomerSubscriptionService`), the same way this codebase enforces
   other cross-row invariants in a service class rather than a DB trigger
   (e.g. the arrears maker-checker rule).
3. **Admin UX.** A per-service "Channels" sub-screen was considered
   (TV-specific label, TV-specific route). Rejected in favor of a generic
   "Options" sub-list on every service's edit screen — no service is
   hardcoded to have variants; any service can grow them the same way TV
   does, through the same UI, with the operator supplying whatever labels
   make sense for their business ("channels" is just what they'll happen to
   name TV's variants).
4. **Billing & field-ops.** A variant row is priced and summed exactly like
   a base row, so no change to `manuscript:calculate`, arrears, or bill
   printing was needed. The one new rule that reaches those neighbors: the
   bulk zone bill-update tool (section 8) already only touches customers
   holding a single line item — a variant row is just one more kind of line
   item, so that rule needed no change, only a wording update (any pivot
   row counts, base or variant).

**Rejected alternative reading:** a "channel broadcast" could instead mean
a *content owner* paying the operator to carry their channel (carriage
revenue) — a B2B relationship with no `customers` row at all. That is a
materially different feature (a channel-partner ledger, not a customer
subscription) and was set aside as out of scope here; the interpretation
built is the customer-facing one, matching "tickable in the customer form."
If the owner meant the carriage-revenue reading, that's a separate future
feature request, not a variant of this one.

### The invariant

A `customer_service` row with a non-null `service_variant_id` requires the
customer to **also** hold a base row (`service_variant_id IS NULL`) for
that same `service_id`, in the *same* selection set —
`CustomerSubscriptionService::sync()` rejects a selection set that ticks a
variant without its parent service. (You can't have "the local news channel
add-on" without holding TV itself.) Unticking the base service in an edit
detaches every variant under it too — enforced the same way, not left to
the frontend to get right.

### Pricing

A variant's `price` is **independent of its parent service's `price`** —
subscribing to a variant adds its own price on top of the base service's
price; it does not replace it. `services.price` and `service_variants.price`
are two separate catalogue numbers, edited on two separate rows, each with
its own "apply new price to all current subscribers" action (section 6).

## 5. Model / service layer

- `App\Models\Service` — `HasUuid`, `Auditable`, `RouteKey('uuid')`.
  `customers()` belongsToMany (pivot exposes `id`, `uuid`, `price`,
  `service_variant_id`). `variants()` hasMany(ServiceVariant); `activeVariants()`
  scope-equivalent. Scopes `active()`, `ordered()`. Static `Service::default()`
  (memoised, the `is_default` row) + `forgetDefault()`.
- `App\Models\ServiceVariant` — `HasUuid`, `Auditable`, `RouteKey('uuid')`.
  `belongsTo(Service::class)`. Scopes `active()`, `ordered()`.
- `Customer::services()` — `belongsToMany(Service::class)->using(CustomerSubscription::class)->withPivot(['id','uuid','price','service_variant_id'])->withTimestamps()`.
  `Customer::subscriptions()` — `hasMany(CustomerSubscription::class)`, the
  write path.
- `App\Models\CustomerSubscription` (the `customer_service` pivot) — extends
  `Pivot` **and** is a first-class Auditable model with its own `id`/`uuid`,
  operated directly (create/update/delete) by
  `CustomerSubscriptionService` rather than through `belongsToMany`
  attach/detach/sync, precisely so every mutation fires the Auditable model
  events (a `sync()` call never does). `belongsTo(Service::class)` +
  `belongsTo(ServiceVariant::class)` (nullable).
- `App\DataTransferObjects\CustomerServiceSelection` — one ticked
  row on the form: `serviceUuid`, `?serviceVariantUuid`, `price`.
- `CustomerData` gains `?array $services` (list of `CustomerServiceSelection`).
  `null` = "don't touch subscriptions" (bulk bill update, status changes);
  an array = the full desired set (add/edit form).
- **`App\Services\CustomerSubscriptionService`** — the single writer:
  - `sync(Customer $customer, array $selections): void` — resolves every
    service/variant uuid up front (rejecting unknown ones and duplicates —
    a `(service, variant)` pair selected twice), enforces the section 4
    invariant, then diffs the pivot to match (attach new, update changed
    prices, detach removed), then `recomputeBill($customer)`. One
    transaction, keyed internally by `(service_id, variant_id ?? null)`
    rather than `service_id` alone, since a service can now be represented
    by more than one pivot row.
  - `recomputeBill(Customer $customer): void` — `customer->bill =
    customer->subscriptions->sum('price'); customer->save();`.
  - Guard: refuse an empty `$selections` (a customer must hold ≥1
    subscription row — a customer with none would have `bill = 0`, which
    the billing engine treats as "free", almost never intended).
  - `setSingleServicePrice()` — the bulk zone bill-update write path
    (section 8): the caller has already confirmed the customer holds
    exactly one `customer_service` row (base or variant, doesn't matter
    which) and this reprices that one row.
  - `applyCataloguePriceToSubscribers(Service $service)` — reprices every
    **base** row (`service_variant_id IS NULL`) for that service to its
    current catalogue price, and recomputes each affected bill.
  - `applyVariantPriceToSubscribers(ServiceVariant $variant)` — the variant
    equivalent, reprices every row pinned to that variant.
  - `defaultSelection(): CustomerServiceSelection` — the default service at
    its catalogue price, no variant. Used when a new-customer request omits
    `services` entirely.
- `CustomerService::create()` / `update()` call
  `CustomerSubscriptionService::sync()` when `CustomerData->services` is
  non-null, inside the same transaction that writes the customer.

## 6. Requests / API

- `StoreCustomerRequest` / `UpdateCustomerRequest`:
  - `bill` is **no longer accepted from the client** — remove the rule (or
    keep it `prohibited`). The server computes it. (Keep the column; just
    stop trusting client input for it.)
  - `services` — `required, array, min:1`
  - `services.*.service_uuid` — `required, uuid, exists:services,uuid` and
    must be `active` (custom rule) unless the customer already holds it
    (edit form keeping a now-inactive service)
  - `services.*.service_variant_uuid` — `nullable, uuid, exists:service_variants,uuid`,
    must belong to the `service_uuid` on the same row (custom rule), and
    must be `active` unless already held
  - `services.*.price` — `required, numeric, gte:0, max:999999999.99, decimal:0,2`
  - no duplicate `(service_uuid, service_variant_uuid)` pair (custom rule)
  - custom rule: every row with a non-null `service_variant_uuid` must have
    a sibling row in the same payload for the same `service_uuid` with a
    null `service_variant_uuid` (section 4's invariant, checked here too so
    the form gets a clean 422 instead of a service-layer exception)
- `resources/tsx/types` + `CustomerResource` (`app/Http/Resources`): add
  `services: [{ uuid, name, slug, price, variant: {uuid, name, price} | null }]`
  and keep `bill` (the sum) for back-compat with every existing consumer,
  web and mobile.
- Mobile: `SyncService::upsertedCustomers()` payload gains `services` (bare
  `[{slug, name, price, variant_name, variant_price}]`); the mobile customer
  detail screen shows them. Read-only on mobile for v1 (agents don't edit
  subscriptions in the field).

## 7. Catalogue management UI

New screen — **Settings → Services** (`/settings/services`), not a new
top-level nav item (it's operator config, like Company Info / Bill
Printing). List + create + edit + activate/deactivate. Delete only when
`customers()->count() === 0` (else 422 "N customers subscribe to this
service — deactivate it instead", mirroring
`CustomerService::delete()`'s history guard).

- Editing a service's catalogue `price` does **not** retro-change existing
  subscriptions (their pivot `price` is independent). Offer an explicit
  "apply new price to all N current subscribers" button that runs
  `CustomerSubscriptionService::applyCataloguePriceToSubscribers()`.
- `is_default` is a radio across the list (exactly one). Changing it only
  affects which service is pre-ticked on future add forms.
- **Options / variants** (section 4): every service's edit view has an
  "Options" sub-list — add/edit/deactivate/delete a variant under it,
  identical CRUD shape to the top-level catalogue (name, price, active,
  sort order), with its own "apply new price to all N current subscribers"
  action (`applyVariantPriceToSubscribers`). No service is special-cased in
  code to have this section — it's always present, just empty (and
  collapsible) for a service with none.

### RBAC

New permission `App\Auth\Permission::ServicesManage = 'services.manage'`
(Company area) gates the whole Settings → Services screen, its service CRUD,
**and** its variant CRUD — one permission, not one per sub-resource
(deliberately not splitting further: variants are a detail of managing a
service, not a separate concern with its own audience). Seeded to **admin**
only (super bypasses) via the same idempotent top-up tenant migration
pattern as `customers.export_record` / `payments.issue_receipt`. Add to
`DefaultRolesSeeder` for new tenants. `manager` is NOT granted it
(catalogue pricing is an admin decision) — confirm with owner if they want
manager too. Viewing the catalogue on the customer form needs no
permission (any user who can create a customer sees the tick list).

## 8. Customer add / edit form (the visible change)

`resources/tsx/pages/Customers/Create.tsx` + `Edit.tsx`:

- Replace the single **Monthly bill** number input with a **Services**
  block:
  - a checkbox row per `active` service (name + description), the
    `is_default` one checked on Create
  - when a service is ticked, reveal a price input next to it, prefilled
    with that service's catalogue `price`
  - **if the ticked service has any active variants**, reveal a nested,
    indented tick-list under it ("Options" — or whatever the operator named
    them) — each variant its own checkbox + price input, prefilled from the
    variant's catalogue price. Unticking the parent service clears and
    hides its variants.
  - a live **Total monthly bill: X FCFA** line = sum of every ticked
    price (services and variants alike), read-only, visually where the old
    `bill` field was
  - inline warning on a ticked service or variant whose price is `0.00`
- Edit: prefill from `customer.services` (ticked services + ticked variants
  + each row's `price`). An inactive service/variant the customer still
  holds shows ticked with a muted "(inactive)" tag and stays
  editable/removable.
- `Customers/Show.tsx`: a "Services" card — each service (and, indented,
  each of its ticked variants), its price, and the grand total; shown near
  the billing summary.

## 9. Interactions with existing bill-writing paths

| Path | Behaviour |
|---|---|
| `CustomerImportService` (xlsx) | `bill` column still imported. After creating the customer, attach one `tv`-service base subscription at `price = bill` (same as the backfill; no variants from import). Document in the import template notes. A future "services/variant columns in the xlsx" is out of scope. |
| `BulkUpdateCustomerBillRequest` / `CustomerService::adjustBillsForZone()` | Only acts on customers holding **exactly one** `customer_service` row (base or variant — either counts). Adjust that one row's price via `setSingleServicePrice()`, then recompute. Customers with 2+ rows are **skipped with a reason** ("has multiple services — adjust per service in the catalogue"). Update the preview/plan shape + the Zone bulk-bill modal copy. |
| `ArrearsAdjustmentService` | Untouched — it adjusts `total_arrears`/`credit`, never `bill`. |
| `manuscript:calculate` | Untouched — reads `customers.bill`. |
| `CustomerRecordExportService` | Add a `services` section (each service + price, each held variant indented under its parent with its own price + subscribed-at) to the gathered record. |

## 10. Tests

- `services` + `service_variants` + `customer_service` migrations apply;
  seed creates 4 services, one `is_default`, zero variants;
  `uq_services_single_default` holds; the `customer_service`
  `NULLS NOT DISTINCT` unique constraint rejects a second base row for the
  same (customer, service) but allows two different variants of it.
- Backfill: an existing customer with `bill = 7500` ends up with one base
  `tv` subscription (no variant) at `7500`, `bill` unchanged.
- `CustomerSubscriptionService::sync()` — attach/detach/reprice diff for
  both base and variant rows; empty selection rejected; a variant selected
  without its base service rejected; unticking a base service detaches its
  variants.
- Store customer with `[TV base 5000, TV variant "News" 2000, Internet
  3000]` → `bill = 10000`, three pivot rows. `bill` in the request is
  ignored/prohibited.
- Update: untick the "News" variant → `bill = 8000`, that pivot row gone;
  untick TV entirely → the variant row (if any survived) is gone too.
- Catalogue CRUD (services **and** variants) — `services.manage` gate
  (super+admin 200, manager 403); delete blocked while subscribers exist;
  "apply price to all" recomputes for both `applyCataloguePriceToSubscribers`
  and `applyVariantPriceToSubscribers`.
- `CustomerResource` + sync payload carry `services` (with nested variant)
  and the summed `bill`.
- Bulk zone bill update skips multi-row customers (whether the extra row is
  a second service or a variant) with the right reason; still updates a
  true single-row customer whether that row is a base or a variant.
- Regression: `ManuscriptCalculateTest`, `CustomerTest`, `CustomerImportTest`,
  `BulkUpdateBill*` all still green.

## 11. Out of scope for v1 (note, don't build)

- The "channel-owner carriage fee" reading of "broadcast their channel" —
  a B2B revenue relationship with a content provider, not a customer
  subscription. Separate future feature if actually wanted.
- Per-service/per-variant billing lines on the manuscript / bill slip (bill
  stays a single figure on the slip; services and variants are itemised
  only on the customer page and the record export).
- Variant-of-a-variant (a second nesting level). If a real need for this
  ever appears, revisit — don't add it speculatively.
- Service-level or variant-level proration when a customer adds/drops
  mid-month.
- Editing subscriptions (services or variants) from the mobile app.
- Service bundles / package pricing (a fixed-price group of services sold
  together at a discount off the sum).
- xlsx import of per-service or per-variant columns.
