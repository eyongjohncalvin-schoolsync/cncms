<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ApplyCompensationBatchJob;
use App\Models\Branch;
use App\Models\CompensationBatch;
use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\Zone;
use App\Support\BranchLookup;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compensation for a whole zone or branch after an outage (owner,
 * 2026-10-25): one App\Models\CompensationBatch with one free-months request
 * per billable customer (App\Services\FreeMonthGrantService::requestCompensation()),
 * all approved or rejected together — always by someone other than whoever
 * asked (the owner excepted). Approving gives each customer their months in
 * the background (ApplyCompensationBatchJob): a branch can be thousands of
 * customers.
 */
class CompensationService
{
    /** Customers who were receiving the service: not cut off, not awaiting activation. */
    private const array BILLABLE_STATUSES = ['active', 'passive'];

    public function __construct(
        private readonly FreeMonthGrantService $grants,
    ) {}

    /**
     * @param  list<int>|null  $serviceIds  null = every service each customer holds
     */
    public function request(Branch $branch, ?Zone $zone, ?array $serviceIds, int $months, string $note, User $actor): CompensationBatch
    {
        // Resolved here, not injected: apply() also runs on a queue worker, where there is no signed-in user.
        $context = app(TenantContext::class);

        if ($context->isFenced() && $context->branchId !== $branch->id) {
            throw ValidationException::withMessages(['branch' => 'You can only compensate customers of your own branch.']);
        }

        if ($zone !== null && $zone->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['zone' => 'That zone is not in this branch.']);
        }

        return DB::transaction(function () use ($branch, $zone, $serviceIds, $months, $note, $actor): CompensationBatch {
            $batch = CompensationBatch::query()->create([
                'branch_id' => $branch->id,
                'zone_id' => $zone?->id,
                'service_ids' => $serviceIds,
                'months' => $months,
                'note' => $note,
                'status' => CompensationBatch::STATUS_PENDING_APPROVAL,
                'requested_by' => $actor->id,
            ]);

            $held = DB::table('customer_service')
                ->whereIn('customer_id', $this->customersQuery($batch)->select('id'))
                ->select('customer_id', 'service_id')
                ->distinct()
                ->get()
                ->groupBy('customer_id')
                ->map(fn ($rows): array => $rows->pluck('service_id')->map(fn ($id): int => (int) $id)->all());

            $count = 0;

            $this->customersQuery($batch)->with('zone')->chunkById(500, function ($customers) use ($batch, $held, $serviceIds, $months, $note, $actor, &$count): void {
                // The new requests take their customer's branch from memory, not a query each.
                BranchLookup::remember($customers);

                foreach ($customers as $customer) {
                    $theirs = $held[$customer->id] ?? [];
                    $services = $serviceIds === null ? $theirs : array_values(array_intersect($serviceIds, $theirs));

                    if ($services === []) {
                        continue;
                    }

                    // $theirs: the services already looked up for the whole batch above.
                    $this->grants->requestCompensation($customer, $services, $months, $note, $actor, batchId: $batch->id, heldServiceIds: $theirs);
                    $count++;
                }
            });

            if ($count === 0) {
                throw ValidationException::withMessages(['zone' => 'No customer there holds those services — nothing to compensate.']);
            }

            $batch->update(['customers_count' => $count]);

            return $batch;
        });
    }

    public function approve(CompensationBatch $batch, User $actor): CompensationBatch
    {
        $batch = DB::transaction(function () use ($batch, $actor): CompensationBatch {
            $locked = $this->lockedPending($batch);
            $locked->update(['status' => CompensationBatch::STATUS_APPROVED, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $locked;
        });

        // Sent after the approval's transaction has committed (above), so the worker sees it approved.
        ApplyCompensationBatchJob::dispatch($batch->id, $actor->id);

        return $batch;
    }

    public function reject(CompensationBatch $batch, User $actor, ?string $reason): CompensationBatch
    {
        return DB::transaction(function () use ($batch, $actor, $reason): CompensationBatch {
            $locked = $this->lockedPending($batch);
            $locked->update(['status' => CompensationBatch::STATUS_REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'rejection_reason' => $reason]);

            FreeMonthGrant::query()
                ->where('compensation_batch_id', $locked->id)
                ->where('status', FreeMonthGrant::STATUS_PENDING_APPROVAL)
                ->update(['status' => FreeMonthGrant::STATUS_REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'rejection_reason' => $reason]);

            return $locked;
        });
    }

    /** Customers given their months per transaction while applying a batch. */
    public const int APPLY_CHUNK = 100;

    /**
     * Gives the customers of an approved batch their months, APPLY_CHUNK
     * customers at a time — each chunk one transaction with one batched
     * recalculation per month (FreeMonthGrantService::approveCompensationGroups()).
     * A request already applied is skipped, so running it again finishes an
     * interrupted batch. Stops after $secondsBudget and returns false when
     * customers remain (the job queues itself again), true when done.
     */
    public function apply(CompensationBatch $batch, User $actor, int $secondsBudget = PHP_INT_MAX): bool
    {
        $startedAt = microtime(true);

        while (true) {
            $groupUuids = FreeMonthGrant::query()
                ->where('compensation_batch_id', $batch->id)
                ->where('status', FreeMonthGrant::STATUS_PENDING_APPROVAL)
                ->select('group_uuid')
                ->groupBy('group_uuid')
                ->orderByRaw('min(id)')
                ->limit(self::APPLY_CHUNK)
                ->pluck('group_uuid')
                ->all();

            if ($groupUuids === []) {
                return true;
            }

            $this->grants->approveCompensationGroups($groupUuids, $actor, $batch->id);

            if (microtime(true) - $startedAt > $secondsBudget) {
                return false;
            }
        }
    }

    /**
     * @return Builder<Customer>
     */
    private function customersQuery(CompensationBatch $batch): Builder
    {
        return Customer::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $batch->branch_id)
            ->when($batch->zone_id !== null, fn ($query) => $query->where('zone_id', $batch->zone_id))
            ->whereIn('status', self::BILLABLE_STATUSES)
            ->where(fn ($query) => $query->whereNull('activation_status')->orWhere('activation_status', '!=', Customer::ACTIVATION_PENDING));
    }

    private function lockedPending(CompensationBatch $batch): CompensationBatch
    {
        $locked = CompensationBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== CompensationBatch::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['batch' => 'This compensation is not waiting for approval.']);
        }

        return $locked;
    }
}
