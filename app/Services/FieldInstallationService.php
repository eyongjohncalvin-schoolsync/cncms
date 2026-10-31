<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\InstallationData;
use App\DataTransferObjects\PaymentData;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\ExpenseCategory;
use App\Models\FieldInstallation;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Zone;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Offline field installations (docs/plans/installation-and-promotions.md,
 * phase 3, D10): a field agent registers a customer — with the installation
 * money (and months paid upfront) and the receipts the phone printed from
 * its receipt block — while offline; the office then activates or rejects.
 *
 *  - register() — one sync item (`changes.installations[]`). Idempotent on
 *    local_uuid. Needs `installations.field`, own zone only. Creates the
 *    customer as "pending activation" (never billed) with its charge and
 *    payments in ONE transaction through CustomerInstallationService; the
 *    money is in the collector's remittance at once (the receipts were
 *    issued). Possible duplicates are flagged for the office, never blocked
 *    and never merged.
 *  - activate() — the office confirms or corrects zone, offer, free months
 *    and connection date; billing starts (CustomerInstallationService::
 *    activatePending()).
 *  - reject() — with a reason: the payments are rejected (receipts voided),
 *    a refund bon is raised for the money taken (bons are the only way
 *    money leaves — the cash itself still comes in through the
 *    remittance), and the customer is archived.
 */
class FieldInstallationService
{
    public function __construct(
        private readonly CustomerInstallationService $installations,
        private readonly CustomerService $customers,
        private readonly PaymentVerificationService $verifications,
        private readonly VoucherService $vouchers,
        private readonly TenantContext $context,
    ) {}

    /**
     * Applies one pushed registration. Throws on refusal (permission, zone,
     * validation, a refused receipt) — the sync loop reports it per item.
     *
     * @param  array<string, mixed>  $item
     * @return array{installation: FieldInstallation, replayed: bool}
     */
    public function register(array $item, string $deviceId, User $actor): array
    {
        $localUuid = (string) ($item['local_uuid'] ?? '');
        $existing = FieldInstallation::query()->with('customer')->where('local_uuid', $localUuid)->first();

        if ($existing !== null) {
            return ['installation' => $existing, 'replayed' => true];
        }

        if (! $this->context->can('installations.field')) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to register customers in the field.']]);
        }

        if (Impersonation::active()) {
            throw ValidationException::withMessages(['permission' => [BlockDuringImpersonation::MONEY_MESSAGE]]);
        }

        $data = $this->validated($item);
        $zone = Zone::query()->where('uuid', $data['zone_uuid'])->first();

        if ($zone === null) {
            throw ValidationException::withMessages(['zone_uuid' => ['Unknown zone.']]);
        }

        // Own zones only. An office role holding the permission (not
        // zone-scoped) is fenced by its branch like any customer it adds.
        $ownZoneIds = TenantContext::currentZoneIds();

        if ($ownZoneIds === []) {
            throw ValidationException::withMessages(['zone_uuid' => ['Your account has no zone — ask the office to assign one before registering customers.']]);
        }

        if ($ownZoneIds !== null && ! in_array($zone->id, $ownZoneIds, true)) {
            throw ValidationException::withMessages(['zone_uuid' => ['You can only register customers in your own zones.']]);
        }

        $branchId = TenantContext::currentBranchId();

        if ($branchId !== null && $zone->branch_id !== $branchId) {
            throw ValidationException::withMessages(['zone_uuid' => ['This zone is outside your branch.']]);
        }

        if (Customer::query()->withoutGlobalScope(BranchScope::class)->withTrashed()->where('uuid', $data['customer_uuid'])->exists()) {
            throw ValidationException::withMessages(['customer_uuid' => ['A customer with this id already exists — the registration was not saved.']]);
        }

        $duplicates = $this->installations->possibleDuplicates($data['name'], $data['phone']);
        $installation = InstallationData::fromArray($data);
        $collectedAt = $data['created_at'] ?? null;

        return DB::transaction(function () use ($data, $installation, $duplicates, $deviceId, $collectedAt, $actor, $localUuid): array {
            $result = $this->installations->createPending(
                CustomerData::fromArray($data),
                $installation,
                $data['customer_uuid'],
                new PaymentData(recordedByDevice: $deviceId, collectedAt: $collectedAt),
                $this->receipt($data['installation_receipt'] ?? null),
                $this->receipt($data['upfront_receipt'] ?? null),
                $actor,
            );

            $declared = $data['upfront_receipt']['amount'] ?? null;

            if ($declared !== null && bccomp(bcadd((string) $declared, '0', 2), $result['upfront_amount'], 2) !== 0) {
                throw ValidationException::withMessages(['upfront_receipt' => ['The months paid upfront come to '.number_format((float) $result['upfront_amount'], 0, '.', ' ').' FCFA at the current prices, but the receipt says '.number_format((float) $declared, 0, '.', ' ').' FCFA.']]);
            }

            $fieldInstallation = FieldInstallation::query()->create([
                'local_uuid' => $localUuid,
                'customer_id' => $result['customer']->id,
                'customer_charge_id' => $result['charge']?->id,
                'submitted_by' => $actor->id,
                'recorded_by_device' => $deviceId,
                'collected_at' => $collectedAt !== null ? Carbon::parse($collectedAt) : null,
                'proposal' => [
                    'installation_offer_uuid' => $installation->offerUuid,
                    'installation_fee' => $installation->fee,
                    'installation_paid' => bcadd($installation->paidNow, '0', 2),
                    'installation_reason' => $installation->reason,
                    'connected_on' => $installation->connectedOn,
                    'service_plans' => array_values(array_map(
                        fn (string $uuid, array $row): array => ['service_uuid' => $uuid, ...$row],
                        array_keys($installation->servicePlans ?? []),
                        $installation->servicePlans ?? [],
                    )),
                    'method' => $installation->method,
                    'method_reference' => $installation->methodReference,
                ],
                'possible_duplicates' => $duplicates !== [] ? $duplicates : null,
                'status' => FieldInstallation::STATUS_PENDING,
            ]);

            foreach ($result['payments'] as $payment) {
                $payment->forceFill(['field_installation_id' => $fieldInstallation->id])->save();
            }

            // Whoever activates new customers hears about it (manager inbox, wave 2).
            app(ActionAlertService::class)->alert(
                app(ActionAlertService::class)->deciders('installations.activate', $result['customer']->branch_id, $actor->id),
                'inbox.activation',
                'warning',
                'New customer to activate',
                "{$actor->name} registered {$result['customer']->name} in the field.",
                '/customers?pending_activation=1',
                'field_installation',
                $fieldInstallation->uuid,
                $result['customer']->branch_id,
            );

            return ['installation' => $fieldInstallation->setRelation('customer', $result['customer']), 'replayed' => false];
        });
    }

    /**
     * Activates a pending registration with what the office confirmed or
     * corrected: zone, offer, free months per service, connection date.
     *
     * @param  array{zone_uuid?: ?string, installation_offer_uuid?: ?string, connected_on?: ?string, service_plans?: ?array<int, array<string, mixed>>, installation_reason?: ?string, note?: ?string}  $input
     */
    public function activate(FieldInstallation $fieldInstallation, array $input, User $actor): FieldInstallation
    {
        return DB::transaction(function () use ($fieldInstallation, $input, $actor): FieldInstallation {
            $locked = $this->lockedPending($fieldInstallation);
            $customer = Customer::query()->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();

            $corrections = [];

            if (! empty($input['zone_uuid'])) {
                $zone = Zone::query()->where('uuid', $input['zone_uuid'])->firstOrFail();

                if ($zone->id !== $customer->zone_id) {
                    $corrections['zone'] = ['from' => $customer->zone?->name, 'to' => $zone->name];
                    $customer->forceFill(['zone_id' => $zone->id])->save();
                }
            }

            // Anything the office leaves out stands as the agent proposed it.
            $proposal = $locked->proposal;
            $confirmed = [
                'installation_offer_uuid' => array_key_exists('installation_offer_uuid', $input) ? $input['installation_offer_uuid'] : ($proposal['installation_offer_uuid'] ?? null),
                'connected_on' => $input['connected_on'] ?? $proposal['connected_on'] ?? $customer->connected_on?->toDateString(),
                'service_plans' => $input['service_plans'] ?? $proposal['service_plans'] ?? [],
                'installation_reason' => $input['installation_reason'] ?? null,
                'connected_by_uuid' => $input['connected_by_uuid'] ?? null,
                'customer_says_paid_amount' => $input['customer_says_paid_amount'] ?? null,
                'customer_says_paid_to' => $input['customer_says_paid_to'] ?? null,
            ];
            $installation = InstallationData::fromArray($confirmed);

            foreach (['installation_offer_uuid', 'connected_on'] as $key) {
                if ($confirmed[$key] !== ($proposal[$key] ?? null)) {
                    $corrections[$key] = ['from' => $proposal[$key] ?? null, 'to' => $confirmed[$key]];
                }
            }

            $proposedFree = collect($proposal['service_plans'] ?? [])->mapWithKeys(fn (array $row): array => [$row['service_uuid'] => [$row['free_from'] ?? null, (int) ($row['free_months'] ?? 0)]]);
            $chosenFree = collect($installation->servicePlans ?? [])->map(fn (array $row): array => [$row['free_months'] > 0 ? $row['free_from'] : null, $row['free_months']]);

            if ($proposedFree->filter(fn (array $row): bool => $row[1] > 0)->all() != $chosenFree->filter(fn (array $row): bool => $row[1] > 0)->all()) {
                $corrections['free_months'] = ['from' => $proposedFree->all(), 'to' => $chosenFree->all()];
            }

            $this->installations->activatePending($customer, $installation, $actor);

            $locked->update([
                'status' => FieldInstallation::STATUS_ACTIVATED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => isset($input['note']) && trim((string) $input['note']) !== '' ? mb_substr(trim((string) $input['note']), 0, 500) : null,
                'corrections' => $corrections !== [] ? $corrections : null,
            ]);

            $this->customers->forgetShowCache($customer->uuid);

            return $locked->fresh(['customer']);
        });
    }

    /**
     * Rejects a pending registration: every payment taken with it is
     * rejected (its receipt voided), a refund bon is raised for the money
     * taken — it waits for approval like any bon, and the cash still comes
     * in through the collector's remittance — and the customer is archived.
     */
    public function reject(FieldInstallation $fieldInstallation, string $reason, User $actor): FieldInstallation
    {
        return DB::transaction(function () use ($fieldInstallation, $reason, $actor): FieldInstallation {
            $locked = $this->lockedPending($fieldInstallation);
            $customer = Customer::query()->with('zone')->whereKey($locked->customer_id)->lockForUpdate()->firstOrFail();

            $payments = Payment::query()
                ->with(['verification', 'customerCharge'])
                ->where('field_installation_id', $locked->id)
                ->where('verification_status', '!=', 'rejected')
                ->get();

            $taken = '0.00';

            foreach ($payments as $payment) {
                $taken = bcadd($taken, (string) $payment->amount, 2);
                $this->verifications->voidForRefund($payment, "Field registration rejected: {$reason}", $actor);
            }

            $voucher = null;

            if (bccomp($taken, '0', 2) > 0) {
                $category = ExpenseCategory::query()->firstOrCreate(['name' => CustomerChargeService::REFUND_CATEGORY], ['icon' => 'ti-receipt-refund', 'is_active' => true]);

                $voucher = $this->vouchers->request([
                    'kind' => Voucher::KIND_EXPENSE,
                    'payee_name' => mb_substr((string) $customer->name, 0, 150),
                    'purpose' => mb_substr("Refund — field registration rejected: {$customer->name} ({$customer->accountCode()}): {$reason}", 0, 500),
                    'category_uuid' => $category->uuid,
                    'amount' => $taken,
                    'origin' => Voucher::ORIGIN_REFUND,
                ], $actor, branchId: $customer->branch_id);
            }

            if ($locked->customer_charge_id !== null) {
                $installationRefund = '0.00';

                foreach ($payments->where('purpose', Payment::PURPOSE_INSTALLATION) as $payment) {
                    $installationRefund = bcadd($installationRefund, (string) $payment->amount, 2);
                }

                // The charge closes as refunded; its refund is the bon above.
                CustomerCharge::query()->whereKey($locked->customer_charge_id)->firstOrFail()->update([
                    'status' => CustomerCharge::STATUS_REFUNDED,
                    'refund_status' => bccomp($installationRefund, '0', 2) > 0 ? CustomerCharge::DECISION_APPROVED : null,
                    'refund_amount' => bccomp($installationRefund, '0', 2) > 0 ? $installationRefund : null,
                    'refund_reason' => mb_substr("Field registration rejected: {$reason}", 0, 500),
                    'refund_requested_by' => $actor->id,
                    'refund_requested_at' => now(),
                    'refund_decided_by' => $actor->id,
                    'refund_decided_at' => now(),
                    'refund_voucher_id' => $voucher?->id,
                ]);
            }

            $locked->update([
                'status' => FieldInstallation::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => mb_substr($reason, 0, 500),
                'refund_voucher_id' => $voucher?->id,
            ]);

            $customer->forceFill(['activation_status' => null])->save();
            // Its money is refunded through the bon just raised — settling money
            // still open on an archived customer is allowed.
            $this->customers->archive($customer, $actor->id, mb_substr("Field registration rejected: {$reason}", 0, 255), whileMoneyIsOpen: true);

            return $locked->fresh(['customer']);
        });
    }

    /** Field registrations waiting for the office — the dashboard count. */
    public function pendingCount(): int
    {
        $branchId = TenantContext::currentBranchId();

        return Customer::query()
            ->pendingActivation()
            ->when($branchId !== null, fn ($query) => $query->where('customers.branch_id', $branchId))
            ->count();
    }

    private function lockedPending(FieldInstallation $fieldInstallation): FieldInstallation
    {
        $locked = FieldInstallation::query()->whereKey($fieldInstallation->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw ValidationException::withMessages(['status' => "This registration is already {$locked->status}."]);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>|null  $receipt
     */
    private function receipt(?array $receipt): ?PaymentData
    {
        if ($receipt === null) {
            return null;
        }

        return new PaymentData(
            localUuid: isset($receipt['local_uuid']) && Str::isUuid((string) $receipt['local_uuid']) ? (string) $receipt['local_uuid'] : null,
            receiptNumber: isset($receipt['receipt_number']) && trim((string) $receipt['receipt_number']) !== '' ? trim((string) $receipt['receipt_number']) : null,
            securityCode: $receipt['security_code'] ?? null,
            receiptIssuedAt: $receipt['receipt_issued_at'] ?? null,
        );
    }

    /**
     * The business rules of one registration — the push request only checks
     * its shape, so one bad item fails alone.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function validated(array $item): array
    {
        return Validator::make($item, [
            'local_uuid' => ['required', 'uuid'],
            'customer_uuid' => ['required', 'uuid'],
            'created_at' => ['nullable', 'date'],
            'zone_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'preferred_channel' => ['nullable', 'string', Rule::in(Customer::PREFERRED_CHANNELS)],
            'level' => ['nullable', 'string', 'in:normal,Vip,Operator'],
            'services' => ['required', 'array', 'min:1'],
            'services.*.service_uuid' => ['required', 'uuid', 'exists:services,uuid'],
            'services.*.service_variant_uuid' => ['nullable', 'uuid', 'exists:service_variants,uuid'],
            'services.*.price' => ['required', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            ...StoreCustomerRequest::installationRules(),
            'installation_receipt' => ['nullable', 'array'],
            'installation_receipt.local_uuid' => ['nullable', 'uuid'],
            'installation_receipt.receipt_number' => ['nullable', 'string', 'max:30'],
            'installation_receipt.security_code' => ['nullable', 'string', 'max:12'],
            'installation_receipt.receipt_issued_at' => ['nullable', 'string', 'max:40'],
            'upfront_receipt' => ['nullable', 'array'],
            'upfront_receipt.local_uuid' => ['nullable', 'uuid'],
            'upfront_receipt.amount' => ['nullable', 'numeric', 'min:0'],
            'upfront_receipt.receipt_number' => ['nullable', 'string', 'max:30'],
            'upfront_receipt.security_code' => ['nullable', 'string', 'max:12'],
            'upfront_receipt.receipt_issued_at' => ['nullable', 'string', 'max:40'],
        ])->validate();
    }
}
