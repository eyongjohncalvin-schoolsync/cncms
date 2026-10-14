<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A locked month (YYYY-MM). Once locked, nothing can be dated inside it —
 * see App\Services\PeriodLockService.
 */
#[Fillable(['period', 'status', 'locked_by', 'locked_at', 'note'])]
class AccountingPeriod extends Model
{
    use Auditable, HasUuid;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
