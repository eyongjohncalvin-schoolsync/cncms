<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\MaintenanceNoticeMail;
use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Platform maintenance mode (App\Support\MaintenanceMode): landlord
 * scheduling, the advance-notice banner prop, the tenant lock-out on web
 * and API, and the landlord pass-through.
 *
 * kelvin is the landlord; patience (admin) is an ordinary tenant user.
 */
class MaintenanceModeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
    }

    private function landlord(): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $user->forceFill(['is_landlord' => true])->save();

        return $user;
    }

    private function tenantUser(): User
    {
        $user = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $user->forceFill(['is_landlord' => false])->save();

        return $user;
    }

    private function window(string $startsIn, string $endsIn): MaintenanceWindow
    {
        return MaintenanceWindow::create([
            'starts_at' => now()->modify($startsIn),
            'ends_at' => now()->modify($endsIn),
            'message' => 'Moving to the new server.',
        ]);
    }

    public function test_landlord_sees_the_maintenance_screen_and_others_are_forbidden(): void
    {
        $this->actingAs($this->landlord())
            ->get('/landlord/maintenance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landlord/Maintenance/Index')->has('windows')->has('recipient_count'));

        $this->actingAs($this->tenantUser())->get('/landlord/maintenance')->assertForbidden();
        $this->post('/landlord/maintenance', ['ends_at' => now()->addHour()->toIso8601String()])->assertForbidden();
    }

    public function test_landlord_schedules_a_window_and_tenant_users_are_emailed(): void
    {
        $this->actingAs($this->landlord())
            ->post('/landlord/maintenance', [
                'starts_at' => now()->addHours(2)->toIso8601String(),
                'ends_at' => now()->addHours(4)->toIso8601String(),
                'message' => 'Moving to the new server.',
                'notify_by_email' => true,
            ])
            ->assertRedirect('/landlord/maintenance')
            ->assertSessionHas('success');

        $window = MaintenanceWindow::query()->sole();
        $this->assertSame('scheduled', $window->status());

        Mail::assertQueued(MaintenanceNoticeMail::class, fn (MaintenanceNoticeMail $mail) => $mail->kind === 'scheduled' && $mail->window->is($window));
        Mail::assertQueued(MaintenanceNoticeMail::class, fn (MaintenanceNoticeMail $mail) => $mail->hasTo('patience@shalomtech.dev'));
    }

    public function test_no_email_unless_asked(): void
    {
        $this->actingAs($this->landlord())->post('/landlord/maintenance', [
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
            'notify_by_email' => false,
        ])->assertRedirect();

        Mail::assertNothingQueued();
    }

    public function test_an_empty_start_means_now(): void
    {
        $this->actingAs($this->landlord())->post('/landlord/maintenance', [
            'starts_at' => '',
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertSessionHas('success', 'Maintenance started.');

        $this->assertTrue(MaintenanceWindow::query()->sole()->isActive());
    }

    public function test_validation_rejects_bad_times_and_a_second_live_window(): void
    {
        $landlord = $this->landlord();

        $this->actingAs($landlord)->post('/landlord/maintenance', [
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'ends_at' => now()->addHours(2)->toIso8601String(),
        ])->assertSessionHasErrors('ends_at');

        $this->post('/landlord/maintenance', [
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertSessionHasErrors('starts_at');

        $this->window('+1 hour', '+2 hours');

        $this->post('/landlord/maintenance', [
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(3)->toIso8601String(),
        ])->assertSessionHasErrors('starts_at');

        $this->assertSame(1, MaintenanceWindow::query()->count());
    }

    public function test_a_scheduled_window_is_announced_but_does_not_block(): void
    {
        $window = $this->window('+1 hour', '+3 hours');

        $this->actingAs($this->tenantUser())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('maintenance.id', $window->id)
                ->where('maintenance.active', false)
                ->where('maintenance.message', 'Moving to the new server.'));
    }

    public function test_the_login_page_announces_a_scheduled_window(): void
    {
        $window = $this->window('+1 hour', '+3 hours');

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('maintenance.id', $window->id));
    }

    public function test_an_active_window_locks_tenant_users_out_of_the_web_app(): void
    {
        $window = $this->window('-5 minutes', '+1 hour');

        $this->actingAs($this->tenantUser());

        $this->get('/dashboard')->assertRedirect('/maintenance');
        $this->get('/customers')->assertRedirect('/maintenance');

        $this->get('/maintenance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Maintenance')
                ->where('window.id', $window->id)
                ->where('window.active', true));
    }

    public function test_landlords_keep_access_during_an_active_window(): void
    {
        $this->window('-5 minutes', '+1 hour');

        $this->actingAs($this->landlord())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('maintenance.active', true));
    }

    public function test_an_active_window_returns_503_maintenance_on_the_api(): void
    {
        $this->window('-5 minutes', '+1 hour');
        $this->tenantUser();
        $token = $this->tokenForRole('admin');
        User::query()->where('email', 'kelvin@shalomtech.dev')->update(['is_landlord' => false]);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'MAINTENANCE')
            ->assertJsonStructure(['maintenance' => ['starts_at', 'ends_at']]);
    }

    public function test_api_responses_carry_advance_notice_headers(): void
    {
        $window = $this->window('+1 hour', '+3 hours');
        $token = $this->tokenForRole('admin');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('X-Maintenance-Starts-At', $window->starts_at->toIso8601String())
            ->assertHeader('X-Maintenance-Ends-At', $window->ends_at->toIso8601String());
    }

    public function test_ending_an_active_window_restores_access(): void
    {
        $window = $this->window('-5 minutes', '+1 hour');

        $this->actingAs($this->landlord())
            ->post("/landlord/maintenance/{$window->id}/end")
            ->assertSessionHas('success');

        $this->assertNotNull($window->fresh()->ended_at);

        $this->actingAs($this->tenantUser());
        $this->get('/dashboard')->assertOk();
        $this->get('/maintenance')->assertRedirect('/dashboard');
    }

    public function test_cancelling_a_scheduled_window_emails_users_when_asked(): void
    {
        $window = $this->window('+1 hour', '+2 hours');

        $this->actingAs($this->landlord())
            ->post("/landlord/maintenance/{$window->id}/end", ['notify_by_email' => true])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $window->fresh()->status());
        Mail::assertQueued(MaintenanceNoticeMail::class, fn (MaintenanceNoticeMail $mail) => $mail->kind === 'cancelled');
    }

    public function test_the_end_time_can_be_moved_but_not_on_a_finished_window(): void
    {
        $window = $this->window('-5 minutes', '+1 hour');
        $newEnd = now()->addHours(3)->startOfMinute();

        $this->actingAs($this->landlord())
            ->patch("/landlord/maintenance/{$window->id}", ['ends_at' => $newEnd->toIso8601String()])
            ->assertSessionHas('success');

        $this->assertTrue($window->fresh()->ends_at->equalTo($newEnd));

        $window->update(['ended_at' => now()]);

        $this->patch("/landlord/maintenance/{$window->id}", ['ends_at' => now()->addHours(5)->toIso8601String()])->assertStatus(422);
        $this->post("/landlord/maintenance/{$window->id}/end")->assertStatus(422);
    }
}
