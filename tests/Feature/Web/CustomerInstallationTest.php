<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\FreeMonthGrant;
use App\Models\InstallationOffer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Service;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Zone;
use App\Services\CustomerManuscriptRecalculationService;
use Database\Factories\CustomerFactory;
use Database\Factories\InstallationOfferFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Adding a customer with their installation and first payment, and what
 * follows (docs/plans/installation-and-promotions.md, phase 2): the owner's
 * worked examples (a), (c), (d), the approvals beyond an offer (D8), the
 * compensation grant (D12) and the installation refund through a bon (D9).
 *
 * Two real people for maker ≠ checker: the seeded owner (kelvin, role
 * switched per test) and the seeded admin (patience).
 */
class CustomerInstallationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Zone $zone;

    private Service $tv;

    private Service $internet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->zone = ZoneFactory::new()->create();
        $this->tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $this->tv->update(['price' => 2500]);
        $this->internet = Service::query()->where('slug', 'internet')->firstOrFail();
        $this->internet->update(['price' => 2500, 'active' => true]);
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

    /**
     * @param  array<string, int>  $months  service slug => free months
     */
    private function offer(string $fee = '10000', array $months = ['tv' => 2]): InstallationOffer
    {
        $offer = InstallationOfferFactory::new()->create(['fee' => $fee]);

        foreach ($months as $slug => $count) {
            $offer->serviceMonths()->create(['service_id' => Service::query()->where('slug', $slug)->value('id'), 'free_months' => $count]);
        }

        return $offer;
    }

    /**
     * @param  list<Service>|null  $services
     * @return array<string, mixed>
     */
    private function form(array $installation = [], ?array $services = null, string $name = 'New Installation Customer'): array
    {
        return [
            'zone_uuid' => $this->zone->uuid,
            'name' => $name,
            'phone' => '677'.random_int(100000, 999999),
            'services' => array_map(fn (Service $service): array => ['service_uuid' => $service->uuid, 'price' => '2500'], $services ?? [$this->tv]),
            'method' => 'cash',
            ...$installation,
        ];
    }

    private function created(string $name = 'New Installation Customer'): Customer
    {
        return Customer::query()->where('name', $name)->latest('id')->firstOrFail();
    }

    private function bill(Customer $customer, string $period): Manuscript
    {
        return app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer->fresh(), $period, trigger: 'test');
    }

    public function test_example_a_installation_paid_with_two_free_months_and_a_three_month_advance(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'advance_months' => 3,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $customer = $this->created();
        $this->assertSame('2026-10-10', $customer->connected_on->toDateString());
        $this->assertSame('2026-11', $customer->billing_starts_period);

        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);
        $this->assertSame('10000.00', (string) $charge->standard_amount);
        $this->assertSame($offer->id, $charge->installation_offer_id);

        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
        $this->assertSame('2026-11', $grant->start_period);
        $this->assertSame(2, $grant->months);

        $payments = Payment::query()->where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame([Payment::PURPOSE_SUBSCRIPTION, '7500.00', 3], [$payments[0]->purpose, (string) $payments[0]->amount, $payments[0]->months]);
        $this->assertSame([Payment::PURPOSE_INSTALLATION, '10000.00'], [$payments[1]->purpose, (string) $payments[1]->amount]);

        // A receipt per payment; the installation receipt carries the free
        // period, the advance and the first bill month (D11).
        $this->assertSame(2, PaymentReceipt::query()->whereIn('payment_id', $payments->pluck('id'))->count());
        $installation = PaymentReceipt::query()->where('payment_id', $payments[1]->id)->sole()->snapshot['installation'];
        $this->assertSame('Nov 2026', $installation['free'][0]['from_label']);
        $this->assertSame('Dec 2026', $installation['free'][0]['until_label']);
        $this->assertSame('Mar 2027', $installation['paid_until_label']);
        $this->assertSame('2027-04', $installation['first_bill']);

        foreach (['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'] as $period) {
            $this->assertSame('0.00', (string) $this->bill($customer, $period)->total_bill, "{$period}: nothing to pay");
        }

        $april = $this->bill($customer, '2027-04');
        $this->assertSame('2500.00', (string) $april->total_bill, 'first bill April');

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('customer.installation.applicable', true)
            ->where('customer.installation.charges.0.offer_name', $offer->name));
    }

    public function test_the_preview_computes_what_to_collect_and_the_first_bill(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->postJson('/customers/installation-preview', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'advance_months' => 3,
        ]))->assertOk()
            ->assertJsonPath('collect_now', '17500.00')
            ->assertJsonPath('advance.amount', '7500.00')
            ->assertJsonPath('first_bill', '2027-04')
            ->assertJsonPath('line', 'Collect now: 17,500 · TV Service free Nov–Dec · first bill Apr 2027');
    }

    public function test_example_b_a_three_month_advance_with_per_service_free_months_costs_12500(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer('10000', ['tv' => 2, 'internet' => 1]);

        $this->postJson('/customers/installation-preview', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'advance_months' => 3,
        ], [$this->tv, $this->internet]))->assertOk()
            ->assertJsonPath('advance.amount', '12500.00')
            ->assertJsonPath('advance.from', '2026-12')
            ->assertJsonPath('advance.until', '2027-02')
            ->assertJsonPath('first_bill', '2027-03');
    }

    public function test_example_c_installation_in_instalments_starts_the_free_months_after_the_last_one(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '6000',
        ]))->assertSessionHasNoErrors();

        $customer = $this->created();
        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status);
        $this->assertSame(FreeMonthGrant::STATUS_AWAITING_INSTALLATION_PAYMENT, FreeMonthGrant::query()->where('customer_id', $customer->id)->value('status'));

        // Billed normally meanwhile (D3).
        $this->travelTo(Carbon::parse('2026-10-25 09:00', 'Africa/Lagos'));
        $this->assertSame('2500.00', (string) $this->bill($customer, '2026-11')->total_bill);
        $this->travelTo(Carbon::parse('2026-11-25 09:00', 'Africa/Lagos'));
        $this->assertSame('5000.00', (string) $this->bill($customer, '2026-12')->total_bill);

        // January's bills are already out when the last instalment comes in
        // on 20 Dec — January is still the first free month (D4).
        $this->travelTo(Carbon::parse('2026-12-20 09:00', 'Africa/Lagos'));
        $this->assertSame('2500.00', (string) $this->bill($customer, '2027-01')->bill);

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 4000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
        $this->assertSame('2027-01', $grant->start_period);

        $january = Manuscript::query()->where('customer_id', $customer->id)->where('period', '2027-01')->sole();
        $this->assertSame('0.00', (string) $january->bill, 'January recomputed as free');
        $this->assertSame('2500.00', (string) $january->free_value);
        $this->assertSame('5000.00', (string) $january->total_bill, 'only the unpaid Nov–Dec');
    }

    public function test_example_d_a_customer_added_after_next_months_bills_were_published_gets_that_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 09:00', 'Africa/Lagos'));
        CommandRun::query()->create(['command' => 'manuscript:calculate', 'period' => '2026-11', 'ran_at' => now(), 'status' => 'published', 'published_at' => now()]);
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
        ], name: 'Late Promo Customer'))->assertSessionHasNoErrors();
        $this->post('/customers', $this->form(name: 'Late Plain Customer'))->assertSessionHasNoErrors();

        $promo = $this->created('Late Promo Customer');
        $november = Manuscript::query()->where('customer_id', $promo->id)->where('period', '2026-11')->sole();
        $this->assertSame('0.00', (string) $november->total_bill);
        $this->assertSame('2500.00', (string) $november->free_value);

        $this->assertSame('0.00', (string) $this->bill($promo, '2026-12')->total_bill);
        $this->assertSame('2500.00', (string) $this->bill($promo, '2027-01')->total_bill, 'first bill January');

        // Without free months: November is billed, not skipped.
        $plain = $this->created('Late Plain Customer');
        $this->assertSame('2500.00', (string) Manuscript::query()->where('customer_id', $plain->id)->where('period', '2026-11')->value('total_bill'));
    }

    public function test_a_fee_below_the_offer_waits_for_someone_elses_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_fee' => '5000',
            'installation_paid' => '5000',
        ]))->assertSessionHasErrors('installation_reason');

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_fee' => '5000',
            'installation_paid' => '5000',
            'installation_reason' => 'Neighbour of the zone chief',
        ]))->assertSessionHasNoErrors();

        $charge = CustomerCharge::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(CustomerCharge::DECISION_PENDING, $charge->approval_status);
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status, 'not settled until the lower fee is approved');

        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertSessionHasNoErrors();

        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->fresh()->status);
        $this->assertSame('2026-11', FreeMonthGrant::query()->where('customer_charge_id', $charge->id)->value('start_period'));
    }

    public function test_rejecting_a_lower_fee_puts_the_offer_fee_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_fee' => '5000',
            'installation_reason' => 'Asked for a discount',
        ]))->assertSessionHasNoErrors();

        $charge = CustomerCharge::query()->where('customer_id', $this->created()->id)->sole();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/fee/reject", ['reason' => 'No discounts this month'])->assertSessionHasNoErrors();

        $this->assertSame('10000.00', (string) $charge->fresh()->amount);
        $this->assertSame(CustomerCharge::DECISION_REJECTED, $charge->fresh()->approval_status);
    }

    public function test_months_beyond_the_offer_need_approval_and_a_rejection_keeps_the_offer_months(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'free_months' => [['service_uuid' => $this->tv->uuid, 'months' => 3]],
            'installation_reason' => 'Promised by the field agent',
        ]))->assertSessionHasNoErrors();

        $grant = FreeMonthGrant::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);

        $this->post("/free-months/{$grant->uuid}/approve")->assertForbidden();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/reject", ['reason' => 'Two is the offer'])->assertSessionHasNoErrors();

        $grant->refresh();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status);
        $this->assertSame(2, $grant->months);
        $this->assertSame('2026-11', $grant->start_period);
    }

    public function test_free_months_are_capped_per_grant(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'free_months' => [['service_uuid' => $this->tv->uuid, 'months' => 4]],
            'installation_reason' => 'Too generous',
        ]))->assertSessionHasErrors('free_months');
    }

    public function test_a_full_waiver_is_asked_for_and_starts_the_free_months_once_approved(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_fee' => '0',
            'installation_reason' => 'Church installation',
        ]))->assertSessionHasNoErrors();

        $charge = CustomerCharge::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame('10000.00', (string) $charge->amount);
        $this->assertSame(CustomerCharge::WAIVER_PENDING, $charge->waiver_status);

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertSessionHasNoErrors();

        $this->assertSame(CustomerCharge::STATUS_WAIVED, $charge->fresh()->status);
        $this->assertSame('2026-11', FreeMonthGrant::query()->where('customer_charge_id', $charge->id)->value('start_period'));
    }

    public function test_an_agent_cannot_set_an_installation(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'agent');

        $this->post('/customers', $this->form(['installation_fee' => '10000']))->assertForbidden();
    }

    public function test_a_compensation_grant_with_an_arrears_write_off_needs_someone_elses_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $customer = CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'bill' => 2500, 'status' => 'active']);
        $customer->subscriptions()->create(['service_id' => $this->tv->id, 'price' => 2500]);

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 2, 'reason' => 'No signal for three weeks', 'write_off_amount' => '2500'])->assertSessionHasNoErrors();

        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::REASON_COMPENSATION, $grant->reason);
        $this->assertNull($grant->service_id, 'all services');
        $this->assertNotNull($grant->arrears_adjustment_id);
        $this->assertSame('pending', ArrearsAdjustment::query()->find($grant->arrears_adjustment_id)->status);

        $this->post("/free-months/{$grant->uuid}/approve")->assertForbidden();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('2026-11', $grant->fresh()->start_period);
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->fresh()->status);
    }

    public function test_compensation_stacks_after_free_months_already_running(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $this->post('/customers', $this->form(['installation_offer_uuid' => $offer->uuid, 'installation_paid' => '10000']))->assertSessionHasNoErrors();
        $customer = $this->created();

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Outage'])->assertSessionHasNoErrors();
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->where('reason', FreeMonthGrant::REASON_COMPENSATION)->sole();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('2027-01', $grant->fresh()->start_period, 'after the installation months Nov–Dec');
    }

    public function test_refund_installation_is_approved_by_someone_else_and_paid_out_through_a_bon(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $this->post('/customers', $this->form(['installation_offer_uuid' => $offer->uuid, 'installation_paid' => '10000']))->assertSessionHasNoErrors();
        $customer = $this->created();
        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();

        $this->post("/installation-charges/{$charge->uuid}/refund", ['amount' => 12000, 'reason' => 'Could not connect'])->assertSessionHasErrors('amount');
        $this->post("/installation-charges/{$charge->uuid}/refund", ['amount' => 10000, 'reason' => 'Could not connect'])->assertSessionHasNoErrors();
        $this->post("/installation-charges/{$charge->uuid}/refund/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/refund/approve")->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_REFUNDED, $charge->status);
        $voucher = Voucher::query()->findOrFail($charge->refund_voucher_id);
        $this->assertSame('10000.00', (string) $voucher->amount);
        $this->assertSame('Customer refunds', $voucher->category->name);
        $this->assertSame(FreeMonthGrant::STATUS_CANCELLED, FreeMonthGrant::query()->where('customer_charge_id', $charge->id)->value('status'), 'free months not started yet are forfeited');
    }

    public function test_the_preview_warns_about_a_possible_duplicate(): void
    {
        $this->manager();
        CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'name' => 'Ebai Ndip', 'phone' => '+237 677 123 456']);

        $this->postJson('/customers/installation-preview', [...$this->form(name: 'Someone Else'), 'phone' => '677123456'])
            ->assertOk()
            ->assertJsonPath('duplicates.0.name', 'Ebai Ndip');
    }
}
