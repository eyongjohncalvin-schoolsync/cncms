<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One asynchronous bill-generation run (owner's 2026-08-30 ask — see the
 * 2026_08_30_120000_create_bill_batches_tables migration and
 * App\Services\BillBatchService). Its child bill_batch_files rows are the
 * downloadable PDF artifacts.
 *
 * @property string $status queued|processing|completed|partial|failed
 */
#[Fillable([
    'period', 'status', 'density', 'template', 'filters', 'total_bills', 'total_zones',
    'generated_by', 'batch_id', 'error_message', 'started_at', 'completed_at',
])]
#[RouteKey('uuid')]
class BillBatch extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'density' => 'integer',
            'total_bills' => 'integer',
            'total_zones' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(BillBatchFile::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'partial', 'failed'], true);
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, ['completed', 'partial'], true);
    }
}
