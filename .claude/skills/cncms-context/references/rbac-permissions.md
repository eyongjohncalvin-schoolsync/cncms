# Fine-Grained Payment Permissions — Design & Implementation

Status: **Built and shipped** (2026-08-25) | Owner ask: give two specific classes of user
slightly more/different payment ability than their fixed role normally grants — a "Secretary"
(worker tier) recording walk-in payments, and field agents verifying payments for customers in
their own zone — without turning the 5-role system into a general permission-grant matrix.

---

## 1. Why this shape, not a permission matrix

A full custom permission matrix (named capabilities, per-user grant rows, scope qualifiers,
an admin UI for hand-tuning any user's access) was designed in detail across four expert
deliberations (data model, migration strategy, admin UX, security review) before being
deliberately **shelved** in favor of this narrower design. The decisive finding, from the
security review: both real triggering cases are actually just "the existing branch/zone-fence
pattern, applied to one more action" — solvable by extending `TenantContext` the same direct
way it already carries `branchId`, not by building a general-purpose grant system.

The owner was explicit throughout: enforcement must stay simple and directly checkable, not a
cascading override system. Concrete example given: *"an agent can login and have access only to
customers in the zone assigned... simple."* That's the bar this design meets — one boolean flag
for one case, one new `TenantContext` field + a zone comparison for the other. **If a future need
for genuinely arbitrary per-user custom grants ever arises, the shelved full-matrix design
(52 named permissions, ceiling table, delta-grant table) is documented in this session's history
and can be revisited — it was not wasted work, just more than these two cases needed.**

The full-matrix design also had real, concrete bugs the security review caught before anything
shipped — worth remembering as a general caution for any future access-control work on this app:
bulk endpoints (`bulkVerify`, `bulkDisconnect`, etc.) take no target model, so a scope check added
only to a single-item Policy method silently doesn't apply to its bulk counterpart unless the
bulk service loop is updated too; a "ceiling" concept doesn't stop self-escalation without an
explicit server-side write-path guard, never just client-side UI restriction; a nullable scope
column on a scope-requiring permission must fail closed, not inherit this codebase's existing
"null branch = unrestricted" convention by accident.

## 2. Case 1 — Secretary / worker payment recording

**Data model:** `tenant_users.can_record_payments` (boolean, default `false`). Not a role
widening — `worker` as a role still cannot record payments by default; this is a per-user flag,
settable only on a `worker`-role row.

**Enforcement:** `PaymentPolicy::create()`/`bulkCreate()`/`attachReceipt()` gained an explicit
*or*-branch: `role in (super, admin, manager, agent) OR (role === worker AND
tenantUser->can_record_payments === true)`. This is additive to the existing role check, not a
replacement.

**Who can set it:** `UpdateTenantUserRequest` restricts the whole request to super/admin
(`authorize()`), and a dedicated validation rule rejects (422, not silent) any attempt to set
`can_record_payments` on a `tenantUser` whose current role isn't `worker` — the flag is
meaningless outside that one role and the request layer refuses to accept it elsewhere. If a
user's role is patched away from `worker` in the same request, `SettingsUserController` zeroes
the flag defensively rather than leaving a stale `true` sitting on a non-worker row.

**UI:** a single checkbox in `Settings/Users.tsx`, rendered only on worker-role rows — not a new
settings page, not a generic capability picker.

**A real bug fixed alongside this:** `PaymentController::create()` (the web GET action for the
payment-entry form) queried `Customer::query()->with('zone')->get()` directly, bypassing branch
scoping entirely — any branch-fenced user (including a flag-granted Secretary) would have seen
every customer in every branch in the picker, even though the actual POST was already protected
by `ScopesByBranch`-scoped lookups. Now goes through `CustomerRepositoryInterface::allMatching()`
like every other customer-listing call site.

## 3. Case 2 — Agent zone-scoped payment verification

**Data model:** `TenantContext` gained `?int $zoneId`, resolved the same direct way `branchId`
already is: for the `agent` role, `Agent::where('user_id', ...)->zone_id`; `null` for every other
role (unrestricted — no other role is zone-scoped). `TenantContext::currentZoneId(): ?int` is a
static defensive resolver mirroring `currentBranchId()`, for code that runs before the
tenant-resolving middleware binds context (console commands, some service constructors).

**Enforcement:** `PaymentPolicy::verify()` changed signature to
`verify(User $user, Payment $payment)` — it needed a target model to check scope against, which
it didn't take before. Logic: `role in (super, admin, manager) OR (role === agent AND
context->zoneId !== null AND payment->customer->zone_id === context->zoneId)`.
`VerifyPaymentRequest::authorize()` already resolved `$this->route('payment')` before this call —
no change needed there.

**The bulk-verify bypass this design had to close:** `PaymentPolicy::bulkVerify()` and
`PaymentVerificationService::verifyMany()` originally took no target model at all — a class-level
ability check only. Zone-scoping `verify()` alone would have left an agent able to bypass the
zone fence entirely by hitting the bulk-verify endpoint with UUIDs of payments outside their zone.
Fixed by widening `bulkVerify()` to include `agent` (keeping the existing "bulkVerify mirrors
verify()'s roles" invariant) and adding a third per-item check inside `verifyMany()`'s existing
loop: for an agent actor, any payment whose customer's zone doesn't match `TenantContext`'s
`zoneId` is added to `$skipped` (never silently dropped, never silently verified) — the same
defensive-per-item-recheck pattern that loop already used for "still pending" and "exact bill
match." This was chosen over the simpler fallback of excluding agents from bulk-verify entirely,
because it matches this codebase's established convention (per-item re-validation in bulk loops)
and gives agents genuine same-zone bulk capability rather than an arbitrary restriction.

## 4. Also required, done alongside both cases

- **`TenantUser` gained the `Auditable` trait.** Role/permission changes on this model were not
  audit-logged at all before this — a real, confirmed gap (unlike 13 other models in this app that
  already `use Auditable`). This needed a prerequisite fix: `AuditableObserver` reads `$model->uuid`
  for the NOT NULL `audit_logs.record_uuid` column, and `TenantUser` had no `uuid` column at all.
  Added one via the standard `HasUuid` trait, but **deliberately not wired as the route-binding
  key** — every existing `{tenantUser}` route/controller/frontend call still addresses rows by
  plain `id`, unchanged. The `uuid` exists purely for audit identity.

## 5. What was deliberately NOT built

No `permissions` table, no `tenant_user_permissions` grant table, no "ceiling" table, no scope
enum system, no admin UI for granting arbitrary capabilities to arbitrary users. If a future
change request looks like "let me grant [some permission] to [some specific user]" beyond these
two named cases, don't extend this design ad hoc with more one-off boolean flags — that's the
signal to revisit the shelved full-matrix design instead of accumulating narrow flags.

## 6. Mobile app intersection

The React Native mobile app (see `references/mobile-app-react-native.md`) is expected to consume
zone-scoped verify once it's built — an agent's device would show a "Verify" action gated by
`role === 'agent'` (correct for a role+zone model; there's no per-user-grant case here that a
literal role check could miss).

- **Fixed** (mobile-sync backend prerequisites work, same session): `SyncService::upsertedCustomers()`/
  `changedPayments()` now consult `TenantContext::currentZoneId()`/`currentBranchId()` and scope the
  mobile pull response accordingly — the enforcement boundary and the data-exposure boundary agree.
  This item is done; earlier drafts of this doc listed it as outstanding, corrected here.
- **Still not built**: `SyncService::push()` has no `verify` action in its `$changes` handling —
  only `payments`/`expenditures` (both creates). A `verifications` entry in the sync payload is new
  protocol surface, needed before an agent can verify a payment while offline, not something that
  already exists and just needs a client.

## 7. Investor tier — a third minimal, targeted extension, same pattern as §2/§3

Added alongside `references/complaint-desk.md` (its escalation engine's top tier notifies
investors) and `references/in-app-notifications.md`. Owner's own framing, given directly: *"the
investor can also be a user... that's the simplest way to avoid complexity... a good company app
should have a system to update investors and stakeholders of the current reports... this eliminates
risks, theft, and unaccountability."* Read as: from the owner's and the investor's own perspective,
this must be a normal user account — same login form, same login flow as every other user — never
a separate portal or credential system. That constraint is satisfied regardless of which internal
enforcement mechanism gets picked below; it only rules out anything that would *feel* like a
separate product.

**Decision: `tenant_users.is_investor` (boolean), not a 6th `role` enum value.** Verified this
codebase's 14 Policy classes are 100% closed allow-lists (`isAnyOf(...)`) with zero deny-list
patterns anywhere — so a 6th enum value wouldn't actually leak access into other Policies the way
an early draft of this reasoning assumed (it would be excluded from every allow-list automatically,
simply by never being named in one). The real reasons to reject the enum value are different:

- **Semantic mismatch.** `role` answers "what operational job does this person do" and drives real
  role-keyed logic elsewhere (`TenantContext::resolve()`'s agent-only zone-fencing,
  `ReportController::defaultTierForRole()`, the Settings/Users.tsx role dropdown,
  `TenantUserPolicy`'s role-assignment rules). An investor does none of that work — jamming them
  into the enum forces a judgment call at every one of those role-keyed spots even though no
  security boundary would actually leak. That's the same low-grade, easy-to-miss-a-spot cost this
  doc is generally allergic to, just relocated from "security exclusions" to "business logic."
- **Direct precedent match**: identical shape to `can_record_payments` (§2) — *"additive to the
  existing role check, not a replacement."* One boolean, one additive OR clause in one Policy
  method (`ReportPolicy::view()`), nothing else touched.
- **`is_landlord` is the right analogy, with one correction**: `is_landlord` is central because
  platform-operator authority is inherently cross-tenant. Investor authority is inherently
  tenant-scoped (SWECOM's investor must never see the other real tenant's reports) — so the flag
  lives on `tenant_users`, not `users`, reusing the same cross-schema-FK convention that table
  already established for `user_id`.

**Data model**: `tenant_users.is_investor` (boolean, default `false`), `investor_granted_by`
(nullable, cross-schema FK to `public.users.id`), `investor_granted_at` (nullable timestamp) —
mirrors `is_landlord`/`landlord_granted_by`/`landlord_granted_at` exactly, relocated to the
tenant-scoped table. An investor row's `role` should default to `'worker'` (the existing floor) as
a defensive backstop — not because `role` gates anything for them, but so a mistakenly-`false`
`is_investor` flag still denies everything by default, never accidentally opens up broader access.

**Enforcement — no new middleware.** `EnsureLandlord` exists as route middleware because landlord
access is checked *before* tenancy resolves; investor access is inherently *after* tenancy resolves
(it's a per-tenant grant), so the natural gate is the same place every other feature gets gated:
`ReportPolicy::view()` gains one additive OR — `isAnyOf('super','admin','manager','agent') ||
$context->tenantUser->is_investor`. No `TenantContext` change needed; it already carries the full
`tenantUser` object. **`ReportPolicy::export()` stays super/admin/manager only** — "exactly one
capability: view reports" means view, not export, unless explicitly asked for later. Because
`is_investor` grants nothing beyond that one additive OR and the row's `role` sits at the `worker`
floor, an investor is automatically locked out of every other Policy in the app
(`RESOURCES_ROLES`/`AUDIT_ROLES`/`SETTINGS_ROLES`/etc.) with zero new negative checks anywhere —
that's the actual payoff of the flag-axis choice over the enum-value one.

**Frontend**: `HandleInertiaRequests::share()` gains `'is_investor' => (bool)
$context?->tenantUser->is_investor`, mirrored next to the existing `is_landlord` share (same
division of labor: display hint only, `ReportPolicy` is the real gate). A new `InvestorLayout.tsx`,
structurally identical to `LandlordLayout.tsx`'s existing pattern — a distinct file, not a
conditional branch inside `AppLayout` — with essentially no sidebar nav (there's only one page to
reach), a distinct branding accent so it visually reads as a different area, and just a logout
control (no "back to my workspace" link — investors have no other workspace).
`ReportController` itself needs no changes — its existing "one route, server picks payload/scope,
frontend picks layout per role" convention already covers this; investor layout selection is one
more instance of that same convention, decided client-side off the shared `is_investor` flag.

---

## v2 (2026-09): configurable role→permission matrix

**Status: built and shipped** (RBAC v2, waves 1–4). Full design:
`docs/plans/rbac-v2-configurable-roles.md`.

The v1 design above still stands **as-is** — the two fine-grained
payment/verify extensions (`can_record_payments`, agent zone-scoped
verify), the Investor tier, and every scope fence (`branchId`, `zoneId`)
are unchanged and still additive OR-branches. What v2 replaced is only the
**general role-check mechanism** underneath the policies:

- Roles are no longer hardcoded. Each tenant has its own `roles` +
  `role_permissions` tables (tenant schema). The 5 built-ins (`super`,
  `admin`, `manager`, `agent`, `worker`) are seeded `is_system` rows with
  exactly the permissions their old hardcoded checks granted — **no
  behaviour change on deploy** — plus a tenant can add custom roles.
- Policies now check `App\Support\TenantContext::can('<permission>')` /
  `canAny(...)` against that per-tenant matrix (wired through
  `Gate::before` + a `Gate::define` per permission in
  `PermissionServiceProvider`), instead of `isAnyOf('super','admin',…)`.
- The permission **catalog is closed** — `App\Auth\Permission` (a
  string-backed PHP enum, ~49 cases, `byArea()` for grouping). The matrix
  UI can only toggle catalog entries, never invent new ones;
  `Role::syncPermissions()` intersects against `Permission::values()` as a
  second guard.
- `super` bypasses every check via `roles.is_super` (`Gate::before`), so a
  misconfigured matrix can never lock the owner out.
- Editable in **Users Control Center** (`/users`, not under `/settings`) —
  Users tab (add user, assign role, investor toggle, deactivate) +
  Roles & permissions tab (the checkbox matrix, add/clone/delete custom
  roles). Gated `users.view` / `users.manage` / `roles.manage`.
- Frontend: `HandleInertiaRequests::share()` and `GET /api/v1/auth/me`
  both expose `auth.user.permissions: string[]` (`['*']` for super).
  `AppNav.tsx` (`buildVisibleNavItems`) and every per-page `can*`
  affordance check a permission via `hasPermission()`
  (`resources/tsx/lib/permissions.ts`); the mobile app caches the list in
  its offline session profile and checks it via `useAuth().can()`.
- `cncms:tenant-role` validates its `role` argument against the tenant's
  `roles` table, so it can assign custom roles too.

Not built (still out of scope, same as v1): per-**user** permission
overrides, ceiling/delta-grant tables, scope qualifiers on permissions,
time-boxed grants, an approval workflow for role changes.
