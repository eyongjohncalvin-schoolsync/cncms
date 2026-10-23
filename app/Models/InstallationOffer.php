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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standard installation deal (docs/plans/installation-and-promotions.md,
 * phase 2): the installation fee and the free months it comes with, PER
 * SERVICE (D7) — e.g. "Launch offer: 10,000 FCFA, TV 2 months, Internet 1
 * month". Set in Settings → Installation offers (`installations.offers`).
 *
 * A manager picks one when adding a customer; the fee and the months are
 * COPIED onto the customer (the installation charge's standard_amount and
 * one FreeMonthGrant per service), so editing or retiring an offer never
 * changes a past customer. Offers apply to new installations only.
 *
 * @property string $fee
 */
#[Fillable(['name', 'description', 'fee', 'valid_from', 'valid_to', 'active', 'is_default'])]
#[RouteKey('uuid')]
class InstallationOffer extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** The free months this offer gives, one row per service. */
    public function serviceMonths(): HasMany
    {
        return $this->hasMany(InstallationOfferService::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'installation_offer_services')->withPivot('free_months')->withTimestamps();
    }

    /**
     * Offers a manager may pick today: active and inside their validity
     * window on the Cameroon calendar.
     *
     * @param  Builder<InstallationOffer>  $query
     */
    public function scopeAvailable(Builder $query, ?string $onDate = null): void
    {
        $onDate ??= BusinessTimezone::today();

        $query->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $onDate))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $onDate));
    }

    public function isAvailable(?string $onDate = null): bool
    {
        $onDate ??= BusinessTimezone::today();

        return $this->active
            && ($this->valid_from === null || $this->valid_from->toDateString() <= $onDate)
            && ($this->valid_to === null || $this->valid_to->toDateString() >= $onDate);
    }

    /**
     * Free months per service id.
     *
     * @return array<int, int>
     */
    public function monthsByService(): array
    {
        return $this->serviceMonths
            ->mapWithKeys(fn (InstallationOfferService $row): array => [(int) $row->service_id => (int) $row->free_months])
            ->all();
    }
}
