<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Session-cookie web CRUD (App\Http\Controllers\ZoneController), distinct
 * from the Sanctum bearer-token API coverage in
 * tests/Feature/Api/ZoneTest.php. Same InteractsWithTenantRoles tenant/
 * transaction setup, but authenticates via actingAs() (session auth)
 * instead of tokenForRole() (which issues a Sanctum token).
 */
class ZoneTest extends TestCase
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

    public function test_index_renders_with_zone_data(): void
    {
        // The tenant schema carries 29 real seeded zones (committed rows,
        // visible inside this test's transaction), so a plain unfiltered
        // /zones request mixes them with whatever this test creates. Scope
        // the assertion to a distinctive search term instead of asserting
        // an exact total page size.
        $this->actingAsRole('super');

        ZoneFactory::new()->create(['name' => 'ZTEST-A ONE']);
        ZoneFactory::new()->create(['name' => 'ZTEST-A TWO']);

        $response = $this->get('/zones?search=ZTEST-A');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Zones/Index')
                ->has('zones.data', 2)
                ->has('filters'));
    }

    public function test_create_page_renders(): void
    {
        $this->actingAsRole('manager');

        $response = $this->get('/zones/create');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Zones/Create'));
    }

    public function test_store_creates_a_zone_and_redirects_with_a_flash_message(): void
    {
        $this->actingAsRole('manager');

        $response = $this->post('/zones', [
            'name' => 'Z99 (NEW ZONE)',
            'town' => 'KUMBA 3',
        ]);

        $response->assertRedirect('/zones');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('zones', ['name' => 'Z99 (NEW ZONE)']);
    }

    public function test_store_fails_validation_when_name_is_missing(): void
    {
        $this->actingAsRole('manager');

        $response = $this->post('/zones', ['town' => 'KUMBA 3']);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_manager_can_edit_and_update_a_zone(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $this->get("/zones/{$zone->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Zones/Edit')->where('zone.uuid', $zone->uuid));

        // A zone's town is its branch's now: a sent town is ignored.
        $response = $this->patch("/zones/{$zone->uuid}", ['name' => 'UPDATED NAME', 'town' => 'UPDATED TOWN']);

        $response->assertRedirect('/zones');
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'name' => 'UPDATED NAME', 'town' => $zone->town]);
    }

    public function test_super_can_delete_a_zone(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();

        $response = $this->delete("/zones/{$zone->uuid}");

        $response->assertRedirect('/zones');
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    /**
     * Regression test: deleting a zone still referenced by a customer used
     * to raise a raw, unhandled Postgres RESTRICT-violation QueryException
     * straight to the user (customers.zone_id/agents.zone_id are both
     * restrictOnDelete()). App\Services\ZoneService::delete() now checks
     * up front and ZoneController::destroy() converts it to this app's
     * normal flash('error') redirect instead.
     */
    public function test_deleting_a_zone_with_customers_fails_with_a_friendly_error(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $response = $this->delete("/zones/{$zone->uuid}");

        $response->assertRedirect('/zones');
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    /**
     * Archived customers still hold their zone (the foreign key ignores soft
     * deletes): the count must include them, or the delete hits the raw
     * RESTRICT violation and the user gets a 500.
     */
    public function test_a_zone_holding_only_archived_customers_is_refused_politely(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active'])->delete();

        $this->delete("/zones/{$zone->uuid}")->assertRedirect('/zones')->assertSessionHas('error');

        $this->assertDatabaseHas('zones', ['id' => $zone->id]);
    }

    public function test_agent_cannot_create_a_zone(): void
    {
        $this->actingAsRole('agent');

        $this->get('/zones/create')->assertForbidden();

        $this->post('/zones', [
            'name' => 'Z98 (SHOULD NOT EXIST)',
            'town' => 'KUMBA 3',
        ])->assertForbidden();

        $this->assertDatabaseMissing('zones', ['name' => 'Z98 (SHOULD NOT EXIST)']);
    }

    public function test_agent_cannot_edit_a_zone(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();

        $this->get("/zones/{$zone->uuid}/edit")->assertForbidden();
    }

    public function test_agent_can_still_view_zones(): void
    {
        $this->actingAsRole('agent');

        ZoneFactory::new()->create();

        $this->get('/zones')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/zones')->assertRedirect('/login');
    }

    public function test_store_saves_the_name_upper_case_trimmed_and_collapsed(): void
    {
        $this->actingAsRole('manager');

        $this->post('/zones', ['name' => "  zn-norm   Molyko\té  "])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('zones', ['name' => 'ZN-NORM MOLYKO É']);
        $this->assertDatabaseMissing('zones', ['name' => 'zn-norm Molyko é']);
    }

    public function test_store_rejects_a_name_that_differs_only_in_case(): void
    {
        $this->actingAsRole('manager');

        ZoneFactory::new()->create(['name' => 'ZCASE-DUP']);

        $this->post('/zones', ['name' => 'zcase-dup'])->assertSessionHasErrors(['name']);
        $this->post('/zones', ['name' => ' ZCase-Dup '])->assertSessionHasErrors(['name']);

        $this->assertSame(1, DB::table('zones')->whereRaw('upper(name) = ?', ['ZCASE-DUP'])->count());
    }

    public function test_store_rejects_a_name_colliding_with_a_legacy_mixed_case_row(): void
    {
        $this->actingAsRole('manager');

        // A row the normalise migration had to leave alone (written raw, bypassing the model).
        DB::table('zones')->insert(['branch_id' => DB::table('branches')->value('id'), 'name' => 'Zlegacy-Mixed']);

        $this->post('/zones', ['name' => 'ZLEGACY-MIXED'])->assertSessionHasErrors(['name']);
    }

    public function test_update_rejects_a_name_that_differs_only_in_case_from_another_zone(): void
    {
        $this->actingAsRole('manager');

        ZoneFactory::new()->create(['name' => 'ZUPD-TAKEN']);
        $zone = ZoneFactory::new()->create(['name' => 'ZUPD-MINE']);

        $this->patch("/zones/{$zone->uuid}", ['name' => 'zupd-taken'])->assertSessionHasErrors(['name']);

        $this->assertSame('ZUPD-MINE', $zone->fresh()->name);
    }

    public function test_update_to_its_own_name_in_another_case_is_allowed(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create(['name' => 'ZSELF-CASE']);

        $this->patch("/zones/{$zone->uuid}", ['name' => 'zself-case'])->assertSessionHasNoErrors()->assertRedirect('/zones');

        $this->assertSame('ZSELF-CASE', $zone->fresh()->name);
    }
}
