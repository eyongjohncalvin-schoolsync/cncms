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

    /**
     * A "paid upfront" payment (D15): months per service at its own price.
     *
     * @param  array<string, int>  $months  service slug => months
     */
    private function upfront(Customer $customer, array $months, string $price = '2500'): Payment
    {
        $rows = [];
        $amount = '0';

        foreach ($months as $slug => $count) {
            $rows[] = ['service_id' => $this->service($slug)->id, 'months' => $count, 'rate' => $price];
            $amount = bcadd($amount, bcmul((string) $count, $price, 2), 2);
        }

        return PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'frequency' => 'months',
            'months' => max($months),
            'expiration_date' => null,
            'service_months' => $rows,
            'verification_status' => 'verified',
        ]);
    }

    public function test_example_a_free_months_then_paid_months_then_billed_like_everyone(): void
    {
        // TV 2,500, installed in October: Oct–Nov free (the installation
        // month ticked + one more), 3 months paid upfront → Dec–Feb paid,
        // first billed month March — on the same ledger as everyone (D18).
        $customer = $this->customer(billingStarts: '2026-10');
        $this->grant($customer, 'tv', 2, '2026-10');
        $this->upfront($customer, ['tv' => 3]);

        foreach (['2026-10', '2026-11'] as $period) {
            $result = $this->runMonth($customer, $period);
            $this->assertSame('0.00', $result->totalBill, "{$period} free");
            $this->assertSame('2500.00', $result->freeValue);
            $this->assertSame('free', $result->frozenReason);
            $this->assertSame(3, $result->servicePrepaid[$this->service('tv')->id]['months'], "{$period} uses no paid month");
        }

        foreach (['2026-12' => 2, '2027-01' => 1, '2027-02' => 0] as $period => $left) {
            $result = $this->runMonth($customer, $period);
            $this->assertSame('0.00', $result->totalBill, "{$period} paid upfront");
            $this->assertSame('0.00', $result->bill);
            $this->assertSame('prepaid', $result->frozenReason);
            $this->assertSame($left, $result->servicePrepaid[$this->service('tv')->id]['months'] ?? 0);
        }

        $march = $this->runMonth($customer, '2027-03');
        $this->assertSame('2500.00', $march->bill, 'first billed month');
        $this->assertSame('0.00', $march->credit, 'free months never become credit');
        // Same pay-ahead rule as every existing customer (step 5).
        $this->assertSame('5000.00', $march->totalBill);
    }

    public function test_example_b_free_and_paid_months_per_service(): void
    {
        // TV + Internet at 2,500: TV free Oct–Nov then 1 paid month,
        // Internet free Oct then 2 paid months → Oct, Nov, Dec nothing to
        // pay; both billed from January. Collected upfront: 7,500.
        $customer = $this->customer(['tv', 'internet'], billingStarts: '2026-10');
        $this->grant($customer, 'tv', 2, '2026-10');
        $this->grant($customer, 'internet', 1, '2026-10');
        $payment = $this->upfront($customer, ['tv' => 1, 'internet' => 2]);
        $this->assertSame('7500.00', (string) $payment->amount);

        $walk = FreeMonthPlan::forCustomer($customer)->timelines('2026-10', [$this->service('tv')->id => 1, $this->service('internet')->id => 2]);
        $this->assertSame(['free_from' => '2026-10', 'free_until' => '2026-11', 'paid_from' => '2026-12', 'paid_until' => '2026-12', 'first_billed' => '2027-01'], $walk['services'][$this->service('tv')->id]);
        $this->assertSame(['free_from' => '2026-10', 'free_until' => '2026-10', 'paid_from' => '2026-11', 'paid_until' => '2026-12', 'first_billed' => '2027-01'], $walk['services'][$this->service('internet')->id]);

        foreach (['2026-10', '2026-11', '2026-12'] as $period) {
            $this->assertSame('0.00', $this->runMonth($customer, $period)->totalBill, "{$period}: nothing to pay");
        }

        $january = $this->runMonth($customer, '2027-01');
        $this->assertSame('5000.00', $january->bill);
        $this->assertSame([], array_filter($january->servicePrepaid, fn (array $s): bool => $s['months'] > 0));
    }

    public function test_a_service_paid_upfront_is_charged_while_another_is_free(): void
    {
        // Partly free month: only Internet charged, and it is paid upfront.
        $customer = $this->customer(['tv', 'internet'], billingStarts: '2026-10');
        $this->grant($customer, 'tv', 2, '2026-10');
        $this->upfront($customer, ['internet' => 1]);

        $october = $this->runMonth($customer, '2026-10');
        $this->assertSame('0.00', $october->bill);
        $this->assertSame('2500.00', $october->freeValue);

        $november = $this->runMonth($customer, '2026-11');
        $this->assertSame('2500.00', $november->bill, 'TV still free, Internet billed');
    }

    public function test_free_months_keep_running_while_disconnected_but_paid_months_wait(): void
    {
        // D5 for free months, PD-8 for paid months: disconnected through
        // Oct–Nov, the free months are gone, the paid month is still there.
        $customer = $this->customer(billingStarts: '2026-10');
        $this->grant($customer, 'tv', 2, '2026-10');
        $this->upfront($customer, ['tv' => 1]);

        $customer->update(['status' => 'disconnected']);
        $this->runMonth($customer, '2026-10');
        $this->runMonth($customer, '2026-11');

        $customer->update(['status' => 'active']);
        $december = $this->runMonth($customer, '2026-12');
        $this->assertSame('0.00', $december->totalBill, 'the paid month covers December');
        $this->assertSame('0.00', $december->freeValue);
        $this->assertSame('2500.00', $this->runMonth($customer, '2027-01')->bill);
    }

    public function test_a_rate_change_during_free_or_paid_months_has_no_effect(): void
    {
        $customer = $this->customer(billingStarts: '2026-10');
        $this->grant($customer, 'tv', 1, '2026-10');
        $this->upfront($customer, ['tv' => 1]);

        $this->runMonth($customer, '2026-10');
        $customer->subscriptions()->update(['price' => 4000]);
        $customer->update(['bill' => 4000]);

        $november = $this->runMonth($customer, '2026-11');
        $this->assertSame('0.00', $november->totalBill, 'the paid month covers November whatever the new price');
        $this->assertSame('0.00', $november->totalArrears);

        $this->assertSame('4000.00', $this->runMonth($customer, '2026-12')->bill, 'the new rate applies once the months end');
    }

    public function test_example_e_compensation_after_seven_paid_months_keeps_every_paid_month(): void
    {
        // An existing customer pays 7 months ahead (whole bill). Compensation
        // of 2 months is placed after them (D20): 9 months with nothing to
        // pay, then the bill — the same total whether free or paid come first.
        $customer = $this->customer(billingStarts: null);
        $this->advance($customer, '17500', 7, '2500');

        $this->assertSame(6, $this->runMonth($customer, '2026-10')->prepaidMonthsRemaining);

        $this->grant($customer, null, 2, '2027-05');

        $zeroBills = 0;
        $period = '2026-11';

        for ($i = 0; $i < 8; $i++) {
            if ($this->runMonth($customer, $period)->totalBill === '0.00') {
                $zeroBills++;
            }
            $period = FreeMonthGrant::addMonths($period, 1);
        }

        $this->assertSame(8, $zeroBills, 'Nov–Apr paid + May–Jun free');
        $this->assertSame('0.00', $this->runMonth($customer, '2027-07')->credit);
        $this->assertNotSame('0.00', $this->runMonth($customer, '2027-07')->totalBill);
    }

    public function test_free_months_on_top_of_whole_bill_paid_months_use_no_paid_month(): void
    {
        $customer = $this->customer(billingStarts: null);
        $this->advance($customer, '17500', 7, '2500');
        $this->runMonth($customer, '2026-10');
        $this->grant($customer, null, 2, '2026-11');

        $this->assertSame(6, $this->runMonth($customer, '2026-11')->prepaidMonthsRemaining);
        $this->assertSame(6, $this->runMonth($customer, '2026-12')->prepaidMonthsRemaining);
    }

    public function test_a_month_before_the_installation_month_is_not_billed_and_payments_wait(): void
    {
        $customer = $this->customer(billingStarts: '2026-11');
        $this->pay($customer, '2500');

        $october = $this->runMonth($customer, '2026-10');
        $this->assertSame('not_started', $october->frozenReason);
        $this->assertSame('0.00', $october->totalBill);
        $this->assertCount(0, $october->processedPayments);

        $this->assertSame('2500.00', $this->runMonth($customer, '2026-11')->bill);
    }

    public function test_a_new_customer_without_free_months_is_billed_like_everyone(): void
    {
        // D18: no separate rule for new customers — the first billed month
        // follows the pay-ahead rule exactly like an existing customer's.
        $new = $this->customer(billingStarts: '2026-11');
        $existing = $this->customer(billingStarts: null);

        $this->assertSame($this->runMonth($existing, '2026-11')->totalBill, $this->runMonth($new, '2026-11')->totalBill);
        $this->assertSame($this->runMonth($existing, '2026-12')->totalBill, $this->runMonth($new, '2026-12')->totalBill);
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
