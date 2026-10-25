<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Zone;
use App\Services\CustomerEligibilityService;
use App\Services\CustomerManuscriptRecalculationService;
use App\Services\ManuscriptService;
use App\Support\BusinessTimezone;
use Database\Factories\CustomerChargeFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Relocation and re-installation fees (docs/plans/installation-and-promotions.md,
 * "Out of scope / later"): the installation charge model with kinds
 * `relocation` / `reinstallation`. Covers the Relocate / Re-install actions
 * (default fee, lower fee and waiver maker-checker, payment now, address and
 * zone move), the money staying out of billing, arrears, credit and
 * disconnection, instalments, refunds by bon, receipts, bill lines,
 * reports, the API, settings, permissions and the impersonation guard.
 *
 * Two real people for maker ≠ checker: the seeded owner (kelvin, role
 * switched per test) and the seeded admin (patience).
 */
class RelocationChargeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    /** A far-future period no real data can collide with. */
    private const string PERIOD = '2035-04';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->setDefaultFees('5000', '7500');
    }

    private function setDefaultFees(?string $relocation, ?string $reinstallation): void
    {
        Company::query()->firstOrFail()->update(['relocation_fee' => $relocation, 'reinstallation_fee' => $reinstallation]);
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

    private function customer(array $attributes = []): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'location' => 'Fiango', 'bill' => 2500, 'others' => 0, 'status' => 'active', ...$attributes]);
    }

    private function charge(Customer $customer, string $kind): CustomerCharge
    {
        return CustomerCharge::query()->where('customer_id', $customer->id)->where('kind', $kind)->latest('id')->firstOrFail();
    }

    private function relocate(Customer $customer, array $data = []): CustomerCharge
    {
        $this->post("/customers/{$customer->uuid}/relocation", ['location' => 'Mbonge Road', ...$data])->assertSessionHasNoErrors();

        return $this->charge($customer, CustomerCharge::KIND_RELOCATION);
    }

    private function reinstall(Customer $customer, array $data = []): CustomerCharge
    {
        $this->post("/customers/{$customer->uuid}/reinstallation", $data)->assertSessionHasNoErrors();

        return $this->charge($customer, CustomerCharge::KIND_REINSTALLATION);
    }

    public function test_relocating_moves_the_customer_records_the_fee_and_payment_and_issues_a_relocation_receipt(): void
    {
        $this->manager();
        $customer = $this->customer();
        $fromZone = $customer->zone;
        $toZone = ZoneFactory::new()->create();

        $charge = $this->relocate($customer, ['zone_uuid' => $toZone->uuid, 'paid_now' => 2000, 'method' => 'cash', 'note' => 'Moved near the market']);

        $this->assertSame('5000.00', (string) $charge->amount, 'The default fee applies.');
        $this->assertSame('5000.00', (string) $charge->standard_amount);
        $this->assertNull($charge->approval_status);
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->status);
        $this->assertSame($fromZone->id, $charge->from_zone_id);
        $this->assertSame($toZone->id, $charge->to_zone_id);
        $this->assertSame('FIANGO', $charge->from_location);
        $this->assertSame('MBONGE ROAD', $charge->to_location);
        $this->assertSame('Moved near the market', $charge->note);
        $this->assertSame('3000.00', $charge->balance());

        $customer->refresh();
        $this->assertSame($toZone->id, $customer->zone_id, 'Same account, new zone.');
        $this->assertSame('MBONGE ROAD', $customer->location);

        $payment = Payment::query()->where('customer_charge_id', $charge->id)->sole();
        $this->assertSame(Payment::PURPOSE_INSTALLATION, $payment->purpose);

        $snapshot = PaymentReceipt::query()->where('payment_id', $payment->id)->sole()->snapshot;
        $this->assertSame('RELOCATION RECEIPT', $snapshot['title']);
        $this->assertSame('relocation', $snapshot['charge_kind']);
        $this->assertSame('Relocation fee', $snapshot['description']);
        $this->assertSame(['fee' => '5000.00', 'paid' => '2000.00', 'balance' => '3000.00'], array_intersect_key($snapshot['installation'], array_flip(['fee', 'paid', 'balance'])));
        $this->assertNull($snapshot['balance'], 'No subscription balance on a one-off receipt.');
        $this->assertSame([], $snapshot['payment']['periods']);

        $a6 = view('pdf.receipt', ['r' => $snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false])->render();
        $this->assertStringContainsString('RELOCATION RECEIPT', $a6);
        $this->assertStringContainsString('Relocation fee', $a6);
        $thermal = view('pdf.receipt-thermal', ['r' => $snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false, 'widthMm' => 58])->render();
        $this->assertStringContainsString('RELOCATION RECEIPT', $thermal);
        $this->assertStringContainsString('Reloc. fee', $thermal);

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Show')
            ->where('customer.one_off_charges.balance', '3000.00')
            ->where('customer.one_off_charges.charges.0.kind', 'relocation')
            ->where('customer.one_off_charges.charges.0.to_location', 'MBONGE ROAD')
            ->where('customer.one_off_charges.charges.0.to_zone_name', $toZone->name)
            ->where('customer.one_off_charges.default_fees.relocation', '5000.00')
            ->where('customer.one_off_charges.can_relocate', true)
            ->where('customer.installation.balance', '0.00')
            ->has('customer.installation.charges', 0));
    }

    public function test_relocating_within_the_same_zone_keeps_the_zone(): void
    {
        $this->manager();
        $customer = $this->customer();

        $charge = $this->relocate($customer);

        $this->assertSame($customer->zone_id, $charge->to_zone_id);
        $this->assertSame($customer->zone_id, $customer->fresh()->zone_id);
        $this->assertSame('MBONGE ROAD', $customer->fresh()->location);
    }

    public function test_relocating_to_an_unknown_zone_changes_nothing(): void
    {
        $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/relocation", ['location' => 'Mbonge Road', 'zone_uuid' => '00000000-0000-4000-8000-000000000000', 'paid_now' => 1000, 'method' => 'cash'])
            ->assertSessionHasErrors('zone_uuid');

        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, Payment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('FIANGO', $customer->fresh()->location);
    }

    public function test_a_relocation_needs_the_new_address(): void
    {
        $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/relocation", [])->assertSessionHasErrors('location');
        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());
    }

    public function test_reinstalling_records_the_fee_without_changing_the_address_and_issues_a_reinstallation_receipt(): void
    {
        $this->manager();
        $customer = $this->customer();

        $charge = $this->reinstall($customer, ['paid_now' => 7500, 'method' => 'mtn_momo', 'method_reference' => 'MP-991']);

        $charge->refresh();
        $this->assertSame('7500.00', (string) $charge->amount);
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status, 'Paid in full at once.');
        $this->assertNull($charge->to_location);
        $this->assertSame('FIANGO', $customer->fresh()->location);
        $this->assertSame($customer->zone_id, $customer->fresh()->zone_id);

        $payment = Payment::query()->where('customer_charge_id', $charge->id)->sole();
        $snapshot = PaymentReceipt::query()->where('payment_id', $payment->id)->sole()->snapshot;
        $this->assertSame('RE-INSTALLATION RECEIPT', $snapshot['title']);
        $this->assertSame('Re-installation fee', $snapshot['description']);

        $a6 = view('pdf.receipt', ['r' => $snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false])->render();
        $this->assertStringContainsString('RE-INSTALLATION RECEIPT', $a6);
        $this->assertStringContainsString('Re-installation fee', $a6);
    }

    public function test_an_entered_fee_overrides_the_default_upward_without_approval(): void
    {
        $this->manager();
        $charge = $this->reinstall($this->customer(), ['fee' => 9000]);

        $this->assertSame('9000.00', (string) $charge->amount);
        $this->assertNull($charge->approval_status);
    }

    public function test_without_a_default_the_fee_must_be_entered(): void
    {
        $this->setDefaultFees(null, null);
        $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/reinstallation", [])->assertSessionHasErrors('fee');
        $this->post("/customers/{$customer->uuid}/reinstallation", ['fee' => 0])->assertSessionHasErrors('fee');
        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());

        $charge = $this->reinstall($customer, ['fee' => 4000]);
        $this->assertSame('4000.00', (string) $charge->amount);
        $this->assertNull($charge->standard_amount);
        $this->assertNull($charge->approval_status, 'Nothing to approve against.');
    }

    public function test_a_lower_fee_needs_a_reason_and_approval_by_someone_else(): void
    {
        $manager = $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/relocation", ['location' => 'Mbonge Road', 'fee' => 3000])->assertSessionHasErrors('reason');
        $this->assertSame('FIANGO', $customer->fresh()->location, 'Nothing moves when the fee is refused.');

        $charge = $this->relocate($customer, ['fee' => 3000, 'reason' => 'Neighbour discount', 'paid_now' => 3000, 'method' => 'cash']);

        $this->assertSame('3000.00', (string) $charge->amount);
        $this->assertSame(CustomerCharge::DECISION_PENDING, $charge->approval_status);
        $this->assertSame($manager->id, $charge->requested_by);
        $this->assertSame(CustomerCharge::STATUS_OPEN, $charge->fresh()->status, 'Not paid while the lower fee waits for approval.');

        // A manager doesn't hold installations.approve; the requester can't decide either.
        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertForbidden();
        $this->actAs('kelvin@shalomtech.dev', 'admin');
        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/fee/approve")->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::DECISION_APPROVED, $charge->approval_status);
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);
    }

    public function test_a_rejected_lower_fee_falls_back_to_the_default(): void
    {
        $this->manager();
        $charge = $this->reinstall($this->customer(), ['fee' => 5000, 'reason' => 'Old customer']);

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/fee/reject", ['reason' => 'No'])->assertSessionHasNoErrors();

        $this->assertSame('7500.00', (string) $charge->fresh()->amount);
    }

    public function test_a_waiver_is_requested_with_a_reason_and_approved_by_someone_else(): void
    {
        $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/reinstallation", ['fee' => 0])->assertSessionHasErrors('reason');
        $this->post("/customers/{$customer->uuid}/reinstallation", ['fee' => 0, 'reason' => 'Our fault', 'paid_now' => 1000, 'method' => 'cash'])->assertSessionHasErrors('paid_now');

        $charge = $this->reinstall($customer, ['fee' => 0, 'reason' => 'Our cable was faulty']);

        $this->assertSame('7500.00', (string) $charge->amount, 'The default fee is what gets waived.');
        $this->assertSame(CustomerCharge::WAIVER_PENDING, $charge->waiver_status);
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/waiver/approve")->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_WAIVED, $charge->status);
        $this->assertSame('7500.00', (string) $charge->waived_amount);
        $this->assertSame('0.00', $charge->balance());
    }

    public function test_instalments_pay_the_fee_down_and_never_overpay_it(): void
    {
        $this->manager();
        $charge = $this->relocate($this->customer(), ['paid_now' => 1000, 'method' => 'cash']);

        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 4500, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 4000, 'method' => 'cash'])->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_PAID, $charge->status);
        $this->assertSame(2, Payment::query()->where('customer_charge_id', $charge->id)->where('purpose', Payment::PURPOSE_INSTALLATION)->count());
        $this->post("/installation-charges/{$charge->uuid}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    }

    public function test_paying_more_than_the_fee_up_front_is_refused(): void
    {
        $this->manager();
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/reinstallation", ['paid_now' => 8000, 'method' => 'cash'])->assertSessionHasErrors('paid_now');
        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());
    }

    public function test_one_open_charge_per_kind_but_kinds_are_independent(): void
    {
        $this->manager();
        $customer = $this->customer();
        CustomerChargeFactory::new()->create(['customer_id' => $customer->id]);

        $this->reinstall($customer);
        $this->relocate($customer);

        $this->post("/customers/{$customer->uuid}/reinstallation", [])->assertSessionHasErrors('amount');
        $this->assertSame(3, CustomerCharge::query()->where('customer_id', $customer->id)->count());
    }

    public function test_relocation_and_reinstallation_money_never_touches_billing(): void
    {
        $control = $this->customer();
        $customer = $this->customer();
        $relocation = CustomerChargeFactory::new()->relocation()->create(['customer_id' => $customer->id]);
        $reinstallation = CustomerChargeFactory::new()->reinstallation()->create(['customer_id' => $customer->id]);

        PaymentFactory::new()->create(['customer_id' => $control->id, 'amount' => 1000]);
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 1000]);
        $relocationPayment = PaymentFactory::new()->installation($relocation)->create(['amount' => 5000]);
        $reinstallationPayment = PaymentFactory::new()->installation($reinstallation)->create(['amount' => 7500]);

        $eligible = Payment::query()->where('customer_id', $customer->id)->eligibleForPeriod(self::PERIOD)->count();
        $this->assertSame(1, $eligible, 'Only the subscription payment is billing money.');

        $recalc = app(CustomerManuscriptRecalculationService::class);
        $expected = $recalc->recalculateOne($control->fresh(), self::PERIOD);
        $actual = $recalc->recalculateOne($customer->fresh(), self::PERIOD);

        $this->assertSame((string) $expected->total_arrears, (string) $actual->total_arrears);
        $this->assertSame((string) $expected->credit, (string) $actual->credit);
        $this->assertSame((string) $expected->total_bill, (string) $actual->total_bill);
        $this->assertNull($relocationPayment->fresh()->processed_period);
        $this->assertNull($reinstallationPayment->fresh()->processed_period);
    }

    public function test_an_unpaid_relocation_or_reinstallation_is_never_a_reason_to_disconnect(): void
    {
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 2500, 'prepaid_months_remaining' => 0]);
        CustomerChargeFactory::new()->relocation()->create(['customer_id' => $customer->id, 'amount' => 50000]);
        CustomerChargeFactory::new()->reinstallation()->create(['customer_id' => $customer->id, 'amount' => 50000]);

        $eligible = app(CustomerEligibilityService::class)->eligibleForDisconnection($customer->zone_id, Carbon::create(2035, 1, 20));

        $this->assertNotContains($customer->uuid, $eligible->pluck('uuid')->all());
    }

    public function test_balances_print_as_their_own_lines_on_the_bill_never_in_the_total(): void
    {
        $this->manager();
        $customer = $this->customer();
        Manuscript::query()->create(['customer_id' => $customer->id, 'period' => '2035-01', 'bill' => 2500, 'total_arrears' => 0, 'credit' => 0, 'total_bill' => 2500, 'prepaid_months_remaining' => 0]);
        $installation = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 15000]);
        $this->relocate($customer, ['paid_now' => 1000, 'method' => 'cash']);
        $this->reinstall($customer);

        $bill = app(ManuscriptService::class)->billData($customer->fresh(), '2035-01');
        $this->assertSame('15000.00', $bill['installation_balance'], 'Installation stays its own line.');
        $this->assertSame([
            ['label' => 'Relocation balance', 'amount' => '4000.00'],
            ['label' => 'Re-installation balance', 'amount' => '7500.00'],
        ], $bill['one_off_balances']);
        $this->assertSame('2500.00', (string) $bill['manuscript']->total_bill);

        $bulk = app(ManuscriptService::class)->billDataForCustomers([$customer->fresh()], '2035-01');
        $this->assertSame($bill['one_off_balances'], $bulk[0]['one_off_balances']);

        foreach (['classic', 'compact', 'modern'] as $template) {
            $html = view("pdf.bills.{$template}", $bill)->render();
            $this->assertStringContainsString('Relocation balance', $html, $template);
            $this->assertStringContainsString('Re-installation balance', $html, $template);
        }

        $this->assertNotNull($installation);
        $this->get("/customers/{$customer->uuid}/bill/print?period=2035-01")->assertOk();
    }

    public function test_a_refund_is_approved_by_someone_else_and_paid_out_through_a_bon(): void
    {
        $this->manager();
        $charge = $this->relocate($this->customer(), ['paid_now' => 5000, 'method' => 'cash']);

        $this->post("/installation-charges/{$charge->uuid}/refund", ['amount' => 6000, 'reason' => 'Could not run the cable'])->assertSessionHasErrors('amount');
        $this->post("/installation-charges/{$charge->uuid}/refund", ['amount' => 5000, 'reason' => 'Could not run the cable'])->assertSessionHasNoErrors();
        $this->post("/installation-charges/{$charge->uuid}/refund/approve")->assertForbidden();

        $this->admin();
        $this->post("/installation-charges/{$charge->uuid}/refund/approve")->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(CustomerCharge::STATUS_REFUNDED, $charge->status);
        $voucher = Voucher::query()->findOrFail($charge->refund_voucher_id);
        $this->assertSame('5000.00', (string) $voucher->amount);
        $this->assertStringStartsWith('Relocation refund', $voucher->purpose);
    }

    public function test_reports_show_relocation_and_reinstallation_income_as_their_own_lines(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'super');
        $today = now(BusinessTimezone::WAT)->toDateString();
        $period = now(BusinessTimezone::WAT)->format('Y-m');

        $daily = fn () => $this->get("/reports?tier=daily&date={$today}")->assertOk()->viewData('page')['props']['report'];
        $monthly = fn () => $this->get("/reports?tier=monthly&date={$period}")->assertOk()->viewData('page')['props']['report'];
        $pnl = fn () => $this->get("/resources?period={$period}")->assertOk()->viewData('page')['props']['income'];
        $dashboard = fn () => $this->get("/dashboard?period={$period}")->assertOk()->viewData('page')['props']['stats'];

        Cache::flush();
        $before = ['daily' => $daily(), 'monthly' => $monthly(), 'pnl' => $pnl(), 'dashboard' => $dashboard()];

        $customer = $this->customer();
        $installation = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 20000]);
        $relocation = CustomerChargeFactory::new()->relocation()->create(['customer_id' => $customer->id]);
        $reinstallation = CustomerChargeFactory::new()->reinstallation()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);
        PaymentFactory::new()->installation($installation)->create(['amount' => 7000]);
        PaymentFactory::new()->installation($relocation)->create(['amount' => 3000]);
        PaymentFactory::new()->installation($reinstallation)->create(['amount' => 4000]);
        Cache::flush();

        $after = ['daily' => $daily(), 'monthly' => $monthly(), 'pnl' => $pnl(), 'dashboard' => $dashboard()];
        $delta = fn (string $a, string $b): string => bcsub($a, $b, 2);

        foreach ([['daily', 'payments'], ['monthly', 'collections_cash_received']] as [$tier, $key]) {
            $now = $after[$tier][$key]['by_purpose'];
            $was = $before[$tier][$key]['by_purpose'];
            $this->assertSame('2500.00', $delta($now['subscription'], $was['subscription']), "{$tier} subscription");
            $this->assertSame('7000.00', $delta($now['installation'], $was['installation']), "{$tier} installation");
            $this->assertSame('3000.00', $delta($now['relocation'], $was['relocation']), "{$tier} relocation");
            $this->assertSame('4000.00', $delta($now['reinstallation'], $was['reinstallation']), "{$tier} reinstallation");
        }

        $this->assertSame('3000.00', $delta($after['pnl']['by_purpose']['relocation'], $before['pnl']['by_purpose']['relocation']));
        $this->assertSame('4000.00', $delta($after['pnl']['by_purpose']['reinstallation'], $before['pnl']['by_purpose']['reinstallation']));
        $this->assertSame('7000.00', $delta($after['pnl']['by_purpose']['installation'], $before['pnl']['by_purpose']['installation']));

        // Subscription income (collection rate) and the dashboard's installation figure are untouched by them.
        $this->assertSame('2500.00', $delta((string) $after['dashboard']['monthly_income'], (string) $before['dashboard']['monthly_income']));
        $this->assertSame('7000.00', $delta($after['dashboard']['installation_income'], $before['dashboard']['installation_income']));

        $this->assertSame('7000.00', $delta($after['monthly']['installations']['installation_income'], $before['monthly']['installations']['installation_income']));
        $rows = collect($after['monthly']['installations']['one_off_charges'])->keyBy('kind');
        $was = collect($before['monthly']['installations']['one_off_charges'])->keyBy('kind');
        $this->assertSame('3000.00', $delta($rows['relocation']['income'], $was['relocation']['income']));
        $this->assertSame(1, $rows['relocation']['count'] - $was['relocation']['count']);
        $this->assertSame('4000.00', $delta($rows['reinstallation']['income'], $was['reinstallation']['income']));
        $this->assertSame('Re-installation', $rows['reinstallation']['label']);
    }

    public function test_the_api_customer_resource_exposes_open_one_off_balances_read_only(): void
    {
        $customer = $this->customer();
        $installation = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 15000]);
        $relocation = CustomerChargeFactory::new()->relocation()->create(['customer_id' => $customer->id]);
        PaymentFactory::new()->installation($installation)->create(['amount' => 4000]);
        PaymentFactory::new()->installation($relocation)->create(['amount' => 1000]);

        $token = $this->tokenForRole('manager');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/customers/{$customer->uuid}")
            ->assertOk()
            ->assertJsonPath('data.installation_balance', '11000.00');

        $oneOff = collect($response->json('data.one_off_charges'))->keyBy('kind');
        $this->assertSame('4000.00', $oneOff['relocation']['balance']);
        $this->assertSame('Relocation', $oneOff['relocation']['label']);
        $this->assertSame('11000.00', $oneOff['installation']['balance']);
    }

    public function test_an_agent_can_neither_relocate_nor_reinstall(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'agent');
        $customer = $this->customer();

        $this->post("/customers/{$customer->uuid}/relocation", ['location' => 'Mbonge Road'])->assertForbidden();
        $this->post("/customers/{$customer->uuid}/reinstallation", [])->assertForbidden();
        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());
    }

    public function test_an_archived_customer_cannot_be_relocated(): void
    {
        $this->manager();
        $customer = $this->customer();
        $customer->delete();

        $this->post("/customers/{$customer->uuid}/reinstallation", [])->assertStatus(404);
    }

    public function test_an_impersonating_landlord_cannot_relocate_or_reinstall(): void
    {
        $customer = $this->customer();
        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();
        $patience = $this->admin();
        $patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();

        $this->actingAs($landlord)->post("/landlord/impersonate/{$patience->uuid}")->assertRedirect('/dashboard');

        $this->post("/customers/{$customer->uuid}/relocation", ['location' => 'Mbonge Road'])->assertSessionHas('error');
        $this->post("/customers/{$customer->uuid}/reinstallation", [])->assertSessionHas('error');

        $this->assertSame(0, CustomerCharge::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('FIANGO', $customer->fresh()->location);
    }

    public function test_an_admin_sets_the_default_fees_and_a_manager_cannot(): void
    {
        $this->manager();
        $this->patch('/settings/installation-offers/one-off-fees', ['relocation_fee' => 6000, 'reinstallation_fee' => 8000])->assertForbidden();

        $this->admin();
        $this->patch('/settings/installation-offers/one-off-fees', ['relocation_fee' => 6000, 'reinstallation_fee' => ''])->assertSessionHasNoErrors();

        $company = Company::query()->firstOrFail();
        $this->assertSame('6000.00', (string) $company->relocation_fee);
        $this->assertNull($company->reinstallation_fee, 'Empty = no default.');

        $this->get('/settings/installation-offers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('one_off_fees.relocation_fee', '6000.00')
            ->where('one_off_fees.reinstallation_fee', null));
    }

    public function test_the_zone_listed_for_relocation_exists(): void
    {
        $this->manager();
        $customer = $this->customer();

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customer.one_off_charges.zones', fn ($zones): bool => collect($zones)->contains('uuid', $customer->zone->uuid))
            ->where('customer.one_off_charges.zones', fn ($zones): bool => count($zones) === Zone::query()->count()));
    }
}
