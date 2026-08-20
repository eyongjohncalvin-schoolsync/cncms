<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Factories\AgentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via
 * ZoneFactory/AgentFactory; none of the real seeded rows are touched.
 *
 * Every AgentFactory::new()->create() call below explicitly overrides
 * `user_id` to null: the factory's default state links a brand-new
 * UserFactory-created central `users` row, which — for the same
 * cross-connection-visibility reason documented in
 * InteractsWithTenantRoles — would trip the agents_user_id_foreign FK
 * (public.users is written on the `pgsql` connection, not yet committed
 * and therefore invisible to the `tenant` connection's FK check). None of
 * these tests exercise the agent-to-user link, so leaving it null sidesteps
 * the issue entirely.
 */
class AgentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_index_lists_agents_filtered_by_zone_and_status(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => null, 'status' => 'active']);
        AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => null, 'status' => 'inactive']);
        AgentFactory::new()->create(['zone_id' => $zoneB->id, 'user_id' => null, 'status' => 'active']);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/agents?zone_uuid={$zoneA->uuid}&status=active");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($zoneA->uuid, $data[0]['zone_uuid']);
    }

    public function test_show_returns_an_agent_with_zone(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/agents/{$agent->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $agent->uuid)
            ->assertJsonPath('data.zone_uuid', $zone->uuid);
    }

    public function test_manager_can_create_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/agents', [
                'zone_uuid' => $zone->uuid,
                'name' => 'FIELD AGENT',
                'location' => 'Main Street',
                'phone' => '677000000',
                'salary' => 50000,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'FIELD AGENT')
            ->assertJsonPath('data.zone_uuid', $zone->uuid);

        $this->assertDatabaseHas('agents', ['name' => 'FIELD AGENT', 'zone_id' => $zone->id]);
    }

    public function test_admin_can_update_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'status' => 'active']);

        $token = $this->tokenForRole('admin');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/agents/{$agent->uuid}", ['status' => 'inactive']);

        $response->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'status' => 'inactive']);
    }

    public function test_super_can_delete_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/agents/{$agent->uuid}");

        $response->assertOk()->assertJson(['message' => 'Agent deleted']);
        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }

    public function test_store_fails_validation_when_required_fields_are_missing(): void
    {
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/agents', [
                'name' => 'INCOMPLETE AGENT',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['zone_uuid', 'location', 'phone', 'salary']);
    }

    public function test_agent_role_cannot_create_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/agents', [
                'zone_uuid' => $zone->uuid,
                'name' => 'SHOULD NOT EXIST',
                'location' => 'Main Street',
                'phone' => '677000000',
                'salary' => 50000,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('agents', ['name' => 'SHOULD NOT EXIST']);
    }
}
