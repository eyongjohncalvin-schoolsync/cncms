<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Company;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles. Fixtures are created
 * fresh via CustomerFactory/ManuscriptFactory/CompanyFactory; none of the
 * real seeded rows are touched. We don't assert on PDF internals — just that
 * the register renders as a 200 application/pdf stream and that the role
 * gate matches api-spec.md section 10's "Export data" row (super/admin/
 * manager YES, agent NO).
 */
class ManuscriptExportTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        CompanyFactory::new()->create();
    }

    public function test_manager_can_export_the_manuscript_register_as_a_pdf(): void
    {
        $period = Carbon::now()->format('Y-m');

        foreach (range(1, 3) as $i) {
            $customer = CustomerFactory::new()->create();
            ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);
        }

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/manuscripts/export?period='.$period);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_super_can_export_the_manuscript_register(): void
    {
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/manuscripts/export?period='.$period);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Exercises Company::logoDataUri() through a real dompdf render of
     * resources/views/pdf/manuscript.blade.php's letterhead <img> markup —
     * mirrors BillPrintTest::test_bill_pdf_renders_with_a_company_logo.
     */
    public function test_manuscript_export_pdf_renders_with_a_company_logo(): void
    {
        Storage::fake('public');

        $company = Company::query()->first();
        $company->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))->toMediaCollection('logo');

        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/manuscripts/export?period='.$period);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_agent_cannot_export_the_manuscript_register(): void
    {
        $period = Carbon::now()->format('Y-m');
        $customer = CustomerFactory::new()->create();
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/manuscripts/export?period='.$period);

        $response->assertStatus(403);
    }
}
