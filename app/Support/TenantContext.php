<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agent;
use App\Models\TenantUser;
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
 * `zoneId` is the same kind of row-level fence, one level narrower than
 * `branchId` — it backs PaymentPolicy::verify()'s "an agent may verify a
 * payment only for a customer in their own zone" rule. Resolved the same
 * direct way as branchId: for `agent` role, their own Agent row's
 * `zone_id`; every other role gets `null` (unrestricted — no other role is
 * zone-scoped, unlike branchId which every role can be fenced to via
 * tenant_users.branch_id).
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

    public function __construct(
        public readonly TenantUser $tenantUser,
        public readonly string $role,
        public readonly ?int $branchId = null,
        public readonly ?int $zoneId = null,
    ) {}

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
     * A role name with no matching `roles` row (a schema mid-migration
     * before the seed ran, or a hand-set `tenant_users.role` string that
     * doesn't exist) fails closed: not super, zero permissions.
     *
     * @return array{is_super: bool, permissions: list<string>}
     */
    private function resolveRoleState(): array
    {
        if ($this->roleState !== null) {
            return $this->roleState;
        }

        // `roles` not yet created in this schema — the window between this
        // migration landing in the codebase and `tenants:migrate` running.
        // Checked with Schema::hasTable (a clean information_schema SELECT),
        // NOT a try/catch around the real query: a failed statement inside
        // a transaction poisons it on Postgres ("current transaction is
        // aborted"), which the transaction-wrapped feature-test suite runs
        // into on every authenticated request. Fail closed — Wave 1 changes
        // no Policy, so this degrades to exactly today's behaviour.
        if (! Schema::hasTable('roles')) {
            return $this->roleState = ['is_super' => false, 'permissions' => []];
        }

        $rows = DB::table('roles')
            ->leftJoin('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.name', $this->role)
            ->get(['roles.is_super', 'role_permissions.permission']);

        if ($rows->isEmpty()) {
            return $this->roleState = ['is_super' => false, 'permissions' => []];
        }

        return $this->roleState = [
            'is_super' => (bool) $rows->first()->is_super,
            'permissions' => $rows->pluck('permission')->filter()->values()->all(),
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
     * (see its doc comment for why), for `zoneId` — needed by
     * App\Services\PaymentVerificationService::verifyMany()'s per-item
     * zone re-check, which must keep working however PaymentVerificationService
     * ends up being resolved.
     */
    public static function currentZoneId(): ?int
    {
        return app()->bound(self::class) ? app(self::class)->zoneId : null;
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
        // Resolved once and reused for both branchId and zoneId below —
        // an agent's own Agent row is the single source of truth for both
        // fences (branch transitively via zone->branch, zone directly).
        $agent = $tenantUser->role === 'agent'
            ? Agent::query()->with('zone')->where('user_id', $tenantUser->user_id)->first()
            : null;

        $branchId = $tenantUser->role === 'agent'
            ? ($agent?->zone?->branch_id ?? $tenantUser->branch_id)
            : $tenantUser->branch_id;

        // Unlike branchId, no fallback to a tenant_users column here — no
        // other role is ever zone-scoped, and an agent with no Agent row
        // (or an Agent row with no zone) simply has no zone fence (null,
        // i.e. PaymentPolicy::verify() falls through to false for them
        // rather than granting/denying based on a nonexistent zone).
        $zoneId = $tenantUser->role === 'agent' ? $agent?->zone_id : null;

        return new self($tenantUser, $tenantUser->role, $branchId, $zoneId);
    }
}
