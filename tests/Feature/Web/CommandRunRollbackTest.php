<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\CommandRun;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TenantUserIndex;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\UsesDisposableTenant;
use Tests\TestCase;

/**
 * POST /settings/command-runs/{run}/rollback —
 * App\Http\Controllers\SettingsCommandRunController::rollback() (the
 * manuscript-run-management feature, task-scheduler.md's 2026-08-28
 * addendum: "delete it if not published, rollback... but we cannot delete
 * one which has passed").
 *
 * Uses Tests\Feature\Concerns\UsesDisposableTenant rather than
 * CommandRunCancelTest.php's InteractsWithTenantRoles/real-swecom-in-a-
 * transaction pattern: this test needs the freshly-added
 * `manuscripts.command_run_id` column, which only exists on a schema that
 * has actually run this feature's new migration
 * (2026_08_28_010000_add_command_run_id_to_manuscripts_table.php) — the real
 * `swecom` schema is deliberately never altered as part of building/testing
 * this feature (per the explicit constraint this feature was built under),
 * so a disposable, freshly-migrated tenant is the only schema in this test
 * run guaranteed to have that column.
 */
class CommandRunRollbackTest extends TestCase
{
    use UsesDisposableTenant;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionDisposableTenant('zrbk');
        // ResolveTenantWeb (App\Http\Middleware\ResolveTenantWeb) redirects
        // to the pending-workspace holding page unless the tenant is
        // approved — provisionDisposableTenant() doesn't set this (its other
        // callers never make an HTTP request, so it never mattered to them).
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

        // Drops the entire disposable schema — see UsesDisposableTenant's
        // class doc. That alone does not clean up the central `users` row or
        // its TenantUserIndex entry (dropping a schema doesn't run a DELETE
        // on the tenant_users row inside it, so TenantUser's deleted() sync
        // hook never fires) — mirrors LandlordTest::cleanupPendingWorkspace()'s
        // identical cleanup for the identical reason.
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

    private function commandRun(string $period, string $status): CommandRun
    {
        return CommandRun::create([
            'command' => 'manuscript:calculate',
            'period' => $period,
            'ran_at' => now(),
            'metadata' => ['tenant' => $this->tenant->id, 'trigger' => 'cli'],
            'status' => $status,
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

    public function test_an_admin_can_roll_back_a_published_run_against_the_current_period_and_only_its_own_rows_are_removed(): void
    {
        $this->actingAsRole('admin');

        $period = now()->format('Y-m');
        $zone = $this->zone();
        $customerA = $this->customer($zone);
        $customerB = $this->customer($zone);

        // Two DIFFERENT published runs against the SAME period — the exact
        // "do NOT delete by period alone" scenario the migration's doc
        // comment warns about. Rolling back $runA must leave $runB's row for
        // $customerB completely untouched.
        $runA = $this->commandRun($period, 'published');
        $manuscriptA = $this->manuscriptFor($customerA, $period, $runA);

        $runB = $this->commandRun($period, 'published');
        $manuscriptB = $this->manuscriptFor($customerB, $period, $runB);

        $response = $this->post("/settings/command-runs/{$runA->uuid}/rollback");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame('rolled_back', $runA->fresh()->status);
        $this->assertNotNull($runA->fresh()->metadata['rolled_back_by'] ?? null);
        $this->assertNotNull($runA->fresh()->metadata['rolled_back_at'] ?? null);

        $this->assertNull(Manuscript::query()->find($manuscriptA->id), "runA's own manuscript row must be deleted.");
        $this->assertNotNull(Manuscript::query()->find($manuscriptB->id), "runB's sibling manuscript row (same period, different run) must survive untouched.");
        $this->assertSame('published', $runB->fresh()->status, "runB's own status must be untouched by rolling back runA.");
    }

    public function test_rollback_of_a_pending_review_run_deletes_no_rows_since_none_were_published_yet_but_still_marks_rolled_back(): void
    {
        $this->actingAsRole('admin');

        $period = now()->format('Y-m');
        $run = $this->commandRun($period, 'pending_review');

        $response = $this->post("/settings/command-runs/{$run->uuid}/rollback");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame('rolled_back', $run->fresh()->status);
    }

    public function test_rollback_is_refused_for_a_run_against_a_past_period(): void
    {
        $this->actingAsRole('admin');

        $run = $this->commandRun('2020-01', 'published');
        $zone = $this->zone();
        $customer = $this->customer($zone);
        $manuscript = $this->manuscriptFor($customer, '2020-01', $run);

        $response = $this->post("/settings/command-runs/{$run->uuid}/rollback");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame('published', $run->fresh()->status, 'a locked (past-period) run must be left completely untouched by rollback().');
        $this->assertNotNull(Manuscript::query()->find($manuscript->id), "a locked run's manuscript rows must not be deleted.");
    }

    /**
     * Same lock rule, exercised specifically for a PAST period whose run was
     * never published (pending_review/failed) — the exact case
     * task-scheduler.md's addendum calls out by name: a stale/abandoned row
     * against a past period must not become deletable just because it never
     * got published. Locked is decided purely by period, never by status.
     */
    #[DataProvider('nonCurrentStatuses')]
    public function test_rollback_is_refused_for_any_status_against_a_past_period(string $status): void
    {
        $this->actingAsRole('admin');

        $run = $this->commandRun('2019-06', $status);

        $response = $this->post("/settings/command-runs/{$run->uuid}/rollback");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame($status, $run->fresh()->status);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function nonCurrentStatuses(): array
    {
        return [
            ['pending_review'],
            ['published'],
            ['failed'],
        ];
    }

    public function test_rollback_is_refused_for_a_still_queued_run_against_the_current_period(): void
    {
        $this->actingAsRole('admin');

        $run = $this->commandRun(now()->format('Y-m'), 'queued');

        $response = $this->post("/settings/command-runs/{$run->uuid}/rollback");
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame('queued', $run->fresh()->status, 'a queued run must be cancelled, not rolled back.');
    }

    public function test_a_manager_cannot_roll_back_a_run(): void
    {
        $this->actingAsRole('manager');

        $run = $this->commandRun(now()->format('Y-m'), 'published');

        $this->post("/settings/command-runs/{$run->uuid}/rollback")->assertForbidden();

        $this->assertSame('published', $run->fresh()->status);
    }

    public function test_an_agent_cannot_roll_back_a_run(): void
    {
        $this->actingAsRole('agent');

        $run = $this->commandRun(now()->format('Y-m'), 'published');

        $this->post("/settings/command-runs/{$run->uuid}/rollback")->assertForbidden();

        $this->assertSame('published', $run->fresh()->status);
    }
}
