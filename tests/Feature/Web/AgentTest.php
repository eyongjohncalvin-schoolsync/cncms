<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Agent;
use App\Models\Scopes\BranchScope;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\ZoneAgent;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Field agents (owner, 2026-10-28): a field agent is a user. Their agent
 * record follows their account on the Users page (StaffAccountService); the
 * Agents page lists them with their zones, changes an agent's zone and gives
 * an old agent record without a login one.
 *
 * AgentFactory's default state links a brand-new central `users` row, which
 * would trip the agents_user_id_foreign FK across connections (see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles) — every
 * AgentFactory::new()->create() below sets `user_id` to null or a seeded user.
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

        $this->get('/agents')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Index')
                ->has('agents.data')
                ->has('agents.meta')
                ->has('zones'));
    }

    public function test_agents_are_no_longer_created_or_edited_on_the_agents_page(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);

        $this->actingAsRole('manager');

        $this->get('/agents/create')->assertNotFound();
        $this->get("/agents/{$agent->uuid}/edit")->assertNotFound();
        $this->post('/agents', ['name' => 'NOT HERE'])->assertStatus(405);
        $this->delete("/agents/{$agent->uuid}")->assertNotFound();
        $this->assertDatabaseHas('agents', ['id' => $agent->id]);
        $this->assertDatabaseMissing('agents', ['name' => 'NOT HERE']);
    }

    public function test_the_users_page_keeps_a_field_agents_record_in_step(): void
    {
        $patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $patience->forceFill(['status' => 'active', 'is_landlord' => false])->save();
        $membership = TenantUser::query()->where('user_id', $patience->id)->firstOrFail();
        $membership->update(['role' => 'worker', 'branch_id' => null]);
        Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $patience->id)->delete();

        $this->actingAsRole('super');

        // Becoming a field agent creates their record, named after them, with no zones yet.
        $this->patch("/users/{$membership->id}", ['role' => 'agent'])->assertSessionHasNoErrors();
        $agent = Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $patience->id)->sole();
        $this->assertSame(['active', $patience->name, null], [$agent->status, $agent->name, $agent->zone_id]);

        // Given a zone, then deactivated: the record retires and stops covering it.
        $zone = ZoneFactory::new()->create(['branch_id' => $agent->branch_id]);
        $this->patch("/agents/{$agent->uuid}/zone", ['zone_uuid' => $zone->uuid])->assertSessionHasNoErrors();
        $this->assertTrue(ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->exists());

        $this->post("/users/{$membership->id}/deactivate")->assertRedirect();
        $this->assertSame('inactive', $agent->fresh()->status);
        $this->assertFalse(ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->exists());

        // Reactivated: active again, the same record, zones given anew.
        $this->post("/users/{$membership->id}/activate")->assertRedirect();
        $this->assertSame('active', $agent->fresh()->status);
        $this->assertSame(1, Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $patience->id)->count());

        // Another role: retired.
        $this->patch("/users/{$membership->id}", ['role' => 'worker'])->assertSessionHasNoErrors();
        $this->assertSame('inactive', $agent->fresh()->status);
    }

    public function test_a_manager_can_change_an_agents_zone(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null, 'status' => 'active']);

        $this->actingAsRole('manager');

        $this->patch("/agents/{$agent->uuid}/zone", ['zone_uuid' => $newZone->uuid])->assertRedirect(route('agents.index'));
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $newZone->id]);
        $this->assertSame([$newZone->id], ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->pluck('zone_id')->all());
    }

    public function test_the_index_page_reports_per_zone_customer_and_agent_counts(): void
    {
        $zone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'status' => 'active']);
        CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->actingAsRole('manager');

        $response = $this->get('/agents')->assertOk();

        // Real tenant data means other zones already exist alongside the
        // ones this test just created, so locate ours by uuid.
        $page = json_decode(json_encode($response->viewData('page')), true);
        $zoneProps = collect($page['props']['zones'])->firstWhere('uuid', $zone->uuid);

        $this->assertNotNull($zoneProps, 'The zone created for this test was not present in the zones prop.');
        $this->assertSame(1, $zoneProps['customer_count']);
        $this->assertSame(1, $zoneProps['agent_count']);
        $this->assertSame([$agent->name], $zoneProps['agent_names']);
        $this->assertFalse(collect($page['props']['agents']['data'])->firstWhere('uuid', $agent->uuid)['has_login']);
    }

    public function test_an_agent_role_user_cannot_change_an_agents_zone(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null]);

        $this->actingAsRole('agent');

        $this->patch("/agents/{$agent->uuid}/zone", ['zone_uuid' => $newZone->uuid])->assertStatus(403);
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $oldZone->id]);
    }

    public function test_a_worker_role_user_cannot_change_an_agents_zone(): void
    {
        $oldZone = ZoneFactory::new()->create();
        $newZone = ZoneFactory::new()->create();
        $agent = AgentFactory::new()->create(['zone_id' => $oldZone->id, 'user_id' => null]);

        $this->actingAsRole('worker');

        $this->patch("/agents/{$agent->uuid}/zone", ['zone_uuid' => $newZone->uuid])->assertStatus(403);
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'zone_id' => $oldZone->id]);
    }

    /**
     * Commits real rows: the central user and its tenant membership are on
     * two database sessions (see InteractsWithTenantRoles), so the same
     * "release the outer transactions, clean up in finally" strategy as
     * UsersControlCenterTest's create test.
     */
    public function test_an_old_agent_without_a_login_becomes_a_field_agent_user(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();
        DB::connection('pgsql')->rollBack();

        tenancy()->initialize(Tenant::find('swecom'));
        $this->removeLoginLeftovers();
        $kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $originalRole = TenantUser::query()->where('user_id', $kelvin->id)->value('role');
        TenantUser::query()->where('user_id', $kelvin->id)->update(['role' => 'super']);
        $zone = ZoneFactory::new()->create(['name' => 'LOGIN-ZONE-'.uniqid()]);
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null, 'name' => 'OLD FIELD AGENT', 'status' => 'active']);
        tenancy()->end();

        try {
            $this->actingAs($kelvin)
                ->post("/agents/{$agent->uuid}/login", ['username' => 'oldfieldagent', 'email' => 'oldfieldagent@example.test', 'password' => 'password123'])
                ->assertRedirect(route('agents.index'))
                ->assertSessionHasNoErrors();

            tenancy()->initialize(Tenant::find('swecom'));
            $user = User::query()->where('username', 'oldfieldagent')->firstOrFail();
            $this->assertSame([$user->id, 'agent'], [(int) $agent->fresh()->user_id, TenantUser::query()->where('user_id', $user->id)->value('role')]);
            $this->assertSame($zone->id, $agent->fresh()->zone_id, 'Keeps the zones it had.');
            $this->assertSame(1, Agent::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->count(), 'Linked, not duplicated.');
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            $this->removeLoginLeftovers();
            TenantUser::query()->where('user_id', $kelvin->id)->update(['role' => $originalRole]);

            DB::connection('tenant')->beginTransaction();
            DB::connection('pgsql')->beginTransaction();
        }
    }

    private function removeLoginLeftovers(): void
    {
        $agentIds = Agent::query()->withoutGlobalScope(BranchScope::class)->where('name', 'OLD FIELD AGENT')->pluck('id');
        ZoneAgent::query()->withoutGlobalScopes()->whereIn('agent_id', $agentIds)->delete();
        Agent::query()->withoutGlobalScope(BranchScope::class)->whereKey($agentIds)->delete();
        DB::table('zones')->where('name', 'like', 'LOGIN-ZONE-%')->delete();

        $ids = User::query()->where('username', 'oldfieldagent')->pluck('id');
        DB::table('staff_assignments')->whereIn('user_id', $ids)->delete();
        TenantUser::query()->whereIn('user_id', $ids)->delete();
        User::query()->whereKey($ids)->delete();
    }
}
