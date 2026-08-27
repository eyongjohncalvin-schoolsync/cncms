<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Manuscript;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via
 * ZoneFactory/CustomerFactory/ManuscriptFactory/PaymentFactory; none of the
 * real seeded rows are touched.
 */
class ManuscriptTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_index_lists_manuscripts_for_the_current_period_with_a_summary(): void
    {
        $period = Carbon::now()->format('Y-m');

        // This runs against the real tenant schema, which may already carry
        // manuscripts for the current period (e.g. real imported/calculated
        // billing data) — so assert the count grew by exactly one rather
        // than assuming a pristine period, matching how the other tests in
        // this file scope/filter around ambient data.
        $baseline = Manuscript::query()->where('period', $period)->count();

        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/manuscripts');

        $response->assertOk()
            ->assertJsonPath('period', $period)
            ->assertJsonPath('summary.total_customers', $baseline + 1)
            ->assertJsonStructure([
                'data' => [['customer_uuid', 'customer_name', 'zone_name', 'bill', 'total_arrears', 'credit', 'total_bill', 'period']],
                'summary' => ['total_customers', 'total_bill', 'total_arrears', 'total_credit', 'total_collected', 'collection_rate'],
                'meta',
            ]);
    }

    public function test_index_filters_by_zone_and_status(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'status' => 'disconnected']);
        $customerC = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'status' => 'active']);

        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customerA->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customerB->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customerC->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/manuscripts?zone_uuid={$zoneA->uuid}&status=active");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($customerA->uuid, $data[0]['customer_uuid']);
    }

    public function test_index_counts_verified_payments_recorded_this_period_as_collected(): void
    {
        $period = Carbon::now()->format('Y-m');
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 9999, 'verification_status' => 'pending']);

        $token = $this->tokenForRole('super');

        // Scoped to this customer's own (fresh, factory-created) zone —
        // the real swecom tenant this test runs against may already carry
        // its own verified payments recorded this calendar month
        // (ambient production data), which an unfiltered query would sum
        // into total_collected alongside this test's own payment. Filtering
        // by zone_uuid, like test_index_filters_by_zone_and_status() above,
        // isolates the assertion to just this test's fixtures.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/manuscripts?zone_uuid={$zone->uuid}");

        $response->assertOk()->assertJsonPath('summary.total_collected', '2500.00');
    }

    public function test_index_rejects_an_invalid_period_format(): void
    {
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/manuscripts?period=not-a-period');

        $response->assertStatus(422)->assertJsonValidationErrors(['period']);
    }

    public function test_history_returns_a_customers_manuscripts_newest_period_first(): void
    {
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod('2026-06')->create(['customer_id' => $customer->id]);
        ManuscriptFactory::new()->forPeriod('2026-08')->create(['customer_id' => $customer->id]);
        ManuscriptFactory::new()->forPeriod('2026-07')->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customer->uuid}/manuscripts");

        $response->assertOk();
        $periods = collect($response->json('data'))->pluck('period')->all();
        $this->assertSame(['2026-08', '2026-07', '2026-06'], $periods);
    }

    public function test_agent_can_view_manuscripts(): void
    {
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/manuscripts');

        $response->assertOk();
    }
}
