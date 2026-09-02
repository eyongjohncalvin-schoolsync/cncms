<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The `customer_service` pivot — one customer subscribing to one service at
 * the price actually charged them (services.md section 3). `created_at`
 * doubles as "subscribed-at".
 *
 * Extends Pivot (so `Customer::services()->using(...)` hydrates
 * `$service->pivot` as this class) but is ALSO a first-class Auditable model
 * with its own `id`/`uuid`: App\Services\CustomerSubscriptionService — the
 * single writer of this table — operates rows directly through this model
 * (create/update/delete) rather than through belongsToMany attach/detach,
 * precisely so every mutation fires the model events the AuditableObserver
 * listens on. A `belongsToMany` sync never dispatches those events.
 */
class CustomerSubscription extends Pivot
{
    use Auditable, HasUuid;

    protected $table = 'customer_service';

    public $incrementing = true;

    public $timestamps = true;

    protected $fillable = ['customer_id', 'service_id', 'service_variant_id', 'price'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Null for a base subscription row (services.md section 4). */
    public function serviceVariant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class);
    }
}
