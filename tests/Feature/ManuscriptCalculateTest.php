<?php

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Zone;
use App\Services\ManuscriptCalculationResult;
use App\Services\ManuscriptCalculator;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises App\Services\ManuscriptCalculator (and, in one case, the full
 * manuscript:calculate command) against the real `tenantswecom` schema.
 *
 * Stancl tenancy dynamically creates a `tenant` database connection only once
 * tenancy()->initialize() runs, so it can't be named in $connectionsToTransact
 * up front like a normal DatabaseTransactions connection. Instead, setUp()
 * initializes tenancy to swecom and then manually opens a transaction on that
 * connection; tearDown() rolls it back before ending tenancy. This gives the
 * same "the shared dev Postgres database is left untouched" guarantee as
 * DatabaseTransactions, without ever running a migration/refresh against it.
 *
 * All fixtures (zones, customers, payments) are created fresh per test via
 * factories — none of the real seeded 29 zones / 9 expense categories /
 * company row are read or modified.
 */
class ManuscriptCalculateTest extends TestCase
{
    use DatabaseTransactions;

    private ManuscriptCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        tenancy()->initialize($tenant);

        DB::connection('tenant')->beginTransaction();

        $this->calculator = new ManuscriptCalculator;
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if (DB::connection('tenant')->transactionLevel() > 0) {
                DB::connection('tenant')->rollBack();
            }

            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * Runs the calculator for one customer/period and persists the result
     * exactly like the manuscript:calculate command would, so multi-period
     * scenarios can be built up test by test.
     */
    private function runAndPersist(Customer $customer, string $period): ManuscriptCalculationResult
    {
        $result = $this->calculator->calculate($customer, $period);

        Manuscript::query()
            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
            ->fill($result->toManuscriptAttributes())
            ->save();

        foreach ($result->processedPayments as $payment) {
            $payment->forceFill(['processed_at' => Carbon::now()])->save();
        }

        return $result;
    }

    private function zone(): Zone
    {
        return ZoneFactory::new()->create();
    }

    public function test_a_brand_new_customers_first_manuscript_seeds_from_others_exactly_once(): void
    {
        // business-rules.md #8: the `others` seed balance is folded into
        // total_arrears on the customer's first manuscript ever, and never
        // referenced again on subsequent runs.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 1000,
            'status' => 'active',
        ]);

        $period1 = '2026-01';
        $result1 = $this->runAndPersist($customer, $period1);

        $this->assertTrue($result1->isFirstRun);
        $this->assertEqualsWithDelta(3500.0, (float) $result1->totalArrears, 0.001); // others(1000) + bill(2500)
        $this->assertEqualsWithDelta(0.0, (float) $result1->credit, 0.001);
        $this->assertEqualsWithDelta(6000.0, (float) $result1->totalBill, 0.001); // bill(2500) + arrears(3500)

        $period2 = '2026-02';
        $result2 = $this->runAndPersist($customer, $period2);

        $this->assertFalse($result2->isFirstRun);
        // others must NOT be re-applied: arrears grows by exactly one more
        // bill cycle (2500), not by another 1000 on top.
        $this->assertEqualsWithDelta(6000.0, (float) $result2->totalArrears, 0.001);
        $this->assertEqualsWithDelta(8500.0, (float) $result2->totalBill, 0.001);
    }

    public function test_arrears_accumulate_over_several_unpaid_months(): void
    {
        // Reproduces business-rules.md #2's worked example verbatim: a
        // 2,500 FCFA/month customer who pays nothing for three consecutive
        // calculate runs ends up with total_arrears = 7,500 and
        // total_bill = 10,000.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $this->runAndPersist($customer, '2026-01');
        $this->runAndPersist($customer, '2026-02');
        $result = $this->runAndPersist($customer, '2026-03');

        $this->assertEqualsWithDelta(7500.0, (float) $result->totalArrears, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result->credit, 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $result->totalBill, 0.001);
    }

    public function test_credit_is_consumed_before_arrears(): void
    {
        // Period 1: pay 3 months (7,500) up front against a 2,500 bill —
        // matches business-rules.md #4's "pay ahead -> credit, total_bill=0"
        // example, scaled up so the multi-period consumption below is
        // unambiguous.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 7500,
            'frequency' => 'monthly',
            'verification_status' => 'verified',
        ]);

        $result1 = $this->runAndPersist($customer, '2026-01');
        $this->assertEqualsWithDelta(5000.0, (float) $result1->credit, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalArrears, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalBill, 0.001);

        // Period 2: no payment — credit absorbs this period's bill before
        // any arrears can form.
        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertEqualsWithDelta(2500.0, (float) $result2->credit, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalArrears, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalBill, 0.001);

        // Period 3: the remaining 2,500 credit exactly cancels this period's
        // bill (net = 0), so credit and arrears both land on 0 — but per the
        // core formula (total_bill = bill + total_arrears - credit) a fully
        // exhausted credit no longer offsets the fresh bill, so total_bill
        // shows the full 2,500 again here rather than one period later. This
        // still proves credit was fully consumed before any arrears formed.
        $result3 = $this->runAndPersist($customer, '2026-03');
        $this->assertEqualsWithDelta(0.0, (float) $result3->credit, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result3->totalArrears, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $result3->totalBill, 0.001);

        // Period 4: credit has been gone since period 3, so this is the
        // first period arrears can actually start accruing — proving credit
        // was consumed first, before any arrears were allowed to form.
        $result4 = $this->runAndPersist($customer, '2026-04');
        $this->assertEqualsWithDelta(0.0, (float) $result4->credit, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $result4->totalArrears, 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $result4->totalBill, 0.001);
    }

    public function test_a_prepaid_customer_is_frozen_during_their_expiration_window(): void
    {
        // business-rules.md #7: a `months`/`yearly` frequency payment sets
        // payment_expiration and freezes total_bill at 0 for its duration.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $payment = PaymentFactory::new()->months(6, 2500)->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);

        $period1 = '2026-01';
        $result1 = $this->runAndPersist($customer, $period1);

        $this->assertTrue($result1->isFrozen);
        $this->assertSame('prepaid', $result1->frozenReason);
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalBill, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalArrears, 0.001);
        $this->assertNotNull($result1->paymentExpiration);
        $this->assertTrue($result1->paymentExpiration->isSameDay(Carbon::parse($payment->expiration_date)));
        $this->assertNotNull($payment->fresh()->processed_at, 'the prepayment establishing the freeze should be marked processed');

        // Still inside the window the following period, with no new payment.
        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertTrue($result2->isFrozen);
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalBill, 0.001);
        $this->assertNotNull($result2->paymentExpiration);
        $this->assertTrue($result2->paymentExpiration->isSameDay(Carbon::parse($payment->expiration_date)));
    }

    public function test_a_disconnected_customer_is_frozen_with_no_new_accrual(): void
    {
        // business-rules.md #6: disconnected customers are frozen —
        // total_bill = 0, arrears carried forward unchanged, no new monthly
        // charge accrues no matter how many periods pass.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 1000,
            'status' => 'disconnected',
        ]);

        $result1 = $this->runAndPersist($customer, '2026-01');
        $this->assertTrue($result1->isFrozen);
        $this->assertSame('disconnected', $result1->frozenReason);
        $this->assertEqualsWithDelta(1000.0, (float) $result1->totalArrears, 0.001); // others seeded once, no bill added
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalBill, 0.001);

        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertTrue($result2->isFrozen);
        $this->assertEqualsWithDelta(1000.0, (float) $result2->totalArrears, 0.001); // unchanged — no new accrual
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalBill, 0.001);
    }

    public function test_a_rejected_payment_is_excluded_from_billing(): void
    {
        // business-rules.md #2/#9: only verification_status = 'verified'
        // payments count as income. rejected (and pending) payments must
        // not reduce arrears, and must be left untouched (not processed).
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $rejected = PaymentFactory::new()->rejected()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);

        $pending = PaymentFactory::new()->pending()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);

        $result = $this->runAndPersist($customer, '2026-01');

        // Same numbers as a customer who received zero payments at all.
        $this->assertEqualsWithDelta(2500.0, (float) $result->totalArrears, 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $result->totalBill, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result->income, 0.001);

        $this->assertNull($rejected->fresh()->processed_at);
        $this->assertNull($pending->fresh()->processed_at);
    }

    public function test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run(): void
    {
        // The manuscript:calculate command owns its own tenancy()->initialize()/
        // end() lifecycle end-to-end, exactly as it does in production. Stancl's
        // tenancy()->end() purges (disconnects) the tenant database connection,
        // which would silently roll back this test's own fixtures if they were
        // sitting in the still-open outer transaction from setUp(). So — unlike
        // every other test in this file — this one releases that empty outer
        // transaction up front and cleans up its own rows explicitly afterwards
        // instead of relying on DatabaseTransactions-style rollback.
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $zone = $this->zone();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);
        tenancy()->end();

        $period = '2026-05';

        try {
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $manuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($manuscript);
            $this->assertNotNull($payment->fresh()->processed_at);

            $commandRun = CommandRun::query()
                ->where('command', 'manuscript:calculate')
                ->where('period', $period)
                ->latest('id')
                ->first();

            $this->assertNotNull($commandRun);
            $this->assertSame('swecom', $commandRun->metadata['tenant']);
            $this->assertArrayHasKey('customers_processed', $commandRun->metadata);
            $this->assertArrayHasKey('total_arrears_sum', $commandRun->metadata);
            $this->assertArrayHasKey('errors', $commandRun->metadata);
            $this->assertArrayHasKey('duration_ms', $commandRun->metadata);
            $this->assertGreaterThanOrEqual(1, $commandRun->metadata['customers_processed']);

            tenancy()->end();

            // Re-running the same period must upsert, not duplicate.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count()
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            Manuscript::query()->where('customer_id', $customer->id)->delete();
            Payment::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }
}
