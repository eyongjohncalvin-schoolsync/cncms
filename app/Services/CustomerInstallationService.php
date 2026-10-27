<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\DataTransferObjects\CustomerServiceSelection;
use App\DataTransferObjects\InstallationData;
use App\DataTransferObjects\PaymentData;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\FreeMonthGrant;
use App\Models\InstallationOffer;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\Billing\ConnectionMonth;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use App\Support\UpperCaseLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adding a customer with their installation and first payment
 * (docs/plans/installation-and-promotions.md, phase 2 item 5 + revision
 * D15–D19) — web and mobile (online) share this service.
 *
 * Per service ticked the form chooses ☐ free months — one consecutive run
 * from the installation month or later (the installation month is ticked
 * by default; untick it and it is billed) — and ☐ months paid upfront that
 * follow straight after, at that service's own price. Each service then has
 * its own timeline: free → paid → billed. After that every customer is
 * billed on the same ledger (D18).
 *
 *  - preview() — the live line ("Collect now: 17,500 · TV Service: free
 *    Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from Mar 2027"), each
 *    service's timeline, what needs approval and possible duplicates.
 *    Writes nothing.
 *  - create() — ONE save in ONE transaction: the customer, the installation
 *    charge and its payment, the months paid upfront (one payment, per
 *    service) and the free months, each payment with its receipt. If the
 *    installation month (or a later one) was already generated, the
 *    customer's own rows are calculated straight away so nothing is skipped.
 *
 * Approval (admin/owner, maker ≠ checker): free months need it unless the
 * installation is fully paid at registration (D17) and they are within the
 * company cap (D16) and the offer picked (D19); a fee below the offer's
 * and a full waiver need it too (D8).
 */
class CustomerInstallationService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerChargeService $charges,
        private readonly PaymentService $payments,
        private readonly FreeMonthGrantService $grants,
        private readonly ManuscriptRunLockService $lock,
    ) {}

    /**
     * What saving the form would do, for the live preview.
     *
     * @param  list<CustomerServiceSelection>  $services
     * @return array<string, mixed>
     */
    public function preview(array $services, InstallationData $installation, ?string $name = null, ?string $phone = null): array
    {
        return [
            ...$this->planFor($services, $installation, preview: true)['summary'],
            'duplicates' => $this->possibleDuplicates($name, $phone),
        ];
    }

    /**
     * Auditing (owner, 2026-10-26): who connected the customer (a member of
     * this company's staff), and — for a late registration — why it was late
     * and what the customer says they paid and to whom.
     *
     * @param  array{late: bool}  $plan
     * @return array<string, mixed>
     */
    private function auditFields(InstallationData $installation, array $plan, ?int $defaultConnectorId = null): array
    {
        $connectorId = $defaultConnectorId;

        if ($installation->connectedByUuid !== null) {
            // Users are central, staff membership is per company (two connections, so two lookups).
            $userId = User::query()->where('uuid', $installation->connectedByUuid)->value('id');

            if ($userId === null || ! TenantUser::query()->where('user_id', $userId)->exists()) {
                throw ValidationException::withMessages(['connected_by_uuid' => 'Pick who connected the customer from the staff list.']);
            }

            $connectorId = (int) $userId;
        }

        return [
            'connected_by_user_id' => $connectorId,
            'late_registration_reason' => $plan['late'] ? $installation->reason : null,
            'customer_says_paid_amount' => $plan['late'] ? $installation->customerSaysPaidAmount : null,
            'customer_says_paid_to' => $plan['late'] ? $installation->customerSaysPaidTo : null,
        ];
    }

    /**
     * Customers who may be the same person — same phone number (last nine
     * digits) or exactly the same name. A warning only (item 11).
     *
     * @return list<array{uuid: string, name: string, phone: ?string, zone: ?string, status: string}>
     */
    public function possibleDuplicates(?string $name, ?string $phone): array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $lastNine = strlen($digits) >= 9 ? substr($digits, -9) : null;
        $name = trim((string) $name);

        if ($lastNine === null && mb_strlen($name) < 3) {
            return [];
        }

        // Archived customers and other branches too: a late registration or a
        // promotion is exactly where an old customer (or debtor) comes back
        // as "new". Only a warning — the viewer may not open those records.
        $branchId = TenantContext::currentBranchId();

        return Customer::query()
            ->withTrashed()
            ->withoutGlobalScope(BranchScope::class)
            ->with(['zone' => fn ($query) => $query->withoutGlobalScope(BranchScope::class)->select('id', 'name')])
            ->where(function ($query) use ($lastNine, $name): void {
                if ($lastNine !== null) {
                    $query->orWhereRaw("regexp_replace(coalesce(phone, ''), '\\D', '', 'g') like ?", ['%'.$lastNine]);
                }

                if (mb_strlen($name) >= 3) {
                    // Names are stored normalised (UpperCaseLabel); upper() on
                    // both sides also catches a legacy mixed-case row.
                    $query->orWhereRaw('upper(name) = upper(?)', [UpperCaseLabel::normalise($name)]);
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'zone' => $customer->zone?->name,
                'status' => $customer->trashed() ? 'archived' : $customer->status,
                // Openable by the viewer: not archived, and in their branch (or they see all).
                'openable' => ! $customer->trashed() && ($branchId === null || $customer->branch_id === $branchId),
                'other_branch' => $branchId !== null && $customer->branch_id !== $branchId,
            ])
            ->all();
    }

    /**
     * @return array{customer: Customer, payments: list<Payment>, charge: ?CustomerCharge, grants: Collection<int, FreeMonthGrant>}
     */
    public function create(CustomerData $customerData, InstallationData $installation, User $actor): array
    {
        $plan = $this->planFor($customerData->services ?? [], $installation, branchId: $this->branchOfZone($customerData->zoneUuid));
        $this->assertPricesAllowed($customerData);

        return DB::transaction(function () use ($customerData, $installation, $actor, $plan): array {
            return $this->grants->deferRecalculation(function () use ($customerData, $installation, $actor, $plan): array {
                $customer = $this->customers->create($customerData);
                $customer->forceFill([
                    'connected_on' => $plan['connected_on'],
                    'billing_starts_period' => $plan['installation_month'],
                    ...$this->auditFields($installation, $plan),
                ])->save();

                $offer = $plan['offer'];
                $charge = null;
                $payments = [];

                if ($plan['charge_amount'] !== null) {
                    $charge = $this->charges->open($customer, $plan['charge_amount'], $installation->reason ?? ($offer !== null ? "Installation — {$offer->name}" : 'Installation'), $actor);
                    $charge->update([
                        'standard_amount' => $plan['standard_fee'],
                        'installation_offer_id' => $offer?->id,
                        'approval_status' => $plan['fee_needs_approval'] ? CustomerCharge::DECISION_PENDING : null,
                    ]);

                    if ($plan['waiver_needed']) {
                        $this->charges->requestWaiver($charge, (string) $installation->reason, $actor);
                    }
                }

                $grants = $this->grants->createInstallationGrants(
                    $customer,
                    $charge,
                    $offer,
                    $plan['free_by_service_id'],
                    $plan['free_needs_approval'],
                    $actor,
                    $plan['free_needs_approval'] ? $installation->reason : null,
                );

                // Months paid upfront first, so the installation receipt
                // (issued when the fee is paid) already shows them.
                if ($plan['service_months'] !== []) {
                    $payments[] = $this->payments->createForCustomer($customer, new PaymentData(
                        customerUuid: $customer->uuid,
                        amount: $plan['upfront_amount'],
                        frequency: 'months',
                        months: max(array_column($plan['service_months'], 'months')),
                        method: $installation->method,
                        methodReference: $installation->methodReference,
                        serviceMonths: $plan['service_months'],
                    ));
                }

                if ($charge !== null && bccomp($installation->paidNow, '0', 2) > 0) {
                    $payments[] = $this->charges->recordPayment($charge->fresh(), $installation->paidNow, $installation->method, $installation->methodReference);
                }

                // The installation month (or later ones) may already be
                // generated: write this customer's rows now.
                $this->grants->recalculateFrom($customer, $plan['installation_month']);

                return [
                    'customer' => $customer->fresh(['zone', 'subscriptions.service', 'subscriptions.serviceVariant']),
                    'payments' => $payments,
                    'charge' => $charge?->fresh(),
                    'grants' => $grants,
                ];
            });
        });
    }

    /**
     * An offline field installation (phase 3, D10): the customer — with the
     * uuid the phone gave it, which its field receipts are signed with — the
     * installation charge and the money taken in the field, each payment
     * with the receipt the phone printed, in ONE transaction. The customer
     * waits as "pending activation": no billing start, no free months, no
     * rows; the office settles those at activation (activatePending()).
     *
     * The plan is checked as in create() except what the office settles at
     * activation: an offer that expired while the phone was offline still
     * applies, a reason for anything needing approval is not required yet,
     * and a connection date ahead of the server's clock (a phone's clock)
     * is read as today.
     *
     * @param  PaymentData  $field  the phone's device and collection time, shared by both payments
     * @param  ?PaymentData  $installationReceipt  local_uuid + field receipt of the installation payment
     * @param  ?PaymentData  $upfrontReceipt  local_uuid + field receipt of the months paid upfront
     * @return array{customer: Customer, payments: list<Payment>, charge: ?CustomerCharge, upfront_amount: string}
     */
    public function createPending(
        CustomerData $customerData,
        InstallationData $installation,
        string $customerUuid,
        PaymentData $field,
        ?PaymentData $installationReceipt,
        ?PaymentData $upfrontReceipt,
        User $actor,
    ): array {
        // Only the money is settled now; the free months chosen on the phone
        // wait in the registration's proposal for the office (a month may
        // even have passed before the phone synced).
        $moneyOnly = new InstallationData(
            offerUuid: $installation->offerUuid,
            fee: $installation->fee,
            paidNow: $installation->paidNow,
            servicePlans: array_map(fn (array $row): array => [...$row, 'free_from' => null, 'free_months' => 0], $installation->servicePlans ?? []),
            connectedOn: $installation->connectedOn,
            reason: $installation->reason,
            method: $installation->method,
            methodReference: $installation->methodReference,
        );

        $plan = $this->planFor($customerData->services ?? [], $moneyOnly, field: true, branchId: $this->branchOfZone($customerData->zoneUuid));
        $this->assertPricesAllowed($customerData);

        return DB::transaction(function () use ($customerData, $installation, $customerUuid, $field, $installationReceipt, $upfrontReceipt, $actor, $plan): array {
            $customer = $this->customers->create($customerData);
            $customer->forceFill([
                'uuid' => $customerUuid,
                'connected_on' => $plan['connected_on'],
                'billing_starts_period' => null,
                'activation_status' => Customer::ACTIVATION_PENDING,
                // Registered in the field: the agent connected it (the office may correct it at activation).
                'connected_by_user_id' => $actor->id,
            ])->save();

            $offer = $plan['offer'];
            $charge = null;
            $payments = [];

            if ($plan['charge_amount'] !== null) {
                $charge = $this->charges->open($customer, $plan['charge_amount'], $installation->reason ?? ($offer !== null ? "Installation — {$offer->name}" : 'Installation'), $actor);
                $charge->update([
                    'standard_amount' => $plan['standard_fee'],
                    'installation_offer_id' => $offer?->id,
                    'approval_status' => $plan['fee_needs_approval'] ? CustomerCharge::DECISION_PENDING : null,
                ]);

                if ($plan['waiver_needed']) {
                    $this->charges->requestWaiver($charge, $installation->reason ?? 'Installation fee waived in the field', $actor);
                }
            }

            if ($plan['service_months'] !== []) {
                $payments[] = $this->payments->createForCustomer($customer, new PaymentData(
                    customerUuid: $customer->uuid,
                    amount: $plan['upfront_amount'],
                    frequency: 'months',
                    months: max(array_column($plan['service_months'], 'months')),
                    recordedOffline: true,
                    recordedByDevice: $field->recordedByDevice,
                    localUuid: $upfrontReceipt?->localUuid,
                    collectedAt: $field->collectedAt,
                    method: $installation->method,
                    methodReference: $installation->methodReference,
                    receiptNumber: $upfrontReceipt?->receiptNumber,
                    securityCode: $upfrontReceipt?->securityCode,
                    receiptIssuedAt: $upfrontReceipt?->receiptIssuedAt,
                    serviceMonths: $plan['service_months'],
                ));
            }

            if ($charge !== null && bccomp($installation->paidNow, '0', 2) > 0) {
                $payments[] = $this->charges->recordPayment($charge->fresh(), $installation->paidNow, $installation->method, $installation->methodReference, new PaymentData(
                    recordedOffline: true,
                    recordedByDevice: $field->recordedByDevice,
                    localUuid: $installationReceipt?->localUuid,
                    collectedAt: $field->collectedAt,
                    receiptNumber: $installationReceipt?->receiptNumber,
                    securityCode: $installationReceipt?->securityCode,
                    receiptIssuedAt: $installationReceipt?->receiptIssuedAt,
                ));
            }

            return [
                'customer' => $customer->fresh(['zone', 'subscriptions.service', 'subscriptions.serviceVariant']),
                'payments' => $payments,
                'charge' => $charge?->fresh(),
                'upfront_amount' => $plan['upfront_amount'],
            ];
        });
    }

    /**
     * Activates a field registration (phase 3): the office's confirmed or
     * corrected offer, free months per service and connection date are
     * applied exactly as if the customer had been added at the office —
     * same approval rules (D16/D17/D19: free months apply at once only if
     * the installation was fully paid at registration, within the cap and
     * the offer), the billing start is the installation month, and any month
     * already generated gets this customer's row (Option X, as in create()).
     *
     * The money is what was taken in the field: the fee charged and paid,
     * and the months paid upfront per service, are kept as recorded — only
     * free months, offer and connection date can be corrected.
     *
     * @return array{customer: Customer, grants: Collection<int, FreeMonthGrant>, installation_month: string}
     */
    public function activatePending(Customer $customer, InstallationData $installation, User $actor): array
    {
        $customer->loadMissing('subscriptions.service', 'subscriptions.serviceVariant');

        $services = $customer->subscriptions
            ->map(fn ($row): CustomerServiceSelection => new CustomerServiceSelection(
                serviceUuid: (string) $row->service->uuid,
                price: (string) $row->price,
                serviceVariantUuid: $row->serviceVariant?->uuid,
            ))
            ->values()
            ->all();

        $charge = CustomerCharge::query()
            ->where('customer_id', $customer->id)
            ->where('kind', CustomerCharge::KIND_INSTALLATION)
            ->latest('id')
            ->first();

        // Months paid upfront as recorded in the field (per service uuid).
        $paidMonths = [];
        $serviceUuids = Service::query()->pluck('uuid', 'id');

        $upfront = Payment::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('service_months')
            ->where('verification_status', '!=', 'rejected')
            ->get();

        foreach ($upfront as $payment) {
            foreach ((array) $payment->service_months as $row) {
                $uuid = (string) ($serviceUuids[(int) $row['service_id']] ?? '');
                $paidMonths[$uuid] = ($paidMonths[$uuid] ?? 0) + (int) $row['months'];
            }
        }

        // Free months count from the real connection month, even when the
        // registration synced or is activated after that month closed.
        $connectedOn = min($installation->connectedOn ?? $customer->connected_on?->toDateString() ?? BusinessTimezone::today(), BusinessTimezone::today());
        $connectionMonth = ConnectionMonth::of($connectedOn);
        $plans = [];

        foreach ($services as $selection) {
            if ($selection->serviceVariantUuid !== null) {
                continue;
            }

            $chosen = $installation->servicePlans[$selection->serviceUuid] ?? null;
            $plans[$selection->serviceUuid] = [
                'free_from' => isset($chosen['free_from']) ? max((string) $chosen['free_from'], $connectionMonth) : null,
                'free_months' => (int) ($chosen['free_months'] ?? 0),
                // Settled by the money, not by the form: the upfront months
                // are already a payment, so planFor() must not count them
                // as a new one (paid_months 0) — they reach the timeline
                // through the recorded payment.
                'paid_months' => 0,
            ];
        }

        // Fee and amount paid at registration: what the charge records.
        $paidAtRegistration = $charge !== null
            ? bcadd((string) Payment::query()
                ->where('customer_charge_id', $charge->id)
                ->whereNotNull('field_installation_id')
                ->where('verification_status', '!=', 'rejected')
                ->sum('amount'), '0', 2)
            : '0';

        $plan = $this->planFor($services, new InstallationData(
            offerUuid: $installation->offerUuid,
            paidNow: $paidAtRegistration,
            servicePlans: $plans,
            connectedOn: $connectedOn,
            reason: $installation->reason,
        ), field: true, activation: true, charge: $charge, branchId: $customer->branch_id);

        return DB::transaction(function () use ($customer, $installation, $actor, $plan, $charge): array {
            return $this->grants->deferRecalculation(function () use ($customer, $installation, $actor, $plan, $charge): array {
                $customer->forceFill([
                    'connected_on' => $plan['connected_on'],
                    'billing_starts_period' => $plan['installation_month'],
                    'activation_status' => null,
                    // The agent who registered it in the field connected it, unless the office says otherwise.
                    ...$this->auditFields($installation, $plan, $customer->connected_by_user_id),
                ])->save();

                if ($charge !== null && $plan['offer'] !== null && $charge->installation_offer_id !== $plan['offer']->id) {
                    $charge->update(['installation_offer_id' => $plan['offer']->id, 'standard_amount' => $charge->standard_amount ?? $plan['standard_fee']]);
                }

                $grants = $this->grants->createInstallationGrants(
                    $customer,
                    $charge,
                    $plan['offer'],
                    $plan['free_by_service_id'],
                    $plan['free_needs_approval'],
                    $actor,
                    $plan['free_needs_approval'] ? $installation->reason : null,
                );

                // The installation month (or later ones) may already be
                // generated: write this customer's rows now (Option X).
                $this->grants->recalculateFrom($customer, $plan['installation_month']);
                $customer->touch();

                return ['customer' => $customer, 'grants' => $grants, 'installation_month' => $plan['installation_month']];
            });
        });
    }

    /**
     * A new customer's service prices must be a default, a price option or
     * — for someone allowed — an amount at or above the minimum
     * (CustomerSubscriptionService::assertPricesAllowed()). A bill typed
     * without a service list is the default service at that price.
     */
    private function assertPricesAllowed(CustomerData $customerData): void
    {
        $subscriptions = app(CustomerSubscriptionService::class);
        $branchId = $this->branchOfZone($customerData->zoneUuid);

        $subscriptions->assertPricesAllowed(
            $customerData->services ?? [$subscriptions->defaultSelection($customerData->bill, $branchId)],
            null,
            $branchId,
        );
    }

    /** The branch a new customer joins through their zone (null: the branch being worked in). */
    private function branchOfZone(?string $zoneUuid): ?int
    {
        $branchId = $zoneUuid !== null ? Zone::query()->where('uuid', $zoneUuid)->value('branch_id') : null;

        return $branchId !== null ? (int) $branchId : null;
    }

    /**
     * Resolves and checks the section, and works out what it means.
     *
     * `$field` relaxes what the office settles later for an offline field
     * installation (see createPending()). `$activation` works from the
     * `$charge` recorded in the field: its fee was settled there, so the
     * offer's fee is not compared with it again.
     *
     * @param  list<CustomerServiceSelection>  $services
     * @return array{offer: ?InstallationOffer, standard_fee: ?string, charge_amount: ?string, fee_needs_approval: bool, waiver_needed: bool, free_by_service_id: array<int, array{from: string, months: int}>, free_needs_approval: bool, service_months: list<array{service_id: int, months: int, rate: string}>, upfront_amount: string, connected_on: string, installation_month: string, summary: array<string, mixed>}
     */
    private function planFor(array $services, InstallationData $installation, bool $field = false, bool $activation = false, ?CustomerCharge $charge = null, ?int $branchId = null, bool $preview = false): array
    {
        $today = BusinessTimezone::today();
        // The customer's branch's prices and offer fees (branches v2 D4).
        $branchPrices = $branchId !== null ? BranchPrices::for($branchId) : BranchPrices::current();
        $offer = null;

        if ($installation->offerUuid !== null) {
            $offer = InstallationOffer::query()->with('serviceMonths')->where('uuid', $installation->offerUuid)->first();

            if ($offer === null || (! $field && ! $offer->isAvailable($today))) {
                throw ValidationException::withMessages(['installation_offer_uuid' => 'This installation offer is not available.']);
            }
        }

        // Service prices from the form (the customer doesn't exist yet).
        $catalogue = Service::query()->whereIn('uuid', array_map(fn (CustomerServiceSelection $s): string => $s->serviceUuid, $services))->get()->keyBy('uuid');
        $prices = [];
        $bill = '0.00';

        foreach ($services as $selection) {
            $service = $catalogue->get($selection->serviceUuid);

            if ($service !== null) {
                $prices[$service->id] = bcadd($prices[$service->id] ?? '0', $selection->price, 2);
                $bill = bcadd($bill, $selection->price, 2);
            }
        }

        if ($services === [] && ($default = Service::default()) !== null) {
            $prices[$default->id] = $branchPrices->service($default);
            $bill = $prices[$default->id];
        }

        $serviceNames = Service::query()->whereIn('id', array_keys($prices))->pluck('name', 'id');
        $uuidToId = Service::query()->whereIn('id', array_keys($prices))->pluck('id', 'uuid');

        // The installation month: when the customer was connected, never a
        // month that has already passed (locked).
        $connectedOn = $installation->connectedOn ?? $today;

        if ($connectedOn > $today) {
            // A phone's clock may run ahead of the server's.
            if (! $field) {
                throw ValidationException::withMessages(['connected_on' => 'The connection date cannot be in the future.']);
            }

            $connectedOn = $today;
        }

        // Free months count from the real connection month; the ledger starts
        // there, or now when that month has closed (ConnectionMonth).
        $connectionMonth = ConnectionMonth::of($connectedOn);
        $installationMonth = ConnectionMonth::billingStarts($connectedOn, $this->lock->currentPeriod());
        $late = ConnectionMonth::isLate($connectedOn, $this->lock->currentPeriod());

        // A late registration says why (the office's to give for a field one,
        // at activation). What the customer says they paid, and to whom, is
        // optional (owner, 2026-10-26) — left blank means "not asked".
        if ($late && ! $preview && ($activation || ! $field) && $installation->reason === null) {
            throw ValidationException::withMessages([
                'installation_reason' => 'The customer was connected in '.CustomerCoverageService::monthLabel($connectionMonth).' — say why they are registered only now.',
            ]);
        }

        if ($activation) {
            // Activating a field registration: the fee was settled in the
            // field — what the charge records stands (an offer picked now
            // only presets free months).
            $standardFee = $charge?->standard_amount !== null ? bcadd((string) $charge->standard_amount, '0', 2) : null;
            $chargeAmount = $charge !== null ? bcadd((string) $charge->amount, '0', 2) : null;
            $waiverNeeded = $charge?->waiver_status !== null;
            $feeNeedsApproval = $charge?->approval_status !== null;
        } else {
            // Fee: the offer's unless overridden.
            $standardFee = $offer !== null ? $branchPrices->offerFee($offer) : null;
            $fee = $installation->fee !== null ? bcadd($installation->fee, '0', 2) : ($standardFee ?? '0.00');

            if (bccomp($fee, '0', 2) < 0) {
                throw ValidationException::withMessages(['installation_fee' => 'The installation fee cannot be negative.']);
            }

            $waiverNeeded = $standardFee !== null && bccomp($standardFee, '0', 2) > 0 && bccomp($fee, '0', 2) === 0;
            $feeNeedsApproval = ! $waiverNeeded && $standardFee !== null && bccomp($fee, $standardFee, 2) < 0;
            $chargeAmount = $waiverNeeded ? $standardFee : (bccomp($fee, '0', 2) > 0 ? $fee : null);
        }

        $paidNow = bcadd($installation->paidNow, '0', 2);

        if (bccomp($paidNow, $chargeAmount ?? '0', 2) > 0) {
            throw ValidationException::withMessages(['installation_paid' => 'More than the installation fee cannot be paid toward it.']);
        }

        if ($waiverNeeded && bccomp($paidNow, '0', 2) > 0) {
            throw ValidationException::withMessages(['installation_paid' => 'A waived installation fee is not paid — set the amount paid to 0.']);
        }

        // Per service: free months (consecutive, from the installation month
        // or later) and months paid upfront (D15/D16). No plan sent = the
        // offer's preset from the installation month (D19).
        $presets = $offer?->monthsByService() ?? [];
        $plans = [];

        if ($installation->servicePlans === null) {
            foreach ($presets as $serviceId => $months) {
                if (isset($prices[$serviceId])) {
                    $plans[$serviceId] = ['free_from' => $connectionMonth, 'free_months' => $months, 'paid_months' => 0];
                }
            }
        } else {
            foreach ($installation->servicePlans as $uuid => $row) {
                $serviceId = $uuidToId[$uuid] ?? null;

                if ($serviceId === null) {
                    if ($row['free_months'] > 0 || $row['paid_months'] > 0) {
                        throw ValidationException::withMessages(['service_plans' => 'Free or paid months can only be chosen on services the customer takes.']);
                    }

                    continue;
                }

                $plans[(int) $serviceId] = [...$row, 'free_from' => $row['free_from'] ?? $connectionMonth];
            }
        }

        $max = FreeMonthGrantService::maxMonths();
        $freeById = [];
        $serviceMonths = [];
        $beyondCap = false;
        $beyondOffer = false;

        foreach ($plans as $serviceId => $row) {
            if ($row['free_months'] < 0 || $row['free_months'] > 36 || $row['paid_months'] < 0 || $row['paid_months'] > 24) {
                throw ValidationException::withMessages(['service_plans' => 'Free months are 0–36 and paid months 0–24 per service.']);
            }

            if ($row['free_months'] > 0) {
                if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $row['free_from']) || $row['free_from'] < $connectionMonth) {
                    throw ValidationException::withMessages(['service_plans' => 'Free months start at the connection month or later.']);
                }

                $freeById[$serviceId] = ['from' => (string) $row['free_from'], 'months' => $row['free_months']];
                $beyondCap = $beyondCap || $row['free_months'] > $max;
                $beyondOffer = $beyondOffer || ($offer !== null && $row['free_months'] > ($presets[$serviceId] ?? 0));
            }

            if ($row['paid_months'] > 0) {
                $serviceMonths[] = ['service_id' => $serviceId, 'months' => $row['paid_months'], 'rate' => $prices[$serviceId]];
            }
        }

        $settledAtRegistration = ! $feeNeedsApproval && ! $waiverNeeded && ($chargeAmount === null || bccomp($paidNow, $chargeAmount, 2) >= 0);
        $freeNeedsApproval = $freeById !== [] && (! $settledAtRegistration || $beyondCap || $beyondOffer);

        $needsApproval = array_values(array_filter([
            $feeNeedsApproval ? 'Installation fee below the offer' : null,
            $waiverNeeded ? 'Installation fee waived' : null,
            $freeById !== [] && ! $settledAtRegistration ? 'Free months without the installation fully paid' : null,
            $beyondCap ? "More than {$max} free months" : null,
            $beyondOffer ? 'Free months beyond the offer' : null,
        ]));

        // A field registration's reason is the office's to give, at activation.
        if ($needsApproval !== [] && ! $preview && $installation->reason === null && ($activation || ! $field)) {
            throw ValidationException::withMessages(['installation_reason' => 'Give the reason for '.mb_strtolower(implode(', ', $needsApproval)).' — it needs approval.']);
        }

        $upfront = '0.00';

        foreach ($serviceMonths as $row) {
            $upfront = bcadd($upfront, bcmul((string) $row['months'], $row['rate'], 2), 2);
        }

        // Each service's timeline as the billing engine will play it, the
        // free months shown as if approved.
        $windows = [];

        foreach ($freeById as $serviceId => $row) {
            $windows[] = ['service_id' => $serviceId, 'start' => $row['from'], 'end' => FreeMonthGrant::addMonths($row['from'], $row['months'] - 1)];
        }

        // Paid and billed months from the first open month (never a closed one)…
        $walk = (new FreeMonthPlan($installationMonth, $prices, $windows))->timelines(
            $installationMonth,
            array_combine(array_column($serviceMonths, 'service_id'), array_column($serviceMonths, 'months')) ?: [],
        );
        // …free months from the real connection month, closed ones included.
        $freeWalk = $late ? (new FreeMonthPlan($connectionMonth, $prices, $windows))->timelines($connectionMonth)['services'] : [];

        $timelines = [];

        foreach ($walk['services'] as $serviceId => $timeline) {
            if ($late) {
                $timeline['free_from'] = $freeWalk[$serviceId]['free_from'];
                $timeline['free_until'] = $freeWalk[$serviceId]['free_until'];
            }

            // Months after the free ones that closed before registration: not billed (an admin may charge them).
            $notBilledFrom = $timeline['free_until'] !== null ? FreeMonthGrant::addMonths($timeline['free_until'], 1) : $connectionMonth;
            $notBilledUntil = FreeMonthGrant::addMonths($installationMonth, -1);

            $timelines[] = [
                'service_uuid' => (string) array_search($serviceId, $uuidToId->all(), true),
                'service' => (string) ($serviceNames[$serviceId] ?? 'Service'),
                'price' => $prices[$serviceId],
                ...$timeline,
                'paid_amount' => bcmul((string) ($plans[$serviceId]['paid_months'] ?? 0), $prices[$serviceId], 2),
                'not_billed_from' => $late && $notBilledFrom <= $notBilledUntil ? $notBilledFrom : null,
                'not_billed_until' => $late && $notBilledFrom <= $notBilledUntil ? $notBilledUntil : null,
            ];
        }

        $collectNow = bcadd($paidNow, $upfront, 2);

        return [
            'offer' => $offer,
            'standard_fee' => $standardFee,
            'charge_amount' => $chargeAmount,
            'fee_needs_approval' => $feeNeedsApproval,
            'waiver_needed' => $waiverNeeded,
            'free_by_service_id' => $freeById,
            'free_needs_approval' => $freeNeedsApproval,
            'service_months' => $serviceMonths,
            'upfront_amount' => $upfront,
            'connected_on' => $connectedOn,
            'installation_month' => $installationMonth,
            'connection_month' => $connectionMonth,
            'late' => $late,
            'summary' => [
                'connection_month' => $connectionMonth,
                'connection_month_label' => CustomerCoverageService::monthLabel($connectionMonth),
                'late' => $late,
                'bill' => $bill,
                'fee' => $chargeAmount !== null && ! $waiverNeeded ? $chargeAmount : '0.00',
                'standard_fee' => $standardFee,
                'paid_now' => $paidNow,
                'installation_balance' => $chargeAmount !== null && ! $waiverNeeded ? bcsub($chargeAmount, $paidNow, 2) : '0.00',
                'settled_now' => $settledAtRegistration,
                'installation_month' => $installationMonth,
                'installation_month_label' => CustomerCoverageService::monthLabel($installationMonth),
                'services' => $timelines,
                'upfront_amount' => $upfront,
                'first_bill' => $walk['first_bill'],
                'first_bill_label' => $walk['first_bill'] !== null ? CustomerCoverageService::monthLabel($walk['first_bill']) : null,
                'collect_now' => $collectNow,
                'needs_approval' => $needsApproval,
                'max_free_months' => $max,
                'line' => $this->line($collectNow, $timelines, $freeNeedsApproval),
            ],
        ];
    }

    /**
     * "Collect now: 17,500 · TV Service: free Oct–Nov 2026, paid Dec 2026–Feb
     * 2027, billed from Mar 2027".
     *
     * @param  list<array<string, mixed>>  $timelines
     */
    private function line(string $collectNow, array $timelines, bool $freeAwaitingApproval): string
    {
        $label = app(CustomerCoverageService::class)->label($timelines);

        return 'Collect now: '.number_format((float) $collectNow, 0, '.', ',')
            .($label !== null ? ' · '.$label : '')
            .($freeAwaitingApproval ? ' (free months awaiting approval)' : '');
    }
}
