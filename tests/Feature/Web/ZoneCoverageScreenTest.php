<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Agent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneAgent;
use App\Services\ZoneCoverageService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The Zone coverage screen (App\Http\Controllers\ZoneCoverageController):
 * management gives a branch's zones to its agents — one agent for all of
 * them (a startup), or split, with one responsible collector per zone and
 * any helpers — and sees each agent's workload against the branch's limit.
 */
class ZoneCoverageScreenTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $buea;

    private User $kelvin;

    /** @var list<Zone> */
    private array $zones;

    private Agent $divine;

    private Agent $paul;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->buea = BranchFactory::new()->create(['name' => 'BUEA']);
        $this->zones = collect(['MOLYKO', 'GREAT SOPPO', 'BOKWANGO', 'MILE 17'])
            ->map(fn (string $name): Zone => ZoneFactory::new()->create(['name' => $name, 'branch_id' => $this->buea->id]))
            ->all();
        CustomerFactory::new()->count(3)->create(['zone_id' => $this->zones[0]->id, 'status' => 'active']);

        // Two agents who cover nothing yet.
        $this->divine = AgentFactory::new()->withoutCoverage()->create(['zone_id' => $this->zones[0]->id, 'user_id' => null, 'name' => 'DIVINE']);
        $this->paul = AgentFactory::new()->withoutCoverage()->create(['zone_id' => $this->zones[0]->id, 'user_id' => null, 'name' => 'PAUL']);
    }

    public function test_the_screen_shows_the_branchs_zones_agents_and_limit(): void
    {
        $this->as('admin');

        $this->get("/zones/coverage?branch={$this->buea->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Zones/Coverage')
            ->where('branch.uuid', $this->buea->uuid)
            ->has('zones', 4)
            ->where('zones.0.name', 'BOKWANGO')
            ->where('zones', fn ($zones) => collect($zones)->firstWhere('name', 'MOLYKO')['customers'] === 3)
            ->has('agents', 2)
            ->where('can_manage', true));
    }

    public function test_a_startup_gives_every_zone_to_one_agent(): void
    {
        $this->as('admin');

        $this->apply('responsible', $this->divine, $this->zones)->assertSessionHasNoErrors();

        $this->assertSame(4, $this->coverage($this->divine, ZoneAgent::ROLE_RESPONSIBLE));
    }

    public function test_splitting_zones_moves_them_to_the_new_agent(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, $this->zones);

        $this->apply('responsible', $this->paul, [$this->zones[2], $this->zones[3]])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->coverage($this->divine, ZoneAgent::ROLE_RESPONSIBLE));
        $this->assertSame(0, $this->coverage($this->divine, ZoneAgent::ROLE_HELPER), 'The previous collector stops covering them.');
        $this->assertSame(2, $this->coverage($this->paul, ZoneAgent::ROLE_RESPONSIBLE));
    }

    public function test_the_previous_collector_can_stay_on_as_a_helper(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, $this->zones);

        $this->apply('responsible', $this->paul, [$this->zones[3]], keepPrevious: true)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->coverage($this->divine, ZoneAgent::ROLE_HELPER));
        $this->assertSame(1, $this->coverage($this->paul, ZoneAgent::ROLE_RESPONSIBLE));
    }

    public function test_helpers_can_be_added_and_agents_taken_off(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, $this->zones);

        $this->apply('helper', $this->paul, [$this->zones[0], $this->zones[1]])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->coverage($this->paul, ZoneAgent::ROLE_HELPER));

        $this->apply('remove', $this->paul, [$this->zones[0]])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->coverage($this->paul, ZoneAgent::ROLE_HELPER));

        $this->apply('clear', null, [$this->zones[1]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->coverage($this->divine, ZoneAgent::ROLE_RESPONSIBLE));
    }

    public function test_an_agent_who_loses_their_home_zone_gets_one_they_cover(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, [$this->zones[0], $this->zones[1]]);

        $this->apply('responsible', $this->paul, [$this->zones[0]]);

        $this->assertSame($this->zones[1]->id, $this->divine->fresh()->zone_id);
    }

    public function test_inactive_agents_and_other_branches_are_refused(): void
    {
        $this->as('admin');
        $this->divine->update(['status' => 'inactive']);
        $this->apply('responsible', $this->divine, [$this->zones[0]])->assertSessionHasErrors('agent_uuid');

        $headquartersZone = ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()]);
        $this->apply('helper', $this->paul, [$headquartersZone])->assertSessionHasErrors('zone_uuids');

        $headquartersAgent = AgentFactory::new()->create(['zone_id' => $headquartersZone->id, 'user_id' => null]);
        $this->apply('helper', $headquartersAgent, [$this->zones[0]])->assertSessionHasErrors('agent_uuid');
    }

    public function test_a_fenced_manager_works_on_their_own_branch_only(): void
    {
        $this->as('admin', Branch::headquartersId() === $this->buea->id ? null : $this->buea->id);

        // Asking for another branch still shows their own.
        $this->get('/zones/coverage?branch='.Branch::query()->findOrFail(Branch::headquartersId())->uuid)
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('branch.uuid', $this->buea->uuid)->where('branches', []));

        $this->patch('/zones/coverage', [
            'branch_uuid' => Branch::query()->findOrFail(Branch::headquartersId())->uuid,
            'action' => 'clear', 'zone_uuids' => [$this->zones[0]->uuid],
        ])->assertNotFound();
    }

    public function test_read_only_staff_see_coverage_but_cannot_change_it(): void
    {
        // Workers hold agents.view (read-only), as on the Agents page.
        $this->as('worker');

        $this->get("/zones/coverage?branch={$this->buea->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_manage', false)->where('can_edit_limit', false));
        $this->apply('responsible', $this->divine, [$this->zones[0]])->assertForbidden();
        $this->put('/zones/coverage/limit', ['branch_uuid' => $this->buea->uuid, 'limit' => 5])->assertForbidden();
    }

    public function test_headquarters_sets_the_default_limit_and_a_branch_its_own(): void
    {
        $this->as('admin');

        $this->put('/zones/coverage/limit', ['branch_uuid' => Branch::query()->findOrFail(Branch::headquartersId())->uuid, 'limit' => 300])->assertSessionHasNoErrors();
        $this->assertSame(300, Company::query()->first()->agent_customer_limit);

        $this->get("/zones/coverage?branch={$this->buea->uuid}")->assertInertia(fn (Assert $page) => $page->where('limit', 300)->where('branch_limit', null));

        $this->put('/zones/coverage/limit', ['branch_uuid' => $this->buea->uuid, 'limit' => 150])->assertSessionHasNoErrors();
        $this->get("/zones/coverage?branch={$this->buea->uuid}")->assertInertia(fn (Assert $page) => $page->where('limit', 150)->where('branch_limit', 150));
    }

    public function test_a_zone_shared_by_two_agents_counts_half_for_each(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, [$this->zones[0]]);
        $this->apply('helper', $this->paul, [$this->zones[0]]);

        $loads = ZoneCoverageService::workloads(app(ZoneCoverageService::class)->overview($this->buea));

        $this->assertEqualsWithDelta(1.5, $loads[$this->divine->uuid], 0.001);
        $this->assertEqualsWithDelta(1.5, $loads[$this->paul->uuid], 0.001);
    }

    public function test_the_agents_list_shows_zones_and_workload_in_one_query(): void
    {
        $this->as('admin');
        $this->apply('responsible', $this->divine, $this->zones);
        $this->put('/zones/coverage/limit', ['branch_uuid' => $this->buea->uuid, 'limit' => 3]);

        $loads = app(ZoneCoverageService::class)->workloadsOf([$this->divine->id, $this->paul->id]);
        $this->assertSame(['zones' => 4, 'customers' => 3.0], $loads[$this->divine->id]);
        $this->assertArrayNotHasKey($this->paul->id, $loads, 'An agent covering nothing has no workload row.');

        $this->get("/agents?zone_uuid={$this->zones[0]->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('agents.data', fn ($agents) => ($row = collect($agents)->firstWhere('uuid', $this->divine->uuid)) !== null
                && $row['zones_covered'] === 4
                && $row['customers_covered'] === 3
                && $row['customer_limit'] === 3
                && $row['workload_status'] === 'busy'));
    }

    public function test_zones_without_an_active_collector_are_flagged(): void
    {
        $this->as('admin', $this->buea->id);
        $this->apply('responsible', $this->divine, [$this->zones[0], $this->zones[1]]);

        $this->assertSame(2, app(ZoneCoverageService::class)->uncoveredZoneCount());

        // An inactive responsible collector counts as no collector.
        $this->divine->update(['status' => 'inactive']);
        $this->assertSame(4, app(ZoneCoverageService::class)->uncoveredZoneCount());

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('uncovered_zones', 4)));
    }

    public function test_the_zones_list_shows_each_zones_collector(): void
    {
        $this->as('admin', $this->buea->id);
        $this->apply('responsible', $this->divine, [$this->zones[0]]);

        $this->get('/zones')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('zones.data', fn ($zones) => collect($zones)->firstWhere('uuid', $this->zones[0]->uuid)['collector_name'] === 'DIVINE'
                && collect($zones)->firstWhere('uuid', $this->zones[1]->uuid)['collector_name'] === null));
    }

    /**
     * @param  list<Zone>  $zones
     */
    private function apply(string $action, ?Agent $agent, array $zones, bool $keepPrevious = false): TestResponse
    {
        return $this->patch('/zones/coverage', array_filter([
            'branch_uuid' => $this->buea->uuid,
            'action' => $action,
            'agent_uuid' => $agent?->uuid,
            'zone_uuids' => array_map(fn (Zone $zone): string => $zone->uuid, $zones),
            'keep_previous_as_helper' => $keepPrevious,
        ], fn ($value): bool => $value !== null));
    }

    private function coverage(Agent $agent, string $role): int
    {
        return ZoneAgent::query()->withoutGlobalScope(BranchScope::class)->where('agent_id', $agent->id)->where('role', $role)->count();
    }

    private function as(string $role, ?int $branchId = null): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
