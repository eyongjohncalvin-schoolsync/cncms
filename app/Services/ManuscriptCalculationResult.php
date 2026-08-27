<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArrearsAdjustment;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Immutable value object returned by ManuscriptCalculator::calculate().
 *
 * Carries everything needed to upsert a `manuscripts` row for one customer for
 * one period, plus the set of payments AND arrears adjustments that were
 * consumed by the calculation (and therefore need `processed_at` AND
 * `processed_period` stamped — see App\Services\ManuscriptCalculator's class
 * doc for why both), and enough bookkeeping flags for callers (the
 * manuscript:calculate command, future P&L/reporting code) to build run
 * statistics without recomputing anything.
 */
final readonly class ManuscriptCalculationResult
{
    /**
     * @param  Collection<int, Payment>  $processedPayments  Payments whose amount was factored
     *                                                       into this calculation and that should be stamped with
     *                                                       `processed_at` (a display/audit timestamp only) and
     *                                                       `processed_period` (period-attributed eligibility marker —
     *                                                       see App\Services\ManuscriptCalculator's class doc).
     * @param  Collection<int, ArrearsAdjustment>  $processedAdjustments  Arrears adjustments whose
     *                                                       amount was factored into this calculation and that should
     *                                                       be stamped the same way — see
     *                                                       App\Services\ManuscriptCalculator's class doc for why,
     *                                                       unlike $processedPayments, this is populated even in the
     *                                                       frozen branches.
     */
    public function __construct(
        public string $bill,
        public string $totalArrears,
        public string $credit,
        public string $totalBill,
        public ?Carbon $paymentExpiration,
        public string $income,
        public string $adjustmentNet,
        public bool $isFirstRun,
        public bool $isFrozen,
        public ?string $frozenReason,
        public Collection $processedPayments,
        public Collection $processedAdjustments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toManuscriptAttributes(): array
    {
        return [
            'bill' => $this->bill,
            'total_arrears' => $this->totalArrears,
            'credit' => $this->credit,
            'total_bill' => $this->totalBill,
            'payment_expiration' => $this->paymentExpiration,
        ];
    }
}
