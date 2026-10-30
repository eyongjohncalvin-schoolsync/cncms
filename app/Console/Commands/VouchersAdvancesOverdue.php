<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\VoucherService;
use Illuminate\Console\Command;

/**
 * Cash advances past their settle date with nothing accounted for become
 * the requester's shortage, in every workspace (owner, 2026-10-30) — see
 * VoucherService::openOverdueAdvanceShortages(). Runs daily; scheduled in
 * routes/console.php.
 */
class VouchersAdvancesOverdue extends Command
{
    protected $signature = 'vouchers:advances-overdue {tenant? : Tenant id; omit for every tenant}';

    protected $description = 'Turn cash advances past their settle date into the requester\'s shortage';

    public function handle(VoucherService $vouchers): int
    {
        $tenantId = $this->argument('tenant');
        $tenants = $tenantId !== null ? Tenant::query()->where('id', $tenantId)->get() : Tenant::all();

        tenancy()->runForMultiple($tenants, function (Tenant $tenant) use ($vouchers): void {
            $count = $vouchers->openOverdueAdvanceShortages();

            $this->line("  [{$tenant->id}] {$count} overdue advance(s) became shortages.");
        });

        return self::SUCCESS;
    }
}
