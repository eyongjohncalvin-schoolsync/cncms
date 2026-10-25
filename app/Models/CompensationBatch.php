<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Free months for every billable customer of a zone, or of a whole branch,
 * after an outage — one compensation request per customer
 * (App\Models\FreeMonthGrant, `compensation_batch_id`), all decided at once:
 * always approved by someone other than whoever asked (the owner excepted).
 * App\Services\CompensationService is the only writer.
 *
 * @property int $months
 * @property list<int>|null $service_ids
 */
#[Fillable(['branch_id', 'zone_id', 'service_ids', 'months', 'note', 'status', 'customers_count', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason'])]
#[RouteKey('uuid')]
class CompensationBatch extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public const string STATUS_PENDING_APPROVAL = 'pending_approval';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'service_ids' => 'array',
            'months' => 'integer',
            'customers_count' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(FreeMonthGrant::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** CompensationService sets the branch it was asked for. */
    protected function branchParent(): ?string
    {
        return null;
    }
}
