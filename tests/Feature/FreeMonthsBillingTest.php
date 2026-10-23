<?php

namespace Tests\Feature;

use App\DataTransferObjects\CustomerServiceSelection;
use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\CustomerSubscriptionService;
use App\Services\ManuscriptCalculationResult;
use App\Services\ManuscriptCalculator;
use App\Support\Billing\FreeMonthPlan;
use Database\Factories\CustomerFactory;
use Database\Factories\FreeMonthGrantFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * The billing engine with free months and a first billed month
 * (docs/plans/installation-and-promotions.md, phase 2, item 4) â€” the
 * owner's worked examples (a), (b), (e) at the calculator level, plus D5
 * (free months run by calendar), the rate-change rule and the legacy
 * prepaid window. Runs against a disposable tenant schema, like
 * ManuscriptCalculateTest.
 */
class FreeMonthsBillingTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zfmb');
        tenancy()->initialize($this->tenant);

        Service::query()->where('slug', 'internet')->update(['price' => 2500]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        parent::tearDown();
    }

    private function service(string $slug): Service
    {
        return Service::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * A customer holding the given services at 2,500 each.
     *
     * @param  list<string>  $slugs
     */
    private function customer(array $slugs = ['tv'], ?string $billingStarts = '2026-11', array $attributes = []): Customer
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => ZoneFactory::new()->create()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
            'billing_starts_period' => $billingStarts,
            ...$attributes,
        ]);

        app(CustomerSubscriptionService::class)->sync($customer, array_map(
            fn (string $slug): CustomerServiceSelection => new CustomerServiceSelection($this->service($slug)->uuid, '2500'),
            $slugs,
        ));

        return $customer->fresh();
    }

    private function grant(Customer $customer, ?string $slug, int $months, string $start): FreeMonthGrant
    {
        return FreeMonthGrantFactory::new()->create([
            'customer_id' => $customer->id,
            'service_id' => $slug !== null ? $this->service($slug)->id : null,
            'months' => $months,
            'start_period' => $start,
            'reason' => FreeMonthGrant::REASON_INSTALLATION_OFFER,
            'status' => FreeMonthGrant::STATUS_APPROVED,
        ]);
    }

    private function advance(Customer $customer, string $amount, int $months, string $rate): Payment
    {
        return PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'frequency' => 'months',
            'months' => $months,
            'expiration_date' => null,
            'prepaid_rate' => $rate,
            'clear_arrears_first' => true,
            'verification_status' => 'verified',
        ]);
    }

    private function pay(Customer $customer, string $amount): Payment
    {
        return PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'frequency' => 'monthly',
            'verification_status' => 'verified',
        ]);
    }

    /**
     * Same resolution as the manuscript run, then persisted like it.
     */
    private function runMonth(Customer $customer, string $period): ManuscriptCalculationResult
    {
        $customer = $customer->fresh();

        $previous = Manuscript::query()->where('customer_id', $customer->id)->where('period', '<', $period)->orderByDesc('period')->first();
        $payments = Payment::query()->where('customer_id', $customer->id)->eligibleForPeriod($period)->get();
        $adjustments = ArrearsAdjustment::query()->where('customer_id', $customer->id)->where('status', 'approved')->where('target_period', $period)
            ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))->get();

        $result = (new ManuscriptCalculator)->calculate($customer, $period, $previous, $payments, $adjustments);

        Manuscript::query()->firstOrNew(['customer_id' => $customer->id, 'period' => $period])->fill($result->toManuscriptAttributes())->save();

        foreach ($result->processedPayments as $payment) {
            $payment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
        }

        return $result;
    }

    public function test_example_a_two_free_months_then_three_paid_months_then_the_first_bill(): void
    {
        // TV 2,500; installation paid in full on 10 Oct; 2 free months; a
        // 3-month advance paid the same day â†’ Novâ€“Dec free, Janâ€“Mar paid,
        // first bill April.
        $customer = $this->customer();
        $this->grant($customer, 'tv', 2, '2026-11');
        $this->advance($customer, '7500', 3, '2500');

        foreach (['2026-11', '2026-12'] as $period) {
            $result = $this->runMonth($customer, $period);
            $this->assertSame('0.00', $result->totalBill, "{$period} free");
            $this->assertSame('2500.00', $result->freeValue, "{$period} foregone value");
            $this->assertSame(3, $result->prepaidMonthsRemaining, "{$period} uses no paid month");
            $this->assertSame('free', $result->frozenReason);
        }

        foreach (['2027-01' => 2, '2027-02' => 1, '2027-03' => 0] as $period => $left) {
            $result = $this->runMonth($customer, $period);
            $this->assertSame('0.00', $result->totalBill, "{$period} paid in advance");
            $this->assertSame($left, $result->prepaidMonthsRemaining);
            $this->assertSame('0.00', $result->freeValue);
        }

        $april = $this->runMonth($customer, '2027-04');
        $this->assertSame('2500.00', $april->totalBill, 'first bill is exactly one month');
        $this->assertSame('0.00', $april->totalArrears);
        $this->assertSame('0.00', $april->credit, 'free months never become credit');

        $may = $this->runMonth($customer, '2027-05');
        $this->assertSame('2500.00', $may->totalArrears, 'April unpaid');
        $this->assertSame('5000.00', $may->totalBill);
    }

    public function test_example_b_free_months_per_service(): void
    {
        // TV + Internet at 2,500; TV 2 free, Internet 1 free â†’ month 1
        // nothing, month 2 2,500 (TV still free), month 3 5,000.
        $customer = $this->customer(['tv', 'internet']);
        $this->grant($customer, 'tv', 2, '2026-11');
        $this->grant($customer, 'internet', 1, '2026-11');

        $plan = FreeMonthPlan::forCustomer($customer);
        $this->assertSame('0.00', $plan->charge('2026-11', '5000'));
        $this->assertSame('2500.00', $plan->charge('2026-12', '5000'));
        $this->assertSame('5000.00', $plan->charge('2027-01', '5000'));

        $this->assertSame('0.00', $this->runMonth($customer, '2026-11')->totalBill);

        $december = $this->runMonth($customer, '2026-12');
        $this->assertSame('2500.00', $december->bill);
        $this->assertSame('2500.00', $december->totalBill);
        $this->assertSame('2500.00', $december->freeValue);

        $this->pay($customer, '2500');
        $january = $this->runMonth($customer, '2027-01');
        $this->assertSame('5000.00', $january->totalBill, 'December paid, January in full');
        $this->assertSame('0.00', $january->totalArrears);
    }

    public function test_example_b_a_three_month_advance_costs_12500_and_covers_exactly_three_charged_months(): void
    {
        $customer = $this->customer(['tv', 'internet']);
        $this->grant($customer, 'tv', 2, '2026-11');
        $this->grant($customer, 'internet', 1, '2026-11');

        $bought = FreeMonthPlan::forCustomer($customer)->monthsBought('2026-11', '12500', '5000', 3, 0, '5000');
        $this->assertSame(['months' => 3, 'cost' => '12500.00'], $bought);

        $this->advance($customer, '12500', 3, '5000');

        $november = $this->runMonth($customer, '2026-11');
        $this->assertSame(3, $november->prepaidMonthsRemaining, 'nothing charged, no paid month used');
        $this->assertSame('0.00', $november->credit);

        foreach (['2026-12' => 2, '2027-01' => 1, '2027-02' => 0] as $period => $left) {
            $result = $this->runMonth($customer, $period);
            $this->assertSame('0.00', $result->totalBill, "{$period} covered");
            $this->assertSame($left, $result->prepaidMonthsRemaining);
        }

        $march = $this->runMonth($customer, '2027-03');
        $this->assertSame('5000.00', $march->totalBill, 'first bill: the full month');
        $this->assertSame('0.00', $march->credit);
    }

    public function test_free_months_keep_running_while_the_customer_is_disconnected(): void
    {
        // D5: free months run by calendar. Disconnected through Novâ€“Dec, the
        // two free months are gone by January.
        $customer = $this->customer();
        $this->grant($customer, 'tv', 2, '2026-11');

        $customer->update(['status' => 'disconnected']);
        $this->runMonth($customer, '2026-11');
        $this->runMonth($customer, '2026-12');

        $customer->update(['status' => 'active']);
        $january = $this->runMonth($customer, '2027-01');

        $this->assertSame('2500.00', $january->totalBill);
        $this->assertSame('0.00', $january->freeValue);
    }

    public function test_a_rate_change_during_free_months_has_no_effect(): void
    {
        $customer = $this->customer();
        $this->grant($customer, 'tv', 2, '2026-11');

        $this->runMonth($customer, '2026-11');
        $customer->subscriptions()->update(['price' => 4000]);
        $customer->update(['bill' => 4000]);

        $december = $this->runMonth($customer, '2026-12');
        $this->assertSame('0.00', $december->totalBill);
        $this->assertSame('0.00', $december->totalArrears);

        $this->assertSame('4000.00', $this->runMonth($customer, '2027-01')->totalBill, 'the new rate applies once the free months end');
    }

    public function test_example_e_compensation_on_a_customer_with_seven_paid_months_uses_the_free_months_first(): void
    {
        // An existing customer (no first billed month) with seven paid
        // months gets two free months: free first, paid months preserved â€”
        // nine months with nothing to pay, then the bill.
        $customer = $this->customer(billingStarts: null);
        $this->advance($customer, '17500', 7, '2500');

        $this->assertSame(6, $this->runMonth($customer, '2026-10')->prepaidMonthsRemaining);

        $this->grant($customer, null, 2, '2026-11');

        $zeroBills = 0;
        $period = '2026-11';

        for ($i = 0; $i < 8; $i++) {
            $result = $this->runMonth($customer, $period);
            if ($result->totalBill === '0.00') {
                $zeroBills++;
            }
            if (in_array($period, ['2026-11', '2026-12'], true)) {
                $this->assertSame(6, $result->prepaidMonthsRemaining, "{$period} is free, the paid months are kept");
            }
            $period = FreeMonthGrant::addMonths($period, 1);
        }

        $this->assertSame(8, $zeroBills, 'Oct paid + Novâ€“Dec free + 6 more paid months');
        $this->assertSame('0.00', $this->runMonth($customer, '2027-07')->credit);
        $this->assertNotSame('0.00', $this->runMonth($customer, '2027-08')->totalBill);
    }

    public function test_a_month_before_billing_starts_is_not_billed_and_payments_wait(): void
    {
        $customer = $this->customer(billingStarts: '2026-11');
        $this->pay($customer, '2500');

        $october = $this->runMonth($customer, '2026-10');
        $this->assertSame('not_started', $october->frozenReason);
        $this->assertSame('0.00', $october->totalBill);
        $this->assertCount(0, $october->processedPayments);

        $november = $this->runMonth($customer, '2026-11');
        $this->assertSame('0.00', $november->totalBill, 'the payment made in October covers November');
        $this->assertSame('2500.00', $november->bill);
    }

    public function test_a_new_customer_without_free_months_gets_a_first_bill_of_exactly_one_month(): void
    {
        $customer = $this->customer(billingStarts: '2026-11');

        $this->assertSame('2500.00', $this->runMonth($customer, '2026-11')->totalBill);
        $this->assertSame('5000.00', $this->runMonth($customer, '2026-12')->totalBill, 'November unpaid + December');
    }

    public function test_a_fully_free_month_extends_a_legacy_prepaid_window(): void
    {
        $customer = $this->customer(billingStarts: null);
        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 5000,
            'frequency' => 'months',
            'months' => 2,
            'expiration_date' => '2027-01-01',
            'verification_status' => 'verified',
        ]);
        $this->grant($customer, null, 1, '2026-12');

        $this->assertSame('2027-01-01', $this->runMonth($customer, '2026-11')->paymentExpiration->toDateString());
        $this->assertSame('2027-02-01', $this->runMonth($customer, '2026-12')->paymentExpiration->toDateString(), 'the free month does not use up the paid window');
        $this->assertSame('0.00', $this->runMonth($customer, '2027-01')->totalBill, 'January still paid');
    }

    public function test_an_existing_customer_on_a_partly_free_month_is_charged_only_the_other_services(): void
    {
        $customer = $this->customer(['tv', 'internet'], billingStarts: null);
        $this->grant($customer, 'internet', 1, '2026-11');

        $november = $this->runMonth($customer, '2026-11');
        $this->assertSame('2500.00', $november->bill);
        $this->assertSame('2500.00', $november->freeValue);
        $this->assertNull($november->frozenReason);
    }
}
