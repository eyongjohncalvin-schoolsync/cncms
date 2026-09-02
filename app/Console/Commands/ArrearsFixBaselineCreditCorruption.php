<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off data-repair for the 2026-08 `swecom` baseline-credit corruption
 * (see `.claude/skills/cncms-context/references/arrears-adjustment.md`'s
 * 2026-08-30 addendum).
 *
 * What happened: the owner imported a fixed August 2026 manuscript baseline
 * (`manuscripts` rows for `period = '2026-08'`, `command_run_id = NULL`,
 * figures verbatim from the v1 register). Then two arrears adjustments were
 * approved — #414 MA TE (customer 24, −500) and #518 FON CHRISTINA
 * (customer 39, −2500). `ArrearsAdjustmentService::applyLedgerEffect()`
 * responded by running `CustomerManuscriptRecalculationService::recalculateOne()`
 * for period 2026-08, which recomputed the imported baseline row FROM SCRATCH
 * — re-counting every one of the customer's historical v1 payments as fresh
 * August income. `net` went hugely negative and a bogus `credit` was written
 * (40,000 for MA TE, 32,500 for FON CHRISTINA). The correct baseline `credit`
 * for both is 0.
 *
 * This command restores the correct 2026-08 figures for exactly those two
 * rows, factoring in the two adjustments that WERE legitimately approved:
 *
 *   - MA TE (24):        bill 2500, total_arrears 2500, credit 0, total_bill 5000
 *                        (CSV baseline arrears 3000 − the approved −500 of #414)
 *   - FON CHRISTINA (39): bill 2500, total_arrears 2500, credit 0, total_bill 5000
 *                        (CSV baseline arrears 5000 − the approved −2500 of #518)
 *
 * Each write goes through the Eloquent model so App\Traits\Auditable records
 * an `audit_logs` row with old/new values. Idempotent (a row already at its
 * target figures is skipped; if both are, the command reports "nothing to
 * do"). Guarded: refuses to run against any tenant other than `swecom`
 * unless `--force` is passed. Dry run by default — pass `--apply` to write.
 *
 * Safe to delete once run.
 */
class ArrearsFixBaselineCreditCorruption extends Command
{
    protected $signature = 'arrears:fix-baseline-credit-corruption
        {--tenant=swecom : Tenant slug/id}
        {--period=2026-08 : The corrupted baseline period}
        {--apply : Write the corrections (default is a dry run)}
        {--force : Allow running against a tenant other than swecom}';

    protected $description = 'One-off: restore the 2026-08 baseline manuscript figures for MA TE / FON CHRISTINA corrupted by a from-scratch recalc';

    /**
     * customer_id => [expected name fragment, target figures]. The name
     * fragment is a defensive cross-check only — the fix is keyed by id.
     *
     * @var array<int, array{name: string, bill: string, total_arrears: string, credit: string, total_bill: string}>
     */
    private const TARGETS = [
        24 => ['name' => 'MA TE', 'bill' => '2500.00', 'total_arrears' => '2500.00', 'credit' => '0.00', 'total_bill' => '5000.00'],
        39 => ['name' => 'FON CHRISTINA', 'bill' => '2500.00', 'total_arrears' => '2500.00', 'credit' => '0.00', 'total_bill' => '5000.00'],
    ];

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $period = (string) $this->option('period');
        $apply = (bool) $this->option('apply');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error("Invalid period \"{$period}\".");

            return self::FAILURE;
        }

        if ($tenantId !== 'swecom' && ! $this->option('force')) {
            $this->error("This is a swecom-specific repair. Refusing tenant \"{$tenantId}\" without --force.");

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $plan = [];

            foreach (self::TARGETS as $customerId => $target) {
                $customer = Customer::query()->find($customerId);
                if (! $customer) {
                    $this->error("Customer #{$customerId} not found in tenant {$tenantId} — aborting, no changes made.");

                    return self::FAILURE;
                }

                if (mb_stripos($customer->name, $target['name']) === false) {
                    $this->error("Customer #{$customerId} is \"{$customer->name}\", expected to contain \"{$target['name']}\" — "
                        .'aborting, no changes made (wrong tenant, or the ids have shifted).');

                    return self::FAILURE;
                }

                $manuscript = Manuscript::query()
                    ->where('customer_id', $customerId)
                    ->where('period', $period)
                    ->first();

                if (! $manuscript) {
                    $this->error("No {$period} manuscript row for customer #{$customerId} ({$customer->name}) — aborting.");

                    return self::FAILURE;
                }

                if ($manuscript->command_run_id !== null) {
                    $this->error("The {$period} row for customer #{$customerId} is linked to command_run "
                        ."#{$manuscript->command_run_id} — it is NOT an imported baseline row. Aborting; investigate first.");

                    return self::FAILURE;
                }

                $matches = bccomp((string) $manuscript->bill, $target['bill'], 2) === 0
                    && bccomp((string) $manuscript->total_arrears, $target['total_arrears'], 2) === 0
                    && bccomp((string) $manuscript->credit, $target['credit'], 2) === 0
                    && bccomp((string) $manuscript->total_bill, $target['total_bill'], 2) === 0;

                $plan[] = [
                    'customer_id' => $customerId,
                    'name' => $customer->name,
                    'model' => $manuscript,
                    'target' => $target,
                    'matches' => $matches,
                    'before' => [
                        'bill' => (string) $manuscript->bill,
                        'total_arrears' => (string) $manuscript->total_arrears,
                        'credit' => (string) $manuscript->credit,
                        'total_bill' => (string) $manuscript->total_bill,
                    ],
                ];
            }

            $this->newLine();
            $this->line("<info>Baseline-credit corruption repair</info>  tenant={$tenantId}  period={$period}");
            $this->line($apply ? '<comment>MODE: APPLY</comment>' : 'MODE: dry run (pass --apply to write)');
            $this->newLine();

            $this->table(
                ['cus', 'name', 'bill', 'arrears now→target', 'credit now→target', 'total_bill now→target', 'state'],
                array_map(fn (array $p): array => [
                    $p['customer_id'],
                    mb_substr($p['name'], 0, 22),
                    $p['before']['bill'].'→'.$p['target']['bill'],
                    $p['before']['total_arrears'].'→'.$p['target']['total_arrears'],
                    $p['before']['credit'].'→'.$p['target']['credit'],
                    $p['before']['total_bill'].'→'.$p['target']['total_bill'],
                    $p['matches'] ? 'already correct' : 'WILL FIX',
                ], $plan),
            );

            $toFix = array_values(array_filter($plan, fn (array $p): bool => ! $p['matches']));

            if ($toFix === []) {
                $this->info('Both rows already match their target figures — nothing to do.');

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->newLine();
                $this->info('Dry run complete. Re-run with --apply to write.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($toFix): void {
                foreach ($toFix as $p) {
                    /** @var Manuscript $m */
                    $m = $p['model'];
                    $m->update([
                        'bill' => $p['target']['bill'],
                        'total_arrears' => $p['target']['total_arrears'],
                        'credit' => $p['target']['credit'],
                        'total_bill' => $p['target']['total_bill'],
                    ]);
                    $this->line("Fixed customer #{$p['customer_id']} ({$p['name']}).");
                }
            });

            $this->newLine();
            $this->info('Applied. '.count($toFix).' row(s) corrected. Each wrote an audit_logs row via the Auditable trait.');

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
