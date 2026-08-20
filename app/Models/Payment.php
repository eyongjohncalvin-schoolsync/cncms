<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id', 'amount', 'credit', 'frequency', 'expiration_date', 'months',
    'verification_status', 'processed_at', 'recorded_offline', 'recorded_by_device',
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credit' => 'decimal:2',
            'expiration_date' => 'date',
            'processed_at' => 'datetime',
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
