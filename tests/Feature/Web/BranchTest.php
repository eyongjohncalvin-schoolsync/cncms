<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\BranchFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web CRUD for the new "Manage Branches" surface (App\Http\Controllers\
 * BranchController), plus the schema-phase assertions branches-and-
 * locations.md section 6/7 calls for:
 *
 *   - The branches table exists, seeded with exactly one "Main Branch"
 *     row, and every existing zone/company in the real, already-migrated
 *     "swecom" tenant schema was backfilled to point at it (a real query
 *     against live data, not just "the migration ran without error" —
 *     migrations are applied out-of-band via `tenants:migrate`, this test
 *     only asserts on the resulting state inside its own transaction).
 *   - A new zone can be created with an explicit branch.
 *   - BranchPolicy gates creation to the right roles (super/admin only —
 *     deliberately narrower than ZonePolicy's super/admin/manager, see
 *     the doc's section 8).
 *
 * Same InteractsWithTenantRoles + DatabaseTransactions pattern as
 * ZoneTest.php — no real tenant schema CREATE/DROP cycles (doc section 6
 * step 5's Postgres-deadlock-avoidance note).
 */
class BranchTest extends TestCase
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

    public function test_branches_table_has_exactly_one_seeded_main_branch(): void
    {
        // Asserts against the real, already-migrated swecom tenant schema
        // (this test's transaction sits on top of that committed state) —
        // proves 2026_08_24_160000_create_branches_table.php actually
        // seeded the row it claims to, not just that the migration ran.
        $branches = DB::connection('tenant')->table('branches')->get();

        $this->assertCount(1, $branches);
        $this->assertSame('Main Branch', $branches->first()->name);
    }

    public function test_every_existing_zone_and_company_is_backfilled_to_main_branch(): void
    {
        $mainBranchId = DB::connection('tenant')->table('branches')->where('name', 'Main Branch')->value('id');

        $this->assertNotNull($mainBranchId);

        $zonesTotal = DB::connection('tenant')->table('zones')->count();
        $zonesOnMain = DB::connection('tenant')->table('zones')->where('branch_id', $mainBranchId)->count();
        $zonesNull = DB::connection('tenant')->table('zones')->whereNull('branch_id')->count();

        $this->assertSame(0, $zonesNull);
        $this->assertSame($zonesTotal, $zonesOnMain);
        $this->assertGreaterThan(0, $zonesTotal);

        $companiesTotal = DB::connection('tenant')->table('companies')->count();
        $companiesOnMain = DB::connection('tenant')->table('companies')->where('branch_id', $mainBranchId)->count();

        $this->assertSame($companiesTotal, $companiesOnMain);
        $this->assertGreaterThan(0, $companiesTotal);
    }

    public function test_index_renders_with_branch_data(): void
    {
        $this->actingAsRole('super');

        $response = $this->get('/branches');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Branches/Index')->has('branches.data'));
    }

    public function test_super_can_create_a_branch(): void
    {
        $this->actingAsRole('super');

        $response = $this->post('/branches', ['name' => 'Buea Branch']);

        $response->assertRedirect('/branches');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('branches', ['name' => 'Buea Branch']);
    }

    public function test_admin_can_create_a_branch(): void
    {
        $this->actingAsRole('admin');

        $response = $this->post('/branches', ['name' => 'Douala Branch']);

        $response->assertRedirect('/branches');
        $this->assertDatabaseHas('branches', ['name' => 'Douala Branch']);
    }

    /**
     * Deliberately narrower than ZonePolicy: unlike zones (super/admin/
     * manager may create), branch creation is office-only — a manager
     * cannot create a branch even though they can create a zone.
     */
    public function test_manager_cannot_create_a_branch(): void
    {
        $this->actingAsRole('manager');

        $this->get('/branches/create')->assertForbidden();

        $this->post('/branches', ['name' => 'Should Not Exist'])->assertForbidden();

        $this->assertDatabaseMissing('branches', ['name' => 'Should Not Exist']);
    }

    public function test_agent_cannot_create_a_branch_but_can_view_branches(): void
    {
        $this->actingAsRole('agent');

        $this->get('/branches/create')->assertForbidden();
        $this->post('/branches', ['name' => 'Should Not Exist Either'])->assertForbidden();

        $this->get('/branches')->assertOk();
    }

    public function test_store_fails_validation_when_name_is_missing(): void
    {
        $this->actingAsRole('super');

        $response = $this->post('/branches', []);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_store_fails_validation_on_duplicate_branch_name(): void
    {
        $this->actingAsRole('super');

        BranchFactory::new()->create(['name' => 'Duplicate Branch']);

        $response = $this->post('/branches', ['name' => 'Duplicate Branch']);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/branches')->assertRedirect('/login');
    }

    /**
     * With only "Main Branch" existing, creating a zone without picking a
     * branch defaults to it (ZoneService::resolveBranchId) — the
     * create/edit forms hide the picker entirely in this case (doc
     * section 8's "don't force a decision when there's nothing to choose
     * from yet").
     */
    public function test_zone_created_without_branch_uuid_defaults_to_the_sole_existing_branch(): void
    {
        $this->actingAsRole('manager');

        $mainBranchId = DB::connection('tenant')->table('branches')->where('name', 'Main Branch')->value('id');

        $response = $this->post('/zones', ['name' => 'ZDEFAULT (NO BRANCH)']);

        $response->assertRedirect('/zones');
        $this->assertDatabaseHas('zones', ['name' => 'ZDEFAULT (NO BRANCH)', 'branch_id' => $mainBranchId]);
    }

    public function test_zone_can_be_created_with_an_explicit_branch(): void
    {
        $this->actingAsRole('manager');

        $branch = BranchFactory::new()->create(['name' => 'Buea Explicit Branch']);

        $response = $this->post('/zones', [
            'name' => 'ZEXPLICIT (BUEA)',
            'branch_uuid' => $branch->uuid,
        ]);

        $response->assertRedirect('/zones');
        $this->assertDatabaseHas('zones', ['name' => 'ZEXPLICIT (BUEA)', 'branch_id' => $branch->id]);
    }

    /**
     * Once more than one branch exists, zones.branch_id being NOT NULL
     * means there's no safe silent default any more — the caller must
     * pick one explicitly.
     */
    public function test_zone_creation_requires_branch_uuid_once_multiple_branches_exist(): void
    {
        $this->actingAsRole('manager');

        BranchFactory::new()->create(['name' => 'Second Branch']);

        $response = $this->post('/zones', ['name' => 'ZNOBRANCH (SHOULD FAIL)']);

        $response->assertSessionHasErrors(['branch_uuid']);
        $this->assertDatabaseMissing('zones', ['name' => 'ZNOBRANCH (SHOULD FAIL)']);
    }

    public function test_zone_edit_page_receives_the_branch_list(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $this->get("/zones/{$zone->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Zones/Edit')
                ->where('zone.uuid', $zone->uuid)
                ->has('branches'));
    }

    public function test_zone_branch_can_be_reassigned_on_update(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $newBranch = BranchFactory::new()->create(['name' => 'Reassigned Branch']);

        $response = $this->patch("/zones/{$zone->uuid}", ['branch_uuid' => $newBranch->uuid]);

        $response->assertRedirect('/zones');
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'branch_id' => $newBranch->id]);
    }

    public function test_deleting_a_branch_with_zones_is_restricted(): void
    {
        $branch = BranchFactory::new()->create(['name' => 'Branch With Zones']);
        ZoneFactory::new()->create(['branch_id' => $branch->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Branch::query()->where('id', $branch->id)->delete();
    }

    public function test_edit_page_renders_with_branch_data(): void
    {
        $this->actingAsRole('super');

        $branch = BranchFactory::new()->create(['name' => 'Edit Target Branch']);

        $this->get("/branches/{$branch->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Branches/Edit')
                ->where('branch.uuid', $branch->uuid)
                ->where('branch.name', 'Edit Target Branch'));
    }

    public function test_super_can_update_a_branch_name(): void
    {
        $this->actingAsRole('super');

        $branch = BranchFactory::new()->create(['name' => 'Old Branch Name']);

        $response = $this->patch("/branches/{$branch->uuid}", ['name' => 'New Branch Name']);

        $response->assertRedirect('/branches');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'New Branch Name']);
    }

    public function test_update_fails_validation_on_duplicate_branch_name(): void
    {
        $this->actingAsRole('super');

        BranchFactory::new()->create(['name' => 'Taken Branch Name']);
        $branch = BranchFactory::new()->create(['name' => 'Branch To Rename']);

        $response = $this->patch("/branches/{$branch->uuid}", ['name' => 'Taken Branch Name']);

        $response->assertSessionHasErrors(['name']);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Branch To Rename']);
    }

    /**
     * The friendly-error path (BranchService::delete()) that catches the
     * FK-restrict QueryException from zones.branch_id's restrictOnDelete()
     * and rethrows it as a ValidationException — the web layer must never
     * surface a raw 500/SQL error here, and both the zone and the branch
     * must still exist afterward.
     */
    public function test_deleting_a_branch_with_zones_via_the_web_route_shows_a_friendly_error(): void
    {
        $this->actingAsRole('super');

        $branch = BranchFactory::new()->create(['name' => 'Branch With Zones (Web)']);
        $zone = ZoneFactory::new()->create(['branch_id' => $branch->id]);

        $response = $this->delete("/branches/{$branch->uuid}");

        $response->assertRedirect('/branches');
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'Cannot delete this branch',
            session('error'),
        );
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'branch_id' => $branch->id]);
    }

    public function test_a_branch_without_zones_can_be_deleted(): void
    {
        $this->actingAsRole('super');

        $branch = BranchFactory::new()->create(['name' => 'Branch Without Zones']);

        $response = $this->delete("/branches/{$branch->uuid}");

        $response->assertRedirect('/branches');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    /**
     * Runs the manager/agent/worker "no edit/update/destroy access" and
     * "index still accessible" assertions for one role — called from a
     * dedicated test_* method per role below, matching this file's existing
     * one-method-per-role convention (e.g. test_manager_cannot_create_a_branch())
     * rather than introducing a data provider.
     */
    private function assertRoleCannotEditUpdateOrDeleteButCanViewIndex(string $role): void
    {
        $this->actingAsRole($role);

        $branch = BranchFactory::new()->create(['name' => "Guarded Branch ({$role})"]);

        $this->get("/branches/{$branch->uuid}/edit")->assertForbidden();
        $this->patch("/branches/{$branch->uuid}", ['name' => 'Should Not Apply'])->assertForbidden();
        $this->delete("/branches/{$branch->uuid}")->assertForbidden();

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => "Guarded Branch ({$role})"]);

        // viewAny (index) stays open to every role per BranchPolicy — this
        // edit/update/destroy pass must not narrow that.
        $this->get('/branches')->assertOk();
    }

    public function test_manager_cannot_edit_update_or_delete_a_branch(): void
    {
        $this->assertRoleCannotEditUpdateOrDeleteButCanViewIndex('manager');
    }

    public function test_agent_cannot_edit_update_or_delete_a_branch(): void
    {
        $this->assertRoleCannotEditUpdateOrDeleteButCanViewIndex('agent');
    }

    public function test_worker_cannot_edit_update_or_delete_a_branch(): void
    {
        $this->assertRoleCannotEditUpdateOrDeleteButCanViewIndex('worker');
    }
}
