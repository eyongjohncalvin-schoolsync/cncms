<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Service;
use App\Models\ServiceVariant;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * GET /api/v1/services (Api\ServiceController) — the mobile app's tick-
 * list for the customer add/edit form (services.md sections 6-8), plus the
 * `services` field CustomerResource now carries on every customer
 * GET/POST/PATCH response.
 */
class ServiceCatalogueTest extends TestCase
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

    public function test_index_returns_active_services_with_their_active_variants_only(): void
    {
        $tv = $this->tvService();
        ServiceVariant::query()->create(['service_id' => $tv->id, 'name' => 'News Channel', 'price' => 2000]);
        ServiceVariant::query()->create(['service_id' => $tv->id, 'name' => 'Old Channel', 'price' => 1000, 'active' => false]);
        Service::query()->create(['name' => 'Retired Service', 'price' => 500, 'active' => false]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/services');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('TV Service'));
        $this->assertFalse($names->contains('Retired Service'));

        $tvEntry = collect($response->json('data'))->firstWhere('name', 'TV Service');
        $variantNames = collect($tvEntry['variants'])->pluck('name');
        $this->assertTrue($variantNames->contains('News Channel'));
        $this->assertFalse($variantNames->contains('Old Channel'));
    }

    public function test_customer_show_carries_services(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 5000, 'status' => 'active']);
        $tv = $this->tvService();
        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/customers/{$customer->uuid}");

        $response->assertOk();
        $services = $response->json('data.services');
        $this->assertCount(1, $services);
        $this->assertSame($tv->uuid, $services[0]['service_uuid']);
        $this->assertSame('5000.00', $services[0]['price']);
    }

    public function test_customer_store_via_api_accepts_a_services_payload_and_returns_it(): void
    {
        $zone = ZoneFactory::new()->create();
        $tv = $this->tvService();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/customers', [
            'zone_uuid' => $zone->uuid,
            'name' => 'API SERVICES CUSTOMER',
            'phone' => '677999000',
            'services' => [
                ['service_uuid' => $tv->uuid, 'price' => '4500'],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame('4500.00', $response->json('data.bill'));
        $this->assertCount(1, $response->json('data.services'));
    }
}
