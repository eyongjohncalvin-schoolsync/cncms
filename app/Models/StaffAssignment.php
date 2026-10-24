<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a person worked, in which role, from when to when (branches v2 §4.4,
 * D7). One open row (ends_on null) per person; written only by
 * App\Services\StaffAssignmentService. branch_id null = not fenced to a branch.
 */
#[Fillable(['user_id', 'branch_id', 'role', 'starts_on', 'ends_on', 'changed_by', 'reason'])]
class StaffAssignment extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
