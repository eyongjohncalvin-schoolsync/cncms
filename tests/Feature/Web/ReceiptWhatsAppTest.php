<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PaymentReceiptService;
use App\Services\ReceiptWhatsAppService;
use App\Support\CameroonPhone;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 3 of docs/plans/payment-receipts-and-whatsapp.md — the manual
 * (free, no-Twilio) "Send via WhatsApp" web action. Same harness as
 * PaymentReceiptViewTest.
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
    // Phone normalisation (App\Support\CameroonPhone)
    // -----------------------------------------------------------------

    #[DataProvider('phoneCases')]
    public function test_phone_normalisation(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, CameroonPhone::forWhatsapp($raw));
    }

    public static function phoneCases(): array
    {
        return [
            '9-digit local' => ['677440670', '237677440670'],
            'already 237-prefixed' => ['237677440670', '237677440670'],
            '+237 international' => ['+237677440670', '237677440670'],
            '00237 international' => ['00237677440670', '237677440670'],
            'messy spaces / parens' => ['(67) 744 06 70', '237677440670'],
            'leading trunk 0' => ['0677440670', '237677440670'],
            'empty' => ['', null],
            'whitespace only' => ['   ', null],
            'null' => [null, null],
            'garbage letters' => ['not-a-phone', null],
            'too short' => ['12345', null],
        ];
    }

    // -----------------------------------------------------------------
    // wa.me link + message content
    // -----------------------------------------------------------------

    public function test_manual_link_has_the_wa_me_shape_and_message_contents(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt();

        $link = app(ReceiptWhatsAppService::class)->manualLink($receipt);

        $this->assertNotNull($link);
        $this->assertStringStartsWith('https://wa.me/237677000111?text=', $link);

        $message = urldecode(explode('?text=', $link, 2)[1]);
        $this->assertStringContainsString($receipt->receipt_number, $message);
        $this->assertStringContainsString('2,500 FCFA', $message);
        // The signed public PDF URL is embedded verbatim.
        $this->assertStringContainsString('/pdf/shared', $message);
        $this->assertStringContainsString('signature=', $message);
    }

    public function test_manual_link_is_null_when_the_snapshot_customer_has_no_phone(): void
    {
        [, , $receipt] = $this->verifiedPaymentWithReceipt(['phone' => null]);

        $this->assertNull(app(ReceiptWhatsAppService::class)->manualLink($receipt));
    }

    // -----------------------------------------------------------------
    // POST send-whatsapp
    // -----------------------------------------------------------------

    public function test_send_records_to_sent_log_and_flashes_the_link(): void
    {
        [, $payment, $receipt] = $this->verifiedPaymentWithReceipt();

        $user = $this->actingAsRole('agent');

        $response = $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp");

        $response->assertRedirect();
        $response->assertSessionHas('whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/237677000111?text=', session('whatsapp_url'));

        $log = $receipt->fresh()->sent_log;
        $this->assertCount(1, $log);
        $this->assertSame('whatsapp_manual', $log[0]['channel']);
        $this->assertSame($user->id, $log[0]['by']);
        $this->assertSame('237677000111', $log[0]['to']);
        $this->assertArrayHasKey('at', $log[0]);
    }

    public function test_send_appends_a_second_entry_on_a_repeat_send(): void
    {
        [, $payment, $receipt] = $this->verifiedPaymentWithReceipt();

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertRedirect();
        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertRedirect();

        $this->assertCount(2, $receipt->fresh()->sent_log);
    }

    public function test_send_422s_when_no_receipt_has_been_issued(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertStatus(422);
    }

    public function test_send_422s_for_a_voided_receipt(): void
    {
        [, $payment, $receipt] = $this->verifiedPaymentWithReceipt();
        app(PaymentReceiptService::class)->void($receipt);

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertStatus(422);
        $this->assertCount(0, $receipt->fresh()->sent_log);
    }

    public function test_send_422s_when_the_customer_has_no_phone(): void
    {
        [, $payment, $receipt] = $this->verifiedPaymentWithReceipt(['phone' => null]);

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertStatus(422);
        $this->assertCount(0, $receipt->fresh()->sent_log);
    }

    public function test_send_is_gated_on_payments_view(): void
    {
        [, $payment] = $this->verifiedPaymentWithReceipt();

        $role = Role::query()->create(['name' => 'no-payments-view-wa', 'label' => 'No Payments View', 'is_system' => false]);
        $role->syncPermissions(['customers.view']);

        $this->actingAsRole('no-payments-view-wa');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertStatus(403);
    }

    public function test_show_payload_carries_last_sent_metadata(): void
    {
        [, $payment] = $this->verifiedPaymentWithReceipt();

        $this->actingAsRole('manager');

        $this->post("/payments/{$payment->uuid}/receipt/send-whatsapp")->assertRedirect();

        $this->get("/payments/{$payment->uuid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receipt.sent_count', 1)
                ->has('receipt.last_sent_at'));
    }
}
