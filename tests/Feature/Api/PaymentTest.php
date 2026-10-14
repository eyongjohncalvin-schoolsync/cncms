<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Customer;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema — see
 * Tests\Feature\Api\Concerns\InteractsWithTenantRoles for the transaction/
 * role-switching strategy. All fixtures are created fresh via
 * ZoneFactory/CustomerFactory/PaymentFactory; none of the real seeded rows
 * are touched.
 */
class PaymentTest extends TestCase
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

    public function test_index_lists_payments_filtered_by_customer(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer();

        PaymentFactory::new()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->create(['customer_id' => $otherCustomer->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments?customer_uuid={$customer->uuid}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($customer->uuid, $data[0]['customer_uuid']);
    }

    public function test_show_returns_a_payment_with_customer(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/payments/{$payment->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.uuid', $payment->uuid)
            ->assertJsonPath('data.customer_uuid', $customer->uuid);
    }

    public function test_super_recorded_payment_is_auto_verified(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 2500,
                'frequency' => 'monthly',
            ]);

        $response->assertCreated()->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_agent_recorded_payment_is_pending(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 2500,
                'frequency' => 'monthly',
            ]);

        $response->assertCreated()->assertJsonPath('data.verification_status', 'pending');
    }

    public function test_a_months_frequency_payment_locks_the_prepaid_rate_and_sets_no_expiration_date(): void
    {
        // Draw-down cutover (references/prepayment-drawdown.md): a new
        // months/yearly payment no longer carries an expiration_date — its
        // value flows through the draw-down branch as prepaid months, and
        // prepaid_rate captures the customer's bill at payment time.
        $customer = $this->customer(); // factory bill
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 7500,
                'frequency' => 'months',
                'months' => 3,
            ]);

        $response->assertCreated();
        $this->assertNull($response->json('data.expiration_date'));
        $this->assertSame((string) $customer->bill, $response->json('data.prepaid_rate'));
    }

    public function test_super_can_update_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/payments/{$payment->uuid}", ['amount' => 3000]);

        $response->assertOk()->assertJsonPath('data.amount', '3000.00');
    }

    public function test_super_can_delete_a_payment(): void
    {
        $customer = $this->customer();
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id]);

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/payments/{$payment->uuid}");

        $response->assertOk()->assertJson(['message' => 'Payment deleted']);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_store_fails_validation_for_zero_amount(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 0,
                'frequency' => 'monthly',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_worker_cannot_record_a_payment(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 2500,
                'frequency' => 'monthly',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Owner's rule (2026-10): a prepayment pays off arrears first by
     * default; only someone who may approve arrears can leave them due.
     */
    public function test_a_prepayment_clears_arrears_first_by_default(): void
    {
        $customer = $this->customer();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->postJson('/api/v1/payments', ['customer_uuid' => $customer->uuid, 'amount' => 7500, 'frequency' => 'months', 'months' => 3]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.clear_arrears_first'));
    }

    public function test_an_arrears_approver_can_leave_arrears_due_on_a_prepayment(): void
    {
        $customer = $this->customer();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid, 'amount' => 7500, 'frequency' => 'months', 'months' => 3, 'clear_arrears_first' => false,
            ]);

        $response->assertCreated();
        $this->assertFalse($response->json('data.clear_arrears_first'));
    }

    public function test_an_agent_cannot_leave_arrears_due_on_a_prepayment(): void
    {
        $customer = $this->customer();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('agent'))
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid, 'amount' => 7500, 'frequency' => 'yearly', 'clear_arrears_first' => false,
            ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.clear_arrears_first'));
    }

    public function test_a_monthly_payment_is_not_marked_as_clearing_arrears_first(): void
    {
        $customer = $this->customer();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->postJson('/api/v1/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly']);

        $response->assertCreated();
        $this->assertFalse($response->json('data.clear_arrears_first'));
    }
}
