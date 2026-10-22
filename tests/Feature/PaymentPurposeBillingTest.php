<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerManuscriptRecalculationService;
use App\Services\ManuscriptPreRunReviewService;
use App\Support\ScheduledTasks\ManuscriptChunkDataResolver;
use Database\Factories\CustomerChargeFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LogicException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Installation fees, phase 1 (docs/plans/installation-and-promotions.md):
 * billing only ever sees subscription money. Installation instalments and
 * technician motivation fees never lower arrears, never add credit, never
 * change total_bill and are never stamped as consumed by a bill run. Also
 * covers the purpose column's defaults (old clients) and immutability.
 */
class PaymentPurposeBillingTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    /** A far-future period no real data can collide with. */
    private const string PERIOD = '2035-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
    }

    private function actAs(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_eligible_for_period_scope_only_returns_subscription_payments(): void
    {
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);

        $subscription = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 1000]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 5000]);
        PaymentFactory::new()->technicianFee()->create(['customer_id' => $customer->id, 'amount' => 2000]);

        $eligible = Payment::query()->where('customer_id', $customer->id)->eligibleForPeriod(self::PERIOD)->pluck('id')->all();

        $this->assertSame([$subscription->id], $eligible);
    }

    public function test_the_chunk_resolver_never_hands_non_subscription_money_to_the_calculator(): void
    {
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 1000]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 5000]);
        PaymentFactory::new()->technicianFee()->create(['customer_id' => $customer->id, 'amount' => 2000]);

        [, $payments] = app(ManuscriptChunkDataResolver::class)->resolve([$customer->id], self::PERIOD);

        $this->assertSame(['1000.00'], $payments->get($customer->id)->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
    }

    public function test_installation_and_technician_fee_payments_never_change_arrears_credit_or_total_bill(): void
    {
        $control = $this->customer();
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);

        PaymentFactory::new()->create(['customer_id' => $control->id, 'amount' => 1000]);
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 1000]);
        $installation = PaymentFactory::new()->installation($charge)->create(['amount' => 15000]);
        $fee = PaymentFactory::new()->technicianFee()->create(['customer_id' => $customer->id, 'amount' => 2000]);

        $recalc = app(CustomerManuscriptRecalculationService::class);
        $expected = $recalc->recalculateOne($control->fresh(), self::PERIOD);
        $actual = $recalc->recalculateOne($customer->fresh(), self::PERIOD);

        $this->assertSame((string) $expected->total_arrears, (string) $actual->total_arrears);
        $this->assertSame((string) $expected->credit, (string) $actual->credit);
        $this->assertSame((string) $expected->total_bill, (string) $actual->total_bill);

        // Never consumed by the bill run.
        $this->assertNull($installation->fresh()->processed_period);
        $this->assertNull($fee->fresh()->processed_period);
    }

    public function test_an_installation_payment_alone_does_not_cover_the_month(): void
    {
        $unpaid = $this->customer();
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 20000]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 20000]);

        $recalc = app(CustomerManuscriptRecalculationService::class);
        $expected = $recalc->recalculateOne($unpaid->fresh(), self::PERIOD);
        $manuscript = $recalc->recalculateOne($customer->fresh(), self::PERIOD);

        $this->assertSame('0.00', (string) $manuscript->credit);
        $this->assertSame((string) $expected->total_bill, (string) $manuscript->total_bill, 'Billed exactly like a customer who paid nothing.');
        $this->assertSame(0, (int) $manuscript->prepaid_months_remaining);
    }

    public function test_a_customer_who_only_paid_installation_still_shows_on_the_pre_run_review_as_not_paid(): void
    {
        $customer = $this->customer();
        Manuscript::query()->create([
            'customer_id' => $customer->id, 'period' => '2035-02', 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 2500,
            'prepaid_months_remaining' => 0,
        ]);
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 5000]);

        $review = app(ManuscriptPreRunReviewService::class)->reviewList(self::PERIOD, $customer->zone_id);

        $this->assertContains($customer->uuid, array_column($review['customers'], 'uuid'));
    }

    public function test_reconnecting_with_the_fine_records_a_technician_fee_that_leaves_arrears_alone(): void
    {
        $this->actAs('manager');
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 5000, 'status' => 'disconnected']);
        $control = CustomerFactory::new()->create(['zone_id' => $customer->zone_id, 'bill' => 2500, 'others' => 5000, 'status' => 'active']);

        $this->patch("/customers/{$customer->uuid}/reconnect", ['include_fine' => true])->assertSessionHasNoErrors();

        $fine = Payment::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(Payment::PURPOSE_TECHNICIAN_FEE, $fine->purpose);
        $this->assertSame('verified', $fine->verification_status);

        $recalc = app(CustomerManuscriptRecalculationService::class);
        $this->assertSame(
            (string) $recalc->recalculateOne($control->fresh(), self::PERIOD)->total_arrears,
            (string) $recalc->recalculateOne($customer->fresh(), self::PERIOD)->total_arrears,
            'The fine must not be credited to the customer\'s subscription.',
        );
    }

    public function test_payments_recorded_without_a_purpose_are_subscription_payments(): void
    {
        $this->actAs('manager');
        $customer = $this->customer();

        // An older client (or a forged request) cannot pick a purpose.
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'purpose' => 'installation'])
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(Payment::PURPOSE_SUBSCRIPTION, $payment->fresh()->purpose);
        $this->assertNull($payment->customer_charge_id);
    }

    public function test_the_api_and_offline_sync_record_subscription_payments_and_expose_the_purpose(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly', 'purpose' => 'technician_fee'])
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'subscription');

        $this->assertSame(Payment::PURPOSE_SUBSCRIPTION, Payment::query()->where('uuid', $response->json('data.uuid'))->value('purpose'));
    }

    public function test_a_payments_purpose_cannot_change_once_it_is_verified(): void
    {
        $payment = PaymentFactory::new()->create(['customer_id' => $this->customer()->id, 'verification_status' => 'verified']);

        $this->expectException(LogicException::class);

        $payment->update(['purpose' => Payment::PURPOSE_TECHNICIAN_FEE]);
    }

    public function test_a_pending_payments_purpose_may_still_be_corrected(): void
    {
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $this->customer()->id]);

        $payment->update(['purpose' => Payment::PURPOSE_TECHNICIAN_FEE]);

        $this->assertSame(Payment::PURPOSE_TECHNICIAN_FEE, $payment->fresh()->purpose);
    }

    public function test_an_installation_payment_must_name_its_charge_at_the_database(): void
    {
        $customer = $this->customer();

        $this->expectException(QueryException::class);

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'purpose' => Payment::PURPOSE_INSTALLATION]);
    }

    public function test_installation_charges_are_untouched_by_the_charge_check_for_subscription_payments(): void
    {
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);

        $this->expectException(QueryException::class);

        PaymentFactory::new()->create(['customer_id' => $customer->id, 'customer_charge_id' => $charge->id]);
    }

    public function test_the_open_installation_balance_is_amount_minus_verified_instalments(): void
    {
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 15000]);

        PaymentFactory::new()->installation($charge)->create(['amount' => 5000]);
        PaymentFactory::new()->installation($charge)->pending()->create(['amount' => 4000]);
        PaymentFactory::new()->installation($charge)->rejected()->create(['amount' => 3000]);

        $charge->refresh();
        $this->assertSame('5000.00', $charge->paidAmount());
        $this->assertSame('10000.00', $charge->balance());
        $this->assertSame('6000.00', $charge->recordableAmount());
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status);
    }
}
