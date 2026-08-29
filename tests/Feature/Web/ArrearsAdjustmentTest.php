<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\ArrearsAdjustment;
use App\Models\Manuscript;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ArrearsAdjustmentService;
use Database\Factories\ArrearsAdjustmentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The Arrears Adjustment maker-checker workflow: request (Customers/Show.tsx's
 * "Adjust Arrears" modal, POST /arrears-adjustments), approve/reject
 * (Audit/Index.tsx's "Arrears Adjustments" sub-tab), and — the one thing
 * that actually matters end to end — that an approved adjustment lands on
 * the customer's real manuscript arrears figure via a genuine
 * ManuscriptCalculator recalculation, never a direct write. See
 * tests/Feature/Web/ComplaintTest.php for the shared setup/role-switching
 * conventions this reuses.
 */
class ArrearsAdjustmentTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Logs in as one of the seeded, fixed-role demo users rather than the
     * role-switchable owner — needed whenever a test requires two or three
     * genuinely DISTINCT actors at once (requester vs. first approver vs.
     * second approver), which actingAsRole()'s single reused row can't
     * provide. Mirrors ComplaintTest::anotherUserId()'s reasoning for why
     * these must be already-committed seeded rows, not factory-created ones.
     */
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

    public function test_every_role_can_request_an_arrears_adjustment(): void
    {
        foreach (['super', 'admin', 'manager', 'agent', 'worker'] as $role) {
            $this->actingAsRole($role);
            $customer = CustomerFactory::new()->active()->create();

            $response = $this->post('/arrears-adjustments', [
                'customer_uuid' => $customer->uuid,
                'target_period' => now()->format('Y-m'),
                'direction' => 'decrease',
                'amount' => '1000.00',
                'reason_category' => 'billing_error',
                'reason_note' => "Requested by {$role}.",
            ]);

            $response->assertRedirect();
            $this->assertDatabaseHas('arrears_adjustments', [
                'customer_id' => $customer->id,
                'reason_note' => "Requested by {$role}.",
                'status' => 'pending',
            ]);
        }
    }

    public function test_target_period_cannot_be_in_the_future(): void
    {
        $this->actingAsRole('manager');
        $customer = CustomerFactory::new()->active()->create();

        $response = $this->post('/arrears-adjustments', [
            'customer_uuid' => $customer->uuid,
            'target_period' => now()->addMonth()->format('Y-m'),
            'direction' => 'decrease',
            'amount' => '1000.00',
            'reason_category' => 'billing_error',
            'reason_note' => 'Should not be allowed.',
        ]);

        $response->assertSessionHasErrors(['target_period']);
    }

    public function test_the_arrears_snapshot_is_captured_from_the_customers_latest_manuscript_at_request_time(): void
    {
        $this->actingAsRole('manager');
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500]);
        ManuscriptFactory::new()->forPeriod(now()->subMonth()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 8000,
            'credit' => 0,
            'total_bill' => 10500,
        ]);

        $this->post('/arrears-adjustments', [
            'customer_uuid' => $customer->uuid,
            'target_period' => now()->format('Y-m'),
            'direction' => 'decrease',
            'amount' => '1000.00',
            'reason_category' => 'billing_error',
            'reason_note' => 'Snapshot check.',
        ])->assertRedirect();

        $this->assertDatabaseHas('arrears_adjustments', [
            'customer_id' => $customer->id,
            'arrears_snapshot' => '8000.00',
        ]);
    }

    public function test_a_small_adjustment_is_approved_in_one_step_and_actually_reduces_the_customers_arrears(): void
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

        // Without the adjustment this period would compute to
        // 10000 + (2500 - 0) = 12500 total_arrears. A 5000 'decrease'
        // adjustment (well under the 20000 threshold, a non-legacy reason,
        // no prior approved adjustment) needs only ONE approval and should
        // leave the customer owing 7500.
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->forPeriod($currentPeriod)
            ->withAmount('5000.00')
            ->withArrearsSnapshot('10000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev'); // manager

        $response = $this->post("/arrears-adjustments/{$adjustment->uuid}/approve");
        $response->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->seededUserId('terence@shalomtech.dev'), $adjustment->approved_by);
        $this->assertNull($adjustment->second_approved_by);
        $this->assertSame($currentPeriod, $adjustment->processed_period);
        $this->assertNotNull($adjustment->processed_at);

        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $currentPeriod)
            ->firstOrFail();

        $this->assertSame('7500.00', (string) $manuscript->total_arrears);
    }

    public function test_a_large_adjustment_requires_a_second_approval_and_has_zero_ledger_effect_until_then(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $previousPeriod = now()->subMonth()->format('Y-m');
        $currentPeriod = now()->format('Y-m');

        ManuscriptFactory::new()->forPeriod($previousPeriod)->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 30000,
            'credit' => 0,
            'total_bill' => 32500,
        ]);

        // 25000 is over the 20000 default threshold — requires two approvals.
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->forPeriod($currentPeriod)
            ->withAmount('25000.00')
            ->withArrearsSnapshot('30000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev'); // manager — first approval
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('pending_second_approval', $adjustment->status);
        $this->assertSame($this->seededUserId('terence@shalomtech.dev'), $adjustment->approved_by);

        // Zero ledger effect yet — no manuscript row for the current period
        // has been created by this adjustment.
        $this->assertDatabaseMissing('manuscripts', ['customer_id' => $customer->id, 'period' => $currentPeriod]);

        $this->actingAsSeededUser('patience@shalomtech.dev'); // admin — second approval
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->seededUserId('patience@shalomtech.dev'), $adjustment->second_approved_by);

        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $currentPeriod)
            ->firstOrFail();

        // 30000 + (2500 - 0) - 25000 = 7500.
        $this->assertSame('7500.00', (string) $manuscript->total_arrears);
    }

    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev');

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => 'x'])->assertStatus(403);
    }

    /**
     * The super self-approval carve-out (ArrearsAdjustmentPolicy's class doc,
     * 2026-08-29): the owner may approve an adjustment they raised themselves,
     * and it reaches the ledger via the same real recalculation path as any
     * other approval — mirrors
     * test_a_small_adjustment_is_approved_in_one_step_...().
     */
    public function test_a_super_can_approve_an_adjustment_they_requested_themselves_and_it_reaches_the_ledger(): void
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

        $owner = $this->actingAsRole('super');

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($owner->id)
            ->forPeriod($currentPeriod)
            ->withAmount('5000.00')
            ->withArrearsSnapshot('10000.00')
            ->create(['customer_id' => $customer->id]);

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($owner->id, $adjustment->approved_by);
        $this->assertSame($currentPeriod, $adjustment->processed_period);
        $this->assertNotNull($adjustment->processed_at);

        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $currentPeriod)
            ->firstOrFail();

        // 10000 + (2500 - 0) - 5000 = 7500.
        $this->assertSame('7500.00', (string) $manuscript->total_arrears);
    }

    public function test_an_admin_still_cannot_approve_or_reject_their_own_request(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $admin = $this->actingAsRole('admin');

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($admin->id)
            ->create(['customer_id' => $customer->id]);

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => 'x'])->assertStatus(403);
    }

    public function test_a_manager_still_cannot_approve_their_own_request(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $manager = $this->actingAsRole('manager');

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($manager->id)
            ->create(['customer_id' => $customer->id]);

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
    }

    /**
     * The super carve-out is the ONLY change — the ordinary maker-checker
     * path is untouched: an unrelated admin still approves someone else's
     * request exactly as before.
     */
    public function test_an_unrelated_admin_can_still_approve_a_request_raised_by_a_super(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $currentPeriod = now()->format('Y-m');

        ManuscriptFactory::new()->forPeriod(now()->subMonth()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 10000,
            'credit' => 0,
            'total_bill' => 12500,
        ]);

        $requesterId = $this->seededUserId('divine@shalomtech.dev');
        TenantUser::query()->where('user_id', $requesterId)->update(['role' => 'super']);

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($requesterId)
            ->forPeriod($currentPeriod)
            ->withAmount('5000.00')
            ->withArrearsSnapshot('10000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('patience@shalomtech.dev'); // admin — neither requester nor a super
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->seededUserId('patience@shalomtech.dev'), $adjustment->approved_by);
    }

    /**
     * At the SECOND stage the carve-out still holds for super only: a super
     * who both raised the request and gave the first approval can still give
     * the second one. An admin first approver cannot (the identity rule is
     * unchanged for them).
     */
    public function test_at_the_second_stage_a_super_who_raised_and_first_approved_can_still_give_the_second_approval(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        $currentPeriod = now()->format('Y-m');

        ManuscriptFactory::new()->forPeriod(now()->subMonth()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'bill' => 2500,
            'total_arrears' => 30000,
            'credit' => 0,
            'total_bill' => 32500,
        ]);

        $owner = $this->actingAsRole('super');

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($owner->id)
            ->forPeriod($currentPeriod)
            ->withAmount('25000.00')
            ->withArrearsSnapshot('30000.00')
            ->pendingSecondApproval($owner->id)
            ->create(['customer_id' => $customer->id]);

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($owner->id, $adjustment->second_approved_by);

        $manuscript = Manuscript::query()
            ->where('customer_id', $customer->id)
            ->where('period', $currentPeriod)
            ->firstOrFail();

        // 30000 + (2500 - 0) - 25000 = 7500.
        $this->assertSame('7500.00', (string) $manuscript->total_arrears);
    }

    public function test_at_the_second_stage_an_admin_who_gave_the_first_approval_still_cannot_give_the_second(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $firstApproverId = $this->seededUserId('patience@shalomtech.dev'); // admin

        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->pendingSecondApproval($firstApproverId)
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('patience@shalomtech.dev');
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
    }

    public function test_agents_and_workers_cannot_approve_or_reject(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        $this->actingAsRole('agent');
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);

        $this->actingAsRole('worker');
        $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => 'x'])->assertStatus(403);
    }

    public function test_a_manager_cannot_give_the_second_approval(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->pendingSecondApproval($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        $this->actingAsRole('manager');
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
    }

    public function test_the_first_approver_cannot_also_give_the_second_approval(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->pendingSecondApproval($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        // terence gave the first approval — even though admin/super gate
        // would otherwise apply to a *different* admin, terence is not one
        // anyway; this specifically proves the self-block, so we simulate
        // terence briefly holding admin rights to isolate that one rule.
        TenantUser::query()->where('user_id', $adjustment->approved_by)->update(['role' => 'admin']);
        $this->actingAsSeededUser('terence@shalomtech.dev');

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
    }

    public function test_a_repeat_adjustment_within_90_days_requires_a_second_approval_even_below_threshold(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        ManuscriptFactory::new()->forPeriod(now()->subMonth()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'total_arrears' => 20000,
            'credit' => 0,
        ]);

        // A prior APPROVED adjustment for this same customer, approved 10
        // days ago — well within the 90-day repeat window.
        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->approved($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id, 'approved_at' => now()->subDays(10)]);

        // A new, small, non-legacy request — would normally go straight to
        // 'approved' in one step, but the 90-day repeat rule forces a
        // second approval anyway.
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->withAmount('500.00')
            ->withArrearsSnapshot('20000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev');
        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('pending_second_approval', $adjustment->status);
    }

    public function test_approval_is_blocked_when_the_customers_arrears_have_drifted_since_the_request(): void
    {
        $customer = CustomerFactory::new()->active()->create(['bill' => 2500, 'others' => 0]);
        ManuscriptFactory::new()->forPeriod(now()->subMonth()->format('Y-m'))->create([
            'customer_id' => $customer->id,
            'total_arrears' => 10000,
            'credit' => 0,
        ]);

        // Snapshot deliberately does not match the real current figure
        // (10000) — simulates the figure having drifted since the request
        // was submitted.
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->withArrearsSnapshot('4000.00')
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev');
        $response = $this->post("/arrears-adjustments/{$adjustment->uuid}/approve");

        $response->assertRedirect();
        $this->assertStringContainsString('has changed since', (string) session('error'));

        $adjustment->refresh();
        $this->assertSame('pending', $adjustment->status);
    }

    public function test_rejecting_requires_a_reason_and_has_zero_ledger_effect(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev');

        $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => ''])
            ->assertSessionHasErrors(['rejection_reason']);

        $response = $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => 'Insufficient evidence.']);
        $response->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('rejected', $adjustment->status);
        $this->assertSame('Insufficient evidence.', $adjustment->rejection_reason);
        $this->assertDatabaseMissing('manuscripts', ['customer_id' => $customer->id, 'period' => now()->format('Y-m')]);
    }

    /**
     * The Policy itself already refuses to approve/reject anything not
     * currently 'pending'/'pending_second_approval' (ArrearsAdjustmentPolicy
     * ::approve()'s `default => false` branch) — a terminal-status
     * adjustment reached via a normal HTTP request never even gets past
     * authorization, so this is a 403, not a redirect-with-error.
     */
    public function test_a_terminal_status_adjustment_is_refused_by_the_policy_before_it_reaches_the_service(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $adjustment = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->rejected()
            ->create(['customer_id' => $customer->id]);

        $this->actingAsSeededUser('terence@shalomtech.dev');

        $this->post("/arrears-adjustments/{$adjustment->uuid}/approve")->assertStatus(403);
        $this->post("/arrears-adjustments/{$adjustment->uuid}/reject", ['rejection_reason' => 'Too late.'])->assertStatus(403);
    }

    /**
     * The Service's OWN "already decided" guard (ArrearsAdjustmentService
     * ::approve()/reject()'s `if (! $adjustment->isPending())` check) is a
     * defensive re-check for a stale in-memory model — e.g. two concurrent
     * requests that both read the row as 'pending' before either commits.
     * That race isn't reproducible through a single synchronous HTTP
     * request (route-model-binding always hands the Policy and Service the
     * same freshly-fetched instance), so this exercises the Service
     * directly with two independently-loaded copies of the same row,
     * mirroring the Service's own doc comment: "ArrearsAdjustmentPolicy::
     * approve() has already checked the actor may act... this method" —
     * i.e. the Service trusts the Policy ran, and is tested here in
     * isolation from it, the same way the Policy was tested in isolation
     * above.
     */
    public function test_the_services_own_stale_read_guard_refuses_a_second_decision_on_the_same_row(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        $stored = ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        $actor = $this->actingAsSeededUser('terence@shalomtech.dev');

        // Two independent reads of the identical 'pending' row — simulating
        // two requests that raced past the Policy check before either
        // committed a decision.
        $firstRead = ArrearsAdjustment::query()->findOrFail($stored->id);
        $secondRead = ArrearsAdjustment::query()->findOrFail($stored->id);

        $service = app(ArrearsAdjustmentService::class);
        $service->approve($firstRead, $actor);

        $this->expectException(ValidationException::class);
        $service->approve($secondRead, $actor);
    }

    public function test_the_audit_log_arrears_adjustments_tab_lists_pending_and_decided_requests_with_stats(): void
    {
        // Runs against the real seeded tenant (InteractsWithTenantRoles), which
        // may already hold adjustments from prior owner activity — assert the
        // delta from this test's own two rows, not an absolute count.
        $before = min(ArrearsAdjustment::query()->count(), 25);

        $customer = CustomerFactory::new()->active()->create();
        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);
        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->approved($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id, 'approved_at' => now()]);

        $this->actingAsSeededUser('patience@shalomtech.dev'); // admin

        $this->get('/audit/logs?view=arrears_adjustments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audit/Index')
                ->where('view', 'arrears_adjustments')
                ->has('arrears_adjustments.stats')
                ->has('arrears_adjustments.adjustments.data', min($before + 2, 25)));
    }

    public function test_the_audit_tab_row_payload_carries_the_context_and_per_row_decision_flags_the_review_ui_needs(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id, 'reason_note' => 'Double-charged in the March migration.']);

        $this->actingAsSeededUser('patience@shalomtech.dev'); // admin — eligible first approver, not the requester

        $this->get('/audit/logs?view=arrears_adjustments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('arrears_adjustments.adjustments.data.0', fn (Assert $row) => $row
                    ->where('reason_note', 'Double-charged in the March migration.')
                    ->where('customer_uuid', $customer->uuid)
                    ->where('can_approve', true)
                    ->where('can_reject', true)
                    ->etc()));
    }

    public function test_a_pending_second_approval_row_surfaces_as_approvable_to_an_eligible_second_approver_in_the_review_payload(): void
    {
        $customer = CustomerFactory::new()->active()->create();
        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->pendingSecondApproval($this->seededUserId('terence@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        // patience (admin) is neither the requester nor the first approver, so
        // the review list must offer the (relabelled "Second approve") action
        // — driven purely by the server-resolved can_approve flag on a row
        // still at the pending_second_approval stage. The end-to-end second
        // approval itself is already covered by
        // test_a_large_adjustment_requires_a_second_approval_...().
        $this->actingAsSeededUser('patience@shalomtech.dev');
        $this->get('/audit/logs?view=arrears_adjustments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('arrears_adjustments.adjustments.data.0', fn (Assert $row) => $row
                    ->where('status', 'pending_second_approval')
                    ->where('can_approve', true)
                    ->where('can_reject', true)
                    ->etc()));
    }

    public function test_service_dashboard_counts_reflect_pending_and_applied_totals(): void
    {
        // Delta assertions — the real seeded tenant may already hold owner
        // adjustments (see the audit-tab test above).
        $service = app(ArrearsAdjustmentService::class);
        $before = $service->dashboard();

        $customer = CustomerFactory::new()->active()->create();

        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->create(['customer_id' => $customer->id]);

        ArrearsAdjustmentFactory::new()
            ->requestedBy($this->seededUserId('divine@shalomtech.dev'))
            ->approved($this->seededUserId('terence@shalomtech.dev'))
            ->withAmount('3000.00')
            ->create(['customer_id' => $customer->id, 'approved_at' => now()]);

        $after = $service->dashboard();

        $this->assertSame($before['pending_approval'] + 1, $after['pending_approval']);
        $this->assertSame($before['applied_this_month'] + 1, $after['applied_this_month']);
        $this->assertSame(bcadd((string) $before['total_written_off'], '3000.00', 2), (string) $after['total_written_off']);
    }
}
