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
 * Wave 3 of docs/plans/payment-receipts-and-whatsapp.md — the token-auth
 * mobile "receipt WhatsApp message" endpoint. Same harness as
 * Api/PaymentReceiptTest.
 */
class ReceiptWhatsAppTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
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

    private function paymentWithReceipt(array $customerOverrides = []): array
    {
        $payment = PaymentFactory::new()->create([
            'customer_id' => $this->customer($customerOverrides)->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);
        $receipt = app(PaymentReceiptService::class)->issueFor($payment);

        return [$payment, $receipt];
    }

    public function test_returns_phone_and_message_and_records_the_send(): void
    {
        [$payment, $receipt] = $this->paymentWithReceipt();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt/whatsapp-message");

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.has_phone', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.phone', '237677000111')
            ->assertJsonStructure(['data' => ['has_phone', 'available', 'reason', 'phone', 'message']]);

        $this->assertStringContainsString($receipt->receipt_number, $response->json('data.message'));
        $this->assertStringContainsString('/pdf/shared', $response->json('data.message'));

        $log = $receipt->fresh()->sent_log;
        $this->assertCount(1, $log);
        $this->assertSame('whatsapp_manual', $log[0]['channel']);
        $this->assertSame('237677000111', $log[0]['to']);
    }

    public function test_reports_no_phone_without_recording(): void
    {
        [$payment, $receipt] = $this->paymentWithReceipt(['phone' => null]);

        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt/whatsapp-message")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'no_phone')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.message', null);

        $this->assertCount(0, $receipt->fresh()->sent_log);
    }

    public function test_404s_when_the_payment_has_no_receipt(): void
    {
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $this->customer()->id]);

        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt/whatsapp-message")
            ->assertStatus(404);
    }

    public function test_422s_for_a_voided_receipt(): void
    {
        [$payment, $receipt] = $this->paymentWithReceipt();
        app(PaymentReceiptService::class)->void($receipt);

        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt/whatsapp-message")
            ->assertStatus(422);

        $this->assertCount(0, $receipt->fresh()->sent_log);
    }

    public function test_requires_a_token(): void
    {
        [$payment] = $this->paymentWithReceipt();

        $this->getJson("/api/v1/payments/{$payment->uuid}/receipt/whatsapp-message")->assertStatus(401);
    }
}
