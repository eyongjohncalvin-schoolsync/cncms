<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
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

    public function test_me_returns_the_authenticated_agents_own_record(): void
    {
        $zone = ZoneFactory::new()->create();
        $token = $this->tokenForRole('agent');

        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => $user->id]);

        // A second, unrelated agent must never be returned by this
        // endpoint — this is the "own profile only" scoping this test
        // exists to pin down.
        AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/agents/me');

        $response->assertOk()
            ->assertJsonPath('data.uuid', $agent->uuid)
            ->assertJsonPath('data.zone_uuid', $zone->uuid)
            ->assertJsonPath('data.salary', $agent->salary);
    }

    public function test_me_returns_404_when_the_caller_has_no_agent_record(): void
    {
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/agents/me');

        $response->assertStatus(404);
    }

    /**
     * A field agent is a user (owner, 2026-10-28): accounts are created on
     * the Users page and zones given on the Agents page — the API only reads.
     */
    public function test_the_api_no_longer_creates_edits_or_deletes_agents(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'status' => 'active']);
        $token = $this->tokenForRole('super');
        $api = $this->withHeader('Authorization', "Bearer {$token}");

        $api->postJson('/api/v1/agents', ['zone_uuid' => $zone->uuid, 'name' => 'NOT HERE'])->assertStatus(405);
        $api->patchJson("/api/v1/agents/{$agent->uuid}", ['status' => 'inactive'])->assertStatus(405);
        $api->deleteJson("/api/v1/agents/{$agent->uuid}")->assertStatus(405);

        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'status' => 'active']);
        $this->assertDatabaseMissing('agents', ['name' => 'NOT HERE']);
    }
}
