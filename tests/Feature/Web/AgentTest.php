<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\AgentFactory;
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
