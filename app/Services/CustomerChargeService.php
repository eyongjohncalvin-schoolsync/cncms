<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Models\Voucher;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
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
 *
 * Relocation and re-installation fees are the same charge with another
 * `kind` (App\Services\CustomerRelocationService opens them); every action
 * here works on any kind, and their money is `installation`-purpose too.
 */
class CustomerChargeService
{
    /** The expense category installation refund bons are booked under. */
    public const string REFUND_CATEGORY = 'Customer refunds';

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * One open charge of each kind at a time per customer.
     *
     * @param  array<string, mixed>  $extra  more columns for the new row (relocation from/to, note)
     */
    public function open(Customer $customer, string $amount, ?string $reason, User $actor, string $kind = CustomerCharge::KIND_INSTALLATION, array $extra = []): CustomerCharge
    {
        return DB::transaction(function () use ($customer, $amount, $reason, $actor, $kind, $extra): CustomerCharge {
            // Serialises two clicks on "Add installation fee".
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $hasOpen = CustomerCharge::query()
                ->where('customer_id', $customer->id)
                ->where('kind', $kind)
                ->where('status', CustomerCharge::STATUS_OPEN)
                ->exists();

            if ($hasOpen) {
                $label = strtolower(CustomerCharge::KIND_LABELS[$kind] ?? $kind);

                throw ValidationException::withMessages(['amount' => "{$customer->name} already has an open {$label} fee. Record payments against it or waive it first."]);
            }

            $charge = CustomerCharge::query()->create([
                ...$extra,
                'customer_id' => $customer->id,
                'kind' => $kind,
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
     *
     * $field carries what an offline field installation adds (phase 3):
     * the phone's local_uuid, collection time, device and the field receipt
     * printed at collection. Its amount/purpose/charge are ignored.
     */
    public function recordPayment(CustomerCharge $charge, string $amount, ?string $method, ?string $methodReference, ?PaymentData $field = null): Payment
    {
        $charge->loadMissing('customer');

        return DB::transaction(function () use ($charge, $amount, $method, $methodReference, $field): Payment {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            $label = strtolower($locked->kindLabel());

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['amount' => "This {$label} fee is already {$locked->status}."]);
            }

            $recordable = $locked->recordableAmount();

            if (bccomp($amount, $recordable, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Only '.number_format((float) $recordable, 0, '.', ' ')." FCFA of the {$label} fee is still to be paid.",
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
                recordedOffline: $field?->recordedOffline,
                recordedByDevice: $field?->recordedByDevice,
                localUuid: $field?->localUuid,
                collectedAt: $field?->collectedAt,
                receiptNumber: $field?->receiptNumber,
                securityCode: $field?->securityCode,
                receiptIssuedAt: $field?->receiptIssuedAt,
            ));

            $this->forgetCustomerCache($charge->customer);

            return $payment;
        });
    }

    public function requestWaiver(CustomerCharge $charge, string $reason, User $actor): CustomerCharge
    {
        if (! $charge->isOpen()) {
            throw ValidationException::withMessages(['reason' => 'This '.strtolower($charge->kindLabel())." fee is already {$charge->status}."]);
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
     * Approves a fee below the offer's (D8). The charge may now become paid
     * — and its free months start — once its payments cover it.
     */
    public function approveFee(CustomerCharge $charge, User $actor): CustomerCharge
    {
        return DB::transaction(function () use ($charge, $actor): CustomerCharge {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPendingFeeApproval()) {
                throw ValidationException::withMessages(['fee' => 'This '.strtolower($locked->kindLabel()).' fee is not waiting for approval.']);
            }

            $locked->update(['approval_status' => CustomerCharge::DECISION_APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $locked->refreshStatus();
            $this->forgetCustomerCache($locked->customer);

            return $locked->fresh();
        });
    }

    /**
     * Rejects a fee below the offer's: the offer's fee (standard_amount)
     * applies instead.
     */
    public function rejectFee(CustomerCharge $charge, User $actor, ?string $reason): CustomerCharge
    {
        return DB::transaction(function () use ($charge, $actor, $reason): CustomerCharge {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPendingFeeApproval()) {
                throw ValidationException::withMessages(['fee' => 'This '.strtolower($locked->kindLabel()).' fee is not waiting for approval.']);
            }

            $locked->update([
                'amount' => $locked->standard_amount ?? $locked->amount,
                'approval_status' => CustomerCharge::DECISION_REJECTED,
                'approval_rejection_reason' => $reason,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $locked->refreshStatus();
            $this->forgetCustomerCache($locked->customer);

            return $locked->fresh();
        });
    }

    /**
     * "Refund installation" (D9) — non-refundable by default, so it is asked
     * for with a reason and approved by someone else. At most what was paid.
     */
    public function requestRefund(CustomerCharge $charge, string $amount, string $reason, User $actor): CustomerCharge
    {
        return DB::transaction(function () use ($charge, $amount, $reason, $actor): CustomerCharge {
            $locked = CustomerCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CustomerCharge::STATUS_OPEN, CustomerCharge::STATUS_PAID], true) || $locked->hasPendingRefund()) {
                throw ValidationException::withMessages(['amount' => 'This '.strtolower($locked->kindLabel()).' fee cannot be refunded.']);
            }

            $paid = $locked->paidAmount();

            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $paid, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'A refund is more than 0 and at most what was paid ('.number_format((float) $paid, 0, '.', ' ').' FCFA).']);
            }

            $locked->update([
                'refund_status' => CustomerCharge::DECISION_PENDING,
                'refund_amount' => $amount,
                'refund_reason' => $reason,
                'refund_requested_by' => $actor->id,
                'refund_requested_at' => now(),
                'refund_decided_by' => null,
                'refund_decided_at' => null,
                'refund_rejection_reason' => null,
            ]);

            $this->forgetCustomerCache($locked->customer);

            return $locked;
        });
    }

    /**
     * Approves a refund: the charge becomes `refunded`, its unused free
     * months are forfeited, and a bon (voucher) is raised to pay the money
     * out — bons are the only way money leaves the company. The approver
     * also approves the bon when the bon rules allow them to.
     */
    public function approveRefund(CustomerCharge $charge, User $actor): CustomerCharge
    {
        return DB::transaction(function () use ($charge, $actor): CustomerCharge {
            $locked = CustomerCharge::query()->with('customer')->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasPendingRefund()) {
                throw ValidationException::withMessages(['refund' => 'There is no refund waiting for approval on this fee.']);
            }

            $requester = User::query()->find($locked->refund_requested_by) ?? $actor;
            $category = ExpenseCategory::query()->firstOrCreate(['name' => self::REFUND_CATEGORY], ['icon' => 'ti-receipt-refund', 'is_active' => true]);
            $vouchers = app(VoucherService::class);

            $voucher = $vouchers->request([
                'kind' => Voucher::KIND_EXPENSE,
                'payee_name' => (string) $locked->customer->name,
                'purpose' => "{$locked->kindLabel()} refund — {$locked->customer->name} ({$locked->customer->accountCode()}): {$locked->refund_reason}",
                'category_uuid' => $category->uuid,
                'amount' => (string) $locked->refund_amount,
                'origin' => Voucher::ORIGIN_REFUND,
            ], $requester, branchId: $locked->branch_id);

            if ($vouchers->canDecide($voucher, $actor, app(TenantContext::class))) {
                $vouchers->approve($voucher, $actor, "{$locked->kindLabel()} refund approved");
            }

            $locked->update([
                'status' => CustomerCharge::STATUS_REFUNDED,
                'refund_status' => CustomerCharge::DECISION_APPROVED,
                'refund_decided_by' => $actor->id,
                'refund_decided_at' => now(),
                'refund_voucher_id' => $voucher->id,
            ]);

            app(FreeMonthGrantService::class)->forfeitForCharge($locked, 'Installation refunded');
            $this->forgetCustomerCache($locked->customer);

            return $locked->fresh();
        });
    }

    public function rejectRefund(CustomerCharge $charge, User $actor, ?string $reason): CustomerCharge
    {
        if (! $charge->hasPendingRefund()) {
            throw ValidationException::withMessages(['refund' => 'There is no refund waiting for approval on this fee.']);
        }

        $charge->update([
            'refund_status' => CustomerCharge::DECISION_REJECTED,
            'refund_decided_by' => $actor->id,
            'refund_decided_at' => now(),
            'refund_rejection_reason' => $reason,
        ]);

        return $charge;
    }

    /**
     * The customer's installation charges, newest first, each with its
     * live balance — for the customer page and the API.
     *
     * @param  list<string>  $kinds
     * @return Collection<int, CustomerCharge>
     */
    public function forCustomer(Customer $customer, array $kinds = [CustomerCharge::KIND_INSTALLATION]): Collection
    {
        return CustomerCharge::query()
            ->with(['requestedBy', 'waiverRequestedBy', 'waiverDecidedBy', 'offer', 'refundRequestedBy', 'refundDecidedBy', 'refundVoucher', 'fromZone', 'toZone'])
            ->where('customer_id', $customer->id)
            ->whereIn('kind', $kinds)
            ->latest('id')
            ->get();
    }

    /**
     * What each customer still owes on open charges, per kind — one query
     * for a whole list. Customers and kinds with nothing owed are absent.
     *
     * @param  array<int, int>  $customerIds
     * @param  list<string>  $kinds
     * @return array<int, array<string, string>> customer id => [kind => balance]
     */
    public static function openBalancesByKind(array $customerIds, array $kinds = CustomerCharge::KINDS): array
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

        $rows = CustomerCharge::query()
            ->leftJoinSub($paid, 'paid', 'paid.customer_charge_id', '=', 'customer_charges.id')
            ->whereIn('customer_charges.customer_id', $customerIds)
            ->whereIn('customer_charges.kind', $kinds)
            ->where('customer_charges.status', CustomerCharge::STATUS_OPEN)
            ->groupBy('customer_charges.customer_id', 'customer_charges.kind')
            ->selectRaw('customer_charges.customer_id, customer_charges.kind, sum(greatest(customer_charges.amount - coalesce(paid.paid, 0), 0)) as balance')
            ->toBase()
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balance = bcadd((string) $row->balance, '0', 2);

            if (bccomp($balance, '0', 2) > 0) {
                $balances[(int) $row->customer_id][(string) $row->kind] = $balance;
            }
        }

        return $balances;
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
        return array_filter(array_map(
            fn (array $byKind): ?string => $byKind[CustomerCharge::KIND_INSTALLATION] ?? null,
            self::openBalancesByKind($customerIds, [CustomerCharge::KIND_INSTALLATION]),
        ));
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
        CustomerService::forgetShowCache($customer->uuid, $customer->branch_id);
    }
}
