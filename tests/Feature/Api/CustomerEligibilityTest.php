<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /api/v1/customers/eligible-for-disconnection —
 * App\Http\Controllers\Api\CustomerController::eligibleForDisconnection(),
 * the mobile JSON counterpart to
 * tests/Feature/Web/DisconnectionEligibilityTest.php's `?eligible=1` web
 * tab. Both hit the exact same App\Services\CustomerEligibilityService, so
 * this suite deliberately doesn't re-prove the threshold/deadline math
 * (already covered there) — it focuses on the JSON contract and, most
 * importantly, that an agent's zone scoping is enforced server-side and
 * can't be bypassed via the zone_uuid query string.
 */
class CustomerEligibilityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        // Past the 5th-of-the-month payment deadline, deterministic.
        Carbon::setTestNow('2026-08-24 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_manager_sees_eligible_customers_across_zones(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $inA = $this->activeCustomerWithArrears($zoneA->id, '2500.00', '8000.00');
        $inB = $this->activeCustomerWithArrears($zoneB->id, '3000.00', '9500.00');

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers/eligible-for-disconnection');

        $response->assertOk();
        $uuids = array_column($response->json('data'), 'uuid');
        $this->assertContains($inA->uuid, $uuids);
        $this->assertContains($inB->uuid, $uuids);
    }

    public function test_manager_can_filter_by_zone_uuid(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();
        $inA = $this->activeCustomerWithArrears($zoneA->id, '2500.00', '8000.00');
        $this->activeCustomerWithArrears($zoneB->id, '3000.00', '9500.00');

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/eligible-for-disconnection?zone_uuid={$zoneA->uuid}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($inA->uuid, $data[0]['uuid']);
    }

    public function test_agent_only_sees_their_own_zones_eligible_customers(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $this->tokenForRole('agent');

        $ownZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $ownZone->id, 'user_id' => $user->id]);

        $inOwnZone = $this->activeCustomerWithArrears($ownZone->id, '2500.00', '8000.00');
        $this->activeCustomerWithArrears($otherZone->id, '2500.00', '8000.00');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers/eligible-for-disconnection');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($inOwnZone->uuid, $data[0]['uuid']);
    }

    /**
     * An agent cannot escape their zone scoping by tampering with the
     * zone_uuid query string — the controller ignores it entirely for the
     * `agent` role, mirroring eligibilityIndex()'s web behavior.
     */
    public function test_agent_cannot_view_another_zone_via_query_param(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $this->tokenForRole('agent');

        $ownZone = ZoneFactory::new()->create();
        $otherZone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $ownZone->id, 'user_id' => $user->id]);

        $this->activeCustomerWithArrears($otherZone->id, '2500.00', '8000.00');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/eligible-for-disconnection?zone_uuid={$otherZone->uuid}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_worker_gets_a_403(): void
    {
        $token = $this->tokenForRole('worker');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/customers/eligible-for-disconnection')
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/customers/eligible-for-disconnection')
            ->assertUnauthorized();
    }
}
