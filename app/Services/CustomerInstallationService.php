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
 * (docs/plans/installation-and-promotions.md, phase 2, item 5) — web and
 * mobile (online) share this service.
 *
 *  - preview() — the live "Collect now: 17,500 · TV free Nov–Dec · first
 *    bill Jan 2027" line the form shows while it is being filled in. Never
 *    writes anything.
 *  - create() — ONE save in ONE transaction: the customer, the installation
 *    charge and its payment, an optional advance payment and the free
 *    months, each payment with its receipt. The month after the customer
 *    joins is their first billed month (D4); if that month's bills were
 *    already generated, the customer's own row is calculated straight away
 *    so nothing is skipped.
 *
 * The rules (D3–D8): free months come from the offer, per service, and
 * start the month after the installation is fully paid or waived; until
 * then the customer is billed normally. A fee below the offer's, months
 * beyond the offer and a full waiver need a reason and admin/owner
 * approval. An advance covers exactly what is charged each month, and the
 * amount is computed here, never typed.
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
        $plan = $this->planFor($services, $installation);

        return [
            ...$plan['summary'],
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
        $services = $customerData->services ?? [];
        $plan = $this->planFor($services, $installation);

        return DB::transaction(function () use ($customerData, $installation, $actor, $plan): array {
            return $this->grants->deferRecalculation(function () use ($customerData, $installation, $actor, $plan): array {
                $customer = $this->customers->create($customerData);
                $customer->forceFill([
                    'connected_on' => $plan['connected_on'],
                    'billing_starts_period' => $plan['billing_starts'],
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
                    $plan['months_by_service_id'],
                    $actor,
                    $plan['months_need_approval'] ? $installation->reason : null,
                );

                // The advance first, so the installation receipt (issued
                // when the fee is paid) can already show "paid until".
                if ($installation->advanceMonths > 0) {
                    $payments[] = $this->payments->createForCustomer($customer, new PaymentData(
                        customerUuid: $customer->uuid,
                        amount: $plan['summary']['advance']['amount'],
                        frequency: 'months',
                        months: $installation->advanceMonths,
                        clearArrearsFirst: true,
                        method: $installation->method,
                        methodReference: $installation->methodReference,
                    ));
                }

                if ($charge !== null && bccomp($installation->paidNow, '0', 2) > 0) {
                    $payments[] = $this->charges->recordPayment($charge->fresh(), $installation->paidNow, $installation->method, $installation->methodReference);
                }

                // D4: the first billed month — if its bills (or later ones)
                // were already generated, write this customer's rows now.
                $this->grants->recalculateFrom($customer, $plan['billing_starts']);

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
     * @return array{offer: ?InstallationOffer, standard_fee: ?string, charge_amount: ?string, fee_needs_approval: bool, waiver_needed: bool, months_by_service_id: array<int, int>, months_need_approval: bool, connected_on: string, billing_starts: string, summary: array<string, mixed>}
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

            if ($service === null) {
                continue;
            }

            $prices[$service->id] = bcadd($prices[$service->id] ?? '0', $selection->price, 2);
            $bill = bcadd($bill, $selection->price, 2);
        }

        if ($services === []) {
            $default = Service::default();

            if ($default !== null) {
                $prices[$default->id] = bcadd((string) $default->price, '0', 2);
                $bill = $prices[$default->id];
            }
        }

        $serviceNames = Service::query()->whereIn('id', array_keys($prices))->pluck('name', 'id');

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

        // Free months per service: the offer's unless changed.
        $standardMonths = $offer?->monthsByService() ?? [];
        $monthsByServiceId = [];

        if ($installation->freeMonths === null) {
            foreach ($standardMonths as $serviceId => $months) {
                if (isset($prices[$serviceId])) {
                    $monthsByServiceId[$serviceId] = $months;
                }
            }
        } else {
            $uuids = Service::query()->whereIn('uuid', array_keys($installation->freeMonths))->pluck('id', 'uuid');

            foreach ($installation->freeMonths as $uuid => $months) {
                $serviceId = $uuids[$uuid] ?? null;

                if ($serviceId === null || ! isset($prices[$serviceId])) {
                    if ($months > 0) {
                        throw ValidationException::withMessages(['free_months' => 'Free months can only be given on services the customer takes.']);
                    }

                    continue;
                }

                if ($months > 0) {
                    $monthsByServiceId[(int) $serviceId] = $months;
                }
            }
        }

        $max = FreeMonthGrantService::maxMonths();

        foreach ($monthsByServiceId as $months) {
            if ($months < 0 || $months > $max) {
                throw ValidationException::withMessages(['free_months' => "At most {$max} free months per service."]);
            }
        }

        $monthsNeedApproval = collect($monthsByServiceId)->contains(fn (int $months, int $serviceId): bool => $months > ($standardMonths[$serviceId] ?? 0));

        $needsApproval = array_values(array_filter([
            $feeNeedsApproval ? 'Installation fee below the offer' : null,
            $waiverNeeded ? 'Installation fee waived' : null,
            $monthsNeedApproval ? 'Free months beyond the offer' : null,
        ]));

        if ($needsApproval !== [] && $installation->reason === null) {
            throw ValidationException::withMessages(['installation_reason' => 'Give the reason for '.mb_strtolower(implode(', ', $needsApproval)).' — it needs approval.']);
        }

        if ($installation->advanceMonths < 0 || $installation->advanceMonths > 24) {
            throw ValidationException::withMessages(['advance_months' => 'An advance is between 1 and 24 months.']);
        }

        // D4: the month the customer joins is not billed; never a month
        // that has already passed.
        $connectedOn = $installation->connectedOn ?? $today;

        if ($connectedOn > $today) {
            throw ValidationException::withMessages(['connected_on' => 'The connection date cannot be in the future.']);
        }

        $billingStarts = max(FreeMonthGrant::addMonths(BusinessTimezone::periodOf(Carbon::parse($connectedOn, BusinessTimezone::WAT)), 1), $this->lock->currentPeriod());

        // The free months start the month after the fee is settled (D3) —
        // today, if it is settled at this save.
        $settledNow = ! $feeNeedsApproval && ! $waiverNeeded && ($chargeAmount === null || bccomp($paidNow, $chargeAmount, 2) >= 0);
        $freeStart = max(FreeMonthGrant::addMonths(BusinessTimezone::currentPeriod(), 1), $billingStarts);
        $startsNow = $settledNow && ! $monthsNeedApproval;

        $windows = [];

        if ($startsNow) {
            foreach ($monthsByServiceId as $serviceId => $months) {
                $windows[] = ['service_id' => $serviceId, 'start' => $freeStart, 'end' => FreeMonthGrant::addMonths($freeStart, $months - 1)];
            }
        }

        $freePlan = new FreeMonthPlan($billingStarts, $prices, $windows);
        $advance = null;

        if ($installation->advanceMonths > 0) {
            $bought = $freePlan->monthsBought($billingStarts, '999999999.99', $bill, $installation->advanceMonths, 0, $bill);
            $covered = $freePlan->coverage($billingStarts, $bill, $bought['months']);
            $advance = [
                'months' => $bought['months'],
                'amount' => $bought['cost'],
                'from' => $covered['paid_from'],
                'until' => $covered['paid_until'],
                'from_label' => $covered['paid_from'] !== null ? CustomerCoverageService::monthLabel($covered['paid_from']) : null,
                'until_label' => $covered['paid_until'] !== null ? CustomerCoverageService::monthLabel($covered['paid_until']) : null,
            ];
        }

        $walk = $freePlan->coverage($billingStarts, $bill, $advance['months'] ?? 0);

        $free = [];

        foreach ($monthsByServiceId as $serviceId => $months) {
            $window = $startsNow ? ['start' => $freeStart, 'end' => FreeMonthGrant::addMonths($freeStart, $months - 1)] : null;
            $free[] = [
                'service' => (string) ($serviceNames[$serviceId] ?? 'Service'),
                'months' => $months,
                'from' => $window['start'] ?? null,
                'until' => $window['end'] ?? null,
                'from_label' => $window !== null ? CustomerCoverageService::monthLabel($window['start']) : null,
                'until_label' => $window !== null ? CustomerCoverageService::monthLabel($window['end']) : null,
                'waiting' => $window === null,
            ];
        }

        $collectNow = bcadd($paidNow, $advance['amount'] ?? '0', 2);

        return [
            'offer' => $offer,
            'standard_fee' => $standardFee,
            'charge_amount' => $chargeAmount,
            'fee_needs_approval' => $feeNeedsApproval,
            'waiver_needed' => $waiverNeeded,
            'months_by_service_id' => $monthsByServiceId,
            'months_need_approval' => $monthsNeedApproval,
            'connected_on' => $connectedOn,
            'billing_starts' => $billingStarts,
            'summary' => [
                'bill' => $bill,
                'fee' => $chargeAmount !== null && ! $waiverNeeded ? $chargeAmount : '0.00',
                'standard_fee' => $standardFee,
                'paid_now' => $paidNow,
                'installation_balance' => $chargeAmount !== null && ! $waiverNeeded ? bcsub($chargeAmount, $paidNow, 2) : '0.00',
                'settled_now' => $settledNow,
                'billing_starts' => $billingStarts,
                'billing_starts_label' => CustomerCoverageService::monthLabel($billingStarts),
                'free' => $free,
                'advance' => $advance,
                'first_bill' => $walk['first_bill'],
                'first_bill_label' => $walk['first_bill'] !== null ? CustomerCoverageService::monthLabel($walk['first_bill']) : null,
                'collect_now' => $collectNow,
                'needs_approval' => $needsApproval,
                'max_free_months' => $max,
                'line' => $this->line($collectNow, $free, $walk['first_bill']),
            ],
        ];
    }

    /**
     * "Collect now: 17,500 · TV free Nov–Dec · Internet free Nov · first bill Jan 2027".
     *
     * @param  list<array<string, mixed>>  $free
     */
    private function line(string $collectNow, array $free, ?string $firstBill): string
    {
        $parts = ['Collect now: '.number_format((float) $collectNow, 0, '.', ',')];

        foreach ($free as $window) {
            if ($window['waiting']) {
                $parts[] = "{$window['service']} {$window['months']} free month(s) once installation is paid";

                continue;
            }

            $from = Carbon::createFromFormat('!Y-m', $window['from'])->format('M');
            $until = Carbon::createFromFormat('!Y-m', $window['until'])->format('M');
            $parts[] = "{$window['service']} free ".($from === $until ? $from : "{$from}–{$until}");
        }

        if ($firstBill !== null) {
            $parts[] = 'first bill '.CustomerCoverageService::monthLabel($firstBill);
        }

        return implode(' · ', $parts);
    }
}
