<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone the business pays (fuel station, hardware shop, landlord…). A
 * supplier added by one person stays `pending` until a different person
 * approves it, so nobody can invent a payee and pay it in one go.
 */
#[Fillable(['name', 'phone', 'momo_number', 'location', 'notes', 'status', 'created_by', 'approved_by', 'approved_at'])]
#[RouteKey('uuid')]
class Supplier extends Model
{
    use Auditable, HasUuid;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(Expenditure::class);
    }
}
