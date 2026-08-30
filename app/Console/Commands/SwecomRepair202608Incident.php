<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\Tenant;
use App\Models\Zone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, guarded, idempotent repair for the four pieces of test / incident
 * pollution left in the real `swecom` tenant schema. Dry run by default —
 * pass `--apply` to write. Wrapped in a DB transaction; a second run reports
 * "nothing to do".
 *
 * This command SUPERSEDES the loose scratchpad script
 * `…/scratchpad/cleanup_swecom_test_pollution.php` — that file is no longer
 * needed. Safe to delete this command (and
 * `ArrearsFixBaselineCreditCorruption`) once the owner has run it against
 * `swecom`.
 *
 * ---------------------------------------------------------------------------
 * WHAT HAPPENED (see `.claude/skills/cncms-context/references/arrears-adjustment.md`
 * §14 "Addendum, 2026-08-30" and the manuscript monthly-cycle notes,
 * `project_manuscript_monthly_cycle.md`):
 *
 *  1. Six faker-named test-fixture customers (ids ~10643-10648: Clotilde
 *     Harris, Annetta Wyman, Nadia Shields, Aylin Greenholt, Jayda Grant,
 *     Russel Ward) were left SOFT-DELETED in `swecom` by a test run before
 *     the SoftDeletes-cleanup fix landed. `swecom` has ZERO legitimately
 *     archived customers — the archive feature shipped 2026-08-29 and nobody
 *     has used it — so every `deleted_at IS NOT NULL` customer here is debris.
 *     Their child rows (`payments`, `payment_verifications`, `manuscripts`,
 *     `messages`, `arrears_adjustments`) go too, in FK-safe order, then the
 *     customer via `forceDelete()`.
 *
 *  2. Zones 11715 / 11716 were created only for those test customers and are
 *     orphaned once the customers are gone.
 *
 *  3. ~892 bogus `manuscripts` rows (plus a few `command_runs`) for the
 *     fictional periods `2031-*`, `2033-*`, `2034-*`. Those periods are ONLY
 *     ever used by tests (CustomerReconnectArrearsPaymentTest,
 *     CustomerManuscriptRecalculationServiceTest,
 *     LiveManuscriptRecalculationAndBatchConsistencyTest,
 *     CommandRunCancelUnblocksDispatchTest) — never real billing data. A
 *     `manuscript:calculate --tenant=swecom <fictional-period>` run against
 *     the whole tenant wrote a row for every real customer; only the test's
 *     own fixture customer was cleaned up.
 *
 *  4. MA TE (customer 24) and FON CHRISTINA (customer 39) have corrupted
 *     `2026-08` baseline rows — a bogus `credit` of 40,000 / 32,500 written
 *     when an approved arrears adjustment triggered a from-scratch recalc of
 *     an imported-baseline period (arrears-adjustment.md §14). The correct
 *     figures for both are `bill 2500, total_arrears 2500, credit 0,
 *     total_bill 5000`. This phase is NOT reimplemented here — it delegates
 *     to `arrears:fix-baseline-credit-corruption`
 *     (App\Console\Commands\ArrearsFixBaselineCreditCorruption), which owns
 *     that logic (well-guarded, idempotent, audited).
 *
 * ---------------------------------------------------------------------------
 * SAFETY GUARDS (phase 1 aborts the whole command, no writes, if any trip):
 *   - more than `--max-customers` (default 15) trashed customers present;
 *   - any trashed customer whose `deleted_at` date is not `--archived-on`
 *     (default 2026-08-29 — the known incident timestamp);
 *   - any trashed customer whose name matches a live (non-trashed) customer
 *     — that smells like a real archive, not test debris.
 * Every customer / ledger row removal goes through the Eloquent model so
 * App\Traits\Auditable records it. The bulk fictional-period deletes are
 * pure garbage rows — a query-builder delete() is used, and the counts are
 * printed.
 */
class SwecomRepair202608Incident extends Command
{
    protected $signature = 'swecom:repair-2026-08-incident
        {--tenant=swecom : Tenant slug/id to repair}
        {--apply : Write the repairs (default is a dry run printing the full plan)}
        {--force : Allow running against a tenant other than swecom}
        {--skip-customers : Skip phase 1 (trashed test customers) + phase 2 (orphan zones)}
        {--skip-manuscripts : Skip phase 3 (fictional-period manuscript / command_run rows)}
        {--skip-baseline : Skip phase 4 (MA TE / FON CHRISTINA 2026-08 baseline-credit fix)}
        {--max-customers=15 : Safety cap — abort phase 1 if more trashed customers than this are present}
        {--archived-on=2026-08-29 : Safety guard — every trashed customer\'s deleted_at date MUST equal this}';

    protected $description = 'One-off: repair the four pieces of test/incident pollution in the real swecom tenant (trashed test customers, orphan zones, fictional-period manuscripts, MA TE/FON CHRISTINA baseline credit)';

    /**
     * SQL LIKE patterns for the fictional periods only tests ever use.
     *
     * @var list<string>
     */
    private const FICTIONAL_PERIOD_PATTERNS = ['2031-%', '2033-%', '2034-%'];

    public function handle(): int
    {
        $tenantId = (string) $this->option('tenant');
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $maxCustomers = (int) $this->option('max-customers');
        $archivedOn = (string) $this->option('archived-on');

        if ($tenantId !== 'swecom' && ! $force) {
            $this->error("This is a swecom-specific repair. Refusing tenant \"{$tenantId}\" without --force.");

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant \"{$tenantId}\" not found.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<info>swecom 2026-08 incident repair</info>  tenant={$tenantId}");
        $this->line($apply ? '<comment>MODE: APPLY</comment>' : 'MODE: dry run (pass --apply to write)');
        $this->newLine();

        tenancy()->initialize($tenant);

        $didSomething = false;

        try {
            // ---- Phases 1 + 2: trashed test customers & orphan zones ----
            if ($this->option('skip-customers')) {
                $this->line('Phase 1/2 (trashed customers + orphan zones): <comment>skipped</comment>');
            } else {
                $plan = $this->planCustomers($archivedOn);
                $this->printCustomerPlan($plan);

                if ($plan['violations'] !== []) {
                    $this->newLine();
                    $this->error('Phase 1 safety guard tripped — this does NOT look like test-fixture debris. No changes made:');
                    foreach ($plan['violations'] as $v) {
                        $this->line("  - {$v}");
                    }

                    return self::FAILURE;
                }

                if (count($plan['customers']) > $maxCustomers) {
                    $this->newLine();
                    $this->error(sprintf(
                        'Phase 1 safety cap: %d trashed customers present, limit is %d (--max-customers). '
                        .'This is too many to be test debris — aborting, no changes made.',
                        count($plan['customers']),
                        $maxCustomers,
                    ));

                    return self::FAILURE;
                }

                if ($plan['customers'] === []) {
                    $this->line('Phase 1/2: nothing to do.');
                } else {
                    $didSomething = true;

                    if ($apply) {
                        DB::transaction(function () use ($plan): void {
                            $this->applyCustomers($plan);
                        });
                        $this->info('Phase 1/2 applied.');
                    }
                }
            }

            $this->newLine();

            // ---- Phase 3: fictional-period manuscript / command_run rows ----
            if ($this->option('skip-manuscripts')) {
                $this->line('Phase 3 (fictional-period rows): <comment>skipped</comment>');
            } else {
                $ms = Manuscript::query()->where(fn ($q) => $this->wherePatterns($q))->count();
                $cr = DB::connection('tenant')->table('command_runs')->where(fn ($q) => $this->wherePatterns($q))->count();

                $this->line(sprintf(
                    'Phase 3: %d manuscript rows and %d command_runs rows for periods %s',
                    $ms,
                    $cr,
                    implode(' / ', self::FICTIONAL_PERIOD_PATTERNS),
                ));

                if ($ms === 0 && $cr === 0) {
                    $this->line('Phase 3: nothing to do.');
                } else {
                    $didSomething = true;

                    if ($apply) {
                        DB::transaction(function () use (&$ms, &$cr): void {
                            $ms = Manuscript::query()->where(fn ($q) => $this->wherePatterns($q))->delete();
                            $cr = DB::connection('tenant')->table('command_runs')->where(fn ($q) => $this->wherePatterns($q))->delete();
                        });
                        $this->info("Phase 3 applied: deleted {$ms} manuscripts, {$cr} command_runs.");
                    }
                }
            }
        } finally {
            tenancy()->end();
        }

        $this->newLine();

        // ---- Phase 4: baseline-credit fix — delegated, never reimplemented ----
        if ($this->option('skip-baseline')) {
            $this->line('Phase 4 (MA TE / FON CHRISTINA baseline credit): <comment>skipped</comment>');
        } else {
            $this->line('Phase 4: delegating to arrears:fix-baseline-credit-corruption ...');

            $args = ['--tenant' => $tenantId];
            if ($tenantId !== 'swecom') {
                $args['--force'] = true;
            }
            if ($apply) {
                $args['--apply'] = true;
            }

            $baselineExit = $this->call('arrears:fix-baseline-credit-corruption', $args);

            if ($baselineExit !== self::SUCCESS) {
                $this->error('Phase 4 (arrears:fix-baseline-credit-corruption) failed — see its output above.');

                return self::FAILURE;
            }
        }

        $this->newLine();

        if (! $didSomething && ! $apply) {
            $this->info('Dry run complete. (Phases 1-3 found nothing; phase 4 status is above.) Re-run with --apply to write.');
        } elseif (! $apply) {
            $this->info('Dry run complete. Re-run with --apply to write.');
        } else {
            $this->info('Repair applied. Re-run to confirm it now reports nothing to do.');
        }

        return self::SUCCESS;
    }

    /**
     * Build the phase 1/2 plan without touching anything.
     *
     * @return array{customers: list<Customer>, zones: list<array{id: int, name: string|null, orphaned: bool, reason: string}>, violations: list<string>, counts: array<string, int>}
     */
    private function planCustomers(string $archivedOn): array
    {
        /** @var list<Customer> $trashed */
        $trashed = Customer::withTrashed()->whereNotNull('deleted_at')->orderBy('id')->get()->all();

        $violations = [];

        $liveNames = Customer::query()
            ->pluck('name')
            ->map(fn (string $n): string => mb_strtolower(trim($n)))
            ->flip();

        foreach ($trashed as $c) {
            $deletedDate = optional($c->deleted_at)->toDateString();
            if ($deletedDate !== $archivedOn) {
                $violations[] = "customer #{$c->id} ({$c->name}) deleted_at date is "
                    .var_export($deletedDate, true)." — expected {$archivedOn}.";
            }

            if ($liveNames->has(mb_strtolower(trim($c->name)))) {
                $violations[] = "trashed customer #{$c->id} name \"{$c->name}\" also exists as a LIVE customer — "
                    .'looks like a real archive, not test debris.';
            }
        }

        $trashedIds = array_map(fn (Customer $c): int => $c->id, $trashed);

        $paymentIds = $trashedIds === []
            ? []
            : Payment::query()->whereIn('customer_id', $trashedIds)->pluck('id')->all();

        $counts = [
            'customers' => count($trashedIds),
            'payments' => count($paymentIds),
            'payment_verifications' => $paymentIds === [] ? 0 : PaymentVerification::query()->whereIn('payment_id', $paymentIds)->count(),
            'manuscripts' => $trashedIds === [] ? 0 : Manuscript::query()->whereIn('customer_id', $trashedIds)->count(),
            'messages' => $trashedIds === [] ? 0 : Message::query()->whereIn('customer_id', $trashedIds)->count(),
            'arrears_adjustments' => $trashedIds === [] ? 0 : ArrearsAdjustment::query()->whereIn('customer_id', $trashedIds)->count(),
        ];

        // A zone is orphaned once ONLY these trashed customers reference it
        // and no agent does.
        $zones = [];
        $zoneIds = array_values(array_unique(array_filter(array_map(fn (Customer $c) => $c->zone_id, $trashed))));
        foreach ($zoneIds as $zid) {
            $otherCustomers = Customer::withTrashed()
                ->where('zone_id', $zid)
                ->whereNotIn('id', $trashedIds ?: [0])
                ->exists();
            $agentUse = Agent::query()->where('zone_id', $zid)->exists();

            $zones[] = [
                'id' => $zid,
                'name' => Zone::query()->whereKey($zid)->value('name'),
                'orphaned' => ! $otherCustomers && ! $agentUse,
                'reason' => $otherCustomers ? 'still has other customers' : ($agentUse ? 'referenced by an agent' : 'orphaned'),
            ];
        }

        return [
            'customers' => $trashed,
            'zones' => $zones,
            'violations' => $violations,
            'counts' => $counts,
        ];
    }

    /**
     * @param  array{customers: list<Customer>, zones: list<array{id: int, name: string|null, orphaned: bool, reason: string}>, counts: array<string, int>}  $plan
     */
    private function printCustomerPlan(array $plan): void
    {
        $this->line('<info>Phase 1: trashed test-fixture customers</info>');

        if ($plan['customers'] === []) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'name', 'zone_id', 'deleted_at'],
                array_map(fn (Customer $c): array => [
                    $c->id,
                    $c->name,
                    $c->zone_id,
                    (string) $c->deleted_at,
                ], $plan['customers']),
            );

            $this->line('  child rows that go with them:');
            foreach ($plan['counts'] as $table => $n) {
                if ($table === 'customers') {
                    continue;
                }
                $this->line(sprintf('    %-22s %d', $table, $n));
            }
        }

        $this->newLine();
        $this->line('<info>Phase 2: zones referenced by those customers</info>');
        foreach ($plan['zones'] as $z) {
            $this->line(sprintf(
                '  zone %s (%s) — %s%s',
                $z['id'],
                $z['name'] ?? '?',
                $z['orphaned'] ? 'WILL DELETE' : 'KEEP',
                $z['orphaned'] ? '' : " ({$z['reason']})",
            ));
        }
    }

    /**
     * @param  array{customers: list<Customer>, zones: list<array{id: int, name: string|null, orphaned: bool, reason: string}>}  $plan
     */
    private function applyCustomers(array $plan): void
    {
        foreach ($plan['customers'] as $customer) {
            $paymentIds = Payment::query()->where('customer_id', $customer->id)->pluck('id')->all();

            if ($paymentIds !== []) {
                PaymentVerification::query()->whereIn('payment_id', $paymentIds)->get()->each->delete();
            }
            ArrearsAdjustment::query()->where('customer_id', $customer->id)->get()->each->delete();
            Message::query()->where('customer_id', $customer->id)->get()->each->delete();
            Manuscript::query()->where('customer_id', $customer->id)->get()->each->delete();
            Payment::query()->where('customer_id', $customer->id)->get()->each->delete();

            $customer->forceDelete();
            $this->line("  purged customer #{$customer->id} ({$customer->name}).");
        }

        foreach ($plan['zones'] as $z) {
            if (! $z['orphaned']) {
                $this->line("  kept zone {$z['id']} ({$z['reason']}).");

                continue;
            }
            Zone::query()->whereKey($z['id'])->first()?->delete();
            $this->line("  deleted orphan zone {$z['id']} ({$z['name']}).");
        }
    }

    /**
     * Apply the OR-of-LIKE fictional-period predicate to a query builder.
     */
    private function wherePatterns(mixed $q): void
    {
        foreach (self::FICTIONAL_PERIOD_PATTERNS as $i => $pattern) {
            if ($i === 0) {
                $q->where('period', 'like', $pattern);

                continue;
            }

            $q->orWhere('period', 'like', $pattern);
        }
    }
}
