<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\LandlordActivity;
use App\Models\MaintenanceWindow;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Landlord impersonation (App\Support\Impersonation): landlords only, full
 * access, and every trail names the landlord — the landlord activity log
 * and the workspace's own audit_logs.impersonated_by.
 *
 * kelvin is the landlord; patience (made an admin here) is impersonated.
 */
class ImpersonationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $landlord;

    private User $patience;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->landlord->forceFill(['is_landlord' => true])->save();

        $this->patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $this->patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();
        TenantUser::query()->where('user_id', $this->patience->id)->update(['role' => 'admin', 'branch_id' => null]);
    }

    private function startImpersonating(): void
    {
        $this->actingAs($this->landlord)
            ->post("/landlord/impersonate/{$this->patience->uuid}")
            ->assertRedirect('/dashboard');
    }

    public function test_a_landlord_logs_in_as_a_workspace_user_and_sees_the_banner(): void
    {
        $this->startImpersonating();

        $this->assertAuthenticatedAs($this->patience);
        $this->assertSame($this->landlord->id, session(Impersonation::SESSION_KEY));

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.email', 'patience@shalomtech.dev')
                ->where('impersonation.user', $this->patience->name)
                ->where('impersonation.impersonator', $this->landlord->name));

        $started = LandlordActivity::query()->where('action', 'impersonation.started')->sole();
        $this->assertSame($this->landlord->id, $started->user_id);
        $this->assertSame((string) $this->patience->id, $started->subject_id);
        $this->assertSame('swecom', $started->details['tenant_id']);
    }

    public function test_writes_are_logged_for_the_landlord_and_marked_in_the_workspace_audit(): void
    {
        $this->startImpersonating();

        $this->post('/zones', ['name' => 'IMP-TEST'])->assertRedirect();

        $zone = Zone::query()->where('name', 'IMP-TEST')->sole();
        $audit = AuditLog::query()->where('table_name', 'zones')->where('record_uuid', $zone->uuid)->sole();
        $this->assertSame($this->patience->id, $audit->user_id);
        $this->assertSame($this->landlord->id, $audit->impersonated_by);

        $action = LandlordActivity::query()->where('action', 'impersonation.action')->sole();
        $this->assertSame($this->landlord->id, $action->user_id);
        $this->assertSame('POST', $action->details['method']);
        $this->assertSame('/zones', $action->details['path']);

        $this->get('/audit/logs')->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.impersonated_by', $this->landlord->name));
    }

    public function test_ordinary_changes_are_not_marked_as_impersonated(): void
    {
        $this->actingAs($this->patience)->post('/zones', ['name' => 'OWN-TEST'])->assertRedirect();

        $zone = Zone::query()->where('name', 'OWN-TEST')->sole();
        $this->assertNull(AuditLog::query()->where('record_uuid', $zone->uuid)->sole()->impersonated_by);
        $this->assertFalse(LandlordActivity::query()->where('action', 'impersonation.action')->exists());
    }

    public function test_stopping_returns_to_the_landlord(): void
    {
        $this->startImpersonating();

        $this->post('/impersonation/stop')->assertRedirect('/landlord/tenants');

        $this->assertAuthenticatedAs($this->landlord);
        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->assertSame($this->landlord->id, LandlordActivity::query()->where('action', 'impersonation.stopped')->sole()->user_id);
        $this->get('/landlord')->assertOk();
    }

    public function test_log_out_while_impersonating_returns_to_the_landlord_instead(): void
    {
        $this->startImpersonating();

        $this->post('/logout')->assertRedirect('/landlord/tenants');

        $this->assertAuthenticatedAs($this->landlord);
    }

    public function test_an_impersonated_session_has_no_landlord_powers(): void
    {
        $this->startImpersonating();

        $this->get('/landlord')->assertForbidden();
        $this->post("/landlord/impersonate/{$this->patience->uuid}")->assertForbidden();
    }

    public function test_landlords_inactive_users_and_non_members_cannot_be_impersonated(): void
    {
        $this->actingAs($this->landlord);

        $this->post("/landlord/impersonate/{$this->landlord->uuid}")->assertSessionHas('error');

        $otherLandlord = $this->patience;
        $otherLandlord->forceFill(['is_landlord' => true])->save();
        $this->post("/landlord/impersonate/{$otherLandlord->uuid}")->assertSessionHas('error', 'Landlords cannot be impersonated.');
        $otherLandlord->forceFill(['is_landlord' => false, 'status' => 'passive'])->save();
        $this->post("/landlord/impersonate/{$otherLandlord->uuid}")->assertSessionHas('error');

        $outsider = User::factory()->create();
        $this->post("/landlord/impersonate/{$outsider->uuid}")->assertSessionHas('error', "{$outsider->name} does not belong to any workspace.");

        $this->assertAuthenticatedAs($this->landlord);
        $this->assertFalse(LandlordActivity::query()->where('action', 'impersonation.started')->exists());
    }

    public function test_non_landlords_cannot_impersonate(): void
    {
        $this->actingAs($this->patience)
            ->post("/landlord/impersonate/{$this->landlord->uuid}")
            ->assertForbidden();
    }

    public function test_an_impersonating_landlord_passes_through_maintenance(): void
    {
        MaintenanceWindow::create(['starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);

        $this->startImpersonating();

        $this->get('/dashboard')->assertOk();
    }

    public function test_the_tenant_edit_page_lists_members_for_impersonation(): void
    {
        $this->actingAs($this->landlord)
            ->get('/landlord/tenants/swecom/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Tenants/Edit')
                ->has('members')
                ->where('members', fn ($members) => collect($members)->contains(fn ($member) => $member['email'] === 'patience@shalomtech.dev' && $member['can_impersonate'] === true)
                    && collect($members)->contains(fn ($member) => $member['email'] === 'kelvin@shalomtech.dev' && $member['can_impersonate'] === false)));
    }

    public function test_the_landlord_users_page_lists_and_searches_everyone_with_log_in_as(): void
    {
        $this->actingAs($this->landlord)
            ->get('/landlord/users?search=PATIENCE')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.email', 'patience@shalomtech.dev')
                ->where('users.data.0.workspace', 'SWECOM PLC')
                ->where('users.data.0.can_impersonate', true)
                ->where('filters.search', 'PATIENCE'));

        $this->actingAs($this->patience)->get('/landlord/users')->assertForbidden();
    }
}
