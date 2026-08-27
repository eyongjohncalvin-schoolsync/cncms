<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ArrearsAdjustment;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zone;
use App\Services\CustomerManuscriptRecalculationService;
use App\Services\ManuscriptGenerationBatchService;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Tests\TestCase;

/**
 * Regression test for a race between the compute/publish preview mechanism
 * (App\Services\ManuscriptGenerationBatchService) and the single-customer
 * immediate-recalculation path used by an approved arrears adjustment
 * (App\Services\CustomerManuscriptRecalculationService::recalculateOne(),
 * called synchronously from App\Services\ArrearsAdjustmentService::
 * applyLedgerEffect()).
 *
 * Timeline exercised:
 *   1. A scheduled batch run computes a preview for period P covering
 *      customer X — computed_result durably stores X's attributes and
 *      payment ids as they stood at compute time; status = 'pending_review'.
 *      Nothing is written to `manuscripts` yet (dispatch() never writes
 *      live data — see that service's class doc).
 *   2. BEFORE anyone publishes that preview, an arrears adjustment for X is
 *      approved and immediately recalculated via recalculateOne() (called
 *      directly here to isolate just the race, per this test's brief —
 *      functionally identical to what ArrearsAdjustmentService::approve()
 *      does via applyLedgerEffect()). This writes a NEW, corrected
 *      manuscript row for X/P directly to `manuscripts`, and stamps the
 *      payment AND the adjustment as processed_period = P.
 *   3. Someone clicks "Publish" on the STALE preview from step 1.
 *
 * Originally written to reproduce a confirmed bug: publish() used to never
 * check for this, and would blindly overwrite X's already-corrected
 * manuscript with the stale, pre-adjustment numbers (ManuscriptGenerationBatchService
 * ::publish()'s firstOrNew()->fill()->save()), silently reverting the
 * correction while the consumed adjustment stayed marked "processed" with no
 * trace of its effect anywhere in the live data. Fixed (2026-08 audit):
 * publish() now skips any customer whose manuscript row was updated at or
 * after the run's own `ran_at` (>=, not >, since both columns are
 * second-precision and a same-second race — the norm in this very test —
 * would otherwise tie) rather than overwrite it. This test now asserts the
 * FIX: X's corrected manuscript survives untouched, and the skip is recorded
 * in `metadata.skipped_stale_customers` rather than happening silently.
 */
class ManuscriptPublishStaleRaceTest extends TestCase
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

    public function test_publishing_a_stale_preview_does_not_revert_a_correction_applied_by_an_arrears_adjustment_in_the_meantime(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);

        $period = '2031-04'; // far-future, unused-elsewhere period — same convention as ManuscriptGenerationBatchServiceTest.

        $commandRun = null;
        $adjustment = null;

        try {
            // --- Step 1: the scheduled batch computes a preview for P. ---
            $commandRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);

            $this->assertSame('pending_review', $commandRun->status);
            $this->assertFalse(Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->exists());

            $staleAttributes = $commandRun->computed_result['customers'][(string) $customer->id]['attributes'];
            $this->assertEqualsWithDelta(1500.0, (float) $staleAttributes['total_arrears'], 0.001);
            $this->assertEqualsWithDelta(4000.0, (float) $staleAttributes['total_bill'], 0.001);
            $this->assertSame(
                [$payment->id],
                $commandRun->computed_result['customers'][(string) $customer->id]['processed_payment_ids'],
                'the stale snapshot must only reference the payment that existed at compute time.'
            );

            // --- Step 2: an arrears adjustment for X is approved and applied
            //     BEFORE anyone publishes the stale preview above. Built
            //     directly (rather than via the full maker-checker
            //     create()/approve() workflow) and recalculateOne() called
            //     directly, to isolate just the compute/publish-vs-
            //     recalculation race per this test's brief — this is exactly
            //     what ArrearsAdjustmentService::applyLedgerEffect() does
            //     for the current period once an adjustment clears approval.
            $requester = User::query()->firstOrFail();

            $adjustment = new ArrearsAdjustment([
                'customer_id' => $customer->id,
                'target_period' => $period,
                'direction' => 'decrease',
                'amount' => '800.00',
                'reason_category' => 'billing_error',
                'reason_note' => 'Correcting a billing error discovered after the batch preview was computed.',
                'arrears_snapshot' => '1500.00',
                'status' => 'approved',
            ]);
            $adjustment->requested_by = $requester->id;
            $adjustment->approved_by = $requester->id;
            $adjustment->approved_at = now();
            $adjustment->save();

            $correctedManuscript = app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer, $period);

            // Sanity check: the correction really did land, with the
            // adjustment's -800 folded in on top of the same payment.
            $this->assertEqualsWithDelta(700.0, (float) $correctedManuscript->total_arrears, 0.001);
            $this->assertEqualsWithDelta(3200.0, (float) $correctedManuscript->total_bill, 0.001);
            $this->assertSame($period, $payment->fresh()->processed_period);
            $this->assertSame($period, $adjustment->fresh()->processed_period);

            // --- Step 3: someone now clicks "Publish" on the STALE preview. ---
            $publisher = User::query()->first();
            $this->batches->publish($commandRun->fresh(), $publisher?->id);

            $finalManuscript = Manuscript::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->firstOrFail();

            // THE FIX: publish() detects that this manuscript row was
            // updated at/after the run's own ran_at and skips it entirely,
            // rather than overwriting recalculateOne()'s correction with its
            // own stale stored snapshot.
            $this->assertEqualsWithDelta(
                700.0,
                (float) $finalManuscript->total_arrears,
                0.001,
                'publish() must leave the out-of-band-corrected manuscript untouched, not overwrite it with the stale pre-adjustment total_arrears.'
            );
            $this->assertEqualsWithDelta(
                3200.0,
                (float) $finalManuscript->total_bill,
                0.001,
                'publish() must leave the out-of-band-corrected manuscript untouched, not overwrite it with the stale pre-adjustment total_bill.'
            );

            // The adjustment's processed_period is untouched by publish()
            // (it was never in the stale snapshot's processed_adjustment_ids
            // to begin with) — still correctly attributed to P from
            // recalculateOne(), consistent with the manuscript it produced.
            $this->assertSame(
                $period,
                $adjustment->fresh()->processed_period,
                'the adjustment must stay attributed to this period, consistent with the correction it produced.'
            );

            // The run still finishes as 'published' — a stale customer is
            // skipped, not treated as a whole-run failure — but the skip
            // must be visible in metadata, not silent.
            $this->assertSame('published', $commandRun->fresh()->status);
            $this->assertSame(
                [$customer->id],
                $commandRun->fresh()->metadata['skipped_stale_customers'] ?? null,
                'the skipped customer must be recorded in metadata, not silently dropped.'
            );
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($this->tenant);
            }

            if ($adjustment) {
                ArrearsAdjustment::query()->whereKey($adjustment->id)->delete();
            }

            Manuscript::query()->where('customer_id', $customer->id)->delete();
            Payment::query()->where('customer_id', $customer->id)->delete();
            Customer::query()->whereKey($customer->id)->delete();
            Zone::query()->whereKey($zone->id)->delete();

            if ($commandRun) {
                CommandRun::query()->whereKey($commandRun->id)->delete();
            }

            tenancy()->end();
        }
    }
}
