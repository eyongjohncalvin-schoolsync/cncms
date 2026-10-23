<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service's free months on an installation offer (D7: free months are
 * per service). Written only by the Settings → Installation offers screen.
 */
#[Fillable(['installation_offer_id', 'service_id', 'free_months'])]
class InstallationOfferService extends Model
{
    protected function casts(): array
    {
        return [
            'free_months' => 'integer',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(InstallationOffer::class, 'installation_offer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
