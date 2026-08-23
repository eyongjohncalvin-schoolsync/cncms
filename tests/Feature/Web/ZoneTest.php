<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

        $response = $this->patch("/zones/{$zone->uuid}", ['town' => 'UPDATED TOWN']);

        $response->assertRedirect('/zones');
        $this->assertDatabaseHas('zones', ['id' => $zone->id, 'town' => 'UPDATED TOWN']);
    }

    public function test_super_can_delete_a_zone(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();

        $response = $this->delete("/zones/{$zone->uuid}");

        $response->assertRedirect('/zones');
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
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
}
