<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place stock can be: the store, a technician's bag, or one of the
 * "other ends" of a movement (a customer, the supplier, written off).
 */
#[Fillable(['type', 'name', 'user_id', 'customer_id', 'branch_id', 'is_active'])]
#[RouteKey('uuid')]
class StockLocation extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public const string STORE = 'store';

    public const string TECHNICIAN = 'technician';

    public const string CUSTOMER = 'customer';

    public const string SUPPLIER = 'supplier';

    public const string ADJUSTMENT = 'adjustment';

    /** Places that physically hold stock someone is answerable for. */
    public const array HOLDING = [self::STORE, self::TECHNICIAN];

    public function isHolding(): bool
    {
        return in_array($this->type, self::HOLDING, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
