<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Branch-fences implicit route-model binding ({customer}, {payment},
 * {agent}, {zone} URL segments) — the mechanism that makes a direct
 * "GET /customers/{branch-b-customer-uuid}" attempt actually 404 for a
 * branch-fenced user, not just get filtered out of list views. See
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 4/7.
 *
 * This intentionally lives on the Model, not on a Policy — every Policy
 * class's ability logic (who-can-do-what per role) stays untouched per the
 * feature's constraints; this is a separate, row-level "does this uuid
 * even resolve for you" gate that runs before a controller action (and
 * therefore before any Policy check) ever sees the model. Overriding
 * resolveRouteBindingQuery() is the single, well-supported Laravel
 * extension point for exactly this ("scope route model binding to the
 * current tenant/user"), and — unlike a global Eloquent scope, which the
 * design doc rejected as "too magic" — it only affects resolving a uuid
 * from a route segment, not Customer::query() or any other query
 * elsewhere in the app.
 *
 * Defensively no-ops when TenantContext isn't bound (matches
 * App\Repositories\Concerns\ScopesByBranch::currentBranchId()'s same
 * reasoning) and when the caller is cross-branch (branchId === null).
 */
trait ScopesRouteBindingToBranch
{
    /**
     * Relation path (dot-notation for a multi-hop path) from this model to
     * the Zone carrying branch_id — e.g. 'zone' (default, Customer/Agent)
     * or 'customer.zone' (Payment). Return null to scope directly on this
     * model's own branch_id column instead (Zone).
     */
    protected static function branchRouteBindingRelation(): ?string
    {
        return 'zone';
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (! app()->bound(TenantContext::class)) {
            return $query;
        }

        $branchId = app(TenantContext::class)->branchId;

        if ($branchId === null) {
            return $query;
        }

        $relation = static::branchRouteBindingRelation();

        return $relation === null
            ? $query->where('branch_id', $branchId)
            : $query->whereHas($relation, fn (Builder $inner) => $inner->where('branch_id', $branchId));
    }
}
