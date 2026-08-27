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
use App\Services\ManuscriptGenerationBatchService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Tests\TestCase;

/**
 * Stress-tests App\Services\CustomerManuscriptRecalculationService::
 * recalculateOne() for the shape a not-yet-built "live-recalculate on
 * payment verification" feature would actually take: repeated, interleaved
 * calls for ONE customer while OTHER customers' independent payment/
 * manuscript activity is happening around it. The owner's stated concern is
 * that recalculating one customer must never affect any customer whose own
 * payment situation didn't change — this was already stress-tested for the
 * batch/chunked path (see ManuscriptGenerationBatchServiceTest and
 * ManuscriptCalculateTest's own "unrelated customer" tests), but not yet for
 * recalculateOne() invoked repeatedly/interleaved like a live trigger would.
 *
 * Mirrors ManuscriptGenerationBatchServiceTest's fixture style (real,
 * committed rows cleaned up explicitly in a finally block, rather than
 * DatabaseTransactions) since these tests also exercise
 * ManuscriptGenerationBatchService::dispatch()/publish(), which — under
 * QUEUE_CONNECTION=sync — still cycles the `tenant` connection via Stancl's
 * QueueTenancyBootstrapper for every chunk/batch-completion job, which would
 * silently roll back fixtures sitting in an open outer transaction.
 */
class CustomerManuscriptRecalculationServiceTest extends TestCase
{
    private ManuscriptGenerationBatchService $batches;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find('swecom');
        $this->assertNotNull($tenant, 'The swecom tenant must already be provisioned to run this test.');
        $this->tenant = $tenant;

        $this->batches = app(ManuscriptGenerationBatchService::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, int>  $commandRunIds
     */
    private function cleanUp(Zone $zone, array $customers, array $commandRunIds = []): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($this->tenant);
        }

        $customerIds = array_map(fn (Customer $c): int => $c->id, $customers);

        Manuscript::query()->whereIn('customer_id', $customerIds)->delete();
        Payment::query()->whereIn('customer_id', $customerIds)->delete();
        Customer::query()->whereIn('id', $customerIds)->delete();
        Zone::query()->whereKey($zone->id)->delete();

        if ($commandRunIds !== []) {
            CommandRun::query()->whereIn('id', $commandRunIds)->delete();
        }
    }

    private function activeCustomer(Zone $zone, float $bill): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => $bill,
            'others' => 0,
            'status' => 'active',
        ]);
    }

    /**
     * Points 1 and 2 of the investigation: four customers in the same zone,
     * each with an established P1 baseline (via the batch service scoped to
     * just these four, avoiding touching every real tenant customer). For
     * P2: A gets a verified payment, recalculateOne(A) fires (simulating
     * "A's payment was just verified"), then B gets a DIFFERENT payment
     * recorded WITHOUT calling recalculateOne for B, then recalculateOne(A)
     * fires AGAIN (a second live event for A — e.g. a correction). C and D
     * had zero payment activity and must end up with NO manuscript row at
     * all for P2; B — whose payment was recorded but never fed through
     * recalculateOne — must be completely unaffected by A's two calls.
     */
    public function test_recalculate_one_never_touches_a_customer_whose_own_payment_situation_did_not_change(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customerA = $this->activeCustomer($zone, 2500);
        $customerB = $this->activeCustomer($zone, 3000);
        $customerC = $this->activeCustomer($zone, 4000);
        $customerD = $this->activeCustomer($zone, 2000);
        $customers = [$customerA, $customerB, $customerC, $customerD];
        $customerIds = array_map(fn (Customer $c): int => $c->id, $customers);

        $period1 = '2034-01';
        $period2 = '2034-02';

        $commandRunIds = [];

        try {
            // Baseline P1 for all four, scoped to just this fixture set (not
            // the whole tenant) via the batch service's explicit
            // $customerIds parameter.
            $baselineRun = $this->batches->dispatch($period1, scheduledTask: null, autoPublish: true, customerIds: $customerIds);
            $commandRunIds[] = $baselineRun->id;
            $this->assertSame('published', $baselineRun->fresh()->status);

            foreach ($customers as $customer) {
                $this->assertTrue(
                    Manuscript::query()->where('customer_id', $customer->id)->where('period', $period1)->exists(),
                    "expected a P1 baseline manuscript for customer {$customer->id}"
                );
            }

            $recalc = app(CustomerManuscriptRecalculationService::class);

            // --- P2 interleaving ---

            // A gets a new verified payment.
            $paymentA = PaymentFactory::new()->create([
                'customer_id' => $customerA->id,
                'amount' => 1000,
                'verification_status' => 'verified',
            ]);

            // "A's payment was just verified, live-recalculate A" — call 1.
            $manuscriptA1 = $recalc->recalculateOne($customerA->fresh(), $period2);

            // C and D had zero payment activity and must have NO manuscript
            // row for P2 at all yet.
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->exists());
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerD->id)->where('period', $period2)->exists());
            // B hasn't been recalculated yet either.
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->exists());

            // B gets a DIFFERENT payment recorded — but recalculateOne is
            // deliberately NOT called for B.
            $paymentB = PaymentFactory::new()->create([
                'customer_id' => $customerB->id,
                'amount' => 1500,
                'verification_status' => 'verified',
            ]);

            // A second live event for A (e.g. another payment or a
            // correction) — recalculateOne(A) fires AGAIN. No new payment
            // for A between the two calls, so this must be idempotent.
            $manuscriptA2 = $recalc->recalculateOne($customerA->fresh(), $period2);

            // C and D: still completely untouched by any of A's activity.
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->exists(), 'C must still have no P2 manuscript row.');
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerD->id)->where('period', $period2)->exists(), 'D must still have no P2 manuscript row.');

            // B: recorded a payment but was never passed to recalculateOne —
            // must be entirely unaffected by A's two calls: no manuscript
            // row, payment left un-consumed.
            $this->assertFalse(Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->exists(), "B must still have no P2 manuscript row — recalculateOne was never called for B.");
            $this->assertNull($paymentB->fresh()->processed_at, "B's payment must be untouched by A's recalculateOne calls.");
            $this->assertNull($paymentB->fresh()->processed_period);

            // A: exactly one manuscript row (idempotent second call, not a
            // duplicate), A's own payment genuinely consumed.
            $this->assertSame(1, Manuscript::query()->where('customer_id', $customerA->id)->where('period', $period2)->count());
            $this->assertNotNull($paymentA->fresh()->processed_at, 'sanity: A\'s payment must actually have been consumed, or this test proves nothing.');
            $this->assertSame($period2, $paymentA->fresh()->processed_period);

            // The two back-to-back calls for A (no new payment in between)
            // must be byte-identical — proof the second call didn't
            // fabricate anything even for A itself.
            $this->assertSame($manuscriptA1->fresh()->total_arrears, $manuscriptA2->fresh()->total_arrears);
            $this->assertSame($manuscriptA1->fresh()->credit, $manuscriptA2->fresh()->credit);
            $this->assertSame($manuscriptA1->fresh()->total_bill, $manuscriptA2->fresh()->total_bill);
        } finally {
            $this->cleanUp($zone, $customers, $commandRunIds);
        }
    }

    /**
     * Point 4: the stale-preview guard added to
     * ManuscriptGenerationBatchService::publish() (see that method's own doc
     * comment referencing recalculateOne() by name) was built with the
     * ArrearsAdjustmentService::approve() caller in mind. This confirms it
     * also correctly protects a live-recalc feature built on the SAME
     * primitive: dispatch a batch preview (autoPublish: false) covering A
     * and C, let a live recalculateOne(A) land in between preview-compute
     * and publish (simulating a payment-verification event arriving mid-
     * review), then publish the stale preview. A's live-recalculated
     * manuscript must be preserved untouched (and flagged in
     * skipped_stale_customers); C's must publish normally from the batch.
     */
    public function test_the_stale_preview_guard_protects_a_live_recalc_built_on_recalculate_one(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customerA = $this->activeCustomer($zone, 2500);
        $customerC = $this->activeCustomer($zone, 4000);
        $customers = [$customerA, $customerC];
        $customerIds = [$customerA->id, $customerC->id];

        $period1 = '2034-03';
        $period2 = '2034-04';

        $commandRunIds = [];

        try {
            // Baseline P1 for both.
            $baselineRun = $this->batches->dispatch($period1, scheduledTask: null, autoPublish: true, customerIds: $customerIds);
            $commandRunIds[] = $baselineRun->id;
            $this->assertSame('published', $baselineRun->fresh()->status);

            // Batch preview for P2, covering A and C — NOT auto-published.
            $previewRun = $this->batches->dispatch($period2, scheduledTask: null, autoPublish: false, customerIds: $customerIds);
            $commandRunIds[] = $previewRun->id;
            $this->assertSame('pending_review', $previewRun->status);

            $cComputedAttributes = $previewRun->computed_result['customers'][(string) $customerC->id]['attributes'];

            // A live payment-triggered recalculation lands between
            // preview-compute and publish.
            $paymentA = PaymentFactory::new()->create([
                'customer_id' => $customerA->id,
                'amount' => 1000,
                'verification_status' => 'verified',
            ]);
            $recalc = app(CustomerManuscriptRecalculationService::class);
            $liveManuscriptA = $recalc->recalculateOne($customerA->fresh(), $period2);

            $liveArrearsA = (float) $liveManuscriptA->fresh()->total_arrears;
            $liveCreditA = (float) $liveManuscriptA->fresh()->credit;
            $liveTotalBillA = (float) $liveManuscriptA->fresh()->total_bill;

            // Now publish the now-stale preview.
            $published = $this->batches->publish($previewRun->fresh());
            $this->assertSame('published', $published->status);

            // A's manuscript must be EXACTLY what the live recalculateOne
            // produced — untouched by the stale batch publish.
            $manuscriptA = Manuscript::query()->where('customer_id', $customerA->id)->where('period', $period2)->firstOrFail();
            $this->assertEqualsWithDelta($liveArrearsA, (float) $manuscriptA->total_arrears, 0.001);
            $this->assertEqualsWithDelta($liveCreditA, (float) $manuscriptA->credit, 0.001);
            $this->assertEqualsWithDelta($liveTotalBillA, (float) $manuscriptA->total_bill, 0.001);
            $this->assertContains($customerA->id, $published->metadata['skipped_stale_customers'] ?? [], 'A must be recorded as skipped-stale.');

            // A's live-consumed payment must not be re-touched/reprocessed
            // by the stale publish.
            $this->assertSame($period2, $paymentA->fresh()->processed_period);

            // C's manuscript must publish normally from the batch — never
            // touched by recalculateOne, so nothing to protect it from.
            $manuscriptC = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->firstOrFail();
            $this->assertEqualsWithDelta((float) $cComputedAttributes['total_arrears'], (float) $manuscriptC->total_arrears, 0.001);
            $this->assertEqualsWithDelta((float) $cComputedAttributes['total_bill'], (float) $manuscriptC->total_bill, 0.001);
            $this->assertNotContains($customerC->id, $published->metadata['skipped_stale_customers'] ?? [], 'C must NOT be flagged as skipped-stale.');
        } finally {
            $this->cleanUp($zone, $customers, $commandRunIds);
        }
    }
}
