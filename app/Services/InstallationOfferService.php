<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstallationOffer;
use App\Models\InstallationOfferService as OfferServiceMonths;
use App\Models\Service;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Installation offers (docs/plans/installation-and-promotions.md,
 * phase 2, item 1) — the only writer of App\Models\InstallationOffer. An
 * offer is a fee and free months per service (D7), each capped by
 * companies.max_free_months (D8), with an optional validity window. Since
 * 2026-10-26 offers are promotions: an admin switches one on for a campaign,
 * staff pick it on purpose — never preselected (free months are the default
 * without one). Offers are copied onto a customer when picked, so editing
 * one never changes past customers.
 */
class InstallationOfferService
{
    /**
     * @param  array{name: string, description?: ?string, fee: string|int|float, valid_from?: ?string, valid_to?: ?string, active?: bool, is_default?: bool, months?: list<array{service_uuid: string, months: int}>}  $data
     */
    public function save(?InstallationOffer $offer, array $data): InstallationOffer
    {
        $months = $this->resolveMonths($data['months'] ?? []);

        return DB::transaction(function () use ($offer, $data, $months): InstallationOffer {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'fee' => $data['fee'],
                'valid_from' => $data['valid_from'] ?? null,
                'valid_to' => $data['valid_to'] ?? null,
                'active' => (bool) ($data['active'] ?? true),
                // Promotions are never preselected (owner, 2026-10-26).
                'is_default' => false,
            ];

            if ($offer === null) {
                $offer = InstallationOffer::query()->create($attributes);
            } else {
                $offer->update($attributes);
            }

            $offer->serviceMonths()->whereNotIn('service_id', array_keys($months))->delete();

            foreach ($months as $serviceId => $freeMonths) {
                OfferServiceMonths::query()->updateOrCreate(
                    ['installation_offer_id' => $offer->id, 'service_id' => $serviceId],
                    ['free_months' => $freeMonths],
                );
            }

            return $offer->load('serviceMonths');
        });
    }

    /**
     * An offer already picked for a customer keeps its row (the customer's
     * charge points at it); it can only be retired.
     */
    public function delete(InstallationOffer $offer): void
    {
        $used = DB::table('customer_charges')->where('installation_offer_id', $offer->id)->count();

        if ($used > 0) {
            throw ValidationException::withMessages(['offer' => "{$used} installation(s) used \"{$offer->name}\" — deactivate it instead of deleting it."]);
        }

        $offer->delete();
    }

    /**
     * The offers a manager may pick today, shaped for the add-customer form
     * (web and mobile), by name.
     *
     * @return list<array{uuid: string, name: string, description: ?string, fee: string, is_default: bool, months: list<array{service_uuid: string, service_name: string, months: int}>}>
     */
    public function availableForForm(?int $branchId = null): array
    {
        // The fee of the customer's branch (or the branch being worked in) — branches v2 D4.
        $prices = $branchId !== null ? BranchPrices::for($branchId) : BranchPrices::current();

        return InstallationOffer::query()
            ->available(BusinessTimezone::today())
            ->with('serviceMonths.service')
            ->orderBy('name')
            ->get()
            ->map(fn (InstallationOffer $offer): array => [...$this->shape($offer), 'fee' => $prices->offerFee($offer)])
            ->values()
            ->all();
    }

    /**
     * @return array{uuid: string, name: string, description: ?string, fee: string, is_default: bool, months: list<array{service_uuid: string, service_name: string, months: int}>}
     */
    public function shape(InstallationOffer $offer): array
    {
        $offer->loadMissing('serviceMonths.service');

        return [
            'uuid' => $offer->uuid,
            'name' => $offer->name,
            'description' => $offer->description,
            'fee' => bcadd((string) $offer->fee, '0', 2),
            // Always false: no promotion is preselected — older phone builds read this to preselect one.
            'is_default' => false,
            'months' => $offer->serviceMonths
                ->map(fn (OfferServiceMonths $row): array => [
                    'service_uuid' => (string) $row->service?->uuid,
                    'service_name' => (string) $row->service?->name,
                    'months' => (int) $row->free_months,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<array{service_uuid: string, months: int|string}>  $rows
     * @return array<int, int> service id => months (rows with 0 dropped)
     */
    private function resolveMonths(array $rows): array
    {
        $max = FreeMonthGrantService::maxMonths();
        $ids = Service::query()->whereIn('uuid', array_column($rows, 'service_uuid'))->pluck('id', 'uuid');
        $months = [];

        foreach ($rows as $row) {
            $value = (int) $row['months'];

            if ($value === 0) {
                continue;
            }

            if ($value < 0 || $value > $max) {
                throw ValidationException::withMessages(['months' => "Free months per service are between 0 and {$max} (Settings → Company)."]);
            }

            $serviceId = $ids[$row['service_uuid']] ?? null;

            if ($serviceId === null) {
                throw ValidationException::withMessages(['months' => 'Unknown service.']);
            }

            $months[(int) $serviceId] = $value;
        }

        return $months;
    }
}
