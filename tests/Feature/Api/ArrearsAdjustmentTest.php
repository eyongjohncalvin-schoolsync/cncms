<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Factories\CustomerFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * JSON API counterpart of tests/Feature/Web/ArrearsAdjustmentTest.php —
 * covers only Api\ArrearsAdjustmentController::store(), the REQUEST side
 * this app's mobile client calls (see that controller's own class doc for
 * why there is no approve()/reject() JSON surface to test here — that stays
 * web-only). Reuses InteractsWithTenantRoles exactly like
 * tests/Feature/Api/ManuscriptTest.php.
 */
class ArrearsAdjustmentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_every_role_can_request_an_arrears_adjustment_via_the_json_api(): void
    {
        foreach (['super', 'admin', 'manager', 'agent', 'worker'] as $role) {
            $customer = CustomerFactory::new()->active()->create();
            $token = $this->tokenForRole($role);

            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/arrears-adjustments', [
                    'customer_uuid' => $customer->uuid,
                    'target_period' => now()->format('Y-m'),
                    'direction' => 'decrease',
                    'amount' => '1000.00',
                    'reason_category' => 'billing_error',
                    'reason_note' => "Requested by {$role} via mobile.",
                ]);

            $response->assertCreated()
                ->assertJsonPath('data.status', 'pending')
                ->assertJsonPath('data.customer_uuid', $customer->uuid)
                ->assertJsonPath('data.direction', 'decrease')
                ->assertJsonPath('data.reason_category', 'billing_error')
                ->assertJsonStructure([
                    'data' => [
                        'uuid', 'customer_uuid', 'customer_name', 'target_period', 'direction',
                        'amount', 'reason_category', 'reason_note', 'arrears_snapshot', 'status',
                        'requested_by' => ['uuid', 'name'], 'created_at',
                    ],
                ]);

            $this->assertDatabaseHas('arrears_adjustments', [
                'customer_id' => $customer->id,
                'reason_note' => "Requested by {$role} via mobile.",
                'status' => 'pending',
            ]);
        }
    }

    public function test_it_rejects_a_future_target_period(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/arrears-adjustments', [
                'customer_uuid' => $customer->uuid,
                'target_period' => now()->addMonth()->format('Y-m'),
                'direction' => 'decrease',
                'amount' => '1000.00',
                'reason_category' => 'billing_error',
                'reason_note' => 'Should be rejected.',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('target_period');
    }

    public function test_it_requires_a_reason_note(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/arrears-adjustments', [
                'customer_uuid' => $customer->uuid,
                'target_period' => now()->format('Y-m'),
                'direction' => 'decrease',
                'amount' => '1000.00',
                'reason_category' => 'billing_error',
                'reason_note' => '',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('reason_note');
    }

    public function test_it_rejects_a_non_positive_amount(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/arrears-adjustments', [
                'customer_uuid' => $customer->uuid,
                'target_period' => now()->format('Y-m'),
                'direction' => 'decrease',
                'amount' => '0',
                'reason_category' => 'billing_error',
                'reason_note' => 'Should be rejected.',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('amount');
    }
}
