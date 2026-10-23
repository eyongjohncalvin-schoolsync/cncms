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
use App\Models\Service;
use App\Models\User;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BusinessTimezone;
use Illuminate\Support\Carbon;
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
            ...$this->planFor($services, $installation)['summary'],
            'duplicates' => $this->possibleDuplicates($name, $phone),
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

        return Customer::query()
            ->with('zone:id,name')
            ->where(function ($query) use ($lastNine, $name): void {
                if ($lastNine !== null) {
                    $query->orWhereRaw("regexp_replace(coalesce(phone, ''), '\\D', '', 'g') like ?", ['%'.$lastNine]);
                }

                if (mb_strlen($name) >= 3) {
                    $query->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]);
                }
            })
            ->limit(5)
            ->get()
            ->map(fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'zone' => $customer->zone?->name,
                'status' => $customer->status,
            ])
            ->all();
    }

    /**
     * @return array{customer: Customer, payments: list<Payment>, charge: ?CustomerCharge, grants: Collection<int, FreeMonthGrant>}
     */
    public function create(CustomerData $customerData, InstallationData $installation, User $actor): array
    {
        $plan = $this->planFor($customerData->services ?? [], $installation);

        return DB::transaction(function () use ($customerData, $installation, $actor, $plan): array {
            return $this->grants->deferRecalculation(function () use ($customerData, $installation, $actor, $plan): array {
                $customer = $this->customers->create($customerData);
                $customer->forceFill([
                    'connected_on' => $plan['connected_on'],
                    'billing_starts_period' => $plan['installation_month'],
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
     * Resolves and checks the section, and works out what it means.
     *
     * @param  list<CustomerServiceSelection>  $services
     * @return array{offer: ?InstallationOffer, standard_fee: ?string, charge_amount: ?string, fee_needs_approval: bool, waiver_needed: bool, free_by_service_id: array<int, array{from: string, months: int}>, free_needs_approval: bool, service_months: list<array{service_id: int, months: int, rate: string}>, upfront_amount: string, connected_on: string, installation_month: string, summary: array<string, mixed>}
     */
    private function planFor(array $services, InstallationData $installation): array
    {
        $today = BusinessTimezone::today();
        $offer = null;

        if ($installation->offerUuid !== null) {
            $offer = InstallationOffer::query()->with('serviceMonths')->where('uuid', $installation->offerUuid)->first();

            if ($offer === null || ! $offer->isAvailable($today)) {
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
            $prices[$default->id] = bcadd((string) $default->price, '0', 2);
            $bill = $prices[$default->id];
        }

        $serviceNames = Service::query()->whereIn('id', array_keys($prices))->pluck('name', 'id');
        $uuidToId = Service::query()->whereIn('id', array_keys($prices))->pluck('id', 'uuid');

        // The installation month: when the customer was connected, never a
        // month that has already passed (locked).
        $connectedOn = $installation->connectedOn ?? $today;

        if ($connectedOn > $today) {
            throw ValidationException::withMessages(['connected_on' => 'The connection date cannot be in the future.']);
        }

        $installationMonth = max(BusinessTimezone::periodOf(Carbon::parse($connectedOn, BusinessTimezone::WAT)), $this->lock->currentPeriod());

        // Fee: the offer's unless overridden.
        $standardFee = $offer !== null ? bcadd((string) $offer->fee, '0', 2) : null;
        $fee = $installation->fee !== null ? bcadd($installation->fee, '0', 2) : ($standardFee ?? '0.00');

        if (bccomp($fee, '0', 2) < 0) {
            throw ValidationException::withMessages(['installation_fee' => 'The installation fee cannot be negative.']);
        }

        $waiverNeeded = $standardFee !== null && bccomp($standardFee, '0', 2) > 0 && bccomp($fee, '0', 2) === 0;
        $feeNeedsApproval = ! $waiverNeeded && $standardFee !== null && bccomp($fee, $standardFee, 2) < 0;
        $chargeAmount = $waiverNeeded ? $standardFee : (bccomp($fee, '0', 2) > 0 ? $fee : null);
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
                    $plans[$serviceId] = ['free_from' => $installationMonth, 'free_months' => $months, 'paid_months' => 0];
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

                $plans[(int) $serviceId] = [...$row, 'free_from' => $row['free_from'] ?? $installationMonth];
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
                if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $row['free_from']) || $row['free_from'] < $installationMonth) {
                    throw ValidationException::withMessages(['service_plans' => 'Free months start at the installation month or later.']);
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

        if ($needsApproval !== [] && $installation->reason === null) {
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

        $walk = (new FreeMonthPlan($installationMonth, $prices, $windows))->timelines(
            $installationMonth,
            array_combine(array_column($serviceMonths, 'service_id'), array_column($serviceMonths, 'months')) ?: [],
        );

        $timelines = [];

        foreach ($walk['services'] as $serviceId => $timeline) {
            $timelines[] = [
                'service_uuid' => (string) array_search($serviceId, $uuidToId->all(), true),
                'service' => (string) ($serviceNames[$serviceId] ?? 'Service'),
                'price' => $prices[$serviceId],
                ...$timeline,
                'paid_amount' => bcmul((string) ($plans[$serviceId]['paid_months'] ?? 0), $prices[$serviceId], 2),
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
            'summary' => [
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
