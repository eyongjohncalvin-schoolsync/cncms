<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * How a payment was made (cash / MTN MoMo / Orange Money / bank) and its
 * MoMo reference, captured at collection on the web form, the bulk form
 * and the mobile sync push. A MoMo reference can only be used once.
 */
class PaymentMethodTest extends TestCase
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
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_momo_payment_is_saved_with_its_method_and_reference(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('manager');

        $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
            'method' => 'mtn_momo',
            'momo_ref' => ' MP2610.1234 ',
        ])->assertRedirect('/payments');

        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'method' => 'mtn_momo', 'momo_ref' => 'MP2610.1234']);
    }

    public function test_a_momo_payment_needs_its_reference_and_a_reference_can_only_be_used_once(): void
    {
        $this->actingAsRole('manager');
        PaymentFactory::new()->create(['customer_id' => $this->customer()->id, 'method' => 'mtn_momo', 'momo_ref' => 'MP-USED-1']);

        $this->post('/payments', [
            'customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'orange_money',
        ])->assertSessionHasErrors('momo_ref');

        $this->post('/payments', [
            'customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'momo_ref' => 'mp-used-1',
        ])->assertSessionHasErrors(['momo_ref' => 'This MoMo reference has already been used on another payment.']);

        $this->post('/payments', [
            'customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'cheque',
        ])->assertSessionHasErrors('method');
    }

    public function test_bulk_payments_record_cash_or_bank(): void
    {
        $customer = $this->customer();
        $this->actingAsRole('manager');

        $this->post('/payments/bulk', [
            'customer_uuids' => [$customer->uuid], 'frequency' => 'monthly', 'method' => 'cash',
        ])->assertRedirect('/payments');

        $this->assertSame('cash', Payment::query()->where('customer_id', $customer->id)->value('method'));

        $this->post('/payments/bulk', [
            'customer_uuids' => [$this->customer()->uuid], 'frequency' => 'monthly', 'method' => 'mtn_momo',
        ])->assertSessionHasErrors('method');
    }

    public function test_a_synced_payment_keeps_its_method_and_a_reused_momo_reference_fails_only_that_item(): void
    {
        PaymentFactory::new()->create(['customer_id' => $this->customer()->id, 'method' => 'mtn_momo', 'momo_ref' => 'MP-TAKEN']);
        $token = $this->tokenForRole('agent');
        $good = (string) Str::uuid();
        $reused = (string) Str::uuid();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-method-1',
            'changes' => ['payments' => [
                ['local_uuid' => $good, 'customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'orange_money', 'momo_ref' => 'OM-NEW-1'],
                ['local_uuid' => $reused, 'customer_uuid' => $this->customer()->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'method' => 'mtn_momo', 'momo_ref' => 'mp-taken'],
            ]],
        ])->assertOk();

        $results = collect($response->json('results.payments'))->keyBy('local_uuid');
        $this->assertSame('synced', $results[$good]['status']);
        $this->assertSame('failed', $results[$reused]['status']);
        $this->assertStringContainsString('already been used', $results[$reused]['error']);
        $this->assertDatabaseHas('payments', ['local_uuid' => $good, 'method' => 'orange_money', 'momo_ref' => 'OM-NEW-1']);
        $this->assertDatabaseMissing('payments', ['local_uuid' => $reused]);
    }
}
