<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A closed (or reopened) expenditure month (E6 of
 * docs/plans/expenditures-and-spending-controls.md). Closed on the 5th of
 * the following month (Cameroon time) with a snapshot of its totals; only
 * the owner reopens, with a reason. A month with no row has never closed.
 * App\Services\ExpenditureMonthService holds the rules.
 */
#[Fillable(['period', 'closed_at', 'closed_by', 'snapshot', 'reopened_by', 'reopened_at', 'reopen_reason'])]
class ExpenditureMonth extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    // Cross-schema — User is pinned to the central connection.
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
