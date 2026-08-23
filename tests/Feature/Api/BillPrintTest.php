<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. Fixtures are created fresh via
 * ZoneFactory/CustomerFactory/ManuscriptFactory/CompanyFactory; none of the
 * real seeded rows are touched. We don't assert on PDF internals — dompdf's
 * binary output isn't meaningful to inspect here — just that the response
 * renders as a 200 application/pdf stream and that the role gate matches
 * business-rules.md section 3 / api-spec.md section 10's "Print bills" row
 * (super/admin/manager/agent YES, worker NO).
 */
class BillPrintTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        CompanyFactory::new()->create();
    }

    private function customerWithManuscript(?string $period = null): Customer
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        ManuscriptFactory::new()
            ->forPeriod($period ?? Carbon::now()->format('Y-m'))
            ->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        return $customer;
    }

    public function test_manager_can_print_a_bill_for_a_customer_with_a_manuscript(): void
    {
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/bills/{$customer->uuid}/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_agent_can_print_a_bill(): void
    {
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/bills/{$customer->uuid}/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_worker_cannot_print_a_bill(): void
    {
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/bills/{$customer->uuid}/print");

        $response->assertStatus(403);
    }

    public function test_print_accepts_an_explicit_period(): void
    {
        $customer = $this->customerWithManuscript('2026-06');
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/bills/{$customer->uuid}/print?period=2026-06");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_print_returns_not_found_when_no_manuscript_exists_for_the_period(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/bills/{$customer->uuid}/print");

        $response->assertStatus(404);
    }
}
