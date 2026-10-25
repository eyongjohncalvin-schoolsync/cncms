<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a technician's usual zones — a routing hint for complaint
 * assignment, never an access rule (App\Services\TechnicianService).
 */
#[Fillable(['user_id', 'zone_id', 'assigned_by'])]
class TechnicianZone extends Model
{
    use BelongsToBranch;

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    protected function branchParent(): ?string
    {
        return 'zone';
    }
}
