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
 * A regular monthly cost (rent, salaries, electricity, signal fees) that is
 * confirmed once per month with one tap. Each confirmation is an ordinary
 * expense that goes through the normal approval rules; a template can only
 * produce one expense per month.
 */
#[Fillable(['category_id', 'supplier_id', 'service_id', 'name', 'amount', 'payment_method', 'day_of_month', 'is_active', 'created_by'])]
#[RouteKey('uuid')]
class RecurringExpense extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'day_of_month' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The service this cost belongs to; null = shared across services. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(Expenditure::class);
    }
}
