# English / French Language Support — Design Spec

Status: **Design, not yet implemented — build on explicit go-ahead only** | Owner ask: Cameroon is
officially bilingual (English + French); this was scoped out at the start of the build and needs
to be added across the tenant-facing app.

---

## 1. Why

CNCMS was built English-only. Cameroon's operators and their customers are realistically mixed
English/French speakers, and official documents (bills, receipts) are exactly the kind of paper a
bilingual small business needs to get right in either language.

## 2. Frontend mechanism

**`react-i18next`**, fed by an Inertia-shared `locale` string — not the full translation
dictionary. Translation copy is static content, better suited to a compiled/code-split JS bundle
than re-fetched-every-navigation Inertia props.

- `HandleInertiaRequests::share()` adds `'locale' => $locale` (a plain string, e.g. `'en'`/`'fr'`)
  alongside the existing `auth`/`flash` shared props.
- `resources/js/lang/{en,fr}/*.json`, statically imported into `i18next` at bootstrap in
  `resources/js/app.tsx` (alongside the existing `createInertiaApp` setup) — not fetched over
  HTTP per request.
- A root-level sync calls `i18next.changeLanguage(page.props.locale)` when the shared prop
  changes, so a language switch re-renders without a full page reload.
- Components use `useTranslation()` / `t('customers.index.title')`. Type the resource shape
  (`i18next.d.ts`) so a typo'd key fails at compile time — real value at ~33 pages of migration.
- Rejected: a homegrown context+lookup object. At this scale it reinvents pluralization,
  interpolation, and namespace loading `react-i18next` already solves for free.

## 3. Backend mechanism

Laravel's native `lang/{en,fr}/*.php` + `__()`/`trans()` — the framework default, and the only
mechanism that reaches FormRequest validation messages, flash messages, and the PDF Blade
templates uniformly. No per-FormRequest wiring needed: validation messages resolve through
`lang/{locale}/validation.php` keyed off whatever `App::getLocale()` is at validation time — the
only requirement is that locale resolution (see §4) runs in middleware **before** the FormRequest's
`rules()`/`messages()` execute (the same pipeline position `ResolveTenantWeb` already occupies).

Currently: **no custom validation messages exist anywhere in this codebase** — every FormRequest
falls through to Laravel's stock English defaults, meaning `lang/en`/`lang/fr` don't exist yet at
all. This is a real gap, not just a translation task — someone has to write the English resource
files too, not only the French ones.

## 4. Where locale lives, and resolution order

**`users.locale` (central, nullable) → `companies.default_locale` (tenant-scoped, new column) →
`config('app.locale')` fallback (`'en'`)**.

- `users.locale`: a personal, cross-device preference on the central identity record (consistent
  with how `is_landlord` already lives there) — nullable, so most staff simply inherit the tenant
  default.
- `companies.default_locale`: the tenant's own default, naturally paired with the fields
  `company-settings.md` already added this cycle. Putting it here (not a separate
  `tenant_settings` table) means it inherits branch-scoping for free the moment
  `branches-and-locations.md` ships — each branch's own `Company` row becomes independently
  editable, language default included, with zero rework.
- Reject URL locale-prefixing (`/en/...`) — this app's route structure has no locale segment
  anywhere and retrofitting one touches every route file for no real benefit here.
- A new `ResolveLocale` middleware, placed **after** `ResolveTenantWeb` (needs `TenantContext`/
  `companies` resolved for the tenant-default fallback), computes the locale once and calls
  `App::setLocale()`. `HandleInertiaRequests::share()` reads `app()->getLocale()` for the frontend
  prop — one resolution, two consumers.
- Guest/pre-auth routes (login/register — no `users` row to read yet): a session/cookie override,
  checked first, ahead of `users.locale`.

## 5. Customer-facing language — explicitly deferred

**No `customers.preferred_language` field in v1.** Reasoning: 78% of customers have no phone on
file, so bill delivery is largely a face-to-face, in-person handoff — the staff member already
knows (or can ask) what language to hand a customer's bill in. The real requirement this creates
is **per-print language choice**: the bill-print action needs to let staff pick the *document's*
language independent of their own UI language, even without a stored customer preference. Build
that (a locale parameter on the PDF-generating code path — `resources/views/pdf/bill.blade.php`/
`manuscript.blade.php` must accept an explicit `$locale` rather than relying on ambient
`App::getLocale()`, e.g. via `App::setLocale($target)`/restore, or Laravel 11+'s
`app()->runningWithLocale($locale, fn () => ...)`), not a new customer column.

Revisit `customers.preferred_language` only when (a) automated customer-facing messaging (the
dormant `messages`/SMS table, or the bill-notification feature discussed separately this session)
becomes bilingual-aware, or (b) a tenant reports enough mixed-language customers that per-print
manual toggling becomes real friction. Document as deferred, not foreclosed.

## 6. Self-service onboarding & landlord area

- Add a simple locale picker (English pre-selected, not blank) to the existing Step 2
  company-info form in the self-registration flow (see `self-service-onboarding.md`) — writes
  straight to the new tenant's `companies.default_locale`. Avoid `Accept-Language` sniffing
  (unreliable on shared/library computers, or when an agent signs up on an operator's behalf).
- The Landlord/platform-admin area stays **English-only, deliberately** — it's ShalomTech's own
  internal tool (`EnsureLandlord` gates on `users.is_landlord`, unrelated to any tenant's own
  staff or customers), not a surface any LCO tenant or their customers ever see.

## 7. Real content scope (grounded in the actual codebase, not estimated)

- **~400-600 distinct UI strings** across 33 page files in `resources/tsx/pages/` (14 module
  directories). `resources/tsx/components/ui/` primitives (Button, Modal, Table, etc.) carry
  almost no hardcoded copy themselves — they take children/props — so they are NOT a
  centralization point; the strings live in the pages and a handful of shared domain components
  (`StatusBadge.tsx`, `CustomerStatusActions.tsx`, `BulkStatusModal.tsx`).
- **Enum/status display labels need their own translation layer, separate from stored values.**
  `VerificationStatus` already does this correctly (`StatusBadge.tsx`'s `verificationLabelMap`).
  `CustomerStatus` does NOT — it renders the raw enum value directly. `status_reason` is worse:
  `Disconnections/Index.tsx` renders it via `.replace(/_/g, ' ')` + CSS `capitalize`, so
  `tv_problem` literally displays as "Tv Problem" today (English, not just untranslated — a real
  bug independent of French, worth a quick fix regardless of i18n timing). **Hard rule for
  whoever implements this**: translate DISPLAY labels only. Never translate a stored/compared
  value — `status`, `status_reason`, `verification_status`, `frequency`, `level`, `role`,
  `action` all get matched in `where()` clauses, Policy checks, and `in:` validation rules; only
  their rendered label changes per locale.
- **PDF documents** (`resources/views/pdf/bill.blade.php`, `manuscript.blade.php`): ~25-30
  distinct strings combined — labels (From/To/Zone/Tel/Payment Deadline/Code/Location), the
  reconnection-fine warning line, MOMO/account-details headers, and `manuscript.blade.php`'s
  13-column register table header row. Short, but official documents a customer physically
  holds — get the French exactly right, not machine-translated.
- **Backend flash messages**: ~30 distinct strings across ~9 controllers, all following one
  consistent `->with('success'|'error', '...')` pattern.
- **Recommended cuts for v1**: Landlord/Tenants area (3 pages, English-only per §6), Audit Log
  (low-frequency, admin/manager-only technical event review) — de-prioritize freely.

## 8. Rollout order

Phased, not big-bang — a 33-page + 2-PDF-template + ~30-flash-message surface is comparable in
scale to the Controller-Service-Repository-DTO layer shipped earlier this session, not a single
sitting's work.

1. **Infra**: `ResolveLocale` middleware, `react-i18next` wiring, shared chrome (nav, sidebar,
   auth pages, toasts/validation strings), language switcher. Everything else depends on this.
2. **PDF bill/manuscript templates** — the only artifact a real customer physically holds; highest
   stakes, do this first among content.
3. **Dashboard, Customers, Payments** — highest-traffic daily office-staff surfaces, including the
   bulk-record/bulk-verify UI added this session.
4. **Manuscripts, Disconnections, Agents (incl. smart zone change), Zones**.
5. **Deferred**: Audit Log, Settings, Landlord admin, Workspace, Resources — ship v1 without
   these; English fallback, no crash risk, translate progressively afterward.

**Sequencing note, still relevant whenever work starts**: PDF templates and
`Settings/Company.tsx`/`Company.php`/`SettingsCompanyController.php` were being actively rewritten
by the company-settings agent when this doc was written (that work is now finished and merged —
confirm current state before touching those files, rather than assuming this note is still live).

**If split across parallel agents**, divide by page-directory boundary (they never share files):
- Payments + Manuscripts + Disconnections
- Customers + Zones + Agents
- Dashboard + shared layout/nav/auth (do first — others depend on it)
- Settings + PDF templates + Landlord/Audit/Workspace/Resources (sequence last, solo on any
  still-contested files)

## 9. Testing implications

- Smoke-test every translated page under the French locale for render crashes / missing
  translation-key errors, not just visual QA.
- **PDF layout regression pass specifically for French** — French strings commonly run 15-20%
  longer than English equivalents and can overflow tight table/label columns in
  `bill.blade.php`/`manuscript.blade.php`. Check explicitly, don't assume it just fits.
- Confirm enum/status values stay keyed on stable English identifiers, never translated display
  strings — this also matters for the Audit Log's name-based search (fixed this session), which
  must keep matching on stored values regardless of UI language.
- Verify FCFA currency and date formatting stay locale-correct independent of which language the
  UI is rendering in.
- Confirm locale preference is scoped per user/session and never leaks across tenants in this
  multi-tenant (schema-per-tenant) app.
