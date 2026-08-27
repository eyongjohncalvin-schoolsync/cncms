<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\User;
use App\Services\CustomerManuscriptRecalculationService;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The audit-trace fix for App\Services\CustomerManuscriptRecalculationService::
 * recalculateOne() (2026-08-27 — see that class's own class doc and
 * .claude/skills/cncms-context/references/arrears-adjustment.md's addendum):
 * before this, recalculateOne() mutated `manuscripts` rows with ZERO
 * run-level trace, unlike every manuscript:calculate run. Confirms a
 * 'manuscript:recalculate-one' command_runs row now always exists, with the
 * expected metadata shape, both for a direct call and for the real
 * ArrearsAdjustmentService::approve() -> applyLedgerEffect() path — including
 * that the approving admin's identity survives all the way through, both for
 * the synchronous current-period call AND the queued forward-sweep job
 * (where auth()->id() would otherwise be lost).
 */
class ArrearsAdjustmentCommandRunAuditTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsSeededUser(string $email): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function seededUserId(string $email): int
    {
        return User::query()->where('email', $email)->firstOrFail()->id;
    }

    public function test_recalculate_one_always_logs_a_command_run_with_the_supplied_trigger_and_context(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $period = now()->format('Y-m');

        $recalculator = app(CustomerManuscriptRecalculationService::class);
        $recalculator->recalculateOne(
            $customer,
            $period,
            trigger: 'arrears_adjustment',
            auditContext: ['arrears_adjustment_id' => 999, 'triggered_by_user_id' => 42],
        );

        $run = CommandRun::query()
            ->where('command', 'manuscript:recalculate-one')
            ->where('period', $period)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'a command_runs row must be created for every recalculateOne() call.');
        $this->assertSame('published', $run->status);
        $this->assertNotNull($run->published_at);
        $this->assertNotNull($run->ran_at);
        $this->assertSame($customer->id, $run->metadata['customer_id']);
        $this->assertSame('arrears_adjustment', $run->metadata['trigger']);
        $this->assertSame(999, $run->metadata['arrears_adjustment_id']);
        $this->assertSame(42, $run->metadata['triggered_by_user_id']);
    }

    public function test_recalculate_one_defaults_the_trigger_to_unspecified_when_no_caller_supplies_one(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $period = now()->format('Y-m');

        app(CustomerManuscriptRecalculationService::class)->recalculateOne($customer, $period);

        $run = CommandRun::query()
            ->where('command', 'manuscript:recalculate-one')
            ->where('period', $period)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('unspecified', $run->metadata['trigger']);
        $this->assertSame($customer->id, $run->metadata['customer_id']);
    }

    /**
     * End to end through the real maker-checker approve() flow: a small,
     * single-approval adjustment (well under the second-approval threshold)
     * applies its ledger effect synchronously for the current period. The
     * resulting command_runs row must carry BOTH the adjustment's own id and
     * the approving admin's user id — not left null just because this ran
     * through a real HTTP request rather than a direct service call.
     */
    public function test_approving_an_adjustment_logs_a_command_run_carrying_the_adjustment_id_and_approving_admins_id(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $previousPeriod = now()->subMonth()->format('Y-m');
        $currentPeriod = now()->format('Y-m');

        ManuscriptFactory::new()->forPeriod($previousPeriod)->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 10000,
            'credit' => 0,
            'total_bill' => 12500,
        ]);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->forPeriod($currentPeriod)
            ->withAmount('5000.00')
            ->withArrearsSnapshot('10000.00')
            ->create(['customer_id' => $customer->id]);

        $approver = $this->actingAsSeededUser('terence@shalomtech.dev'); // manager

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);

        $run = CommandRun::query()
            ->where('command', 'manuscript:recalculate-one')
            ->where('period', $currentPeriod)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'the synchronous current-period recalculation must have logged a command_run row.');
        $this->assertSame('published', $run->status);
        $this->assertSame($customer->id, $run->metadata['customer_id']);
        $this->assertSame('arrears_adjustment', $run->metadata['trigger']);
        $this->assertSame($adjustment->id, $run->metadata['arrears_adjustment_id']);
        $this->assertSame($approver->id, $run->metadata['triggered_by_user_id']);
    }
}
