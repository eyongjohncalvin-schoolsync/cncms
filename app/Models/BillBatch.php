<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One asynchronous bill-generation run (owner's 2026-08-30 ask — see the
 * 2026_08_30_120000_create_bill_batches_tables migration and
 * App\Services\BillBatchService). Its child bill_batch_files rows are the
 * downloadable PDF artifacts.
 *
 * @property string $status queued|processing|completed|partial|failed|cancelled
 */
#[Fillable([
    'period', 'status', 'density', 'template', 'filters', 'total_bills', 'total_zones',
    'generated_by', 'batch_id', 'error_message', 'started_at', 'completed_at', 'branch_id',
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

    /**
     * Chaperoned so each loaded file's `billBatch` is this instance —
     * BillBatchFile::downloadName() reads it, and the zip build calls that
     * once per zone file.
     */
    /**
     * Branches v2: a fenced user sees their branch's bill runs only — never
     * headquarters' company-wide run (its bulk PDF holds every branch's bills).
     */
    public function scopeVisible(Builder $query): Builder
    {
        $branchId = TenantContext::currentBranchId();

        return $branchId === null ? $query : $query->where('branch_id', $branchId);
    }

    /** Route binding: another branch's run is a 404 for a fenced user. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->visible();
    }

    public function files(): HasMany
    {
        return $this->hasMany(BillBatchFile::class)->chaperone();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'partial', 'failed', 'cancelled'], true);
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, ['completed', 'partial'], true);
    }
}
