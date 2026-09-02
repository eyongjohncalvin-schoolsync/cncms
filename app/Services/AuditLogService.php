<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogs,
    ) {}

    /**
     * Audit logs are append-only and grow constantly, so there's no
     * predictable invalidation key to forget on write (unlike
     * ExpenditureService's dashboard cache) — the short TTL alone is the
     * entire caching strategy here.
     *
     * @param  array<string, mixed>  $filters  Supported keys: 'table_name', 'action',
     *                                         'user_uuid', 'search', 'record_uuid', 'from', 'to'.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage() ?: 1;

        $cacheKey = 'audit:logs:list:'.md5(json_encode([$filters, $perPage, $page]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): LengthAwarePaginator => $this->auditLogs->paginate($filters, $perPage),
        );
    }

    /**
     * Best-effort human-readable one-liner for the audit viewer's Summary
     * column (audit-strategy.md section 9). Covers the common table_name +
     * action combinations from the reference doc's examples and falls back
     * to a generic description for everything else — this deliberately
     * doesn't try to describe every possible field change.
     *
     * @param  Collection<int|string, string>|null  $categoryNames  Optional
     *                                                              category_id => name lookup map (see categoryNamesFor()), used
     *                                                              to summarize 'expenditures' rows without a per-row query.
     *                                                              When omitted, falls back to the previous per-row-query
     *                                                              behavior so other call sites keep working unchanged.
     */
    public function summarize(AuditLog $log, ?Collection $categoryNames = null): string
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        return match ($log->table_name) {
            'customers' => $this->summarizeCustomer($log, $old, $new),
            'payments' => $this->summarizePayment($log, $old, $new),
            'payment_verifications' => $this->summarizePaymentVerification($log, $old, $new),
            'expenditures' => $this->summarizeExpenditure($log, $old, $new, $categoryNames),
            'manuscripts' => $this->summarizeManuscript($log, $old, $new),
            default => $this->genericSummary($log),
        };
    }

    /**
     * Batch-loads the expense_category id => name lookup for every
     * 'expenditures'-table row in the given collection of audit logs, so a
     * whole page can be summarized with one query instead of one query per
     * row (see summarize()/summarizeExpenditure()/categoryName()).
     *
     * @param  iterable<AuditLog>  $logs
     * @return Collection<int|string, string>
     */
    public function categoryNamesFor(iterable $logs): Collection
    {
        $ids = collect($logs)
            ->filter(fn (AuditLog $log): bool => $log->table_name === 'expenditures')
            ->flatMap(fn (AuditLog $log): array => [
                data_get($log->old_values, 'category_id'),
                data_get($log->new_values, 'category_id'),
            ])
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return ExpenseCategory::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function summarizeCustomer(AuditLog $log, array $old, array $new): string
    {
        if ($log->action === 'create') {
            return "Created customer: {$this->label($new, 'name')}";
        }

        if ($log->action === 'delete') {
            return "Deleted customer: {$this->label($old, 'name')}";
        }

        // Archiving (customer-deletion deliberation, 2026-08-29) — a soft
        // delete, recorded as an update that sets/clears archived_by (see
        // App\Services\CustomerService::archive()/restore() and
        // AuditableObserver::deleted()'s soft-delete skip).
        $wasArchived = ! empty($old['archived_by']);
        $isArchived = ! empty($new['archived_by']);

        if (! $wasArchived && $isArchived) {
            $reason = trim((string) ($new['archived_reason'] ?? ''));

            return "Archived customer: {$this->label($new, 'name')}".($reason !== '' ? " — {$reason}" : '');
        }

        if ($wasArchived && ! $isArchived) {
            return "Restored customer: {$this->label($new, 'name')}";
        }

        if (($old['status'] ?? null) !== ($new['status'] ?? null)) {
            return "Changed customer status: {$this->label($old, 'status')} -> {$this->label($new, 'status')}";
        }

        if (($old['bill'] ?? null) !== ($new['bill'] ?? null)) {
            return "Changed customer bill: {$this->money($old['bill'] ?? null)} -> {$this->money($new['bill'] ?? null)}";
        }

        return 'Updated customer record';
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function summarizePayment(AuditLog $log, array $old, array $new): string
    {
        return match ($log->action) {
            'create' => "Recorded payment: {$this->money($new['amount'] ?? null)}",
            'delete' => "Deleted payment: {$this->money($old['amount'] ?? null)}",
            default => "Updated payment: {$this->money($new['amount'] ?? $old['amount'] ?? null)}",
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function summarizePaymentVerification(AuditLog $log, array $old, array $new): string
    {
        $status = $new['status'] ?? $old['status'] ?? 'pending';

        if (in_array($status, ['approved', 'rejected'], true)) {
            return "Verified payment: {$status} by ".($log->user?->name ?? 'system');
        }

        return $this->genericSummary($log);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function summarizeExpenditure(AuditLog $log, array $old, array $new, ?Collection $categoryNames = null): string
    {
        $values = $log->action === 'delete' ? $old : $new;
        $amount = $this->money($values['amount'] ?? null);
        $categoryId = $values['category_id'] ?? null;
        $category = $categoryNames !== null
            ? ($categoryId ? $categoryNames->get($categoryId) : null)
            : $this->categoryName($categoryId);
        $suffix = $category ? " ({$category})" : '';

        return match ($log->action) {
            'create' => "Recorded expenditure: {$amount}{$suffix}",
            'delete' => "Deleted expenditure: {$amount}{$suffix}",
            default => "Updated expenditure: {$amount}{$suffix}",
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function summarizeManuscript(AuditLog $log, array $old, array $new): string
    {
        if ($log->action === 'create') {
            return "Calculated manuscript: total bill {$this->money($new['total_bill'] ?? null)}";
        }

        if (($old['total_bill'] ?? null) !== ($new['total_bill'] ?? null)) {
            return "Recalculated manuscript: total bill {$this->money($old['total_bill'] ?? null)} -> {$this->money($new['total_bill'] ?? null)}";
        }

        return $this->genericSummary($log);
    }

    private function genericSummary(AuditLog $log): string
    {
        $table = str_replace('_', ' ', $log->table_name);

        return Str::ucfirst($log->action)." {$table} record";
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function label(array $values, string $key): string
    {
        return (string) ($values[$key] ?? '—');
    }

    private function money(mixed $amount): string
    {
        if ($amount === null) {
            return '0 FCFA';
        }

        return number_format((float) $amount, 0, '.', ',').' FCFA';
    }

    private function categoryName(mixed $categoryId): ?string
    {
        if (! $categoryId) {
            return null;
        }

        return ExpenseCategory::query()->whereKey($categoryId)->value('name');
    }
}
