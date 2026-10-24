<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\BranchLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row that belongs to one branch (docs/plans/branches-v2.md §2 rule 1, §5).
 *
 * The branch is stamped once, on the server, when the row is created — from
 * the parent named by branchParent() (a customer's zone, a payment's
 * customer, …) unless the creating service already set it — and never
 * changes afterwards, so a report grouped by branch_id stays true after
 * zones, customers or staff move. branch_id is deliberately left out of
 * mass assignment on rows that derive it, so no request can choose it.
 *
 * A model whose branch is its CURRENT location rather than history
 * (Customer, Agent) returns true from branchFollowsParent(): its branch_id is
 * re-derived when its parent key changes, and the database keeps it equal to
 * its zone's (composite foreign key, cascading when a zone changes branch).
 *
 * The parent's branch comes from, in order: a loaded parent relation,
 * App\Support\BranchLookup (primed by bulk writers), one query (remembered).
 */
trait BelongsToBranch
{
    /**
     * The belongsTo relation whose row carries this row's branch_id, or null
     * when the creating service always sets branch_id itself.
     */
    abstract protected function branchParent(): ?string;

    protected static function branchFollowsParent(): bool
    {
        return false;
    }

    protected static function bootBelongsToBranch(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('branch_id') === null) {
                $model->setAttribute('branch_id', $model->branchIdFromParent());
            }

            if ($model->getAttribute('branch_id') === null) {
                throw new LogicException($model::class.' was created without a branch.');
            }
        });

        static::updating(function (Model $model): void {
            $parent = $model->branchParent();

            if (static::branchFollowsParent() && $parent !== null && $model->isDirty($model->{$parent}()->getForeignKeyName())) {
                $model->unsetRelation($parent);
                $model->setAttribute('branch_id', $model->branchIdFromParent());

                return;
            }

            if ($model->isDirty('branch_id')) {
                throw new LogicException($model::class.'::branch_id is set when the row is created and never changes.');
            }
        });

        if (static::branchFollowsParent()) {
            static::updated(static function (Model $model): void {
                if ($model->wasChanged('branch_id')) {
                    BranchLookup::forget($model::class, (int) $model->getKey());
                }
            });
        }
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Rows of one branch; null (every branch) is a no-op. */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId === null ? $query : $query->where($this->qualifyColumn('branch_id'), $branchId);
    }

    protected function branchIdFromParent(): ?int
    {
        $parent = $this->branchParent();

        if ($parent === null) {
            return null;
        }

        /** @var BelongsTo $relation */
        $relation = $this->{$parent}();
        $key = $this->getAttribute($relation->getForeignKeyName());

        if ($key === null) {
            return null;
        }

        if ($this->relationLoaded($parent) && $this->getRelation($parent)?->getAttribute('branch_id') !== null) {
            return (int) $this->getRelation($parent)->getAttribute('branch_id');
        }

        $parentClass = $relation->getRelated()::class;
        $branchId = BranchLookup::get($parentClass, (int) $key);

        if ($branchId === null) {
            $branchId = $relation->getQuery()->value('branch_id');

            if ($branchId !== null) {
                BranchLookup::rememberIds($parentClass, [(int) $key => (int) $branchId]);
            }
        }

        return $branchId !== null ? (int) $branchId : null;
    }
}
