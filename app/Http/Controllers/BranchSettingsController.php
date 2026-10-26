<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SetBranchPriceRequest;
use App\Http\Requests\UpdateBranchSettingsRequest;
use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\Company;
use App\Models\InstallationOffer;
use App\Models\PrepaymentOffer;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Services\BranchPriceService;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A branch's own contact details, MoMo number and fees (branches v2 §4.6,
 * D4): what its bills, receipts and messages print, and the fees its staff
 * charge. Empty fields inherit headquarters' company settings. Managed by
 * whoever manages branches, or by the branch's own staff
 * (BranchPolicy::manageSettings()).
 */
class BranchSettingsController extends Controller
{
    /** "My branch" for a branch's own staff. */
    public function mine(TenantContext $context): RedirectResponse
    {
        abort_unless($context->isFenced(), 404);

        $branch = Branch::query()->findOrFail($context->branchId);

        return redirect()->route('branches.settings.edit', $branch);
    }

    public function edit(Branch $branch, BranchPriceService $prices): Response
    {
        $this->authorize('manageSettings', $branch);

        $headquarters = Company::cached();

        return Inertia::render('Branches/Settings', [
            'branch' => [
                'uuid' => $branch->uuid,
                'name' => $branch->name,
                'code' => $branch->code,
                'sends_to_hq' => $branch->sends_to_hq,
                ...collect(Branch::OVERRIDABLE)->mapWithKeys(fn (string $field): array => [$field => $branch->{$field} !== null ? (string) $branch->{$field} : null])->all(),
            ],
            // Shown as each field's placeholder: what the branch uses when the field is empty.
            'headquarters' => collect(Branch::OVERRIDABLE)
                ->mapWithKeys(fn (string $field): array => [$field => $headquarters?->{$field} !== null ? (string) $headquarters->{$field} : null])
                ->all(),
            'can_manage_branches' => request()->user()->can('update', $branch),
            'prices' => $this->priceList($branch),
            'price_history' => $this->priceHistory($branch, $prices),
        ]);
    }

    public function update(UpdateBranchSettingsRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return redirect()->route('branches.settings.edit', $branch)->with('success', "{$branch->name} settings saved.");
    }

    public function setPrice(SetBranchPriceRequest $request, Branch $branch, BranchPriceService $prices): RedirectResponse
    {
        $data = $request->validated();
        $price = isset($data['price']) ? (string) $data['price'] : null;

        $result = $prices->set($branch, $data['target_type'], $data['target_uuid'], $price, $data['reason'], $request->user(), (bool) ($data['apply_to_subscribers'] ?? false));

        $message = $price === null ? 'Back to headquarters\' price.' : 'Price saved.';

        if ($result['repriced'] > 0) {
            $message .= $result['repriced'] === 1 ? ' 1 subscriber repriced.' : " {$result['repriced']} subscribers repriced.";
        }

        return redirect()->route('branches.settings.edit', $branch)->with('success', $message);
    }

    /**
     * Every active service (with its options) and available installation
     * offer: headquarters' price beside the branch's own, if it sets one.
     *
     * @return array{services: list<array<string, mixed>>, offers: list<array<string, mixed>>}
     */
    private function priceList(Branch $branch): array
    {
        $prices = BranchPrices::for($branch->id);

        return [
            'services' => Service::query()->active()->ordered()->with(['variants' => fn ($query) => $query->active()->ordered()])->get()
                ->map(fn (Service $service): array => [
                    'uuid' => $service->uuid,
                    'name' => $service->name,
                    'hq_price' => number_format((float) $service->price, 2, '.', ''),
                    'branch_price' => $prices->overridesService($service->id) ? $prices->service($service) : null,
                    'variants' => $service->variants->map(fn (ServiceVariant $variant): array => [
                        'uuid' => $variant->uuid,
                        'name' => $variant->name,
                        'hq_price' => number_format((float) $variant->price, 2, '.', ''),
                        'branch_price' => $prices->overridesVariant($variant->id) ? $prices->variant($variant) : null,
                    ])->values()->all(),
                ])->values()->all(),
            'offers' => InstallationOffer::query()->available(BusinessTimezone::today())->orderBy('name')->get()
                ->map(fn (InstallationOffer $offer): array => [
                    'uuid' => $offer->uuid,
                    'name' => $offer->name,
                    'hq_price' => number_format((float) $offer->fee, 2, '.', ''),
                    'branch_price' => $prices->overridesOffer($offer->id) ? $prices->offerFee($offer) : null,
                ])->values()->all(),
            // Prepayment offers on sale ("4 months for 10,000") — a branch may sell at its own price.
            'prepayment_offers' => PrepaymentOffer::query()->available(BusinessTimezone::today())->orderBy('months')->get()
                ->map(fn (PrepaymentOffer $offer): array => [
                    'uuid' => $offer->uuid,
                    'name' => "{$offer->name} ({$offer->months} months)",
                    'hq_price' => number_format((float) $offer->price, 2, '.', ''),
                    'branch_price' => $prices->overridesPrepaymentOffer($offer->id) ? $prices->prepaymentOfferPrice($offer) : null,
                ])->values()->all(),
        ];
    }

    /**
     * @return list<array{when: string, item: string, price: ?string, reason: string, by: ?string}>
     */
    private function priceHistory(Branch $branch, BranchPriceService $prices): array
    {
        return $prices->history($branch)->map(fn (BranchPrice $change): array => [
            'when' => $change->created_at->toIso8601String(),
            'item' => match (true) {
                $change->service_id !== null => (string) $change->service?->name,
                $change->service_variant_id !== null => trim($change->variant?->service?->name.' — '.$change->variant?->name, ' —'),
                $change->installation_offer_id !== null => 'Installation: '.$change->installationOffer?->name,
                default => 'Prepayment offer: '.$change->prepaymentOffer?->name,
            },
            'price' => $change->price !== null ? (string) $change->price : null,
            'reason' => $change->reason,
            'by' => $change->setBy?->name,
        ])->values()->all();
    }
}
