<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zone;
use App\Services\ManuscriptGenerationBatchService;
use App\Support\ScheduledTasks\ManuscriptChunkDataResolver;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the chunked Bus::batch() manuscript_generation mechanism
 * (task-scheduler.md section 4.1) and its compute/publish split (section 4)
 * against the real `tenantswecom` schema.
 *
 * Unlike ManuscriptCalculateTest's usual pattern (an open, rolled-back
 * `tenant` connection transaction), tests here use REAL, committed fixtures
 * cleaned up explicitly in a `finally` block — same reasoning as that file's
 * own one exception to its own rule
 * (test_the_command_upserts_manuscripts_processes_payments_and_logs_a_command_run):
 * Stancl's DatabaseManager::connectToTenant() unconditionally purges and
 * recreates the `tenant` PDO connection on every tenancy()->initialize()
 * call — including the ones Stancl's QueueTenancyBootstrapper triggers
 * automatically for EVERY queued job (confirmed: Illuminate\Queue\SyncQueue
 * still fires JobProcessing/JobProcessed even under QUEUE_CONNECTION=sync,
 * so this happens even in tests, once per chunk job plus once for the
 * batch's then()/catch() completion job). That purge would silently roll
 * back / disconnect an open outer transaction's uncommitted fixtures before
 * a chunk job ever got to read them. Committing fixtures for real (and
 * deleting them again afterward) is what makes this test exercise the real
 * multi-connection queue path instead of accidentally testing only the
 * trivial case where everything happens to share one DB session.
 *
 * QUEUE_CONNECTION=sync in testing (phpunit.xml) still means every chunk job
 * — and the batch's then()/catch() completion callbacks — run synchronously,
 * in-process, before dispatch() returns, so assertions immediately after a
 * dispatch() call are meaningful without a real queue worker in this run.
 * The job_batches row Laravel writes regardless of queue driver (see
 * config/queue.php's 'batching' block — always the central 'pgsql'
 * connection) proves real, independent per-chunk jobs were created, not one
 * giant job pretending to be chunked.
 */
class ManuscriptGenerationBatchServiceTest extends TestCase
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
     * Deletes everything a test created (in FK-safe order: manuscripts,
     * payments, customers, zone, command_runs) and ends tenancy — mirrors
     * ManuscriptCalculateTest's own explicit-cleanup test. Re-initializes
     * tenancy first if a queued job's tenancy cycling left it ended.
     *
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

    private function activeCustomer(Zone $zone): Customer
    {
        return CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'others' => 0,
            'status' => 'active',
        ]);
    }

    public function test_a_scheduled_run_lands_as_pending_review_without_touching_live_manuscripts_or_payments(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);

        $period = '2026-06';
        $commandRun = null;

        try {
            $commandRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);

            $this->assertSame('pending_review', $commandRun->status);
            $this->assertNotNull($commandRun->batch_id);

            // Live data must be completely untouched by compute — the whole
            // point of the compute/publish split (section 4).
            $this->assertFalse(Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->exists());
            $this->assertNull($payment->fresh()->processed_at);

            $summary = $commandRun->computed_result['summary'];
            $this->assertSame(1, $summary['customers_processed']);
            $this->assertEqualsWithDelta(1500.0, (float) $summary['total_arrears_sum'], 0.001);

            $customerResult = $commandRun->computed_result['customers'][(string) $customer->id];
            $this->assertEqualsWithDelta(1500.0, (float) $customerResult['attributes']['total_arrears'], 0.001);
            $this->assertEqualsWithDelta(4000.0, (float) $customerResult['attributes']['total_bill'], 0.001);
            $this->assertSame([$payment->id], $customerResult['processed_payment_ids']);
        } finally {
            $this->cleanUp($zone, [$customer], $commandRun ? [$commandRun->id] : []);
        }
    }

    public function test_publish_commits_exactly_what_was_computed_even_if_payments_changed_since(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        $originalPayment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);

        $period = '2026-06';
        $commandRun = null;

        try {
            $commandRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);
            $this->assertSame('pending_review', $commandRun->status);

            $computedArrears = (float) $commandRun->computed_result['customers'][(string) $customer->id]['attributes']['total_arrears'];
            $computedTotalBill = (float) $commandRun->computed_result['customers'][(string) $customer->id]['attributes']['total_bill'];

            // A new verified payment arrives AFTER compute but BEFORE publish
            // — task-scheduler.md section 4's core guarantee is that this
            // must NOT change the numbers already computed and shown in the
            // preview.
            $lateArrivingPayment = PaymentFactory::new()->create([
                'customer_id' => $customer->id,
                'amount' => 5000,
                'verification_status' => 'verified',
            ]);

            $publisher = User::query()->first();
            $this->batches->publish($commandRun->fresh(), $publisher?->id);

            $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();

            $this->assertEqualsWithDelta($computedArrears, (float) $manuscript->total_arrears, 0.001);
            $this->assertEqualsWithDelta($computedTotalBill, (float) $manuscript->total_bill, 0.001);

            // Only the payment that was part of the ORIGINAL computed result
            // is marked processed — the late-arriving payment is left
            // untouched for the next period's normal calculation to pick up.
            $this->assertNotNull($originalPayment->fresh()->processed_at);
            $this->assertNull($lateArrivingPayment->fresh()->processed_at);

            $this->assertSame('published', $commandRun->fresh()->status);
            $this->assertNotNull($commandRun->fresh()->published_at);
        } finally {
            $this->cleanUp($zone, [$customer], $commandRun ? [$commandRun->id] : []);
        }
    }

    public function test_publish_is_idempotent_and_never_double_processes_a_run(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'verification_status' => 'verified',
        ]);

        $commandRun = null;

        try {
            $commandRun = $this->batches->dispatch('2026-06', scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);

            $this->batches->publish($commandRun->fresh());
            $firstPublishedAt = $commandRun->fresh()->published_at;

            // A second publish call (e.g. a double-click) must be a no-op,
            // not re-run the manuscripts upsert or re-touch published_at.
            $this->batches->publish($commandRun->fresh());

            $this->assertTrue($firstPublishedAt->equalTo($commandRun->fresh()->published_at));
            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customer->id)->where('period', '2026-06')->count()
            );
        } finally {
            $this->cleanUp($zone, [$customer], $commandRun ? [$commandRun->id] : []);
        }
    }

    /**
     * The exact stale-publish scenario the audits demonstrated: two
     * independent runs for the same period, the correct/more-recent one
     * ALREADY published, then an attempt to publish an OLDER, still
     * pending_review one. That must now be rejected, not silently
     * overwrite the already-live, more-current numbers.
     *
     * freshRun is inserted with status='published' from the start (never
     * passing through 'queued'/'pending_review') so this can coexist with
     * staleRun's 'pending_review' row without tripping
     * idx_command_runs_period_inflight (a partial unique index scoped to
     * ONLY 'queued'/'pending_review' — see that migration's doc comment):
     * with that guard in place, two rows for the same period can no longer
     * BOTH be in-flight at once (that race is exactly what it closes), but
     * "one still pending_review, one already published" is precisely the
     * legitimate state this scenario needs and the guard correctly still
     * allows — e.g. representing rows from before today's fix, or any path
     * that publishes outside the normal dispatch()-mediated sequence. This
     * isolates publish()'s OWN defense-in-depth check specifically.
     */
    public function test_publish_rejects_a_stale_run_once_a_more_recently_dispatched_run_for_the_same_period_is_already_published(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        // A far-future period, deliberately outside the range of any real
        // seeded/demo manuscript:calculate history in this dev database
        // (unlike '2026-06'/'2026-07' used elsewhere, which — being real
        // recent calendar months relative to "today" — already have their
        // own genuine historical 'published' CommandRun rows from actual
        // demo runs, colliding with this test's own row-count assertions).
        $period = '2031-01';

        $staleAttributes = ['bill' => '2500.00', 'total_arrears' => '2500.00', 'credit' => '0.00', 'total_bill' => '5000.00', 'payment_expiration' => null];
        $freshAttributes = ['bill' => '2500.00', 'total_arrears' => '0.00', 'credit' => '0.00', 'total_bill' => '0.00', 'payment_expiration' => null];

        $staleRun = null;
        $freshRun = null;

        try {
            $staleRun = CommandRun::create([
                'command' => 'manuscript:calculate',
                'period' => $period,
                'ran_at' => now()->subHour(),
                'metadata' => [],
                'status' => 'pending_review',
                'computed_result' => [
                    'customers' => [(string) $customer->id => ['attributes' => $staleAttributes, 'processed_payment_ids' => [], 'is_frozen' => false]],
                    'summary' => [],
                ],
            ]);

            // Represents the more recently-dispatched run that already got
            // published (e.g. an admin's manual "Run Now" trigger,
            // auto-published) — inserted directly as 'published' so it
            // never contends with staleRun's still-open 'pending_review'
            // row under the partial unique index.
            $freshRun = CommandRun::create([
                'command' => 'manuscript:calculate',
                'period' => $period,
                'ran_at' => now(),
                'metadata' => [],
                'status' => 'published',
                'published_at' => now(),
                'computed_result' => [
                    'customers' => [(string) $customer->id => ['attributes' => $freshAttributes, 'processed_payment_ids' => [], 'is_frozen' => false]],
                    'summary' => [],
                ],
            ]);

            $this->assertGreaterThan($staleRun->id, $freshRun->id, 'freshRun must have been "dispatched" (inserted) after staleRun for this scenario to be meaningful.');

            // The live manuscript reflects freshRun's already-published numbers.
            Manuscript::query()->firstOrNew(['customer_id' => $customer->id, 'period' => $period])->fill($freshAttributes)->save();

            // An admin now reviews and clicks Publish on the OLDER preview,
            // unaware it's already been superseded — must be rejected.
            $thrown = null;

            try {
                $this->batches->publish($staleRun->fresh());
            } catch (ValidationException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'publishing the stale run must throw, not silently overwrite the live data.');
            $this->assertArrayHasKey('period', $thrown->errors());
            $this->assertSame('pending_review', $staleRun->fresh()->status, 'the rejected stale run must not have been marked published.');

            // The live manuscript must still reflect the FRESH run's numbers.
            $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();
            $this->assertEqualsWithDelta(0.0, (float) $manuscript->total_arrears, 0.001, 'must still be the fresh run\'s numbers, not overwritten by the stale run.');
            $this->assertEqualsWithDelta(0.0, (float) $manuscript->total_bill, 0.001);
        } finally {
            $ids = array_values(array_filter([$staleRun?->id, $freshRun?->id]));
            $this->cleanUp($zone, [$customer], $ids);
        }
    }

    /**
     * The narrower, complementary guard: two independent runs for the SAME
     * period must never both be allowed to sit in-flight at once —
     * idx_command_runs_period_inflight (a partial unique index on
     * (command, period) WHERE status IN ('queued', 'pending_review'), see
     * that migration's doc comment) rejects the second dispatch() outright.
     * This covers the scheduled tick racing a manual "Run Now" click; see
     * test_a_rapid_double_click_on_the_manual_run_now_trigger_is_rejected_not_duplicated
     * below for the manual-trigger-only double-click variant.
     */
    public function test_dispatch_rejects_a_second_in_flight_run_for_the_same_period(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        $period = '2031-02'; // see the stale-publish test above for why a far-future period is used here

        $commandRun = null;

        try {
            $commandRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);
            $this->assertSame('pending_review', $commandRun->status);

            $thrown = null;

            try {
                $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);
            } catch (ValidationException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'a second dispatch() for the same still-in-flight period must be rejected.');
            $this->assertArrayHasKey('period', $thrown->errors());

            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'exactly one CommandRun must exist for this period — the second attempt must not have created a row.'
            );
        } finally {
            $this->cleanUp($zone, [$customer], $commandRun ? [$commandRun->id] : []);
        }
    }

    /**
     * The manual "Run Manuscript Calculation" trigger's own double-click
     * scenario specifically (App\Http\Controllers\ManuscriptController::
     * calculate(), autoPublish: true) — task-scheduler.md's explicit ask to
     * confirm this is actually covered, not assumed. Built directly rather
     * than relying on real queue timing: under QUEUE_CONNECTION=sync (this
     * test suite's driver — see this class's own doc comment), a single
     * dispatch() call runs its whole batch AND auto-publish synchronously
     * before returning, so a real second dispatch() call issued immediately
     * after would never actually observe the first as still in-flight. This
     * directly recreates that in-flight instant instead: the first click's
     * CommandRun row already exists with status='queued' (its batch not yet
     * finished) at the moment the second click's dispatch() runs.
     */
    public function test_a_rapid_double_click_on_the_manual_run_now_trigger_is_rejected_not_duplicated(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        $period = '2031-03'; // see the stale-publish test above for why a far-future period is used here

        $firstClick = null;

        try {
            $firstClick = CommandRun::create([
                'command' => 'manuscript:calculate',
                'period' => $period,
                'ran_at' => now(),
                'metadata' => [],
                'status' => 'queued',
            ]);

            $thrown = null;

            try {
                $this->batches->dispatch($period, scheduledTask: null, autoPublish: true, customerIds: [$customer->id]);
            } catch (ValidationException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'a second "Run Now" click while the first is still in-flight (queued) must be rejected.');
            $this->assertArrayHasKey('period', $thrown->errors());

            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'the second click must not have created a competing CommandRun row.'
            );
        } finally {
            $this->cleanUp($zone, [$customer], $firstClick ? [$firstClick->id] : []);
        }
    }

    public function test_the_batch_actually_chunks_into_multiple_independent_jobs(): void
    {
        tenancy()->initialize($this->tenant);

        config(['scheduled_tasks.manuscript_generation.chunk_size' => 1]);

        $zone = ZoneFactory::new()->create();
        $customers = CustomerFactory::new()->count(3)->create(['zone_id' => $zone->id, 'bill' => 2500, 'others' => 0, 'status' => 'active'])->all();
        $customerIds = array_map(fn (Customer $c): int => $c->id, $customers);

        $commandRun = null;

        try {
            $commandRun = $this->batches->dispatch('2026-06', scheduledTask: null, autoPublish: false, customerIds: $customerIds);

            $batchRow = DB::connection('pgsql')->table('job_batches')->where('id', $commandRun->batch_id)->first();

            $this->assertNotNull($batchRow);
            // Real chunking, not one giant job: 3 customers at chunk_size=1
            // must produce 3 independent ComputeManuscriptChunkJob
            // instances in the batch — Laravel's own job_batches.total_jobs
            // count is the proof, not anything simulated by this test.
            $this->assertSame(3, $batchRow->total_jobs);
            $this->assertSame(0, $batchRow->failed_jobs);
            $this->assertSame('pending_review', $commandRun->fresh()->status);
            $this->assertCount(3, $commandRun->fresh()->computed_result['customers']);
        } finally {
            $this->cleanUp($zone, $customers, $commandRun ? [$commandRun->id] : []);
        }
    }

    /**
     * Simulates a whole-chunk failure (e.g. a transient DB error during the
     * once-per-chunk bulk data resolution) for the LAST customer's chunk
     * specifically — the coarser, batch-level failure mode
     * allowFailures()/catch() exist to tolerate, distinct from the
     * per-customer try/catch ComputeManuscriptChunkJob already handles on
     * its own for a single bad customer record.
     *
     * Poisoning the LAST chunk (rather than the first/middle) is
     * deliberate, not arbitrary: under QUEUE_CONNECTION=sync (this test
     * suite's driver — phpunit.xml), Illuminate\Queue\Queue::bulk() pushes
     * each batch job in a plain foreach, and Illuminate\Queue\SyncQueue
     * RE-THROWS a job's exception after recording its failure
     * (SyncQueue::handleException()) — so under sync specifically, a chunk
     * failure aborts the push loop for anything queued AFTER it, and
     * dispatch() itself throws instead of returning normally. That's a
     * property of the SYNC driver's inherently-blocking single-process
     * execution, not of Bus::batch()'s allowFailures() contract: with a
     * real async queue connection (this app's actual QUEUE_CONNECTION=
     * database — see this feature's build notes), every chunk job is
     * already enqueued before any of them run, so one job failing on one
     * worker never prevents another already-queued job from being picked
     * up and completed by any worker. Poisoning the last chunk lets this
     * test still prove the property IS true for chunks that already
     * completed (A and B's results survive C's failure) without depending
     * on sync-queue-specific push ordering for chunks after the failure.
     */
    public function test_a_single_failed_chunk_does_not_lose_the_other_chunks_results(): void
    {
        tenancy()->initialize($this->tenant);

        config(['scheduled_tasks.manuscript_generation.chunk_size' => 1]);

        $zone = ZoneFactory::new()->create();
        $customerA = $this->activeCustomer($zone);
        $customerB = $this->activeCustomer($zone);
        $customerC = $this->activeCustomer($zone);

        $this->app->bind(ManuscriptChunkDataResolver::class, function () use ($customerC) {
            return new class($customerC->id) extends ManuscriptChunkDataResolver
            {
                public function __construct(private readonly int $poisonCustomerId) {}

                public function resolve(array $customerIds, string $period): array
                {
                    if (in_array($this->poisonCustomerId, $customerIds, true)) {
                        throw new RuntimeException('Simulated chunk-level failure.');
                    }

                    return parent::resolve($customerIds, $period);
                }
            };
        });

        $commandRun = null;

        try {
            try {
                $this->batches->dispatch('2026-06', scheduledTask: null, autoPublish: false, customerIds: [$customerA->id, $customerB->id, $customerC->id]);
                $this->fail('Expected the simulated chunk failure to propagate under the sync queue driver used in tests (see this method\'s doc comment).');
            } catch (RuntimeException $e) {
                $this->assertSame('Simulated chunk-level failure.', $e->getMessage());
            }

            // dispatch() threw before returning its CommandRun (sync-queue
            // quirk explained above), but the row itself was created, and
            // the batch's catch() callback still ran synchronously as part
            // of processing chunk C's failure BEFORE the exception
            // propagated out — so it's already fully updated by now.
            $commandRun = CommandRun::query()->where('period', '2026-06')->latest('id')->firstOrFail();

            // Must NOT silently advance to pending_review with incomplete
            // data (task-scheduler.md section 4.1's explicit
            // "do not auto-transition" rule).
            $this->assertSame('failed', $commandRun->status);

            $computedCustomers = $commandRun->computed_result['customers'];
            $this->assertArrayHasKey((string) $customerA->id, $computedCustomers, 'chunk A succeeded and must not be lost');
            $this->assertArrayHasKey((string) $customerB->id, $computedCustomers, 'chunk B succeeded and must not be lost');
            $this->assertArrayNotHasKey((string) $customerC->id, $computedCustomers);
            $this->assertSame(2, $commandRun->computed_result['summary']['customers_processed']);

            // Live manuscripts remain completely untouched — a failed run
            // must never partially publish.
            $this->assertSame(
                0,
                Manuscript::query()->whereIn('customer_id', [$customerA->id, $customerB->id, $customerC->id])->count()
            );
        } finally {
            $this->cleanUp($zone, [$customerA, $customerB, $customerC], $commandRun ? [$commandRun->id] : []);
        }
    }

    /**
     * Cross-customer isolation invariant for the CHUNKED path (the direct,
     * non-chunked manuscript:calculate command has its own version of this
     * test — see
     * ManuscriptCalculateTest::test_an_unrelated_customers_manuscript_is_unaffected_by_another_customers_payment_change_on_rerun).
     * chunk_size is forced to 1 so each of A/B/C's compute runs in its OWN
     * separate ComputeManuscriptChunkJob — i.e. its own separate
     * App\Support\ScheduledTasks\ManuscriptChunkDataResolver::resolve() call
     * — rather than merely different iterations of one chunk's foreach.
     * When customer A's payment situation changes between two dispatch()
     * runs for the SAME period, customers B and C (never touched) must land
     * on exactly what zero payment activity of their own would produce, and
     * a THIRD, no-op dispatch() of that period must be byte-identical to the
     * second.
     */
    public function test_an_unrelated_customers_manuscript_is_unaffected_by_another_customers_payment_change_across_chunks(): void
    {
        tenancy()->initialize($this->tenant);

        config(['scheduled_tasks.manuscript_generation.chunk_size' => 1]);

        $zone = ZoneFactory::new()->create();
        $customerA = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 3000, 'others' => 500, 'status' => 'active']);
        $customerC = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 4000, 'others' => 0, 'status' => 'active']);
        $customerIds = [$customerA->id, $customerB->id, $customerC->id];

        $period1 = '2032-01';
        $period2 = '2032-02';

        $commandRunIds = [];

        try {
            $run1 = $this->batches->dispatch($period1, scheduledTask: null, autoPublish: true, customerIds: $customerIds);
            $commandRunIds[] = $run1->id;
            $this->assertSame('published', $run1->fresh()->status);

            $bP1 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period1)->firstOrFail();
            $cP1 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period1)->firstOrFail();
            $this->assertEqualsWithDelta(3500.0, (float) $bP1->total_arrears, 0.001);
            $this->assertEqualsWithDelta(4000.0, (float) $cP1->total_arrears, 0.001);

            // Only A's payment situation changes before P2.
            $paymentA = PaymentFactory::new()->create([
                'customer_id' => $customerA->id,
                'amount' => 10000,
                'verification_status' => 'verified',
            ]);

            $run2 = $this->batches->dispatch($period2, scheduledTask: null, autoPublish: true, customerIds: $customerIds);
            $commandRunIds[] = $run2->id;
            $this->assertSame('published', $run2->fresh()->status);

            $batchRow = DB::connection('pgsql')->table('job_batches')->where('id', $run2->batch_id)->first();
            $this->assertSame(3, $batchRow->total_jobs, 'chunk_size=1 with 3 customers must produce 3 independent chunk jobs, proving each customer is resolved in its own chunk.');

            $aP2Run1 = Manuscript::query()->where('customer_id', $customerA->id)->where('period', $period2)->firstOrFail();
            $this->assertEqualsWithDelta(0.0, (float) $aP2Run1->total_arrears, 0.001);
            $this->assertEqualsWithDelta(5000.0, (float) $aP2Run1->credit, 0.001);
            $this->assertNotNull($paymentA->fresh()->processed_at, 'A\'s payment must actually have been consumed, or this test proves nothing.');

            $bP2Run1 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->firstOrFail();
            $cP2Run1 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->firstOrFail();

            $this->assertEqualsWithDelta(6500.0, (float) $bP2Run1->total_arrears, 0.001, "B's arrears must be unaffected by A's new payment even though it was computed in a different chunk job.");
            $this->assertEqualsWithDelta(0.0, (float) $bP2Run1->credit, 0.001);
            $this->assertEqualsWithDelta(9500.0, (float) $bP2Run1->total_bill, 0.001);

            $this->assertEqualsWithDelta(8000.0, (float) $cP2Run1->total_arrears, 0.001, "C's arrears must be unaffected by A's new payment even though it was computed in a different chunk job.");
            $this->assertEqualsWithDelta(0.0, (float) $cP2Run1->credit, 0.001);
            $this->assertEqualsWithDelta(12000.0, (float) $cP2Run1->total_bill, 0.001);

            // Re-dispatch P2 a third time, no further changes anywhere — B
            // and C must be byte-identical to run 2 as well.
            $run3 = $this->batches->dispatch($period2, scheduledTask: null, autoPublish: true, customerIds: $customerIds);
            $commandRunIds[] = $run3->id;
            $this->assertSame('published', $run3->fresh()->status);

            $bP2Run2 = Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->firstOrFail();
            $cP2Run2 = Manuscript::query()->where('customer_id', $customerC->id)->where('period', $period2)->firstOrFail();

            $this->assertSame($bP2Run1->total_arrears, $bP2Run2->total_arrears);
            $this->assertSame($bP2Run1->credit, $bP2Run2->credit);
            $this->assertSame($bP2Run1->total_bill, $bP2Run2->total_bill);

            $this->assertSame($cP2Run1->total_arrears, $cP2Run2->total_arrears);
            $this->assertSame($cP2Run1->credit, $cP2Run2->credit);
            $this->assertSame($cP2Run1->total_bill, $cP2Run2->total_bill);

            $this->assertSame(
                1,
                Manuscript::query()->where('customer_id', $customerB->id)->where('period', $period2)->count()
            );
        } finally {
            $this->cleanUp($zone, [$customerA, $customerB, $customerC], $commandRunIds);
        }
    }

    public function test_the_manual_run_now_trigger_uses_the_batch_mechanism_and_auto_publishes_immediately(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = $this->activeCustomer($zone);
        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
        ]);

        $period = '2026-06';
        $commandRun = null;

        try {
            // autoPublish: true — App\Http\Controllers\ManuscriptController::calculate()
            // (the "Run Manuscript Calculation" button) calls dispatch()
            // exactly this way: no review gate, but via the same chunked
            // batch mechanism as the scheduled path (task-scheduler.md
            // section 4.1).
            $commandRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: true, customerIds: [$customer->id]);

            $this->assertSame('published', $commandRun->fresh()->status);

            $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->first();
            $this->assertNotNull($manuscript);
            $this->assertNotNull($payment->fresh()->processed_at);
        } finally {
            $this->cleanUp($zone, [$customer], $commandRun ? [$commandRun->id] : []);
        }
    }
}
