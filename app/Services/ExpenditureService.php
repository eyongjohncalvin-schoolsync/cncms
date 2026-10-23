<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expenditure;
use App\Models\User;
use App\Repositories\Contracts\ExpenditureRepositoryInterface;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expenditures after docs/plans/expenditures-and-spending-controls.md:
 * read them, re-categorise / fix their wording (E7), void a legacy row.
 * Creating them is App\Services\VoucherService's job alone (E1) — a bon
 * paid or settled, or a correction of one — and nothing is ever deleted.
 * Amounts never change here; a wrong amount is corrected on the bon.
 */
class ExpenditureService
{
    public function __construct(
        private readonly ExpenditureRepositoryInterface $expenditures,
        private readonly ExpenseCategoryRepositoryInterface $categories,
        private readonly ExpenditureMonthService $months,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'month' (Y-m), 'category_uuid', 'from', 'to', 'user_uuid', 'source', 'status'.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->expenditures->paginate($this->scopedFilters($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters  Same keys as list().
     * @return Collection<int, Expenditure>
     */
    public function all(array $filters): Collection
    {
        return $this->expenditures->all($this->scopedFilters($filters));
    }

    public function findOrFail(string $uuid): Expenditure
    {
        $expenditure = $this->expenditures->findByUuid($uuid, ['category', 'user']);

        if (! $expenditure) {
            throw new ModelNotFoundException("Expenditure [{$uuid}] not found.");
        }

        return $expenditure;
    }

    /**
     * Re-categorise and/or fix the description and notes, with a reason —
     * only while the month is open, never by the person who requested or
     * recorded it (ExpenditurePolicy::update). An empty description or note
     * clears it.
     *
     * @param  array{category_uuid?: ?string, description?: ?string, notes?: ?string}  $changes
     */
    public function edit(Expenditure $expenditure, array $changes, string $reason): Expenditure
    {
        $this->assertEditable($expenditure);

        $attributes = ['edit_reason' => $reason];

        if (array_key_exists('category_uuid', $changes) && $changes['category_uuid'] !== null) {
            $attributes['category_id'] = $this->resolveCategoryId($changes['category_uuid']);
        }

        foreach (['description', 'notes'] as $field) {
            if (array_key_exists($field, $changes)) {
                $value = $changes[$field] === null ? null : trim((string) $changes[$field]);
                $attributes[$field] = $value === '' ? null : $value;
            }
        }

        $expenditure = $this->expenditures->update($expenditure, $attributes);
        $this->forgetCaches($expenditure->spent_at);

        return $expenditure->load(['category', 'user']);
    }

    /**
     * Voids a legacy (directly recorded) expenditure: the row stays, marked
     * void, out of every total. A bon's expenditure is corrected on the bon
     * instead (VoucherService::correct) — the cash really left.
     */
    public function void(Expenditure $expenditure, User $actor, string $reason): Expenditure
    {
        if ($expenditure->source !== Expenditure::SOURCE_LEGACY) {
            throw ValidationException::withMessages(['reason' => 'A bon\'s expenditure is corrected on the bon, not voided.']);
        }

        $this->assertEditable($expenditure);

        return DB::transaction(function () use ($expenditure, $actor, $reason): Expenditure {
            $expenditure->update([
                'status' => Expenditure::STATUS_VOID,
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ]);

            $this->forgetCaches($expenditure->spent_at);

            return $expenditure;
        });
    }

    /**
     * Forgets the dashboard and report caches for the month $spentAt falls
     * in. ResourcesDashboardService's key is branch-suffixed, so only this
     * actor's own key and the unrestricted ('all') key are forgotten; other
     * branch-fenced copies age out with the 5-minute TTL.
     */
    public function forgetCaches(mixed $spentAt): void
    {
        if ($spentAt === null) {
            return;
        }

        $date = $spentAt instanceof Carbon ? $spentAt : Carbon::parse($spentAt);
        $period = $date->format('Y-m');

        Cache::forget('resources:dashboard:'.$period.':'.(TenantContext::currentBranchId() ?? 'all'));
        Cache::forget('resources:dashboard:'.$period.':all');

        ReportService::forgetCache($date);
    }

    private function assertEditable(Expenditure $expenditure): void
    {
        if ($expenditure->isVoid()) {
            throw ValidationException::withMessages(['reason' => 'This expenditure is void.']);
        }

        $this->months->assertOpen($expenditure->period());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function scopedFilters(array $filters): array
    {
        if (BusinessTimezone::isValidPeriod($filters['month'] ?? null)) {
            [$filters['from'], $filters['to']] = BusinessTimezone::periodDates($filters['month']);
        }

        if (! empty($filters['category_uuid'])) {
            $filters['category_id'] = $this->resolveCategoryId($filters['category_uuid']);
        }

        if (! empty($filters['user_uuid'])) {
            $filters['user_id'] = $this->resolveUserId($filters['user_uuid']);
        }

        if (isset($filters['source']) && ! in_array($filters['source'], Expenditure::SOURCES, true)) {
            unset($filters['source']);
        }

        if (isset($filters['status']) && ! in_array($filters['status'], [Expenditure::STATUS_ACTIVE, Expenditure::STATUS_VOID], true)) {
            unset($filters['status']);
        }

        return $filters;
    }

    private function resolveCategoryId(string $categoryUuid): int
    {
        $category = $this->categories->findByUuid($categoryUuid);

        if (! $category) {
            throw ValidationException::withMessages(['category_uuid' => ['The selected category does not exist.']]);
        }

        return $category->id;
    }

    private function resolveUserId(string $userUuid): int
    {
        $user = User::query()->where('uuid', $userUuid)->first();

        if (! $user) {
            throw ValidationException::withMessages(['user_uuid' => ['The selected user does not exist.']]);
        }

        return $user->id;
    }
}
