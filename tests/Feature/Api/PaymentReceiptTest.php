<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Services\PaymentReceiptService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 2 of docs/plans/payment-receipts-and-whatsapp.md — the token-auth
 * mobile receipt endpoints. Same harness as Api/PaymentTest.
 */
class PaymentReceiptTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
    }

    private function customer(): Customer
    {
        $zone = ZoneFactory::new()->create();

        return CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'status' => 'active',
            'phone' => '677000111',
        ]);
    }

    private function paymentWithReceipt(): array
    {
        $payment = PaymentFactory::new()->create(['customer_id' => $this->customer()->id, 'amount' => 2500]);
        $receipt = app(PaymentReceiptService::class)->issueFor($payment);

        return [$payment, $receipt];
    }

    public function test_show_returns_the_receipt_for_a_payment(): void
    {
        [$payment, $receipt] = $this->paymentWithReceipt();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $receipt->uuid)
            ->assertJsonPath('data.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.payment_uuid', $payment->uuid)
            ->assertJsonStructure(['data' => ['uuid', 'receipt_number', 'status', 'issued_at', 'amount', 'pdf_url', 'shared_pdf_url']]);
    }

    public function test_show_404s_when_the_payment_has_no_receipt(): void
    {
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $this->customer()->id]);

        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt")
            ->assertStatus(404);
    }

    public function test_show_requires_a_token(): void
    {
        [$payment] = $this->paymentWithReceipt();

        $this->getJson("/api/v1/payments/{$payment->uuid}/receipt")->assertStatus(401);
    }

    public function test_pdf_download_streams_a_pdf(): void
    {
        [, $receipt] = $this->paymentWithReceipt();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/payment-receipts/{$receipt->uuid}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            "receipt-{$receipt->receipt_number}.pdf",
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_pdf_download_requires_a_token(): void
    {
        [, $receipt] = $this->paymentWithReceipt();

        $this->get("/api/v1/payment-receipts/{$receipt->uuid}/pdf")->assertStatus(401);
    }
}
