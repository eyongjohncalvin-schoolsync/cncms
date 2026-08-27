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
use App\Services\ManuscriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class ManuscriptCalculate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manuscript:calculate
        {period? : YYYY-MM, defaults to current month}
        {--tenant=swecom : Slug/id of the tenant to run the calculation for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the monthly manuscript billing calculation for every customer of a tenant';

    public function __construct(
        private readonly ManuscriptCalculator $calculator,
        private readonly ManuscriptService $manuscripts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->argument('period') ?? Carbon::now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
            $this->error("Invalid period \"{$period}\" — expected format YYYY-MM.");

            return self::FAILURE;
        }

        $tenantId = (string) $this->option('tenant');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        tenancy()->initialize($tenant);

        try {
            $stats = $this->runForEveryCustomer((string) $period);

            // Runs (success or partial — matches CommandRun below, which logs
            // regardless of $stats['errors']) while tenancy is still
            // initialized, so CacheTenancyBootstrapper prefixes the forgotten
            // key to this same tenant. See ManuscriptService::forgetSummaryCache()
            // for what this does and does not cover.
            $this->manuscripts->forgetSummaryCache((string) $period);

            $stats['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
            $stats['tenant'] = $tenantId;

            CommandRun::create([
                'command' => 'manuscript:calculate',
                'period' => $period,
                'ran_at' => Carbon::now(),
                'metadata' => $stats,
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
        } finally {
            tenancy()->end();
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function runForEveryCustomer(string $period): array
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

            // Eligibility mirrors App\Support\ScheduledTasks\ManuscriptChunkDataResolver::resolve()
            // exactly — see App\Services\ManuscriptCalculator's class doc for
            // the full rationale (idempotent reruns + frozen-payment
            // carry-forward + no double-counting across periods).
            $eligibleVerifiedPaymentsByCustomer = Payment::query()
                ->whereIn('customer_id', $customerIds)
                ->where('verification_status', 'verified')
                ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))
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

                        Manuscript::query()
                            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
                            ->fill($result->toManuscriptAttributes())
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
