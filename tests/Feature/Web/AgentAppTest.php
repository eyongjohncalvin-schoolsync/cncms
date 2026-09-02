<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * "Get the Agent App" page (App\Http\Controllers\AgentAppController,
 * /agent-app). Same session-auth Inertia pattern as SettingsNotificationsTest:
 * reuse the seeded owner (kelvin@shalomtech.dev), flip their tenant_users
 * role per test.
 */
class AgentAppTest extends TestCase
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

    public function test_agent_can_view_the_page(): void
    {
        $this->actingAsRole('agent');

        $this->get('/agent-app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AgentApp/Index')
                ->where('version', config('agent-app.version'))
                ->has('android_url'));
    }

    public function test_manager_and_admin_and_super_can_view_the_page(): void
    {
        foreach (['manager', 'admin', 'super'] as $role) {
            $this->actingAsRole($role);
            $this->get('/agent-app')->assertOk();
        }
    }

    public function test_worker_is_forbidden(): void
    {
        $this->actingAsRole('worker');

        $this->get('/agent-app')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/agent-app')->assertRedirect('/login');
    }

    public function test_a_configured_android_url_is_passed_to_the_page(): void
    {
        config()->set('agent-app.android_url', 'https://expo.dev/accounts/miskhan/projects/cncms-mobile/builds/demo');

        $this->actingAsRole('agent');

        $this->get('/agent-app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('android_url', 'https://expo.dev/accounts/miskhan/projects/cncms-mobile/builds/demo'));
    }

    public function test_no_android_url_leaves_url_null(): void
    {
        config()->set('agent-app.android_url', null);

        $this->actingAsRole('agent');

        $this->get('/agent-app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('android_url', null));
    }
}
