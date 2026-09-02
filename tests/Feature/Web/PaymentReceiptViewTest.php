<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PaymentReceiptService;
use App\Support\PaymentReceiptLink;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 2 of docs/plans/payment-receipts-and-whatsapp.md — the web view /
 * download / manual-issue actions and the signed public PDF link.
 *
 * Same harness as tests/Feature/PaymentReceiptTest.php (Wave 1): real
 * `tenantswecom` schema, DatabaseTransactions rollback, the seeded owner's
 * role flipped per test. The signed-public-route action re-calls
 * tenancy()->initialize('swecom') which Stancl short-circuits when already
 * initialised to the same key (Tenancy::initialize, line 43) — so the
 * test's open tenant transaction is never purged.
 */
class PaymentReceiptViewTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
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
            'bill' => 2500,
            'status' => 'active',
            'phone' => '677000111',
        ], $overrides));
    }

    private function verifiedPaymentWithReceipt(array $customerOverrides = []): array
    {
        $customer = $this->customer($customerOverrides);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);
        $receipt = app(PaymentReceiptService::class)->issueFor($payment);

        return [$customer, $payment, $receipt];
    }

    // -----------------------------------------------------------------
    // Show payload
    // -----------------------------------------------------------------

    public function test_show_payload_carries_the_receipt(): void
    {
        [, $payment, $receipt] = $this->verifiedPaymentWithReceipt();

        $this->actingAsRole('manager');

        $this->get("/payments/{$payment->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Show')
                ->where('receipt.receipt_number', $receipt->receipt_number)
                ->where('receipt.status', 'issued')
                ->where('receipt.download_url', route('payment-receipts.pdf', $receipt))
                ->has('receipt.shared_url')
                ->where('can_issue_receipt', true));
    }

    public function test_show_payload_receipt_is_null_when_none_issued(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $this->get("/payments/{$payment->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('receipt', null));
    }

    // -----------------------------------------------------------------
    // Download
    // -----------------------------------------------------------------

    public function test_download_returns_a_pdf_with_the_receipt_number_filename(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $this->actingAsRole('manager');

        $response = $this->get(route('payment-receipts.pdf', $receipt));

        $response->assertOk();
        $this->assertStringContainsString('pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            "receipt-{$receipt->receipt_number}.pdf",
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_download_is_gated_on_payments_view(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        // A custom role with NO payments.view (all 5 system roles have it).
        $role = Role::query()->create(['name' => 'no-payments-view', 'label' => 'No Payments View', 'is_system' => false]);
        $role->syncPermissions(['customers.view']);

        $this->actingAsRole('no-payments-view');

        $this->get(route('payment-receipts.pdf', $receipt))->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Manual issue
    // -----------------------------------------------------------------

    public function test_manager_can_manually_issue_a_receipt_for_a_verified_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/issue")->assertRedirect();

        $this->assertDatabaseHas('payment_receipts', ['payment_id' => $payment->id, 'status' => 'issued']);
    }

    public function test_manual_issue_is_gated_on_payments_issue_receipt(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);

        // `agent` has payments.view but NOT payments.issue_receipt.
        $this->actingAsRole('agent');

        $this->post("/payments/{$payment->uuid}/receipt/issue")->assertStatus(403);
        $this->assertDatabaseMissing('payment_receipts', ['payment_id' => $payment->id]);
    }

    public function test_manual_issue_refuses_an_unverified_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/issue")->assertRedirect();
        $this->assertDatabaseMissing('payment_receipts', ['payment_id' => $payment->id]);
    }

    // -----------------------------------------------------------------
    // Signed public PDF link
    // -----------------------------------------------------------------

    public function test_signed_public_link_streams_the_pdf_with_no_auth(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $url = PaymentReceiptLink::shared($receipt);

        // No actingAs — a WhatsApp recipient has no session.
        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringContainsString('pdf', (string) $response->headers->get('content-type'));
    }

    public function test_signed_public_link_aborts_without_a_signature(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $this->get("/payment-receipts/{$receipt->uuid}/pdf/shared?tenant=swecom")->assertStatus(403);
    }

    public function test_signed_public_link_aborts_when_tampered(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $tampered = PaymentReceiptLink::shared($receipt).'&extra=1';

        $this->get($tampered)->assertStatus(403);
    }

    public function test_signed_public_link_aborts_when_expired(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $expired = URL::temporarySignedRoute(
            'payment-receipts.pdf.shared',
            now()->subMinute(),
            ['receiptUuid' => $receipt->uuid, 'tenant' => 'swecom'],
        );

        $this->get($expired)->assertStatus(403);
    }

    public function test_signed_public_link_404s_a_voided_receipt(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();
        app(PaymentReceiptService::class)->void($receipt);

        $this->get(PaymentReceiptLink::shared($receipt->fresh()))->assertStatus(404);
    }
}
