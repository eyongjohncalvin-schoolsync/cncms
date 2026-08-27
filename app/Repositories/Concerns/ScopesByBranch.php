<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Shared branch-fencing filter for every Repository that lists rows
 * reachable (directly or via a `zone`) from a branch — see
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4's "Enforcement mechanism": explicit when()-style filtering in
 * each Repository, backed by exactly this one trait, rather than every
 * Repository hand-rolling its own version (the "forgot to scope this one
 * query" risk the doc calls out).
 *
 * Deliberately NOT a global Eloquent scope (rejected in the design doc as
 * "too magic") and deliberately does NOT constructor-inject TenantContext
 * — Repository classes are also resolved outside any tenant HTTP request
 * (e.g. App\Console\Commands\ManuscriptCalculate injects ManuscriptService,
 * which resolves ManuscriptRepositoryInterface, at command-construction
 * time, before any ResolveTenant/ResolveTenantWeb middleware could ever
 * bind one). Forcing
 * a hard TenantContext dependency there would break every artisan
 * invocation; currentBranchId() resolves it defensively instead and falls
 * back to "unrestricted", the same safe direction as an unset
 * tenant_users.branch_id.
 */
trait ScopesByBranch
{
    /**
     * Scopes $query to rows whose `$zoneRelation` (a model relation name,
     * dot-notation supported for a multi-hop path — e.g. 'zone' for
     * Customer/Agent, 'customer.zone' for Payment/Manuscript) belongs to
     * branch $branchId. A null $branchId (cross-branch/unrestricted) is a
     * no-op, matching TenantContext's "null = sees every branch" contract.
     */
    protected function scopeToBranch(Builder $query, ?int $branchId, string $zoneRelation = 'zone'): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->whereHas($zoneRelation, fn (Builder $inner) => $inner->where('branch_id', $branchId));
    }

    /**
     * Same contract as scopeToBranch(), for a model that carries branch_id
     * directly (currently only Zone) — no relation join needed.
     */
    protected function scopeToBranchDirect(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    /**
     * The caller's effective branch fence for this request, or null
     * (unrestricted) when no TenantContext is bound at all — see this
     * trait's class doc for why that's resolved defensively here rather
     * than via constructor injection. Delegates to
     * TenantContext::currentBranchId() so this same defensive lookup isn't
     * duplicated in more than one place.
     */
    protected function currentBranchId(): ?int
    {
        return TenantContext::currentBranchId();
    }
}
