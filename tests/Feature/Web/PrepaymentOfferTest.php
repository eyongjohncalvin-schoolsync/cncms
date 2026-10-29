<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PrepaymentOffer;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ManuscriptCalculator;
use App\Support\BranchPrices;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\PrepaymentOfferFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Prepayment offers ("4 months for 10,000" on a 3,000 bill): a payment
 * under an offer buys exactly its months for exactly its price — so nobody
 * records a bigger amount to make the months come out right — and only for a
 * customer with no unpaid debt.
 */
class PrepaymentOfferTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private Customer $customer;

    private PrepaymentOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => Branch::headquartersId()])->id, 'bill' => 3000, 'status' => 'active']);
        $this->offer = PrepaymentOfferFactory::new()->create(['name' => '4 MONTHS PROMO', 'months' => 4, 'price' => 10000]);
        $this->as('manager');
    }

    public function test_a_payment_under_an_offer_buys_its_months_for_its_price(): void
    {
        $this->payOffer()->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame($this->offer->id, $payment->prepayment_offer_id);
        $this->assertSame('months', $payment->frequency);
        $this->assertSame(4, (int) $payment->months);
        $this->assertSame('2500.00', (string) $payment->prepaid_rate);
        $this->assertSame('10000.00', (string) $payment->offer_price);
    }

    public function test_the_amount_must_be_the_offers_price(): void
    {
        $this->payOffer(['amount' => 12000])->assertSessionHasErrors('prepayment_offer_uuid');
        $this->assertSame(0, Payment::query()->where('customer_id', $this->customer->id)->count());
    }

    public function test_a_customer_who_owes_pays_the_debt_and_the_offer_in_one_payment(): void
    {
        ManuscriptFactory::new()->withArrears(2000)->create(['customer_id' => $this->customer->id, 'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m')]);

        // The offer price alone isn't enough: the debt comes first.
        $this->payOffer()->assertSessionHasErrors('prepayment_offer_uuid');
        $this->assertStringContainsString('Collect 12,000 FCFA', (string) session('errors')->first('prepayment_offer_uuid'));

        $this->payOffer(['amount' => 12000])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame('10000.00', (string) $payment->offer_price);
        $this->assertTrue((bool) $payment->clear_arrears_first, 'The debt is cleared first.');
    }

    public function test_the_payment_form_knows_what_is_still_owed(): void
    {
        ManuscriptFactory::new()->withArrears(6000)->create(['customer_id' => $this->customer->id, 'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m')]);
        $this->post('/payments', ['customer_uuid' => $this->customer->uuid, 'amount' => 4000, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        // Owed 6,000, paid 4,000 since the bill run: 2,000 left, so the offer collects 12,000.
        $this->get('/payments/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('customers', fn ($customers) => collect($customers)->firstWhere('uuid', $this->customer->uuid)['unpaid_debt'] === '2000.00'));
    }

    public function test_debt_paid_earlier_in_the_same_visit_counts(): void
    {
        ManuscriptFactory::new()->withArrears(2000)->create(['customer_id' => $this->customer->id, 'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m')]);

        $this->post('/payments', ['customer_uuid' => $this->customer->uuid, 'amount' => 2000, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $this->payOffer(['duplicate_reason' => 'advance'])->assertSessionHasNoErrors();

        $this->assertNotNull(Payment::query()->where('customer_id', $this->customer->id)->whereNotNull('prepayment_offer_id')->first());
    }

    public function test_the_bill_run_clears_the_debt_then_gives_the_offers_months(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 3000, 'others' => 0, 'status' => 'active']);
        $period = BusinessTimezone::currentPeriod();
        $previous = ManuscriptFactory::new()->create(['customer_id' => $customer->id, 'period' => Carbon::createFromFormat('!Y-m', $period)->subMonthNoOverflow()->format('Y-m'), 'bill' => 3000, 'total_arrears' => 2000, 'credit' => 0]);

        // Paid as expected: 2,000 debt + 10,000 offer.
        $result = (new ManuscriptCalculator)->calculate($customer->fresh(), $period, $previous, collect([$this->offerPayment($customer, '12000')]), collect());
        $this->assertSame('0.00', $result->totalArrears);
        $this->assertSame(3, $result->prepaidMonthsRemaining, 'Four months bought, this month uses one.');
        $this->assertSame('0.00', $result->credit);

        // The debt was bigger than the collector saw: 9,000 left can't buy the
        // 10,000 offer, so it is an ordinary prepayment at the offer's rate.
        $previous->update(['total_arrears' => 3000]);
        $short = (new ManuscriptCalculator)->calculate($customer->fresh(), $period, $previous->fresh(), collect([$this->offerPayment($customer, '12000')]), collect());
        $this->assertSame(2, $short->prepaidMonthsRemaining, '9,000 at 2,500 buys three months; this month uses one.');
        $this->assertSame('1500.00', $short->credit);
    }

    private function offerPayment(Customer $customer, string $amount): Payment
    {
        return PaymentFactory::new()->create([
            'customer_id' => $customer->id, 'amount' => $amount, 'frequency' => 'months', 'months' => 4,
            'prepaid_rate' => '2500.00', 'offer_price' => '10000.00', 'clear_arrears_first' => true,
            'prepayment_offer_id' => $this->offer->id, 'verification_status' => 'verified', 'expiration_date' => null,
        ]);
    }

    public function test_an_offer_out_of_its_dates_is_refused_at_the_counter(): void
    {
        $this->offer->update(['valid_to' => Carbon::now()->subDays(2)->toDateString()]);

        $this->payOffer()->assertSessionHasErrors('prepayment_offer_uuid');
    }

    public function test_a_branch_sells_at_its_own_price(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'BUEA']);
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $buea->id])->id, 'bill' => 3000, 'status' => 'active']);
        DB::connection('tenant')->table('branch_prices')->insert([
            'uuid' => (string) Str::uuid(), 'branch_id' => $buea->id, 'prepayment_offer_id' => $this->offer->id,
            'price' => 9000, 'reason' => 'Competition', 'set_by' => $this->kelvin->id, 'created_at' => now(),
        ]);
        BranchPrices::flush();

        $this->assertSame('9000.00', BranchPrices::for($buea->id)->prepaymentOfferPrice($this->offer));
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 10000, 'frequency' => 'months', 'months' => 4, 'prepayment_offer_uuid' => $this->offer->uuid])
            ->assertSessionHasErrors('prepayment_offer_uuid');
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 9000, 'frequency' => 'months', 'months' => 4, 'prepayment_offer_uuid' => $this->offer->uuid])
            ->assertSessionHasNoErrors();
    }

    public function test_an_offline_phone_s_offer_is_never_refused_but_a_doubtful_one_waits_for_the_office(): void
    {
        ManuscriptFactory::new()->withArrears(2000)->create(['customer_id' => $this->customer->id, 'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m')]);
        $token = $this->tokenForRole('manager');

        $results = $this->withToken($token)->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-1',
            'changes' => ['payments' => [
                ['local_uuid' => $owing = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 10000, 'frequency' => 'months', 'months' => 4, 'prepayment_offer_uuid' => $this->offer->uuid],
            ]],
        ])->assertOk()->json('results.payments');

        $this->assertSame('synced', $results[0]['status'], 'Cash already collected is never refused.');
        $payment = Payment::query()->where('local_uuid', $owing)->sole();
        $this->assertSame($this->offer->id, $payment->prepayment_offer_id);
        $this->assertSame('pending', $payment->verification_status, 'Sold to a customer with debt: the office decides.');
    }

    public function test_a_phone_selling_an_offer_after_it_ended_is_kept_but_waits_for_the_office(): void
    {
        $this->offer->update(['valid_to' => Carbon::now()->subDays(5)->toDateString()]);

        $results = $this->withToken($this->tokenForRole('manager'))->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-1',
            'changes' => ['payments' => [
                ['local_uuid' => $late = (string) Str::uuid(), 'customer_uuid' => $this->customer->uuid, 'amount' => 10000, 'frequency' => 'months', 'months' => 4,
                    'prepayment_offer_uuid' => $this->offer->uuid, 'created_at' => Carbon::now()->subDay()->toIso8601String()],
                ['local_uuid' => $inTime = (string) Str::uuid(), 'customer_uuid' => CustomerFactory::new()->create(['zone_id' => $this->customer->zone_id, 'bill' => 3000, 'status' => 'active'])->uuid,
                    'amount' => 10000, 'frequency' => 'months', 'months' => 4,
                    'prepayment_offer_uuid' => $this->offer->uuid, 'created_at' => Carbon::now()->subDays(6)->toIso8601String()],
            ]],
        ])->assertOk()->json('results.payments');

        $this->assertSame(['synced', 'synced'], array_column($results, 'status'), 'Cash already collected is never refused.');
        $late = Payment::query()->where('local_uuid', $late)->sole();
        $this->assertSame($this->offer->id, $late->prepayment_offer_id);
        $this->assertSame('pending', $late->verification_status, 'Sold after the offer ended: the office decides.');
        $this->assertSame('verified', Payment::query()->where('local_uuid', $inTime)->value('verification_status'), 'Sold while it ran.');
    }

    public function test_an_offer_payment_keeps_the_offers_months(): void
    {
        $this->payOffer()->assertSessionHasNoErrors();
        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $payment->update(['verification_status' => 'pending']);
        // No receipt in the customer's hand, so the offer's own rule is what answers.
        PaymentReceipt::query()->where('payment_id', $payment->id)->delete();

        $this->put("/payments/{$payment->uuid}", ['amount' => 10000, 'frequency' => 'months', 'months' => 12])->assertSessionHasErrors('months');
        $this->assertSame(4, $payment->fresh()->months);
    }

    public function test_the_bill_run_gives_exactly_the_offers_months_with_no_leftover(): void
    {
        // 5,000 for 3 months on a 2,000 bill: dividing by the bill would give 2 months + 1,000 credit.
        $offer = PrepaymentOfferFactory::new()->create(['months' => 3, 'price' => 5000]);
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2000, 'others' => 0, 'status' => 'active']);
        $period = BusinessTimezone::currentPeriod();
        $previous = ManuscriptFactory::new()->create(['customer_id' => $customer->id, 'period' => Carbon::createFromFormat('!Y-m', $period)->subMonthNoOverflow()->format('Y-m'), 'bill' => 2000, 'total_arrears' => 0, 'credit' => 0]);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id, 'amount' => 5000, 'frequency' => 'months', 'months' => 3,
            'prepaid_rate' => '1666.66', 'clear_arrears_first' => false, 'prepayment_offer_id' => $offer->id,
            'verification_status' => 'verified', 'expiration_date' => null,
        ]);

        $result = (new ManuscriptCalculator)->calculate($customer->fresh(), $period, $previous, collect([$payment]), collect());

        $this->assertSame(2, $result->prepaidMonthsRemaining, 'Three months bought, this month uses one.');
        $this->assertSame('0.00', $result->credit);
        $this->assertSame('0.00', $result->totalArrears);
    }

    public function test_debt_paid_separately_first_still_gets_the_whole_offer_at_the_bill_run(): void
    {
        // Owes 4,000; pays 4,000, then buys the 5,000-for-3-months offer. The
        // debt payment clears the debt, so the offer gets all its months.
        $offer = PrepaymentOfferFactory::new()->create(['months' => 3, 'price' => 5000]);
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2000, 'others' => 0, 'status' => 'active']);
        $period = BusinessTimezone::currentPeriod();
        $previous = ManuscriptFactory::new()->create(['customer_id' => $customer->id, 'period' => Carbon::createFromFormat('!Y-m', $period)->subMonthNoOverflow()->format('Y-m'), 'bill' => 2000, 'total_arrears' => 4000, 'credit' => 0]);
        $debt = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 4000, 'frequency' => 'monthly', 'verification_status' => 'verified']);
        $offerPayment = PaymentFactory::new()->create([
            'customer_id' => $customer->id, 'amount' => 5000, 'frequency' => 'months', 'months' => 3, 'offer_price' => 5000,
            'prepaid_rate' => '2000', 'clear_arrears_first' => true, 'prepayment_offer_id' => $offer->id,
            'verification_status' => 'verified', 'expiration_date' => null,
        ]);

        $result = (new ManuscriptCalculator)->calculate($customer->fresh(), $period, $previous, collect([$debt, $offerPayment]), collect());

        $this->assertSame(2, $result->prepaidMonthsRemaining, 'Three months bought, this month uses one.');
        $this->assertSame('0.00', $result->totalArrears);
        $this->assertSame('0.00', $result->credit);
    }

    public function test_headquarters_keeps_the_offer_list(): void
    {
        $this->as('admin');
        $this->post('/settings/prepayment-offers', ['name' => '2 MONTHS PROMO', 'months' => 2, 'price' => 5000, 'active' => true])->assertSessionHasNoErrors();
        $offer = PrepaymentOffer::query()->where('name', '2 MONTHS PROMO')->sole();

        $this->get('/settings/prepayment-offers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/PrepaymentOffers')
            ->where('offers', fn ($offers) => collect($offers)->firstWhere('uuid', $offer->uuid)['on_sale'] === true));

        // An offer already sold is switched off, never deleted.
        $this->payOffer();
        $this->delete("/settings/prepayment-offers/{$this->offer->uuid}")->assertSessionHasNoErrors();
        $this->assertFalse($this->offer->fresh()->active);
        $this->delete("/settings/prepayment-offers/{$offer->uuid}");
        $this->assertNull(PrepaymentOffer::query()->find($offer->id));

        // A branch's staff can't change the list.
        $this->as('admin', BranchFactory::new()->create()->id);
        $this->post('/settings/prepayment-offers', ['name' => 'X', 'months' => 2, 'price' => 1, 'active' => true])->assertForbidden();
    }

    public function test_a_branch_sets_its_own_offer_price_on_its_settings(): void
    {
        $buea = BranchFactory::new()->create(['name' => 'BUEA']);
        $this->as('admin');

        $this->post("/branches/{$buea->uuid}/prices", ['target_type' => 'prepayment_offer', 'target_uuid' => $this->offer->uuid, 'price' => 9500, 'reason' => 'Competition'])
            ->assertSessionHasNoErrors();

        BranchPrices::flush();
        $this->assertSame('9500.00', BranchPrices::for($buea->id)->prepaymentOfferPrice($this->offer));
    }

    public function test_the_phone_gets_the_offers_at_its_branch_price(): void
    {
        $this->withToken($this->tokenForRole('manager'))->getJson('/api/v1/payments/prepayment-offers')->assertOk()
            ->assertJsonFragment(['uuid' => $this->offer->uuid, 'months' => 4, 'price' => '10000.00']);
    }

    public function test_the_phone_gets_the_real_unpaid_debt_and_hears_when_it_moves(): void
    {
        // Owes 6,000 on the last bill; another collector already took 2,000.
        ManuscriptFactory::new()->withArrears(6000)->create(['customer_id' => $this->customer->id, 'period' => Carbon::now()->subMonthNoOverflow()->format('Y-m')]);
        PaymentFactory::new()->create(['customer_id' => $this->customer->id, 'amount' => 2000, 'frequency' => 'monthly', 'verification_status' => 'verified']);
        $token = $this->tokenForRole('manager');

        $first = $this->withToken($token)->getJson('/api/v1/sync/pull')->assertOk();
        $this->assertSame('4000.00', collect($first->json('changes.customers.upserted'))->firstWhere('uuid', $this->customer->uuid)['unpaid_debt']);

        // A later payment by someone else: the customer row is untouched, yet the next sync resends it.
        $since = $first->json('synced_at');
        $this->travel(1)->minutes();
        PaymentFactory::new()->create(['customer_id' => $this->customer->id, 'amount' => 1500, 'frequency' => 'monthly', 'verification_status' => 'verified']);

        $next = collect($this->withToken($token)->getJson('/api/v1/sync/pull?since='.urlencode($since))->assertOk()->json('changes.customers.upserted'));
        $this->assertSame('2500.00', $next->firstWhere('uuid', $this->customer->uuid)['unpaid_debt'] ?? null);
    }

    public function test_the_phone_can_sell_at_the_customers_branch_price(): void
    {
        // An HQ manager's phone selling to a BUEA customer must ask BUEA's price, as the server charges.
        $buea = BranchFactory::new()->create(['name' => 'BUEA']);
        BranchPrice::query()->create(['branch_id' => $buea->id, 'prepayment_offer_id' => $this->offer->id, 'price' => 9500, 'reason' => 'Competition']);
        BranchPrices::flush();
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $buea->id])->id, 'bill' => 3000, 'status' => 'active']);
        $token = $this->tokenForRole('manager');

        $offer = collect($this->withToken($token)->getJson('/api/v1/payments/prepayment-offers')->assertOk()->json('data'))->firstWhere('uuid', $this->offer->uuid);
        $this->assertSame('10000.00', $offer['price'], "The caller's own branch.");
        $this->assertSame('9500.00', $offer['branch_prices'][$buea->uuid]);

        $pulled = collect($this->withToken($token)->getJson('/api/v1/sync/pull')->assertOk()->json('changes.customers.upserted'))
            ->firstWhere('uuid', $customer->uuid);
        $this->assertSame($buea->uuid, $pulled['branch_uuid']);
    }

    private function payOffer(array $extra = []): TestResponse
    {
        return $this->post('/payments', [
            'customer_uuid' => $this->customer->uuid,
            'amount' => 10000,
            'frequency' => 'months',
            'months' => 4,
            'prepayment_offer_uuid' => $this->offer->uuid,
            ...$extra,
        ]);
    }

    private function as(string $role, ?int $branchId = null): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($this->kelvin);
    }
}
