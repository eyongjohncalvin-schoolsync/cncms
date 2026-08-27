<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Zone;
use App\Services\ManuscriptGenerationBatchService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The self-lockout fix (2026-08-27 security-review finding): before this,
 * nothing could ever clear a `command_runs` row genuinely stuck at
 * status='queued' — e.g. a crashed queue worker mid-batch, or a `kill -9`'d
 * manuscript:calculate CLI process. Left stuck, such a row permanently
 * blocks idx_command_runs_period_inflight (a partial unique index on
 * (command, period) WHERE status IN ('queued', 'pending_review') — see that
 * migration's doc comment) for that exact period forever.
 *
 * This reproduces exactly that: a manually-inserted, orphaned 'queued' row
 * for a period (standing in for the crashed-worker/killed-process scenario —
 * functionally identical to the index, which only cares about the row's
 * status, not what created it), confirms a real dispatch() for that same
 * period is rejected while it stands, then confirms cancelling it (the same
 * status='queued' -> 'failed' flip
 * App\Http\Controllers\SettingsCommandRunController::cancel() performs —
 * exercised directly here rather than via HTTP, since cancel()'s HTTP
 * authorization/only-queued behavior is already covered by
 * tests/Feature/Web/CommandRunCancelTest.php) immediately frees the index
 * for a subsequent dispatch() to succeed.
 *
 * Uses real, committed fixtures cleaned up in a `finally` block — same
 * reasoning as ManuscriptGenerationBatchServiceTest's own class doc: a
 * successful dispatch() here runs a real chunked Bus::batch(), whose
 * per-chunk/completion jobs cycle the `tenant` DB connection via Stancl's
 * QueueTenancyBootstrapper even under QUEUE_CONNECTION=sync, which would
 * silently roll back uncommitted fixtures sitting in an open outer
 * transaction.
 */
class CommandRunCancelUnblocksDispatchTest extends TestCase
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

    public function test_cancelling_a_stuck_queued_run_frees_the_period_for_a_real_dispatch(): void
    {
        tenancy()->initialize($this->tenant);

        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
        $period = '2033-05'; // far-future, outside any real seeded manuscript history

        $orphanedRun = null;

        try {
            // Simulates a crashed queue worker / kill -9'd CLI process:
            // inserted directly at 'queued', never updated to 'published'/
            // 'failed' by anything — exactly the state this fix targets.
            $orphanedRun = CommandRun::create([
                'command' => 'manuscript:calculate',
                'period' => $period,
                'ran_at' => now()->subHour(),
                'metadata' => ['tenant' => 'swecom', 'trigger' => 'cli'],
                'status' => 'queued',
            ]);

            // While it stands, idx_command_runs_period_inflight rejects a
            // real dispatch() for the same period — confirming the lock is
            // genuinely reproduced before testing its release.
            $blocked = null;

            try {
                $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);
            } catch (ValidationException $e) {
                $blocked = $e;
            }

            $this->assertNotNull($blocked, 'a dispatch() for the same period must be rejected while the orphaned run is still queued.');
            $this->assertArrayHasKey('period', $blocked->errors());
            $this->assertSame(
                1,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'the blocked dispatch() attempt must not have created a second row.'
            );

            // Cancel it — the exact status='queued' -> 'failed' flip
            // SettingsCommandRunController::cancel() performs.
            $orphanedRun->update([
                'status' => 'failed',
                'metadata' => [...$orphanedRun->metadata, 'cancelled_by' => null, 'cancelled_at' => now()->toIso8601String(), 'cancel_reason' => 'test'],
            ]);
            $this->assertSame('failed', $orphanedRun->fresh()->status);

            // The period must now be immediately free for a real dispatch()
            // to succeed — no separate "release" step needed, since the
            // partial index's WHERE clause only ever matched 'queued'/
            // 'pending_review' rows in the first place.
            $newRun = $this->batches->dispatch($period, scheduledTask: null, autoPublish: false, customerIds: [$customer->id]);
            $this->assertSame('pending_review', $newRun->status);
            $this->assertNotSame($orphanedRun->id, $newRun->id);

            $this->assertSame(
                2,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->count(),
                'both the cancelled orphan and the new run must now coexist (one failed, one pending_review).'
            );
        } finally {
            $ids = array_values(array_filter([
                $orphanedRun?->id,
                CommandRun::query()->where('command', 'manuscript:calculate')->where('period', $period)->where('status', 'pending_review')->value('id'),
            ]));
            $this->cleanUp($zone, [$customer], $ids);
        }
    }
}
