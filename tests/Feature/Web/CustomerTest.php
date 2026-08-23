<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Session-cookie web CRUD (App\Http\Controllers\CustomerController),
 * distinct from the Sanctum bearer-token API coverage in
 * tests/Feature/Api/CustomerTest.php. Same InteractsWithTenantRoles
 * tenant/transaction setup, but authenticates via actingAs() (session auth)
 * instead of tokenForRole() (which issues a Sanctum token).
 */
class CustomerTest extends TestCase
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

    public function test_index_renders_with_customer_data(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->get('/customers');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->has('zones')
                ->has('filters'));
    }

    public function test_index_filters_by_zone_and_status(): void
    {
        $this->actingAsRole('super');

        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'active']);
        CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'disconnected']);
        CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'status' => 'active']);

        $response = $this->get("/customers?zone_uuid={$zoneA->uuid}&status=active");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.zone_uuid', $zoneA->uuid)
                ->where('customers.data.0.status', 'active'));
    }

    public function test_create_page_renders(): void
    {
        $this->actingAsRole('manager');

        $response = $this->get('/customers/create');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Create')->has('zones'));
    }

    public function test_store_creates_a_customer_and_redirects_with_a_flash_message(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'JANE DOE',
            'bill' => 2500,
            'level' => 'normal',
            'status' => 'active',
        ]);

        $response->assertRedirect('/customers');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('customers', ['name' => 'JANE DOE', 'zone_id' => $zone->id]);
    }

    public function test_store_fails_validation_for_invalid_level(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'JOHN DOE',
            'bill' => 2500,
            'level' => 'not-a-real-level',
        ]);

        $response->assertSessionHasErrors(['level']);
        $this->assertDatabaseMissing('customers', ['name' => 'JOHN DOE']);
    }

    public function test_show_renders_customer_detail(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->get("/customers/{$customer->uuid}");

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Show')
                ->where('customer.uuid', $customer->uuid)
                ->has('customer.recent_payments'));
    }

    public function test_manager_can_edit_and_update_a_customer(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'status' => 'active']);

        $this->get("/customers/{$customer->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Edit')->where('customer.uuid', $customer->uuid));

        $response = $this->patch("/customers/{$customer->uuid}", [
            'status' => 'disconnected',
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'disconnected']);
    }

    public function test_super_can_delete_a_customer(): void
    {
        $this->actingAsRole('super');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $response = $this->delete("/customers/{$customer->uuid}");

        $response->assertRedirect('/customers');
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_agent_cannot_create_a_customer(): void
    {
        $this->actingAsRole('agent');

        $this->get('/customers/create')->assertForbidden();

        $zone = ZoneFactory::new()->create();

        $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'SHOULD NOT EXIST',
            'bill' => 2500,
        ])->assertForbidden();

        $this->assertDatabaseMissing('customers', ['name' => 'SHOULD NOT EXIST']);
    }

    public function test_agent_cannot_edit_a_customer(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->get("/customers/{$customer->uuid}/edit")->assertForbidden();
    }

    public function test_agent_can_still_view_customers(): void
    {
        $this->actingAsRole('agent');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        $this->get('/customers')->assertOk();
        $this->get("/customers/{$customer->uuid}")->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }
}
