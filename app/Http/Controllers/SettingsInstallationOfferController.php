<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveInstallationOfferRequest;
use App\Http\Requests\UpdateFreeMonthsCapRequest;
use App\Http\Requests\UpdateOneOffFeesRequest;
use App\Models\Company;
use App\Models\CustomerCharge;
use App\Models\InstallationOffer;
use App\Models\Service;
use App\Services\CustomerRelocationService;
use App\Services\FreeMonthGrantService;
use App\Services\InstallationOfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Installation offers (docs/plans/installation-and-promotions.md,
 * phase 2, item 1): the company's standard installation deals — fee and
 * free months per service — and the cap on free months per grant.
 * `installations.offers` (InstallationOfferPolicy).
 */
class SettingsInstallationOfferController extends Controller
{
    public function __construct(
        private readonly InstallationOfferService $offers,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InstallationOffer::class);

        $usage = DB::table('customer_charges')
            ->whereNotNull('installation_offer_id')
            ->groupBy('installation_offer_id')
            ->selectRaw('installation_offer_id, count(*) as used')
            ->pluck('used', 'installation_offer_id');

        return Inertia::render('Settings/InstallationOffers', [
            'offers' => InstallationOffer::query()
                ->with('serviceMonths.service')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (InstallationOffer $offer): array => [
                    ...$this->offers->shape($offer),
                    'valid_from' => $offer->valid_from?->toDateString(),
                    'valid_to' => $offer->valid_to?->toDateString(),
                    'active' => (bool) $offer->active,
                    'available' => $offer->isAvailable(),
                    'used' => (int) ($usage[$offer->id] ?? 0),
                ])
                ->values()
                ->all(),
            'services' => Service::query()->where('active', true)->ordered()->get()
                ->map(fn (Service $service): array => ['uuid' => $service->uuid, 'name' => $service->name, 'price' => (string) $service->price])
                ->values()
                ->all(),
            'max_free_months' => FreeMonthGrantService::maxMonths(),
            'compensation_months_without_approval' => FreeMonthGrantService::monthsWithoutApproval(),
            'one_off_fees' => [
                'relocation_fee' => CustomerRelocationService::defaultFee(CustomerCharge::KIND_RELOCATION),
                'reinstallation_fee' => CustomerRelocationService::defaultFee(CustomerCharge::KIND_REINSTALLATION),
            ],
        ]);
    }

    public function store(SaveInstallationOfferRequest $request): RedirectResponse
    {
        $this->offers->save(null, $request->validated());

        return redirect()->route('settings.installation-offers.index')->with('success', 'Installation offer added.');
    }

    public function update(SaveInstallationOfferRequest $request, InstallationOffer $installationOffer): RedirectResponse
    {
        $this->offers->save($installationOffer, $request->validated());

        return redirect()->route('settings.installation-offers.index')->with('success', 'Installation offer saved. Customers who already took it keep what they were given.');
    }

    public function destroy(InstallationOffer $installationOffer): RedirectResponse
    {
        $this->authorize('delete', $installationOffer);

        $this->offers->delete($installationOffer);

        return redirect()->route('settings.installation-offers.index')->with('success', 'Installation offer deleted.');
    }

    public function updateCap(UpdateFreeMonthsCapRequest $request): RedirectResponse
    {
        Company::query()->firstOrFail()->update(array_map('intval', $request->safe()->only(['max_free_months', 'compensation_months_without_approval'])));
        Company::forgetCache();

        return redirect()->route('settings.installation-offers.index')->with('success', 'Free-month limits saved.');
    }

    /** Default relocation and re-installation fees (null = no default). */
    public function updateOneOffFees(UpdateOneOffFeesRequest $request): RedirectResponse
    {
        $money = fn (mixed $value): ?string => $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');

        Company::query()->firstOrFail()->update([
            'relocation_fee' => $money($request->validated('relocation_fee')),
            'reinstallation_fee' => $money($request->validated('reinstallation_fee')),
        ]);
        Company::forgetCache();

        return redirect()->route('settings.installation-offers.index')->with('success', 'Relocation and re-installation fees saved.');
    }
}
