<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CustomerChargeService;
use App\Services\CustomerEligibilityService;
use App\Services\ManuscriptService;
use Database\Factories\CustomerChargeFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Installation fees, phase 1 (docs/plans/installation-and-promotions.md):
 * the installation charge on the customer page — opening it, instalments,
 * its separate balance (customer page, API, bill), the installation receipt,
 * and the maker-checker waiver.
 *
 * Two real people for maker ≠ checker: the seeded owner (kelvin, role
 * switched per test) and the seeded admin (patience).
 */
class InstallationChargeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actAs(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function manager(): User
    {
        return $this->actAs('kelvin@shalomtech.dev', 'manager');
    }

    private function admin(): User
    {
        return $this->actAs('patience@shalomtech.dev', 'admin');
    }

    private function customer(array $attributes = []): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 0, 'status' => 'active', ...$attributes]);
    }

    private function openCharge(Customer $customer, string $amount = '15000'): CustomerCharge
    {
        $this->post("/customers/{$customer->uuid}/installation-charges", ['amount' => $amount, 'reason' => 'New connection'])->assertSessionHasNoErrors();

        return CustomerCharge::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    public function test_a_manager_opens_an_installation_charge_and_it_shows_on_the_customer_page(): void
    {
        $this->manager();
        $customer = $this->customer();

        $charge = $this->openCharge($customer);

        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status);
        $this->assertSame('15000.00', (string) $charge->amount);

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.installation.balance', '15000.00')
            ->where('customer.installation.charges.0.status', 'open')
            ->where('customer.installation.charges.0.can.record_payment', true)
            ->where('customer.installation.can_create', true));
    }

    public function test_only_one_open_installation_charge_at_a_time(): void
    {
        $this->manager();
        $customer = $this->customer();
        $this->openCharge($customer);

        $this->post("/customers/{$customer->uuid}/installation-charges", ['amount' => 5000])->assertSessionHasErrors('amount');
    }

    public function test_an_agent_cannot_open_an_installation_charge(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'agent');

        $this->post("/customers/{$this->customer()->uuid}/installation-charges", ['amount' => 5000])->assertForbidden();
    }

    public function test_instalments_reduce_the_balance_until_the_charge_is_paid(): void
    {
        $this->manager();
        $customer = $this->customer();
        $charge = $this->openCharge($customer);

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 10000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('5000.00', $charge->fresh()->balance());
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->fresh()->status);

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 5000, 'method' => 'mtn_momo', 'method_reference' => 'MP-77'])->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);
        $this->assertNotNull($charge->paid_at);
        $this->assertSame('0.00', $charge->balance());
        $this->assertSame('0.00', CustomerChargeService::openInstallationBalance($customer));

        $payments = Payment::query()->where('customer_charge_id', $charge->id)->get();
        $this->assertCount(2, $payments);
        $this->assertTrue($payments->every(fn (Payment $payment): bool => $payment->purpose === Payment::PURPOSE_INSTALLATION && $payment->frequency === 'monthly'));
    }

    public function test_an_instalment_can_never_overpay_the_fee(): void
    {
        $this->manager();
        $charge = $this->openCharge($this->customer(), '10000');

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 12000, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::query()->where('customer_charge_id', $charge->id)->count());
    }

    public function test_a_paid_charge_takes_no_more_payments(): void
    {
        $this->manager();
        $charge = $this->openCharge($this->customer(), '5000');
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 5000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    }

    public function test_a_rejected_instalment_reopens_a_paid_charge(): void
    {
        $this->manager();
        $charge = $this->openCharge($this->customer(), '5000');
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 5000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->fresh()->status);

        $payment = Payment::query()->where('customer_charge_id', $charge->id)->sole();
        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'reject', 'notes' => 'Counterfeit note'])->assertSessionHasNoErrors();

        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->fresh()->status);
        $this->assertSame('5000.00', $charge->fresh()->balance());
    }

    public function test_the_installation_receipt_is_titled_and_shows_fee_paid_and_balance(): void
    {
        $this->manager();
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 5000, 'credit' => 0, 'total_bill' => 7500, 'prepaid_months_remaining' => 0]);
        $charge = $this->openCharge($customer);

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 6000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $receipt = PaymentReceipt::query()->whereHas('payment', fn ($query) => $query->where('customer_charge_id', $charge->id))->sole();
        $snapshot = $receipt->snapshot;

        $this->assertSame('installation', $snapshot['purpose']);
        $this->assertSame('INSTALLATION RECEIPT', $snapshot['title']);
        $this->assertEquals(['fee' => '15000.00', 'paid' => '6000.00', 'balance' => '9000.00', 'status' => 'open'], array_intersect_key($snapshot['installation'], array_flip(['fee', 'paid', 'balance', 'status'])));
        // Phase 2 (D11): no free months on this customer, nothing to print.
        $this->assertSame([], $snapshot['installation']['services']);
        $this->assertNull($snapshot['installation']['first_bill']);
        // Not subscription money: no billing months, no bill balance.
        $this->assertSame([], $snapshot['payment']['periods']);
        $this->assertNull($snapshot['balance']);
        $this->assertNull($snapshot['invoice']);

        // Every layout carries the heading and the fee block.
        $a6 = view('pdf.receipt', ['r' => $snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false])->render();
        $this->assertStringContainsString('INSTALLATION RECEIPT', $a6);
        $this->assertStringContainsString('Installation fee', $a6);
        $this->assertStringContainsString('9,000.00', $a6);

        $thermal = view('pdf.receipt-thermal', ['r' => $snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false, 'widthMm' => 58])->render();
        $this->assertStringContainsString('INSTALLATION RECEIPT', $thermal);
        $this->assertStringContainsString('Install fee', $thermal);
    }

    public function test_a_subscription_receipt_balance_ignores_installation_money(): void
    {
        $this->manager();
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 5000, 'credit' => 0, 'total_bill' => 7500, 'prepaid_months_remaining' => 0]);
        $charge = $this->openCharge($customer);
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 6000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        $receipt = PaymentReceipt::query()->whereHas('payment', fn ($query) => $query->where('customer_id', $customer->id)->where('purpose', Payment::PURPOSE_SUBSCRIPTION))->sole();

        $this->assertSame('subscription', $receipt->snapshot['purpose']);
        $this->assertSame('PAYMENT RECEIPT', $receipt->snapshot['title']);
        $this->assertSame('5000.00', $receipt->snapshot['balance']['remaining'], 'Only the subscription payment counts toward the bill.');
        $this->assertCount(1, $receipt->snapshot['payment']['periods']);
        $this->assertNull($receipt->snapshot['installation']);
    }

    public function test_the_installation_balance_is_a_separate_line_on_the_bill_never_in_the_total(): void
    {
        $this->manager();
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 2500, 'prepaid_months_remaining' => 0]);
        $charge = $this->openCharge($customer);
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 5000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $bill = app(ManuscriptService::class)->billData($customer->fresh(), '2035-01');
        $this->assertSame('10000.00', $bill['installation_balance']);
        $this->assertSame('2500.00', (string) $bill['manuscript']->total_bill);

        $bulk = app(ManuscriptService::class)->billDataForCustomers([$customer->fresh()], '2035-01');
        $this->assertSame('10000.00', $bulk[0]['installation_balance']);

        $this->get("/customers/{$customer->uuid}/bill/print?period=2035-01")->assertOk();
    }

    public function test_an_unpaid_installation_is_never_a_reason_to_disconnect(): void
    {
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 2500, 'prepaid_months_remaining' => 0]);
        CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 50000]);

        $eligible = app(CustomerEligibilityService::class)->eligibleForDisconnection($customer->zone_id, Carbon::create(2035, 1, 20));

        $this->assertNotContains($customer->uuid, $eligible->pluck('uuid')->all());
    }

    public function test_the_api_customer_resource_exposes_the_installation_balance(): void
    {
        $customer = $this->customer();
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 15000]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 4000]);

        $token = $this->tokenForRole('manager');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customer->uuid}")
            ->assertOk()
            ->assertJsonPath('data.installation_balance', '11000.00');
    }

    public function test_a_waiver_needs_someone_other_than_whoever_asked(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'admin');
        $charge = $this->openCharge($this->customer());
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 5000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->post("/installation-charges/{$charge->uuid}/waiver", ['reason' => 'Promotion for the church'])->assertSessionHasNoErrors();
        $this->assertSame(CustomerCharge::WAIVER_PENDING, $charge->fresh()->waiver_status);

        // Maker ≠ checker: the requester (an admin, who holds the permission) cannot approve.
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertForbidden();
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->fresh()->status);

        // A manager doesn't hold installations.approve.
        $this->manager();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertForbidden();

        $approver = $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_WAIVED, $charge->status);
        $this->assertSame(CustomerCharge::WAIVER_APPROVED, $charge->waiver_status);
        $this->assertSame('10000.00', (string) $charge->waived_amount);
        $this->assertSame($approver->id, $charge->waiver_decided_by);
        $this->assertSame('0.00', $charge->balance());
        $this->assertSame('0.00', CustomerChargeService::openInstallationBalance($charge->customer));
    }

    public function test_a_rejected_waiver_leaves_the_charge_open(): void
    {
        $this->manager();
        $charge = $this->openCharge($this->customer());
        $this->post("/installation-charges/{$charge->uuid}/waiver", ['reason' => 'Asked nicely'])->assertSessionHasNoErrors();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/waiver/reject", ['reason' => 'No grounds'])->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status);
        $this->assertSame(CustomerCharge::WAIVER_REJECTED, $charge->waiver_status);
        $this->assertSame('No grounds', $charge->waiver_rejection_reason);
        $this->assertSame('15000.00', $charge->balance());
    }

    public function test_the_owner_may_decide_their_own_waiver_request(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'super');
        $charge = $this->openCharge($this->customer());

        $this->post("/installation-charges/{$charge->uuid}/waiver", ['reason' => 'Owner decision'])->assertSessionHasNoErrors();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertSessionHasNoErrors();

        $this->assertSame(CustomerCharge::STATUS_WAIVED, $charge->fresh()->status);
    }

    public function test_a_waiver_cannot_be_requested_twice_or_on_a_paid_charge(): void
    {
        $this->manager();
        $charge = $this->openCharge($this->customer(), '5000');
        $this->post("/installation-charges/{$charge->uuid}/waiver", ['reason' => 'First ask'])->assertSessionHasNoErrors();
        $this->post("/installation-charges/{$charge->uuid}/waiver", ['reason' => 'Second ask'])->assertForbidden();

        $paid = $this->openCharge($this->customer(), '5000');
        $this->post("/installation-charges/{$paid->uuid}/payments", ['amount' => 5000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->post("/installation-charges/{$paid->uuid}/waiver", ['reason' => 'Too late'])->assertForbidden();
    }
}
