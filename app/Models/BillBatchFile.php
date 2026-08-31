<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One downloadable PDF (or ZIP) artifact produced by a BillBatch run.
 *
 * kind:
 *   - 'zone'  — one zone's bill slips; zone_id / zone_name set.
 *   - 'bulk'  — the single all-zones PDF, customers ordered by zone then
 *               name; zone_id NULL.
 *   - 'zip'   — a convenience ZIP of every 'zone' file; zone_id NULL.
 *
 * @property string $kind
 */
#[Fillable([
    'bill_batch_id', 'zone_id', 'zone_name', 'kind', 'disk', 'path',
    'bill_count', 'page_count', 'size_bytes',
])]
#[RouteKey('uuid')]
class BillBatchFile extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'bill_count' => 'integer',
            'page_count' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function billBatch(): BelongsTo
    {
        return $this->belongsTo(BillBatch::class);
    }

    public function downloadName(): string
    {
        return match ($this->kind) {
            'bulk' => "bills-{$this->billBatch->period}-all.pdf",
            'zip' => "bills-{$this->billBatch->period}-by-zone.zip",
            default => 'bills-'.$this->billBatch->period.'-'.\Illuminate\Support\Str::slug($this->zone_name ?: 'unzoned').'.pdf',
        };
    }
}
