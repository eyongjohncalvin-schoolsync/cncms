<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Models\Zone;
use App\Services\ManuscriptRerunGuard;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * POST /settings/command-runs/{run}/unpublish —
 * App\Http\Controllers\SettingsCommandRunController::unpublish() (the
 * manuscript-run-management feature, task-scheduler.md's 2026-08-28
 * addendum: "an Unpublish action for a published manuscript run — undo a
 * publish, fix an error, re-generate, without affecting any other month").
 *
 * Uses Tests\Feature\Concerns\UsesDisposableTenant for the same reason
 * CommandRunRollbackTest does: this needs the freshly-added
 * `manuscripts.command_run_id` column and to write real rows, and one test
 * runs the real `manuscript:calculate` artisan command end-to-end (which
 * re-initializes tenancy internally, so it cannot rely on the usual
 * transaction-rollback safety net).
 */
class CommandRunUnpublishTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zunp');
        $this->tenant->update(['registration_status' => 'approved']);

        $this->user = User::factory()->create();

        tenancy()->initialize($this->tenant);
        TenantUser::create(['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        TenantUserIndex::query()->where('user_id', $this->user->id)->where('tenant_id', $this->tenant->id)->delete();
        $this->user->delete();

        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        TenantUser::query()->where('user_id', $this->user->id)->where('tenant_id', $this->tenant->id)->update(['role' => $role]);

        $this->actingAs($this->user);
    }

    private function zone(): Zone
    {
        return ZoneFactory::new()->create();
    }

    private function customer(Zone $zone): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'others' => 0, 'status' => 'active']);
    }

    /**
     * @param  array<int, int>  $paymentIds
     * @param  array<int, int>  $adjustmentIds
     */
    private function publishedRun(string $period, Customer $customer, array $paymentIds = [], array $adjustmentIds = []): CommandRun
    {
        return CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => $this->tenant->id, 'trigger' => 'manual'],
            'status' => 'published',
            'published_at' => now(),
            'computed_result' => [
                'summary' => ['customers_processed' => 1],
                'customers' => [
                    (string) $customer->id => [
                        'processed_payment_ids' => $paymentIds,
                        'processed_adjustment_ids' => $adjustmentIds,
                    ],
                ],
            ],
        ]);
    }

    private function manuscriptFor(Customer $customer, string $period, CommandRun $run): Manuscript
    {
        return Manuscript::create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 0,
            'credit' => 0,
            'total_bill' => 2500,
            'period' => $period,
            'command_run_id' => $run->id,
        ]);
    }

    public function test_unpublish_removes_only_this_runs_rows_restores_stamps_and_is_audited(): void
    {
        $this->actingAsRole('admin');

        $period = now()->format('Y-m');
        $zone = $this->zone();
        $customerA = $this->customer($zone);
        $customerB = $this->customer($zone);

        $paymentA = PaymentFactory::new()->create([
            'customer_id' => $customerA->id,
            'verification_status' => 'verified',
            'processed_at' => now(),
            'processed_period' => $period,
        ]);
        $adjustmentA = ArrearsAdjustmentFactory::new()
            ->forPeriod($period)
            ->requestedBy($this->user->id)
            ->approved($this->user->id)
            ->create([
                'customer_id' => $customerA->id,
                'processed_at' => now(),
                'processed_period' => $period,
            ]);

        // Two DIFFERENT published runs against the SAME period — unpublishing
        // runA must leave runB's row for customerB entirely untouched (the
        // "never scope by period alone" guarantee).
        $runA = $this->publishedRun($period, $customerA, [$paymentA->id], [$adjustmentA->id]);
        $manuscriptA = $this->manuscriptFor($customerA, $period, $runA);

        $runB = $this->publishedRun($period, $customerB);
        $manuscriptB = $this->manuscriptFor($customerB, $period, $runB);

        $response = $this->post("/settings/command-runs/{$runA->uuid}/unpublish");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Run moved to a terminal, non-published, non-guard-tripping status.
        $this->assertSame('rolled_back', $runA->fresh()->status);
        $this->assertSame($this->user->id, $runA->fresh()->metadata['unpublished_by'] ?? null);
        $this->assertNotNull($runA->fresh()->metadata['unpublished_at'] ?? null);
        $this->assertSame(1, $runA->fresh()->metadata['unpublished_manuscripts_deleted'] ?? null);
        $this->assertSame(1, $runA->fresh()->metadata['unpublished_payments_restored'] ?? null);
        $this->assertSame(1, $runA->fresh()->metadata['unpublished_adjustments_restored'] ?? null);

        // Only runA's manuscript row was deleted.
        $this->assertNull(Manuscript::query()->find($manuscriptA->id), "runA's own manuscript row must be deleted.");
        $this->assertNotNull(Manuscript::query()->find($manuscriptB->id), "runB's sibling row (same period, different run) must survive.");
        $this->assertSame('published', $runB->fresh()->status);

        // Idempotency stamps restored to NULL so a fresh calculation reconsumes them.
        $this->assertNull($paymentA->fresh()->processed_period);
        $this->assertNull($paymentA->fresh()->processed_at);
        $this->assertNull($adjustmentA->fresh()->processed_period);
        $this->assertNull($adjustmentA->fresh()->processed_at);

        // Audited.
        $audit = AuditLog::query()
            ->where('table_name', 'command_runs')
            ->where('record_uuid', $runA->uuid)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'unpublish must write an audit_logs row for the command_runs record.');
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('unpublished', $audit->new_values['reason'] ?? null);
        $this->assertSame(1, $audit->new_values['manuscripts_deleted'] ?? null);
        $this->assertSame(1, $audit->new_values['payments_restored'] ?? null);
    }

    public function test_after_unpublish_a_fresh_manuscript_calculate_runs_without_force_and_reconsumes_the_payment(): void
    {
        $this->actingAsRole('admin');

        $period = now()->format('Y-m');
        $zone = $this->zone();
        $customer = $this->customer($zone);

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 2500,
            'verification_status' => 'verified',
            'processed_at' => now(),
            'processed_period' => $period,
        ]);

        $run = $this->publishedRun($period, $customer, [$payment->id]);
        $this->manuscriptFor($customer, $period, $run);

        $this->post("/settings/command-runs/{$run->uuid}/unpublish")->assertSessionHas('success');

        // No published run remains for the period, so the rerun guard allows
        // a plain re-run — this is exactly the "runs again without --force"
        // guarantee.
        tenancy()->initialize($this->tenant);
        app(ManuscriptRerunGuard::class)->assertRerunAllowed($period, false);
        $this->assertNull($payment->fresh()->processed_period);
        tenancy()->end();

        $this->artisan('manuscript:calculate', ['period' => $period, '--tenant' => $this->tenant->getTenantKey()])
            ->assertExitCode(0);

        tenancy()->initialize($this->tenant);

        $fresh = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->first();
        $this->assertNotNull($fresh, 'a fresh manuscript:calculate must regenerate the period.');

        // The freed payment was reconsumed by the fresh run.
        $this->assertSame($period, $payment->fresh()->processed_period);

        // A new, separate published run row now owns the period.
        $newRun = CommandRun::query()->where('period', $period)->where('status', 'published')->latest('id')->first();
        $this->assertNotNull($newRun);
        $this->assertNotSame($run->id, $newRun->id);
    }

    public function test_unpublish_is_refused_for_a_past_locked_period(): void
    {
        $this->actingAsRole('admin');

        $zone = $this->zone();
        $customer = $this->customer($zone);

        $payment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'verification_status' => 'verified',
            'processed_at' => now(),
            'processed_period' => '2020-01',
        ]);

        $run = $this->publishedRun('2020-01', $customer, [$payment->id]);
        $manuscript = $this->manuscriptFor($customer, '2020-01', $run);

        $response = $this->post("/settings/command-runs/{$run->uuid}/unpublish");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame('published', $run->fresh()->status, 'a locked (past-period) run must be left untouched.');
        $this->assertNotNull(Manuscript::query()->find($manuscript->id));
        $this->assertSame('2020-01', $payment->fresh()->processed_period, 'a locked run must not have its stamps restored.');
    }

    #[DataProvider('nonPublishedStatuses')]
    public function test_unpublish_is_refused_for_a_non_published_run(string $status): void
    {
        $this->actingAsRole('admin');

        $period = now()->format('Y-m');
        $run = CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => $this->tenant->id, 'trigger' => 'manual'],
            'status' => $status,
        ]);

        $response = $this->post("/settings/command-runs/{$run->uuid}/unpublish");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame($status, $run->fresh()->status);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function nonPublishedStatuses(): array
    {
        return [
            ['queued'],
            ['pending_review'],
            ['failed'],
            ['rolled_back'],
        ];
    }

    public function test_a_manager_cannot_unpublish_a_run(): void
    {
        $this->actingAsRole('manager');

        $zone = $this->zone();
        $customer = $this->customer($zone);
        $run = $this->publishedRun(now()->format('Y-m'), $customer);

        $this->post("/settings/command-runs/{$run->uuid}/unpublish")->assertForbidden();

        $this->assertSame('published', $run->fresh()->status);
    }

    public function test_an_agent_cannot_unpublish_a_run(): void
    {
        $this->actingAsRole('agent');

        $zone = $this->zone();
        $customer = $this->customer($zone);
        $run = $this->publishedRun(now()->format('Y-m'), $customer);

        $this->post("/settings/command-runs/{$run->uuid}/unpublish")->assertForbidden();

        $this->assertSame('published', $run->fresh()->status);
    }
}
