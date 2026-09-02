<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced sub-option under a App\Models\Service, one level deep (services.md
 * section 4) — the concrete case named was a specific TV channel broadcast
 * under the `tv` service, its own price on top of the base subscription.
 * No service is hardcoded to have variants: any service can grow them
 * through the same Settings -> Services "Options" sub-list.
 *
 * `price` is independent of the parent service's `price` — subscribing to a
 * variant ADDS its own charge, it never replaces the base. A customer's
 * `customer_service` row for a variant always requires a sibling base row
 * for the same service (enforced in App\Services\CustomerSubscriptionService,
 * not at the DB level — see that class).
 */
#[Fillable(['service_id', 'name', 'price', 'active', 'sort_order'])]
#[RouteKey('uuid')]
class ServiceVariant extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @param  Builder<ServiceVariant>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param  Builder<ServiceVariant>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
