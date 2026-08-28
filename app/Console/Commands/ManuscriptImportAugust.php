<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off bootstrap: loads the legacy (v1) end-of-cycle manuscript register
 * into the v2 `manuscripts` table as the period-2026-08 rows, so the first v2
 * `manuscript:calculate` run (period 2026-09) has a real prior position to
 * carry forward instead of falling back to `customers.others` as a first run.
 *
 * Pure inserts — reads bill / total_arrears / credit / total_bill verbatim
 * from a CSV, sets period='2026-08', payment_expiration=NULL,
 * command_run_id=NULL (no calc run produced these; NULL keeps them out of any
 * run's Delete/Rollback scope, exactly like the schema's other pre-migration
 * historical rows). Touches nothing else — no payments, no command_runs.
 *
 * Safe to delete once the 2026-08 rows are in place.
 */
class ManuscriptImportAugust extends Command
{
    protected $signature = 'manuscript:import-august
        {--tenant=swecom : Tenant slug/id to load into}
        {--file= : Path to the CSV (cus_id,name,zone,status,bill,total_arrears,credit,total_bill)}
        {--period=2026-08 : Period label to write}
        {--force : Insert even if rows already exist for this period}';

    protected $description = 'One-off: insert the legacy August (2026-08) manuscript register into a tenant';

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $period = (string) $this->option('period');
        $file = (string) ($this->option('file') ?: base_path('august_manuscript.csv'));
        $force = (bool) $this->option('force');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error("Invalid period \"{$period}\".");

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error("CSV not found: {$file}");

            return self::FAILURE;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $header = str_getcsv((string) array_shift($lines));
        $expected = ['cus_id', 'name', 'zone', 'status', 'bill', 'total_arrears', 'credit', 'total_bill'];
        if ($header !== $expected) {
            $this->error('Unexpected CSV header: '.implode(',', $header));

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $already = Manuscript::query()->where('period', $period)->count();
            if ($already > 0 && ! $force) {
                $this->error("{$already} manuscript rows already exist for {$period}. Pass --force to add anyway.");

                return self::FAILURE;
            }

            $validIds = Customer::query()->pluck('id')->flip();
            $rows = [];
            $missing = [];
            foreach ($lines as $line) {
                $c = str_getcsv($line);
                $cusId = (int) $c[0];
                if (! isset($validIds[$cusId])) {
                    $missing[] = $cusId;

                    continue;
                }
                $rows[] = [
                    'customer_id' => $cusId,
                    'bill' => $c[4],
                    'total_arrears' => $c[5],
                    'credit' => $c[6],
                    'total_bill' => $c[7],
                    'payment_expiration' => null,
                    'period' => $period,
                    'command_run_id' => null,
                ];
            }

            if ($missing !== []) {
                $this->error('CSV has customer_ids absent from this tenant: '.implode(',', $missing));

                return self::FAILURE;
            }

            DB::transaction(function () use ($rows): void {
                foreach ($rows as $attrs) {
                    Manuscript::query()->create($attrs);
                }
            });

            $n = Manuscript::query()->where('period', $period)->count();
            $this->info("Inserted. period={$period} rows_now={$n}");
            $this->table(['metric', 'sum'], [
                ['bill', number_format((float) Manuscript::query()->where('period', $period)->sum('bill'))],
                ['total_arrears', number_format((float) Manuscript::query()->where('period', $period)->sum('total_arrears'))],
                ['credit', number_format((float) Manuscript::query()->where('period', $period)->sum('credit'))],
                ['total_bill', number_format((float) Manuscript::query()->where('period', $period)->sum('total_bill'))],
            ]);

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
