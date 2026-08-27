<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use Illuminate\Console\Command;

class CheckBabila extends Command
{
    protected $signature = 'debug:check-babila {--clean}';

    protected $description = 'Temporary investigation command';

    public function handle(): void
    {
        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);
            $this->info("=== Tenant: {$tenant->id} ===");

            $customers = Customer::where('name', 'like', '%abila%')
                ->orWhere('name', 'like', '%rancis%')
                ->get();
            $this->info('  matches for abila/rancis: '.$customers->count());

            // Real business: this tenant only ever legitimately has manuscript
            // periods up to the current month (2026-08). Anything beyond
            // 2026-09 is definitionally bogus. Break down by period to see
            // the true scope before deleting anything.
            $bogus = Manuscript::where('period', '>', '2026-08')
                ->selectRaw('period, count(*) as cnt, min(created_at) as first_created, max(created_at) as last_created')
                ->groupBy('period')
                ->orderBy('period')
                ->get();
            $this->info('  Bogus period breakdown (period > 2026-08):');
            $totalBogus = 0;
            foreach ($bogus as $row) {
                $this->line("    period={$row->period} count={$row->cnt} created={$row->first_created} to {$row->last_created}");
                $totalBogus += $row->cnt;
            }
            $this->info("  TOTAL bogus rows: {$totalBogus}");
            $this->info('  Total real manuscript rows (period <= 2026-08): '.Manuscript::where('period', '<=', '2026-08')->count());

            if ($this->option('clean') && $tenant->id === 'swecom') {
                $deleted = Manuscript::where('period', '>', '2026-08')->delete();
                $this->warn("  DELETED {$deleted} bogus manuscript rows for tenant {$tenant->id}");
            }

            foreach ($customers as $customer) {
                $this->info("Customer: id={$customer->id} uuid={$customer->uuid} name={$customer->name} status={$customer->status} bill={$customer->bill} created_at={$customer->created_at}");

                $manuscripts = Manuscript::where('customer_id', $customer->id)->orderBy('period')->get();
                $this->info("  Manuscript count: {$manuscripts->count()}");

                foreach ($manuscripts as $m) {
                    $this->line("  period={$m->period} arrears={$m->total_arrears} credit={$m->credit} total_bill={$m->total_bill} created={$m->created_at} updated={$m->updated_at}");
                }
            }

            tenancy()->end();
        }
    }
}
