<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Jobs\SendReceiptEmailJob;
use App\Mail\ReceiptMail;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptEmailService;
use App\Services\ReceiptWhatsAppService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Receipts by email (Brevo SMTP in production): the customer's email +
 * preferred channel, the manual "Email receipt" action, the queued send
 * (PDF attached, recorded in sent_log), and auto-send on verification for
 * customers who prefer email when Settings → Notifications allows it.
 */
class ReceiptEmailTest extends TestCase
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

    /**
     * $confirmations — cash controls' "confirm every collection by email"
     * (on by default for new tenants); off here unless a test is about it,
     * so the preferred-channel auto-send rule is tested on its own.
     */
    private function settings(bool $emailEnabled, bool $autoSend, bool $confirmations = false): void
    {
        NotificationSetting::cached()->update([
            'email_enabled' => $emailEnabled,
            'auto_send_receipts' => $autoSend,
            'email_collection_confirmations' => $confirmations,
        ]);
        Cache::forget(NotificationSetting::CACHE_KEY);
    }

    private function customer(array $overrides = []): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'status' => 'active',
            'email' => 'ada@example.com',
            'preferred_channel' => 'email',
            ...$overrides,
        ]);
    }

    private function receiptFor(Customer $customer): PaymentReceipt
    {
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500, 'verification_status' => 'verified']);

        return app(PaymentReceiptService::class)->issueFor($payment, autoSend: false);
    }

    public function test_a_customer_can_be_saved_with_an_email_and_preferred_channel_and_the_email_cleared(): void
    {
        $this->actingAsRole('manager');
        $zone = ZoneFactory::new()->create();

        $this->post('/customers', [
            'zone_uuid' => $zone->uuid, 'name' => 'Mail Customer', 'phone' => '677123456', 'bill' => 2500,
            'email' => ' Mail@Example.com ', 'preferred_channel' => 'email',
        ])->assertRedirect();

        $customer = Customer::query()->where('name', 'MAIL CUSTOMER')->sole();
        $this->assertSame('mail@example.com', $customer->email);
        $this->assertSame('email', $customer->preferred_channel);

        $this->patch("/customers/{$customer->uuid}", ['email' => '', 'preferred_channel' => 'print'])->assertRedirect();

        $customer->refresh();
        $this->assertNull($customer->email);
        $this->assertSame('print', $customer->preferred_channel);
    }

    public function test_an_unknown_channel_or_a_bad_email_is_rejected(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customer();

        $this->patch("/customers/{$customer->uuid}", ['preferred_channel' => 'pigeon'])->assertSessionHasErrors('preferred_channel');
        $this->patch("/customers/{$customer->uuid}", ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_email_receipt_queues_the_send(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->settings(emailEnabled: true, autoSend: false);
        $receipt = $this->receiptFor($this->customer());

        $this->actingAsRole('manager');
        $this->post("/payments/{$receipt->payment->uuid}/receipt/send-email")
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'ada@example.com'));

        Bus::assertDispatched(SendReceiptEmailJob::class, fn (SendReceiptEmailJob $job): bool => $job->receiptId === $receipt->id);
    }

    public function test_email_receipt_explains_why_it_cannot_send(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->actingAsRole('manager');

        $this->settings(emailEnabled: false, autoSend: false);
        $receipt = $this->receiptFor($this->customer());
        $this->post("/payments/{$receipt->payment->uuid}/receipt/send-email")
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'switched off'));

        $this->settings(emailEnabled: true, autoSend: false);
        $noEmail = $this->receiptFor($this->customer(['email' => null]));
        $this->post("/payments/{$noEmail->payment->uuid}/receipt/send-email")
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no email'));

        Bus::assertNotDispatched(SendReceiptEmailJob::class);
    }

    public function test_the_job_emails_the_pdf_and_records_the_send(): void
    {
        Mail::fake();
        $this->settings(emailEnabled: true, autoSend: false);
        $receipt = $this->receiptFor($this->customer());

        (new SendReceiptEmailJob($receipt->id))->handle(
            app(PaymentReceiptService::class),
            app(ReceiptEmailService::class),
            app(ReceiptWhatsAppService::class),
        );

        Mail::assertSent(ReceiptMail::class, function (ReceiptMail $mail) use ($receipt): bool {
            $attachments = $mail->attachments();

            return $mail->hasTo('ada@example.com')
                && $mail->receipt->is($receipt)
                && count($attachments) === 1
                && str_contains($mail->envelope()->subject, $receipt->receipt_number);
        });

        $log = $receipt->fresh()->sent_log;
        $this->assertSame('email', end($log)['channel']);
        $this->assertSame('ada@example.com', end($log)['to']);
    }

    public function test_the_job_does_nothing_if_the_receipt_was_voided_meanwhile(): void
    {
        Mail::fake();
        $this->settings(emailEnabled: true, autoSend: false);
        $receipt = $this->receiptFor($this->customer());
        $receipt->update(['status' => PaymentReceipt::STATUS_VOID]);

        (new SendReceiptEmailJob($receipt->id))->handle(
            app(PaymentReceiptService::class),
            app(ReceiptEmailService::class),
            app(ReceiptWhatsAppService::class),
        );

        Mail::assertNothingSent();
    }

    public function test_a_verified_payment_is_emailed_automatically_to_a_customer_who_prefers_email(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->settings(emailEnabled: true, autoSend: true);
        $this->actingAsRole('manager');

        $prefersEmail = $this->customer();
        $prefersWhatsapp = $this->customer(['preferred_channel' => 'whatsapp']);

        foreach ([$prefersEmail, $prefersWhatsapp] as $customer) {
            $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertRedirect();
        }

        $emailedReceipt = Payment::query()->where('customer_id', $prefersEmail->id)->sole()->receipt;
        Bus::assertDispatchedTimes(SendReceiptEmailJob::class, 1);
        Bus::assertDispatched(SendReceiptEmailJob::class, fn (SendReceiptEmailJob $job): bool => $job->receiptId === $emailedReceipt->id);
    }

    public function test_collection_confirmations_email_every_customer_with_an_address_whatever_their_channel(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->settings(emailEnabled: true, autoSend: false, confirmations: true);
        $this->actingAsRole('manager');

        $prefersWhatsapp = $this->customer(['preferred_channel' => 'whatsapp']);
        $noAddress = $this->customer(['email' => null]);

        foreach ([$prefersWhatsapp, $noAddress] as $customer) {
            $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertRedirect();
        }

        $confirmed = Payment::query()->where('customer_id', $prefersWhatsapp->id)->sole()->receipt;
        Bus::assertDispatchedTimes(SendReceiptEmailJob::class, 1);
        Bus::assertDispatched(SendReceiptEmailJob::class, fn (SendReceiptEmailJob $job): bool => $job->receiptId === $confirmed->id);
    }

    public function test_nothing_is_sent_automatically_when_auto_send_is_off(): void
    {
        Bus::fake([SendReceiptEmailJob::class]);
        $this->settings(emailEnabled: true, autoSend: false);
        $this->actingAsRole('manager');

        $this->post('/payments', ['customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertRedirect();

        Bus::assertNotDispatched(SendReceiptEmailJob::class);
    }

    public function test_auto_send_can_be_switched_on_in_notification_settings(): void
    {
        $this->actingAsRole('admin');

        $this->patch('/settings/notifications', [
            'whatsapp_enabled' => '1', 'email_enabled' => '1', 'sms_enabled' => '0', 'auto_send_receipts' => '1',
        ])->assertRedirect();

        $this->assertTrue(NotificationSetting::cached()->auto_send_receipts);
    }
}
