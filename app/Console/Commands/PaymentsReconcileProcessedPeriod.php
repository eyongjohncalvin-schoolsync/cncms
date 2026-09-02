<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off reconciliation for the v1 -> v2 payment import gap.
 *
 * v1's `manuscript:calculate` consumed each payment exactly once, in the
 * calendar month it was created (`whereYear/whereMonth(now())` on the
 * first-run query — see the legacy CalculateManuscript command). The
 * imported v2 `2026-08` manuscript baseline is v1's running balance AFTER
 * every prior month's payments was consumed that way.
 *
 * But the v2 import copied those payments across with `processed_period =
 * NULL`, and v2's eligibility predicate (Payment::scopeEligibleForPeriod)
 * is `verified AND (processed_period IS NULL OR = P)` — no calendar-month
 * filter. So the next `manuscript:calculate 2026-09` would re-consume every
 * one of those already-settled payments as September income, fabricating
 * huge credit for customers who are actually square (confirmed: MS
 * CHRISTIAN, arrears 0, 36,000 FCFA of pre-Aug payments -> would compute
 * credit 33,000).
 *
 * This command stamps every verified, still-NULL payment created BEFORE the
 * cutoff period with `processed_period` = its own creation month (and
 * `processed_at` = its own `created_at`, a display-only field). That
 * restores v1's "each payment counted once, in its month" behaviour:
 * afterwards the 2026-09 run sees only genuinely September-dated payments.
 *
 * Changes NO manuscript figure. Dry run by default; `--apply` to write.
 * Refuses any tenant other than the one named unless `--force`. Idempotent
 * (only touches `processed_period IS NULL` rows). Safe to delete once run.
 */
class PaymentsReconcileProcessedPeriod extends Command
{
    protected $signature = 'payments:reconcile-processed-period
        {--tenant=swecom : Tenant slug/id}
        {--before=2026-09 : Only payments created before this YYYY-MM period are stamped}
        {--apply : Write the changes (default is a dry run)}
        {--force : Allow running against a tenant other than the default}';

    protected $description = 'Backfill processed_period on pre-cutoff verified payments the v1->v2 import left NULL, so the next manuscript run cannot re-consume them';

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
            $this->error("This is a swecom-specific reconciliation. Refusing tenant \"{$tenantId}\" without --force.");

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
            $base = fn () => Payment::query()
                ->where('verification_status', 'verified')
                ->whereNull('processed_period')
                ->where('created_at', '<', $cutoff);

            $total = $base()->count();

            $this->newLine();
            $this->line("<info>Payment processed_period reconciliation</info>  tenant={$tenantId}  cutoff={$before}");
            $this->line($apply ? '<comment>MODE: APPLY</comment>' : 'MODE: dry run (pass --apply to write)');
            $this->newLine();

            if ($total === 0) {
                $this->info('No verified, unstamped payments created before the cutoff — nothing to do.');

                return self::SUCCESS;
            }

            $byMonth = $base()
                ->selectRaw("to_char(created_at, 'YYYY-MM') as ym, count(*) as c, coalesce(sum(amount), 0) as s")
                ->groupBy('ym')
                ->orderBy('ym')
                ->get();

            $this->table(
                ['created month -> processed_period', 'payments', 'total amount (FCFA)'],
                $byMonth->map(fn ($r): array => [$r->ym, $r->c, number_format((float) $r->s)])->all(),
            );
            $this->line("  <info>{$total}</info> payments, ".number_format((float) $base()->sum('amount')).' FCFA total — each stamped to its own creation month.');
            $this->line('  Payments created on/after '.$before.' are left untouched (the 2026-09 run consumes those).');
            $this->newLine();

            if (! $apply) {
                $this->info('Dry run complete. Re-run with --apply to write. Changes no manuscript figure.');

                return self::SUCCESS;
            }

            $stamped = 0;

            DB::transaction(function () use ($base, &$stamped): void {
                $base()->orderBy('id')->chunkById(200, function ($payments) use (&$stamped): void {
                    foreach ($payments as $payment) {
                        // Through the model so App\Traits\Auditable records the
                        // processed_period change on each row.
                        $payment->update([
                            'processed_period' => $payment->created_at->format('Y-m'),
                            'processed_at' => $payment->created_at,
                        ]);
                        $stamped++;
                    }
                });
            });

            $this->info("Applied. {$stamped} payments stamped. Each wrote an audit_logs row via the Auditable trait.");
            $this->line('The 2026-09 manuscript run will now consume only payments dated 2026-09 onward.');

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
