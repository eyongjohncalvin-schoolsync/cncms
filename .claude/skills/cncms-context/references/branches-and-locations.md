# Multi-Branch / Multi-Location Support — Design Spec

Status: **Design, not yet implemented** | Owner ask: a single tenant/operator (e.g. SWECOM) may
run offices in more than one city (Kumba today, Buea as the working example), each with its own
zones/customers/staff, while still being managed from one login with a consolidated view.

---

## 1. Why

`zones.town` already exists and already defaults to `'KUMBA 3'` for every zone, but it has never
been used to actually differentiate anything — every zone in the live data has the same town. It
was an early, informal attempt at exactly this concept. As ShalomTech's tenants grow beyond a
single city, "branch" needs to become a real, first-class grouping — not a second unused column.

## 2. Core architectural decision: branches are NOT Stancl tenants

Stancl Tenancy resolves exactly one active tenant schema per request (`search_path` switching).
"One login, several branches, one consolidated view" is incompatible with modeling a branch as a
separate Stancl tenant — rendering a consolidated report would require juggling multiple
simultaneous schema contexts in a single request, which the framework doesn't support.

**A branch is an ordinary table inside the tenant's existing schema**, the same way `zones`
already is. The Stancl tenant boundary stays reserved for "a different *operator*/company with
its own login and billing relationship to ShalomTech." SWECOM operating in two cities is still
one operator, one schema, one set of central `users` rows.

This mirrors standard practice in every comparable multi-location SaaS product (POS, multi-branch
CRM, multi-branch banking software) — branch/location is always a foreign-keyed dimension inside
one company's data, never a tenancy boundary.

## 3. Data model

- New `branches` table inside each tenant schema: `id`, `uuid`, `name`. (Dual-key pattern, same
  as every other tenant table in this codebase — see `HasUuid`/`#[RouteKey('uuid')]`.)
- `zones.branch_id` FK, `restrictOnDelete()` (a branch with zones still assigned to it can't be
  deleted, mirroring `zones.zone_id`'s existing `restrictOnDelete()` on `customers`).
- **`zones.town` is effectively absorbed by branch** — do not keep both as parallel, potentially
  contradictory concepts. `town` can stay as a display-only free-text field on `zones` if useful
  (a branch could span multiple towns in theory), but the meaningful grouping/filtering dimension
  going forward is `branch_id`, not `town`.
- **Do not denormalize `branch_id` onto `customers`/`payments`/`manuscripts`.** At current scale
  (~549 customers, 29 zones for one full operator) deriving branch via the `zone_id` join is
  simple and fast enough. Revisit only if a tenant's per-branch customer count grows into the
  tens of thousands and the join becomes a measurable bottleneck — don't pre-optimize for that now.
- **`companies` becomes branch-scoped**: add a nullable `branch_id` FK (nullable specifically so
  the migration can backfill safely — see §6). MOMO collection is realistically local per office,
  so each branch plausibly has its own MOMO number/office contact/logo. `Company::cached()`'s
  cache key needs to become branch-aware once this lands (it's currently a single tenant-wide
  singleton lookup).

## 4. Access control (RBAC)

Decided: **same roles, branch-fenced — not a sixth role.** Reuses every existing Policy
(`CustomerPolicy`, `PaymentPolicy`, etc.) unchanged; only *which rows* a role's abilities apply to
changes.

- The tenant's actual top-level `super` (the real owner — e.g. Kelvin at SWECOM) keeps full
  cross-branch visibility by default — that's the person who needs the consolidated view.
- Other staff (`admin`/`manager`/`agent`) default to being scoped to **one branch**, enforced as a
  real server-side boundary, not a client-side filter that could be bypassed.
- Mechanism: a nullable `branch_id` on `tenant_users` (or `tenant_user_index`, whichever proves
  cleaner at implementation time). `null` = sees every branch (cross-branch). A specific
  `branch_id` = locked to just that one. The tenant's owner sets this when creating/editing a
  staff account — no request/approval flow needed, it's a direct admin decision.
- **Deliberately not supporting "sees branches A and C but not B"** (a many-to-many
  branch-access model) — the null-or-one-branch shape covers the stated need. Upgrading to
  multi-branch-subset access later means introducing a pivot table, not a column rename; this is
  an accepted, explicit simplicity tradeoff, not an oversight.
- `agent`-role users get branch-scoping for free: they're already zone-scoped via their own
  `Agent.zone_id` row (the same mechanism `CustomerEligibilityService`'s zone-scoping already
  uses for the disconnection-eligibility board), and since zones now belong to branches, an
  agent's visibility is transitively branch-scoped with zero extra schema/logic.
- **Enforcement mechanism**: prefer explicit `when()`-style filtering in each Repository (matches
  this codebase's existing convention everywhere — see `CustomerRepository::paginate()`), backed
  by a shared trait/concern so every repository applies it identically rather than each one
  hand-rolling it (hand-rolled per-repository scoping is the likeliest way to accidentally leak
  cross-branch data on a new query someone forgets to scope). A global Eloquent scope was
  considered and rejected for v1 — too "magic," and the cross-branch `super` bypass needed on
  every query would fight the global-scope model more than it helps.
- **UI implication**: a branch-fenced user should see NO branch switcher at all — they only ever
  have one branch, so showing them a selector is pure confusion. Only the cross-branch owner needs
  a "Branch: Buea ▾" control anywhere.

## 5. Financial / reporting

- `manuscript:calculate` stays **one uniform tenant-wide run** — no evidence multiple billing
  cycles per branch are needed; keep it simple.
- Resources P&L dashboard gains an **optional `branch_id` filter** (an additional `WHERE` via the
  zone join) — consolidated (all-branches) view stays the default, drilling into one branch is
  opt-in. This is a filter addition, not a restructure of the existing income/expense/margin
  aggregation.
- `command_runs` does not need a `branch_id` — the command itself isn't branch-scoped.

## 6. Migration & rollout

1. One migration: create `branches`, seed a single `"Main Branch"` row, add nullable
   `zones.branch_id`, backfill every existing zone to Main Branch in the same migration (safe at
   the current ~29-zone scale), then tighten to `NOT NULL` once backfilled.
2. Follow-up migration: same pattern for `companies.branch_id` (nullable, backfill the existing
   single company row to Main Branch).
3. All existing SWECOM staff accounts get `branch_id = null` on migration day (full cross-branch
   access, i.e. **current behavior is preserved exactly** — branch-fencing is opt-in per user
   going forward, nobody's access silently narrows on deploy day).
4. Apply via `php artisan tenants:migrate --force` against all existing tenants (there are
   currently two: `swecom` and a second tenant created during earlier self-registration testing).
   New tenants inherit the schema automatically via the standard `TenantCreated` pipeline — no
   special-casing needed for tenants provisioned after this ships.
5. **Test with `DatabaseTransactions` only.** Do not add tests that do real tenant schema
   `CREATE`/`DROP` cycles for this feature — this session had two severe Postgres deadlock
   incidents traced to exactly that pattern (see `tests/Feature/Web/LandlordTest.php`'s class doc
   comment for why *that* file is a deliberate, documented exception; branch-table tests have no
   such need and should stay in the normal transactional pattern like `DisconnectionsTest.php`).

## 7. Testing surface (this is a security boundary, not a purely additive feature)

Because §4 introduces a real access-control boundary, nearly every existing "manager sees all
customers/payments/etc." test needs a sibling "branch-fenced manager sees only their branch's
records" test across most modules (Customers, Payments, Manuscripts, Disconnections, Audit Log,
Resources). Budget for this explicitly when scoping implementation — it's a materially larger
test-writing task than the schema/RBAC code itself.

## 8. Open question for whoever implements this

Branch-first vs. zone-first creation: should creating a Zone require picking an *existing* Branch
(rejecting/disallowing zone creation until the branch exists), or should the Zone form allow
inline "create a new branch" from within it? **Recommendation: branch-first.** Keeps zone creation
simple and avoids accidentally spawning duplicate/typo'd branches from a form whose primary
purpose is zones, not branch management. Revisit only if real usage shows this is a friction
point.
