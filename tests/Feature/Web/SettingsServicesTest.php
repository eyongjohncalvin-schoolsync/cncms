<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings -> Services (services.md sections 6-7) — the catalogue CRUD +
 * options ("variants") sub-CRUD + "apply price to all subscribers", all
 * gated by the single `services.manage` permission (ServicePolicy).
 */
class SettingsServicesTest extends TestCase
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

    private function tvService(): Service
    {
        return Service::query()->where('slug', 'tv')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_admin_can_view_the_catalogue(): void
    {
        $this->actingAsRole('admin');

        $this->get('/settings/services')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Services')
                ->has('services', 4));
    }

    public function test_manager_is_forbidden(): void
    {
        $this->actingAsRole('manager');

        $this->get('/settings/services')->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Service CRUD
    // -----------------------------------------------------------------

    public function test_store_creates_a_service(): void
    {
        $this->actingAsRole('admin');

        $response = $this->post('/settings/services', [
            'name' => 'Premium Support',
            'price' => '1500',
        ]);

        $response->assertRedirect('/settings/services');
        $this->assertDatabaseHas('services', ['name' => 'Premium Support', 'price' => 1500]);
    }

    public function test_store_rejects_a_duplicate_name_case_insensitively(): void
    {
        $this->actingAsRole('admin');

        $response = $this->post('/settings/services', [
            'name' => 'tv service', // seeded row is "TV Service"
            'price' => '1000',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_setting_is_default_clears_the_previous_default(): void
    {
        $this->actingAsRole('admin');
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();

        $this->patch("/settings/services/{$internet->uuid}", [
            'is_default' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('services', ['slug' => 'internet', 'is_default' => true]);
        $this->assertDatabaseHas('services', ['slug' => 'tv', 'is_default' => false]);
    }

    public function test_destroy_is_blocked_while_a_customer_subscribes(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $tv = $this->tvService();
        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $response = $this->delete("/settings/services/{$tv->uuid}");

        $response->assertSessionHasErrors('service');
        $this->assertDatabaseHas('services', ['uuid' => $tv->uuid]);
    }

    public function test_destroy_succeeds_for_a_service_with_no_subscribers(): void
    {
        $this->actingAsRole('admin');
        $service = Service::query()->create(['name' => 'Unused Service', 'price' => 500]);

        $this->delete("/settings/services/{$service->uuid}")->assertRedirect();

        $this->assertDatabaseMissing('services', ['uuid' => $service->uuid]);
    }

    public function test_apply_price_reprices_every_current_subscriber(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $tv = $this->tvService();
        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $tv->update(['price' => 6000]);

        $this->post("/settings/services/{$tv->uuid}/apply-price")->assertRedirect();

        $this->assertSame('6000.00', (string) $customer->fresh()->bill);
    }

    // -----------------------------------------------------------------
    // Variant ("option") sub-CRUD
    // -----------------------------------------------------------------

    public function test_store_variant_creates_an_option_under_the_service(): void
    {
        $this->actingAsRole('admin');
        $tv = $this->tvService();

        $response = $this->post("/settings/services/{$tv->uuid}/variants", [
            'name' => 'Local News Channel',
            'price' => '2000',
        ]);

        $response->assertRedirect('/settings/services');
        $this->assertDatabaseHas('service_variants', ['service_id' => $tv->id, 'name' => 'Local News Channel']);
    }

    public function test_a_variant_from_a_different_service_404s_under_the_wrong_service_url(): void
    {
        $this->actingAsRole('admin');
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();
        $variant = ServiceVariant::query()->create(['service_id' => $internet->id, 'name' => '20 Mbps', 'price' => 4000]);

        $this->patch("/settings/services/{$tv->uuid}/variants/{$variant->uuid}", ['price' => '5000'])
            ->assertStatus(404);
    }

    public function test_destroy_variant_is_blocked_while_a_customer_holds_it(): void
    {
        $this->actingAsRole('admin');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 7000, 'status' => 'active']);
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create(['service_id' => $tv->id, 'name' => 'News Channel', 'price' => 2000]);
        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);
        $customer->subscriptions()->create(['service_id' => $tv->id, 'service_variant_id' => $channel->id, 'price' => 2000]);

        $this->delete("/settings/services/{$tv->uuid}/variants/{$channel->uuid}")
            ->assertSessionHasErrors('variant');
    }
}
