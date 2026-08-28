<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off migration-repair: the v1 register never persisted `payment_expiration`
 * on manuscripts, so `ManuscriptImportAugust` seeded every 2026-08 baseline row
 * with NULL — including customers who hold an active forward prepayment
 * (`payments.frequency` in months/yearly, `expiration_date` still in the future).
 *
 * Without this, the first v2 `manuscript:calculate` (period 2026-09) reads a NULL
 * `previousManuscript->payment_expiration` (ManuscriptCalculator.php:154) and — if
 * the establishing payment has also been marked `processed_period` by the owner's
 * separate payment reconciliation — bills those customers a full month despite
 * valid prepayment.
 *
 * This command writes ONLY `manuscripts.payment_expiration` on the affected
 * baseline rows (from the customer's latest verified future `expiration_date`),
 * asserts every money column is byte-identical before and after, and optionally
 * inserts a synthetic `published` `command_runs` row for the baseline period so
 * `ManuscriptRerunGuard` refuses an accidental `manuscript:calculate 2026-08`
 * that would otherwise overwrite the whole seeded baseline from `customers.others`.
 *
 * It does NOT touch payments, does NOT run any calculation, and leaves
 * already-lapsed prepayments alone (those customers are correctly billed again
 * from the next period). Safe to delete once run. Dry-run by default.
 */
class ManuscriptReconcilePrepaidBaseline extends Command
{
    protected $signature = 'manuscript:reconcile-prepaid-baseline
        {--tenant=swecom : Tenant slug/id}
        {--baseline-period=2026-08 : The seeded baseline period}
        {--apply : Write the changes (default is a dry run)}
        {--no-guard-run : Skip inserting the synthetic baseline-period command_runs guard row}';

    protected $description = 'One-off: backfill payment_expiration on the seeded baseline for still-prepaid customers';

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $baseline = (string) $this->option('baseline-period');
        $apply = (bool) $this->option('apply');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $baseline)) {
            $this->error("Invalid baseline-period \"{$baseline}\".");

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        $nextPeriod = Carbon::createFromFormat('Y-m', $baseline)->addMonthNoOverflow()->format('Y-m');
        $nextPeriodStart = Carbon::createFromFormat('Y-m', $nextPeriod)->startOfMonth()->toDateString();

        tenancy()->initialize($tenant);

        try {
            if (Manuscript::query()->where('period', $nextPeriod)->exists()) {
                $this->error("Manuscript rows already exist for {$nextPeriod} — the calculation has run. "
                    ."This baseline repair is only valid BEFORE the first post-seed run; use a per-customer "
                    ."recalculation or an arrears adjustment instead.");

                return self::FAILURE;
            }

            $baselineCount = Manuscript::query()->where('period', $baseline)->count();
            if ($baselineCount === 0) {
                $this->error("No manuscript rows for baseline period {$baseline}. Run manuscript:import-august first.");

                return self::FAILURE;
            }

            // Customers with a verified prepayment whose window still covers
            // any part of $nextPeriod (or later). `expiration_date` is a date;
            // ">= first day of next period" is the period-relative test — a
            // window ending 2026-08-29 does NOT cover September and is left to
            // be billed normally.
            $windows = Payment::query()
                ->where('verification_status', 'verified')
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '>=', $nextPeriodStart)
                ->get()
                ->groupBy('customer_id')
                ->map(fn ($rows) => $rows->max('expiration_date'));

            $lapsed = Payment::query()
                ->where('verification_status', 'verified')
                ->whereNotNull('expiration_date')
                ->where('expiration_date', '<', $nextPeriodStart)
                ->whereIn('frequency', ['months', 'yearly'])
                ->where(fn ($q) => $q->whereNull('processed_period'))
                ->get();

            if ($windows->isEmpty()) {
                $this->info('No still-active prepaid windows to reconcile.');
            }

            $customers = Customer::query()->whereIn('id', $windows->keys())->get()->keyBy('id');
            $rows = Manuscript::query()
                ->where('period', $baseline)
                ->whereIn('customer_id', $windows->keys())
                ->get()
                ->keyBy('customer_id');

            $plan = [];
            foreach ($windows as $customerId => $expiration) {
                $m = $rows->get($customerId);
                $c = $customers->get($customerId);
                if (! $m) {
                    $this->error("Customer {$customerId} has a prepaid window but no {$baseline} manuscript row — aborting.");

                    return self::FAILURE;
                }
                $expDate = Carbon::parse($expiration)->toDateString();
                $plan[] = [
                    'customer_id' => $customerId,
                    'name' => $c?->name ?? "#{$customerId}",
                    'status' => $c?->status ?? '?',
                    'current' => $m->payment_expiration?->toDateString() ?? '—',
                    'proposed' => $expDate,
                    'bill' => (string) $m->bill,
                    'arrears' => (string) $m->total_arrears,
                    'credit' => (string) $m->credit,
                    'total_bill' => (string) $m->total_bill,
                    'model' => $m,
                    'date' => $expDate,
                ];
            }

            usort($plan, fn ($a, $b) => strcmp($a['proposed'], $b['proposed']));

            $this->newLine();
            $this->line("<info>Prepaid baseline reconciliation</info>  tenant={$tenantId}  baseline={$baseline}  next-run={$nextPeriod}");
            $this->line($apply ? '<comment>MODE: APPLY</comment>' : 'MODE: dry run (pass --apply to write)');
            $this->newLine();

            $this->table(
                ['cus', 'name', 'status', 'pay_exp now', '→ proposed', 'bill', 'arrears', 'credit', 'total_bill'],
                array_map(fn ($p) => [
                    $p['customer_id'], mb_substr($p['name'], 0, 26), $p['status'],
                    $p['current'], $p['proposed'], $p['bill'], $p['arrears'], $p['credit'], $p['total_bill'],
                ], $plan),
            );

            $this->line(sprintf('Affected baseline rows: %d of %d', count($plan), $baselineCount));

            if ($lapsed->isNotEmpty()) {
                $this->newLine();
                $this->warn('Already-lapsed multi-month payments (NOT touched here — the window is over, the customer');
                $this->warn('is correctly billed from '.$nextPeriod.'). These still have processed_period=NULL, so the');
                $this->warn('owner\'s payment reconciliation must stamp them or '.$nextPeriod.' re-reads their full amount as income:');
                foreach ($lapsed as $p) {
                    $this->line(sprintf('  payment #%d  cus %d  %s  %s FCFA  exp %s',
                        $p->id, $p->customer_id, $p->frequency, $p->amount, $p->expiration_date?->toDateString()));
                }
            }

            if (! $apply) {
                $this->newLine();
                $this->info('Dry run complete. Re-run with --apply to write.');

                return self::SUCCESS;
            }

            // Money-invariance guard: capture the exact sums before.
            $before = $this->moneySums($baseline);

            DB::transaction(function () use ($plan, $baseline, $tenantId): void {
                foreach ($plan as $p) {
                    /** @var Manuscript $m */
                    $m = $p['model'];
                    if ($m->payment_expiration?->toDateString() === $p['date']) {
                        continue;
                    }
                    $m->update(['payment_expiration' => $p['date']]);
                }

                if (! $this->option('no-guard-run')
                    && ! CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $baseline)->exists()) {
                    CommandRun::query()->create([
                        'command' => 'manuscript:calculate',
                        'period' => $baseline,
                        'ran_at' => Carbon::now(),
                        'status' => 'published',
                        'published_at' => Carbon::now(),
                        'metadata' => [
                            'tenant' => $tenantId,
                            'trigger' => 'baseline-import',
                            'synthetic' => true,
                            'note' => 'Represents the v1 register import (ManuscriptImportAugust), not a real '
                                .'manuscript:calculate run. Exists so ManuscriptRerunGuard refuses an accidental '
                                .'recompute of this seeded baseline. command_run_id is deliberately NOT set on the '
                                .'baseline manuscript rows, so a rollback of this row can never delete them.',
                        ],
                    ]);
                    $this->line("Inserted synthetic guard command_runs row for {$baseline}.");
                }
            });

            $after = $this->moneySums($baseline);
            if ($before !== $after) {
                $this->error('MONEY-COLUMN INVARIANCE VIOLATED — this should be impossible. Sums before/after:');
                $this->line(json_encode(['before' => $before, 'after' => $after]));

                return self::FAILURE;
            }

            $written = Manuscript::query()->where('period', $baseline)->whereNotNull('payment_expiration')->count();
            $this->newLine();
            $this->info("Applied. {$baseline} rows with payment_expiration set: {$written}. Money columns unchanged.");

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }

    /** @return array<string, string> */
    private function moneySums(string $period): array
    {
        $q = Manuscript::query()->where('period', $period);

        return [
            'bill' => (string) $q->clone()->sum('bill'),
            'total_arrears' => (string) $q->clone()->sum('total_arrears'),
            'credit' => (string) $q->clone()->sum('credit'),
            'total_bill' => (string) $q->clone()->sum('total_bill'),
            'count' => (string) $q->clone()->count(),
        ];
    }
}
