<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentReversalRequest;
use App\Models\SyncQueue;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * A collector asks the office to reverse a payment, or cancels one on the
 * phone before it synced; and sees, on the phone, what someone else took in
 * their zones this month (owner, 2026-10-28).
 */
class PaymentReversalRequestTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    private function as(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);

        return $user;
    }

    private function paymentBy(string $email, array $attributes = []): Payment
    {
        return PaymentFactory::new()->create([
            'customer_id' => $this->customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
            'recorded_by' => User::query()->where('email', $email)->value('id'),
            ...$attributes,
        ]);
    }

    public function test_the_collector_asks_and_the_office_declines_with_a_reason(): void
    {
        $payment = $this->paymentBy('kelvin@shalomtech.dev');

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $page) => $page->where('can_request_reversal', true));
        $this->post("/payments/{$payment->uuid}/reversal-request", ['reason' => 'Recorded on the wrong customer'])->assertSessionHas('success');
        $this->post("/payments/{$payment->uuid}/reversal-request", ['reason' => 'Again'])->assertForbidden();

        $request = PaymentReversalRequest::query()->where('payment_id', $payment->id)->sole();
        $this->assertTrue($request->isPending());

        // The collector can't decide their own request.
        $this->post("/reversal-requests/{$request->uuid}/decline", ['note' => 'No'])->assertForbidden();

        $this->as('terence@shalomtech.dev', 'admin');
        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->where('reversal_requests_count', 1));
        $this->get('/payments/reversal-requests')->assertInertia(fn (Assert $page) => $page
            ->component('Payments/ReversalRequests')
            ->where('requests.0.reason', 'Recorded on the wrong customer'));
        $this->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $page) => $page->where('reversal_request.reason', 'Recorded on the wrong customer'));

        $this->post("/reversal-requests/{$request->uuid}/decline", ['note' => 'The customer confirmed the payment'])->assertSessionHas('success');

        $this->assertSame(PaymentReversalRequest::STATUS_DECLINED, $request->refresh()->status);
        $this->assertSame('verified', $payment->refresh()->verification_status);
        $this->assertTrue(Notification::query()->where('type', 'payment.reversal_decided')->exists());
    }

    public function test_reversing_the_payment_answers_the_request(): void
    {
        $payment = $this->paymentBy('kelvin@shalomtech.dev');

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post("/payments/{$payment->uuid}/reversal-request", ['reason' => 'Customer paid twice'])->assertSessionHasNoErrors();

        $this->as('terence@shalomtech.dev', 'admin');
        $this->post("/payments/{$payment->uuid}/reverse", ['reason' => 'Asked by the collector: Customer paid twice', 'money' => 'none'])->assertSessionHasNoErrors();

        $this->assertSame(PaymentReversalRequest::STATUS_DONE, PaymentReversalRequest::query()->where('payment_id', $payment->id)->sole()->status);
    }

    public function test_only_the_collector_who_recorded_it_asks(): void
    {
        $payment = $this->paymentBy('terence@shalomtech.dev');

        $this->as('kelvin@shalomtech.dev', 'agent');
        $this->post("/payments/{$payment->uuid}/reversal-request", ['reason' => 'Not mine'])->assertForbidden();
    }

    public function test_the_phone_asks_and_sees_what_others_took_in_its_zones(): void
    {
        $mine = $this->paymentBy('kelvin@shalomtech.dev');
        $office = $this->paymentBy('terence@shalomtech.dev', ['recorded_offline' => false]);
        $elsewhere = PaymentFactory::new()->create([
            'customer_id' => CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id])->id,
            'recorded_by' => User::query()->where('email', 'terence@shalomtech.dev')->value('id'),
        ]);

        $token = $this->tokenForAgentCovering($this->customer->zone);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/payments/{$mine->uuid}/reversal-request", ['reason' => 'Wrong amount'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $others = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/payments/by-others')->assertOk()->json('data');

        $this->assertSame([$office->uuid], array_column($others, 'uuid'), 'Not the agent\'s own, not another zone\'s.');
        $this->assertSame('office', $others[0]['where']);
        $this->assertNotContains($elsewhere->uuid, array_column($others, 'uuid'));
    }

    public function test_a_payment_cancelled_on_the_phone_before_it_synced(): void
    {
        $token = $this->tokenForAgentCovering($this->customer->zone);
        $synced = $this->paymentBy('kelvin@shalomtech.dev', ['local_uuid' => (string) Str::uuid()]);
        $neverArrived = (string) Str::uuid();

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-cancel-1',
            'changes' => ['payment_cancellations' => [
                ['local_uuid' => $neverArrived, 'receipt_number' => null, 'reason' => 'The customer did not pay'],
                // It reached the server after all (the phone missed the answer): the office decides.
                ['local_uuid' => $synced->local_uuid, 'receipt_number' => null, 'reason' => 'The customer did not pay'],
            ]],
        ])->assertOk();

        $this->assertSame(['cancelled', 'requested'], array_column($response->json('results.payment_cancellations'), 'status'));
        $this->assertSame('verified', $synced->refresh()->verification_status, 'Never undone by the phone itself.');
        $this->assertStringStartsWith('Cancelled on the phone', PaymentReversalRequest::query()->where('payment_id', $synced->id)->sole()->reason);
        $this->assertTrue(SyncQueue::query()->where('entity_type', 'payment_cancellation')->where('local_uuid', $neverArrived)->exists());
    }
}
