<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off: scrub bogus far-future `processed_period` values off real
 * payments.
 *
 * Test suites that run the real `manuscript:calculate --tenant=swecom`
 * command for fictional far-future periods (2031-*, 2033-*, 2034-*) against
 * the whole tenant stamp `processed_period` on every active customer's
 * verified payments and only clean up the manuscript rows afterward, not
 * the payment stamps. That leaves real payments marked as consumed by a
 * period that does not and will never exist.
 *
 * For each affected payment:
 *   - created before `--before` (a real past month): re-stamp to its own
 *     creation month — it WAS a historical payment, already reflected in
 *     the imported baseline, and must stay ineligible for future runs.
 *   - created on/after `--before`: reset to NULL — it is a genuine recent
 *     payment that no real run has consumed yet, so the next
 *     `manuscript:calculate` should pick it up.
 *
 * The polluting tests are being deleted alongside this command. Dry run by
 * default; `--apply` to write. Tenant-guarded, idempotent, each write
 * audited via the Auditable trait. Safe to delete once run.
 */
class PaymentsPurgeTestPeriodStamps extends Command
{
    protected $signature = 'payments:purge-test-period-stamps
        {--tenant=swecom : Tenant slug/id}
        {--before=2026-08 : Payments created before this YYYY-MM go back to their real month; on/after go to NULL}
        {--apply : Write the changes (default is a dry run)}
        {--force : Allow running against a tenant other than the default}';

    protected $description = 'Scrub bogus far-future processed_period values (2031-*/2033-*/2034-*) left on real payments by test runs';

    /**
     * SQL LIKE patterns for periods that are ONLY ever produced by test
     * runs — no real or planned calculation uses them.
     *
     * @var array<int, string>
     */
    private const BOGUS_PATTERNS = ['2031-%', '2033-%', '2034-%'];

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $before = (string) $this->option('before');
        $apply = (bool) $this->option('apply');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $before)) {
            $this->error("Invalid --before period \"{$before}\" (expected YYYY-MM).");

            return self::FAILURE;
        }

        if ($tenantId !== 'swecom' && ! $this->option('force')) {
            $this->error("swecom-specific cleanup. Refusing tenant \"{$tenantId}\" without --force.");

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        $cutoff = Carbon::createFromFormat('Y-m-d H:i:s', $before.'-01 00:00:00');

        tenancy()->initialize($tenant);

        try {
            $base = function () {
                $q = Payment::query();
                $q->where(function ($inner) {
                    foreach (self::BOGUS_PATTERNS as $pattern) {
                        $inner->orWhere('processed_period', 'like', $pattern);
                    }
                });

                return $q;
            };

            $total = $base()->count();

            $this->newLine();
            $this->line("<info>Bogus far-future processed_period cleanup</info>  tenant={$tenantId}  cutoff={$before}");
            $this->line($apply ? '<comment>MODE: APPLY</comment>' : 'MODE: dry run (pass --apply to write)');
            $this->newLine();

            if ($total === 0) {
                $this->info('No payments carry a 2031-*/2033-*/2034-* processed_period — nothing to do.');

                return self::SUCCESS;
            }

            $toRealMonth = (clone $base())->where('created_at', '<', $cutoff)->count();
            $toNull = $total - $toRealMonth;

            $byMonth = (clone $base())
                ->selectRaw("to_char(created_at, 'YYYY-MM') as ym, count(*) as c")
                ->groupBy('ym')
                ->orderBy('ym')
                ->get();

            $this->table(
                ['created month', 'payments', 'becomes'],
                $byMonth->map(fn ($r): array => [
                    $r->ym,
                    $r->c,
                    $r->ym < $before ? "processed_period = {$r->ym}" : 'processed_period = NULL',
                ])->all(),
            );
            $this->line("  <info>{$total}</info> payments carry a bogus period: {$toRealMonth} re-stamped to their real month, {$toNull} reset to NULL.");
            $this->newLine();

            if (! $apply) {
                $this->info('Dry run complete. Re-run with --apply to write. Changes no manuscript figure.');

                return self::SUCCESS;
            }

            $reStamped = 0;
            $nulled = 0;

            DB::transaction(function () use ($base, $cutoff, &$reStamped, &$nulled): void {
                $base()->orderBy('id')->chunkById(200, function ($payments) use ($cutoff, &$reStamped, &$nulled): void {
                    foreach ($payments as $payment) {
                        if ($payment->created_at->lt($cutoff)) {
                            $payment->update([
                                'processed_period' => $payment->created_at->format('Y-m'),
                                'processed_at' => $payment->created_at,
                            ]);
                            $reStamped++;
                        } else {
                            $payment->update(['processed_period' => null, 'processed_at' => null]);
                            $nulled++;
                        }
                    }
                });
            });

            $this->info("Applied. {$reStamped} re-stamped to their real month, {$nulled} reset to NULL. Each wrote an audit_logs row.");

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
