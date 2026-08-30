<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['customer_id', 'bill', 'total_arrears', 'credit', 'total_bill', 'payment_expiration', 'prepaid_months_remaining', 'prepaid_rate', 'period', 'command_run_id'])]
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
            'prepaid_months_remaining' => 'integer',
            'prepaid_rate' => 'decimal:2',
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
     * The single source of truth for the "covered through" / expiry label
     * shown in the manuscript register (both the PDF blade and
     * App\Exports\ManuscriptRegisterExport render this so the two exports can
     * never disagree). A post-paid customer carries an explicit
     * payment_expiration date; a prepaid customer carries only a month
     * counter (references/prepayment-drawdown.md), so derive the covered-
     * through month from this row's own period + prepaid_months_remaining.
     */
    public function expiryLabel(): string
    {
        if ($this->payment_expiration !== null) {
            return $this->payment_expiration->format('M y');
        }

        $prepaidMonths = (int) $this->prepaid_months_remaining;

        if ($prepaidMonths > 0) {
            return Carbon::createFromFormat('Y-m', $this->period)
                ->addMonthsNoOverflow($prepaidMonths)
                ->format('M y');
        }

        return '-';
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
