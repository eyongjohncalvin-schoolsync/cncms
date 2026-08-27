<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin AuditLog
 *
 * audit_logs has no `uuid` column (see the migration comment: it's never
 * referenced externally, unlike every other tenant table) — this is the one
 * deliberate exception to the UUID-everywhere rule, so `id` is exposed here
 * instead.
 */
class AuditLogResource extends JsonResource
{
    /**
     * category_id => name lookup map for this item's page, primed by
     * collection() below so summarize() can batch-load expense category
     * names once per page instead of once per 'expenditures' row.
     */
    private ?Collection $categoryNames = null;

    /**
     * Overridden so the whole page's expense-category names can be
     * batch-loaded once (via AuditLogService::categoryNamesFor()) and
     * handed to every item's summarize() call, instead of each item's
     * toArray() triggering its own per-row category lookup.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource)
    {
        $logs = $resource instanceof LengthAwarePaginator ? $resource->getCollection() : $resource;

        $categoryNames = app(AuditLogService::class)->categoryNamesFor($logs);

        $collection = parent::collection($resource);

        foreach ($collection->collection as $item) {
            if ($item instanceof self) {
                $item->categoryNames = $categoryNames;
            }
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_name' => $this->table_name,
            'record_uuid' => $this->record_uuid,
            'action' => $this->action,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null),
            'ip_address' => $this->ip_address,
            'device_id' => $this->device_id,
            'created_at' => $this->created_at,
            'summary' => app(AuditLogService::class)->summarize($this->resource, $this->categoryNames),
        ];
    }
}
