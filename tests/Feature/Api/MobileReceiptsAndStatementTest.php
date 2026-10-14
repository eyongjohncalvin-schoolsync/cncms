<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\SendReceiptEmailJob;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The mobile app's receipt and statement endpoints: per-format signed
 * receipt links (pocket thermal printers), emailing a receipt, and a
 * customer's bill history with signed statement links.
 */
class MobileReceiptsAndStatementTest extends TestCase
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
        return CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'status' => 'active',
            'phone' => '677000111',
            ...$overrides,
        ]);
    }

    /**
     * @return array{0: Payment, 1: PaymentReceipt}
     */
    private function paymentWithReceipt(Customer $customer): array
    {
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);

        return [$payment, app(PaymentReceiptService::class)->issueFor($payment, autoSend: false)];
    }

    private function emailEnabled(bool $enabled): void
    {
        NotificationSetting::cached()->update(['email_enabled' => $enabled]);
        Cache::forget(NotificationSetting::CACHE_KEY);
    }

    public function test_the_receipt_carries_a_signed_link_per_paper_size_that_streams_that_format(): void
    {
        [$payment] = $this->paymentWithReceipt($this->customer());
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}/receipt")
            ->assertOk()
            ->assertJsonStructure(['data' => ['shared_pdf_urls' => ['a6', 'thermal58', 'thermal80'], 'invoice_number', 'customer_email', 'email_unavailable_reason']]);

        $thermal = (string) $response->json('data.shared_pdf_urls.thermal58');
        $this->assertStringContainsString('format=thermal58', $thermal);

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->get($thermal)->assertOk();

        // The format is covered by the signature.
        $this->get(str_replace('format=thermal58', 'format=thermal80', $thermal))->assertForbidden();
    }

    public function test_send_email_queues_the_receipt_to_the_customers_address(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->emailEnabled(true);
        [$payment, $receipt] = $this->paymentWithReceipt($this->customer(['email' => 'client@example.com']));
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/receipt/send-email")
            ->assertOk()
            ->assertJsonPath('message', "Receipt {$receipt->receipt_number} is being emailed to client@example.com.");

        Bus::assertDispatched(SendReceiptEmailJob::class, fn (SendReceiptEmailJob $job): bool => $job->receiptId === $receipt->id);
    }

    public function test_send_email_is_refused_with_the_reason_when_there_is_no_address_or_email_is_off(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $token = $this->tokenForRole('agent');

        $this->emailEnabled(true);
        [$noAddress] = $this->paymentWithReceipt($this->customer(['email' => null]));
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$noAddress->uuid}/receipt/send-email")
            ->assertUnprocessable()
            ->assertJsonPath('errors.receipt.0', 'This customer has no email address on file.');

        $this->emailEnabled(false);
        [$switchedOff] = $this->paymentWithReceipt($this->customer(['email' => 'client@example.com']));
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$switchedOff->uuid}/receipt/send-email")
            ->assertUnprocessable();

        Bus::assertNotDispatched(SendReceiptEmailJob::class);
    }

    public function test_bill_history_lists_bills_with_status_and_signed_statement_links(): void
    {
        $customer = $this->customer();
        $period = now()->format('Y-m');
        ManuscriptFactory::new()->forPeriod($period)->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/history")
            ->assertOk()
            ->assertJsonPath('data.0.period', $period)
            ->assertJsonPath('data.0.status', 'unpaid')
            ->assertJsonStructure(['statement_urls' => ['6', '12', '24']]);

        $this->app['auth']->forgetGuards();
        $statement = $this->withoutHeader('Authorization')->get((string) $response->json('statement_urls.12'));
        $statement->assertOk();
        $this->assertStringContainsString('pdf', (string) $statement->headers->get('content-type'));

        $this->get($response->json('statement_urls.12').'&extra=1')->assertForbidden();
    }

    public function test_bill_history_hides_statement_links_from_a_role_that_cannot_print_bills(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('worker');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/bills/{$customer->uuid}/history")
            ->assertOk()
            ->assertJsonPath('statement_urls', null);
    }
}
