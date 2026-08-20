<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Services\ManuscriptCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

    public function __construct(private readonly ManuscriptCalculator $calculator)
    {
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
            foreach ($customers as $customer) {
                try {
                    DB::transaction(function () use ($customer, $period, &$frozenCustomers, &$totalArrearsSum, &$totalCreditSum, &$totalBillSum): void {
                        $result = $this->calculator->calculate($customer, $period);

                        Manuscript::query()
                            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
                            ->fill($result->toManuscriptAttributes())
                            ->save();

                        foreach ($result->processedPayments as $payment) {
                            $payment->forceFill(['processed_at' => Carbon::now()])->save();
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
