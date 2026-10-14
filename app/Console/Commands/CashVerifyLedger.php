<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CashLedgerService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Nightly seal check of every tenant's cash ledger: recomputes the hash
 * chain and every account balance. A break means a ledger row or a balance
 * was changed outside the application — the owner is alerted at once.
 */
class CashVerifyLedger extends Command
{
    protected $signature = 'cash:verify-ledger {--tenant= : Only this tenant}';

    protected $description = 'Check the cash ledger hash chain and balances; alert the owner on any break';

    public function handle(CashLedgerService $ledger, NotificationService $notifications): int
    {
        $failed = 0;
        $tenants = $this->option('tenant') ? [$this->option('tenant')] : null;

        tenancy()->runForMultiple($tenants, function (Tenant $tenant) use ($ledger, $notifications, &$failed): void {
            $result = $ledger->verify();
            $broken = $result['broken_at'] !== null || $result['balance_mismatches'] !== [];

            $this->line(sprintf(
                '%s: %d entries, chain %s, %d balance mismatch(es)',
                $tenant->getTenantKey(),
                $result['entries'],
                $result['broken_at'] === null ? 'intact' : "BROKEN at #{$result['broken_at']}",
                count($result['balance_mismatches']),
            ));

            if ($broken) {
                $failed++;
                $notifications->broadcastToRole(
                    'super',
                    'cash.ledger_tampered',
                    'emergency',
                    'Cash ledger check failed',
                    $result['broken_at'] !== null
                        ? "The cash ledger's seal is broken at entry #{$result['broken_at']} — a record was changed outside the app."
                        : 'Cash account balances no longer match the ledger — a balance was changed outside the app.',
                    '/cash',
                );
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
