<?php

namespace Tests\Feature;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Zone;
use App\Services\ManuscriptCalculationResult;
use App\Services\ManuscriptCalculator;
use App\Services\ManuscriptService;
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
    private function runAndPersist(Customer $customer, string $period, ?Carbon $asOf = null): ManuscriptCalculationResult
    {
        // Resolves the same two lookups App\Console\Commands\ManuscriptCalculate
        // now batch-resolves per chunk, but for this single customer — mirrors
        // production exactly since ManuscriptCalculator::calculate() no longer
        // queries the database itself.
        $previousManuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        // Eligibility mirrors App\Support\ScheduledTasks\ManuscriptChunkDataResolver::resolve()
        // and App\Console\Commands\ManuscriptCalculate exactly — see
        // App\Services\ManuscriptCalculator's class doc for the full
        // rationale (this is the fix for the idempotency bug this test file
        // exercises below).
        $eligibleVerifiedPayments = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('verification_status', 'verified')
            ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))
            ->get();

        $eligibleAdjustments = ArrearsAdjustment::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'approved')
            ->where('target_period', $period)
            ->where(fn ($query) => $query->whereNull('processed_period')->orWhere('processed_period', $period))
            ->get();

        $result = $this->calculator->calculate($customer, $period, $previousManuscript, $eligibleVerifiedPayments, $eligibleAdjustments, $asOf);

        Manuscript::query()
            ->firstOrNew(['customer_id' => $customer->id, 'period' => $period])
            ->fill($result->toManuscriptAttributes())
            ->save();

        foreach ($result->processedPayments as $payment) {
            $payment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
        }

        foreach ($result->processedAdjustments as $adjustment) {
            $adjustment->forceFill(['processed_at' => Carbon::now(), 'processed_period' => $period])->save();
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

    public function test_a_prepaid_customers_freeze_lifts_exactly_on_the_expiration_day(): void
    {
        // business-rules.md #7 / step 3 of the calculation formula: the freeze
        // condition is `payment_expiration > TODAY` (strict greater-than), so
        // on the exact calendar day the expiration date arrives, billing must
        // already have resumed — not one day early, not one day late. Calls
        // the calculator directly (bypassing runAndPersist) so a fixed $asOf
        // can be pinned to the day before and the day of expiration.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $payment = PaymentFactory::new()->months(1, 2500)->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);

        $expiration = Carbon::parse($payment->expiration_date);

        $dayBefore = $this->calculator->calculate(
            $customer,
            '2026-01',
            null,
            collect([$payment]),
            collect(),
            $expiration->copy()->subDay(),
        );
        $this->assertTrue($dayBefore->isFrozen, 'still inside the prepaid window one day before expiration');
        $this->assertSame('prepaid', $dayBefore->frozenReason);
        $this->assertEqualsWithDelta(0.0, (float) $dayBefore->totalBill, 0.001);

        $onExpirationDay = $this->calculator->calculate(
            $customer,
            '2026-01',
            null,
            collect([$payment]),
            collect(),
            $expiration->copy(),
        );
        $this->assertFalse($onExpirationDay->isFrozen, 'freeze must have lifted by the exact expiration day, not one day later');
        $this->assertEqualsWithDelta(2500.0, (float) $onExpirationDay->totalBill, 0.001);
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

    public function test_a_suspended_customer_is_frozen_with_no_new_accrual(): void
    {
        // Fix 1 (2026-08 audit): `suspended` must freeze arrears exactly like
        // `disconnected` — payments are already blocked for both statuses
        // (StorePaymentRequest, PaymentService::createMany(), SyncService),
        // so a suspended customer left accruing new monthly charges had no
        // way to ever pay them down. Mirrors
        // test_a_disconnected_customer_is_frozen_with_no_new_accrual exactly.
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 1000,
            'status' => 'suspended',
        ]);

        $result1 = $this->runAndPersist($customer, '2026-01');
        $this->assertTrue($result1->isFrozen);
        $this->assertSame('suspended', $result1->frozenReason);
        $this->assertEqualsWithDelta(1000.0, (float) $result1->totalArrears, 0.001); // others seeded once, no bill added
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalBill, 0.001);

        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertTrue($result2->isFrozen);
        $this->assertEqualsWithDelta(1000.0, (float) $result2->totalArrears, 0.001); // unchanged — no new accrual
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalBill, 0.001);
    }

    /**
     * Direct reproduction of the audit's confirmed bug and its fix: over 60
     * consecutive monthly runs (5 years), a `suspended` customer's arrears
     * must stay exactly as frozen as an otherwise-identical `disconnected`
     * customer's — not balloon to 60 bill-cycles' worth (the pre-fix
     * behavior the audit measured at 157,500 FCFA for a 2,500 FCFA/month
     * customer with 7,500 seeded via `others`: 7,500 + 60*2,500 = 157,500).
     */
    public function test_a_suspended_customer_stays_frozen_across_a_five_year_sixty_period_stress_scenario(): void
    {
        $zone = $this->zone();

        $suspended = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 7500,
            'status' => 'suspended',
        ]);

        $disconnected = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 7500,
            'status' => 'disconnected',
        ]);

        $lastSuspendedResult = null;
        $lastDisconnectedResult = null;

        for ($month = 1; $month <= 60; $month++) {
            $period = Carbon::create(2026, 1, 1)->addMonths($month - 1)->format('Y-m');

            $lastSuspendedResult = $this->runAndPersist($suspended, $period);
            $lastDisconnectedResult = $this->runAndPersist($disconnected, $period);
        }

        // The bug: arrears would have grown to others(7500) + 60*bill(2500) =
        // 157,500. The fix: arrears never move past the seeded 7,500.
        $this->assertEqualsWithDelta(7500.0, (float) $lastSuspendedResult->totalArrears, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $lastSuspendedResult->totalBill, 0.001);
        $this->assertTrue($lastSuspendedResult->isFrozen);
        $this->assertSame('suspended', $lastSuspendedResult->frozenReason);

        // Suspended must behave byte-identically to disconnected — same
        // seeded arrears in, same frozen arrears out, after the same 60
        // periods of zero payments.
        $this->assertSame($lastDisconnectedResult->totalArrears, $lastSuspendedResult->totalArrears);
        $this->assertSame($lastDisconnectedResult->credit, $lastSuspendedResult->credit);
        $this->assertSame($lastDisconnectedResult->totalBill, $lastSuspendedResult->totalBill);

        $suspendedManuscript = Manuscript::query()->where('customer_id', $suspended->id)->where('period', '2030-12')->first();
        $this->assertNotNull($suspendedManuscript);
        $this->assertEqualsWithDelta(7500.0, (float) $suspendedManuscript->total_arrears, 0.001);
    }

    /**
     * Fix 3 (2026-08 audit): locks in behavior that today only works "by
     * accident of code ordering" — a customer with an active multi-month
     * prepayment gets disconnected mid-window, stays disconnected past the
     * point the prepayment would have expired, then reconnects. Because the
     * disconnected/suspended/passive freeze check runs BEFORE the prepaid
     * check in ManuscriptCalculator::calculate(), payment_expiration is
     * still carried forward untouched through every frozen period (the
     * frozen branch's `paymentExpiration: $previousExpiration ? ... : null`),
     * and once reconnected, exactly one fresh bill cycle accrues — never a
     * retroactive charge for any of the periods that were frozen, including
     * the ones that fell inside the original prepaid window.
     */
    public function test_an_active_prepayment_survives_disconnection_and_reconnection_with_no_retroactive_arrears(): void
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        // 12-month prepayment: expiration_date = now() + 12 months.
        $payment = PaymentFactory::new()->months(12, 2500)->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
        ]);
        $expiration = Carbon::parse($payment->expiration_date);

        $now = Carbon::now();

        // Period 1: still active, well inside the prepaid window — frozen as
        // 'prepaid', payment_expiration set, the prepayment consumed.
        $result1 = $this->runAndPersist($customer, '2026-01', $now);
        $this->assertTrue($result1->isFrozen);
        $this->assertSame('prepaid', $result1->frozenReason);
        $this->assertTrue($result1->paymentExpiration->isSameDay($expiration));
        $this->assertNotNull($payment->fresh()->processed_period);

        // Disconnected mid-window (month 2 of the prepaid year).
        $customer->update(['status' => 'disconnected']);

        $result2 = $this->runAndPersist($customer, '2026-02', $now->copy()->addMonths(1));
        $this->assertTrue($result2->isFrozen);
        $this->assertSame('disconnected', $result2->frozenReason, 'disconnected takes priority over prepaid in the freeze check, but expiration must still carry forward.');
        $this->assertNotNull($result2->paymentExpiration);
        $this->assertTrue($result2->paymentExpiration->isSameDay($expiration), 'payment_expiration must be carried forward unchanged through the disconnected freeze.');
        $this->assertEqualsWithDelta(0.0, (float) $result2->totalArrears, 0.001);

        // Still disconnected, now past the point the prepayment would have
        // expired (asOf is 14 months out, past the 12-month window) — must
        // STILL be frozen as 'disconnected', not silently unfreeze because
        // the prepaid window lapsed while nobody was looking.
        $result3 = $this->runAndPersist($customer, '2027-03', $now->copy()->addMonths(14));
        $this->assertTrue($result3->isFrozen);
        $this->assertSame('disconnected', $result3->frozenReason);
        $this->assertEqualsWithDelta(0.0, (float) $result3->totalArrears, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result3->totalBill, 0.001);

        // Reconnect, still past the original expiration.
        $customer->update(['status' => 'active']);

        $result4 = $this->runAndPersist($customer, '2027-04', $now->copy()->addMonths(15));
        $this->assertFalse($result4->isFrozen, 'billing must resume now that the customer is active again and the prepaid window has genuinely lapsed.');
        // Exactly ONE fresh bill cycle of arrears — never a retroactive
        // charge for any of the ~14 frozen periods, including the ones that
        // were also still inside the original prepaid window. total_bill is
        // bill + total_arrears - credit = 2500 + 2500 - 0 = 5000, matching
        // the same formula every other test in this file uses (e.g.
        // test_arrears_accumulate_over_several_unpaid_months).
        $this->assertEqualsWithDelta(2500.0, (float) $result4->totalArrears, 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $result4->totalBill, 0.001);
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

    /**
     * Direct reproduction of the pre-fix idempotency bug found by two
     * independent audits: `income` used to be derived from
     * `processed_at IS NULL` (an "unconsumed by ANY period, ever" flag).
     * The first run for a period correctly sums this payment as income and
     * stamps it processed — but a second, harmless-looking re-run of the
     * SAME period (no new payments in between) then saw `income = 0` (the
     * payment was already stamped), while the previous-period baseline it
     * reads is unaffected by the rerun — fabricating a full new bill's
     * worth of phantom arrears on top of an already-correct 0. The fix
     * (payments.processed_period, period-attributed rather than a one-way
     * flag) must make every value byte-identical across any number of
     * reruns — not just "still exactly one row", which is all the original
     * version of this test file's own rerun test actually checked (see
     * test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run
     * below, now strengthened the same way).
     */
    public function test_rerunning_the_same_period_with_no_new_payments_produces_byte_identical_results(): void
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);

        $period = '2026-01';

        $result1 = $this->runAndPersist($customer, $period);
        $this->assertEqualsWithDelta(2500.0, (float) $result1->income, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $result1->totalArrears, 0.001, 'the bill is exactly covered by the payment — no arrears after run 1.');
        $this->assertEqualsWithDelta(0.0, (float) $result1->credit, 0.001);

        // Re-run the exact same period, with no new payments recorded.
        $result2 = $this->runAndPersist($customer, $period);
        $result3 = $this->runAndPersist($customer, $period);

        // Byte-identical (string) comparison, not just "close enough" floats
        // — the whole point is that the same DECIMAL VALUE must come back
        // every time, not merely a numerically-similar one.
        $this->assertSame($result1->totalArrears, $result2->totalArrears, 'total_arrears must be byte-identical on rerun 2.');
        $this->assertSame($result1->credit, $result2->credit, 'credit must be byte-identical on rerun 2.');
        $this->assertSame($result1->totalBill, $result2->totalBill, 'total_bill must be byte-identical on rerun 2.');
        $this->assertSame($result1->income, $result2->income, 'income must be byte-identical on rerun 2.');

        $this->assertSame($result1->totalArrears, $result3->totalArrears, 'total_arrears must be byte-identical on rerun 3.');
        $this->assertSame($result1->credit, $result3->credit, 'credit must be byte-identical on rerun 3.');
        $this->assertSame($result1->totalBill, $result3->totalBill, 'total_bill must be byte-identical on rerun 3.');

        // The specific fabricated-arrears regression the audits found: this
        // must NOT have become 2,500.
        $this->assertEqualsWithDelta(0.0, (float) $result3->totalArrears, 0.001);

        $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->first();
        $this->assertEqualsWithDelta(0.0, (float) $manuscript->total_arrears, 0.001);
        $this->assertSame(
            1,
            Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count(),
            'a rerun must upsert the existing manuscript row, never duplicate it.'
        );
    }

    /**
     * The property the old `processed_at IS NULL` mechanism was protecting,
     * which the processed_period-based fix must still guarantee: once a
     * payment is consumed by period P, it must never also be counted as
     * income for a DIFFERENT period Q — no double-counting across periods.
     */
    public function test_a_payment_cannot_be_double_counted_across_two_different_periods(): void
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);

        $result1 = $this->runAndPersist($customer, '2026-01');
        $this->assertEqualsWithDelta(2500.0, (float) $result1->income, 0.001);
        $this->assertSame('2026-01', $payment->fresh()->processed_period);

        // The very next period, with no new payment: income must be 0, not
        // 2,500 again — the January payment must not be double-counted.
        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertEqualsWithDelta(0.0, (float) $result2->income, 0.001);
        $this->assertSame('2026-01', $payment->fresh()->processed_period, 'must stay attributed to January, not silently reassigned to February.');

        // Arrears must accrue normally for the unpaid February bill.
        $this->assertEqualsWithDelta(2500.0, (float) $result2->totalArrears, 0.001);
    }

    /**
     * `passive` customers are frozen exactly like `disconnected` ones
     * (ManuscriptCalculator's class doc), but — unlike `disconnected` — they
     * ARE allowed to receive payments through normal channels
     * (PaymentService::createBulk()'s doc: "`passive` is left payable,
     * deliberately not blocked"). A payment recorded while passive must not
     * be lost: it must sit eligible (processed_period stays NULL) across
     * however many frozen periods pass, and get correctly consumed the
     * moment the customer becomes active again — proving the
     * processed_period fix didn't regress this pre-existing carry-forward
     * behavior.
     */
    public function test_a_passive_customers_payment_carries_forward_until_they_become_active_again(): void
    {
        $customer = CustomerFactory::new()->create([
            'zone_id' => $this->zone()->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'passive',
        ]);

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);

        $result1 = $this->runAndPersist($customer, '2026-01');
        $this->assertTrue($result1->isFrozen);
        $this->assertNull($payment->fresh()->processed_period, 'a frozen period must not consume any payment');

        $result2 = $this->runAndPersist($customer, '2026-02');
        $this->assertTrue($result2->isFrozen);
        $this->assertNull($payment->fresh()->processed_period);

        $customer->update(['status' => 'active']);

        $result3 = $this->runAndPersist($customer, '2026-03');
        $this->assertFalse($result3->isFrozen);
        $this->assertEqualsWithDelta(2500.0, (float) $result3->income, 0.001, 'the January payment must still be picked up once billing resumes.');
        $this->assertSame('2026-03', $payment->fresh()->processed_period);
        $this->assertEqualsWithDelta(0.0, (float) $result3->totalArrears, 0.001, 'bill exactly covered by the carried-forward payment — no new arrears.');
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
            $this->assertSame($period, $payment->fresh()->processed_period);

            $arrearsAfterFirstRun = $manuscript->total_arrears;
            $creditAfterFirstRun = $manuscript->credit;
            $totalBillAfterFirstRun = $manuscript->total_bill;

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

            // Re-running the same period must upsert, not duplicate — and,
            // per the idempotency fix, the VALUES must be byte-identical too,
            // not just "still exactly one row" (what this test originally,
            // insufficiently, checked — see
            // test_rerunning_the_same_period_with_no_new_payments_produces_byte_identical_results
            // above for the direct reproduction of the bug this would have
            // missed). --force is required here (2026-08-27): the first run
            // above already published this period, and
            // App\Services\ManuscriptRerunGuard now refuses a bare rerun of
            // an already-published period — see
            // test_a_rerun_of_an_already_published_period_is_refused_without_force
            // below for that refusal tested directly.
            $this->artisan('manuscript:calculate', [
                'period' => $period,
                '--tenant' => 'swecom',
                '--force' => true,
            ])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->count()
            );

            $manuscriptAfterRerun = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            $this->assertSame($arrearsAfterFirstRun, $manuscriptAfterRerun->total_arrears, 'total_arrears must be byte-identical after a harmless rerun.');
            $this->assertSame($creditAfterFirstRun, $manuscriptAfterRerun->credit, 'credit must be byte-identical after a harmless rerun.');
            $this->assertSame($totalBillAfterFirstRun, $manuscriptAfterRerun->total_bill, 'total_bill must be byte-identical after a harmless rerun.');
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

    /**
     * Cross-customer isolation invariant (distinct from every idempotency
     * test above, which only ever exercises ONE customer at a time):
     * manuscript:calculate batch-resolves previousManuscriptsByCustomer /
     * eligibleVerifiedPaymentsByCustomer / eligibleAdjustmentsByCustomer once
     * per 200-customer chunk via groupBy('customer_id')
     * (runForEveryCustomer() above), then calls
     * ManuscriptCalculator::calculate() per customer inside that chunk. This
     * proves that batching never leaks between customers: when customer A's
     * payment situation changes between two runs of the SAME period,
     * customers B and C — whose payment history never changes — must land
     * on EXACTLY what they'd get with zero payment activity of their own,
     * and a THIRD, no-op rerun of that period must be byte-identical to the
     * second.
     *
     * Runs against the real, full customer table (like
     * test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run
     * above — manuscript:calculate has no way to scope to a customer
     * subset), so far-future, never-otherwise-used periods are chosen and
     * EVERY manuscript row for those periods is deleted in the finally block
     * (not just this test's own 3 customers), fully undoing the side effect
     * of running the real command against the whole tenant.
     */
    public function test_an_unrelated_customers_manuscript_is_unaffected_by_another_customers_payment_change_on_rerun(): void
    {
        DB::connection('tenant')->rollBack();
        tenancy()->end();

        tenancy()->initialize(Tenant::find('swecom'));
        $zone = $this->zone();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 3000, 'others' => 500, 'status' => 'active']);
        $customerC = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 4000, 'others' => 0, 'status' => 'active']);
        $customerIds = [$customerA->id, $customerB->id, $customerC->id];
        tenancy()->end();

        $period1 = '2031-08';
        $period2 = '2031-09';

        try {
            // P1: establishes a baseline for all three, nobody has any
            // payment history yet.
            $this->artisan('manuscript:calculate', ['period' => $period1, '--tenant' => 'swecom'])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $bP1 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period1)->firstOrFail();
            $cP1 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period1)->firstOrFail();
            // B: previousArrears seeded from others(500) + bill(3000).
            $this->assertEqualsWithDelta(3500.0, (float) $bP1->total_arrears, 0.001);
            // C: previousArrears seeded from others(0) + bill(4000).
            $this->assertEqualsWithDelta(4000.0, (float) $cP1->total_arrears, 0.001);

            // Only customer A's payment situation changes before P2: a new,
            // large verified payment that overpays A's arrears+bill.
            $paymentA = PaymentFactory::new()->create([
                'customer_id' => $customerA->id,
                'amount' => 10000,
                'verification_status' => 'verified',
            ]);
            tenancy()->end();

            $this->artisan('manuscript:calculate', ['period' => $period2, '--tenant' => 'swecom'])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $bP2Run1 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->firstOrFail();
            $cP2Run1 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->firstOrFail();

            // Sanity: A's own payment really was applied — otherwise this
            // test would prove nothing about isolation.
            $aP2Run1 = Manuscript::query()->where('customer_id', $customerA->id)->where('period', $period2)->firstOrFail();
            $this->assertEqualsWithDelta(0.0, (float) $aP2Run1->total_arrears, 0.001);
            $this->assertEqualsWithDelta(5000.0, (float) $aP2Run1->credit, 0.001);
            $this->assertSame($period2, $paymentA->fresh()->processed_period);

            // B and C must land EXACTLY where zero payment activity of their
            // own would put them: previousNet + this period's bill, nothing
            // else — completely unaffected by A's new payment landing in the
            // same chunk-resolution batch.
            $this->assertEqualsWithDelta(6500.0, (float) $bP2Run1->total_arrears, 0.001, "B's arrears must be unaffected by A's new payment.");
            $this->assertEqualsWithDelta(0.0, (float) $bP2Run1->credit, 0.001);
            $this->assertEqualsWithDelta(9500.0, (float) $bP2Run1->total_bill, 0.001);
            $this->assertNull($bP2Run1->payment_expiration);

            $this->assertEqualsWithDelta(8000.0, (float) $cP2Run1->total_arrears, 0.001, "C's arrears must be unaffected by A's new payment.");
            $this->assertEqualsWithDelta(0.0, (float) $cP2Run1->credit, 0.001);
            $this->assertEqualsWithDelta(12000.0, (float) $cP2Run1->total_bill, 0.001);
            $this->assertNull($cP2Run1->payment_expiration);

            tenancy()->end();

            // A third run of P2, with no further changes for anyone — B and
            // C must be byte-identical to run 2 as well (idempotency
            // compounded with cross-customer isolation). --force is required
            // (2026-08-27): the run just above already published P2.
            $this->artisan('manuscript:calculate', ['period' => $period2, '--tenant' => 'swecom', '--force' => true])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $bP2Run2 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->firstOrFail();
            $cP2Run2 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->firstOrFail();

            $this->assertSame($bP2Run1->total_arrears, $bP2Run2->total_arrears, 'B total_arrears must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($bP2Run1->credit, $bP2Run2->credit, 'B credit must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($bP2Run1->total_bill, $bP2Run2->total_bill, 'B total_bill must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($bP2Run1->payment_expiration, $bP2Run2->payment_expiration);

            $this->assertSame($cP2Run1->total_arrears, $cP2Run2->total_arrears, 'C total_arrears must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($cP2Run1->credit, $cP2Run2->credit, 'C credit must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($cP2Run1->total_bill, $cP2Run2->total_bill, 'C total_bill must be byte-identical across rerun 2 -> 3.');
            $this->assertSame($cP2Run1->payment_expiration, $cP2Run2->payment_expiration);

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->count()
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            // The full-tenant command wrote a manuscript row for EVERY real
            // customer at these far-future, never-otherwise-used periods —
            // not just A/B/C — so clean up ALL of them, not merely this
            // test's own fixtures.
            Manuscript::query()->whereIn('period', [$period1, $period2])->delete();
            Payment::query()->whereIn('customer_id', $customerIds)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->whereIn('period', [$period1, $period2])->delete();
            Customer::query()->whereIn('id', $customerIds)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }

    /**
     * The "already safely runnable" guard (task-scheduler.md's 2026-08-27
     * addendum, App\Services\ManuscriptRerunGuard), exercised directly
     * against the CLI command: once a period has a PUBLISHED command_runs
     * row, a bare rerun (no --force) must be refused — a different check
     * from idx_command_runs_period_inflight, which by this point has
     * nothing in-flight left to collide with (the first run already
     * finished and published). Own tenancy()->initialize()/end() lifecycle,
     * same reasoning as test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run
     * above.
     */
    public function test_a_rerun_of_an_already_published_period_is_refused_without_force(): void
    {
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
        tenancy()->end();

        $period = '2035-01';

        try {
            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom'])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));
            $publishedCommandRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('published', $publishedCommandRun->status);
            $arrearsAfterFirstRun = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->value('total_arrears');
            tenancy()->end();

            // A brand new verified payment arrives, and someone (or a cron
            // misfire) re-triggers the CLI for the SAME period with no
            // --force — this must be refused, and must NOT touch the
            // already-published manuscript or create a second command_runs row.
            tenancy()->initialize(Tenant::find('swecom'));
            PaymentFactory::new()->create([
                'customer_id' => $customer->id,
                'amount' => 2500,
                'verification_status' => 'verified',
            ]);
            tenancy()->end();

            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom'])->assertExitCode(1);

            tenancy()->initialize(Tenant::find('swecom'));

            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'a refused rerun must not create a second command_runs row.'
            );

            $manuscriptAfterRefusedRerun = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();
            $this->assertSame($arrearsAfterFirstRun, $manuscriptAfterRefusedRerun->total_arrears, 'a refused rerun must not have recomputed anything.');
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

    /**
     * The override escape hatch (--force), exercised directly: with it, a
     * rerun of an already-published period proceeds, creates a genuinely
     * new command_runs row (status lifecycle queued -> published), and
     * actually recomputes against whatever new data has arrived since.
     */
    public function test_a_rerun_of_an_already_published_period_succeeds_with_force(): void
    {
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
        tenancy()->end();

        $period = '2035-02';

        try {
            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom'])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));
            $firstCommandRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();

            PaymentFactory::new()->create([
                'customer_id' => $customer->id,
                'amount' => 2500,
                'verification_status' => 'verified',
            ]);
            tenancy()->end();

            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom', '--force' => true])->assertExitCode(0);

            tenancy()->initialize(Tenant::find('swecom'));

            $this->assertSame(
                2,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'a forced rerun must create a genuinely new command_runs row alongside the original.'
            );

            $secondCommandRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->latest('id')->firstOrFail();
            $this->assertNotSame($firstCommandRun->id, $secondCommandRun->id);
            $this->assertSame('published', $secondCommandRun->status);

            // The new payment must actually have been consumed — proving
            // this was a real recomputation, not a no-op.
            $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();
            $this->assertEqualsWithDelta(0.0, (float) $manuscript->total_arrears, 0.001);
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

    /**
     * The CLI's own queued-then-published status lifecycle (2026-08-27)
     * actually engaging idx_command_runs_period_inflight — mirrors
     * ManuscriptGenerationBatchServiceTest::
     * test_a_rapid_double_click_on_the_manual_run_now_trigger_is_rejected_not_duplicated,
     * but for a second CLI invocation racing a first one that's still
     * 'queued' (rather than a second dispatch() call): confirms the
     * partial unique index protects this synchronous path exactly as it
     * protects the async batch path, because it only cares about the
     * command_runs row's status at the moment of insert, not what code
     * inserted it.
     */
    public function test_a_concurrent_cli_invocation_for_the_same_period_is_rejected_by_the_inflight_lock(): void
    {
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

        $period = '2035-03';

        // Simulates a first CLI invocation (or a racing web/scheduled
        // dispatch()) that is still mid-computation — its command_runs row
        // already inserted as 'queued', not yet 'published'.
        $inFlightRun = CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => 'swecom', 'trigger' => 'cli'],
            'status' => 'queued',
        ]);
        tenancy()->end();

        try {
            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom'])->assertExitCode(1);

            tenancy()->initialize(Tenant::find('swecom'));

            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'the second, concurrent-shaped invocation must not have created a competing command_runs row.'
            );
            $this->assertSame('queued', $inFlightRun->fresh()->status, 'the original in-flight row must be untouched.');

            // Nothing computed by the rejected second invocation.
            $this->assertFalse(Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->exists());
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

    /**
     * A FATAL failure (something outside runForEveryCustomer()'s own
     * per-customer try/catch, which already tolerates a single bad customer
     * record without throwing) must mark the queued command_runs row
     * 'failed' — never leave it stuck at 'queued' forever, which would
     * permanently block idx_command_runs_period_inflight from ever letting
     * this period run again. Simulated via a ManuscriptService binding
     * whose forgetSummaryCache() throws, since that runs after the real
     * per-customer computation has already succeeded.
     */
    public function test_a_fatal_failure_after_computation_marks_the_command_run_failed_not_stuck_queued(): void
    {
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
        tenancy()->end();

        $period = '2035-04';

        $this->app->bind(ManuscriptService::class, fn () => new class extends ManuscriptService
        {
            public function __construct() {}

            public function forgetSummaryCache(string $period): void
            {
                throw new \RuntimeException('Simulated fatal failure after computation.');
            }
        });

        try {
            $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => 'swecom'])->assertExitCode(1);

            tenancy()->initialize(Tenant::find('swecom'));

            $commandRun = CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->firstOrFail();
            $this->assertSame('failed', $commandRun->status);
            $this->assertArrayHasKey('exception', $commandRun->metadata);

            // The manuscript upsert itself (which happens before the
            // simulated failure point) is real and unaffected — only the
            // command_runs row's final status reflects the failure.
            $this->assertTrue(Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->exists());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize(Tenant::find('swecom'));
            }

            Manuscript::query()->where('customer_id', $customer->id)->delete();
            CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            tenancy()->end();
        }
    }
}
