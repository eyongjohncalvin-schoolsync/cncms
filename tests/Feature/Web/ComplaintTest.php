<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use App\Services\ComplaintService;
use Database\Factories\ComplaintFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Web (session-auth, Inertia) counterpart of the Complaint Desk core build
 * (references/complaint-desk.md sections 1-2, 4, 6 — escalation/broadcast,
 * sections 3/5, are a separate later build). See
 * tests/Feature/Web/PaymentTest.php / ResourceTest.php for the shared
 * setup/role-switching conventions this reuses.
 */
class ComplaintTest extends TestCase
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
     * complaints.submitted_by/assigned_to/resolved_by are cross-schema FKs
     * into the central public.users table — same reasoning as
     * ResourceTest::seededUserId() for expenditures.user_id.
     */
    private function seededUserId(): int
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail()->id;
    }

    /**
     * A second, already-committed central user distinct from the seeded
     * "acting" owner (kelvin@shalomtech.dev) — needed to construct
     * complaints genuinely submitted by "someone else" for the
     * resolve()/reopen() self-block tests below. Must be an already-
     * committed row for the same cross-connection-visibility reason as
     * seededUserId() (see InteractsWithTenantRoles's class doc): this real
     * tenant schema's seed data includes several real staff users besides
     * the owner, so no factory-created row is needed.
     */
    private function anotherUserId(): int
    {
        return User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail()->id;
    }

    public function test_every_role_can_create_a_complaint(): void
    {
        foreach (['super', 'admin', 'manager', 'agent', 'worker'] as $role) {
            $this->actingAsRole($role);

            $response = $this->post('/complaints', [
                'category' => 'operational',
                'title' => "Issue reported by {$role}",
                'description' => 'Something is not working right.',
            ]);

            $response->assertRedirect('/complaints');
            $this->assertDatabaseHas('complaints', ['title' => "Issue reported by {$role}"]);
        }
    }

    public function test_customer_category_requires_a_customer_and_operational_forbids_one(): void
    {
        $this->actingAsRole('agent');

        // 'customer' category without customer_uuid — required_if fails.
        $response = $this->post('/complaints', [
            'category' => 'customer',
            'title' => 'Customer complaint missing customer',
            'description' => 'A customer complained.',
        ]);
        $response->assertSessionHasErrors(['customer_uuid']);

        // 'operational' category WITH a customer_uuid — prohibited_if fails.
        $customer = CustomerFactory::new()->create();
        $response = $this->post('/complaints', [
            'category' => 'operational',
            'title' => 'Operational complaint with a customer attached',
            'description' => 'This should not be allowed.',
            'customer_uuid' => $customer->uuid,
        ]);
        $response->assertSessionHasErrors(['customer_uuid']);

        // 'customer' category with a valid customer_uuid succeeds, and the
        // customer's own zone is silently derived onto the complaint.
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $response = $this->post('/complaints', [
            'category' => 'customer',
            'title' => 'Valid customer complaint',
            'description' => 'On behalf of a customer.',
            'customer_uuid' => $customer->uuid,
        ]);
        $response->assertRedirect('/complaints');
        $this->assertDatabaseHas('complaints', [
            'title' => 'Valid customer complaint',
            'customer_id' => $customer->id,
            'zone_id' => $zone->id,
        ]);
    }

    public function test_only_super_admin_manager_can_resolve_and_resolving_without_notes_fails(): void
    {
        // Submitted by someone other than the seeded actor, so neither
        // check below is confused with the separate self-resolve rule
        // tested by test_submitter_cannot_resolve_their_own_complaint().
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();

        $this->actingAsRole('agent');
        $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Fixed it.'])
            ->assertStatus(403);

        $this->actingAsRole('worker');
        $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Fixed it.'])
            ->assertStatus(403);

        $this->actingAsRole('manager');
        $response = $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => '']);
        $response->assertSessionHasErrors(['resolution_notes']);
        $this->assertDatabaseHas('complaints', ['id' => $complaint->id, 'status' => 'open']);
    }

    public function test_submitter_cannot_resolve_their_own_complaint(): void
    {
        $manager = $this->actingAsRole('manager');
        $complaint = ComplaintFactory::new()->submittedBy($manager->id)->create();

        $response = $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Self-resolved.']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('complaints', ['id' => $complaint->id, 'status' => 'open']);
    }

    public function test_a_manager_can_resolve_someone_elses_complaint_with_notes(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();

        $this->actingAsRole('manager');
        $response = $this->post("/complaints/{$complaint->uuid}/resolve", ['resolution_notes' => 'Investigated and fixed.']);

        $response->assertRedirect();
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => 'resolved',
            'resolution_notes' => 'Investigated and fixed.',
            'resolved_by' => $this->seededUserId(),
        ]);
    }

    public function test_a_manager_can_link_a_duplicate_and_it_is_excluded_from_the_escalation_sweep(): void
    {
        $zone = ZoneFactory::new()->create();
        $original = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'category' => 'operational',
            'zone_id' => $zone->id,
        ]);
        $duplicate = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'category' => 'operational',
            'zone_id' => $zone->id,
        ]);

        /** @var ComplaintRepositoryInterface $repository */
        $repository = app(ComplaintRepositoryInterface::class);

        // Before linking, both are open and neither is linked — both
        // appear in the escalation-eligible sweep.
        $before = $repository->openForEscalationSweep()->pluck('id');
        $this->assertTrue($before->contains($duplicate->id));

        $this->actingAsRole('manager');
        $response = $this->post("/complaints/{$duplicate->uuid}/link-duplicate", ['duplicate_of_uuid' => $original->uuid]);
        $response->assertRedirect();

        $this->assertDatabaseHas('complaints', ['id' => $duplicate->id, 'duplicate_of_id' => $original->id]);

        // After linking, the duplicate is excluded from the sweep — it
        // rides on the original's clock instead (references/complaint-desk.md
        // section 4.2).
        $after = $repository->openForEscalationSweep()->pluck('id');
        $this->assertFalse($after->contains($duplicate->id));
        $this->assertTrue($after->contains($original->id));
    }

    public function test_an_agent_cannot_link_a_duplicate(): void
    {
        $original = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();
        $duplicate = ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();

        $this->actingAsRole('agent');
        $response = $this->post("/complaints/{$duplicate->uuid}/link-duplicate", ['duplicate_of_uuid' => $original->uuid]);

        $response->assertStatus(403);
    }

    public function test_the_duplicate_check_surfaces_existing_matches_without_blocking_submission(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);

        // An existing open 'customer' complaint for this exact customer,
        // opened within the last 7 days.
        ComplaintFactory::new()->submittedBy($this->anotherUserId())->create([
            'category' => 'customer',
            'customer_id' => $customer->id,
            'zone_id' => $zone->id,
            'title' => 'Existing billing complaint',
        ]);

        $this->actingAsRole('agent');

        // The soft, non-blocking check itself (references/complaint-desk.md
        // section 4.1) — surfaces the existing candidate for the same
        // customer.
        $duplicatesResponse = $this->getJson("/complaints/duplicates?category=customer&customer_uuid={$customer->uuid}");
        $duplicatesResponse->assertOk();
        $titles = collect($duplicatesResponse->json('complaints'))->pluck('title');
        $this->assertTrue($titles->contains('Existing billing complaint'));

        // ...and a second complaint for the same customer can still be
        // filed regardless — never a hard block.
        $response = $this->post('/complaints', [
            'category' => 'customer',
            'title' => 'A new, possibly-duplicate complaint',
            'description' => 'Filing anyway.',
            'customer_uuid' => $customer->uuid,
        ]);
        $response->assertRedirect('/complaints');
        $this->assertDatabaseHas('complaints', ['title' => 'A new, possibly-duplicate complaint']);
    }

    /**
     * Deliberately two separate test methods rather than one that switches
     * role and re-requests '/complaints' twice: App\Http\Routing\Route
     * memoizes its resolved controller instance on the Route object itself
     * (Illuminate\Routing\Route::getController()), which persists across
     * multiple simulated requests within a single test method — so a
     * constructor-injected TenantContext (ComplaintController's `$context`)
     * would go stale on the second call even though the container's
     * TenantContext binding itself is correctly refreshed per request by
     * ResolveTenantWeb. This is a test-harness-only artifact (a real request
     * — outside an Octane-style persistent worker — always gets a fresh
     * Route/controller instance); splitting into two independent test
     * methods (each of which gets a fresh application/router per PHPUnit
     * test method) avoids it without weakening what's actually verified.
     */
    public function test_index_renders_the_dashboard_view_for_a_manager(): void
    {
        ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();

        $this->actingAsRole('manager');
        $this->get('/complaints')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Complaints/Index')
            ->where('view', 'dashboard')
            ->has('stats'));
    }

    public function test_index_renders_the_submission_view_for_an_agent(): void
    {
        ComplaintFactory::new()->submittedBy($this->anotherUserId())->create();

        $this->actingAsRole('agent');
        $this->get('/complaints')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Complaints/Index')
            ->where('view', 'submission')
            ->where('stats', null));
    }

    public function test_a_worker_cannot_reopen_a_resolved_complaint(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->resolved()->create();

        $this->actingAsRole('worker');
        $response = $this->post("/complaints/{$complaint->uuid}/reopen");

        $response->assertStatus(403);
    }

    public function test_reopening_does_not_reset_the_escalation_clock(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->resolved()->create([
            'created_at' => now()->subDays(3),
        ]);
        $originalCreatedAt = $complaint->created_at;

        $this->actingAsRole('manager');
        $response = $this->post("/complaints/{$complaint->uuid}/reopen");
        $response->assertRedirect();

        $reopened = $complaint->fresh();
        $this->assertTrue($reopened->created_at->equalTo($originalCreatedAt));
        $this->assertSame('open', $reopened->status);
        $this->assertNull($reopened->resolution_notes);
        $this->assertNull($reopened->resolved_at);
    }

    public function test_service_reopen_clears_resolution_fields_without_touching_created_at(): void
    {
        $complaint = ComplaintFactory::new()->submittedBy($this->anotherUserId())->resolved()->create([
            'resolved_by' => $this->seededUserId(),
            'created_at' => now()->subDays(5),
        ]);

        $reopened = app(ComplaintService::class)->reopen($complaint->fresh());

        $this->assertSame('open', $reopened->status);
        $this->assertNull($reopened->resolved_at);
        $this->assertNull($reopened->resolved_by);
        $this->assertNull($reopened->resolution_notes);
        $this->assertTrue($reopened->created_at->equalTo($complaint->created_at));
    }
}
