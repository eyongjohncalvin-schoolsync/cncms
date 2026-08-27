<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agent;
use App\Models\TenantUser;

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
