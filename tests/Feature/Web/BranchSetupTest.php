<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\ZoneService;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Guided branch setup: creating a branch lands on its setup page (quick-add
 * zones + checklist), the bulk zone endpoint behind the quick-add, the
 * branch list's zone counts and setup links, and Zones/Create's
 * `?branch=` preselect.
 */
class BranchSetupTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $buea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
    }

    private function as(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    /** Another real tenant member (not the seeded owner) to place on a branch. */
    private function otherMember(): TenantUser
    {
        $owner = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $member = TenantUser::query()->where('user_id', '!=', $owner->id)->first();

        if ($member === null) {
            $this->markTestSkipped('The test tenant needs a second member.');
        }

        return $member;
    }

    public function test_creating_a_branch_redirects_to_its_setup_page(): void
    {
        $this->as('super');

        $response = $this->post('/branches', ['name' => 'limbe']);

        $branch = Branch::query()->where('name', 'LIMBE')->sole();
        $response->assertRedirect("/branches/{$branch->uuid}/setup");
        $response->assertSessionHas('success');
    }

    public function test_setup_page_shows_an_empty_checklist_for_a_new_branch(): void
    {
        $this->as('super');

        $this->get("/branches/{$this->buea->uuid}/setup")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Branches/Setup')
                ->where('branch.uuid', $this->buea->uuid)
                ->where('branch.name', 'BUEA')
                ->where('checklist.zones.done', false)
                ->where('checklist.zones.count', 0)
                ->where('checklist.manager.done', false)
                ->where('checklist.settings.applicable', true)
                ->where('checklist.settings.done', false)
                ->where('can.add_zones', true)
                ->where('can.manage_settings', true));
    }

    public function test_setup_checklist_ticks_from_real_data(): void
    {
        ZoneFactory::new()->create(['branch_id' => $this->buea->id, 'name' => 'MOLYKO']);
        $member = $this->otherMember();
        $member->update(['role' => 'manager', 'branch_id' => $this->buea->id]);
        User::query()->whereKey($member->user_id)->update(['status' => 'active']);
        $this->buea->update(['momo_number' => '699000000']);

        $this->as('admin');

        $this->get("/branches/{$this->buea->uuid}/setup")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklist.zones.done', true)
                ->where('checklist.zones.count', 1)
                ->where('checklist.zones.names', ['MOLYKO'])
                ->where('checklist.manager.done', true)
                ->where('checklist.manager.names', [User::query()->findOrFail($member->user_id)->name])
                ->where('checklist.settings.done', true));
    }

    public function test_a_recorded_branch_price_counts_as_settings_reviewed(): void
    {
        $service = Service::query()->first();

        if ($service === null) {
            $this->markTestSkipped('The test tenant needs a service.');
        }

        $owner = $this->as('super');
        BranchPrice::query()->create(['branch_id' => $this->buea->id, 'service_id' => $service->id, 'price' => '2000', 'reason' => 'Competition', 'set_by' => $owner->id]);

        $this->get("/branches/{$this->buea->uuid}/setup")
            ->assertInertia(fn (Assert $page) => $page->where('checklist.settings.done', true));
    }

    public function test_a_worker_or_deactivated_manager_does_not_count_as_branch_manager(): void
    {
        $member = $this->otherMember();
        $member->update(['role' => 'worker', 'branch_id' => $this->buea->id]);

        $this->as('super');
        $this->get("/branches/{$this->buea->uuid}/setup")
            ->assertInertia(fn (Assert $page) => $page->where('checklist.manager.done', false));

        $member->update(['role' => 'manager']);
        User::query()->whereKey($member->user_id)->update(['status' => 'passive']);

        $this->get("/branches/{$this->buea->uuid}/setup")
            ->assertInertia(fn (Assert $page) => $page->where('checklist.manager.done', false));
    }

    public function test_headquarters_settings_step_is_not_applicable(): void
    {
        $this->as('super');
        $headquarters = Branch::query()->where('is_headquarters', true)->sole();

        $this->get("/branches/{$headquarters->uuid}/setup")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklist.settings.applicable', false)
                ->where('checklist.settings.done', false)
                ->where('checklist.zones.done', true));
    }

    public function test_setup_page_is_refused_to_a_manager(): void
    {
        $this->as('manager');
        $this->get("/branches/{$this->buea->uuid}/setup")->assertForbidden();

        $this->as('manager', $this->buea->id);
        $this->get("/branches/{$this->buea->uuid}/setup")->assertForbidden();
    }

    public function test_bulk_create_stores_every_zone_upper_cased_in_the_branch(): void
    {
        $this->as('super');
        Cache::put('zones:all', 'stale');

        $levels = [];
        Zone::created(function () use (&$levels): void {
            $levels[] = DB::connection('tenant')->transactionLevel();
        });
        $outerLevel = DB::connection('tenant')->transactionLevel();

        $response = $this->post("/branches/{$this->buea->uuid}/zones", ['names' => ['molyko', '  great   soppo ', 'Bokwai']]);

        $response->assertRedirect("/branches/{$this->buea->uuid}/setup");
        $response->assertSessionHas('success', '3 zones added.');

        $this->assertEqualsCanonicalizing(
            ['MOLYKO', 'GREAT SOPPO', 'BOKWAI'],
            Zone::query()->where('branch_id', $this->buea->id)->pluck('name')->all(),
        );
        $this->assertFalse(Cache::has('zones:all'), 'The zone list cache is cleared like a single create.');
        $this->assertCount(3, $levels);
        $this->assertCount(1, array_unique($levels), 'Every zone is written inside the same transaction.');
        $this->assertGreaterThan($outerLevel, $levels[0]);
        $this->assertSame(3, DB::connection('tenant')->table('audit_logs')
            ->where('table_name', 'zones')
            ->where('action', 'create')
            ->whereIn('record_uuid', Zone::query()->where('branch_id', $this->buea->id)->pluck('uuid'))
            ->count(), 'Each zone gets the same audit row as a single create.');
    }

    public function test_a_failing_insert_keeps_none_of_the_batch(): void
    {
        $this->as('super');

        try {
            app(ZoneService::class)->createManyInBranch($this->buea->id, ['ATOMIC ONE', 'ATOMIC ONE']);
            $this->fail('The duplicate insert should have failed.');
        } catch (QueryException) {
            // Expected: zones.name is unique.
        }

        $this->assertDatabaseMissing('zones', ['name' => 'ATOMIC ONE'], 'tenant');
    }

    public function test_bulk_create_reports_an_error_per_bad_name(): void
    {
        $this->as('super');
        ZoneFactory::new()->create(['name' => 'THR01', 'branch_id' => Branch::headquartersId()]);

        $response = $this->post("/branches/{$this->buea->uuid}/zones", [
            'names' => ['thr01', 'GOOD ONE', 'new zone', 'NEW  ZONE', str_repeat('A', 26), '   '],
        ]);

        $response->assertSessionHasErrors(['names.0', 'names.3', 'names.4', 'names.5']);
        $response->assertSessionDoesntHaveErrors(['names.1', 'names.2']);
        $this->assertSame(0, Zone::query()->where('branch_id', $this->buea->id)->count(), 'Nothing is saved while any name is bad.');
    }

    public function test_bulk_create_requires_at_least_one_name(): void
    {
        $this->as('super');

        $this->post("/branches/{$this->buea->uuid}/zones", ['names' => []])->assertSessionHasErrors('names');
    }

    public function test_a_user_fenced_to_another_branch_is_refused(): void
    {
        $limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $this->as('manager', $limbe->id);

        $this->post("/branches/{$this->buea->uuid}/zones", ['names' => ['MOLYKO']])->assertForbidden();

        $this->assertSame(0, Zone::query()->withoutGlobalScopes()->where('branch_id', $this->buea->id)->count());
    }

    public function test_a_fenced_manager_may_add_zones_to_their_own_branch(): void
    {
        $this->as('manager', $this->buea->id);

        $this->post("/branches/{$this->buea->uuid}/zones", ['names' => ['MOLYKO']])->assertRedirect();

        $this->assertSame(1, Zone::query()->where('branch_id', $this->buea->id)->count());
    }

    public function test_a_role_that_cannot_create_zones_is_refused(): void
    {
        $this->as('agent');

        $this->post("/branches/{$this->buea->uuid}/zones", ['names' => ['MOLYKO']])->assertForbidden();
    }

    public function test_index_shows_zone_counts_and_setup_links(): void
    {
        $this->as('super');
        ZoneFactory::new()->count(2)->create(['branch_id' => $this->buea->id]);

        $this->get('/branches')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_manage', true)
                ->where('branches.data', fn ($branches) => collect($branches)->firstWhere('uuid', $this->buea->uuid)['zone_count'] === 2));
    }

    public function test_index_zone_counts_do_not_query_per_branch(): void
    {
        $this->as('super');

        $this->get('/branches')->assertOk(); // warm up per-process memos

        $queriesFor = function (): int {
            DB::connection('tenant')->flushQueryLog();
            DB::connection('tenant')->enableQueryLog();
            $this->get('/branches')->assertOk();
            $count = count(DB::connection('tenant')->getQueryLog());
            DB::connection('tenant')->disableQueryLog();

            return $count;
        };

        $before = $queriesFor();

        foreach (range(1, 4) as $i) {
            ZoneFactory::new()->count(2)->create(['branch_id' => BranchFactory::new()->create()->id]);
        }

        // Saving a branch clears the branch memo; a real request starts without one anyway.
        $this->get('/branches')->assertOk();

        $this->assertSame($before, $queriesFor(), 'More branches must not mean more queries.');
    }

    public function test_index_hides_setup_links_from_a_manager(): void
    {
        $this->as('manager');

        $this->get('/branches')->assertInertia(fn (Assert $page) => $page->where('can_manage', false));
    }

    public function test_zone_create_preselects_a_valid_branch(): void
    {
        $this->as('super');

        $this->get("/zones/create?branch={$this->buea->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Zones/Create')->where('selected_branch_uuid', $this->buea->uuid));
    }

    public function test_zone_create_ignores_an_invalid_or_unknown_branch(): void
    {
        $this->as('super');

        $this->get('/zones/create?branch=not-a-uuid')
            ->assertInertia(fn (Assert $page) => $page->where('selected_branch_uuid', null));

        $this->get('/zones/create?branch=0b4a6b0c-0000-4000-8000-000000000000')
            ->assertInertia(fn (Assert $page) => $page->where('selected_branch_uuid', null));

        $this->get('/zones/create')
            ->assertInertia(fn (Assert $page) => $page->where('selected_branch_uuid', null));
    }

    public function test_zone_create_ignores_another_branch_for_a_fenced_user(): void
    {
        $limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $this->as('manager', $limbe->id);

        $this->get("/zones/create?branch={$this->buea->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('selected_branch_uuid', null));
    }
}
