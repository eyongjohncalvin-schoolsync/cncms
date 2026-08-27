<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExpenditureData;
use App\Models\Expenditure;
use App\Models\User;
use App\Repositories\Contracts\ExpenditureRepositoryInterface;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ExpenditureService
{
    public function __construct(
        private readonly ExpenditureRepositoryInterface $expenditures,
        private readonly ExpenseCategoryRepositoryInterface $categories,
    ) {}

    /**
     * Expenditures are written moderately often (agents/managers recording
     * throughout the day), so this uses a short TTL rather than precise
     * per-filter/page invalidation — same tradeoff as PaymentService::list().
     *
     * @param  array<string, mixed>  $filters  Supported keys: 'category_uuid', 'from', 'to', 'user_uuid'.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $scopedFilters = $this->scopedFilters($filters);
        $page = Paginator::resolveCurrentPage() ?: 1;

        $cacheKey = 'resources:expenditures:list:'.md5(json_encode([$scopedFilters, $perPage, $page]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): LengthAwarePaginator => $this->expenditures->paginate($scopedFilters, $perPage),
        );
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
     * Fixed interface contract — the Sync API depends on this exact
     * signature (see ExpenditureData's class doc). $userId is passed in
     * explicitly rather than read from auth() internally, because the Sync
     * API calls this on behalf of an agent whose ID it already has for
     * offline-recorded expenditures. Expenditures have no verification
     * workflow (no `verification_status` column) — they are trusted once
     * recorded; role gating via ExpenditurePolicy is the only control.
     */
    public function create(ExpenditureData $data, int $userId): Expenditure
    {
        $categoryId = $this->resolveCategoryId($data->categoryUuid);

        $expenditure = $this->expenditures->create($categoryId, $userId, $data->toAttributes());

        $this->forgetDashboardCache($expenditure->spent_at);

        return $expenditure->load(['category', 'user']);
    }

    public function update(Expenditure $expenditure, ExpenditureData $data): Expenditure
    {
        $attributes = $data->toAttributes();

        if ($data->categoryUuid !== null) {
            $attributes['category_id'] = $this->resolveCategoryId($data->categoryUuid);
        }

        // spent_at may move the expenditure into a different period, so both
        // the period it's leaving and the one it's landing in need their
        // dashboard cache invalidated (a no-op forget on the same key twice
        // when spent_at is unchanged or stays within the period).
        $previousSpentAt = $expenditure->spent_at;

        $expenditure = $this->expenditures->update($expenditure, $attributes);

        $this->forgetDashboardCache($previousSpentAt);
        $this->forgetDashboardCache($expenditure->spent_at);

        return $expenditure->load(['category', 'user']);
    }

    public function delete(Expenditure $expenditure): void
    {
        $spentAt = $expenditure->spent_at;

        $this->expenditures->delete($expenditure);

        $this->forgetDashboardCache($spentAt);
    }

    /**
     * Derives the expenditure's YYYY-MM period from its spent_at date and
     * forgets ResourcesDashboardService's cache entry for that period, so the
     * P&L dashboard reflects a newly-recorded/edited/deleted expense promptly
     * rather than waiting out the full 5-minute TTL. Its cache key is now
     * branch-suffixed (income is branch-scoped even though expenses are not
     * — see ResourcesDashboardService::expensesFor()'s doc comment), so —
     * same tradeoff as CustomerService::forgetShowCache() — only this
     * actor's own key and the unrestricted ('all') key are explicitly
     * forgotten; any other branch-fenced caller's copy is bounded by the 5
     * minute TTL instead.
     */
    private function forgetDashboardCache(mixed $spentAt): void
    {
        if ($spentAt === null) {
            return;
        }

        $period = ($spentAt instanceof Carbon ? $spentAt : Carbon::parse($spentAt))->format('Y-m');

        Cache::forget('resources:dashboard:'.$period.':'.(TenantContext::currentBranchId() ?? 'all'));
        Cache::forget('resources:dashboard:'.$period.':all');

        // Extends the same forget-on-write to /reports' Daily/Weekly/Monthly
        // caches — expenditures feed the Daily tier's "expenditures recorded
        // today" figure and (via ResourcesDashboardService::dashboard())
        // the Monthly tier's P&L block. Expenditures carry no branch
        // attribution (see ResourcesDashboardService::expensesFor()'s doc
        // comment), so only the unrestricted ('all') report keys are ever
        // forgotten here — see ReportService::forgetCache()'s doc comment.
        ReportService::forgetCache($spentAt instanceof Carbon ? $spentAt : Carbon::parse($spentAt));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function scopedFilters(array $filters): array
    {
        if (! empty($filters['category_uuid'])) {
            $filters['category_id'] = $this->resolveCategoryId($filters['category_uuid']);
        }

        if (! empty($filters['user_uuid'])) {
            $filters['user_id'] = $this->resolveUserId($filters['user_uuid']);
        }

        return $filters;
    }

    private function resolveCategoryId(?string $categoryUuid): int
    {
        if (! $categoryUuid) {
            throw ValidationException::withMessages(['category_uuid' => ['The category_uuid field is required.']]);
        }

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
