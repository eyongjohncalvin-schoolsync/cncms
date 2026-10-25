<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a branch's price for a service, a service option or an
 * installation offer (branches v2 D4) — append-only, so the rows are the
 * price history. The latest row per target is the current price; a null
 * price hands the target back to headquarters' catalogue price. Read through
 * App\Support\BranchPrices; written by App\Services\BranchPriceService.
 */
#[Fillable(['branch_id', 'service_id', 'service_variant_id', 'installation_offer_id', 'prepayment_offer_id', 'price', 'reason', 'set_by'])]
class BranchPrice extends Model
{
    use Auditable, HasUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }

    public function installationOffer(): BelongsTo
    {
        return $this->belongsTo(InstallationOffer::class);
    }

    public function prepaymentOffer(): BelongsTo
    {
        return $this->belongsTo(PrepaymentOffer::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
