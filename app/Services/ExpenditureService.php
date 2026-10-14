<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExpenditureData;
use App\Models\Expenditure;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\Contracts\ExpenditureRepositoryInterface;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recording, evidencing, approving and voiding expenses. The rules about
 * WHEN an expense needs people live in ExpenseControlService; this class
 * runs the life cycle around them, keeps the dashboards fresh and tells the
 * right people. Expenses are never deleted — see Expenditure's class doc.
 */
class ExpenditureService
{
    /** Receipt photos are private: served only through an authorised route. */
    public const string RECEIPT_DISK = 'local';

    public function __construct(
        private readonly ExpenditureRepositoryInterface $expenditures,
        private readonly ExpenseCategoryRepositoryInterface $categories,
        private readonly ExpenseControlService $controls,
        private readonly CashPostingService $cash,
        private readonly PeriodLockService $periods,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'category_uuid', 'from', 'to', 'user_uuid', 'status', 'supplier_uuid', 'service_uuid'.
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->expenditures->paginate($this->scopedFilters($filters), $perPage);
    }

    public function findOrFail(string $uuid): Expenditure
    {
        $expenditure = $this->expenditures->findByUuid($uuid, ['category', 'user', 'supplier', 'service']);

        if (! $expenditure) {
            throw new ModelNotFoundException("Expenditure [{$uuid}] not found.");
        }

        return $expenditure;
    }

    /**
     * Records an expense and decides its starting status. $userId is passed
     * explicitly because the Sync API records on behalf of the agent whose
     * phone captured it. Control rules never reject an expense here (an
     * offline entry must not be lost) — they route it to a person instead.
     *
     * @param  array<string, mixed>  $receiptMeta  capture details from the phone/browser (time, GPS, camera vs gallery)
     * @param  array<string, mixed>  $attributes  set by the system, not the user (e.g. the recurring template it came from)
     */
    public function create(ExpenditureData $data, int $userId, ?UploadedFile $receipt = null, array $receiptMeta = [], array $attributes = []): Expenditure
    {
        $expenditure = new Expenditure([
            ...$data->toAttributes(),
            ...$attributes,
            'category_id' => $this->resolveCategoryId($data->categoryUuid),
            'supplier_id' => $this->resolveSupplierId($data->supplierUuid),
            'service_id' => $this->resolveServiceId($data->serviceUuid),
            'user_id' => $userId,
            'branch_id' => TenantContext::currentBranchId(),
        ]);

        $this->assertPaymentRefUnused($expenditure->payment_ref);
        $this->periods->assertOpen((string) $expenditure->spent_at, 'spent_at');

        DB::transaction(function () use ($expenditure, $receipt, $receiptMeta): void {
            if ($receipt !== null) {
                $this->storeReceipt($expenditure, $receipt, $receiptMeta);
                $this->controls->receiptAttached($expenditure);
            } else {
                $this->controls->evaluate($expenditure);
            }

            $expenditure->save();

            // Money left a till / account the moment it was spent.
            $this->cash->expenseRecorded($expenditure, User::query()->findOrFail($expenditure->user_id));
        });

        $this->afterStatusChange($expenditure);

        return $expenditure->load(['category', 'user', 'supplier', 'service']);
    }

    /**
     * Edits an expense that is still waiting (for its receipt or its first
     * approval). The facts may change, so it is re-checked from scratch.
     * Approved, rejected and void expenses can't be edited — void and
     * record again.
     */
    public function update(Expenditure $expenditure, ExpenditureData $data): Expenditure
    {
        if (! in_array($expenditure->status, [Expenditure::STATUS_PENDING_EVIDENCE, Expenditure::STATUS_PENDING_APPROVAL], true)) {
            throw ValidationException::withMessages(['expenditure' => ['Only an expense still waiting for approval can be edited. Void it and record it again instead.']]);
        }

        $previousSpentAt = $expenditure->spent_at;
        $expenditure->fill($data->toAttributes());

        if ($data->categoryUuid !== null) {
            $expenditure->category_id = $this->resolveCategoryId($data->categoryUuid);
        }

        if ($data->supplierUuid !== null) {
            $expenditure->supplier_id = $this->resolveSupplierId($data->supplierUuid);
        }

        if ($data->serviceUuid !== null) {
            $expenditure->service_id = $this->resolveServiceId($data->serviceUuid);
        }

        if ($expenditure->isDirty('spent_at')) {
            $this->periods->assertOpen((string) $expenditure->spent_at, 'spent_at');
        }

        if ($expenditure->isDirty('payment_ref')) {
            $this->assertPaymentRefUnused($expenditure->payment_ref, $expenditure->id);
        }

        $this->controls->evaluate($expenditure);

        if ($expenditure->receipt_path !== null && $expenditure->status === Expenditure::STATUS_PENDING_EVIDENCE) {
            $this->controls->receiptAttached($expenditure);
        }

        $expenditure->save();

        $this->forgetDashboardCache($previousSpentAt);
        $this->afterStatusChange($expenditure);

        return $expenditure->load(['category', 'user', 'supplier', 'service']);
    }

    /**
     * Adds (or replaces, while still pending) the receipt photo.
     *
     * @param  array<string, mixed>  $meta
     */
    public function attachReceipt(Expenditure $expenditure, UploadedFile $file, array $meta = []): Expenditure
    {
        if ($expenditure->receipt_path !== null && ! $expenditure->isPending()) {
            throw ValidationException::withMessages(['receipt' => ['This expense already has its receipt.']]);
        }

        $statusBefore = $expenditure->status;

        $this->storeReceipt($expenditure, $file, $meta);
        $this->controls->receiptAttached($expenditure);
        $expenditure->save();

        if ($expenditure->status !== $statusBefore) {
            $this->afterStatusChange($expenditure);
        }

        return $expenditure;
    }

    public function approve(Expenditure $expenditure, User $actor): Expenditure
    {
        $this->controls->approve($expenditure, $actor);
        $this->afterStatusChange($expenditure);

        return $expenditure;
    }

    public function reject(Expenditure $expenditure, User $actor, string $reason): Expenditure
    {
        $this->controls->reject($expenditure, $actor, $reason);
        $this->cash->expenseReversed($expenditure, $actor, 'Expense rejected: '.$reason);
        $this->afterStatusChange($expenditure);

        return $expenditure;
    }

    public function void(Expenditure $expenditure, User $actor, string $reason): Expenditure
    {
        $this->controls->void($expenditure, $actor, $reason);
        $this->cash->expenseReversed($expenditure, $actor, 'Expense voided: '.$reason);
        $this->afterStatusChange($expenditure);

        return $expenditure;
    }

    /**
     * Expenses waiting for a decision this person may make: first
     * approvals for approvers, second approvals for owners — never their
     * own.
     *
     * @return Collection<int, Expenditure>
     */
    public function awaitingDecisionBy(User $actor, bool $canApprove, bool $canApproveLarge): Collection
    {
        $statuses = array_values(array_filter([
            $canApprove ? Expenditure::STATUS_PENDING_APPROVAL : null,
            $canApproveLarge ? Expenditure::STATUS_PENDING_SECOND_APPROVAL : null,
        ]));

        if ($statuses === []) {
            return new Collection;
        }

        return Expenditure::query()
            ->with(['category', 'user', 'supplier', 'service', 'firstApprover'])
            ->whereIn('status', $statuses)
            ->where('user_id', '!=', $actor->id)
            ->where(fn ($query) => $query->whereNull('first_approved_by')->orWhere('first_approved_by', '!=', $actor->id))
            ->oldest()
            ->limit(200)
            ->get();
    }

    /**
     * Streams the receipt photo. New photos live on the private disk;
     * photos uploaded before receipts were made private are still on the
     * public disk.
     */
    public function receiptResponse(Expenditure $expenditure): StreamedResponse
    {
        abort_if($expenditure->receipt_path === null, 404);

        foreach ([self::RECEIPT_DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($expenditure->receipt_path)) {
                return Storage::disk($disk)->response($expenditure->receipt_path, null, [
                    'Cache-Control' => 'private, max-age=600',
                ]);
            }
        }

        abort(404);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeReceipt(Expenditure $expenditure, UploadedFile $file, array $meta): void
    {
        $path = $file->store('receipts/expenditures', self::RECEIPT_DISK);

        $expenditure->forceFill([
            'receipt_path' => $path,
            'receipt_sha256' => hash_file('sha256', $file->getRealPath()) ?: null,
            'receipt_meta' => array_filter([
                ...array_intersect_key($meta, array_flip(['captured_at', 'latitude', 'longitude', 'source'])),
                'uploaded_at' => Carbon::now()->toIso8601String(),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    private function afterStatusChange(Expenditure $expenditure): void
    {
        $this->forgetDashboardCache($expenditure->spent_at);
        $this->controls->notify($expenditure);
    }

    private function assertPaymentRefUnused(?string $paymentRef, ?int $ignoreId = null): void
    {
        if ($paymentRef === null) {
            return;
        }

        $taken = Expenditure::query()
            ->whereRaw('lower(payment_ref) = ?', [mb_strtolower($paymentRef)])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['payment_ref' => ['This transaction reference is already on another expense.']]);
        }
    }

    /**
     * Derives the expenditure's YYYY-MM period from its spent_at date and
     * forgets ResourcesDashboardService's cache entry for that period, so the
     * P&L dashboard reflects a change promptly rather than waiting out the
     * full 5-minute TTL. Its cache key is branch-suffixed, so — same
     * tradeoff as CustomerService::forgetShowCache() — only this actor's own
     * key and the unrestricted ('all') key are explicitly forgotten; any
     * other branch-fenced caller's copy is bounded by the 5 minute TTL.
     */
    private function forgetDashboardCache(mixed $spentAt): void
    {
        if ($spentAt === null) {
            return;
        }

        $date = $spentAt instanceof Carbon ? $spentAt : Carbon::parse($spentAt);
        $period = $date->format('Y-m');

        Cache::forget('resources:dashboard:'.$period.':'.(TenantContext::currentBranchId() ?? 'all'));
        Cache::forget('resources:dashboard:'.$period.':all');

        // /reports' Daily tier shows "expenditures recorded today" and the
        // Monthly tier embeds the P&L — see ReportService::forgetCache().
        ReportService::forgetCache($date);
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

        if (! empty($filters['supplier_uuid'])) {
            $filters['supplier_id'] = $this->resolveSupplierId($filters['supplier_uuid']);
        }

        if (! empty($filters['service_uuid'])) {
            $filters['service_id'] = $this->resolveServiceId($filters['service_uuid']);
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

    private function resolveSupplierId(?string $supplierUuid): ?int
    {
        if (! $supplierUuid) {
            return null;
        }

        $supplier = Supplier::query()->where('uuid', $supplierUuid)->where('status', '!=', Supplier::STATUS_INACTIVE)->first();

        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_uuid' => ['The selected supplier does not exist.']]);
        }

        return $supplier->id;
    }

    private function resolveServiceId(?string $serviceUuid): ?int
    {
        if (! $serviceUuid) {
            return null;
        }

        $service = Service::query()->where('uuid', $serviceUuid)->first();

        if (! $service) {
            throw ValidationException::withMessages(['service_uuid' => ['The selected service does not exist.']]);
        }

        return $service->id;
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
