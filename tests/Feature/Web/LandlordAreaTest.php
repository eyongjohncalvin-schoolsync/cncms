<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The landlord dashboard, the landlord activity log, and landlord access
 * management (grant/revoke). Central data only — no tenancy, so plain
 * DatabaseTransactions rolls everything back.
 *
 * kelvin is the acting landlord; patience is the user being granted.
 */
class LandlordAreaTest extends TestCase
{
    use DatabaseTransactions;

    private User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->where('is_landlord', true)->update(['is_landlord' => false]);

        $this->landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->landlord->forceFill(['is_landlord' => true])->save();

        $this->actingAs($this->landlord);
    }

    private function patience(): User
    {
        return User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
    }

    public function test_dashboard_shows_platform_counts_and_recent_activity(): void
    {
        LandlordActivity::record('tenant.approved', 'Approved workspace Test', 'tenant', 'test');

        $this->get('/landlord')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Dashboard')
                ->where('stats.tenants', Tenant::query()->count())
                ->where('stats.landlords', 1)
                ->where('recent_activity.0.summary', 'Approved workspace Test')
                ->where('recent_activity.0.user', $this->landlord->name));
    }

    public function test_the_landlord_area_is_forbidden_to_non_landlords(): void
    {
        $this->actingAs($this->patience());

        $this->get('/landlord')->assertForbidden();
        $this->get('/landlord/activity')->assertForbidden();
        $this->get('/landlord/landlords')->assertForbidden();
        $this->post('/landlord/landlords', ['email' => 'patience@shalomtech.dev'])->assertForbidden();
    }

    public function test_tenant_actions_are_logged(): void
    {
        $this->post('/landlord/tenants/swecom/reject', ['reason' => 'Testing'])->assertRedirect();
        $this->post('/landlord/tenants/swecom/approve')->assertRedirect();
        $this->patch('/landlord/tenants/swecom', ['bulk_whatsapp_enabled' => '1'])->assertRedirect();

        $actions = LandlordActivity::query()->where('subject_id', 'swecom')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['tenant.rejected', 'tenant.approved', 'tenant.updated'], $actions);

        $rejected = LandlordActivity::query()->where('action', 'tenant.rejected')->sole();
        $this->assertSame($this->landlord->id, $rejected->user_id);
        $this->assertSame(['reason' => 'Testing'], $rejected->details);
        $this->assertNotNull($rejected->ip_address);
    }

    public function test_activity_log_paginates_and_filters_by_subject(): void
    {
        LandlordActivity::record('tenant.approved', 'Approved A', 'tenant', 'a');
        LandlordActivity::record('maintenance.scheduled', 'Scheduled maintenance', 'maintenance', 1);

        $this->get('/landlord/activity?subject=maintenance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landlord/Activity/Index')
                ->has('activities.data', 1)
                ->where('activities.data.0.action', 'maintenance.scheduled')
                ->where('filters.subject', 'maintenance'));
    }

    public function test_a_landlord_grants_and_revokes_access_and_both_are_logged(): void
    {
        $patience = $this->patience();

        $this->post('/landlord/landlords', ['email' => '  PATIENCE@shalomtech.dev '])->assertSessionHas('success');

        $patience->refresh();
        $this->assertTrue($patience->is_landlord);
        $this->assertSame($this->landlord->id, $patience->landlord_granted_by);

        $this->get('/landlord/landlords')->assertInertia(fn (Assert $page) => $page->has('landlords', 2));

        $this->delete("/landlord/landlords/{$patience->uuid}")->assertSessionHas('success');
        $this->assertFalse($patience->fresh()->is_landlord);

        $this->assertSame(
            ['landlord.granted', 'landlord.revoked'],
            LandlordActivity::query()->where('subject_id', (string) $patience->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_granting_an_unknown_email_fails_validation(): void
    {
        $this->post('/landlord/landlords', ['email' => 'nobody@example.test'])->assertSessionHasErrors('email');
    }

    public function test_a_landlord_cannot_revoke_themselves_so_one_always_remains(): void
    {
        $this->delete("/landlord/landlords/{$this->landlord->uuid}")->assertSessionHas('error', 'You cannot revoke your own landlord access.');
        $this->assertTrue($this->landlord->fresh()->is_landlord);
        $this->assertSame(1, User::query()->where('is_landlord', true)->count());
    }

    public function test_revoking_someone_who_is_not_a_landlord_is_a_404(): void
    {
        $this->delete("/landlord/landlords/{$this->patience()->uuid}")->assertNotFound();
    }

    public function test_the_cli_grant_is_logged_without_a_user(): void
    {
        auth()->logout(); // a real CLI run has nobody signed in

        Artisan::call('cncms:grant-landlord', ['email' => 'patience@shalomtech.dev']);

        $entry = LandlordActivity::query()->where('action', 'landlord.granted')->sole();
        $this->assertNull($entry->user_id);
        $this->assertNull($entry->ip_address);
        $this->assertStringContainsString('(command line)', $entry->summary);
    }

    public function test_a_landlord_without_a_workspace_lands_on_the_landlord_dashboard(): void
    {
        auth()->logout();

        $user = User::factory()->create(['password' => 'secret-password-1']);
        $user->forceFill(['is_landlord' => true])->save();

        $this->post('/login', ['username' => $user->email, 'password' => 'secret-password-1'])->assertRedirect('/landlord');
    }
}
