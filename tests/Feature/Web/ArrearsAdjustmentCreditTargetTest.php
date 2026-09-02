<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * The `target = 'credit'` correction path and the imported-baseline
 * delta-vs-recalc branch (arrears-adjustment.md §14, 2026-08-30) — the
 * fallback for the 2026-08 `swecom` baseline-credit corruption: an approved
 * adjustment against a manuscript row with `command_run_id IS NULL` must NOT
 * trigger a from-scratch `ManuscriptCalculator` recompute (which re-reads
 * settled v1 payment history and fabricates a bogus credit); it must land as
 * a bounded, audited delta on that one row.
 *
 * Uses Tests\Feature\Concerns\UsesDisposableTenant (not
 * ArrearsAdjustmentTest.php's InteractsWithTenantRoles / real-swecom-in-a-
 * transaction pattern) for the exact reason CommandRunRollbackTest's class
 * doc gives: this needs the freshly-added `arrears_adjustments.target` /
 * `credit_snapshot` columns, which only exist on a schema that has actually
 * run this feature's migration — the real `swecom` schema is deliberately
 * never altered while building/testing this feature.
 */
class ArrearsAdjustmentCreditTargetTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    private User $approver;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zact');
        $this->tenant->update(['registration_status' => 'approved']);

        $this->approver = User::factory()->create();
        $this->requester = User::factory()->create();

        tenancy()->initialize($this->tenant);
        TenantUser::create(['user_id' => $this->approver->id, 'tenant_id' => $this->tenant->id, 'role' => 'manager']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        foreach ([$this->approver, $this->requester] as $user) {
            TenantUserIndex::query()->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->delete();
            $user->delete();
        }

        parent::tearDown();
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['bill' => 2500, 'others' => 0, 'status' => 'active']);
    }

    /**
     * Imported-baseline row: command_run_id IS NULL (ManuscriptFactory never
     * sets it).
     */
    private function baselineManuscript(Customer $customer, string $period, float $arrears, float $credit): Manuscript
    {
        return ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => $arrears,
            'credit' => $credit,
            'total_bill' => max(0, 2500 + $arrears - $credit),
        ]);
    }

    private function recalcOneRunCount(): int
    {
        return CommandRun::query()->where('command', 'manuscript:recalculate-one')->count();
    }

    public function test_a_credit_target_adjustment_against_a_baseline_row_reduces_only_the_manuscript_credit(): void
    {
        $period = now()->format('Y-m');
        $customer = $this->customer();
        $manuscript = $this->baselineManuscript($customer, $period, arrears: 0, credit: 5000);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->requester->id)
            ->forPeriod($period)
            ->creditTarget()          // direction 'increase' = claw back
            ->withAmount('3000.00')
            ->withCreditSnapshot('5000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAs($this->approver);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $manuscript->refresh();
        $this->assertSame('2000.00', (string) $manuscript->credit);       // 5000 - 3000
        $this->assertSame('0.00', (string) $manuscript->total_arrears);   // untouched
        $this->assertSame('2500.00', (string) $manuscript->bill);         // untouched
        $this->assertSame('500.00', (string) $manuscript->total_bill);    // max(0, 2500 + 0 - 2000)
        $this->assertNull($manuscript->command_run_id);                   // still a baseline row

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($period, $adjustment->processed_period);
        $this->assertNotNull($adjustment->processed_at);
    }

    public function test_a_credit_adjustment_against_an_imported_baseline_applies_as_a_direct_delta_and_does_not_recalculate(): void
    {
        $period = now()->format('Y-m');
        $customer = $this->customer();
        $this->baselineManuscript($customer, $period, arrears: 0, credit: 4000);

        // A verified, never-consumed payment: a from-scratch recompute of
        // this period would fold it in as fresh income (moving credit by far
        // more than the adjustment) and stamp it processed. Neither is allowed.
        $strayPayment = PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 9999,
            'verification_status' => 'verified',
        ]);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->requester->id)
            ->forPeriod($period)
            ->creditTarget()
            ->withAmount('4000.00')
            ->withCreditSnapshot('4000.00')
            ->create(['customer_id' => $customer->id]);

        $runsBefore = $this->recalcOneRunCount();

        $this->actingAs($this->approver);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();

        $this->assertSame('0.00', (string) $manuscript->credit);          // exact delta, clamped
        $this->assertSame($runsBefore, $this->recalcOneRunCount(), 'no manuscript:recalculate-one run should have been created');

        $strayPayment->refresh();
        $this->assertNull($strayPayment->processed_period, 'the stray payment must not have been consumed');
        $this->assertNull($strayPayment->processed_at);
    }

    public function test_an_arrears_adjustment_against_an_imported_baseline_also_applies_as_a_direct_delta(): void
    {
        // The literal 2026-08 incident shape (adjustment #414, MA TE −500).
        $period = now()->format('Y-m');
        $customer = $this->customer();
        $manuscript = $this->baselineManuscript($customer, $period, arrears: 3000, credit: 0);

        PaymentFactory::new()->create([
            'customer_id' => $customer->id,
            'amount' => 42000,   // the historical v1 payments that corrupted the real row
            'verification_status' => 'verified',
        ]);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->requester->id)
            ->forPeriod($period)
            ->withAmount('500.00')   // decrease (factory default)
            ->withArrearsSnapshot('3000.00')
            ->create(['customer_id' => $customer->id]);

        $runsBefore = $this->recalcOneRunCount();

        $this->actingAs($this->approver);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $manuscript->refresh();
        $this->assertSame('2500.00', (string) $manuscript->total_arrears);  // 3000 - 500, NOT a huge credit
        $this->assertSame('0.00', (string) $manuscript->credit);
        $this->assertSame('5000.00', (string) $manuscript->total_bill);     // 2500 + 2500 - 0
        $this->assertSame($runsBefore, $this->recalcOneRunCount());
    }

    public function test_the_credit_staleness_recheck_trips_when_credit_drifts_between_request_and_approval(): void
    {
        $period = now()->format('Y-m');
        $customer = $this->customer();
        $this->baselineManuscript($customer, $period, arrears: 0, credit: 5000);   // real current figure

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->requester->id)
            ->forPeriod($period)
            ->creditTarget()
            ->withAmount('2000.00')
            ->withCreditSnapshot('1000.00')   // deliberately stale
            ->create(['customer_id' => $customer->id]);

        $this->actingAs($this->approver);
        $response = $this->post("/arrears-adjustments/{$adjustment->uuid}/approve");

        $response->assertRedirect();
        $this->assertStringContainsString('credit figure', (string) session('error'));

        $adjustment->refresh();
        $this->assertSame('pending', $adjustment->status);

        $manuscript = Manuscript::query()->where('customer_id', $customer->id)->where('period', $period)->firstOrFail();
        $this->assertSame('5000.00', (string) $manuscript->credit);   // untouched
    }

    public function test_clear_credit_produces_a_request_whose_amount_equals_the_current_credit(): void
    {
        // "Clear credit" is a pure UI pre-fill (ArrearsAdjustmentModal) — this
        // asserts the request it produces once submitted through the real
        // endpoint: target=credit, direction=increase, amount == current
        // credit, and credit_snapshot captured fresh server-side.
        $period = now()->format('Y-m');
        $customer = $this->customer();
        $this->baselineManuscript($customer, $period, arrears: 0, credit: 7250);

        $this->actingAs($this->approver);
        $this->post('/arrears-adjustments', [
            'customer_uuid' => $customer->uuid,
            'target_period' => $period,
            'target' => 'credit',
            'direction' => 'increase',
            'amount' => '7250.00',
            'reason_category' => 'credit_correction',
            'reason_note' => 'Clearing a bogus credit balance.',
        ])->assertRedirect();

        $this->assertDatabaseHas('arrears_adjustments', [
            'customer_id' => $customer->id,
            'target' => 'credit',
            'direction' => 'increase',
            'amount' => '7250.00',
            'credit_snapshot' => '7250.00',
            'status' => 'pending',
        ]);
    }

    public function test_a_credit_adjustment_in_a_live_current_period_row_still_uses_the_recalc_path(): void
    {
        // A row written by a real manuscript:calculate run (command_run_id set)
        // in the CURRENT (unlocked) period is NOT a baseline — the original
        // recalc path still applies, creating a manuscript:recalculate-one run.
        $period = now()->format('Y-m');
        $customer = $this->customer();

        $run = CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'status' => 'published',
            'published_at' => now(),
            'metadata' => ['tenant' => $this->tenant->id, 'trigger' => 'cli'],
        ]);
        Manuscript::create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 1000,
            'credit' => 0,
            'total_bill' => 3500,
            'period' => $period,
            'command_run_id' => $run->id,
        ]);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->requester->id)
            ->forPeriod($period)
            ->withAmount('500.00')
            ->withArrearsSnapshot('1000.00')
            ->create(['customer_id' => $customer->id]);

        $runsBefore = $this->recalcOneRunCount();

        $this->actingAs($this->approver);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        // The recalc path ran (synchronous current-period call, plus the
        // forward job's own sweep if the queue is sync) — at least one new
        // manuscript:recalculate-one run, unlike the baseline delta path
        // which creates none.
        $this->assertGreaterThan($runsBefore, $this->recalcOneRunCount(), 'the recalc path should have run for a live current-period row');

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
    }
}
