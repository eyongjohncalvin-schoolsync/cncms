# RBAC v2 — configurable roles & permissions

**Owner ask (2026-09-02):** "the user roles and permissions implementation is
too amateur… make it more configurable, we don't have a way of assigning
permissions to roles, and I don't want the roles to be hardcoded. Detach it
from Settings — put it on a new nav link **Users Control Center** where we
add users and manage permissions."

## Relationship to `rbac-permissions.md` (v1)

v1 deliberately **shelved** a full permission matrix (52 named permissions +
per-user ceiling table + delta-grant table + per-user tuning UI) as more than
the two triggering cases needed, and the owner was explicit then that
enforcement must stay "simple and directly checkable, not a cascading
override system."

**v2 is narrower than what was shelved** and keeps that bar:

| Shelved v1 matrix | v2 |
|---|---|
| Per-**user** grant rows | Per-**role** only. A user's abilities = their role's permission set. Nothing per-user. |
| Ceiling table + delta-grant table | None. One pivot: `role_permissions`. |
| 52 hand-tuned permissions | ~40, mechanically derived from existing policy methods (list below). |
| Arbitrary admin tuning of any user | Admin edits the **role→permission matrix**; users are just assigned a role. |

The one hardcoded rule that stays: **`super` bypasses every check**
(`Gate::before`), so a misconfigured matrix can never lock the owner out.

Scope fences (`branchId`, `zoneId`, `can_record_payments`) are **orthogonal**
and unchanged — they filter *which rows*, permissions decide *which actions*.
Do not fold them into this.

## Data model (tenant schema)

```
roles
  id            bigserial pk
  uuid          uuid v7
  name          citext, unique per tenant   -- 'super','admin',... plus custom
  label         varchar                     -- display name, editable
  description   varchar null
  is_system     boolean default false       -- the 5 seeded roles; cannot be deleted or renamed(name), CAN have permissions edited (except super)
  is_super      boolean default false        -- exactly one row; the Gate::before bypass; permissions list ignored for it
  created_at / updated_at

role_permissions
  role_id       fk roles
  permission    varchar          -- must be a value in the code catalog (App\Auth\Permission)
  primary key (role_id, permission)
```

`tenant_users.role` stays a **string** column holding `roles.name` (keeps
every existing row valid, keeps `TenantContext::role` working, avoids a
migration of the pivot). Add FK `tenant_users.role -> roles.name` deferred,
or validate in the request layer if a cross-schema FK is awkward.

`is_investor` stays a separate `tenant_users` boolean (it's a tier flag, not
a role) — untouched.

### Permission catalog — `App\Auth\Permission` (PHP enum, string-backed)

Fixed in code. The UI can only toggle these, never invent new ones (the
simplicity guard). Derived from the current policy methods:

```
customers.view  customers.create  customers.update  customers.delete
customers.archive  customers.print_bill  customers.change_status        -- disconnect/suspend/reconnect (single + bulk)
customers.status_board
payments.view  payments.create  payments.update  payments.delete
payments.verify                                                          -- single + bulk
manuscripts.view  manuscripts.export  manuscripts.calculate  manuscripts.send_bill
zones.view  zones.manage                                                 -- create/update/delete collapse to one
agents.view  agents.manage
branches.view  branches.manage
expenditures.view  expenditures.create  expenditures.update  expenditures.delete  expenditures.dashboard
reports.view  reports.export
complaints.view  complaints.create  complaints.resolve  complaints.assign  complaints.notify_investors
arrears.view  arrears.request  arrears.approve                            -- approve covers approve+reject
audit.view
company.view  company.update
command_runs.view  command_runs.publish  command_runs.schedule
users.view  users.manage                                                 -- add/edit users, assign roles
roles.manage                                                             -- edit the permission matrix, add/remove custom roles
```

Collapsing rule: where every current call site checks the *same* role set for
a group of policy methods (e.g. `zones.create/update/delete` are all
`super,admin,manager`), collapse to one permission. Where they differ (e.g.
`customers.delete` is `super,admin` but `customers.update` is
`super,admin,manager`) keep them separate. Wave 1 agent: produce the final
catalog by auditing every policy + the `isAnyOf()` call sites and the route
middleware, and record the "which policy methods map to which permission"
table in this doc before writing the enum.

### Default seed — behaviour must not change on day 1

Seed the 5 system roles with exactly the permissions their current hardcoded
checks grant, so every existing test passes unchanged and no user gains or
loses access at deploy:

- **super** — `is_super = true` (all, via bypass)
- **admin** — everything except nothing (currently `super,admin` sees all admin screens); give admin every permission except leave `roles.manage`/`users.manage` to super+admin both (they're both `super,admin` today)
- **manager** — the `super,admin,manager` set
- **agent** — the `super,admin,manager,agent` set (reports.view, customers.view, payments.create, etc.) + keep zone fence
- **worker** — minimal (currently almost everything is closed to worker); `payments.create` only if `can_record_payments` — that stays a separate flag, so worker's role gets no `payments.create`

Wave 1 delivers the seed as a tenant migration/seeder that is **idempotent**
and safe to run on the live `tenantswecom` schema.

## Enforcement

`TenantContext` gains:

```php
public function can(string $permission): bool
// true if is_super, else permission ∈ role's permission set (cached per request)
public function canAny(string ...$permissions): bool
```

Wire into Laravel's Gate so policies and `$user->can()` work:

```php
// AppServiceProvider or a dedicated PermissionServiceProvider
Gate::before(fn ($user, $ability) => TenantContext bound && context->isSuper() ? true : null);
foreach (Permission::cases() as $p) {
    Gate::define($p->value, fn ($user) => app(TenantContext::class)->can($p->value));
}
```

Policies then become thin: `CustomerPolicy::create()` →
`return $user->can('customers.create');` (plus any existing scope check,
which stays). **Bulk methods must check the same permission as their
single counterpart** (documented v1 bug: `bulkVerify` etc. take no model —
add the check in the bulk service loop too, not just the policy).

Route-level `->middleware(...)` role gates (if any) → `->can('permission')`.

Frontend: `HandleInertiaRequests::share()` adds
`auth.user.permissions: string[]` (the resolved list, `['*']` for super).
`AppNav.tsx` replaces `SETTINGS_ROLES` / `REPORTS_ROLES` / … arrays with
`permissions.includes('x')` checks. Per-page `can_*` props already computed
server-side from policies keep working (they now resolve through the matrix).

Mobile: `GET /api/v1/auth/me` adds `permissions`. Mobile guards that
currently check `role` switch to permission checks. Offline: the permission
list is cached with the session and refreshed on each successful sync
(same cadence as role today — see `offline-sync-strategy.md`).

## Users Control Center (new nav, detached from Settings)

New top-level nav item **Users Control Center** (`/users`), gated to
`users.view`. Not under `/settings`. Two tabs:

1. **Users** — list every `tenant_user` (name, email, role, investor flag,
   status, last active). Add user (by email — must be an existing central
   `users` row or invite flow, mirror `cncms:tenant-role`), change role,
   toggle investor, deactivate. Gated: list = `users.view`, mutations =
   `users.manage`.
2. **Roles & permissions** — the matrix. Rows = permissions (grouped by
   area), columns = roles. Checkbox grid. Add a custom role (name + label +
   clone-from). Delete a custom role (blocked if any user holds it — reassign
   first). `is_system` roles: matrix editable, name locked, not deletable.
   `super` column: all-checked, read-only. Gated `roles.manage`.

Move the existing Settings → Users screen's function here; leave a redirect
or remove the old nav entry (coordinator decides at wave 3).

## Waves

### Wave 1 — foundation (no behaviour change)  ✅ BUILT (awaiting coordinator commit)
Delivered: `app/Auth/Permission.php` (49-case enum + `byArea()`),
`app/Models/Role.php` + `app/Models/RolePermission.php`,
`database/migrations/tenant/2026_09_02_000000_create_roles_and_role_permissions_tables.php`
+ `2026_09_02_000100_seed_default_roles.php`, `database/seeders/DefaultRolesSeeder.php`
(wired into `TenantDatabaseSeeder`), `app/Console/Commands/SeedDefaultRoles.php`
(`cncms:seed-default-roles`), `TenantContext::can()/canAny()/isSuper()/permissions()`,
`app/Providers/PermissionServiceProvider.php` (Gate::before + 49 defines),
`permissions` in the Inertia share + `/auth/me` + both TS type files,
`tests/Feature/Web/RolePermissionResolutionTest.php`. Catalog table below.
**Coordinator must run `php artisan tenants:migrate` (or `cncms:seed-default-roles`)
before Wave 2** — see the Wave 1 notes.

Owns: `database/migrations/tenant/*_create_roles_tables.php`,
`database/seeders/` role seed, `app/Auth/Permission.php`,
`app/Models/Role.php`, `app/Support/TenantContext.php` (+ `resolve()`),
`app/Providers/*` gate wiring, `app/Services/PermissionRegistry.php` (or
similar) for the cached per-request resolution.
Deliver: the catalog↔policy-method mapping table (append to this doc), the
migration + idempotent seeder, `can()`/`canAny()`, `Gate::before` + defines,
`permissions` in the Inertia share and `/auth/me`. **Every existing test
still green.** New tests: `RolePermissionResolutionTest`.
Do NOT touch policies or controllers yet.

### Wave 2 — enforcement swap
Owns: `app/Policies/*`, the bulk services
(`app/Services/*BulkService.php` / loops), route files with role middleware,
`app/Http/Requests/*` that check roles.
Every `isAnyOf(...)` / hardcoded role check → `can()` / `$user->can()`.
Bulk endpoints get the check in the service loop. Fail closed.
Tests: run every `tests/Feature/**/*Policy*`, `PaymentTest`, `CustomerTest`,
`ManuscriptTest`, `ComplaintTest`, `ArrearsAdjustmentTest`, `ReportTest`,
`SettingsTest`, `LandlordTest` — all must stay green (behaviour identical
because the seed matches the old checks).

### Wave 3 — Users Control Center UI + controllers  ✅ BUILT (awaiting coordinator commit)
Owns: `app/Http/Controllers/UsersControlCenter/*`, `routes/web/users.php`,
`resources/tsx/pages/UsersControlCenter/*`,
`resources/tsx/components/shared/AppNav.tsx` (new nav item + drop old
Settings→Users nav), `app/Http/Requests/StoreRole*`, `UpdateRole*`.
Depends on wave 1. Can start once wave 2 is merged.
Tests: `UsersControlCenterTest`, `RoleManagementTest`.

**Delivered:**
- Migration `database/migrations/tenant/2026_09_03_000000_relax_tenant_users_role_check.php`
  — **drops** the `tenant_users_role_check` CHECK constraint (Postgres
  artefact of the original `$table->enum('role', …)`). No replacement FK;
  `Store/UpdateTenantUserRequest`'s `role` rule is now
  `Rule::exists('roles', 'name')` (validates against the tenant `roles`
  table, system + custom). Applied to every tenant via `tenants:migrate`.
- `app/Http/Controllers/UsersControlCenter/UserController.php` — the retired
  `SettingsUserController` relocated verbatim (two-connection create,
  `resolveBranchId`, role≠worker clears `can_record_payments`, investor
  audit stamps). Renders `UsersControlCenter/Users`; role `<select>` is now
  fed from a `roles` prop (the tenant's own roles), not a literal list.
- `app/Http/Controllers/UsersControlCenter/RoleController.php` — matrix
  `index` (roles + `permissionsByArea` from `Permission::byArea()`,
  per-role `user_count`), `store` (name/label/description + optional
  `clone_from` by uuid), `update` (label/description + full permission
  list via `Role::syncPermissions`), `destroy` (422 `ValidationException`
  listing the members who still hold it).
- `app/Policies/RolePolicy.php` (registered in `AppServiceProvider` next to
  `ReportPolicy`) — everything gated `roles.manage`; `update` also blocks
  `is_super`, `delete` also blocks `is_system`.
- `app/Http/Requests/StoreRoleRequest.php` (lowercase-normalised name,
  `/^[a-z0-9][a-z0-9_-]*$/`, `notIn` the 5 reserved names, `unique:roles`)
  + `UpdateRoleRequest.php` (`permissions.*` → `Rule::in(Permission::values())`,
  hard 422 on an unknown string — the "catalog is closed" guard).
- `routes/web/users.php` (required from `routes/web.php` inside
  `['auth','tenant.web','throttle:web']`): `users` GET/POST,
  `users/{tenantUser}` PATCH, `users/{tenantUser}/deactivate` POST,
  `users/roles` GET/POST, `users/roles/{role}` PATCH/DELETE. `settings/users`
  is now `Route::redirect('settings/users', '/users')` (name kept as
  `settings.users.index`); `SettingsUserController` + `Settings/Users.tsx`
  deleted; the "Users & Roles" tab removed from `SettingsTabs`.
- Frontend: `resources/tsx/pages/UsersControlCenter/{Users,Roles}.tsx` +
  `resources/tsx/components/users/UsersControlCenterTabs.tsx` (mirrors
  `SettingsTabs`). No separate `Index.tsx` shell — each page renders
  `AppLayout` + the tabs directly, exactly as the Settings pages do (the
  plan's "Index.tsx shell" would fork from that established pattern).
- `AppNav.tsx`: new `Users Control Center` item (`/users`, `sky` accent,
  `IconShieldLock`), gated by `permissions.includes('users.view')` (or
  `'*'`). `buildVisibleNavItems(role, permissions)` + `AppNav`/`MobileNavDrawer`
  thread `auth.user.permissions` through. The other items' `_ROLES` arrays
  are untouched — Wave 4's job. `common.users_control_center` added to
  en/fr.
- Roles matrix save model: a read-only ✓/— grid for the overview; editing
  is one role at a time via a modal (label + description + the full
  permission checklist grouped by area) that PATCHes
  `/users/roles/{uuid}` **once** with the complete `permissions` array —
  no per-cell requests, no multi-role batch. `is_super`'s column has no
  Edit button; `is_system` rows show the locked key but an editable
  checklist; custom rows also get a Delete button (disabled with a reason
  when `user_count > 0`).
- Tests: `tests/Feature/Web/UsersControlCenterTest.php` (10),
  `tests/Feature/Web/RoleManagementTest.php` (13). Regression: `SettingsTest`
  (Users assertions moved out; legacy-redirect test added), `RoleLoginTest`
  (`/settings/users` → `/users`), `InvestorTest` (grant/revoke paths moved).

### Wave 4 — frontend enforcement + mobile + cleanup  ✅ BUILT (awaiting coordinator commit)
Owns: `resources/tsx/components/shared/AppNav.tsx` (swap role arrays →
permissions — coordinate with wave 3 if concurrent), remaining `resources/tsx`
role literals, `mobile/src` role checks, `mobile/src/api` types,
`app/Http/Controllers/Api/AuthController.php` (`/auth/me`).
Full mobile test run (`cd mobile && npm test`) + the web suites touched.
Update `rbac-permissions.md` with a "v2 superseded the role-check approach"
section and this doc's final state.

**Delivered:**
- `resources/tsx/components/shared/AppNav.tsx` — every `*_ROLES` array
  removed. `buildVisibleNavItems(permissions)` (no more `role` arg) filters
  a `permission?` on each base nav item (`customers.view` / `zones.view` /
  `payments.view` / `manuscripts.view` / `agents.view` / `complaints.view`;
  dashboard ungated) and gates the conditional items on:
  Disconnections→`customers.status_board`, Flagged Customers→
  `customers.eligibility_board && !customers.status_board` (agent variant
  intent preserved), Reports→`reports.view`, Agent App→`manuscripts.view`
  (the agreed `mobile.sync` proxy), Resources→`expenditures.dashboard`,
  Audit→`audit.view`, Branches→`branches.manage`, Users Control Center→
  `users.view`, Settings→`canAny('company.update','command_runs.view')`.
  `AppNav`/`MobileNavDrawer`/`AppLayout` call sites drop the `role` prop.
- `resources/tsx/lib/permissions.ts` (new) — `hasPermission()` /
  `hasAnyPermission()` / `useCan()`, honouring `'*'`.
- `resources/tsx/types/index.ts` — `AuthUser.role` widened to
  `string | null`; `Role` union kept exported for "a system role" sites.
- Per-page affordance checks migrated off hardcoded role names to
  `hasPermission(auth.user?.permissions, …)`: `Payments/Index`
  (`payments.verify`/`payments.delete`), `Customers/Index` +
  `Customers/Show` (`customers.update`/`customers.archive`),
  `Disconnections/Index` (`customers.change_status`), `Manuscripts/Index`
  (`manuscripts.export`/`.calculate`/`.send_bill`), `Agents/Index`
  (`agents.manage`), `Resources/Dashboard` + `Resources/Categories`
  (`expense_categories.manage`), `Resources/Expenditures/Index`
  (`expenditures.delete`). Each new permission is seeded to exactly the old
  role set, so no system-role user's UI changes. `Reports/Index`'s
  `AGENT_LAYOUT_ROLES` (layout selection, not a gate — like mobile
  `zones.tsx`) and `Users.tsx`'s `role === 'agent'/'worker'` (system-role
  display branches) left as-is per plan.
- `mobile/src/types/api.ts` — `MeResponse.permissions` docstring updated
  (field itself added in Wave 1).
- `mobile/src/auth/AuthContext.tsx` — `permissions: string[]` carried in
  state, cached in the SecureStore session profile (`AuthProfile`, optional
  for back-compat → defaults `[]`), refreshed on every `/auth/me` (login +
  cold start), reset on logout/401. Exposes `permissions` + `can(permission)`
  (honours `'*'`) on the context value. (The mobile session profile is a
  SecureStore JSON blob, not versioned SQLite — no migration; `role` was
  stored the same way.)
- Mobile guards swapped to `can()`: `disconnect/[uuid].tsx` →
  `can('customers.change_status') || role === 'agent'` (matches
  CustomerPolicy::disconnect's agent OR-branch), `reconnect/[uuid].tsx` →
  `can('customers.change_status')` (no agent branch), `manuscript.tsx` →
  `can('manuscripts.view')`, `reports.tsx` → `can('reports.view')`.
  `zones.tsx`'s `role === 'agent'` (a "show MY zone" UX branch) and
  `(tabs)/index.tsx` / `settings.tsx` (role display-only) left as-is.
- `app/Console/Commands/TenantRole.php` — validates `role` against the
  tenant's `roles` table after `tenancy()->initialize()` (system + custom),
  error lists the available names. No longer `Rule::in` the 5 built-ins.
- `app/Http/Controllers/Api/AuthController.php` — no change needed
  (`/auth/me` already returned `permissions` from Wave 1).
- Docs: `rbac-permissions.md` "v2 (2026-09)" section appended; this doc +
  `docs/plans/README.md` marked DONE.

**RBAC v2 is COMPLETE** — all 4 waves built.

## Non-goals (do not build)
Per-user permission overrides. Permission scoping/qualifiers. A "ceiling"
concept. Time-boxed grants. Approval workflow for role changes. Any of the
shelved v1 matrix's extra tables.

---

## Wave 1: final catalog

Audited every method in the 14 `app/Policies/*` classes, every
`$this->context->isAnyOf(...)` / `is('...')` call site in `app/`, every
`$user->can(...)` FormRequest `authorize()`, and every `abort_unless` role
gate. There are **no** route-level `->middleware()` role gates — all role
enforcement is Policy methods plus a handful of controller/service checks.

**Catalog = 49 permissions** (`App\Auth\Permission`). Two were added beyond
the plan's first sketch because their role set genuinely differs from the
nearest neighbour (the collapsing rule says keep separate):
`customers.eligibility_board` (adds `agent` vs `customers.status_board`) and
`expense_categories.manage` (`super,admin` — distinct from every
`expenditures.*`). `notification_settings` collapsed **into** `company.view`
/ `company.update` (identical `super,admin` gate, same Settings surface).

Role-set shorthand: **S**=super **A**=admin **M**=manager **G**=agent
**W**=worker. "all" = unconditionally `true` for any authenticated tenant
user (S/A/M/G/W).

### Customers

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `customers.view` | `CustomerPolicy::viewAny/view` | all | A M G W |
| `customers.create` | `CustomerPolicy::create`; `ImportCustomersRequest`; `CustomerController::store/import` | S A M | A M |
| `customers.update` | `CustomerPolicy::update`; `BulkUpdateCustomerBillRequest` | S A M | A M |
| `customers.delete` | `CustomerPolicy::delete`; `CustomerController::destroy` | S A M | A M |
| `customers.archive` | `CustomerPolicy::archive/restore`; `ArchiveCustomerRequest` | S A M | A M |
| `customers.print_bill` | `CustomerPolicy::printBill`; `BillController`; `Api\BillController` | S A M G | A M G |
| `customers.change_status` | `CustomerPolicy::disconnect/suspend/reconnect` + `bulkDisconnect/bulkSuspend/bulkReconnect`; the `*CustomerRequest` authorize()s | S A M (agent gets zone-scoped **disconnect only**, stays an OR-branch) | A M |
| `customers.status_board` | `CustomerPolicy::viewStatusBoard` (the /disconnections board) | S A M | A M |
| `customers.eligibility_board` | `CustomerPolicy::viewEligibilityBoard`; `DisconnectionsController::eligibilityIndex`; `Api\CustomerController` | S A M G | A M G |

### Payments

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `payments.view` | `PaymentPolicy::viewAny/view` | all | A M G W |
| `payments.create` | `PaymentPolicy::create` + `bulkCreate` + `attachReceipt`; `PaymentController::create/store` | S A M G (**+ worker w/ `can_record_payments`**, stays an OR-branch) | A M G |
| `payments.update` | `PaymentPolicy::update`; `PaymentController` `can_manage` | S A M | A M |
| `payments.delete` | `PaymentPolicy::delete`; `PaymentController` `can_delete` | S A | A |
| `payments.verify` | `PaymentPolicy::verify` + `bulkVerify`; `PaymentService::create` auto-verify; `VerifyPaymentRequest`/`BulkVerifyPaymentRequest` | S A M (agent gets zone-scoped verify, stays an OR-branch; `bulkVerify` keeps agent in the class gate + per-item zone recheck in `PaymentVerificationService::verifyMany`) | A M |

### Manuscripts

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `manuscripts.view` | `ManuscriptPolicy::viewAny/view`; `Api\ManuscriptController`; `Api\SyncController::authorizeSync` (same S A M G set); `AgentAppController` (same set) | S A M G | A M G |
| `manuscripts.export` | `ManuscriptPolicy::export`; `BillBatchController` (all 4 actions) | S A M | A M |
| `manuscripts.calculate` | `ManuscriptPolicy::calculate`; `ManuscriptController` (run/rerun/publish paths) | S A | A |
| `manuscripts.send_bill` | `ManuscriptPolicy::sendBill` | S A M G | A M G |

### Zones / field agents / branches

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `zones.view` | `ZonePolicy::viewAny/view` | all | A M G W |
| `zones.manage` | `ZonePolicy::create/update/delete`; `ImportZonesRequest` | S A M | A M |
| `agents.view` | `AgentPolicy::viewAny/view` | all | A M G W |
| `agents.manage` | `AgentPolicy::create/update/delete` | S A M | A M |
| `branches.view` | `BranchPolicy::viewAny/view` | all | A M G W |
| `branches.manage` | `BranchPolicy::create/update/delete` | S A | A |

### Expenditures

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `expenditures.view` | `ExpenditurePolicy::viewAny/view`; `ExpenseCategoryPolicy::viewAny/view` | all | A M G W |
| `expenditures.create` | `ExpenditurePolicy::create` | S A M G | A M G |
| `expenditures.update` | `ExpenditurePolicy::update` | S A | A |
| `expenditures.delete` | `ExpenditurePolicy::delete`; `Api\ExpenditureController` | S A | A |
| `expenditures.dashboard` | `ExpenditurePolicy::viewDashboard`; `ResourceController::dashboard`; `Api\ResourcesDashboardController` | S A M | A M |
| `expense_categories.manage` | `ExpenseCategoryPolicy::create/update/delete` | S A | A |

### Reports / audit

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `reports.view` | `ReportPolicy::view` | S A M G (**+ `is_investor`**, stays an OR-branch) | A M G |
| `reports.export` | `ReportPolicy::export`; `ReportController::export` `can_export` | S A M | A M |
| `audit.view` | `AuditLogPolicy::viewAny`; `AuditLogController`; `Api\AuditLogController` | S A M | A M |

> `ReportService::applyRoleVisibility()` also strips the `pnl` sub-payload
> for anyone below `super,admin`. That is a **payload-shaping** filter inside
> an already-authorised response, not an access gate — left as-is for Wave 2
> to decide whether it wants a `reports.pnl` permission. Not seeded.

### Complaints

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `complaints.view` | `ComplaintPolicy::viewAny/view`; `ComplaintController` dashboard-vs-submission view is cosmetic, not a gate | all | A M G W |
| `complaints.create` | `ComplaintPolicy::create` | all | A M G W |
| `complaints.resolve` | `ComplaintPolicy::resolve` + `reopen` + `linkDuplicate` (all S A M); `ReopenComplaintRequest`/`LinkDuplicateComplaintRequest`; `ComplaintController` `can_manage`/`can_link_duplicate` | S A M (**+ actor ≠ submitter**, stays in the policy) | A M |
| `complaints.assign` | `ComplaintPolicy::assign`; `AssignComplaintRequest` | S A M | A M |
| `complaints.notify_investors` | `ComplaintPolicy::notifyInvestors`; `NotifyInvestorsComplaintRequest`; `ComplaintController` `can_notify_investors` | S A | A |

### Arrears adjustments

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `arrears.view` | `ArrearsAdjustmentPolicy::viewAny/view` | all | A M G W |
| `arrears.request` | `ArrearsAdjustmentPolicy::create` | all | A M G W |
| `arrears.approve` | `ArrearsAdjustmentPolicy::approve` + `reject`; `ApproveArrearsAdjustmentRequest`/`RejectArrearsAdjustmentRequest`; `AuditLogController` `can_approve`/`can_reject` | stage 1 (`pending`): S A M; stage 2 (`pending_second_approval`): S A — plus the maker≠checker identity rules and the `super` self-approval carve-out | A M (stage-2 "admin/super only" narrowing + all identity rules **stay hardcoded** in the policy — Wave 2) |

### Company & settings / task scheduler / users

| Permission | Policy methods / call sites | Current role set | Seeded to |
|---|---|---|---|
| `company.view` | `CompanyPolicy::view`; `NotificationSettingPolicy::view`; `SettingsBillPrintingController` (uses `view Company`) | all | A M G W |
| `company.update` | `CompanyPolicy::update`; `NotificationSettingPolicy::update` | S A | A |
| `command_runs.view` | `CommandRunPolicy::viewAny`; `SettingsCommandRunController::index` | S A | A |
| `command_runs.publish` | `CommandRunPolicy::publish` (publish + cancel + rollback + unpublish); `SettingsCommandRunController` (all 4 mutating actions + their `can*` props); `ManuscriptController` `canPublish` | S A | A |
| `command_runs.schedule` | `CommandRunPolicy::manageSchedule`; `SettingsCommandRunController` `canManageSchedule` | S A | A |
| `users.view` | `TenantUserPolicy::viewAny/view`; `SettingsUserController::index` | S A | A |
| `users.manage` | `TenantUserPolicy::create/update/deactivate`; `SettingsUserController::store/update/deactivate`; `UpdateTenantUserRequest`/`StoreTenantUserRequest` | S A | A |
| `roles.manage` | *(no policy yet — Wave 3 matrix UI)* | S A (by design) | A |

### Seed summary

| Role | `is_super` | Permission count | Rule |
|---|---|---|---|
| `super` | **true** | — (bypass, no rows) | Gate::before |
| `admin` | false | 49 (all) | every catalog entry |
| `manager` | false | 35 | the `super,admin,manager` set |
| `agent` | false | 18 | the `super,admin,manager,agent` set (minus `customers.change_status` & `payments.verify` — agent only gets those zone-scoped, as OR-branches) |
| `worker` | false | 11 | every unconditionally-`true` policy method + `complaints.create` + `arrears.request` |

Nesting holds: `worker ⊂ agent ⊂ manager ⊂ admin`.

### Notes for Wave 2

- **`Gate::before` super bypass is global** (per the spec's literal
  pseudocode). Two policies deliberately return `false` for a `super` today
  and Wave 2 must decide whether to preserve that against the bypass:
  `ComplaintPolicy::resolve/reopen` (submitter exclusion — a `super` who
  filed the complaint) and `ArrearsAdjustmentPolicy::approve` terminal
  states (`default => false` for an already approved/rejected row). No
  existing test exercises either super case, so Wave 1 ships the global
  bypass; flag raised for the coordinator.
- **`customers.change_status`, `payments.verify`**: agent is NOT seeded
  these. Wave 2's rewritten `CustomerPolicy::disconnect` /
  `PaymentPolicy::verify` / `bulkVerify` must keep the existing
  `role === 'agent' && zoneId matches` OR-branch next to the `can()` call,
  and `PaymentVerificationService::verifyMany()` keeps its per-item zone
  recheck.
- **`payments.create` worker branch**: `can_record_payments` stays an
  additive OR — worker role is not seeded `payments.create`.
- **`reports.view` investor branch**: `is_investor` stays an additive OR.
- **`arrears.approve`**: seeding grants the stage-1 gate (S A M). The
  stage-2 `admin/super only` narrowing and the maker≠checker/second-approver
  identity checks and the `super` carve-out are all orthogonal to "does this
  role hold the permission" — they stay in the policy body.
- **`tenant_users.role` has a CHECK constraint** (`tenant_users_role_check`)
  pinning it to the 5 system names. Wave 3 must drop/replace it before a
  custom role name can be stored on a membership row. (Wave 1's resolution
  path doesn't care — it joins by name string — but assignment does.)
- Non-policy class gates not given a dedicated permission:
  `AgentAppController` (agent-app download page) and
  `Api\SyncController::authorizeSync` both check the exact `S A M G` set —
  Wave 4 (mobile) can either reuse `manuscripts.view` or add a
  `mobile.sync` permission; not seeded now.

### Notes for Wave 4 (from Wave 3)

- **`AppNav.tsx` is now half-migrated on purpose.** Only
  `usersControlCenterNavItem` is permission-gated
  (`can('users.view')` where `can` also honours `'*'`);
  `buildVisibleNavItems(role, permissions)` takes both args. Every other
  item still uses its `*_ROLES` array against `auth.user.role`. Wave 4
  swaps the rest: `SETTINGS_ROLES`→`company.view`-ish (or a dedicated
  `settings.view`? — there's no such permission; `command_runs.view` +
  `company.update` are the real Settings gates, so gate the Settings link
  on `canAny('company.update','command_runs.view')`), `RESOURCES_ROLES`→
  `expenditures.dashboard`, `AUDIT_ROLES`→`audit.view`,
  `DISCONNECTIONS_ROLES`→`customers.status_board`,
  `ELIGIBILITY_NAV_ROLES`→`customers.eligibility_board` (but keep it
  agent-only-visually if desired), `REPORTS_ROLES`→`reports.view`,
  `AGENT_APP_ROLES`→(no permission yet — see the `mobile.sync` note above).
  `RoleLoginTest`/`InvestorTest` assert these endpoints by URL, not by
  nav, so they won't need changes — but any *nav-rendering* test will.
- **`AuthUser.role` (TS) is still typed `Role | null`** (the 5-literal
  union). A user on a custom role is currently a type lie there. Wave 4
  should widen it to `string | null` and audit `resources/tsx` +
  `mobile/src` for `role === 'literal'` comparisons (there are several —
  e.g. `AppNav`'s arrays, `Users.tsx`'s `role === 'agent'`/`'worker'`
  branches which are still *correct* since those are system roles, but
  worth a pass). `TenantUserRow.role` and `RoleOption`/`RoleMatrixRow` are
  already `string`.
- **`/auth/me` (`Api\AuthController::me`)** already returns `permissions`
  (Wave 1). Mobile guards that switch on `role` move to permission checks;
  the offline session cache needs the `permissions` array added alongside
  `role` (see `offline-sync-strategy.md`).
- **`cncms:tenant-role` command** still `Rule::in`s the 5 names somewhere
  in its validation — Wave 4 (or whoever) should let it target custom
  roles too, or explicitly document it as system-roles-only.
- The `Role` model's `syncPermissions()` already intersects against
  `Permission::values()`, so even a future bug that lets an unknown string
  past the request layer can't persist one.
