<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via
 * ZoneFactory/CustomerFactory; none of the real seeded rows are touched.
 */
class CustomerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_index_lists_customers_filtered_by_zone_and_status(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'active']);
        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'disconnected']);
        CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'status' => 'active']);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers?zone_uuid={$zoneA->uuid}&status=active");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($zoneA->uuid, $data[0]['zone_uuid']);
        $this->assertSame('active', $data[0]['status']);
    }

    public function test_show_returns_a_customer_with_zone(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customer->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $customer->uuid)
            ->assertJsonPath('data.zone_uuid', $zone->uuid);
    }

    public function test_manager_can_create_a_customer(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'zone_uuid' => $zone->uuid,
                'name' => 'JANE DOE',
                'bill' => 2500,
                'level' => 'normal',
                'status' => 'active',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'JANE DOE')
            ->assertJsonPath('data.zone_uuid', $zone->uuid);

        $this->assertDatabaseHas('customers', ['name' => 'JANE DOE', 'zone_id' => $zone->id]);
    }

    public function test_admin_can_update_a_customer(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $token = $this->tokenForRole('admin');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/customers/{$customer->uuid}", [
                'status' => 'disconnected',
            ]);

        $response->assertOk()->assertJsonPath('data.status', 'disconnected');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'disconnected']);
    }

    public function test_super_can_delete_a_customer(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/customers/{$customer->uuid}");

        $response->assertOk()->assertJson(['message' => 'Customer deleted']);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_store_fails_validation_for_invalid_level(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'zone_uuid' => $zone->uuid,
                'name' => 'JOHN DOE',
                'bill' => 2500,
                'level' => 'not-a-real-level',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['level']);
    }

    public function test_agent_cannot_create_a_customer(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'zone_uuid' => $zone->uuid,
                'name' => 'SHOULD NOT EXIST',
                'bill' => 2500,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('customers', ['name' => 'SHOULD NOT EXIST']);
    }

    public function test_agent_can_still_view_customers(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customer->uuid}");

        $response->assertOk()->assertJsonPath('data.uuid', $customer->uuid);
    }
}
