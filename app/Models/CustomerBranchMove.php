<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer moving to another branch (branches v2 §4.3, D11): dated and
 * recorded; the new branch bills from effective_period (the next month), and
 * everything before stays with the old branch. Written by
 * App\Models\Customer when its branch changes.
 */
#[Fillable(['customer_id', 'from_branch_id', 'to_branch_id', 'from_zone_id', 'to_zone_id', 'effective_period', 'moved_by', 'reason'])]
class CustomerBranchMove extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }
}
