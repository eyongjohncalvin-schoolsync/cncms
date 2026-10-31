<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ExpenditureMonthService;
use Illuminate\Console\Command;

/**
 * Month close (E6 of docs/plans/expenditures-and-spending-controls.md):
 * from the 5th of each month (Cameroon time) closes the previous month's
 * expenditures in every workspace and snapshots its totals. Runs daily so a
 * missed day still closes the month; a month the owner reopened is left
 * for the owner to close again. Scheduled in routes/console.php.
 */
class ExpendituresCloseMonths extends Command
{
    protected $signature = 'expenditures:close-months {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Close the previous month\'s expenditures (from the 5th, Cameroon time) and snapshot its totals';

    public function handle(ExpenditureMonthService $months): int
    {
        $tenantId = $this->argument('tenant');
        $tenants = $tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all();

        // A demo that ended is read-only (App\Support\DemoReadOnly): no automatic changes either.
        $tenants = $tenants->reject(fn (Tenant $tenant) => $tenant->isReadOnly());

        tenancy()->runForMultiple($tenants, function (Tenant $tenant) use ($months): void {
            $closed = $months->closeDue();

            $this->line($closed === []
                ? "  [{$tenant->id}] nothing to close."
                : "  [{$tenant->id}] closed ".implode(', ', $closed).'.');
        });

        return self::SUCCESS;
    }
}
