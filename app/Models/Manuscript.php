<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'bill', 'total_arrears', 'credit', 'total_bill', 'payment_expiration', 'period', 'command_run_id'])]
#[RouteKey('uuid')]
class Manuscript extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'bill' => 'decimal:2',
            'total_arrears' => 'decimal:2',
            'credit' => 'decimal:2',
            'total_bill' => 'decimal:2',
            'payment_expiration' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The `command_runs` row (command='manuscript:calculate') that wrote or
     * last overwrote this row — nullable, see this column's migration doc
     * comment for why pre-migration historical rows are NULL here. Used by
     * the Delete/Rollback action's precise-scoping DELETE (see
     * SettingsCommandRunController::rollback()); never a display-only
     * relation today.
     */
    public function commandRun(): BelongsTo
    {
        return $this->belongsTo(CommandRun::class);
    }
}
