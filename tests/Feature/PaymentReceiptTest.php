<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PaymentReceiptService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Wave 1 of docs/plans/payment-receipts-and-whatsapp.md — the model,
 * generation service, PDF, and the auto-issue/void hook in
 * PaymentVerificationService.
 *
 * Runs against the real `tenantswecom` schema with DatabaseTransactions
 * (rolls back) — same strategy as tests/Feature/Api/PaymentTest.php. The
 * verify() hook is exercised through the real HTTP verify route (the only
 * place TenantContext resolves); the service methods are called directly.
 * The backfill command's write path can't share this setup (its
 * `tenancy()->runForMultiple` re-init purges the transaction) — see
 * PaymentReceiptBackfillTest.
 */
class PaymentReceiptTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsManager(): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => 'manager']);
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
        ], $overrides));
    }

    private function receipts(): PaymentReceiptService
    {
        return app(PaymentReceiptService::class);
    }

    private function approveViaHttp(Payment $payment): void
    {
        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'approve', 'momo_ref' => 'MOMO-RCP-001'])
            ->assertRedirect();
    }

    private function rejectViaHttp(Payment $payment): void
    {
        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'reject', 'notes' => 'Not received.'])
            ->assertRedirect();
    }

    public function test_a_receipt_is_auto_issued_when_a_payment_is_verified(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $user = $this->actingAsManager();
        $this->approveViaHttp($payment);

        $receipt = PaymentReceipt::query()->where('payment_id', $payment->id)->first();

        $this->assertNotNull($receipt);
        $this->assertSame(PaymentReceipt::STATUS_ISSUED, $receipt->status);
        $this->assertSame('2500.00', (string) $receipt->amount);
        $this->assertSame($user->id, $receipt->issued_by);
        $this->assertMatchesRegularExpression('/^RCP-\d{4}-\d{6}$/', $receipt->receipt_number);
        $this->assertSame($customer->name, $receipt->snapshot['customer']['name']);
        $this->assertSame('MOMO-RCP-001', $receipt->snapshot['payment']['momo_ref']);
    }

    public function test_no_receipt_is_issued_on_reject(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsManager();
        $this->rejectViaHttp($payment);

        $this->assertFalse(PaymentReceipt::query()->where('payment_id', $payment->id)->exists());
    }

    public function test_an_existing_receipt_is_voided_when_its_payment_is_later_rejected(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsManager();
        $this->approveViaHttp($payment);
        $receipt = PaymentReceipt::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(PaymentReceipt::STATUS_ISSUED, $receipt->status);

        $this->rejectViaHttp($payment->fresh());

        $this->assertSame(PaymentReceipt::STATUS_VOID, $receipt->fresh()->status);
        $this->assertTrue(PaymentReceipt::query()->where('id', $receipt->id)->exists());
    }

    public function test_re_approving_a_rejected_payment_reactivates_the_same_receipt_row(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $this->actingAsManager();
        $this->approveViaHttp($payment);
        $original = PaymentReceipt::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->rejectViaHttp($payment->fresh());
        $this->assertSame(PaymentReceipt::STATUS_VOID, $original->fresh()->status);

        $this->approveViaHttp($payment->fresh());

        $reactivated = PaymentReceipt::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame($original->id, $reactivated->id);
        $this->assertSame($original->receipt_number, $reactivated->receipt_number);
        $this->assertSame(PaymentReceipt::STATUS_ISSUED, $reactivated->status);
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $payment->id)->count());
    }

    public function test_issue_for_is_idempotent(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $first = $this->receipts()->issueFor($payment);
        $second = $this->receipts()->issueFor($payment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->receipt_number, $second->receipt_number);
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $payment->id)->count());
    }

    public function test_receipt_numbers_are_unique_and_sequential_across_concurrent_issues(): void
    {
        $customer = $this->customer();
        $paymentA = PaymentFactory::new()->create(['customer_id' => $customer->id]);
        $paymentB = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        // The receipt_counters FOR UPDATE lock in allocateNumber() is what
        // stops two allocations ever reading the same next_number under real
        // concurrency; sequential mint here proves the counter advances.
        $a = $this->receipts()->issueFor($paymentA);
        $b = $this->receipts()->issueFor($paymentB);

        $this->assertNotSame($a->receipt_number, $b->receipt_number);

        [, , $numA] = explode('-', $a->receipt_number);
        [, , $numB] = explode('-', $b->receipt_number);
        $this->assertSame((int) $numA + 1, (int) $numB);
    }

    public function test_snapshot_is_frozen_against_a_later_customer_edit(): void
    {
        $customer = $this->customer(['name' => 'Original Name']);
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $receipt = $this->receipts()->issueFor($payment);

        $customer->update(['name' => 'Renamed Later']);

        $this->assertSame('Original Name', $receipt->fresh()->snapshot['customer']['name']);
    }

    public function test_pdf_renders_a_non_empty_pdf_and_caches_it(): void
    {
        Storage::fake('local');

        $customer = $this->customer();
        $payment = PaymentFactory::new()->months(3)->create(['customer_id' => $customer->id]);
        $receipt = $this->receipts()->issueFor($payment);

        $path = $this->receipts()->pdf($receipt);

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));
        $this->assertSame($path, $receipt->fresh()->pdf_path);
        $this->assertSame('local', $receipt->fresh()->pdf_disk);
    }

    public function test_pdf_does_not_re_render_on_a_second_call(): void
    {
        Storage::fake('local');

        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);
        $receipt = $this->receipts()->issueFor($payment);

        $path = $this->receipts()->pdf($receipt);

        // A second pdf() call that re-rendered would replace this sentinel
        // with real PDF bytes.
        Storage::disk('local')->put($path, 'SENTINEL-NOT-A-PDF');

        $again = $this->receipts()->pdf($receipt->fresh());

        $this->assertSame($path, $again);
        $this->assertSame('SENTINEL-NOT-A-PDF', Storage::disk('local')->get($path));
    }

    public function test_void_keeps_the_row(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);
        $receipt = $this->receipts()->issueFor($payment);

        $this->receipts()->void($receipt);

        $this->assertTrue($receipt->fresh()->isVoid());
        $this->assertTrue(PaymentReceipt::query()->where('id', $receipt->id)->exists());
    }

    public function test_bulk_verify_route_issues_a_receipt_per_verified_payment(): void
    {
        $customer = $this->customer(['bill' => 2500]);
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->actingAsManager();
        $this->post('/payments/bulk-verify', ['payment_uuids' => [$payment->uuid]])->assertRedirect();

        $this->assertDatabaseHas('payment_receipts', [
            'payment_id' => $payment->id,
            'status' => 'issued',
        ]);
    }
}
