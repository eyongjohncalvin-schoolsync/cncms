<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Services\ManuscriptCalculator;
use App\Support\ScheduledTasks\ManuscriptChunkDataResolver;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One chunk of the manuscript_generation compute step (task-scheduler.md
 * section 4.1) — computes arrears/credit/total_bill/frozen-status for its
 * slice of the tenant's customers and merges the result into this run's
 * durable computed_result store (command_runs.computed_result).
 *
 * Deliberately does NOT write to the live `manuscripts` table and does NOT
 * stamp `payments.processed_at`/`processed_period` — those are
 * publish-time-only side effects
 * (see App\Services\ManuscriptGenerationBatchService::publish()), so a
 * payment arriving between compute and an admin's review can never change
 * numbers already computed and shown in the preview.
 *
 * Always dispatched from inside an active tenancy()->initialize() context
 * (see App\Services\ManuscriptGenerationBatchService::dispatch()) — Stancl's
 * QueueTenancyBootstrapper (config/tenancy.php) transparently re-initializes
 * the correct tenant schema when this job (and the enclosing batch's
 * then()/catch() closures) actually run on the queue worker, so no tenant id
 * needs to be threaded through this job manually.
 *
 * A per-customer calculation error is caught and logged into this chunk's
 * own error list WITHOUT failing the job (mirrors
 * App\Console\Commands\ManuscriptCalculate's existing per-customer
 * try/catch) — only a failure in the shared, once-per-chunk data resolution
 * (App\Support\ScheduledTasks\ManuscriptChunkDataResolver, called outside
 * this try/catch on purpose) fails the whole job, which is the coarser,
 * batch-level failure mode allowFailures()/catch() exist to tolerate.
 */
class ComputeManuscriptChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, int>  $customerIds  Internal (not uuid) customer ids assigned to this chunk.
     */
    public function __construct(
        public readonly int $commandRunId,
        public readonly string $period,
        public readonly array $customerIds,
        public readonly int $chunkIndex,
    ) {}

    public function handle(ManuscriptCalculator $calculator, ManuscriptChunkDataResolver $resolver): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        [$previousManuscriptsByCustomer, $eligibleVerifiedPaymentsByCustomer, $eligibleAdjustmentsByCustomer] = $resolver->resolve($this->customerIds, $this->period);

        $customers = Customer::query()->whereIn('id', $this->customerIds)->get()->keyBy('id');

        $customerResults = [];
        $customersProcessed = 0;
        $frozenCustomers = 0;
        $totalArrearsSum = '0.00';
        $totalCreditSum = '0.00';
        $totalBillSum = '0.00';
        $errors = 0;
        $errorDetails = [];

        foreach ($this->customerIds as $customerId) {
            $customer = $customers->get($customerId);

            if (! $customer) {
                // Deleted between dispatch and this job actually running —
                // not a calculation error, just nothing left to compute.
                continue;
            }

            try {
                $result = $calculator->calculate(
                    $customer,
                    $this->period,
                    $previousManuscriptsByCustomer->get($customer->id),
                    $eligibleVerifiedPaymentsByCustomer->get($customer->id, new Collection),
                    $eligibleAdjustmentsByCustomer->get($customer->id, new Collection),
                );

                $attributes = $result->toManuscriptAttributes();
                $attributes['payment_expiration'] = $attributes['payment_expiration']?->toDateString();

                $customerResults[(string) $customer->id] = [
                    'attributes' => $attributes,
                    'processed_payment_ids' => $result->processedPayments->pluck('id')->all(),
                    'processed_adjustment_ids' => $result->processedAdjustments->pluck('id')->all(),
                    'is_frozen' => $result->isFrozen,
                ];

                if ($result->isFrozen) {
                    $frozenCustomers++;
                }

                $totalArrearsSum = bcadd($totalArrearsSum, $result->totalArrears, 2);
                $totalCreditSum = bcadd($totalCreditSum, $result->credit, 2);
                $totalBillSum = bcadd($totalBillSum, $result->totalBill, 2);
                $customersProcessed++;
            } catch (Throwable $e) {
                $errors++;
                $errorDetails[] = [
                    'customer_id' => $customer->id,
                    'customer_uuid' => $customer->uuid,
                    'message' => $e->getMessage(),
                ];
                report($e);
            }
        }

        $this->mergeIntoComputedResult([
            'customers' => $customerResults,
            'stats' => [
                'customers_processed' => $customersProcessed,
                'frozen_customers' => $frozenCustomers,
                'total_arrears_sum' => $totalArrearsSum,
                'total_credit_sum' => $totalCreditSum,
                'total_bill_sum' => $totalBillSum,
                'errors' => $errors,
                'error_details' => $errorDetails,
            ],
        ]);
    }

    /**
     * Atomically merges this chunk's results into command_runs.computed_result
     * under its own top-level "chunk_N" key via a single parameterized SQL
     * UPDATE using Postgres jsonb's `||` operator — NOT a PHP-side
     * read-modify-write (SELECT then UPDATE), which would lose data under
     * concurrent chunk jobs (two chunks reading the same starting value,
     * each writing back their own version, one clobbering the other's — a
     * classic lost-update race). Every chunk writes to a DISTINCT top-level
     * key, so jsonb `||`'s shallow top-level merge is exactly correct and
     * safe under concurrency (Postgres serializes concurrent UPDATEs to the
     * same row; each one's `||` reads the just-committed value under the
     * row lock). See
     * App\Services\ManuscriptGenerationBatchService::aggregateComputedResult()
     * for where these per-chunk keys get flattened into the final
     * "customers"/"summary" shape once the whole batch completes.
     */
    private function mergeIntoComputedResult(array $chunkPayload): void
    {
        DB::statement(
            'UPDATE command_runs SET computed_result = coalesce(computed_result, \'{}\'::jsonb) || ?::jsonb WHERE id = ?',
            [json_encode(['chunk_'.$this->chunkIndex => $chunkPayload], JSON_THROW_ON_ERROR), $this->commandRunId]
        );
    }
}
