<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\BusinessTimezone;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * One definition of the area's arrears and customer counts for the web
 * dashboard and the phone (owner, 2026-11-02: Home showed ~1.2M where the
 * web showed ~700k) — App\Services\AreaSummaryService.
 */
class AreaSummaryTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function billed(int $zoneId, string $status, int $arrears): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => $zoneId, 'status' => $status, 'bill' => 2500]);
        ManuscriptFactory::new()->create(['customer_id' => $customer->id, 'period' => BusinessTimezone::currentPeriod(), 'total_arrears' => $arrears]);

        return $customer;
    }

    public function test_the_phone_gets_the_servers_figures_for_its_own_zones_and_drops_customers_that_left(): void
    {
        $mine = ZoneFactory::new()->create();
        $other = ZoneFactory::new()->create();
        $active = $this->billed($mine->id, 'active', 5000);
        $this->billed($mine->id, 'active', 0);
        $this->billed($mine->id, 'disconnected', 7000);
        $elsewhere = $this->billed($other->id, 'active', 9000);

        $token = $this->tokenForAgentCovering($mine);
        $pull = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull')->assertOk();

        // Active customers' arrears only; the frozen debt on its own line; this agent's zone only.
        $pull->assertJsonPath('summary.arrears', '5000.00')
            ->assertJsonPath('summary.owing_customers', 1)
            ->assertJsonPath('summary.frozen_arrears', '7000.00')
            ->assertJsonPath('summary.active_customers', 2)
            ->assertJsonPath('summary.disconnected_customers', 1)
            ->assertJsonPath('summary.total_customers', 3);

        $inScope = $pull->json('changes.customers.in_scope');
        $this->assertContains($active->uuid, $inScope);
        $this->assertNotContains($elsewhere->uuid, $inScope);
    }

    public function test_the_web_dashboard_shows_the_same_arrears_as_the_phone(): void
    {
        $zone = ZoneFactory::new()->create();
        $this->billed($zone->id, 'active', 5000);
        $this->billed($zone->id, 'suspended', 3000);

        $token = $this->tokenForRole('manager');
        $phone = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull')->assertOk()->json('summary');

        $this->actingAs(User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail());
        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('stats.total_arrears', $phone['arrears'])
            ->where('stats.active_customers', $phone['active_customers']));
    }
}
