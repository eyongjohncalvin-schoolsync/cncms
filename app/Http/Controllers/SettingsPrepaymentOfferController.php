<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SavePrepaymentOfferRequest;
use App\Models\Payment;
use App\Models\PrepaymentOffer;
use App\Support\BusinessTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Prepayment offers: months paid ahead at a promotional price
 * ("4 months for 10,000"). Headquarters keeps the list; a branch may set its
 * own price for one on its branch settings. Changing an offer never changes
 * payments already made under it (they keep their months and amount).
 */
class SettingsPrepaymentOfferController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', PrepaymentOffer::class);

        $monthStart = Carbon::now(BusinessTimezone::WAT)->startOfMonth()->setTimezone('UTC');

        // Sold this month and in all, one grouped query. The discount is
        // what the months would have cost at each customer's current bill.
        $sales = Payment::query()
            ->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->whereNotNull('payments.prepayment_offer_id')
            ->where('payments.verification_status', '!=', 'rejected')
            ->groupBy('payments.prepayment_offer_id')
            ->selectRaw('payments.prepayment_offer_id,
                count(*) as sold,
                count(*) filter (where payments.created_at >= ?) as sold_this_month,
                coalesce(sum(greatest(payments.months * customers.bill - payments.amount, 0)) filter (where payments.created_at >= ?), 0) as discount_this_month', [$monthStart, $monthStart])
            ->get()
            ->keyBy('prepayment_offer_id');

        return Inertia::render('Settings/PrepaymentOffers', [
            'offers' => PrepaymentOffer::query()->orderByDesc('active')->orderBy('months')->get()
                ->map(fn (PrepaymentOffer $offer): array => [
                    'uuid' => $offer->uuid,
                    'name' => $offer->name,
                    'months' => $offer->months,
                    'price' => (string) $offer->price,
                    'valid_from' => $offer->valid_from?->toDateString(),
                    'valid_to' => $offer->valid_to?->toDateString(),
                    'active' => (bool) $offer->active,
                    'on_sale' => $offer->isOnSale(),
                    'sold' => (int) ($sales[$offer->id]->sold ?? 0),
                    'sold_this_month' => (int) ($sales[$offer->id]->sold_this_month ?? 0),
                    'discount_this_month' => number_format((float) ($sales[$offer->id]->discount_this_month ?? 0), 2, '.', ''),
                ])->values()->all(),
            'can_manage' => request()->user()->can('create', PrepaymentOffer::class),
        ]);
    }

    public function store(SavePrepaymentOfferRequest $request): RedirectResponse
    {
        PrepaymentOffer::query()->create($request->validated());

        return redirect()->route('settings.prepayment-offers.index')->with('success', 'Prepayment offer added.');
    }

    public function update(SavePrepaymentOfferRequest $request, PrepaymentOffer $prepaymentOffer): RedirectResponse
    {
        $prepaymentOffer->update($request->validated());

        return redirect()->route('settings.prepayment-offers.index')->with('success', 'Prepayment offer saved. Payments already made under it keep their months.');
    }

    /** An offer never sold is deleted; one already sold is only switched off, so its payments keep their record. */
    public function destroy(PrepaymentOffer $prepaymentOffer): RedirectResponse
    {
        $this->authorize('delete', $prepaymentOffer);

        if (Payment::query()->where('prepayment_offer_id', $prepaymentOffer->id)->exists()) {
            $prepaymentOffer->update(['active' => false]);

            return redirect()->route('settings.prepayment-offers.index')->with('success', 'That offer was already sold, so it was switched off instead of deleted.');
        }

        $prepaymentOffer->delete();

        return redirect()->route('settings.prepayment-offers.index')->with('success', 'Prepayment offer deleted.');
    }
}
