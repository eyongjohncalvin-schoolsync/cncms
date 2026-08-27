<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Services\ManuscriptService;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\View;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Covers the three new bill templates (resources/views/pdf/bills/
 * {classic,compact,modern}.blade.php) end-to-end through the real
 * /api/v1/bills/{uuid}/print endpoint, and the reconnection-fine regression
 * this cycle fixed: the old resources/views/pdf/bill.blade.php hardcoded
 * "2000 FCFA" even though Company::reconnection_fine became
 * admin-configurable earlier this cycle — all three new templates (and the
 * old view, kept for safety) now read Company::cached()->reconnection_fine.
 *
 * Same "don't assert on PDF binary internals" stance as BillPrintTest: HTTP
 * assertions only check status/content-type — dompdf's PDF stream output
 * may or may not be compressed, so grepping the binary response body for
 * text is not a reliable test strategy. The reconnection-fine regression
 * test instead renders the Blade partial directly via view()->render()
 * (bypassing dompdf entirely), asserting on the actual HTML text dompdf
 * would go on to rasterize.
 */
class BillTemplatesTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customerWithManuscript(): Customer
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);

        ManuscriptFactory::new()
            ->forPeriod(now()->format('Y-m'))
            ->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        return $customer;
    }

    public function test_each_bill_template_renders_without_error_for_a_real_customer(): void
    {
        $company = CompanyFactory::new()->create();
        $customer = $this->customerWithManuscript();
        $token = $this->tokenForRole('manager');

        foreach (Company::BILL_TEMPLATES as $template) {
            $company->update(['bill_template' => $template]);
            Company::forgetCache();

            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->get("/api/v1/bills/{$customer->uuid}/print");

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_reconnection_fine_shown_on_bill_matches_configured_value_not_hardcoded(): void
    {
        CompanyFactory::new()->create(['reconnection_fine' => '13750.00']);
        $customer = $this->customerWithManuscript();

        Company::forgetCache();

        $data = app(ManuscriptService::class)->billData($customer, null);

        foreach (Company::BILL_TEMPLATES as $template) {
            $html = View::make('pdf.bills.'.$template, $data)->render();

            $this->assertStringContainsString(
                '13,750.00',
                $html,
                "Template [{$template}] does not display the configured reconnection fine."
            );
            $this->assertStringNotContainsString('2000 FCFA', $html);
            $this->assertStringNotContainsString('2,000.00 FCFA', $html);
        }
    }

    public function test_reconnection_fine_regression_also_holds_for_the_old_bill_view(): void
    {
        CompanyFactory::new()->create(['reconnection_fine' => '9999.00']);
        $customer = $this->customerWithManuscript();

        Company::forgetCache();

        $data = app(ManuscriptService::class)->billData($customer, null);
        $html = View::make('pdf.bill', $data)->render();

        $this->assertStringContainsString('9,999.00', $html);
        $this->assertStringNotContainsString('2000 FCFA', $html);
    }
}
