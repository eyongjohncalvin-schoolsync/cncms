<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The branch fence (docs/plans/branches-v2.md §5): while a branch-fenced
 * user's request is being served, every query on a branch-owned model only
 * sees that branch's rows — lists, counts, exports, lookups and route
 * binding alike — so a forgotten filter can't leak another town's data.
 *
 * A no-op for unfenced users (owner, HQ-level staff, investors), artisan
 * commands, queue jobs and scheduled tasks (no fenced TenantContext bound).
 * The few services that must see across branches — company-wide limits,
 * headquarters accounts, idempotency checks — say so explicitly with
 * withoutGlobalScope(BranchScope::class).
 */
final class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branchId = TenantContext::currentBranchId();

        if ($branchId !== null) {
            $builder->where($model->qualifyColumn('branch_id'), $branchId);
        }
    }
}
