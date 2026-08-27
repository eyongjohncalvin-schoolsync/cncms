<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use Database\Seeders\DemoTransactionalDataSeeder;
use Illuminate\Console\Command;

/**
 * Thin wrapper around DemoTransactionalDataSeeder for the demo-data reset
 * documented in that class's docblock.
 *
 * Why a dedicated command instead of `php artisan tenants:seed --class=...`:
 * Stancl's `tenants:seed` already brackets the whole seeder run in
 * tenancy()->initialize()/end() via Tenancy::runForMultiple(). But
 * DemoTransactionalDataSeeder needs to end/re-initialize tenancy itself,
 * repeatedly, around each `manuscript:calculate` sub-invocation (that
 * command brackets its own tenancy context too — see the seeder's
 * replayPaymentsAndCalculateManuscripts() docblock). Nesting that dance
 * inside `tenants:seed`'s own bracketing works via Stancl's idempotent
 * initialize()/no-op end(), but running it as its own command keeps the
 * tenancy lifecycle easy to reason about end-to-end and gives us a place to
 * print a real verification summary once seeding finishes.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed {--tenant=swecom : Slug/id of the tenant to seed}';

    protected $description = 'Seed the reconstructed demo dataset (customers, payment history, manuscripts, users, agents) into a tenant schema';

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            (new DemoTransactionalDataSeeder)->setCommand($this)->run();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
        }

        $this->newLine();
        $this->info('Verification counts (tenant='.$tenantId.'):');
        $this->table(['Table', 'Count'], [
            ['customers', Customer::count()],
            ['payments', Payment::count()],
            ['manuscripts', Manuscript::count()],
            ['agents', Agent::count()],
            ['command_runs', CommandRun::count()],
        ]);

        $this->newLine();
        $this->info('Customers by status:');
        $this->table(['status', 'count'], Customer::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [$row->status, $row->count])
            ->all());

        tenancy()->end();

        return self::SUCCESS;
    }
}
