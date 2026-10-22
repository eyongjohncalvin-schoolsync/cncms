<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installation charges (docs/plans/installation-and-promotions.md, phase 1)
 * — the only writer of App\Models\CustomerCharge.
 *
 *  - open()          — puts an installation fee on a customer (one open
 *                      installation charge at a time).
 *  - recordPayment() — an instalment (D2), recorded through
 *                      PaymentService like any payment (verification,
 *                      receipt, remittance) but with purpose `installation`,
 *                      so it never touches the subscription ledger.
 *  - requestWaiver() / approveWaiver() / rejectWaiver() — maker-checker (D8):
 *                      whoever asks can never approve (the owner excepted,
 *                      exactly like arrears adjustments — see
 *                      App\Policies\CustomerChargePolicy).
 *
 * The balance is shown on the customer page and as its own line on the bill,
 * but it is never part of total_bill / arrears and never a reason to
 * disconnect (D1). Phase 2 creates the charge from an installation offer in
 * the add-customer flow and starts the offer's free months once the charge
 * is paid or waived (D3).
 */
class CustomerChargeService
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function open(Customer $customer, string $amount, ?string $reason, User $actor): CustomerCharge
    {
        return DB::transaction(function () use ($customer, $amount, $reason, $actor): CustomerCharge {
            // Serialises two clicks on "Add installation fee".
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $hasOpen = CustomerCharge::query()
                ->where('customer_id', $customer->id)
                ->where('kind', CustomerCharge::KIND_INSTALLATION)
                ->where('status', CustomerCharge::STATUS_OPEN)
                ->exists();

            if ($hasOpen) {
                throw ValidationException::withMessages(['amount' => "{$customer->name} already has an open installation fee. Record payments against it or waive it first."]);
            }

            $charge = CustomerCharge::query()->create([
                'customer_id' => $customer->id,
                'kind' => CustomerCharge::KIND_INSTALLATION,
                'amount' => $amount,
                'status' => CustomerCharge::STATUS_OPEN,
                'reason' => $reason,
                'requested_by' => $actor->id,
            ]);

            $this->forgetCustomerCache($customer);

            return $charge;
        });
    }

    /**
     * Records an installation instalment. Never more than what is still
     * recordable (fee − verified − pending), so the charge can't be overpaid
     * into a credit.
     */
    public function recordPayment(CustomerCharge $charge, string $amount, ?string $method, ?string $methodReference): Payment
    {
        $charge->loadMissing('customer');

        return DB::transaction(function () use ($charge, $amount, $method, $methodReference): Payment {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['amount' => "This installation fee is already {$locked->status}."]);
            }

            $recordable = $locked->recordableAmount();

            if (bccomp($amount, $recordable, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Only '.number_format((float) $recordable, 0, '.', ' ').' FCFA of the installation fee is still to be paid.',
                ]);
            }

            $payment = $this->payments->createForCustomer($charge->customer, new PaymentData(
                customerUuid: $charge->customer->uuid,
                amount: $amount,
                frequency: 'monthly',
                method: $method,
                methodReference: $methodReference,
                purpose: Payment::PURPOSE_INSTALLATION,
                customerChargeId: $locked->id,
            ));

            $this->forgetCustomerCache($charge->customer);

            return $payment;
        });
    }

    public function requestWaiver(CustomerCharge $charge, string $reason, User $actor): CustomerCharge
    {
        if (! $charge->isOpen()) {
            throw ValidationException::withMessages(['reason' => "This installation fee is already {$charge->status}."]);
        }

        if ($charge->hasPendingWaiver()) {
            throw ValidationException::withMessages(['reason' => 'A waiver is already waiting for approval.']);
        }

        $charge->update([
            'waiver_status' => CustomerCharge::WAIVER_PENDING,
            'waiver_reason' => $reason,
            'waiver_requested_by' => $actor->id,
            'waiver_requested_at' => now(),
            'waiver_decided_by' => null,
            'waiver_decided_at' => null,
            'waiver_rejection_reason' => null,
        ]);

        return $charge;
    }

    /**
     * Waives what is left of the fee: the charge becomes `waived` (which
     * counts as fully paid for phase 2's free months, D3) and the waived
     * remainder is kept on the row. Authorisation (maker ≠ checker) is the
     * policy's job; this re-checks the state under a lock.
     */
    public function approveWaiver(CustomerCharge $charge, User $actor): CustomerCharge
    {
        $charge = DB::transaction(function () use ($charge, $actor): CustomerCharge {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPendingWaiver() || ! $locked->isOpen()) {
                throw ValidationException::withMessages(['waiver' => 'There is no waiver waiting for approval on this fee.']);
            }

            $locked->update([
                'status' => CustomerCharge::STATUS_WAIVED,
                'waived_amount' => $locked->balance(),
                'waiver_status' => CustomerCharge::WAIVER_APPROVED,
                'waiver_decided_by' => $actor->id,
                'waiver_decided_at' => now(),
            ]);

            return $locked;
        });

        $this->forgetCustomerCache($charge->customer);

        return $charge;
    }

    public function rejectWaiver(CustomerCharge $charge, User $actor, ?string $reason): CustomerCharge
    {
        if (! $charge->hasPendingWaiver()) {
            throw ValidationException::withMessages(['waiver' => 'There is no waiver waiting for approval on this fee.']);
        }

        $charge->update([
            'waiver_status' => CustomerCharge::WAIVER_REJECTED,
            'waiver_decided_by' => $actor->id,
            'waiver_decided_at' => now(),
            'waiver_rejection_reason' => $reason,
        ]);

        return $charge;
    }

    /**
     * The customer's installation charges, newest first, each with its
     * live balance — for the customer page and the API.
     *
     * @return Collection<int, CustomerCharge>
     */
    public function forCustomer(Customer $customer): Collection
    {
        return CustomerCharge::query()
            ->with(['requestedBy', 'waiverRequestedBy', 'waiverDecidedBy'])
            ->where('customer_id', $customer->id)
            ->where('kind', CustomerCharge::KIND_INSTALLATION)
            ->latest('id')
            ->get();
    }

    /**
     * What each customer still owes on open installation charges — one
     * query for a whole list (bill batches print hundreds at once).
     * Customers with nothing owed are absent.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, string> customer id => balance
     */
    public static function openInstallationBalances(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $paid = Payment::query()
            ->select('customer_charge_id')
            ->selectRaw('sum(amount) as paid')
            ->where('purpose', Payment::PURPOSE_INSTALLATION)
            ->where('verification_status', 'verified')
            ->groupBy('customer_charge_id');

        return CustomerCharge::query()
            ->leftJoinSub($paid, 'paid', 'paid.customer_charge_id', '=', 'customer_charges.id')
            ->whereIn('customer_charges.customer_id', $customerIds)
            ->where('customer_charges.kind', CustomerCharge::KIND_INSTALLATION)
            ->where('customer_charges.status', CustomerCharge::STATUS_OPEN)
            ->groupBy('customer_charges.customer_id')
            ->selectRaw('customer_charges.customer_id, sum(greatest(customer_charges.amount - coalesce(paid.paid, 0), 0)) as balance')
            ->pluck('balance', 'customer_id')
            ->map(fn ($balance): string => bcadd((string) $balance, '0', 2))
            ->filter(fn (string $balance): bool => bccomp($balance, '0', 2) > 0)
            ->all();
    }

    public static function openInstallationBalance(Customer $customer): string
    {
        return self::openInstallationBalances([$customer->id])[$customer->id] ?? '0.00';
    }

    /**
     * CustomerService::findOrFail() caches the customer with its recent
     * payments; the charges themselves are always read fresh. Same key
     * format (and same "own branch + 'all'" tradeoff) as
     * CustomerService::forgetShowCache().
     */
    private function forgetCustomerCache(Customer $customer): void
    {
        Cache::forget("customers:show:{$customer->uuid}:".(TenantContext::currentBranchId() ?? 'all'));
        Cache::forget("customers:show:{$customer->uuid}:all");
    }
}
