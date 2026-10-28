<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\DataTransferObjects\RejectArrearsAdjustmentData;
use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\User;
use App\Support\Billing\ConnectionMonth;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BusinessTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer registered after their connection month had closed (owner,
 * 2026-10-26). Their free months count from the real connection; the
 * months that then passed before registration were never billed — by
 * default nothing is charged. An admin may charge them as old debt through
 * an ordinary arrears adjustment (maker ≠ checker, applied to the current
 * bill — closed months are never rewritten):
 *
 *  - `missed`: only the months after the free ones;
 *  - `all`: every month since connection, and the free months end — the
 *    customer is treated as never having had them (applied on approval).
 */
class LateRegistrationService
{
    public const string REASON_CATEGORY = 'late_registration';

    /** An approved old-debt charge undone (reverse()). */
    public const string REVERSAL_CATEGORY = 'late_registration_reversal';

    /** The cancel reason endFreeMonths() stamps — and restoreFreeMonths() looks for. */
    private const string FREE_MONTHS_ENDED = 'Late registration: charged since connection';

    public function __construct(
        private readonly ArrearsAdjustmentService $adjustments,
        private readonly FreeMonthGrantService $grants,
        private readonly ManuscriptRunLockService $lock,
    ) {}

    /**
     * The months between connection and the first bill, priced at the
     * customer's services: which were free, which passed unbilled, and any
     * charge already asked for. Null when the customer was not late.
     *
     * @return array{connection_month: string, billing_starts: string, months: list<array{period: string, free: bool, amount: string}>, missed_months: int, missed_amount: string, all_months: int, all_amount: string, free_months_running: bool, reason: ?string, connected_by: ?string, says_paid_amount: ?string, says_paid_to: ?string, charge: ?array{uuid: string, amount: string, status: string, cancels_free_months: bool}}|null
     */
    public function summary(Customer $customer): ?array
    {
        if ($customer->connected_on === null || $customer->billing_starts_period === null) {
            return null;
        }

        $connectionMonth = ConnectionMonth::of($customer->connected_on->toDateString());
        $billingStarts = (string) $customer->billing_starts_period;

        if ($connectionMonth >= $billingStarts) {
            return null;
        }

        $plan = FreeMonthPlan::forCustomer($customer);
        $months = [];

        for ($period = $connectionMonth; $period < $billingStarts; $period = FreeMonthGrant::addMonths($period, 1)) {
            $missed = '0.00';
            $full = '0.00';

            foreach ($plan->servicePrices as $serviceId => $price) {
                $full = bcadd($full, (string) $price, 2);

                if (! $plan->isServiceFree((int) $serviceId, $period)) {
                    $missed = bcadd($missed, (string) $price, 2);
                }
            }

            $months[] = ['period' => $period, 'free' => bccomp($missed, '0', 2) === 0, 'amount' => $missed, 'full' => $full];
        }

        $charge = ArrearsAdjustment::query()
            ->where('customer_id', $customer->id)
            ->where('reason_category', self::REASON_CATEGORY)
            ->where('status', '!=', 'rejected')
            ->latest('id')
            ->first();
        $reversal = $charge === null ? null : ArrearsAdjustment::query()
            ->where('reverses_adjustment_id', $charge->id)
            ->where('status', '!=', 'rejected')
            ->latest('id')
            ->first();

        return [
            'connection_month' => $connectionMonth,
            'billing_starts' => $billingStarts,
            'months' => array_map(fn (array $m): array => ['period' => $m['period'], 'free' => $m['free'], 'amount' => $m['amount']], $months),
            'missed_months' => count(array_filter($months, fn (array $m): bool => bccomp($m['amount'], '0', 2) > 0)),
            'missed_amount' => array_reduce($months, fn (string $carry, array $m): string => bcadd($carry, $m['amount'], 2), '0.00'),
            'all_months' => count($months),
            'all_amount' => array_reduce($months, fn (string $carry, array $m): string => bcadd($carry, $m['full'], 2), '0.00'),
            'free_months_running' => $this->runningInstallationGrants($customer) !== [],
            'reason' => $customer->late_registration_reason,
            // Auditing: who connected them, and what they say they paid and to whom.
            'connected_by' => $customer->connected_by_user_id !== null ? User::query()->whereKey($customer->connected_by_user_id)->value('name') : null,
            'says_paid_amount' => $customer->customer_says_paid_amount !== null ? (string) $customer->customer_says_paid_amount : null,
            'says_paid_to' => $customer->customer_says_paid_to,
            'charge' => $charge === null ? null : [
                'uuid' => $charge->uuid,
                'amount' => (string) $charge->amount,
                'status' => $charge->status,
                'cancels_free_months' => (bool) $charge->cancels_free_months,
                'requested_by_id' => $charge->requested_by,
                // Undone by an approved credit (reverse()); null status = no reversal asked.
                'reversed' => $reversal?->status === 'approved',
                'reversal_status' => $reversal?->status,
                'reversal_uuid' => $reversal?->uuid,
            ],
        ];
    }

    /**
     * Ask to charge the late months as old debt — an ordinary arrears
     * adjustment on the current bill, approved by someone else.
     */
    public function charge(Customer $customer, string $scope, string $amount, string $note, User $actor): ArrearsAdjustment
    {
        $summary = $this->summary($customer);

        if ($summary === null) {
            throw ValidationException::withMessages(['amount' => 'This customer was registered in the month they were connected — there is nothing to charge.']);
        }

        if ($summary['charge'] !== null && ! $summary['charge']['reversed']) {
            throw ValidationException::withMessages(['amount' => 'The late months were already charged (or are waiting for approval).']);
        }

        $adjustment = $this->adjustments->create(new ArrearsAdjustmentData(
            customerUuid: $customer->uuid,
            targetPeriod: $this->lock->currentPeriod(),
            direction: 'increase',
            amount: bcadd($amount, '0', 2),
            reasonCategory: self::REASON_CATEGORY,
            reasonNote: $note,
        ), $actor->id);

        if ($scope === 'all') {
            $adjustment->forceFill(['cancels_free_months' => true])->save();
        }

        return $adjustment;
    }

    /**
     * "Charge every month since connection", once approved: the free months
     * end — none from the current month on. Months already passed stay as
     * they were (they are in the charge instead).
     */
    public function endFreeMonths(Customer $customer): void
    {
        $current = $this->lock->currentPeriod();

        foreach ($this->runningInstallationGrants($customer) as $grant) {
            $used = max(0, FreeMonthGrantService::monthsBetween((string) $grant->start_period, $current));
            // Remembered so reversing the charge gives the months back (restoreFreeMonths()).
            $grant->forceFill(['months_before_cancel' => $grant->months]);
            $grant->update($used > 0
                ? ['months' => $used, 'cancelled_at' => now(), 'cancel_reason' => self::FREE_MONTHS_ENDED]
                : ['status' => FreeMonthGrant::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => self::FREE_MONTHS_ENDED]);
        }

        $this->grants->recalculateFrom($customer, $current);
    }

    /**
     * A reversed "charge every month since connection": the free months it
     * ended are back, as they were.
     */
    public function restoreFreeMonths(Customer $customer): void
    {
        $grants = FreeMonthGrant::query()
            ->where('customer_id', $customer->id)
            ->where('cancel_reason', self::FREE_MONTHS_ENDED)
            ->whereNotNull('months_before_cancel')
            ->get();

        foreach ($grants as $grant) {
            $grant->forceFill(['months_before_cancel' => null]);
            $grant->update(['months' => $grant->getOriginal('months_before_cancel'), 'status' => FreeMonthGrant::STATUS_APPROVED, 'cancelled_at' => null, 'cancel_reason' => null]);
        }

        if ($grants->isNotEmpty()) {
            $this->grants->recalculateFrom($customer, (string) $grants->min('start_period'));
        }
    }

    /**
     * Corrects the connection date (and reason) of a late registration
     * (owner, 2026-10-28): the free months move with it and billing starts
     * from the new month — or from the month they were registered in, if
     * that came later. Setting it to the registration month undoes the late
     * registration. Only open months are rewritten; closed ones never are.
     * Refused while an old-debt charge stands: its amount was counted from
     * the old date — withdraw or reverse it first.
     */
    public function correct(Customer $customer, string $connectedOn, ?string $reason, User $actor): void
    {
        $summary = $this->summary($customer);

        if ($summary === null) {
            throw ValidationException::withMessages(['connected_on' => 'This customer was not registered late.']);
        }

        if ($summary['charge'] !== null && ! $summary['charge']['reversed']) {
            throw ValidationException::withMessages(['connected_on' => 'The late months were charged as old debt — withdraw or reverse that charge first.']);
        }

        $registeredIn = BusinessTimezone::periodOf($customer->created_at);
        $oldMonth = $summary['connection_month'];
        $newMonth = ConnectionMonth::of($connectedOn);

        if ($newMonth > $registeredIn) {
            throw ValidationException::withMessages(['connected_on' => 'The connection date cannot be after the month they were registered in.']);
        }

        $stillLate = $newMonth < $registeredIn;

        if ($stillLate && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why they were registered late.']);
        }

        $oldBillingStarts = (string) $customer->billing_starts_period;
        $shift = FreeMonthGrantService::monthsBetween($oldMonth, $newMonth);

        DB::transaction(function () use ($customer, $connectedOn, $reason, $stillLate, $newMonth, $registeredIn, $shift, $oldBillingStarts): void {
            // The installation free months count from the connection: they move with it.
            if ($shift !== 0) {
                FreeMonthGrant::query()
                    ->where('customer_id', $customer->id)
                    ->where('reason', FreeMonthGrant::REASON_INSTALLATION_OFFER)
                    ->whereNotIn('status', [FreeMonthGrant::STATUS_CANCELLED, FreeMonthGrant::STATUS_REJECTED])
                    ->whereNotNull('start_period')
                    ->get()
                    ->each(fn (FreeMonthGrant $grant) => $grant->update(['start_period' => FreeMonthGrant::addMonths((string) $grant->start_period, $shift)]));
            }

            $customer->forceFill([
                'connected_on' => $connectedOn,
                'billing_starts_period' => max($newMonth, $registeredIn),
                'late_registration_reason' => $stillLate ? $reason : null,
            ])->save();

            $this->grants->recalculateFrom($customer, min($oldBillingStarts, (string) $customer->billing_starts_period));
        });
    }

    /** Whoever asked for the old-debt charge takes the request back while it waits. */
    public function withdraw(ArrearsAdjustment $charge, User $actor): void
    {
        if ($charge->requested_by !== $actor->id) {
            throw ValidationException::withMessages(['charge' => 'Only whoever asked for this charge can withdraw it — others approve or reject it.']);
        }

        $this->adjustments->reject($charge, new RejectArrearsAdjustmentData("Withdrawn by {$actor->name}"));
    }

    /**
     * Undoes an approved old-debt charge: an equal credit on the current
     * bill, approved by someone else like any adjustment — the charge is
     * never deleted. If the charge ended the free months, approving the
     * reversal gives them back (ArrearsAdjustmentService::approve()).
     */
    public function reverse(Customer $customer, string $note, User $actor): ArrearsAdjustment
    {
        $summary = $this->summary($customer);
        $charge = $summary === null || $summary['charge'] === null ? null : ArrearsAdjustment::query()->where('uuid', $summary['charge']['uuid'])->first();

        if ($charge === null || $charge->status !== 'approved') {
            throw ValidationException::withMessages(['charge' => 'There is no approved old-debt charge to reverse.']);
        }

        if ($summary['charge']['reversed'] || $summary['charge']['reversal_status'] !== null) {
            throw ValidationException::withMessages(['charge' => 'This charge was already reversed (or the reversal is waiting for approval).']);
        }

        $reversal = $this->adjustments->create(new ArrearsAdjustmentData(
            customerUuid: $customer->uuid,
            targetPeriod: $this->lock->currentPeriod(),
            direction: 'decrease',
            amount: (string) $charge->amount,
            reasonCategory: self::REVERSAL_CATEGORY,
            reasonNote: $note,
        ), $actor->id);

        $reversal->forceFill(['reverses_adjustment_id' => $charge->id])->save();

        return $reversal;
    }

    /**
     * Installation free months still running this month or later.
     *
     * @return list<FreeMonthGrant>
     */
    private function runningInstallationGrants(Customer $customer): array
    {
        $current = $this->lock->currentPeriod();

        return FreeMonthGrant::query()
            ->active()
            ->where('customer_id', $customer->id)
            ->where('reason', FreeMonthGrant::REASON_INSTALLATION_OFFER)
            ->get()
            ->filter(fn (FreeMonthGrant $grant): bool => (string) $grant->endPeriod() >= $current)
            ->values()
            ->all();
    }
}
