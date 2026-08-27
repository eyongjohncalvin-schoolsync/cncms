<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly manuscript billing engine.
 *
 * Computes, for a single customer and a single 'YYYY-MM' period, the arrears,
 * credit, and total_bill that `manuscript:calculate` should write to the
 * `manuscripts` table — without touching the database itself. The caller
 * (currently the manuscript:calculate console command) is responsible for
 * persisting the returned ManuscriptCalculationResult and for stamping
 * `processed_at`/`processed_period` on the payments/adjustments it says were
 * consumed.
 *
 * Ledger model
 * ------------
 * Only `verification_status = 'verified'` payments the caller has determined
 * are ELIGIBLE for this period are treated as income — pending and rejected
 * payments never affect billing (business-rules.md #2, #9). Eligibility
 * itself is the caller's concern (see the $eligibleVerifiedPayments param
 * below and App\Support\ScheduledTasks\ManuscriptChunkDataResolver /
 * App\Console\Commands\ManuscriptCalculate, which both resolve it the same
 * way): a payment is eligible for period P when it has never yet been
 * consumed by any period's calculation (`processed_period IS NULL` — this is
 * what lets a frozen customer's payment carry forward untouched across
 * however many disconnected/passive/prepaid periods pass before it's finally
 * consumed) OR it was already consumed by this SAME period P
 * (`processed_period = P`), which is what makes re-running P idempotent —
 * a second run for the same period sees the identical payment set as the
 * first, not zero. `processed_period` is stamped, alongside the existing
 * `processed_at` timestamp, once a payment is actually consumed (see
 * ManuscriptCalculationResult's doc comment) — this replaced a plain
 * `processed_at IS NULL` boolean check, which could not distinguish "never
 * consumed" from "already consumed by this exact period," and silently
 * fabricated a full period's worth of phantom arrears on any re-run.
 *
 * $eligibleAdjustments (the Arrears Adjustment feature) uses the IDENTICAL
 * idempotency mechanism, applied to App\Models\ArrearsAdjustment instead of
 * Payment: eligible for period P when `status = 'approved' AND target_period
 * = P AND (processed_period IS NULL OR processed_period = P)` — resolved by
 * the caller (see App\Support\ScheduledTasks\ManuscriptChunkDataResolver and
 * App\Console\Commands\ManuscriptCalculate, which both resolve it the same
 * way, exactly like $eligibleVerifiedPayments). Unlike payments, an eligible
 * adjustment is consumed in EVERY branch below, including the frozen ones —
 * see "Special cases" below.
 *
 * Each period carries forward a single net figure from the previous period:
 *
 *     previousNet = previousManuscript.total_arrears - previousManuscript.credit
 *
 * (or, for a customer's very first manuscript ever, previousNet = customers.others
 * — the imported seed balance, applied exactly once per business-rules.md #8).
 *
 *     net = previousNet + (bill - income) ± adjustmentTotal
 *
 * where adjustmentTotal is the signed sum of this period's eligible
 * adjustments — a 'decrease' adjustment subtracts from net (reduces what the
 * customer owes), an 'increase' adjustment adds to it. A positive net is
 * arrears (still owed); a negative net becomes credit. This is what makes
 * credit "consumed before arrears": leftover credit from an overpayment
 * reduces `previousNet` below zero, which directly offsets next period's
 * bill before any new arrears can accrue. total_bill is then
 * `bill + total_arrears - credit`, clamped to a minimum of 0, matching the
 * core formula in database-schema.md.
 *
 * Special cases (business-rules.md #6, #7, #8):
 * - `disconnected`, `passive`, and `suspended` customers accrue no NEW
 *   charge (no bill, no income) — but an approved arrears adjustment still
 *   applies: `net = previousNet ± adjustmentTotal`, split into
 *   totalArrears/credit the same way as any other period, with total_bill
 *   still forced to 0 (freezing means no CHARGE is due while frozen, not
 *   that a correction can't land). This is the Arrears Adjustment feature's
 *   central use case — a disconnected customer's stale, wrong arrears figure
 *   is real and must be fixable without first reconnecting them. No payments
 *   are consumed here (so a reconnection payment recorded while still
 *   disconnected/suspended is picked up cleanly by the next normal run
 *   instead of being silently absorbed while frozen) — but eligible
 *   adjustments ARE consumed here, every time, since there is no later
 *   "unfrozen" run that would otherwise ever pick them up for a customer who
 *   stays frozen indefinitely. `suspended` was added alongside
 *   `disconnected` because payments are already blocked for both statuses
 *   (StorePaymentRequest, PaymentService::createMany(), SyncService) — a
 *   suspended customer had no way to pay down arrears that kept accruing
 *   every period, which could balloon into a large, effectively unpayable
 *   debt over a long suspension. See CustomerStatusService::reconnectOne()'s
 *   doc comment for the reconnection FINE, which is a separate, admin-
 *   discretion opt-in concern (2026-08 owner decision) uniformly for either
 *   status — nothing about it varies between `disconnected` and `suspended`
 *   any more, unlike the arrears-freezing behavior above.
 * - A customer with a `months`/`yearly` frequency payment sets/extends
 *   `payment_expiration`. While that date is still in the future, billing is
 *   frozen the same way (no new bill/income applied) — the prepayment's
 *   value is "spent" via the expiration mechanism, not the arrears/credit
 *   ledger — but, exactly like the disconnected/passive/suspended branch
 *   above, an eligible arrears adjustment still applies and is still
 *   consumed. Only the payment(s) that established the expiration are
 *   marked processed; normal monthly billing resumes automatically once
 *   `payment_expiration` is no longer in the future.
 */
final class ManuscriptCalculator
{
    private const SCALE = 2;

    /**
     * @param  ?Manuscript  $previousManuscript  The customer's most recent manuscript row with
     *                                           `period` < $period (or null if this is their first
     *                                           manuscript ever), resolved by the caller — see
     *                                           App\Console\Commands\ManuscriptCalculate, which
     *                                           batch-resolves this per chunk instead of one query
     *                                           per customer.
     * @param  Collection<int, Payment>  $eligibleVerifiedPayments  This customer's
     *                                                               `verification_status = 'verified'` payments eligible
     *                                                               for period $period — see this class's doc comment for
     *                                                               exactly what "eligible" means and why — resolved by
     *                                                               the caller the same way.
     * @param  Collection<int, ArrearsAdjustment>  $eligibleAdjustments  This customer's `status =
     *                                                               'approved'` arrears adjustments eligible for period
     *                                                               $period — see this class's doc comment for exactly
     *                                                               what "eligible" means, resolved by the caller the
     *                                                               same way as $eligibleVerifiedPayments.
     */
    public function calculate(
        Customer $customer,
        string $period,
        ?Manuscript $previousManuscript,
        Collection $eligibleVerifiedPayments,
        Collection $eligibleAdjustments,
        ?Carbon $asOf = null,
    ): ManuscriptCalculationResult {
        $asOf ??= Carbon::now();

        $billDue = $this->normalize((string) $customer->bill);

        $isFirstRun = $previousManuscript === null;

        $previousArrears = $isFirstRun
            ? $this->normalize((string) $customer->others)
            : $this->normalize((string) $previousManuscript->total_arrears);

        $previousCredit = $isFirstRun
            ? '0.00'
            : $this->normalize((string) $previousManuscript->credit);

        $previousExpiration = $isFirstRun ? null : $previousManuscript->payment_expiration;

        $adjustmentNet = $this->adjustmentNet($eligibleAdjustments);
        $processedAdjustments = $eligibleAdjustments->values();

        if (in_array($customer->status, ['disconnected', 'passive', 'suspended'], true)) {
            [$totalArrears, $credit] = $this->splitNet($this->add($this->sub($previousArrears, $previousCredit), $adjustmentNet));

            return new ManuscriptCalculationResult(
                bill: $billDue,
                totalArrears: $totalArrears,
                credit: $credit,
                totalBill: '0.00',
                paymentExpiration: $previousExpiration ? Carbon::parse($previousExpiration) : null,
                income: '0.00',
                adjustmentNet: $adjustmentNet,
                isFirstRun: $isFirstRun,
                isFrozen: true,
                frozenReason: $customer->status,
                processedPayments: new Collection,
                processedAdjustments: $processedAdjustments,
            );
        }

        $expiringPayments = $eligibleVerifiedPayments
            ->filter(fn (Payment $payment): bool => $payment->expiration_date !== null);

        $candidateExpiration = $expiringPayments
            ->map(fn (Payment $payment): Carbon => Carbon::parse($payment->expiration_date))
            ->when($previousExpiration, fn (Collection $dates) => $dates->push(Carbon::parse($previousExpiration)))
            ->max();

        if ($candidateExpiration !== null && $candidateExpiration->greaterThan($asOf)) {
            [$totalArrears, $credit] = $this->splitNet($this->add($this->sub($previousArrears, $previousCredit), $adjustmentNet));

            return new ManuscriptCalculationResult(
                bill: $billDue,
                totalArrears: $totalArrears,
                credit: $credit,
                totalBill: '0.00',
                paymentExpiration: $candidateExpiration,
                income: '0.00',
                adjustmentNet: $adjustmentNet,
                isFirstRun: $isFirstRun,
                isFrozen: true,
                frozenReason: 'prepaid',
                processedPayments: $expiringPayments->values(),
                processedAdjustments: $processedAdjustments,
            );
        }

        $income = $eligibleVerifiedPayments->reduce(
            fn (string $carry, Payment $payment): string => $this->add($carry, $this->normalize((string) $payment->amount)),
            '0.00'
        );

        $net = $this->add(
            $this->add($this->sub($previousArrears, $previousCredit), $this->sub($billDue, $income)),
            $adjustmentNet,
        );

        [$totalArrears, $credit] = $this->splitNet($net);

        $totalBill = $this->add($billDue, $this->sub($totalArrears, $credit));

        if ($this->compare($totalBill, '0.00') < 0) {
            $totalBill = '0.00';
        }

        return new ManuscriptCalculationResult(
            bill: $billDue,
            totalArrears: $totalArrears,
            credit: $credit,
            totalBill: $totalBill,
            paymentExpiration: null,
            income: $income,
            adjustmentNet: $adjustmentNet,
            isFirstRun: $isFirstRun,
            isFrozen: false,
            frozenReason: null,
            processedPayments: $eligibleVerifiedPayments->values(),
            processedAdjustments: $processedAdjustments,
        );
    }

    /**
     * Signed sum of this period's eligible adjustments: 'decrease' amounts
     * subtract, 'increase' amounts add — see this class's doc comment for
     * the ± adjustmentTotal formula.
     *
     * @param  Collection<int, ArrearsAdjustment>  $eligibleAdjustments
     */
    private function adjustmentNet(Collection $eligibleAdjustments): string
    {
        return $eligibleAdjustments->reduce(
            function (string $carry, ArrearsAdjustment $adjustment): string {
                $amount = $this->normalize((string) $adjustment->amount);

                return $adjustment->direction === 'increase'
                    ? $this->add($carry, $amount)
                    : $this->sub($carry, $amount);
            },
            '0.00'
        );
    }

    /**
     * Splits a net ledger figure into (totalArrears, credit): a positive net
     * is still-owed arrears; a negative (or zero) net becomes credit —
     * shared by every branch above so the split logic can never drift
     * between the frozen and non-frozen paths.
     *
     * @return array{0: string, 1: string}
     */
    private function splitNet(string $net): array
    {
        if ($this->compare($net, '0.00') > 0) {
            return [$net, '0.00'];
        }

        return ['0.00', $this->negate($net)];
    }

    private function normalize(string $value): string
    {
        return bcadd($value, '0.00', self::SCALE);
    }

    private function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    private function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    private function negate(string $a): string
    {
        return bcsub('0.00', $a, self::SCALE);
    }

    private function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }
}
