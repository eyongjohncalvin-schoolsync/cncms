<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One salary decision for a person (branches v2 §4.5, D8) — append-only. The
 * latest approved row is their salary; a pending_hq row waits for
 * headquarters because it is outside its grade's range. Written only by
 * App\Services\SalaryService.
 */
#[Fillable([
    'user_id', 'branch_id', 'salary_grade_id', 'amount', 'effective_from', 'status', 'reason',
    'set_by', 'decided_by', 'decided_at', 'decision_note',
])]
#[RouteKey('uuid')]
class StaffSalary extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_PENDING = 'pending_hq';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(SalaryGrade::class, 'salary_grade_id');
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

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
