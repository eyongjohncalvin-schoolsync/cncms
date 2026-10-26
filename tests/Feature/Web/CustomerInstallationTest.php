<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Company;
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
use Illuminate\Support\Facades\Cache;
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
        // These tests follow compensation through approval; the yearly
        // allowance without approval is covered in CompensationTest.
        Company::query()->firstOrFail()->update(['compensation_months_without_approval' => 0]);
        Company::forgetCache();
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
        return Customer::query()->where('name', mb_strtoupper($name))->latest('id')->firstOrFail();
    }

    private function bill(Customer $customer, string $period): Manuscript
    {
        return app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer->fresh(), $period, trigger: 'test');
    }

    /**
     * One service's choice on the form (D15): free months from a month, and
     * months paid upfront after them.
     *
     * @return array{service_uuid: string, free_from: ?string, free_months: int, paid_months: int}
     */
    private function plan(Service $service, int $free = 0, int $paid = 0, ?string $from = '2026-10'): array
    {
        return ['service_uuid' => $service->uuid, 'free_from' => $free > 0 ? $from : null, 'free_months' => $free, 'paid_months' => $paid];
    }

    public function test_a_late_registration_counts_free_months_from_the_real_connection(): void
    {
        // Connected 12 Aug, registered 10 Oct with 3 free months: Aug–Oct
        // are free (Aug and Sep already passed), so the first bill is Nov.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();

        $this->post('/customers', $this->form([
            'connected_on' => '2026-08-12',
            'service_plans' => [$this->plan($this->tv, free: 3, from: '2026-08')],
        ]))->assertSessionHasErrors('installation_reason');

        $this->post('/customers', $this->form([
            'connected_on' => '2026-08-12',
            'installation_reason' => 'The agent connected him in August and forgot to register him',
            'service_plans' => [$this->plan($this->tv, free: 3, from: '2026-08')],
        ]))->assertSessionHasNoErrors();

        $customer = $this->created();
        $this->assertSame('2026-08-12', $customer->connected_on->toDateString());
        $this->assertSame('2026-10', $customer->billing_starts_period, 'closed months are never written');

        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-08', $grant->start_period, 'counted from the connection, not moved to now');
        $this->assertSame(3, $grant->months);

        $this->assertSame('0.00', (string) $this->bill($customer, '2026-10')->total_bill, 'October is the last free month');
        $this->assertSame('2500.00', (string) $this->bill($customer, '2026-11')->bill, 'the first bill');
    }

    public function test_a_late_registration_shows_the_months_not_billed(): void
    {
        // Connected in August with one free month: September passed unbilled.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();

        $preview = $this->postJson('/customers/installation-preview', $this->form([
            'connected_on' => '2026-08-12',
            'service_plans' => [$this->plan($this->tv, free: 1, from: '2026-08')],
        ]))->assertOk()->json();

        $this->assertTrue($preview['late'], 'The preview works before the reason is typed.');
        $this->assertSame('2026-08', $preview['connection_month']);
        $this->assertSame('2026-10', $preview['first_bill']);
        $tv = collect($preview['services'])->firstWhere('service_uuid', $this->tv->uuid);
        $this->assertSame(['2026-08', '2026-08'], [$tv['free_from'], $tv['free_until']]);
        $this->assertSame(['2026-09', '2026-09'], [$tv['not_billed_from'], $tv['not_billed_until']]);
        $this->assertStringContainsString('not billed Sep', $preview['line']);
    }

    public function test_approving_late_free_months_keeps_their_start(): void
    {
        // The fee isn't paid, so the free months wait for approval; approving
        // them a month later must not move them forward and give the used ones back.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $this->post('/customers', $this->form([
            'installation_fee' => '10000',
            'installation_paid' => '0',
            'installation_reason' => 'Promised by the agent',
            'service_plans' => [$this->plan($this->tv, free: 2, from: '2026-10')],
        ]))->assertSessionHasNoErrors();
        $grant = FreeMonthGrant::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);

        $this->travelTo(Carbon::parse('2026-11-20 09:00', 'Africa/Lagos'));
        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('2026-10', $grant->fresh()->start_period);
    }

    public function test_example_a_free_months_then_months_paid_upfront_then_billed(): void
    {
        // TV 2,500, installed 10 Oct, fee paid in full: Oct–Nov free (the
        // installation month + one more), 3 months paid upfront (Dec–Feb),
        // first billed month March.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 2, paid: 3)],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $customer = $this->created();
        $this->assertSame('2026-10-10', $customer->connected_on->toDateString());
        $this->assertSame('2026-10', $customer->billing_starts_period, 'the installation month');

        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);
        $this->assertSame($offer->id, $charge->installation_offer_id);

        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_APPROVED, $grant->status, 'fully paid, within the offer: no approval needed');
        $this->assertSame('2026-10', $grant->start_period);
        $this->assertSame(2, $grant->months);

        $payments = Payment::query()->where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame('7500.00', (string) $payments[0]->amount, 'months paid upfront at the service price');
        $this->assertEquals([['service_id' => $this->tv->id, 'months' => 3, 'rate' => '2500.00']], $payments[0]->service_months);
        $this->assertSame([Payment::PURPOSE_INSTALLATION, '10000.00'], [$payments[1]->purpose, (string) $payments[1]->amount]);

        // A receipt per payment; the installation receipt carries each
        // service's free and paid periods and first billed month (D11).
        $this->assertSame(2, PaymentReceipt::query()->whereIn('payment_id', $payments->pluck('id'))->count());
        $installation = PaymentReceipt::query()->where('payment_id', $payments[1]->id)->sole()->snapshot['installation'];
        $this->assertEquals(['service' => 'TV Service', 'free' => 'Oct–Nov 2026', 'paid' => 'Dec 2026–Feb 2027', 'first_billed' => 'Mar 2027'], $installation['services'][0]);
        $this->assertSame('2027-03', $installation['first_bill']);

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('customer.coverage.label', 'TV Service: free Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from Mar 2027')
            ->where('customer.installation.applicable', true));

        foreach (['2026-10', '2026-11', '2026-12', '2027-01', '2027-02'] as $period) {
            $this->assertSame('0.00', (string) $this->bill($customer, $period)->total_bill, "{$period}: nothing to pay");
        }

        $march = $this->bill($customer, '2027-03');
        $this->assertSame('2500.00', (string) $march->bill, 'first billed month March');
        $this->assertSame('5000.00', (string) $march->total_bill, 'same pay-ahead rule as every customer (D18)');
    }

    public function test_the_preview_shows_each_service_timeline_and_what_to_collect(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->postJson('/customers/installation-preview', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 2, paid: 3)],
        ]))->assertOk()
            ->assertJsonPath('collect_now', '17500.00')
            ->assertJsonPath('upfront_amount', '7500.00')
            ->assertJsonPath('installation_month', '2026-10')
            ->assertJsonPath('first_bill', '2027-03')
            ->assertJsonPath('services.0.paid_until', '2027-02')
            ->assertJsonPath('line', 'Collect now: 17,500 · TV Service: free Oct–Nov 2026, paid Dec 2026–Feb 2027, billed from Mar 2027');
    }

    public function test_example_b_per_service_free_and_paid_months(): void
    {
        // TV free Oct–Nov + 1 paid, Internet free Oct + 2 paid: 7,500 upfront,
        // both billed from January.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();

        $this->postJson('/customers/installation-preview', $this->form([
            'service_plans' => [$this->plan($this->tv, free: 2, paid: 1), $this->plan($this->internet, free: 1, paid: 2)],
        ], [$this->tv, $this->internet]))->assertOk()
            ->assertJsonPath('upfront_amount', '7500.00')
            ->assertJsonPath('collect_now', '7500.00')
            ->assertJsonPath('first_bill', '2027-01')
            ->assertJsonPath('services.0.paid_from', '2026-12')
            ->assertJsonPath('services.1.paid_from', '2026-11')
            ->assertJsonPath('services.1.paid_until', '2026-12');
    }

    public function test_the_installation_month_can_be_unticked_and_is_then_billed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();

        $this->postJson('/customers/installation-preview', $this->form([
            'service_plans' => [$this->plan($this->tv, free: 1, from: '2026-11')],
        ]))->assertOk()->assertJsonPath('services.0.first_billed', '2026-10');

        $this->postJson('/customers/installation-preview', $this->form([
            'service_plans' => [$this->plan($this->tv, free: 1, from: '2026-09')],
        ]))->assertStatus(422);
    }

    public function test_example_c_free_months_without_the_installation_fully_paid_need_approval(): void
    {
        // D17: 6,000 of 10,000 paid at registration — the free months wait
        // for an admin/owner; meanwhile the customer is billed.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $form = ['installation_offer_uuid' => $offer->uuid, 'installation_paid' => '6000', 'service_plans' => [$this->plan($this->tv, free: 2)]];

        $this->post('/customers', $this->form($form))->assertSessionHasErrors('installation_reason');
        $this->post('/customers', $this->form([...$form, 'installation_reason' => 'Pays the rest on Friday']))->assertSessionHasNoErrors();

        $customer = $this->created();
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);
        $this->assertSame('2500.00', (string) $this->bill($customer, '2026-10')->bill, 'billed while waiting');

        // Paying the rest later does not start them by itself (D17).
        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 4000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->fresh()->status);

        $this->post("/free-months/{$grant->uuid}/approve")->assertForbidden();
        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('2026-10', $grant->fresh()->start_period, 'from the month chosen');
        $this->assertSame('0.00', (string) $this->bill($customer, '2026-10')->bill, 'October now free');
    }

    public function test_example_d_a_customer_added_after_next_months_bills_were_published_gets_every_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 09:00', 'Africa/Lagos'));
        foreach (['2026-10', '2026-11'] as $period) {
            CommandRun::query()->create(['command' => 'manuscript:calculate', 'period' => $period, 'ran_at' => now(), 'status' => 'published', 'published_at' => now()]);
        }
        $this->manager();

        $this->post('/customers', $this->form(['service_plans' => [$this->plan($this->tv, free: 1)]], name: 'Late Promo Customer'))->assertSessionHasNoErrors();
        $this->post('/customers', $this->form(name: 'Late Plain Customer'))->assertSessionHasNoErrors();

        $promo = $this->created('Late Promo Customer');
        $row = fn (Customer $customer, string $period) => Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->sole();

        $this->assertSame('0.00', (string) $row($promo, '2026-10')->total_bill, 'installation month free');
        $this->assertSame('2500.00', (string) $row($promo, '2026-11')->bill, 'November billed — not skipped');

        $plain = $this->created('Late Plain Customer');
        $this->assertSame('2500.00', (string) $row($plain, '2026-10')->bill, 'installation month billed');
        $this->assertSame('2500.00', (string) $row($plain, '2026-11')->bill);
    }

    public function test_a_fee_below_the_offer_waits_for_someone_elses_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $form = ['installation_offer_uuid' => $offer->uuid, 'installation_fee' => '5000', 'installation_paid' => '5000'];
        $this->post('/customers', $this->form($form))->assertSessionHasErrors('installation_reason');
        $this->post('/customers', $this->form([...$form, 'installation_reason' => 'Neighbour of the zone chief']))->assertSessionHasNoErrors();

        $charge = CustomerCharge::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(CustomerCharge::DECISION_PENDING, $charge->approval_status);
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status, 'not settled until the lower fee is approved');

        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertSessionHasNoErrors();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->fresh()->status);
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

    public function test_months_beyond_the_offer_preset_need_approval(): void
    {
        // D19: the offer ticks TV 2 free months; the user may change it, but
        // 3 is beyond the preset.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 3)],
            'installation_reason' => 'Promised by the field agent',
        ]))->assertSessionHasNoErrors();

        $grant = FreeMonthGrant::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);
        $this->assertSame(2, $grant->standard_months);

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/reject", ['reason' => 'Two is the offer'])->assertSessionHasNoErrors();
        $this->assertSame(FreeMonthGrant::STATUS_REJECTED, $grant->fresh()->status);
    }

    public function test_more_than_the_company_cap_needs_an_admin_and_a_reason(): void
    {
        // D16: max 3 free months unless an admin/owner approves more.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();

        $this->post('/customers', $this->form(['service_plans' => [$this->plan($this->tv, free: 4)]]))->assertSessionHasErrors('installation_reason');
        $this->post('/customers', $this->form(['service_plans' => [$this->plan($this->tv, free: 4)], 'installation_reason' => 'Church']))->assertSessionHasNoErrors();

        $grant = FreeMonthGrant::query()->where('customer_id', $this->created()->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();
        $grant->refresh();
        $this->assertSame(4, $grant->months);
        $this->assertNotNull($grant->decided_by);
        $this->assertSame('Church', $grant->note, 'the reason is recorded');
    }

    public function test_a_full_waiver_and_its_free_months_both_need_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();

        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_fee' => '0',
            'service_plans' => [$this->plan($this->tv, free: 2)],
            'installation_reason' => 'Church installation',
        ]))->assertSessionHasNoErrors();

        $customer = $this->created();
        $charge = CustomerCharge::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(CustomerCharge::WAIVER_PENDING, $charge->waiver_status);
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(FreeMonthGrant::STATUS_PENDING_APPROVAL, $grant->status);

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertSessionHasNoErrors();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame(CustomerCharge::STATUS_WAIVED, $charge->fresh()->status);
        $this->assertSame('2026-10', $grant->fresh()->start_period);
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
        $this->assertSame($this->tv->id, $grant->service_id, 'one row per service, on its own timeline (D20)');
        $this->assertSame('pending', ArrearsAdjustment::query()->find($grant->arrears_adjustment_id)->status);

        $this->post("/free-months/{$grant->uuid}/approve")->assertForbidden();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();
        $this->assertSame('2026-11', $grant->fresh()->start_period);
    }

    public function test_compensation_goes_after_the_services_free_and_paid_months(): void
    {
        // D20: TV free Oct–Nov, paid Dec–Feb → one compensation month is
        // March, so no paid month is lost.
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 2, paid: 3)],
        ]))->assertSessionHasNoErrors();
        $customer = $this->created();

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Outage'])->assertSessionHasNoErrors();
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->where('reason', FreeMonthGrant::REASON_COMPENSATION)->sole();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('2027-03', $grant->fresh()->start_period);
    }

    public function test_compensation_for_one_service_waits_for_the_months_paid_ahead(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $customer = CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'bill' => 5000, 'status' => 'active']);
        $customer->subscriptions()->create(['service_id' => $this->tv->id, 'price' => 2500]);
        $customer->subscriptions()->create(['service_id' => $this->internet->id, 'price' => 2500]);
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2026-10', 'bill' => 5000, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 0, 'prepaid_months_remaining' => 3, 'prepaid_rate' => 5000]);

        $this->post("/customers/{$customer->uuid}/free-months", ['months' => 1, 'reason' => 'Internet down', 'service_uuids' => [$this->internet->uuid]])->assertSessionHasNoErrors();
        $grant = FreeMonthGrant::query()->where('customer_id', $customer->id)->sole();

        $this->admin();
        $this->post("/free-months/{$grant->uuid}/approve")->assertSessionHasNoErrors();

        $this->assertSame('2027-02', $grant->fresh()->start_period, 'after Nov–Jan paid');
    }

    public function test_refund_installation_is_approved_by_someone_else_and_paid_out_through_a_bon(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 2)],
        ]))->assertSessionHasNoErrors();
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

        $grant = FreeMonthGrant::query()->where('customer_charge_id', $charge->id)->sole();
        $this->assertSame(1, $grant->months, 'October was given; November forfeited');
        $this->assertNotNull($grant->cancelled_at);
    }

    public function test_the_monthly_report_shows_installations_promo_cost_and_who_gave_what(): void
    {
        Cache::flush();
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Africa/Lagos'));
        $this->manager();
        $offer = $this->offer();
        $this->post('/customers', $this->form([
            'installation_offer_uuid' => $offer->uuid,
            'installation_paid' => '10000',
            'service_plans' => [$this->plan($this->tv, free: 2)],
        ]))->assertSessionHasNoErrors();
        $customer = $this->created();
        $this->bill($customer, '2026-10');
        $this->bill($customer, '2026-11');

        $this->admin();

        $this->get('/reports?tier=monthly&date=2026-10')->assertOk()->assertInertia(fn ($page) => $page
            ->where('report.installations.by_offer.0.offer', $offer->name)
            ->where('report.installations.by_offer.0.count', 1)
            ->where('report.installations.installation_income', '10000.00'));

        $this->get('/reports?tier=monthly&date=2026-11')->assertOk()->assertInertia(fn ($page) => $page
            ->where('report.installations.promo_cost', '2500.00')
            ->where('report.installations.customers_on_promo', 1)
            ->where('report.installations.ending_soon.0.ends', '2026-11'));
    }

    public function test_the_preview_warns_about_a_possible_duplicate(): void
    {
        $this->manager();
        CustomerFactory::new()->create(['zone_id' => $this->zone->id, 'name' => 'Ebai Ndip', 'phone' => '+237 677 123 456']);

        $this->postJson('/customers/installation-preview', [...$this->form(name: 'Someone Else'), 'phone' => '677123456'])
            ->assertOk()
            ->assertJsonPath('duplicates.0.name', 'EBAI NDIP');
    }
}
