<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\PaymentVerificationFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * App\Console\Commands\SwecomRepair202608Incident
 * (`swecom:repair-2026-08-incident`).
 *
 * Uses Tests\Feature\Concerns\UsesDisposableTenant for the same reason
 * CommandRunRollbackTest does: this exercises a command that commits real
 * deletes/updates (and its phase 3 needs the `manuscripts.command_run_id`
 * column that only a freshly-migrated schema has), so it needs a throwaway
 * tenant schema it can freely write to and then drop — never `swecom`.
 *
 * All four corruption shapes are seeded into the disposable tenant and the
 * command is asserted to: write nothing on a dry run; on `--apply` purge the
 * trashed customer + every child row + the now-orphan zone, delete exactly
 * the fictional-period rows while leaving real periods alone, and restore the
 * MA TE / FON CHRISTINA baseline (via the delegated
 * `arrears:fix-baseline-credit-corruption`); be a no-op on a second `--apply`;
 * and trip the phase-1 safety cap at 20 trashed customers.
 */
class SwecomRepair202608IncidentTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    /**
     * Central `users` rows this test committed (arrears_adjustments.requested_by
     * has a real FK to public.users) — dropping the tenant schema does not
     * remove them, so they are cleaned up explicitly, mirroring
     * CommandRunRollbackTest::tearDown().
     *
     * @var list<int>
     */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('swrp');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        if ($this->createdUserIds !== []) {
            User::query()->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    private function withinTenancy(callable $callback): void
    {
        tenancy()->initialize($this->tenant);

        try {
            $callback();
        } finally {
            tenancy()->end();
        }
    }

    private function db()
    {
        return DB::connection('tenant');
    }

    /**
     * @return array{trashed_id: int, trashed_zone_id: int, adjustment_id: int, real_manuscript_id: int, real_command_run_id: int}
     */
    private function seedAllFourShapes(): array
    {
        $ref = [];

        $requestedBy = User::factory()->create()->id;
        $this->createdUserIds[] = $requestedBy;

        $this->withinTenancy(function () use (&$ref, $requestedBy): void {
            // ---- Shape 1: a soft-deleted test-fixture customer with a
            //      payment (+ verification), a manuscript, a message and an
            //      arrears_adjustment child, alone in a throwaway zone. ----
            $trashZone = ZoneFactory::new()->create();
            $trashed = CustomerFactory::new()->create([
                'zone_id' => $trashZone->id,
                'name' => 'Clotilde Testfixture',
                'status' => 'active',
            ]);
            $payment = PaymentFactory::new()->create(['customer_id' => $trashed->id]);
            PaymentVerificationFactory::new()->create(['payment_id' => $payment->id]);
            ManuscriptFactory::new()->forPeriod('2026-08')->create(['customer_id' => $trashed->id]);
            $adjustment = ArrearsAdjustmentFactory::new()->requestedBy($requestedBy)->forPeriod('2026-08')
                ->create(['customer_id' => $trashed->id]);
            $this->db()->table('messages')->insert([
                'customer_id' => $trashed->id,
                'content' => 'fixture message',
                'type' => 'sms',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $trashed->delete();
            // Stamp the exact incident timestamp so the --archived-on guard
            // is satisfied.
            $this->db()->table('customers')->where('id', $trashed->id)
                ->update(['deleted_at' => '2026-08-29 22:04:26']);

            $ref['trashed_id'] = $trashed->id;
            $ref['trashed_zone_id'] = $trashZone->id;
            $ref['adjustment_id'] = $adjustment->id;

            // ---- Shape 3: fictional-period manuscript rows + a matching
            //      command_runs row, plus REAL-period rows that must survive. ----
            $realZone = ZoneFactory::new()->create();
            foreach (['2031-01', '2031-02', '2033-06', '2034-03'] as $period) {
                $c = CustomerFactory::new()->create(['zone_id' => $realZone->id]);
                ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $c->id]);
            }
            $this->db()->table('command_runs')->insert([
                ['command' => 'manuscript:calculate', 'period' => '2031-01', 'ran_at' => now(), 'metadata' => '{}', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
                ['command' => 'manuscript:calculate', 'period' => '2034-03', 'ran_at' => now(), 'metadata' => '{}', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $realCustomer = CustomerFactory::new()->create(['zone_id' => $realZone->id]);
            $realManuscript = ManuscriptFactory::new()->forPeriod('2026-08')->create(['customer_id' => $realCustomer->id]);
            $ref['real_manuscript_id'] = $realManuscript->id;
            $this->db()->table('command_runs')->insert([
                'command' => 'manuscript:calculate', 'period' => '2026-08', 'ran_at' => now(),
                'metadata' => '{}', 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ref['real_command_run_id'] = (int) $this->db()->table('command_runs')->where('period', '2026-08')->value('id');

            // ---- Shape 4: MA TE (24) / FON CHRISTINA (39) corrupted 2026-08
            //      baseline rows (command_run_id NULL, bogus credit). Ids are
            //      forced so the delegated arrears:fix-baseline-credit-
            //      corruption command (keyed to ids 24/39) can target them. ----
            $baselineZone = ZoneFactory::new()->create();
            foreach ([24 => ['MA TE', '40000'], 39 => ['FON CHRISTINA', '32500']] as $id => [$name, $credit]) {
                $this->db()->table('customers')->insert([
                    'id' => $id,
                    'zone_id' => $baselineZone->id,
                    'name' => $name,
                    'location' => 'x',
                    'bill' => 2500,
                    'others' => 0,
                    'level' => 'normal',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->db()->table('manuscripts')->insert([
                    'customer_id' => $id,
                    'bill' => 2500,
                    'total_arrears' => 0,
                    'credit' => $credit,
                    'total_bill' => 0,
                    'period' => '2026-08',
                    'command_run_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $ref;
    }

    public function test_dry_run_reports_the_plan_but_writes_nothing(): void
    {
        $ref = $this->seedAllFourShapes();

        $this->artisan('swecom:repair-2026-08-incident', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
        ])->assertOk();

        $this->withinTenancy(function () use ($ref): void {
            $this->assertNotNull(Customer::withTrashed()->find($ref['trashed_id']), 'dry run must not purge the trashed customer.');
            $this->assertNotNull(Zone::query()->find($ref['trashed_zone_id']), 'dry run must not delete the orphan zone.');
            $this->assertSame(4, Manuscript::query()->where(fn ($q) => $q
                ->where('period', 'like', '2031-%')->orWhere('period', 'like', '2033-%')->orWhere('period', 'like', '2034-%'))->count());
            $this->assertSame('40000.00', $this->db()->table('manuscripts')->where('customer_id', 24)->where('period', '2026-08')->value('credit'));
        });
    }

    public function test_apply_repairs_all_four_and_a_second_run_is_a_no_op(): void
    {
        $ref = $this->seedAllFourShapes();

        $this->artisan('swecom:repair-2026-08-incident', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
            '--apply' => true,
        ])->assertOk();

        $this->withinTenancy(function () use ($ref): void {
            // Phase 1: trashed customer + every child row gone.
            $this->assertNull(Customer::withTrashed()->find($ref['trashed_id']), 'trashed customer must be force-deleted.');
            $this->assertSame(0, Payment::query()->where('customer_id', $ref['trashed_id'])->count());
            $this->assertSame(0, PaymentVerification::query()->count());
            $this->assertSame(0, Manuscript::query()->where('customer_id', $ref['trashed_id'])->count());
            $this->assertSame(0, ArrearsAdjustment::query()->where('customer_id', $ref['trashed_id'])->count());
            $this->assertSame(0, $this->db()->table('messages')->where('customer_id', $ref['trashed_id'])->count());

            // Phase 2: orphan zone gone.
            $this->assertNull(Zone::query()->find($ref['trashed_zone_id']), 'orphan zone must be deleted.');

            // Phase 3: exactly the fictional-period rows gone, real ones kept.
            $this->assertSame(0, Manuscript::query()->where(fn ($q) => $q
                ->where('period', 'like', '2031-%')->orWhere('period', 'like', '2033-%')->orWhere('period', 'like', '2034-%'))->count());
            $this->assertSame(0, $this->db()->table('command_runs')->whereIn('period', ['2031-01', '2034-03'])->count());
            $this->assertNotNull(Manuscript::query()->find($ref['real_manuscript_id']), 'the real 2026-08 manuscript must survive.');
            $this->assertNotNull($this->db()->table('command_runs')->where('id', $ref['real_command_run_id'])->first(), 'the real 2026-08 command_run must survive.');

            // Phase 4: baseline restored via the delegated command.
            foreach ([24, 39] as $id) {
                $row = $this->db()->table('manuscripts')->where('customer_id', $id)->where('period', '2026-08')->first();
                $this->assertSame('2500.00', $row->bill);
                $this->assertSame('2500.00', $row->total_arrears);
                $this->assertSame('0.00', $row->credit);
                $this->assertSame('5000.00', $row->total_bill);
            }

            // The customer/ledger removals were audited (Eloquent path).
            $this->assertTrue(
                $this->db()->table('audit_logs')->where('table_name', 'customers')->where('action', 'delete')->exists(),
                'the forceDelete of the trashed customer must have written an audit_logs row.',
            );
        });

        // Second --apply run: nothing left to do.
        $this->artisan('swecom:repair-2026-08-incident', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
            '--apply' => true,
        ])->expectsOutputToContain('nothing to do')->assertOk();

        $this->withinTenancy(function (): void {
            $this->assertSame(0, Customer::withTrashed()->whereNotNull('deleted_at')->count());
            $this->assertSame('0.00', $this->db()->table('manuscripts')->where('customer_id', 24)->where('period', '2026-08')->value('credit'));
        });
    }

    public function test_phase_1_safety_cap_trips_at_twenty_trashed_customers(): void
    {
        $this->withinTenancy(function (): void {
            $zone = ZoneFactory::new()->create();
            for ($i = 0; $i < 20; $i++) {
                $c = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => "Cap Fixture {$i}"]);
                $c->delete();
                $this->db()->table('customers')->where('id', $c->id)->update(['deleted_at' => '2026-08-29 22:04:26']);
            }
            // A fictional-period row that phase 3 would delete — it must NOT,
            // because the cap aborts the whole command before phase 3.
            $live = CustomerFactory::new()->create(['zone_id' => $zone->id]);
            ManuscriptFactory::new()->forPeriod('2031-09')->create(['customer_id' => $live->id]);
        });

        $this->artisan('swecom:repair-2026-08-incident', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
            '--apply' => true,
            '--skip-manuscripts' => true,
            '--skip-baseline' => true,
        ])->assertFailed();

        $this->withinTenancy(function (): void {
            $this->assertSame(20, Customer::withTrashed()->whereNotNull('deleted_at')->count(), 'the cap must abort with no deletions.');
            $this->assertSame(1, Manuscript::query()->where('period', 'like', '2031-%')->count());
        });
    }

    public function test_phase_1_guard_trips_when_a_trashed_name_matches_a_live_customer(): void
    {
        $trashedId = null;

        $this->withinTenancy(function () use (&$trashedId): void {
            $zone = ZoneFactory::new()->create();
            CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Real Person']);
            $trashed = CustomerFactory::new()->create(['zone_id' => $zone->id, 'name' => 'Real Person']);
            $trashed->delete();
            $this->db()->table('customers')->where('id', $trashed->id)->update(['deleted_at' => '2026-08-29 22:04:26']);
            $trashedId = $trashed->id;
        });

        $this->artisan('swecom:repair-2026-08-incident', [
            '--tenant' => $this->tenant->id,
            '--force' => true,
            '--apply' => true,
            '--skip-manuscripts' => true,
            '--skip-baseline' => true,
        ])->assertFailed();

        $this->withinTenancy(function () use ($trashedId): void {
            $this->assertNotNull(Customer::withTrashed()->find($trashedId), 'a name collision with a live customer must block the purge.');
        });
    }
}
