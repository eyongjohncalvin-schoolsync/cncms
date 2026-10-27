<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BumpsCacheVersion;
use App\Models\Concerns\HasUuid;
use App\Support\CacheVersion;
use App\Traits\Auditable;
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
    use Auditable, BelongsToBranch, BumpsCacheVersion, HasUuid;

    /** @return list<string> */
    public function cacheVersions(): array
    {
        return [CacheVersion::PAYMENTS];
    }

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

    /** Its branch comes from its payment (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'payment';
    }

    /** Customer history: stays whole for its customer; fenced on lists and links instead (BelongsToBranch). */
    protected static function fencedByBranchScope(): bool
    {
        return false;
    }
}
