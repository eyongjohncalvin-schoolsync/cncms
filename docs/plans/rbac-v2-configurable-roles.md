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

### Wave 1 — foundation (no behaviour change)
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

### Wave 3 — Users Control Center UI + controllers
Owns: `app/Http/Controllers/UsersControlCenter/*`, `routes/web/users.php`,
`resources/tsx/pages/UsersControlCenter/*`,
`resources/tsx/components/shared/AppNav.tsx` (new nav item + drop old
Settings→Users nav), `app/Http/Requests/StoreRole*`, `UpdateRole*`.
Depends on wave 1. Can start once wave 2 is merged.
Tests: `UsersControlCenterTest`, `RoleManagementTest`.

### Wave 4 — frontend enforcement + mobile + cleanup
Owns: `resources/tsx/components/shared/AppNav.tsx` (swap role arrays →
permissions — coordinate with wave 3 if concurrent), remaining `resources/tsx`
role literals, `mobile/src` role checks, `mobile/src/api` types,
`app/Http/Controllers/Api/AuthController.php` (`/auth/me`).
Full mobile test run (`cd mobile && npm test`) + the web suites touched.
Update `rbac-permissions.md` with a "v2 superseded the role-check approach"
section and this doc's final state.

## Non-goals (do not build)
Per-user permission overrides. Permission scoping/qualifiers. A "ceiling"
concept. Time-boxed grants. Approval workflow for role changes. Any of the
shelved v1 matrix's extra tables.
