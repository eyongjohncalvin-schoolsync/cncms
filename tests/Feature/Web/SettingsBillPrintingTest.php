<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Settings — Bill Printing (this cycle's design review). Same session-auth
 * Inertia pattern as SettingsNotificationsTest/SettingsTest: reuse the real
 * seeded owner (kelvin@shalomtech.dev), flipping their tenant_users role
 * per test.
 */
class SettingsBillPrintingTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        Company::forgetCache();
    }

    protected function tearDown(): void
    {
        Company::forgetCache();

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    public function test_page_renders_with_the_companys_current_settings(): void
    {
        CompanyFactory::new()->create(['bill_template' => 'modern', 'bills_per_page' => 2]);
        Company::forgetCache();

        $this->actingAsRole('admin');

        $response = $this->get('/settings/bill-printing');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/BillPrinting')
                ->where('bill_template', 'modern')
                ->where('bills_per_page', 2)
                ->where('templates', Company::BILL_TEMPLATES)
                ->where('bills_per_page_options', Company::BILLS_PER_PAGE_OPTIONS));
    }

    public function test_admin_can_update_bill_template_and_density(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        $response = $this->patch('/settings/bill-printing', [
            'bill_template' => 'compact',
            'bills_per_page' => 4,
        ]);

        $response->assertRedirect(route('settings.bill-printing.edit'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('companies', [
            'bill_template' => 'compact',
            'bills_per_page' => 4,
        ]);
    }

    public function test_invalid_template_is_rejected(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        $response = $this->patch('/settings/bill-printing', [
            'bill_template' => 'not-a-real-template',
            'bills_per_page' => 1,
        ]);

        $response->assertSessionHasErrors('bill_template');
    }

    public function test_invalid_density_is_rejected(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        $response = $this->patch('/settings/bill-printing', [
            'bill_template' => 'classic',
            'bills_per_page' => 3,
        ]);

        $response->assertSessionHasErrors('bills_per_page');
    }

    /**
     * CompanyPolicy::update() is super/admin-only (CompanyPolicy::view() —
     * used for the GET edit page — is unrestricted for anyone with tenant
     * access, so this specifically covers the PATCH update action, matching
     * SettingsNotificationsTest's/SettingsCompanyTest's identical pattern
     * for the sibling single-row settings pages).
     */
    public function test_manager_agent_and_worker_cannot_update_bill_printing_settings(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        foreach (['manager', 'agent', 'worker'] as $role) {
            $this->actingAsRole($role);

            $response = $this->patch('/settings/bill-printing', [
                'bill_template' => 'compact',
                'bills_per_page' => 2,
            ]);

            $response->assertStatus(403);
        }
    }

    public function test_manager_agent_and_worker_can_still_view_the_settings_page(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        foreach (['manager', 'agent', 'worker'] as $role) {
            $this->actingAsRole($role);

            $this->get('/settings/bill-printing')->assertOk();
        }
    }

    public function test_preview_returns_an_inline_pdf_for_each_template(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        foreach (Company::BILL_TEMPLATES as $template) {
            $response = $this->get("/settings/bill-printing/preview/{$template}");

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        }
    }

    /**
     * No customers exist yet in this test's fresh transaction, so this
     * exercises ManuscriptService::sampleBillData()'s placeholder-customer
     * fallback path — the preview must still render, not error, when the
     * tenant has no real customers to preview with yet.
     */
    public function test_preview_still_renders_when_the_tenant_has_no_customers_yet(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        $response = $this->get('/settings/bill-printing/preview/classic');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_preview_uses_a_real_customer_when_one_exists(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()
            ->forPeriod(now()->format('Y-m'))
            ->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        $this->actingAsRole('admin');

        $response = $this->get('/settings/bill-printing/preview/modern');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_preview_rejects_an_unknown_template(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $this->actingAsRole('admin');

        $this->get('/settings/bill-printing/preview/not-a-real-template')->assertStatus(404);
    }

    /**
     * The end-to-end proof that saving a template selection actually
     * changes what CustomerController::printBill() renders next — not just
     * that the DB column was written. Uses the real single-bill print route
     * (customers/{customer}/bill/print) rather than inspecting PDF
     * internals.
     */
    public function test_selecting_a_template_is_reflected_in_the_next_single_bill_print(): void
    {
        CompanyFactory::new()->create();
        Company::forgetCache();

        $zone = ZoneFactory::new()->create();
        // ->active(): CustomerController::printBill() now refuses a
        // non-active customer (ManuscriptService::billData()), and the
        // factory default status is random (~20% 'disconnected').
        $customer = CustomerFactory::new()->active()->create(['zone_id' => $zone->id, 'bill' => 2500]);
        ManuscriptFactory::new()
            ->forPeriod(now()->format('Y-m'))
            ->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        $this->actingAsRole('admin');

        $this->patch('/settings/bill-printing', ['bill_template' => 'modern', 'bills_per_page' => 1])
            ->assertRedirect(route('settings.bill-printing.edit'));

        $this->assertDatabaseHas('companies', ['bill_template' => 'modern']);

        $response = $this->get("/customers/{$customer->uuid}/bill/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/bill-printing')->assertRedirect('/login');
    }
}
