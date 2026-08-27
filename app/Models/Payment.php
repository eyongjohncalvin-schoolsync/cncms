<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopesRouteBindingToBranch;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id', 'amount', 'credit', 'frequency', 'expiration_date', 'months',
    'verification_status', 'processed_at', 'processed_period', 'recorded_offline', 'recorded_by_device', 'local_uuid',
    // The field agent's actual offline-collection timestamp — see the
    // add_collected_at_to_payments_table migration and
    // App\Services\SyncService::pushPayment()'s doc comment. Deliberately
    // separate from `created_at` (server-arrival time, untouched). Always
    // null for a web-recorded payment; only pushPayment() ever populates
    // it.
    'collected_at',
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    use Auditable, HasUuid, ScopesRouteBindingToBranch;

    /**
     * Payment has no direct zone — branch is reached through its Customer.
     */
    protected static function branchRouteBindingRelation(): ?string
    {
        return 'customer.zone';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit' => 'decimal:2',
            'expiration_date' => 'date',
            'processed_at' => 'datetime',
            'collected_at' => 'datetime',
            'recorded_offline' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function verification(): HasOne
    {
        return $this->hasOne(PaymentVerification::class);
    }
}
