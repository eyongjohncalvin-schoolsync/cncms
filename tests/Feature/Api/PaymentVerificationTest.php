<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Covers the payment verification/approval workflow — api-spec.md sections
 * 3.4/3.5 and business-rules.md section 5. See PaymentTest for the shared
 * fixture/role-switching conventions this reuses.
 */
class PaymentVerificationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customer(): Customer
    {
        $zone = ZoneFactory::new()->create();

        // Explicitly 'active': CustomerFactory's default state picks status
        // randomly (including a 20% chance of 'disconnected'), which would
        // make every test relying on this helper intermittently fail now
        // that disconnected customers are blocked from payment (see
        // StorePaymentRequest). Tests that specifically exercise the block
        // create their own customer with an explicit status instead of
        // using this helper.
        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
    }

    private function pendingPayment(): Payment
    {
        $customer = $this->customer();

        return PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);
    }

    public function test_manager_can_approve_a_pending_payment(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');
        $manager = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'approve',
                'momo_ref' => 'MOMO-20260817-001',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Payment verified'])
            ->assertJsonPath('data.verification_status', 'verified');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'verified',
        ]);

        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
            'status' => 'approved',
            'verified_by' => $manager->id,
            'momo_ref' => 'MOMO-20260817-001',
            'momo_status' => 'confirmed',
        ]);
    }

    public function test_rejecting_without_notes_fails_validation(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'reject',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['notes']);
    }

    public function test_manager_can_reject_a_payment_with_notes(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'reject',
                'notes' => 'Amount does not match MOMO record. Expected 3000 FCFA.',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Payment rejected'])
            ->assertJsonPath('data.verification_status', 'rejected');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'verification_status' => 'rejected',
        ]);

        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
            'status' => 'rejected',
            'notes' => 'Amount does not match MOMO record. Expected 3000 FCFA.',
        ]);
    }

    public function test_agent_cannot_verify_a_payment(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'approve',
            ]);

        $response->assertStatus(403);
    }

    public function test_uploading_a_receipt_sets_the_path_and_returns_a_url(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/receipt", [
                'receipt' => $file,
            ]);

        $response->assertOk()->assertJson(['message' => 'Receipt uploaded']);
        $this->assertNotNull($response->json('receipt_url'));

        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
        ]);

        $verification = $payment->fresh()->verification;
        $this->assertNotNull($verification);
        $this->assertNotNull($verification->receipt_photo_path);
    }

    public function test_receipt_cannot_be_replaced_once_a_payment_is_verified(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $original = UploadedFile::fake()->image('receipt-original.jpg');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/receipt", ['receipt' => $original])
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", ['action' => 'approve'])
            ->assertOk();

        $originalPath = $payment->fresh()->verification->receipt_photo_path;

        $replacement = UploadedFile::fake()->image('receipt-swapped.jpg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/receipt", ['receipt' => $replacement]);

        $response->assertStatus(422)->assertJsonValidationErrors(['receipt']);

        $this->assertSame($originalPath, $payment->fresh()->verification->receipt_photo_path);
    }

    public function test_receipt_can_be_replaced_after_a_rejected_payment_is_resubmitted(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'reject',
                'notes' => 'Receipt illegible, please resubmit.',
            ])->assertOk();

        $newEvidence = UploadedFile::fake()->image('receipt-resubmitted.jpg');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/receipt", ['receipt' => $newEvidence]);

        $response->assertOk();
        $this->assertNotNull($payment->fresh()->verification->receipt_photo_path);
    }

    public function test_manager_can_re_approve_a_previously_rejected_payment(): void
    {
        $payment = $this->pendingPayment();
        $token = $this->tokenForRole('manager');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'reject',
                'notes' => 'Needs new evidence.',
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$payment->uuid}/verify", [
                'action' => 'approve',
            ]);

        $response->assertOk()->assertJsonPath('data.verification_status', 'verified');

        $this->assertDatabaseHas('payment_verifications', [
            'payment_id' => $payment->id,
            'status' => 'approved',
        ]);
    }
}
