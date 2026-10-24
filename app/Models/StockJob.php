<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An installation or repair at a customer — the only way stock reaches a
 * customer. Usage above the norms for the job type is flagged.
 */
#[Fillable(['local_uuid', 'job_type', 'customer_id', 'complaint_id', 'service_id', 'technician_id', 'completed_at', 'latitude', 'longitude', 'notes', 'flags'])]
#[RouteKey('uuid')]
class StockJob extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'flags' => 'array',
        ];
    }

    public const array TYPES = ['installation', 'repair', 'relocation', 'recovery'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockJobLine::class, 'job_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
