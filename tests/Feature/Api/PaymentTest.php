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

        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500]);
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

    public function test_a_months_frequency_payment_computes_expiration_date(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', [
                'customer_uuid' => $customer->uuid,
                'amount' => 7500,
                'frequency' => 'months',
                'months' => 3,
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.expiration_date'));
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
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

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
}
