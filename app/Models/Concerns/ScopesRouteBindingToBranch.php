<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Branch-fences implicit route-model binding ({customer}, {payment},
 * {agent}, {zone} URL segments) — the mechanism that makes a direct
 * "GET /customers/{branch-b-customer-uuid}" attempt actually 404 for a
 * branch-fenced user, not just get filtered out of list views. See
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4/7.
 *
 * This intentionally lives on the Model, not on a Policy — every Policy
 * class's ability logic (who-can-do-what per role) stays untouched; this is
 * a separate, row-level "does this uuid even resolve for you" gate that runs
 * before a controller action (and therefore before any Policy check) ever
 * sees the model. Overriding resolveRouteBindingQuery() is the single,
 * well-supported Laravel extension point for exactly this.
 *
 * Branches v2: every model using this carries its own branch_id (the row's
 * own, never re-derived through customer → zone), so the fence is one
 * indexed condition.
 *
 * Defensively no-ops when TenantContext isn't bound (matches
 * App\Repositories\Concerns\ScopesByBranch::currentBranchId()'s same
 * reasoning) and when the caller is cross-branch (branchId === null).
 */
trait ScopesRouteBindingToBranch
{
    /**
     * @param  Builder|Model  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $branchId = TenantContext::currentBranchId();

        return $branchId === null ? $query : $query->where($this->qualifyColumn('branch_id'), $branchId);
    }
}
