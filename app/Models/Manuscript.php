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
            // `date:Y-m-d`, not bare `date`: the bare cast serializes to a
            // full ISO-8601 datetime ("2026-12-29T00:00:00.000000Z") in
            // Inertia props / API responses, which then renders raw in the
            // Manuscripts "Expiry" column and Customers/Show. The PHP-side
            // value is still a Carbon instance either way (ManuscriptCalculator,
            // CustomerStatusService, the PDF register's ->format('M y') are
            // unaffected) — only the JSON shape changes, to a plain date.
            'payment_expiration' => 'date:Y-m-d',
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
