<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\DataTransferObjects\ResolveComplaintData;
use App\Models\ComplaintEscalation;
use App\Models\Notification;
use App\Models\ScheduledTask;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ComplaintService;
use App\Support\ScheduledTasks\ComplaintEscalationCheckTaskType;
use Database\Factories\ComplaintFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The complaint escalation engine (references/complaint-desk.md section 3):
 * the 4-level threshold table, the Level 3 human gate, idempotency, and the
 * resolution/de-escalation notice. Same real "swecom" tenant / transaction /
 * role-switching conventions as tests/Feature/Web/ComplaintTest.php and
 * NotificationTest.php.
 */
class ComplaintEscalationTest extends TestCase
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

    private function anotherUserId(): int
    {
        return User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail()->id;
    }

    /**
     * The seeded, system-owned scheduled_tasks row (2026_08_25_210010_seed_
     * complaint_escalation_check_scheduled_task migration) — real, already
     * migrated into the swecom tenant schema this test runs against.
     */
    private function scheduledTask(): ScheduledTask
    {
        return ScheduledTask::query()->where('task_type', 'complaint_escalation_check')->firstOrFail();
    }

    private function runSweep(): void
    {
        app(ComplaintEscalationCheckTaskType::class)->run($this->scheduledTask());
    }

    public function test_level_0_fires_once_on_assignment_and_is_idempotent(): void
    {
        $manager = $this->actingAsRole('manager');
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'assigned_to' => $manager->id,
            'created_at' => now(),
        ]);

        $this->runSweep();
        $this->runSweep();

        $this->assertSame(2, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 0)->count());
        $this->assertSame(1, Notification::query()->where('type', 'complaint.assigned')->where('broadcast_scope', 'user')->count());
        $this->assertSame(1, Notification::query()->where('type', 'complaint.assigned')->where('broadcast_scope', 'role')->where('recipient_role', 'manager')->count());

        // Level 0 must never touch escalated_at — see
        // App\Services\ComplaintEscalationService::sweep()'s doc comment.
        $this->assertNull($complaint->fresh()->escalated_at);
    }

    public function test_level_1_and_level_2_escalations_fire_exactly_once_and_are_idempotent_across_ticks(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(30),
        ]);

        $this->runSweep();
        // A second tick within the same window must not re-notify.
        $this->runSweep();

        $this->assertSame(3, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 1)->count());
        $this->assertSame(3, Notification::query()->where('type', 'complaint.escalated_level_1')->count());
        $this->assertSame(['super', 'admin', 'manager'], ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 1)->orderBy('id')->pluck('notified_role')->all());

        // Not yet 48h old — Level 2 must not have fired.
        $this->assertSame(0, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 2)->count());
        $this->assertNull($complaint->fresh()->escalated_at);

        // Age it past 48h and sweep again — Level 2 fires exactly once,
        // Level 1 stays untouched (already logged).
        $complaint->forceFill(['created_at' => now()->subHours(50)])->save();
        $this->runSweep();
        $this->runSweep();

        $this->assertSame(3, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 1)->count());
        $this->assertSame(1, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 2)->count());
        $this->assertSame(1, Notification::query()->where('type', 'complaint.escalated_level_2')->where('broadcast_scope', 'all')->count());
        $this->assertNotNull($complaint->fresh()->escalated_at);
    }

    public function test_level_3_investor_notice_does_not_auto_fire_at_48_hours(): void
    {
        ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(72),
        ]);

        $this->runSweep();

        $this->assertSame(0, ComplaintEscalation::query()->where('level', 3)->count());
        $this->assertSame(0, Notification::query()->where('type', 'complaint.investor_notice')->count());
        $this->assertSame(0, Notification::query()->where('recipient_role', 'investor')->count());
    }

    public function test_notify_investors_button_is_gated_to_super_and_admin_only(): void
    {
        foreach (['manager', 'agent', 'worker'] as $role) {
            $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
                'created_at' => now()->subHours(60),
            ]);

            $this->actingAsRole($role);
            $this->post("/complaints/{$complaint->uuid}/notify-investors")->assertStatus(403);

            $this->assertSame(0, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 3)->count());
        }

        foreach (['admin', 'super'] as $role) {
            $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
                'created_at' => now()->subHours(60),
            ]);

            $this->actingAsRole($role);
            $this->post("/complaints/{$complaint->uuid}/notify-investors")->assertRedirect();

            $this->assertSame(1, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 3)->where('notified_role', 'investor')->count());
        }
    }

    public function test_notify_investors_requires_the_complaint_to_be_armed_at_48_hours(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now(),
        ]);

        $this->actingAsRole('super');
        $this->post("/complaints/{$complaint->uuid}/notify-investors")->assertSessionHasErrors(['complaint']);

        $this->assertSame(0, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 3)->count());
    }

    /**
     * A second click after investors are already notified must not re-send
     * — same idempotency contract as the automatic levels.
     */
    public function test_notify_investors_is_idempotent(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(60),
        ]);

        $this->actingAsRole('super');
        $this->post("/complaints/{$complaint->uuid}/notify-investors")->assertRedirect();
        $this->post("/complaints/{$complaint->uuid}/notify-investors")->assertRedirect();

        $this->assertSame(1, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 3)->count());
        $this->assertSame(1, Notification::query()->where('type', 'complaint.investor_notice')->count());
    }

    public function test_resolving_before_any_escalation_sends_no_deescalation_notice(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now(),
        ]);

        $before = Notification::query()->count();

        $this->actingAsRole('manager');
        $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Fixed immediately.'])
            ->assertRedirect();

        // No complaint_escalations rows ever existed for this complaint, so
        // resolve() must send nothing extra — "never to people who were
        // never escalated to".
        $this->assertSame($before, Notification::query()->count());
        $this->assertSame(0, Notification::query()->where('type', 'complaint.resolved')->count());
    }

    public function test_resolving_after_level_2_notifies_the_full_staff_list(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(50),
        ]);

        $this->runSweep();
        $this->assertSame(1, ComplaintEscalation::query()->where('complaint_id', $complaint->id)->where('level', 2)->count());

        $this->actingAsRole('manager');
        $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Resolved after emergency escalation.'])
            ->assertRedirect();

        $resolvedNotice = Notification::query()->where('type', 'complaint.resolved')->where('broadcast_scope', 'all')->first();
        $this->assertNotNull($resolvedNotice, 'Expected one broadcast_scope=all "complaint.resolved" notice reaching the full staff list.');
    }

    /**
     * Direct service-level counterpart to
     * ComplaintTest::test_a_manager_can_link_a_duplicate_and_it_is_excluded_from_the_escalation_sweep(),
     * which only asserts the repository query's exclusion. This exercises
     * the actual runtime path: a linked duplicate old enough to have crossed
     * every threshold must still receive zero escalations/notifications when
     * the real scheduler task type runs, while its original (same age) is
     * escalated normally.
     */
    public function test_a_linked_duplicate_is_genuinely_excluded_from_the_scheduler_sweep(): void
    {
        $original = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(50),
        ]);
        $duplicate = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now()->subHours(50),
            'duplicate_of_id' => $original->id,
        ]);

        $this->runSweep();

        $this->assertSame(0, ComplaintEscalation::query()->where('complaint_id', $duplicate->id)->count());
        $this->assertGreaterThan(0, ComplaintEscalation::query()->where('complaint_id', $original->id)->count());
    }

    public function test_service_resolve_still_marks_the_complaint_resolved_when_escalation_notice_has_nothing_to_send(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'created_at' => now(),
        ]);

        $resolved = app(ComplaintService::class)->resolve(
            $complaint->fresh(),
            ResolveComplaintData::fromArray(['resolution_notes' => 'Resolved via service test.']),
            $this->anotherUserId(),
        );

        $this->assertSame('resolved', $resolved->status);
    }
}
