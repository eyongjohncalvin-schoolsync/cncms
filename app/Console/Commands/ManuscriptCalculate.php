<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\ManuscriptCalculator;
use App\Services\ManuscriptRerunGuard;
use App\Services\ManuscriptService;
use App\Support\DetectsUniqueViolation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManuscriptCalculate extends Command
{
    use DetectsUniqueViolation;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manuscript:calculate
        {period? : YYYY-MM, defaults to the UPCOMING month — a run executed near month-end governs the month it is about to become, not the one it runs in (business-rules.md section 2, 2026-08-28 correction)}
        {--tenant=swecom : Slug/id of the tenant to run the calculation for}
        {--force : Recompute even if this period was already calculated and published — Laravel\'s established "yes, proceed anyway" idiom (cf. migrate --force)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the monthly manuscript billing calculation for every customer of a tenant';

    public function __construct(
        private readonly ManuscriptCalculator $calculator,
        private readonly ManuscriptService $manuscripts,
        private readonly ManuscriptRerunGuard $rerunGuard,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // 2026-08-28 correction (business-rules.md section 2): this command
        // is triggered near month-end ("historically last week of each
        // month"), and the resulting manuscript governs billing through the
        // NEXT calendar month, not the one the run executes in — a run on
        // 2026-07-22 with no explicit period produces period='2026-08', the
        // bill customers actually owe throughout August, not '2026-07'.
        // addMonthNoOverflow(), not addMonth(): a run on the 29th-31st must
        // not skip a whole month when the target month is shorter (e.g. Jan
        // 31 + addMonth() overflows to March 3, not Feb 28/29).
        $period = (string) ($this->argument('period') ?? Carbon::now()->addMonthNoOverflow()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error("Invalid period \"{$period}\" — expected format YYYY-MM.");

            return self::FAILURE;
        }

        $tenantId = (string) $this->option('tenant');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $startedAt = microtime(true);

        tenancy()->initialize($tenant);

        try {
            // The "already safely runnable" guard (task-scheduler.md's
            // 2026-08-27 addendum, App\Services\ManuscriptRerunGuard): refuses
            // when a PUBLISHED manuscript:calculate run already exists for
            // $period unless --force was passed. Deliberately run BEFORE the
            // queued command_runs row below is even inserted — a refused run
            // shouldn't leave any new row behind. This is a different check
            // from idx_command_runs_period_inflight just below (two runs
            // racing right now); both must coexist.
            try {
                $this->rerunGuard->assertRerunAllowed($period, $force);
            } catch (ValidationException $e) {
                $this->error(collect($e->errors())->flatten()->implode(' ').' Pass --force to recompute it anyway.');

                return self::FAILURE;
            }

            // Inserted with status='queued' BEFORE the synchronous computation
            // starts (2026-08-27) — this is what makes this CLI path subject
            // to the SAME idx_command_runs_period_inflight partial unique
            // index (command, period) WHERE status IN ('queued',
            // 'pending_review') that
            // App\Services\ManuscriptGenerationBatchService::dispatch()
            // already relies on: the index only cares about a command_runs
            // row's status at any given moment, not what code inserted it or
            // whether it's sync or async, so a concurrent web/scheduled
            // dispatch() OR a second simultaneous CLI invocation for this
            // same period now correctly collides against this row. Updated to
            // 'published' (success) or 'failed' (exception) below once
            // computation finishes — never left at 'queued'.
            try {
                $commandRun = CommandRun::create([
                    'command' => 'manuscript:calculate',
                    'period' => $period,
                    'ran_at' => Carbon::now(),
                    'metadata' => ['tenant' => $tenantId, 'trigger' => 'cli'],
                    'status' => 'queued',
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $this->error("A manuscript calculation for {$period} is already running or awaiting review. Wait for it to finish before starting another.");

                    return self::FAILURE;
                }

                throw $e;
            }

            try {
                $stats = $this->runForEveryCustomer($period, $commandRun);

                // Runs (success or partial — matches the commandRun update
                // below, which publishes regardless of $stats['errors'],
                // matching this command's pre-existing behavior of always
                // logging a row even when some per-customer errors occurred)
                // while tenancy is still initialized, so
                // CacheTenancyBootstrapper prefixes the forgotten key to this
                // same tenant. See ManuscriptService::forgetSummaryCache()
                // for what this does and does not cover.
                $this->manuscripts->forgetSummaryCache($period);

                $stats['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
                $stats['tenant'] = $tenantId;

                $commandRun->update([
                    'status' => 'published',
                    'metadata' => [...$commandRun->metadata, ...$stats],
                    'published_at' => Carbon::now(),
                ]);

                $this->info(sprintf(
                    'manuscript:calculate [%s] tenant=%s — %d customers processed, %d frozen, %d errors, %sms.',
                    $period,
                    $tenantId,
                    $stats['customers_processed'],
                    $stats['frozen_customers'],
                    $stats['errors'],
                    $stats['duration_ms'],
                ));

                return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
            } catch (Throwable $e) {
                // A FATAL failure — something outside runForEveryCustomer()'s
                // own per-customer try/catch (which already tolerates and
                // counts a single bad customer record without throwing).
                // Marked 'failed' rather than left at 'queued' so this row
                // never permanently blocks idx_command_runs_period_inflight
                // for this period.
                $commandRun->update([
                    'status' => 'failed',
                    'metadata' => [...$commandRun->metadata, 'exception' => $e->getMessage()],
                ]);

                report($e);

                $this->error("manuscript:calculate [{$period}] failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runForEveryCustomer(string $period, CommandRun $commandRun): array
    {
        $customersProcessed = 0;
        $frozenCustomers = 0;
        $totalArrearsSum = '0.00';
        $totalCreditSum = '0.00';
        $totalBillSum = '0.00';
        $errors = 0;
        $errorDetails = [];

        Customer::query()->chunkById(200, function ($customers) use (
            $period,
            $commandRun,
            &$customersProcessed,
            &$frozenCustomers,
            &$totalArrearsSum,
            &$totalCreditSum,
            &$totalBillSum,
            &$errors,
            &$errorDetails,
        ): void {
            $customerIds = $customers->pluck('id');

            // Batch-resolve, once per chunk of 200, what ManuscriptCalculator
            // used to query per customer: each customer's most recent
            // prior-period manuscript and their period-eligible verified
            // payments. `period` is a 'YYYY-MM' string, so sortByDesc('period')
            // in PHP is equivalent to the previous per-customer
            // ->orderByDesc('period')->first() SQL ordering.
            $previousManuscriptsByCustomer = Manuscript::query()
                ->whereIn('customer_id', $customerIds)
                ->where('period', '<', $period)
                ->get()
                ->groupBy('customer_id')
                ->map(fn (Collection $manuscripts): Manuscript => $manuscripts->sortByDesc('period')->first());

            // Eligibility goes through the single shared
            // Payment::scopeEligibleForPeriod() predicate — see that
            // method's doc comment. This used to inline an identical copy of
            // that query (the other of the two places
            // App\Support\ScheduledTasks\ManuscriptChunkDataResolver::resolve()'s
            // own doc comment warned had to stay in lockstep); now both, plus
            // App\Services\ManuscriptPreRunReviewService, share one
            // definition instead.
            $eligibleVerifiedPaymentsByCustomer = Payment::query()
                ->whereIn('customer_id', $customerIds)
                ->eligibleForPeriod($period)
                ->get()
                ->groupBy('customer_id');

            // Same idempotency mechanism, applied to approved arrears
            // adjustments targeting exactly this period — see
            // App\Support\ScheduledTasks\ManuscriptChunkDataResolver's class
            // doc: this inlined query is the OTHER of the two places that
            // must stay in lockstep with it.
            $eligibleAdjustmentsByCustomer = ArrearsAdjustment::query()
                ->whereIn('customer_id', $customerIds)
                ->where('status', 'approved')
                ->where('target_period', $period)
                ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))
                ->get()
                ->groupBy('customer_id');

            foreach ($customers as $customer) {
                try {
                    DB::transaction(function () use (
                        $customer,
                        $period,
                        $commandRun,
                        $previousManuscriptsByCustomer,
                        $eligibleVerifiedPaymentsByCustomer,
                        $eligibleAdjustmentsByCustomer,
                        &$frozenCustomers,
                        &$totalArrearsSum,
                        &$totalCreditSum,
                        &$totalBillSum,
                    ): void {
                        $result = $this->calculator->calculate(
                            $customer,
                            $period,
                            $previousManuscriptsByCustomer->get($customer->id),
                            $eligibleVerifiedPaymentsByCustomer->get($customer->id, new Collection),
                            $eligibleAdjustmentsByCustomer->get($customer->id, new Collection),
                        );

                        // command_run_id — see ManuscriptGenerationBatchService::
                        // publish()'s matching comment; this is the CLI's own
                        // direct write path (no publish() involved), so it must
                        // set the same linkage itself.
                        Manuscript::query()
                            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
                            ->fill([...$result->toManuscriptAttributes(), 'command_run_id' => $commandRun->id])
                            ->save();

                        foreach ($result->processedPayments as $payment) {
                            $payment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
                        }

                        foreach ($result->processedAdjustments as $adjustment) {
                            $adjustment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
                        }

                        if ($result->isFrozen) {
                            $frozenCustomers++;
                        }

                        $totalArrearsSum = bcadd($totalArrearsSum, $result->totalArrears, 2);
                        $totalCreditSum = bcadd($totalCreditSum, $result->credit, 2);
                        $totalBillSum = bcadd($totalBillSum, $result->totalBill, 2);
                    });

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
        });

        return [
            'customers_processed' => $customersProcessed,
            'frozen_customers' => $frozenCustomers,
            'total_arrears_sum' => (float) $totalArrearsSum,
            'total_credit_sum' => (float) $totalCreditSum,
            'total_bill_sum' => (float) $totalBillSum,
            'errors' => $errors,
            'error_details' => $errorDetails,
        ];
    }
}
