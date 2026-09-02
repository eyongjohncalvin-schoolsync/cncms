# Company Settings — Logo, Head Office & Cameroon Business Registration

Status: **Implemented** | Owner ask: make the Company Settings feature robust enough to support
real official documents (bills, receipts, manuscripts) for a real Cameroonian cable operator —
a real logo upload, a proper head-office address, and legally-correct business registration
numbers, all actually rendered on the PDF outputs.

---

## 1. Why

`companies` was a single-row-per-tenant settings table (see `TenantDatabaseSeeder::seedCompany`)
that predated any real paperwork requirements: `logo` was a bare `string` column with no upload UI
(`Company.tsx` used to say outright "Logo upload isn't wired up yet"), there was no formal
head-office address distinct from the short `location` tag already on the bill's "From:" line, and
nothing modeled Cameroon's actual business-registration identity. This pass makes bills and
manuscripts (`resources/views/pdf/bill.blade.php` / `pdf/manuscript.blade.php`) look like real
official documents from a registered Cameroonian company.

## 2. Fields added

| Field | Type | Nullable | Why |
|---|---|---|---|
| `head_office` | `string(191)` | yes | Full formal postal address of the head office (see §3). |
| `rccm_number` | `string(40)` | yes | OHADA commercial registration number (see §4). |
| `niu` | `string(20)` | yes | DGI taxpayer identification number (see §4). |
| `logo` (media collection) | Spatie Media Library `singleFile()` collection | — | Replaces the old `logo` string column entirely (see §5). |

All three text fields are nullable — a tenant (especially a brand-new self-service signup, see
`self-service-onboarding.md`) may not have this information on hand yet, and existing tenants
shouldn't be forced to backfill it before they can save any other Company Info change.

Migrations (added in this order, tenant-scoped, run via `php artisan tenants:migrate --force`):
1. `2026_08_24_120000_add_head_office_and_registration_to_companies_table.php` — adds
   `head_office`, `rccm_number`, `niu`.
2. `2026_08_24_120010_drop_logo_from_companies_table.php` — drops the old unused `logo` string
   column (every existing row had it `NULL` anyway — `TenantDatabaseSeeder` never set it).
3. `2026_08_24_120020_create_media_table.php` — Spatie's `media` table (see §5).

The original `2026_08_19_090544_create_companies_table.php` migration was **not** edited — it had
already run against both live tenants (`swecom` and `multimedia-digital-cable-network`), following
this codebase's standing convention of always adding a new migration for schema changes.

## 3. `location` vs. `head_office` — why both exist

`location` already existed and is genuinely a different concept, not a near-duplicate:

- **`location`** — a short area/town tag (e.g. `"3/CORNERS"`, `"Downtown"`), `varchar(30)`,
  collected at self-service registration (`WorkspaceProvisioningService`) and shown compactly on
  the bill's `From:` line (`{{ $company?->name }} -- {{ $company?->location }}`). It reads like a
  neighbourhood label, not a postal address — several of SWECOM's own zone names
  (`THR01 (3/CORNERS)`) share the same short-tag style.
- **`head_office`** — the full, formal postal address of the head office (`varchar(191)`), meant
  for letterheads and official paperwork where a one-line locality tag reads as incomplete. Shown
  on the bill as its own `Head Office:` row and in the manuscript register's subtitle line.

Both are optional and independently editable in Settings → Company Info. `location` was
deliberately left alone (name, column, and existing usages) rather than renamed, to avoid
disturbing the self-service registration flow and the existing bill layout — `head_office` is
purely additive.

*Forward-looking note:* `branches-and-locations.md` (design doc, not yet implemented) plans to make
`companies` branch-scoped, with `zones.town` "absorbed" into a first-class `branch` concept. When
that lands, revisit whether `head_office` should live on `Company` (one head office per tenant) or
move to `branches` (a head office per branch) — for now, with one Company row per tenant, it stays
on `Company`.

## 4. Cameroon business-registration terminology (researched, not guessed)

Two distinct identifiers are standard on Cameroonian invoices/official documents — modeling only
one, or inventing generic "Registration Number" wording, would be locally incorrect:

- **RCCM** — *Registre du Commerce et du Crédit Mobilier*. The OHADA (regional business-law
  framework covering Cameroon and 16 other West/Central African states) commercial register — the
  legal "birth certificate" proving a business's registered legal existence. Issued by the local
  Greffe (registry court), not a tax authority.
  Typical format: `RC/[city code]/[year]/[entity type]/[sequential number]`, e.g.
  `RC/DLA/2019/PM/127651` (DLA = Douala, PM = *Personne Morale*/legal entity, PP = *Personne
  Physique*/individual, GIE = *Groupement d'Intérêt Économique*).
- **NIU** — *Numéro d'Identifiant Unique*. The taxpayer identification number issued by the DGI
  (*Direction Générale des Impôts*, Cameroon's tax authority) to every person/entity liable to pay
  tax. Required for tax filing, business bank accounts, government contracts, and customs
  clearance — the number that actually needs to appear on a tax-compliant invoice/receipt.
  Format: a category letter (`M` = enterprise/*morale*, `P` = individual/*physique*, `E`
  = establishment) followed by roughly 11-12 digits and a trailing check letter, e.g.
  `M012345678901A`. Real-world examples in the wild vary slightly in digit count, so `niu` is
  stored as free text (`varchar(20)`, no strict regex) rather than a hard-coded fixed-length
  pattern.

**Decision: model both**, as separate fields (`rccm_number`, `niu`) — they're issued by different
authorities for different purposes (commercial registration vs. tax identification) and both
commonly appear together on real Cameroonian business paperwork. A single generic "registration
number" field would have been factually wrong and hidden which number is which when someone is
reading a printed bill next to their own RCCM/NIU documents.

Sources consulted: [org-id.guide — CM-NIU](http://org-id.guide/list/CM-NIU),
[LeFisk — RCCM Cameroun guide](https://www.lefisk.cm/blog/rccm-cameroun-guide-en-ligne-prix-verification-ohada),
[LeFisk — NIU format guide](https://lefisk.cm/blog/numero-identifiant-fiscal-cameroun-niu-format-obtention-verification),
[OHADA — Nomenclature des Codes RCCM](https://e-rccm.ohada.org/pdf/Nomenclature-des-Codes-RCCM-OHADA.pdf).

Both fields are optional — plenty of small/informal operators (this software's actual target
market) may not have completed formal registration, and the app shouldn't block Company Info saves
or bill printing on it. When present, both are printed together in a small "RCCM: ... | NIU: ..."
line at the foot of the bill and in the manuscript header.

## 5. Logo — Spatie Media Library integration

`spatie/laravel-medialibrary` (`^11.23`) was added via Composer. `Company` implements `HasMedia`
(`Spatie\MediaLibrary\HasMedia`) and uses the `InteractsWithMedia` trait, registering a single
`'logo'` collection:

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('logo')
        ->singleFile()
        ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml']);
}
```

`singleFile()` means every new upload automatically replaces/deletes the previous logo — there is
never more than one media row per Company for this collection, so
`SettingsCompanyController::update()` can call `addMediaFromRequest('logo')->toMediaCollection('logo')`
on every save without manually clearing the old file first.

**Upload flow**: one combined PATCH, not a separate endpoint. `Settings/Company.tsx` still uses
Inertia's `<Form>` component with plain `name`-attributed fields; a `<input type="file" name="logo">`
was added alongside them. Inertia's `<Form>` builds its submit payload from the real DOM `<form>`
element's `FormData`, so a file input is picked up automatically and the request is sent as
`multipart/form-data` with no extra configuration — no separate upload endpoint was needed (unlike
`PaymentController::uploadReceipt()`'s dedicated route, which exists because a payment's receipt is
attached after the payment already exists, a different lifecycle than "one settings form, one
save").

`UpdateCompanyRequest::rules()` validates `logo` as `['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048']`
(2MB cap) — the field is optional on every save; omitting it just leaves the current logo alone.

**Where the logo is stored**: the package's default `disk_name` (`config('media-library.disk_name')`,
env `MEDIA_DISK`) is `'public'`, which was left at its default — this matches
`PaymentController::uploadReceipt()`'s existing convention of storing uploads on the `public` disk
(`storage/app/public`, served via the `storage:link` symlink to `public/storage`). No new disk
config was introduced.

**Where the logo is consumed**:
- **Settings UI** (`resources/tsx/pages/Settings/Company.tsx`) — `SettingsCompanyController::edit()`
  exposes `logo_url` (`$company->getFirstMediaUrl('logo') ?: null`) to the page, which renders a
  preview `<img>` above the file input when a logo already exists.
- **PDF outputs** (`resources/views/pdf/bill.blade.php`, `pdf/manuscript.blade.php`) — via
  `Company::logoDataUri()`, **not** `getFirstMediaUrl()`. dompdf (this app's PDF renderer,
  `barryvdh/laravel-dompdf`) cannot reliably fetch a `storage:`-served URL mid-render — there's no
  guaranteed network/auth context during a server-side render — so the logo is embedded directly as
  a base64 `data:` URI read straight off local disk:

  ```php
  public function logoDataUri(): ?string
  {
      $media = $this->getFirstMedia('logo');
      if (! $media instanceof Media || ! is_file($media->getPath())) {
          return null;
      }
      $contents = file_get_contents($media->getPath());
      return $contents === false ? null : 'data:'.$media->mime_type.';base64,'.base64_encode($contents);
  }
  ```

  Both PDF templates guard with `@if ($company?->logoDataUri())` — no logo uploaded means no
  letterhead image, not a broken `<img>` tag. `bill.blade.php` shows it above the "BILL:" title;
  `manuscript.blade.php` shows a smaller version above the register's title (this PDF can span
  hundreds of rows across multiple pages, so the letterhead is kept small and appears once, not
  per-page).

## 6. `media` table: tenant-scoped, not central

Spatie's package ships no `vendor:publish` "migrations" tag for the installed version, so the
`media` table migration was hand-copied from
`vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub` into
`database/migrations/tenant/2026_08_24_120020_create_media_table.php` — deliberately under
`database/migrations/tenant/`, run per-tenant-schema via `php artisan tenants:migrate`, **not**
`database/migrations/` (central/landlord).

**Why tenant, not central**: every current use of Media Library in this app is `Company::logo`,
and `Company` is itself a tenant-scoped, single-row-per-tenant settings table — each tenant's logo
belongs only to that tenant's schema, exactly like every other tenant-owned table here
(`customers`, `payments`, `companies` itself). `media`'s `model_type`/`model_id` polymorphic columns
don't make it inherently central — polymorphism is about *which model* a row points to, not *which
schema* it lives in. This codebase is schema-per-tenant (`PostgreSQLSchemaManager`, see
`config/tenancy.php`), so a `media` table living in the tenant schema keeps each tenant's uploaded
files (and the `media` rows describing them) fully isolated, consistent with how `search_path`
switching already isolates every other tenant table.

If a *central* model (e.g. something on the landlord's own `Tenant` model) ever needs file uploads,
that would need its own separate `media` table under `database/migrations/` — a single physical
`media` table can't safely serve both central and tenant models simultaneously without also adding
a tenant discriminator column, which the current schema-per-tenant design has no need for.

Applied via `php artisan tenants:migrate --force` against both existing tenants (`swecom` and
`multimedia-digital-cable-network`, the second tenant from earlier self-registration testing) —
verified via `Schema::hasTable('media')` inside `tenancy()->initialize()` for each.

## 6b. `tech_number` and the tabbed-form save bug (fixed 2026-08-31, commit `4dd19ca6`)

`companies.tech_number` (technical support phone, shown on bill slips as "Support: …") exists,
is fillable, and is `nullable` in `UpdateCompanyRequest`. But `Settings/Company.tsx` split the
form into five tabs and **conditionally rendered** each (`{activeSection === 'contact' && (…)}`).
React unmounts the hidden tabs, so Inertia's `<Form method="patch">` serialized only the
inputs currently on screen. Saving from the "Contact & Support" tab PATCHed just
`email`/`phone`/`tech_number` — and `UpdateCompanyRequest` marks `name`, `location`,
`reconnection_fine`, and `arrears_second_approval_threshold` (fields on *other* tabs) as
`required`, so the save 422'd with "name is required" pointing at a field the user couldn't see,
and nothing persisted.

**Fix**: all tab sections stay mounted; visibility toggles via a `hidden` class. The full
company payload always submits regardless of active tab — matching `Settings/Notifications.tsx`
and `Settings/BillPrinting.tsx`. No backend change. **Any future tabbed settings form must do
the same** — never conditionally render form sections that hold fields the submit needs.
Covered by `tests/Feature/Web/SettingsCompanyTest.php`.

## 7. Authorization

No new policy work was needed — `app/Policies/CompanyPolicy.php` already existed (`view()`: any
authenticated tenant user; `update()`: `super`/`admin` only, via `TenantContext::isAnyOf()`) and
gates the same single PATCH endpoint the logo now rides on. `agent`/`worker`/`manager` roles still
cannot save any Company Info field, logo included — covered by
`SettingsTest::test_manager_cannot_update_company_info`,
`test_agent_cannot_update_company_info`, `test_worker_cannot_update_company_info`.

## 8. Testing surface

- `tests/Feature/Web/SettingsTest.php` — `head_office`/`rccm_number`/`niu` save and persist
  (`test_admin_can_update_company_info`), logo upload actually persists to the media collection
  and is retrievable via `logo_url` on the edit page
  (`test_admin_can_upload_a_company_logo`), re-uploading replaces rather than duplicates
  (`test_uploading_a_new_logo_replaces_the_previous_one`), and the three non-admin/super roles
  are still denied (`test_manager_cannot_update_company_info`,
  `test_agent_cannot_update_company_info`, `test_worker_cannot_update_company_info`).
- `tests/Feature/Api/BillPrintTest.php::test_bill_pdf_renders_with_a_company_logo` and
  `tests/Feature/Api/ManuscriptExportTest.php::test_manuscript_export_pdf_renders_with_a_company_logo`
  — real end-to-end dompdf renders (not just view-compiles-without-error) with an actual uploaded
  logo present, exercising `Company::logoDataUri()` through the real letterhead markup in both
  templates.
- `Storage::fake('public')` is used in every logo-upload test to avoid writing real files under
  `storage/app/public` during the test run, matching the `Storage::fake('local')` pattern already
  used by `ZoneImportTest`/`CustomerImportTest`.
- `database/factories/CompanyFactory.php` had its `'logo' => null` line removed (the column no
  longer exists) — every existing test using `CompanyFactory` was re-verified passing after this.

## 9. Incidental finding — FIXED 2026-08-31

The web `ManuscriptController::export()` register PDF used to OOM at 128M (unlike the API
sibling it never called `ini_set('memory_limit', '1024M')`). Fixed as part of the register PDF
work this cycle — the web controller now raises memory + time before rendering, and also
supports `?orientation` and the Excel format. See `bill-printing.md` §3.
