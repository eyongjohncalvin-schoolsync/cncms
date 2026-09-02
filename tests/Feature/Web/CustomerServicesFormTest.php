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
 * services.md section 6 — the customer add/edit form's `services[]` payload,
 * end to end through StoreCustomerRequest/UpdateCustomerRequest and the
 * Create/Edit/Show Inertia props (service_catalogue / customer.services).
 * CustomerSubscriptionServiceTest already covers the write engine itself in
 * isolation; this proves the HTTP contract on top of it.
 */
class CustomerServicesFormTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function tvService(): Service
    {
        return Service::query()->where('slug', 'tv')->firstOrFail();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    public function test_create_page_carries_the_service_catalogue(): void
    {
        $this->actingAsRole('manager');

        $this->get('/customers/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Create')
                ->has('service_catalogue', 4) // tv/internet/vod/satellite-hosting, all seeded active
                ->has('service_catalogue.0.uuid')
                ->has('service_catalogue.0.name')
                ->has('service_catalogue.0.variants'));
    }

    public function test_store_with_a_services_payload_computes_the_bill(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $tv = $this->tvService();
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'SERVICES FORM CUSTOMER',
            'phone' => '677555000',
            'status' => 'active',
            'services' => [
                ['service_uuid' => $tv->uuid, 'price' => '5000'],
                ['service_uuid' => $internet->uuid, 'price' => '3000'],
            ],
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', ['name' => 'SERVICES FORM CUSTOMER', 'bill' => 8000]);
    }

    public function test_store_without_bill_or_services_is_rejected(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();

        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'NO BILL NO SERVICES',
            'phone' => '677555111',
        ]);

        $response->assertSessionHasErrors(['bill', 'services']);
    }

    public function test_store_with_a_variant_requires_its_base_service_and_reports_the_service_layer_error(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $tv = $this->tvService();
        $channel = ServiceVariant::query()->create(['service_id' => $tv->id, 'name' => 'News Channel', 'price' => 2000]);

        // No plain TV row alongside the variant — CustomerSubscriptionService
        // rejects this with a ValidationException, which must surface as a
        // normal redirect-with-errors, not a 500.
        $response = $this->post('/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'VARIANT WITHOUT BASE',
            'phone' => '677555222',
            'services' => [
                ['service_uuid' => $tv->uuid, 'service_variant_uuid' => $channel->uuid, 'price' => '2000'],
            ],
        ]);

        $response->assertSessionHasErrors(['services']);
        $this->assertDatabaseMissing('customers', ['name' => 'VARIANT WITHOUT BASE']);
    }

    public function test_update_with_a_new_services_payload_replaces_the_previous_set(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $internet = Service::query()->where('slug', 'internet')->firstOrFail();

        // The backfill/legacy path already gave this factory-created customer
        // no subscription rows at all (factories bypass the service layer) —
        // simulate a real pre-services customer by leaving it alone and
        // going straight to an edit that supplies the full new set.
        $response = $this->patch("/customers/{$customer->uuid}", [
            'services' => [
                ['service_uuid' => $internet->uuid, 'price' => '4500'],
            ],
        ]);

        $response->assertRedirect('/customers');
        $this->assertSame('4500.00', (string) $customer->fresh()->bill);
    }

    public function test_edit_page_prefills_the_customers_current_services(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $tv = $this->tvService();

        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $this->get("/customers/{$customer->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Edit')
                ->has('customer.services', 1)
                ->where('customer.services.0.service_uuid', $tv->uuid)
                ->where('customer.services.0.price', '5000.00'));
    }

    public function test_show_page_carries_the_customers_services(): void
    {
        $this->actingAsRole('manager');

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $tv = $this->tvService();

        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $this->get("/customers/{$customer->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Show')
                ->has('customer.services', 1));
    }
}
