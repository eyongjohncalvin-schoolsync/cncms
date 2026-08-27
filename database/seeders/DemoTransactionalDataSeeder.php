<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a RECONSTRUCTED / SYNTHETIC demo dataset into the `swecom` tenant
 * schema: ~549 customers, a multi-month payment history, a handful of extra
 * demo users + agents, then runs the REAL `manuscript:calculate` Artisan
 * command across the historical periods that payment history spans so
 * `manuscripts` and `command_runs` are populated by the actual production
 * billing engine (App\Services\ManuscriptCalculator) rather than hand-rolled
 * math.
 *
 * WHY THIS EXISTS: there is no accessible export of the real legacy MariaDB
 * production database in this environment. The row counts, zone
 * concentrations, bill-tier mix, status split, and payment volume below are
 * calibrated to match the statistical profile documented in
 * .ai/skills/cncms/cncms-context/references/database-schema.md and
 * business-rules.md, so the app has believable, internally-consistent data
 * to demo and develop against. THESE ARE NOT REAL SWECOM CUSTOMER RECORDS —
 * nobody should mistake this seeded data for genuine migrated history.
 *
 * Scope: transactional/demo data only. Reference data (zones, expense
 * categories, the company record) is owned exclusively by
 * TenantDatabaseSeeder and is only ever READ here (never inserted/modified).
 *
 * Design note on payment history vs. the billing engine: ManuscriptCalculator
 * treats every payment with `processed_at IS NULL AND verification_status =
 * 'verified'` as unprocessed income for whatever period `manuscript:calculate`
 * is currently run for — it does not filter by the payment's `created_at`.
 * So payments cannot simply be bulk-inserted across all 15 simulated months
 * before running the command once; that would let the very first calculate
 * run scoop up an entire year-plus of future payments as "this month's"
 * income. Instead, this seeder inserts one month's worth of payments, runs
 * `manuscript:calculate` for that period (which stamps `processed_at` on
 * everything it consumes), then moves to the next month — mirroring how the
 * real system would have accrued this data live, month by month.
 *
 * Idempotency: bails out early if Customer::count() > 100, so re-running
 * this against an already-seeded tenant is a safe no-op rather than a
 * second ~549-row duplication.
 *
 * Invocation:
 *   php artisan demo:seed
 * (a thin wrapper around this class — see app/Console/Commands/SeedDemoData.php
 * for why a dedicated command is used instead of `tenants:seed` directly:
 * this seeder needs to end/re-initialize tenancy around each
 * `manuscript:calculate` sub-invocation, since that command brackets its own
 * work in tenancy()->initialize()/end()).
 */
class DemoTransactionalDataSeeder extends Seeder
{
    private const TOTAL_CUSTOMERS = 549;

    /**
     * Exact per-zone customer counts for the top 6 zones, per
     * database-schema.md. Keys must match `zones.name` exactly as seeded by
     * TenantDatabaseSeeder.
     *
     * @var array<string, int>
     */
    private const TOP_ZONE_TARGETS = [
        'THR01 (3/CORNERS)' => 71,
        'AR01(JUNCTION)' => 55,
        'M R01 (DODO)' => 43,
        'LR R01 (LADUMA ROAD)' => 35,
        'AR02(HANS)' => 34,
        'M R04 (JERUME)' => 33,
    ];

    public function run(): void
    {
        if (Customer::count() > 100) {
            $this->log('DemoTransactionalDataSeeder: customers already present (>100 rows) — skipping to avoid duplicating demo data.');

            return;
        }

        $tenant = tenant() ?? Tenant::query()->firstOrFail();

        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        $this->log('DemoTransactionalDataSeeder: seeding demo users...');
        $users = $this->seedUsers($tenant);

        $this->log('DemoTransactionalDataSeeder: seeding '.self::TOTAL_CUSTOMERS.' customers + payment history...');
        $paymentsByPeriod = $this->seedCustomersAndBuildPaymentPlan();

        $this->log('DemoTransactionalDataSeeder: seeding field agents...');
        $this->seedAgents($users);

        $this->log('DemoTransactionalDataSeeder: replaying payment history + running manuscript:calculate per period...');
        $this->replayPaymentsAndCalculateManuscripts($tenant, $paymentsByPeriod);

        $this->log('DemoTransactionalDataSeeder: done.');
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /**
     * @return array<string, User>
     */
    private function seedUsers(Tenant $tenant): array
    {
        $specs = [
            'admin' => ['name' => 'Ngwa Patience Ebot', 'username' => 'pngwa', 'email' => 'patience@shalomtech.dev', 'role' => 'admin'],
            'manager' => ['name' => 'Besong Terence Ako', 'username' => 'tbesong', 'email' => 'terence@shalomtech.dev', 'role' => 'manager'],
            'agent1' => ['name' => 'Ashu Divine Ebot', 'username' => 'dashu', 'email' => 'divine@shalomtech.dev', 'role' => 'agent'],
            'agent2' => ['name' => 'Fon Blessing Ayuk', 'username' => 'bfon', 'email' => 'blessing@shalomtech.dev', 'role' => 'agent'],
            'worker' => ['name' => 'Ekema Godlove Nkemayang', 'username' => 'gekema', 'email' => 'godlove@shalomtech.dev', 'role' => 'worker'],
        ];

        $users = [];

        foreach ($specs as $key => $spec) {
            $user = User::firstOrCreate(
                ['email' => $spec['email']],
                [
                    'name' => $spec['name'],
                    'username' => $spec['username'],
                    'status' => 'active',
                    'password' => 'password',
                    'email_verified_at' => now(),
                ]
            );

            TenantUser::firstOrCreate(
                ['user_id' => $user->id, 'tenant_id' => $tenant->getKey()],
                ['role' => $spec['role']]
            );

            $users[$key] = $user;
        }

        return $users;
    }

    // ------------------------------------------------------------------
    // Customers + payment plan
    // ------------------------------------------------------------------

    /**
     * Creates all customers now (so `manuscript:calculate` has a stable
     * customer set for every period) and computes, but does NOT yet insert,
     * every customer's simulated payment history. Payments are handed back
     * grouped by period so the caller can insert + calculate one month at a
     * time (see the class docblock for why that ordering matters).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function seedCustomersAndBuildPaymentPlan(): array
    {
        $periods = $this->periods();
        $zonePool = $this->buildZonePool();
        $billPool = $this->buildBillPool();
        $statusPool = $this->buildStatusPool();
        $levelPool = $this->buildLevelPool();
        $othersPool = $this->buildOthersPool();

        $paymentsByPeriod = array_fill_keys($periods, []);

        DB::transaction(function () use ($periods, $zonePool, $billPool, $statusPool, $levelPool, $othersPool, &$paymentsByPeriod): void {
            for ($i = 0; $i < self::TOTAL_CUSTOMERS; $i++) {
                $customer = CustomerFactory::new([
                    'zone_id' => $zonePool[$i],
                    'bill' => $billPool[$i],
                    'others' => $othersPool[$i],
                    'level' => $levelPool[$i],
                    'status' => $statusPool[$i],
                ])->create();

                // Backdate created_at to a plausible "customer since" date for
                // display purposes. This is cosmetic only — the billing
                // engine (ManuscriptCalculator) never reads customer.created_at,
                // so it has no effect on arrears/credit math. Done via a raw
                // query (not ->save()) to avoid firing a spurious 'updated'
                // audit-log entry for what is really still creation.
                $since = $this->randomCustomerSince($periods);
                DB::table('customers')->where('id', $customer->id)->update([
                    'created_at' => $since,
                    'updated_at' => $since,
                ]);

                $this->planPaymentsForCustomer($customer, $periods, $paymentsByPeriod);
            }
        });

        return $paymentsByPeriod;
    }

    /**
     * @param  list<string>  $periods
     * @param  array<string, list<array<string, mixed>>>  $paymentsByPeriod
     */
    private function planPaymentsForCustomer(Customer $customer, array $periods, array &$paymentsByPeriod): void
    {
        $periodCount = count($periods);
        $slotTarget = min($this->randomPaymentCount(), $periodCount);

        $slots = collect(range(0, $periodCount - 1))
            ->shuffle()
            ->take($slotTarget)
            ->sort()
            ->values();

        $bill = (float) $customer->bill;
        $frozenUntil = -1;

        foreach ($slots as $index) {
            if ($index <= $frozenUntil) {
                // Swallowed by an earlier multi-month/yearly prepayment that
                // is still active during this slot — a real customer
                // wouldn't pay again while already covered.
                continue;
            }

            $period = $periods[$index];
            [$year, $month] = array_map('intval', explode('-', $period));
            $createdAt = Carbon::create($year, $month, random_int(1, 25), random_int(8, 17), random_int(0, 59));

            $roll = random_int(1, 100);

            if ($roll <= 74) {
                $row = [
                    'amount' => $this->monthlyAmountVariation($bill),
                    'frequency' => 'monthly',
                    'months' => null,
                    'expiration_date' => null,
                ];
            } elseif ($roll <= 92) {
                $months = random_int(2, 4);
                $row = [
                    'amount' => round($bill * $months, 2),
                    'frequency' => 'months',
                    'months' => $months,
                    'expiration_date' => $createdAt->copy()->addMonths($months)->toDateString(),
                ];
                $frozenUntil = $index + $months - 1;
            } else {
                $row = [
                    'amount' => round($bill * 12, 2),
                    'frequency' => 'yearly',
                    'months' => 12,
                    'expiration_date' => $createdAt->copy()->addMonths(12)->toDateString(),
                ];
                $frozenUntil = $index + 11;
            }

            $row['customer_id'] = $customer->id;
            $row['credit'] = 0;
            $row['verification_status'] = 'verified';
            $row['processed_at'] = null;
            $row['recorded_offline'] = false;
            $row['recorded_by_device'] = null;
            $row['created_at'] = $createdAt;
            $row['updated_at'] = $createdAt;

            $paymentsByPeriod[$period][] = $row;
        }
    }

    private function randomPaymentCount(): int
    {
        // Weighted toward ~4-5 (mean ≈4.7 before prepay-swallowing trims it
        // down further), with a long tail so a few customers pay almost
        // every month and a few barely pay at all — matching the arrears
        // narrative in business-rules.md.
        return fake()->randomElement([
            1, 2, 2, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 6, 6, 6, 7, 7, 8, 9,
        ]);
    }

    private function monthlyAmountVariation(float $bill): float
    {
        $roll = random_int(1, 100);

        if ($roll <= 80) {
            return $bill;
        }

        if ($roll <= 92) {
            // Partial payment — arrears will persist (business-rules.md #5).
            $partial = round($bill * (random_int(50, 90) / 100), -2);

            return $partial > 0 ? $partial : $bill;
        }

        // Slight overpayment — becomes credit on the next manuscript run.
        return $bill + random_int(1, 4) * 500;
    }

    private function randomCustomerSince(array $periods): Carbon
    {
        // min() of two uniform draws biases toward the front of the range —
        // most subscribers have been around a while, only a few are recent.
        $maxIndex = count($periods) - 1;
        $index = min(random_int(0, $maxIndex), random_int(0, $maxIndex));
        [$year, $month] = array_map('intval', explode('-', $periods[$index]));

        return Carbon::create($year, $month, random_int(1, 28), random_int(7, 19), random_int(0, 59));
    }

    /**
     * @return list<string> e.g. ['2025-05', '2025-06', ..., '2026-07']
     */
    private function periods(): array
    {
        $periods = [];
        $cursor = Carbon::create(2025, 5, 1);
        $end = Carbon::create(2026, 7, 1);

        while ($cursor->lte($end)) {
            $periods[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $periods;
    }

    // ------------------------------------------------------------------
    // Distribution pools
    // ------------------------------------------------------------------

    /**
     * @return list<int> zone_id for each of the 549 customer slots
     */
    private function buildZonePool(): array
    {
        $allZones = Zone::all();
        $pool = [];

        foreach (self::TOP_ZONE_TARGETS as $name => $count) {
            $zone = $allZones->firstWhere('name', $name);

            if (! $zone) {
                throw new \RuntimeException("Expected seeded zone \"{$name}\" not found — run TenantDatabaseSeeder first.");
            }

            for ($i = 0; $i < $count; $i++) {
                $pool[] = $zone->id;
            }
        }

        $remainingZones = $allZones->reject(
            fn (Zone $zone): bool => array_key_exists($zone->name, self::TOP_ZONE_TARGETS)
        )->shuffle()->values();

        $remainingTotal = self::TOTAL_CUSTOMERS - array_sum(self::TOP_ZONE_TARGETS);
        $counts = $this->decliningDistribution($remainingTotal, $remainingZones->count());

        foreach ($remainingZones as $i => $zone) {
            for ($j = 0; $j < $counts[$i]; $j++) {
                $pool[] = $zone->id;
            }
        }

        shuffle($pool);

        return $pool;
    }

    /**
     * Declining (triangular-weighted) split of $total across $buckets
     * buckets, summing to exactly $total.
     *
     * @return list<int>
     */
    private function decliningDistribution(int $total, int $buckets): array
    {
        $weights = range($buckets, 1);
        $weightSum = array_sum($weights);

        $counts = array_map(
            fn (int $w): int => (int) round($total * $w / $weightSum),
            $weights
        );

        $drift = $total - array_sum($counts);
        $counts[0] += $drift;

        return $counts;
    }

    /**
     * @return list<float>
     */
    private function buildBillPool(): array
    {
        $pool = array_fill(0, 26, 2000.0);
        $pool = array_merge($pool, array_fill(0, 274, 2500.0));
        $pool = array_merge($pool, array_fill(0, 134, 3000.0));

        // Remaining 115 customers across VIP/Operator-style higher tiers,
        // declining from 4000 (most common) to 12500 (rarest).
        foreach (['4000' => 40, '4500' => 10, '5000' => 30, '6000' => 10, '7500' => 15, '10000' => 7, '12500' => 3] as $amount => $count) {
            $pool = array_merge($pool, array_fill(0, $count, (float) $amount));
        }

        shuffle($pool);

        return $pool;
    }

    /**
     * @return list<string>
     */
    private function buildStatusPool(): array
    {
        $pool = array_merge(
            array_fill(0, 428, 'active'),
            array_fill(0, 121, 'disconnected'),
        );

        shuffle($pool);

        return $pool;
    }

    /**
     * @return list<string>
     */
    private function buildLevelPool(): array
    {
        $pool = array_merge(
            array_fill(0, 534, 'normal'),
            array_fill(0, 10, 'Vip'),
            array_fill(0, 5, 'Operator'),
        );

        shuffle($pool);

        return $pool;
    }

    /**
     * @return list<float>
     */
    private function buildOthersPool(): array
    {
        $pool = [];

        for ($i = 0; $i < self::TOTAL_CUSTOMERS; $i++) {
            $pool[] = random_int(1, 100) <= 15
                ? (float) (random_int(1, 10) * 500)
                : 0.0;
        }

        return $pool;
    }

    // ------------------------------------------------------------------
    // Agents
    // ------------------------------------------------------------------

    /**
     * @param  array<string, User>  $users
     */
    private function seedAgents(array $users): void
    {
        if (Agent::count() > 0) {
            return;
        }

        $zones = Zone::all();
        $topZones = $zones->whereIn('name', array_keys(self::TOP_ZONE_TARGETS))->values();
        $agentZones = $topZones->count() >= 5 ? $topZones : $zones->shuffle()->take(5)->values();

        $specs = [
            ['name' => 'Ashu Divine Ebot', 'user_key' => 'agent1'],
            ['name' => 'Fon Blessing Ayuk', 'user_key' => 'agent2'],
            ['name' => 'Tabi Roland Mbua', 'user_key' => null],
            ['name' => 'Mbeng Comfort Ayamba', 'user_key' => null],
            ['name' => 'Njie Success Etonde', 'user_key' => null],
        ];

        foreach ($specs as $i => $spec) {
            $zone = $agentZones[$i % $agentZones->count()];

            AgentFactory::new([
                'zone_id' => $zone->id,
                'user_id' => $spec['user_key'] ? $users[$spec['user_key']]->id : null,
                'name' => $spec['name'],
            ])->create();
        }
    }

    // ------------------------------------------------------------------
    // Payment replay + manuscript engine
    // ------------------------------------------------------------------

    /**
     * @param  array<string, list<array<string, mixed>>>  $paymentsByPeriod
     */
    private function replayPaymentsAndCalculateManuscripts(Tenant $tenant, array $paymentsByPeriod): void
    {
        foreach ($paymentsByPeriod as $period => $rows) {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            if ($rows !== []) {
                DB::transaction(function () use ($rows): void {
                    foreach ($rows as $row) {
                        PaymentFactory::new($row)->create();
                    }
                });
            }

            // manuscript:calculate brackets its own work in
            // tenancy()->initialize()/end() — end tenancy here first so that
            // bracketing doesn't fight an already-open context, then
            // re-initialize for the next iteration (or for whatever the
            // caller does after this method returns).
            tenancy()->end();

            Artisan::call('manuscript:calculate', [
                'period' => $period,
                '--tenant' => $tenant->getKey(),
            ]);

            $this->log('  manuscript:calculate ['.$period.']: '.trim(Artisan::output()));
        }

        tenancy()->initialize($tenant);
    }

    private function log(string $message): void
    {
        if (isset($this->command)) {
            $this->command->getOutput()->writeln($message);

            return;
        }

        fwrite(STDOUT, $message.PHP_EOL);
    }
}
