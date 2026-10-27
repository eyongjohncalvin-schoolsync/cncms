<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BumpsCacheVersion;
use App\Models\Concerns\HasUuid;
use App\Support\CacheVersion;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'period', 'amount', 'branch_id'])]
#[RouteKey('uuid')]
class Budget extends Model
{
    use Auditable, BumpsCacheVersion, HasUuid;

    /** @return list<string> */
    public function cacheVersions(): array
    {
        return [CacheVersion::SPENDING];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
