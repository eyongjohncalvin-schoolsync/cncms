<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The arrears-based "flagged for non-payment" tab on the Disconnections
 * board (?eligible=1) — App\Services\CustomerEligibilityService, reached via
 * App\Http\Controllers\DisconnectionsController::eligibilityIndex(). Same
 * tenant/transaction setup as tests/Feature/Web/DisconnectionsTest.php (the
 * plain status board this complements), and the same zone_uuid-scoping
 * trick tests/Feature/Web/CustomerTest.php's filter tests use
 * (`has('customers.data', 1)` against a freshly-created zone) to keep
 * assertions deterministic against the real seeded swecom tenant data
 * rather than assuming an empty table.
 */
class DisconnectionEligibilityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // Deterministic "past the 5th of the month" instant, so eligibility
        // isn't at the mercy of whatever day the suite happens to run on.
        Carbon::setTestNow('2026-08-24 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    private function activeCustomerWithArrears(int $zoneId, string $bill, string $arrears): Customer
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zoneId,
            'status' => 'active',
            'bill' => $bill,
        ]);

        ManuscriptFactory::new()->create([
            'customer_id' => $customer->id,
            'bill' => $bill,
            'total_arrears' => $arrears,
            'credit' => 0,
            'total_bill' => bcadd($bill, $arrears, 2),
            'period' => '2026-07',
        ]);

        return $customer;
    }

    public function test_customer_exactly_at_3x_bill_is_eligible(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $eligible = $this->activeCustomerWithArrears($zone->id, '2500.00', '7500.00');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disconnections/Index')
                ->where('filters.eligible', true)
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $eligible->uuid)
                ->where('customers.data.0.total_arrears', '7500.00'));
    }

    public function test_customer_just_under_3x_bill_is_not_eligible(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $this->activeCustomerWithArrears($zone->id, '2500.00', '7499.99');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disconnections/Index')
                ->has('customers.data', 0));
    }

    /**
     * Regression for the real-data bug where every active customer's
     * "latest" manuscript silently resolved to a stale period: manuscript
     * rows can be written out of calendar order (a backfill, a corrective
     * rerun, a batch job) so the row with the newest `created_at` is not
     * reliably the row for the newest `period`. Here the customer's
     * highest-period manuscript (2026-08, comfortably over 3x) is the
     * OLDER row by created_at, while a lower-arrears, sub-threshold
     * manuscript for an earlier period (2026-05) was written more
     * recently — exactly the pattern found in the real swecom tenant
     * data. Customer::latestManuscript() must resolve by `period`, not
     * `created_at`, or this customer wrongly disappears from the board.
     */
    public function test_eligibility_uses_the_manuscript_with_the_latest_period_not_the_latest_created_at(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'status' => 'active',
            'bill' => '2500.00',
        ]);

        // The true latest period: comfortably over 3x, written first
        // (older created_at) — mirrors a normal on-time monthly run.
        ManuscriptFactory::new()->create([
            'customer_id' => $customer->id,
            'bill' => '2500.00',
            'total_arrears' => '10000.00',
            'credit' => 0,
            'total_bill' => '12500.00',
            'period' => '2026-08',
            'created_at' => Carbon::parse('2026-08-06 09:00:00'),
            'updated_at' => Carbon::parse('2026-08-06 09:00:00'),
        ]);

        // A stale, earlier period, under threshold, but written AFTER the
        // real latest-period row (e.g. a backfill/rerun) — newest
        // created_at, oldest period.
        ManuscriptFactory::new()->create([
            'customer_id' => $customer->id,
            'bill' => '2500.00',
            'total_arrears' => '2500.00',
            'credit' => 0,
            'total_bill' => '5000.00',
            'period' => '2026-05',
            'created_at' => Carbon::parse('2026-08-20 09:00:00'),
            'updated_at' => Carbon::parse('2026-08-20 09:00:00'),
        ]);

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disconnections/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $customer->uuid)
                ->where('customers.data.0.total_arrears', '10000.00'));
    }

    public function test_eligibility_does_not_trigger_before_the_payment_deadline(): void
    {
        $this->actingAsRole('manager');
        Carbon::setTestNow('2026-08-03 09:00:00'); // the 3rd — before the 5th deadline

        $zone = ZoneFactory::new()->create();
        $this->activeCustomerWithArrears($zone->id, '2500.00', '10000.00');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disconnections/Index')
                ->has('customers.data', 0));
    }

    public function test_eligibility_triggers_once_past_the_payment_deadline(): void
    {
        $this->actingAsRole('manager');
        Carbon::setTestNow('2026-08-06 09:00:00'); // the 6th — just past the deadline

        $zone = ZoneFactory::new()->create();
        $eligible = $this->activeCustomerWithArrears($zone->id, '2500.00', '10000.00');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $eligible->uuid));
    }

    public function test_manager_can_filter_the_eligibility_board_by_zone(): void
    {
        $this->actingAsRole('manager');

        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $inZoneA = $this->activeCustomerWithArrears($zoneA->id, '2500.00', '8000.00');
        $this->activeCustomerWithArrears($zoneB->id, '3000.00', '9500.00');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$zoneA->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $inZoneA->uuid));
    }

    public function test_agent_only_sees_their_own_zones_eligible_customers(): void
    {
        $user = $this->actingAsRole('agent');

        $ownZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $ownZone->id, 'user_id' => $user->id]);

        $inOwnZone = $this->activeCustomerWithArrears($ownZone->id, '2500.00', '8000.00');
        $this->activeCustomerWithArrears($otherZone->id, '2500.00', '8000.00');

        $response = $this->get('/disconnections?eligible=1');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAgentScoped', true)
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $inOwnZone->uuid));
    }

    /**
     * An agent cannot escape their zone scoping by tampering with the
     * zone_uuid query string — DisconnectionsController::eligibilityIndex()
     * ignores it entirely for the `agent` role.
     */
    public function test_agent_cannot_view_another_zone_via_query_param(): void
    {
        $user = $this->actingAsRole('agent');

        $ownZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $ownZone->id, 'user_id' => $user->id]);

        $this->activeCustomerWithArrears($otherZone->id, '2500.00', '8000.00');

        $response = $this->get("/disconnections?eligible=1&zone_uuid={$otherZone->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    }

    public function test_agent_gets_a_403_viewing_the_plain_status_board(): void
    {
        $this->actingAsRole('agent');

        $this->get('/disconnections')->assertForbidden();
    }

    public function test_worker_gets_a_403_viewing_the_eligibility_board(): void
    {
        $this->actingAsRole('worker');

        $this->get('/disconnections?eligible=1')->assertForbidden();
    }

    public function test_manager_can_bulk_disconnect_a_customer_surfaced_by_the_eligibility_board(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $eligible = $this->activeCustomerWithArrears($zone->id, '2500.00', '9000.00');

        // Confirm the board actually surfaces this customer before acting on it.
        $this->get("/disconnections?eligible=1&zone_uuid={$zone->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.uuid', $eligible->uuid));

        $response = $this->post('/disconnections/bulk-disconnect', [
            'customer_uuids' => [$eligible->uuid],
            'note' => 'Automatic — arrears reached 3x monthly bill, past payment deadline.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', [
            'id' => $eligible->id,
            'status' => 'disconnected',
            'status_reason' => 'non_payment',
            'status_note' => 'Automatic — arrears reached 3x monthly bill, past payment deadline.',
        ]);
    }

    public function test_agent_gets_a_403_bulk_disconnecting_even_a_flagged_customer(): void
    {
        $user = $this->actingAsRole('agent');

        $ownZone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $ownZone->id, 'user_id' => $user->id]);

        $eligible = $this->activeCustomerWithArrears($ownZone->id, '2500.00', '9000.00');

        $this->post('/disconnections/bulk-disconnect', [
            'customer_uuids' => [$eligible->uuid],
        ])->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $eligible->id, 'status' => 'active']);
    }
}
