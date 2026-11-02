<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Complaint;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ComplaintService;
use Database\Factories\ComplaintFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The phone's complaint desk and technicians' "My jobs"
 * (docs/plans/mobile-manager-app.md, wave 3): the desk views of
 * GET /api/v1/complaints, what the person may do on one complaint, and
 * the technician marking a job started. Seeded swecom members: patience
 * (admin), terence (manager, a technician), divine (agent).
 */
class ComplaintDeskTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $patience;

    private User $terence;

    private User $divine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        TenantUser::query()->where('tenant_id', 'swecom')->update(['is_investor' => false, 'branch_id' => null]);

        $this->patience = $this->withRole('patience@shalomtech.dev', 'admin');
        $this->terence = $this->withRole('terence@shalomtech.dev', 'manager');
        $this->divine = $this->withRole('divine@shalomtech.dev', 'agent');
    }

    private function withRole(string $email, string $role): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        return $user;
    }

    private function complaint(array $attributes = []): Complaint
    {
        return ComplaintFactory::new()->submittedBy($this->divine->id)->create(['assigned_to' => null, ...$attributes]);
    }

    /**
     * @return list<string>
     */
    private function uuids(string $view): array
    {
        return collect($this->getJson("/api/v1/complaints?view={$view}&per_page=100")->assertOk()->json('data'))->pluck('uuid')->all();
    }

    public function test_the_desk_views_split_open_complaints_by_who_holds_them(): void
    {
        $unassigned = $this->complaint();
        $mine = $this->complaint(['assigned_to' => $this->terence->id]);
        $someoneElses = $this->complaint(['assigned_to' => $this->patience->id]);
        $resolved = ComplaintFactory::new()->submittedBy($this->divine->id)->resolved()->create(['assigned_to' => $this->terence->id]);

        Sanctum::actingAs($this->terence);

        $this->assertContains($unassigned->uuid, $this->uuids('unassigned'));
        $this->assertNotContains($mine->uuid, $this->uuids('unassigned'));

        $this->assertSame([$mine->uuid], array_values(array_intersect($this->uuids('mine'), [$unassigned->uuid, $mine->uuid, $someoneElses->uuid, $resolved->uuid])));

        $open = $this->uuids('open');
        $this->assertContains($someoneElses->uuid, $open);
        $this->assertNotContains($resolved->uuid, $open);
    }

    public function test_one_complaint_says_what_this_person_may_do_with_it(): void
    {
        $complaint = $this->complaint(['assigned_to' => $this->terence->id]);

        Sanctum::actingAs($this->terence);
        $this->getJson("/api/v1/complaints/{$complaint->uuid}")
            ->assertOk()
            ->assertJsonPath('data.assigned_to.uuid', $this->terence->uuid)
            ->assertJsonPath('can.start', true)
            ->assertJsonPath('can.resolve', true);

        // The agent who logged it can neither start nor resolve it — but may
        // send a technician (owner, 2026-11-02).
        Sanctum::actingAs($this->divine);
        $this->getJson("/api/v1/complaints/{$complaint->uuid}")
            ->assertOk()
            ->assertJsonPath('can.start', false)
            ->assertJsonPath('can.resolve', false)
            ->assertJsonPath('can.assign', true);
    }

    public function test_the_technician_marks_their_own_job_started_and_nobody_else_can(): void
    {
        $complaint = $this->complaint(['assigned_to' => $this->terence->id]);

        Sanctum::actingAs($this->patience);
        $this->postJson("/api/v1/complaints/{$complaint->uuid}/start")->assertForbidden();

        Sanctum::actingAs($this->terence);
        $this->postJson("/api/v1/complaints/{$complaint->uuid}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');

        // Started once: it can't be started again.
        $this->postJson("/api/v1/complaints/{$complaint->uuid}/start")->assertForbidden();
        $this->assertSame('in_progress', $complaint->fresh()->status);
    }

    public function test_a_started_job_still_shows_in_my_jobs_and_is_resolved_from_the_phone(): void
    {
        $complaint = $this->complaint(['assigned_to' => $this->terence->id, 'status' => 'in_progress']);

        Sanctum::actingAs($this->terence);
        $this->assertContains($complaint->uuid, $this->uuids('mine'));

        $this->postJson("/api/v1/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => ''])->assertJsonValidationErrors('resolution_notes');
        $this->postJson("/api/v1/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Replaced the splitter'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertNotContains($complaint->uuid, $this->uuids('mine'));
    }

    public function test_the_technician_alert_points_at_the_complaint_so_a_tap_opens_it(): void
    {
        $complaint = $this->complaint();

        app(ComplaintService::class)->assign($complaint, $this->terence->uuid);

        $alert = Notification::query()->where('type', 'complaint.assigned')->where('recipient_user_id', $this->terence->id)->firstOrFail();
        $this->assertSame('complaint', $alert->source_type);
        $this->assertSame($complaint->uuid, $alert->source_uuid);
    }
}
