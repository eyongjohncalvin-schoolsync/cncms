<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Registered by App\Traits\Auditable::bootAuditable() on every auditable
 * model. Fires on created/updated/deleted and writes one immutable row to
 * audit_logs per audit-strategy.md sections 2 and 4.1.
 *
 * `audit_logs` is a tenant-schema table (see
 * database/migrations/tenant/2026_08_19_090701_create_audit_logs_table.php),
 * and tenant_id is read from `tenant()->id`, which requires tenancy to be
 * initialized. Model events can fire outside of any tenant context (e.g. a
 * factory/test seeding data before tenancy()->initialize() runs, or a
 * central-connection write), so writeAudit() no-ops whenever tenancy isn't
 * initialized rather than blowing up the whole request/command on a null
 * tenant. The write itself is also wrapped in a try/catch — a logging
 * failure must never take down the mutation it's trying to record.
 */
class AuditableObserver
{
    public function created(Model $model): void
    {
        $this->writeAudit($model, 'create');
    }

    public function updated(Model $model): void
    {
        $this->writeAudit($model, 'update');
    }

    public function deleted(Model $model): void
    {
        $this->writeAudit($model, 'delete');
    }

    protected function writeAudit(Model $model, string $action): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        try {
            AuditLog::create([
                'tenant_id' => tenant()->id,
                'table_name' => $model->getTable(),
                'record_uuid' => $model->uuid,
                'record_id' => $model->getKey(),
                'action' => $action,
                'old_values' => $action === 'create' ? null : $this->filterFields($model->getOriginal()),
                'new_values' => $action === 'delete' ? null : $this->filterFields($model->getAttributes()),
                // user_id is null for system/scheduled actions (e.g. the
                // manuscript:calculate command running via CLI with no
                // authenticated user) — the column allows it.
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device_id' => request()->header('X-Device-ID'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Excludes fields that change on every update/sync but carry no
     * meaningful audit signal (audit-strategy.md section 4.3).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function filterFields(array $attributes): array
    {
        return Arr::except($attributes, [
            'created_at',
            'updated_at',
            'last_sync_at',
            'sync_status',
            'attempt_count',
        ]);
    }
}
