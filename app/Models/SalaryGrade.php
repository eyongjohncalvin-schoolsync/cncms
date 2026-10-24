<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A step of headquarters' salary scale (branches v2 §4.5, D8): a branch sets a
 * worker's salary inside [min_amount, max_amount]; outside it, headquarters
 * approves.
 */
#[Fillable(['name', 'min_amount', 'max_amount', 'is_active'])]
#[RouteKey('uuid')]
class SalaryGrade extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function covers(string $amount): bool
    {
        return bccomp($amount, (string) $this->min_amount, 2) >= 0 && bccomp($amount, (string) $this->max_amount, 2) <= 0;
    }
}
