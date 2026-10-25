<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\BusinessTimezone;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A prepayment offer: months paid ahead at a promotional price, e.g.
 * "4 months for 10,000" on a 3,000 bill. Headquarters keeps the list
 * (Settings → Prepayment offers, `payments.offers`); a branch may set its own
 * price for one (App\Support\BranchPrices::prepaymentOfferPrice()).
 *
 * A payment made under an offer records it (payments.prepayment_offer_id) and
 * buys exactly its months for exactly its price
 * (App\Services\ManuscriptCalculator), so the amount recorded is always the
 * money really paid. Only sold to a customer with no unpaid debt.
 *
 * @property string $price
 */
#[Fillable(['name', 'months', 'price', 'valid_from', 'valid_to', 'active'])]
#[RouteKey('uuid')]
class PrepaymentOffer extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'price' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'active' => 'boolean',
        ];
    }

    /** Same rule as scopeAvailable(), for an offer already loaded. */
    public function isOnSale(?string $onDate = null): bool
    {
        $onDate ??= BusinessTimezone::today();

        return $this->active
            && ($this->valid_from === null || $this->valid_from->toDateString() <= $onDate)
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $onDate);
    }

    /**
     * Offers a collector may sell today: active and inside their validity
     * window on the Cameroon calendar.
     *
     * @param  Builder<PrepaymentOffer>  $query
     */
    public function scopeAvailable(Builder $query, ?string $onDate = null): void
    {
        $onDate ??= BusinessTimezone::today();

        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $onDate))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $onDate));
    }
}
