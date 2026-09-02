<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Exports\CustomerRecordExport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerRecordExportService;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\ComplaintFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\PaymentVerificationFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * "Export full record" (docs/plans/customer-record-export.md) — the single
 * downloadable PDF / multi-sheet XLSX bundling everything CNCMS holds about
 * one customer. Same harness as CustomerTest: real `tenantswecom` schema,
 * DatabaseTransactions rollback, the seeded owner's role flipped per test.
 */
class CustomerRecordExportTest extends TestCase
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
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function customer(array $overrides = []): Customer
    {
        $zone = ZoneFactory::new()->create();

        return CustomerFactory::new()->create(array_merge([
            'zone_id' => $zone->id,
            'name' => 'CREX Test Customer',
            'bill' => 2500,
            'status' => 'active',
            'phone' => '677000222',
        ], $overrides));
    }

    /**
     * A customer with at least one row in every gathered section.
     */
    private function customerWithFullHistory(): Customer
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $customer = $this->customer();

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 7654,
            'verification_status' => 'verified',
        ]);
        PaymentVerificationFactory::new()->approved()->create([
            'payment_id' => $payment->id,
            'momo_ref' => 'MOMOREF123',
            'verified_by' => $user->id,
        ]);

        ManuscriptFactory::new()->forPeriod(now()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'total_arrears' => 4321,
        ]);

        ArrearsAdjustmentFactory::new()->requestedBy($user->id)->create([
            'customer_id' => $customer->id,
            'amount' => '1500.00',
        ]);

        Message::query()->create([
            'customer_id' => $customer->id,
            'content' => 'Your bill is due.',
            'status' => 'sent',
            'type' => 'bill_reminder',
        ]);

        ComplaintFactory::new()->submittedBy($user->id)->create([
            'category' => 'customer',
            'customer_id' => $customer->id,
            'title' => 'Signal keeps dropping',
        ]);

        // A status change so the derived status_history section is non-empty.
        $customer->update(['status' => 'disconnected', 'status_reason' => 'non_payment']);

        return $customer->fresh();
    }

    // -----------------------------------------------------------------
    // PDF
    // -----------------------------------------------------------------

    public function test_pdf_download_returns_a_pdf_with_the_right_filename(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('super');

        $response = $this->get("/customers/{$customer->uuid}/record-export/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString(
            'customer-crex-test-customer-'.substr($customer->uuid, 0, 8).'-record.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_pdf_body_contains_the_customer_name_and_billing_figures(): void
    {
        $customer = $this->customerWithFullHistory();
        $this->actingAsRole('super');

        // dompdf compresses text streams, so assert against the rendered
        // blade (fed the identical gather() payload) rather than the binary.
        $html = view('pdf.customer-record', [
            'data' => app(CustomerRecordExportService::class)->gather($customer),
            'company' => Company::cached(),
        ])->render();

        $this->assertStringContainsString('CREX Test Customer', $html);
        $this->assertStringContainsString('7,654.00', $html);   // the payment
        $this->assertStringContainsString('4,321.00', $html);   // manuscript arrears
    }

    public function test_every_section_is_present_for_a_customer_with_full_history(): void
    {
        $customer = $this->customerWithFullHistory();

        $html = view('pdf.customer-record', [
            'data' => app(CustomerRecordExportService::class)->gather($customer),
            'company' => Company::cached(),
        ])->render();

        foreach ([
            'Profile',
            'Payments (1)',
            'Manuscript History (1)',
            'Arrears Adjustments (1)',
            'Status History (1)',
            'Messages (1)',
            'Complaints (1)',
            'Audit Trail',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "section [{$heading}] missing from the export");
        }

        // Spot-check real values from three different sections.
        $this->assertStringContainsString('MOMOREF123', $html);
        $this->assertStringContainsString('Signal keeps dropping', $html);
        $this->assertStringContainsString('Your bill is due.', $html);
    }

    public function test_a_customer_with_zero_history_exports_without_error(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('admin');

        $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertOk();
        $this->get("/customers/{$customer->uuid}/record-export/xlsx")->assertOk();

        $data = app(CustomerRecordExportService::class)->gather($customer);
        $this->assertSame([], $data['payments']);
        $this->assertSame([], $data['manuscripts']);
        $this->assertSame([], $data['complaints']);
    }

    // -----------------------------------------------------------------
    // XLSX
    // -----------------------------------------------------------------

    public function test_xlsx_download_returns_the_spreadsheet_content_type_and_filename(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('super');

        $response = $this->get("/customers/{$customer->uuid}/record-export/xlsx");

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'customer-crex-test-customer-'.substr($customer->uuid, 0, 8).'-record.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertGreaterThan(0, $response->getFile()->getSize());
    }

    public function test_xlsx_has_one_sheet_per_section(): void
    {
        Excel::fake();

        $customer = $this->customerWithFullHistory();
        $this->actingAsRole('super');

        $this->get("/customers/{$customer->uuid}/record-export/xlsx")->assertOk();

        Excel::assertDownloaded(
            'customer-crex-test-customer-'.substr($customer->uuid, 0, 8).'-record.xlsx',
            function (CustomerRecordExport $export): bool {
                $titles = array_map(fn ($sheet) => $sheet->title(), $export->sheets());

                return $titles === [
                    'Profile', 'Payments', 'Manuscripts', 'Arrears Adjustments',
                    'Messages', 'Complaints', 'Audit Trail',
                ];
            },
        );
    }

    // -----------------------------------------------------------------
    // Archived (soft-deleted) customer
    // -----------------------------------------------------------------

    public function test_a_soft_deleted_customer_can_still_be_exported(): void
    {
        $customer = $this->customer();
        $customer->delete();
        $this->assertTrue($customer->fresh()->trashed());

        $this->actingAsRole('super');

        $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertOk();
        $this->get("/customers/{$customer->uuid}/record-export/xlsx")->assertOk();
    }

    // -----------------------------------------------------------------
    // Permission gate — customers.export_record (super + admin only)
    // -----------------------------------------------------------------

    public function test_super_and_admin_can_export(): void
    {
        $customer = $this->customer();

        foreach (['super', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertOk();
        }
    }

    public function test_manager_and_agent_are_forbidden(): void
    {
        $customer = $this->customer();

        foreach (['manager', 'agent'] as $role) {
            $this->actingAsRole($role);
            $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertStatus(403);
            $this->get("/customers/{$customer->uuid}/record-export/xlsx")->assertStatus(403);
        }
    }

    public function test_a_custom_role_without_the_permission_is_forbidden(): void
    {
        $customer = $this->customer();

        $role = Role::query()->create(['name' => 'crex-no-export', 'label' => 'No Export', 'is_system' => false]);
        $role->syncPermissions(['customers.view']);

        $this->actingAsRole('crex-no-export');

        $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertStatus(403);
    }

    // Two separate methods (not one switching role mid-test): Route::
    // getController() caches the resolved CustomerController instance — and
    // its constructor-injected TenantContext — for the rest of a test
    // method, so a second GET to the same URI would reuse the first role's
    // context. Same artifact ReportTest documents at length.
    public function test_the_show_payload_carries_can_export_record_for_super(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('super');
        $this->get("/customers/{$customer->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customer.can_export_record', true));
    }

    public function test_the_show_payload_denies_can_export_record_for_manager(): void
    {
        $customer = $this->customer();

        $this->actingAsRole('manager');
        $this->get("/customers/{$customer->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customer.can_export_record', false));
    }

    // -----------------------------------------------------------------
    // Throttle — throttle:exports (10/min/user)
    // -----------------------------------------------------------------

    public function test_the_export_route_is_rate_limited(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('super');

        for ($i = 0; $i < 10; $i++) {
            $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertOk();
        }

        $this->get("/customers/{$customer->uuid}/record-export/pdf")->assertStatus(429);
    }
}
