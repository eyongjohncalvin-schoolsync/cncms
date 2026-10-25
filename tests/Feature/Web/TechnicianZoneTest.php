<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Scopes\BranchScope;
use App\Models\TechnicianZone;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\TechnicianService;
use App\Support\TenantContext;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\ComplaintFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Technicians (anyone whose role resolves complaints) may have usual zones —
 * a hint, never an access rule: none means "works anywhere". Assigning a
 * complaint suggests the zone's technicians first, then those who work
 * anywhere, each with their open complaints; only someone of this company
 * who resolves complaints in the complaint's branch can be given it. Also
 * the Users page flags agent accounts that can't work yet.
 */
class TechnicianZoneTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private Branch $buea;

    private Zone $molyko;

    private Zone $soppo;

    private TenantUser $terence;

    private TenantUser $patience;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->buea = BranchFactory::new()->create(['name' => 'BUEA']);
        $this->molyko = ZoneFactory::new()->create(['name' => 'MOLYKO', 'branch_id' => $this->buea->id]);
        $this->soppo = ZoneFactory::new()->create(['name' => 'GREAT SOPPO', 'branch_id' => $this->buea->id]);

        // Managers resolve complaints: two technicians of Buea.
        $this->terence = $this->staff('terence@shalomtech.dev', 'manager', $this->buea->id);
        $this->patience = $this->staff('patience@shalomtech.dev', 'manager', $this->buea->id);
    }

    public function test_a_complaint_suggests_its_zones_technicians_first(): void
    {
        $this->as('admin');
        $this->setZones($this->terence, [$this->soppo])->assertSessionHasNoErrors();
        $this->setZones($this->patience, [$this->molyko])->assertSessionHasNoErrors();
        $complaint = $this->complaintIn($this->molyko);

        $suggestions = app(TechnicianService::class)->suggestionsFor($complaint);
        $patience = collect($suggestions)->firstWhere('uuid', $this->patience->user->uuid);
        $terence = collect($suggestions)->firstWhere('uuid', $this->terence->user->uuid);

        $this->assertSame('covers', $patience['reason']);
        $this->assertSame('elsewhere', $terence['reason']);
        $this->assertLessThan(array_search($terence, $suggestions, true), array_search($patience, $suggestions, true));
    }

    public function test_a_technician_without_zones_works_anywhere_and_fewer_open_complaints_go_first(): void
    {
        $this->as('admin');
        $complaint = $this->complaintIn($this->molyko);
        $busy = $this->complaintIn($this->soppo);
        Complaint::query()->withoutGlobalScope(BranchScope::class)->whereKey($busy->id)->update(['assigned_to' => $this->terence->user_id]);

        $suggestions = collect(app(TechnicianService::class)->suggestionsFor($complaint))
            ->whereIn('uuid', [$this->terence->user->uuid, $this->patience->user->uuid])->values();

        $this->assertSame(['anywhere', 'anywhere'], $suggestions->pluck('reason')->all());
        $this->assertSame($this->patience->user->uuid, $suggestions[0]['uuid'], 'Fewer open complaints first.');
        $this->assertSame(1, $suggestions[1]['open']);
    }

    public function test_the_complaint_page_offers_the_suggestions_to_whoever_assigns(): void
    {
        $complaint = $this->complaintIn($this->molyko);
        $this->as('admin');

        $this->get("/complaints/{$complaint->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can_assign', true)
            ->where('assignees', fn ($assignees) => collect($assignees)->pluck('uuid')->contains($this->terence->user->uuid)));
    }

    public function test_only_a_technician_of_the_complaints_branch_can_be_given_it(): void
    {
        $complaint = $this->complaintIn($this->molyko);
        $this->as('admin');

        $this->post("/complaints/{$complaint->uuid}/assign", ['assignee_uuid' => $this->terence->user->uuid])->assertSessionHasNoErrors();
        $this->assertSame($this->terence->user_id, (int) $complaint->fresh()->assigned_to);

        // Not someone whose role doesn't resolve complaints…
        $this->staff('terence@shalomtech.dev', 'cashier', $this->buea->id);
        $this->post("/complaints/{$complaint->uuid}/assign", ['assignee_uuid' => $this->patience->user->uuid])->assertSessionHasNoErrors();
        $this->post("/complaints/{$complaint->uuid}/assign", ['assignee_uuid' => $this->terence->user->uuid])->assertSessionHasErrors('assignee_uuid');

        // …nor a technician fenced to another branch.
        $this->staff('patience@shalomtech.dev', 'manager', Branch::headquartersId());
        $this->post("/complaints/{$complaint->uuid}/assign", ['assignee_uuid' => $this->patience->user->uuid])->assertSessionHasErrors('assignee_uuid');
    }

    public function test_usual_zones_are_set_by_whoever_assigns_complaints_within_one_branch(): void
    {
        $this->as('admin');
        $this->setZones($this->terence, [$this->molyko, $this->soppo])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->zoneCount($this->terence));

        $this->setZones($this->terence, [])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->zoneCount($this->terence), 'No zones again: works anywhere.');

        $headquartersZone = ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()]);
        $this->setZones($this->terence, [$headquartersZone])->assertSessionHasErrors('zone_uuids');

        $this->as('worker');
        $this->setZones($this->terence, [$this->molyko])->assertForbidden();
    }

    public function test_the_coverage_screen_lists_the_branchs_technicians(): void
    {
        $this->as('admin');
        $this->setZones($this->terence, [$this->molyko]);

        $this->get("/zones/coverage?branch={$this->buea->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('technicians', fn ($technicians) => ($row = collect($technicians)->firstWhere('uuid', $this->terence->user->uuid)) !== null
                && $row['zones'] === [$this->molyko->uuid]));
    }

    public function test_the_users_page_flags_agents_who_cannot_work_yet(): void
    {
        $noProfile = $this->staff('terence@shalomtech.dev', 'agent', null);
        $noZones = $this->staff('patience@shalomtech.dev', 'agent', null);
        AgentFactory::new()->withoutCoverage()->create(['zone_id' => $this->molyko->id, 'user_id' => $noZones->user_id]);
        $this->as('admin');

        $this->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $noProfile->id)['agent_setup'] === 'no_profile'
                && collect($users)->firstWhere('id', $noZones->id)['agent_setup'] === 'no_zones'));
    }

    public function test_the_agents_own_profile_lists_every_zone_they_cover(): void
    {
        $token = $this->tokenForAgentCovering($this->molyko, $this->soppo);

        $this->withToken($token)->getJson('/api/v1/agents/me')->assertOk()
            ->assertJsonCount(2, 'data.zones')
            ->assertJsonPath('data.zones.0.name', 'GREAT SOPPO')
            ->assertJsonPath('data.zones.1.role', 'responsible');
    }

    /**
     * @param  list<Zone>  $zones
     */
    private function setZones(TenantUser $technician, array $zones): TestResponse
    {
        return $this->put('/zones/coverage/technicians', [
            'branch_uuid' => $this->buea->uuid,
            'user_uuid' => $technician->user->uuid,
            'zone_uuids' => array_map(fn (Zone $zone): string => $zone->uuid, $zones),
        ]);
    }

    private function zoneCount(TenantUser $technician): int
    {
        return TechnicianZone::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $technician->user_id)->count();
    }

    private function complaintIn(Zone $zone): Complaint
    {
        return ComplaintFactory::new()->submittedBy($this->kelvin->id)->create(['zone_id' => $zone->id]);
    }

    private function staff(string $email, string $role, ?int $branchId): TenantUser
    {
        $tenantUser = TenantUser::query()->where('user_id', User::query()->where('email', $email)->value('id'))->with('user')->firstOrFail();
        $tenantUser->update(['role' => $role, 'branch_id' => $branchId]);

        return $tenantUser;
    }

    private function as(string $role, ?int $branchId = null): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
