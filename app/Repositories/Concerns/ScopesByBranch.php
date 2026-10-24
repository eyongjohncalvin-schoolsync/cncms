<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Shared branch-fencing filter for every Repository that lists branch-owned
 * rows — one trait rather than every Repository hand-rolling its own version
 * (the "forgot to scope this one query" risk).
 *
 * Branches v2 (docs/plans/branches-v2.md §6): every branch-owned row carries
 * its own indexed branch_id, so the fence is one condition on the row's own
 * column — no join through customer → zone.
 *
 * Deliberately does NOT constructor-inject TenantContext — Repository classes
 * are also resolved outside any tenant HTTP request (e.g.
 * App\Console\Commands\ManuscriptCalculate injects ManuscriptService, which
 * resolves ManuscriptRepositoryInterface, at command-construction time,
 * before any ResolveTenant/ResolveTenantWeb middleware could ever bind one).
 * currentBranchId() resolves it defensively instead and falls back to
 * "unrestricted", the same safe direction as an unset tenant_users.branch_id.
 */
trait ScopesByBranch
{
    /**
     * Scopes $query to rows of branch $branchId. A null $branchId
     * (cross-branch/unrestricted) is a no-op, matching TenantContext's
     * "null = sees every branch" contract. The column is qualified with the
     * query's own table, so joins added later can't make it ambiguous.
     */
    protected function scopeToBranchDirect(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn('branch_id'), $branchId);
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
