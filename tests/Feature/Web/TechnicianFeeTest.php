<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerCharge;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Remittance;
use App\Models\TechnicianFeePayout;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashBookService;
use App\Support\BusinessTimezone;
use Database\Factories\CustomerChargeFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Technician motivation fees (D13, docs/plans/installation-and-promotions.md):
 * the reconnection fine is recorded as a `technician_fee` payment, receipted
 * as such, handed over with the day's cash as its own subtotal, paid out by
 * the cashier to the named technician from the till — and never counted as
 * income, collections or a bill payment. Also the report split by purpose.
 *
 * Two real people (cash controls' maker ≠ checker): the seeded owner (kelvin)
 * collects, the seeded admin (patience) receives as cashier.
 */
class TechnicianFeeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Cache::flush();
    }

    private function actAs(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function collector(): User
    {
        return $this->actAs('kelvin@shalomtech.dev', 'manager');
    }

    private function cashier(): User
    {
        return $this->actAs('patience@shalomtech.dev', 'cashier');
    }

    private function disconnectedCustomer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'others' => 0, 'status' => 'disconnected']);
    }

    /** Reconnects with the fine (2 000 by default) and takes a 2 500 subscription payment, as the collector. */
    private function collectFineAndSubscription(): Payment
    {
        $this->collector();
        $customer = $this->disconnectedCustomer();

        $this->patch("/customers/{$customer->uuid}/reconnect", ['include_fine' => true])->assertSessionHasNoErrors();
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 2500, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        return Payment::query()->where('customer_id', $customer->id)->where('purpose', Payment::PURPOSE_TECHNICIAN_FEE)->sole();
    }

    private function handOverAndReceive(string $cash): Remittance
    {
        $this->collector();
        $this->post('/cash/remittances', ['declared_cash' => $cash])->assertSessionHasNoErrors();
        $remittance = Remittance::query()->where('collector_user_id', $this->collector()->id)->latest('id')->firstOrFail();

        $this->cashier();
        $this->post("/cash/remittances/{$remittance->uuid}/receive", ['received_cash' => $cash])->assertSessionHasNoErrors();

        return $remittance->fresh();
    }

    public function test_the_fine_gets_a_technician_motivation_fee_receipt(): void
    {
        $fee = $this->collectFineAndSubscription();

        $receipt = PaymentReceipt::query()->where('payment_id', $fee->id)->sole();

        $this->assertSame('technician_fee', $receipt->snapshot['purpose']);
        $this->assertSame('TECHNICIAN MOTIVATION FEE', $receipt->snapshot['title']);
        $this->assertSame([], $receipt->snapshot['payment']['periods']);
        $this->assertNull($receipt->snapshot['balance']);

        $html = view('pdf.receipt-thermal', ['r' => $receipt->snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false, 'widthMm' => 58])->render();
        $this->assertStringContainsString('TECHNICIAN MOTIVATION FEE', $html);
        $this->assertStringContainsString('TECHNICIAN MOTIVATION FEE', view('pdf.receipt', ['r' => $receipt->snapshot, 'logo' => null, 'qr' => null, 'duplicate' => false])->render());
    }

    public function test_the_handover_shows_technician_fees_as_their_own_subtotal(): void
    {
        $fee = $this->collectFineAndSubscription();

        $this->get('/cash/hand-over')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/HandOver')
            ->where('totals.cash', bcadd('2500', (string) $fee->amount, 2))
            ->where('purpose_totals.technician_fee', (string) $fee->amount)
            ->where('purpose_totals.subscription', '2500.00'));

        $remittance = $this->handOverAndReceive(bcadd('2500', (string) $fee->amount, 2));

        $this->get("/cash/remittances/{$remittance->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/Remittance')
            ->where('remittance.purpose_totals.technician_fee', (string) $fee->amount)
            ->where('remittance.purpose_totals.subscription', '2500.00'));
    }

    public function test_a_fee_is_only_payable_once_its_handover_was_received(): void
    {
        $fee = $this->collectFineAndSubscription();

        $this->cashier();
        $this->get('/cash/technician-fees')->assertOk()->assertInertia(fn (Assert $page) => $page->has('fees', 0));
        $this->post('/cash/technician-fees/payouts', ['payments' => [$fee->uuid], 'technician_name' => 'Jean Tech'])->assertSessionHasErrors('payments');
    }

    public function test_the_cashier_pays_collected_fees_to_the_technician_from_the_till(): void
    {
        $fee = $this->collectFineAndSubscription();
        $total = bcadd('2500', (string) $fee->amount, 2);
        $this->handOverAndReceive($total);

        $cashier = $this->cashier();
        $till = app(CashBookService::class)->tillFor($cashier);
        $this->assertSame($total, app(CashBookService::class)->balance($till));

        $this->get('/cash/technician-fees')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Cash/TechnicianFees')
            ->has('fees', 1)
            ->where('fees.0.uuid', $fee->uuid)
            ->where('can_pay', true));

        $this->post('/cash/technician-fees/payouts', ['payments' => [$fee->uuid], 'technician_name' => 'Jean Tech', 'note' => 'Reconnection'])
            ->assertSessionHasNoErrors();

        $payout = TechnicianFeePayout::query()->latest('id')->firstOrFail();
        $this->assertStringStartsWith('TF-', $payout->number);
        $this->assertSame('Jean Tech', $payout->technician_name);
        $this->assertSame((string) $fee->amount, (string) $payout->amount);
        $this->assertSame($payout->id, $fee->fresh()->technician_fee_payout_id);

        $movement = CashMovement::query()->whereKey($payout->cash_movement_id)->sole();
        $this->assertSame('out', $movement->direction);
        $this->assertSame(CashMovement::KIND_TECHNICIAN_FEE_PAYOUT, $movement->kind);
        $this->assertSame($till->id, $movement->cash_account_id);
        $this->assertSame('2500.00', app(CashBookService::class)->balance($till), 'Only the subscription money stays in the till.');

        // Never twice.
        $this->post('/cash/technician-fees/payouts', ['payments' => [$fee->uuid], 'technician_name' => 'Jean Tech'])->assertSessionHasErrors('payments');
    }

    public function test_only_a_till_holder_can_pay_out_fees(): void
    {
        $fee = $this->collectFineAndSubscription();
        $this->handOverAndReceive(bcadd('2500', (string) $fee->amount, 2));

        $this->actAs('kelvin@shalomtech.dev', 'agent');
        $this->post('/cash/technician-fees/payouts', ['payments' => [$fee->uuid], 'technician_name' => 'Jean Tech'])->assertForbidden();
        $this->get('/cash/technician-fees')->assertForbidden();
    }

    public function test_technician_fees_are_never_income_and_reports_split_income_by_purpose(): void
    {
        $this->actAs('kelvin@shalomtech.dev', 'super');
        $today = now(BusinessTimezone::WAT)->toDateString();
        $period = now(BusinessTimezone::WAT)->format('Y-m');

        $daily = fn () => $this->get("/reports?tier=daily&date={$today}")->assertOk()->viewData('page')['props']['report'];
        $monthly = fn () => $this->get("/reports?tier=monthly&date={$period}")->assertOk()->viewData('page')['props']['report'];
        $pnl = fn () => $this->get("/resources?period={$period}")->assertOk()->viewData('page')['props']['income'];
        $dashboard = fn () => $this->get("/dashboard?period={$period}")->assertOk()->viewData('page')['props']['stats'];

        $before = ['daily' => $daily(), 'monthly' => $monthly(), 'pnl' => $pnl(), 'dashboard' => $dashboard()];

        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
        $charge = CustomerChargeFactory::new()->create(['customer_id' => $customer->id, 'amount' => 20000, 'status' => CustomerCharge::STATUS_OPEN]);
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 2500]);
        PaymentFactory::new()->installation($charge)->create(['amount' => 7000]);
        PaymentFactory::new()->technicianFee()->create(['customer_id' => $customer->id, 'amount' => 2000]);
        Cache::flush();

        $after = ['daily' => $daily(), 'monthly' => $monthly(), 'pnl' => $pnl(), 'dashboard' => $dashboard()];

        $delta = fn (string $a, string $b): string => bcsub($a, $b, 2);

        // Income = subscription + installation; the 2 000 technician fee is nowhere.
        $this->assertSame('9500.00', $delta($after['daily']['payments']['verified'], $before['daily']['payments']['verified']));
        $this->assertSame('2500.00', $delta($after['daily']['payments']['by_purpose']['subscription'], $before['daily']['payments']['by_purpose']['subscription']));
        $this->assertSame('7000.00', $delta($after['daily']['payments']['by_purpose']['installation'], $before['daily']['payments']['by_purpose']['installation']));
        $this->assertSame(2, $after['daily']['payments']['count'] - $before['daily']['payments']['count']);

        $this->assertSame('9500.00', $delta($after['monthly']['collections_cash_received']['verified'], $before['monthly']['collections_cash_received']['verified']));
        $this->assertSame('9500.00', $delta($after['pnl']['verified'], $before['pnl']['verified']));
        $this->assertSame('7000.00', $delta($after['pnl']['by_purpose']['installation'], $before['pnl']['by_purpose']['installation']));

        // Collection rate's numerator (and the dashboard's monthly income) is subscription only.
        $this->assertSame('2500.00', $delta((string) $after['dashboard']['monthly_income'], (string) $before['dashboard']['monthly_income']));
        $this->assertSame('7000.00', $delta($after['dashboard']['installation_income'], $before['dashboard']['installation_income']));
    }
}
