<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\ZoneAgent;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolved tenant-membership context for the currently authenticated API
 * request, bound into the container as a singleton by
 * App\Http\Middleware\ResolveTenant.
 *
 * Downstream controllers/policies can read the caller's role for the
 * active tenant either by:
 *
 *   - Type-hinting `App\Support\TenantContext $context` in a controller
 *     method / constructor (resolved via DI), or
 *   - Calling `app(App\Support\TenantContext::class)`, or
 *   - Reading `$request->attributes->get('tenant_role')` /
 *     `$request->attributes->get('tenant_user')`, which the middleware
 *     also sets for convenience.
 *
 * `branchId` is the multi-branch RBAC fence — see
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4. `null` means cross-branch/unrestricted (sees every branch);
 * a specific id means the caller is fenced to just that one branch. This
 * is a row-level concern layered on top of the existing role, not a
 * change to which abilities a role has — every Policy class keeps
 * checking `role` exactly as before, and repositories separately consult
 * `branchId` to decide which rows a query returns.
 *
 * `zoneIds` is the same kind of row-level fence, one level narrower than
 * `branchId`: the zones an `agent` covers (AppModelsZoneAgent —
 * management may give one agent one zone, several, or all of them). It backs
 * every "an agent only sees / collects / verifies in their own zones" rule
 * (sync, payments, disconnections, field registration, reports). Every
 * other role gets `null` (not zone-scoped). An agent with no zones gets an
 * empty list — they see nothing, never the whole branch.
 */
final class TenantContext
{
    /**
     * RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): the resolved
     * {is_super, permissions} for `$this->role`, looked up once and memoised
     * for the life of this instance. Null until first accessed. The
     * instance is bound per-request as a container singleton by both
     * resolving middleware, so this memo is effectively request-scoped —
     * no separate registry needed.
     *
     * @var array{is_super: bool, permissions: list<string>}|null
     */
    private ?array $roleState = null;

    /**
     * @param  bool  $branchChosen  true when $branchId is a branch an unfenced
     *                              user picked in the branch switcher (a view,
     *                              not a fence) — see viewingBranch()
     */
    public function __construct(
        public readonly TenantUser $tenantUser,
        public readonly string $role,
        public readonly ?int $branchId = null,
        /** @var list<int>|null */
        public readonly ?array $zoneIds = null,
        public readonly bool $branchChosen = false,
    ) {}

    /**
     * Branches v2 switcher (docs/plans/branches-v2.md §8): an unfenced user
     * narrowing every screen to one branch for this request. Uses the same
     * fence as a fenced user, so every list, report and lookup follows it.
     */
    public function viewingBranch(int $branchId): self
    {
        return new self($this->tenantUser, $this->role, $branchId, $this->zoneIds, branchChosen: true);
    }

    /** Fenced to one branch by the owner (not merely viewing one). */
    public function isFenced(): bool
    {
        return $this->branchId !== null && ! $this->branchChosen;
    }

    /**
     * May run what is company-wide by design — the monthly billing run and
     * its publishing, opening balances (branches v2 D5): anyone not fenced to
     * a town, headquarters staff included.
     */
    public function actsForCompany(): bool
    {
        return ! $this->isFenced() || $this->branchId === Branch::headquartersId();
    }

    public function is(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAnyOf(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * The `super` bypass (Gate::before). Authoritative source is the
     * `roles.is_super` flag, not the string `$this->role === 'super'` — a
     * tenant could in principle rename which role holds it, and the flag is
     * the single guaranteed-unique marker (uq_roles_single_super).
     */
    public function isSuper(): bool
    {
        return $this->resolveRoleState()['is_super'];
    }

    /**
     * True if `$this->role` grants $permission (a value from
     * App\Auth\Permission). Always true for a super role — its stored rows
     * are irrelevant. Wave 2 rewrites the Policy classes to call this;
     * Wave 1 only wires it into the Gate and the frontend share.
     */
    public function can(string $permission): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return in_array($permission, $this->resolveRoleState()['permissions'], true);
    }

    public function canAny(string ...$permissions): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        $granted = $this->resolveRoleState()['permissions'];

        foreach ($permissions as $permission) {
            if (in_array($permission, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolved permission list for this role — `['*']` for a super role
     * (matching what HandleInertiaRequests::share() / Api\AuthController::me()
     * expose to the frontend), otherwise the exact set of granted strings.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->resolveRoleState()['is_super']) {
            return ['*'];
        }

        return $this->resolveRoleState()['permissions'];
    }

    /**
     * One query, joining `roles` -> `role_permissions` by the role name
     * `tenant_users.role` holds. Kept as a lean query builder call rather
     * than hydrating App\Models\Role — this runs on every authenticated
     * request (both middleware call resolve(), and the Gate/share read it),
     * so it stays as cheap as the branch/zone lookups already here.
     *
     * A schema with no `roles` table yet, or no row for this role name,
     * falls back to the built-in system-role defaults (see
     * defaultRoleState) so an unseeded deploy isn't bricked; a genuinely
     * unknown (never-created) role name still fails closed.
     *
     * @return array{is_super: bool, permissions: list<string>}
     */
    private function resolveRoleState(): array
    {
        if ($this->roleState !== null) {
            return $this->roleState;
        }

        // `roles` not yet created in this schema — the window between this
        // migration landing in the codebase and `tenants:migrate` running
        // (a fresh deploy whose release commands didn't run tenants:migrate,
        // or a tenant provisioned before the seed migration existed).
        // Checked with Schema::hasTable (a clean information_schema SELECT),
        // NOT a try/catch around the real query: a failed statement inside
        // a transaction poisons it on Postgres ("current transaction is
        // aborted"), which the transaction-wrapped feature-test suite runs
        // into on every authenticated request. Fall back to the built-in
        // system-role defaults (DefaultRolesSeeder — the exact sets the old
        // hardcoded checks granted) rather than failing closed: an unseeded
        // schema must NOT brick the app, and must never lock out `super`.
        if (! Schema::hasTable('roles')) {
            return $this->roleState = $this->defaultRoleState();
        }

        $rows = DB::table('roles')
            ->leftJoin('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.name', $this->role)
            ->get(['roles.is_super', 'role_permissions.permission']);

        // No `roles` row for this name at all — the table exists (someone's
        // migrated) but this role was never seeded, or `tenant_users.role`
        // holds a string that predates the roles table. Same fallback: a
        // known system-role name resolves to its default set; a genuinely
        // unknown custom name that was never created still fails closed.
        if ($rows->isEmpty()) {
            return $this->roleState = $this->defaultRoleState();
        }

        return $this->roleState = [
            'is_super' => (bool) $rows->first()->is_super,
            'permissions' => $rows->pluck('permission')->filter()->values()->all(),
        ];
    }

    /**
     * The role state to assume when this schema has no seeded `roles` data
     * for `$this->role` — the exact {is_super, permissions} the pre-RBAC-v2
     * hardcoded checks granted, taken from the single source of truth
     * (Database\Seeders\DefaultRolesSeeder::definitions()). A known system
     * role (`super`/`admin`/`manager`/`agent`/`worker`) resolves to its
     * default; anything else fails closed. This is a floor, not an
     * override: the moment `tenants:migrate` seeds the tables, the real
     * query above wins — including any customisation an admin has made.
     *
     * @return array{is_super: bool, permissions: list<string>}
     */
    private function defaultRoleState(): array
    {
        $definition = DefaultRolesSeeder::definitions()[$this->role] ?? null;

        if ($definition === null) {
            return ['is_super' => false, 'permissions' => []];
        }

        return [
            'is_super' => $definition['is_super'] ?? false,
            'permissions' => $definition['permissions'],
        ];
    }

    public function isCrossBranch(): bool
    {
        return $this->branchId === null;
    }

    /**
     * Defensive, container-lookup variant of reading ->branchId — for code
     * that must work whether or not a TenantContext has actually been
     * bound this request/process, rather than declaring it as a hard
     * constructor dependency. Needed specifically by
     * App\Services\ManuscriptService: it's a constructor dependency of
     * App\Console\Commands\ManuscriptCalculate, which Laravel resolves
     * eagerly while building the artisan command list — i.e. on every
     * artisan invocation, not just when manuscript:calculate itself runs —
     * long before any ResolveTenant/ResolveTenantWeb middleware could ever
     * bind a TenantContext. A hard constructor dependency there breaks
     * every artisan command, not just manuscript:calculate.
     * App\Repositories\Concerns\ScopesByBranch's repositories have the
     * same constraint for the same reason and delegate here too, rather
     * than duplicating the app()->bound() check in multiple places.
     */
    public static function currentBranchId(): ?int
    {
        return app()->bound(self::class) ? app(self::class)->branchId : null;
    }

    /**
     * Same defensive, container-lookup contract as currentBranchId() above
     * (see its doc comment for why), for `zoneIds`: the zones an agent
     * covers, or null when the caller is not zone-scoped.
     *
     * @return list<int>|null
     */
    public static function currentZoneIds(): ?array
    {
        return app()->bound(self::class) ? app(self::class)->zoneIds : null;
    }

    /**
     * Whether the caller may act in this zone: anyone not zone-scoped, or an
     * agent covering it.
     */
    public function coversZone(?int $zoneId): bool
    {
        return $this->zoneIds === null || ($zoneId !== null && in_array($zoneId, $this->zoneIds, true));
    }

    /** An agent covering this zone (the zone-scoped half of several office permissions). */
    public function isAgentCovering(?int $zoneId): bool
    {
        return $this->role === 'agent' && $this->zoneIds !== null && $zoneId !== null && in_array($zoneId, $this->zoneIds, true);
    }

    /**
     * The one zone to file something under when an agent doesn't say which —
     * e.g. a general (non-customer) complaint: their home zone if they still
     * cover it, else the first zone they cover. Null when not zone-scoped or
     * covering nothing.
     */
    public static function currentHomeZoneId(): ?int
    {
        if (! app()->bound(self::class) || app(self::class)->zoneIds === null) {
            return null;
        }

        $context = app(self::class);
        $homeZoneId = Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $context->tenantUser->user_id)->value('zone_id');

        return $homeZoneId !== null && in_array((int) $homeZoneId, $context->zoneIds, true)
            ? (int) $homeZoneId
            : ($context->zoneIds[0] ?? null);
    }

    /**
     * Single, shared place both App\Http\Middleware\ResolveTenant and
     * App\Http\Middleware\ResolveTenantWeb build a TenantContext from a
     * resolved TenantUser — see branches-and-locations.md section 4's
     * "Where branch_id is resolved per-request" note: this logic must live
     * in exactly one place, not be duplicated across the two middleware.
     *
     * For every role except `agent`, the fence is whatever the tenant
     * owner explicitly set on tenant_users.branch_id (null by default,
     * i.e. unrestricted).
     *
     * `agent`-role users get their fence "for free" through a separate,
     * already-existing mechanism instead: their own Agent row's zone_id,
     * transitively branch-scoped now that zones belong to branches (the
     * same "resolve this agent's own zone, filter to it" pattern
     * App\Services\CustomerEligibilityService already uses for the
     * flagged-customers board). This deliberately ignores
     * tenant_users.branch_id for agents — they're never expected to have
     * it set via the UI (see Settings/Users.tsx) — but if an agent has no
     * Agent row at all (e.g. a role flipped to 'agent' without ever
     * creating one, as some test fixtures do), falling back to
     * tenant_users.branch_id keeps behavior sane rather than crashing.
     */
    public static function resolve(TenantUser $tenantUser): self
    {
        // Resolved once and reused for both branchId and zoneIds below —
        // an agent's own Agent row is the single source of truth for both
        // fences (branch transitively via zone->branch, zone directly).
        $agent = $tenantUser->role === 'agent'
            ? Agent::query()->withoutGlobalScope(BranchScope::class)->with(['zone' => fn ($query) => $query->withoutGlobalScope(BranchScope::class)])->where('user_id', $tenantUser->user_id)->first()
            : null;

        $branchId = $tenantUser->role === 'agent'
            ? ($agent?->zone?->branch_id ?? $tenantUser->branch_id)
            : $tenantUser->branch_id;

        // The zones the agent covers (App\Models\ZoneAgent), one query. An
        // agent with no Agent row or no zones gets an empty list: zone-scoped
        // to nothing, never unrestricted. No other role is zone-scoped.
        $zoneIds = $tenantUser->role === 'agent'
            ? ($agent === null ? [] : ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
                ->where('agent_id', $agent->id)->orderBy('zone_id')->pluck('zone_id')->map(fn ($id): int => (int) $id)->all())
            : null;

        return new self($tenantUser, $tenantUser->role, $branchId, $zoneIds);
    }
}
