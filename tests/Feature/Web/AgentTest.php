<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) counterpart to tests/Feature/Api/AgentTest.php,
 * exercising App\Http\Controllers\AgentController instead of the API
 * controller. Reuses the real seeded owner (kelvin@shalomtech.dev), flipping
 * their tenant_users role per test, same pattern as DashboardTest.
 *
 * AgentFactory's default state links a brand-new UserFactory-created central
 * `users` row, which would trip the agents_user_id_foreign FK for the same
 * cross-connection-visibility reason documented in
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles — every
 * AgentFactory::new()->create() call below explicitly overrides `user_id` to
 * null.
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

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_index_renders_with_paginated_agents(): void
    {
        $zone = ZoneFactory::new()->create();
        AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $this->actingAsRole('manager');

        $response = $this->get('/agents');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Index')
                ->has('agents.data')
                ->has('agents.meta')
                ->has('zones'));
    }

    public function test_a_manager_can_create_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();

        $this->actingAsRole('manager');

        $response = $this->post('/agents', [
            'zone_uuid' => $zone->uuid,
            'name' => 'FIELD AGENT',
            'location' => 'Main Street',
            'phone' => '677000000',
            'salary' => 50000,
        ]);

        $response->assertRedirect(route('agents.index'));
        $this->assertDatabaseHas('agents', ['name' => 'FIELD AGENT', 'zone_id' => $zone->id]);
    }

    public function test_a_manager_can_edit_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'status' => 'active']);

        $this->actingAsRole('manager');

        $response = $this->patch("/agents/{$agent->uuid}", ['status' => 'inactive']);

        $response->assertRedirect(route('agents.index'));
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'status' => 'inactive']);
    }

    public function test_the_edit_page_renders_for_a_manager(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $this->actingAsRole('manager');

        $response = $this->get("/agents/{$agent->uuid}/edit");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Edit')
                ->where('agent.uuid', $agent->uuid)
                ->has('zones'));
    }

    public function test_a_manager_can_change_an_agents_zone_via_the_quick_action(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null, 'status' => 'active']);

        $this->actingAsRole('manager');

        // The quick action sends only zone_uuid — verifying the existing
        // FormRequest/DTO/Service chain treats that as a valid partial
        // update without requiring every other Agent field.
        $response = $this->patch("/agents/{$agent->uuid}", ['zone_uuid' => $newZone->uuid]);

        $response->assertRedirect(route('agents.index'));
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $newZone->id]);
    }

    public function test_the_index_page_reports_per_zone_customer_and_agent_counts(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'status' => 'active']);
        CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/agents');

        $response->assertOk();

        // Real tenant data means other zones already exist alongside the
        // ones this test just created, so locate ours by uuid rather than
        // assuming its position in the (name-ordered) zones array.
        $page = json_decode(json_encode($response->viewData('page')), true);
        $zoneProps = collect($page['props']['zones'])->firstWhere('uuid', $zone->uuid);

        $this->assertNotNull($zoneProps, 'The zone created for this test was not present in the zones prop.');
        $this->assertSame(1, $zoneProps['customer_count']);
        $this->assertSame(1, $zoneProps['agent_count']);
        $this->assertSame([$agent->name], $zoneProps['agent_names']);
    }

    public function test_an_agent_role_user_cannot_change_an_agents_zone(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null]);

        $this->actingAsRole('agent');

        $response = $this->patch("/agents/{$agent->uuid}", ['zone_uuid' => $newZone->uuid]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $oldZone->id]);
    }

    public function test_a_worker_role_user_cannot_change_an_agents_zone(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null]);

        $this->actingAsRole('worker');

        $response = $this->patch("/agents/{$agent->uuid}", ['zone_uuid' => $newZone->uuid]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $oldZone->id]);
    }

    public function test_an_agent_role_user_cannot_create_an_agent(): void
    {
        $zone = ZoneFactory::new()->create();

        $this->actingAsRole('agent');

        $response = $this->get('/agents/create');
        $response->assertStatus(403);

        $response = $this->post('/agents', [
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
