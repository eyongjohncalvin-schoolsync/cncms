<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id', 'receipt_photo_path', 'momo_ref', 'momo_status',
    'verified_by', 'verified_at', 'status', 'notes',
])]
#[RouteKey('uuid')]
class PaymentVerification extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Cross-schema relation — User is pinned to the central `pgsql` connection
    // via #[Connection('pgsql')], so this resolves correctly regardless of
    // which tenant schema is currently active.
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
