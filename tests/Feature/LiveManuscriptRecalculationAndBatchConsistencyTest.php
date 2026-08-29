<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Zone;
use App\Services\CustomerManuscriptRecalculationService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Pre-implementation validation for a NOT-YET-BUILT feature: triggering
 * App\Services\CustomerManuscriptRecalculationService::recalculateOne() the
 * instant a payment is verified mid-month ("live manuscript update"), on top
 * of the existing monthly manuscript:calculate batch run for the whole
 * tenant. This file proves (or disproves) that the two mechanisms — one
 * customer at a time via recalculateOne(), the whole tenant at once via the
 * real manuscript:calculate command — never conflict for the SAME
 * customer/period, regardless of which one runs first or how many times
 * either one fires.
 *
 * Both paths share the identical eligibility mechanism documented on
 * App\Services\ManuscriptCalculator's class doc comment
 * (`processed_period IS NULL OR processed_period = period`) via
 * App\Support\ScheduledTasks\ManuscriptChunkDataResolver, so in principle a
 * live update and a later batch run for the same period should be
 * interchangeable and idempotent. These tests exercise that claim against
 * the real `tenantswecom` schema exactly like
 * tests/Feature/ManuscriptCalculateTest.php and
 * tests/Feature/CustomerReconnectArrearsPaymentTest.php do: manuscript:calculate
 * owns its own tenancy()->initialize()/end() lifecycle and processes the
 * ENTIRE real customer table, so writes here are real (non-transactional)
 * and explicitly cleaned up in a finally block, using far-future periods
 * that no other test or real production run has ever touched.
 */
class LiveManuscriptRecalculationAndBatchConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Scenario 1: a live mid-month recalculation fires first (simulating a
     * payment being verified), THEN the monthly batch catches up for the
     * same period. The batch must recognize the payment as already consumed
     * for this period and reproduce the exact same manuscript — not revert
     * it, not double-count the payment, not leave a second row.
     */
    public function test_live_update_then_monthly_batch_is_byte_identical(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        $period = '2033-01';

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
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

        try {
            // Live update: payment verified mid-month, recalculateOne fires
            // immediately for the current period.
            $liveManuscript = app(CustomerManuscriptRecalculationService::class)
                ->recalculateOne($customer, $period);

            // bill(2500) exactly covered by the 2500 payment -> no arrears,
            // no credit. total_bill is total_arrears/credit's SIBLING output
            // (bill + total_arrears - credit = 2500 + 0 - 0 = 2500), not
            // "amount still owed" — it only clamps to 0 when credit actually
            // exceeds the bill (an overpayment), never merely because the
            // bill was paid exactly. See ManuscriptCalculator's class doc
            // comment and tests/Feature/ManuscriptCalculateTest.php's
            // test_credit_is_consumed_before_arrears for the same formula
            // applied elsewhere.
            $this->assertEqualsWithDelta(0.0, (float) $liveManuscript->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $liveManuscript->credit, 0.001);
            $this->assertEqualsWithDelta(2500.0, (float) $liveManuscript->total_bill, 0.001);
            $this->assertSame($period, $payment->fresh()->processed_period);

            $liveArrears = $liveManuscript->total_arrears;
            $liveCredit = $liveManuscript->credit;
            $liveTotalBill = $liveManuscript->total_bill;
            $livePaymentExpiration = $liveManuscript->payment_expiration;

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count(),
                'the live update must have created exactly one manuscript row'
            );

            tenancy()->end();

            // Monthly batch catches up for the SAME period, covering this
            // customer among the whole real tenant.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $batchManuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($batchManuscript);
            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count(),
                'the batch run must have upserted the existing row, never duplicated it'
            );

            // The batch must reproduce the live update's result exactly —
            // not re-count the payment (it must see processed_period already
            // = $period as eligible-but-already-consumed) and not revert
            // anything.
            $this->assertSame($liveArrears, $batchManuscript->total_arrears, 'total_arrears must be byte-identical after the batch catches up.');
            $this->assertSame($liveCredit, $batchManuscript->credit, 'credit must be byte-identical after the batch catches up.');
            $this->assertSame($liveTotalBill, $batchManuscript->total_bill, 'total_bill must be byte-identical after the batch catches up.');
            $this->assertSame($livePaymentExpiration, $batchManuscript->payment_expiration);

            $this->assertSame($period, $payment->fresh()->processed_period, 'must stay attributed to the live-update period, not reassigned by the batch.');
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            // manuscript:calculate touches the whole real tenant, so clean up
            // every manuscript row it wrote for this far-future period, not
            // just this test's own fixture.
            Manuscript::query()->where('period', $period)->delete();
            Payment::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->forceDelete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }

    /**
     * Scenario 2 (reverse order): the monthly batch runs FIRST for the
     * period (consuming the payment), and only afterwards does a live
     * update fire redundantly for the same customer/period — e.g. a
     * late-arriving "payment verified" event after the batch already
     * processed it. This must be a pure no-op: byte-identical manuscript,
     * exactly one row, payment still attributed to the same period.
     */
    public function test_monthly_batch_then_redundant_live_update_is_a_no_op(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        $period = '2033-02';

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 3000,
            'others' => 500,
            'status' => 'active',
        ]);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);
        tenancy()->end();

        try {
            // Monthly batch runs first for the whole tenant.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $batchManuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($batchManuscript);
            $this->assertSame($period, $payment->fresh()->processed_period);

            // First run: previousArrears = others(500), previousCredit = 0.
            // net = (previousArrears - previousCredit) + (bill - income)
            //     = (500 - 0) + (3000 - 1000) = 500 + 2000 = 2500 -> all
            // arrears, no credit. (total_bill, not independently asserted
            // here, would separately be bill + arrears - credit = 3000 +
            // 2500 - 0 = 5500 — that 5500 figure is total_bill's value, not
            // total_arrears's; this comment previously conflated the two.)
            $this->assertEqualsWithDelta(2500.0, (float) $batchManuscript->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $batchManuscript->credit, 0.001);

            $batchArrears = $batchManuscript->total_arrears;
            $batchCredit = $batchManuscript->credit;
            $batchTotalBill = $batchManuscript->total_bill;
            $batchPaymentExpiration = $batchManuscript->payment_expiration;

            // A live update fires redundantly for the SAME customer/period
            // after the batch already consumed everything — e.g. a
            // late-arriving verification event.
            $liveManuscript = app(CustomerManuscriptRecalculationService::class)
                ->recalculateOne($customer, $period);

            $this->assertSame($batchArrears, $liveManuscript->total_arrears, 'total_arrears must be unchanged by the redundant live update.');
            $this->assertSame($batchCredit, $liveManuscript->credit, 'credit must be unchanged by the redundant live update.');
            $this->assertSame($batchTotalBill, $liveManuscript->total_bill, 'total_bill must be unchanged by the redundant live update.');
            $this->assertSame($batchPaymentExpiration, $liveManuscript->payment_expiration);

            $this->assertSame($period, $payment->fresh()->processed_period, 'must stay attributed to the same period, not reprocessed as new income.');

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count(),
                'the redundant live update must not create a duplicate manuscript row'
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            Manuscript::query()->where('period', $period)->delete();
            Payment::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->forceDelete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }

    /**
     * Scenario 3: two verified payments land in the same period for the same
     * customer, each triggering its own live update (recalculateOne called
     * twice — once per payment, as they're verified one after another).
     * The final state must match what a SINGLE monthly batch run, processing
     * BOTH payments together from scratch, would have produced for an
     * otherwise-identical customer — proving the order/count of consumption
     * (live twice vs. batch once) never changes the final numbers.
     */
    public function test_two_incremental_live_updates_match_a_single_batch_run_processing_both_payments(): void
    {
        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');

        $period = '2033-03';

        tenancy()->initialize($tenant);
        $zone = ZoneFactory::new()->create();

        // Customer C: two live updates, one per payment, as they arrive.
        $customerC = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        // Customer D: an otherwise-identical customer whose two payments
        // both already exist by the time a single batch run processes them
        // together, from scratch.
        $customerD = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $payment1C = PaymentFactory::new()->create([
            'customer_id' => $customerC->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);

        $customerIds = [$customerC->id, $customerD->id];

        try {
            $recalculator = app(CustomerManuscriptRecalculationService::class);

            // First payment verified -> first live update. Only payment1C
            // exists so far.
            $recalculator->recalculateOne($customerC, $period);
            $this->assertSame($period, $payment1C->fresh()->processed_period);

            // Second payment for the SAME customer/period arrives later and
            // gets verified -> second live update.
            $payment2C = PaymentFactory::new()->create([
                'customer_id' => $customerC->id,
                'amount' => 1500,
                'verification_status' => 'verified',
            ]);

            $liveManuscriptC = $recalculator->recalculateOne($customerC, $period);

            // bill(2500) exactly covered by 1000 + 1500 = 2500 income -> no
            // arrears, no credit. total_bill stays at the bill amount itself
            // (2500) — it only clamps toward 0 via an actual credit
            // exceeding the bill, never merely because the bill was paid
            // exactly (see the same note on the first test in this file). If
            // the second call had lost the first payment's effect or
            // double-counted it, this would fail (either 2500 arrears
            // remaining, or a 1000 credit).
            $this->assertEqualsWithDelta(0.0, (float) $liveManuscriptC->total_arrears, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $liveManuscriptC->credit, 0.001);
            $this->assertEqualsWithDelta(2500.0, (float) $liveManuscriptC->total_bill, 0.001);
            $this->assertSame($period, $payment1C->fresh()->processed_period, 'the first payment must still be attributed to this period after the second live update.');
            $this->assertSame($period, $payment2C->fresh()->processed_period);

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period)->count(),
                'two live updates for the same customer/period must upsert, never duplicate'
            );

            $cArrears = $liveManuscriptC->total_arrears;
            $cCredit = $liveManuscriptC->credit;
            $cTotalBill = $liveManuscriptC->total_bill;

            // Customer D: both payments already exist, untouched, before a
            // single batch run processes them together from scratch.
            $payment1D = PaymentFactory::new()->create([
                'customer_id' => $customerD->id,
                'amount' => 1000,
                'verification_status' => 'verified',
            ]);
            $payment2D = PaymentFactory::new()->create([
                'customer_id' => $customerD->id,
                'amount' => 1500,
                'verification_status' => 'verified',
            ]);

            tenancy()->end();

            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
            ])->assertExitCode(0);

            tenancy()->initialize($tenant);

            $manuscriptD = Manuscript::query()
                ->where('customer_id', $customerD->id)
                ->where('period', $period)
                ->first();

            $this->assertNotNull($manuscriptD);
            $this->assertSame($period, $payment1D->fresh()->processed_period);
            $this->assertSame($period, $payment2D->fresh()->processed_period);

            // The batch, consuming both payments together in a single run,
            // must land on EXACTLY the same numbers as the two incremental
            // live updates did.
            $this->assertSame($cArrears, $manuscriptD->total_arrears, "D's single-batch total_arrears must match C's two-live-update total_arrears.");
            $this->assertSame($cCredit, $manuscriptD->credit, "D's single-batch credit must match C's two-live-update credit.");
            $this->assertSame($cTotalBill, $manuscriptD->total_bill, "D's single-batch total_bill must match C's two-live-update total_bill.");

            // The batch run touching the whole tenant (including customer C,
            // already fully processed by the two live updates) must not
            // have perturbed C's manuscript either.
            $manuscriptCAfterBatch = Manuscript::query()
                ->where('customer_id', $customerC->id)
                ->where('period', $period)
                ->first();
            $this->assertSame($cArrears, $manuscriptCAfterBatch->total_arrears, "C's manuscript must be unaffected by the batch run picking up other customers.");
            $this->assertSame($cCredit, $manuscriptCAfterBatch->credit);
            $this->assertSame($cTotalBill, $manuscriptCAfterBatch->total_bill);
            $this->assertSame($period, $payment1C->fresh()->processed_period, 'C\'s payments must stay attributed to the live-update period after the batch run.');
            $this->assertSame($period, $payment2C->fresh()->processed_period);

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period)->count()
            );
            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customerD->id)->where('period', $period)->count()
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }

            Manuscript::query()->where('period', $period)->delete();
            Payment::query()->whereIn('customer_id', $customerIds)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereIn('id', $customerIds)->forceDelete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }
}
