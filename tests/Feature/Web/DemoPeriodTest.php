<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\DemoReminderMail;
use App\Models\LandlordActivity;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Support\DemoReadOnly;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The free 4-month demo (Tenant::isOnDemo, App\Support\DemoReadOnly,
 * App\Console\Commands\DemoReminders, Landlord\TenantController::
 * updateDemo/activate): started on approval, reminders, read-only after the
 * last day until a landlord activates the workspace.
 *
 * Runs inside the seeded swecom tenant with kelvin's role switched, as in
 * BranchTest; the demo dates are written on the very Tenant instance
 * tenancy holds, so every layer of the request sees them.
 */
class DemoPeriodTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 10:00:00');
        $this->initializeTenant();
        $this->tenant = tenant();
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
    }

    private function demoEnding(string $lastDay): void
    {
        $this->tenant->demo_starts_on = '2026-07-01';
        $this->tenant->demo_ends_on = $lastDay;
        $this->tenant->demo_reminders_sent = [];
        $this->tenant->activated_at = null;
        $this->tenant->save();
    }

    private function actingAsRole(string $role, bool $landlord = false): void
    {
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => $role]);
        $this->kelvin->forceFill(['is_landlord' => $landlord])->save();
        $this->actingAs($this->kelvin);
    }

    public function test_approving_a_sign_up_starts_a_four_month_demo(): void
    {
        $this->tenant->registration_status = 'pending';
        $this->tenant->save();
        $this->actingAsRole('super', landlord: true);

        $this->post("/landlord/tenants/{$this->tenant->id}/approve")->assertSessionHas('success', fn (string $message) => str_contains($message, 'Free demo until 28 Feb 2027'));

        $tenant = Tenant::find($this->tenant->id);
        $this->assertSame('2026-11-01', $tenant->demo_starts_on);
        $this->assertSame('2027-02-28', $tenant->demo_ends_on);
        $this->assertTrue($tenant->isOnDemo());
        $this->assertSame(119, $tenant->demoDaysLeft());
        $this->assertFalse($tenant->isReadOnly());
    }

    public function test_a_company_without_demo_dates_is_a_full_account(): void
    {
        $this->assertFalse($this->tenant->isOnDemo());
        $this->assertNull($this->tenant->demoDaysLeft());
        $this->assertFalse($this->tenant->isReadOnly());
    }

    public function test_after_the_last_day_nothing_new_can_be_saved_on_the_web(): void
    {
        $this->demoEnding('2026-10-31');
        $this->actingAsRole('admin');

        $this->from('/zones')->post('/zones', ['name' => 'ZDEMO (READ ONLY)'])
            ->assertRedirect('/zones')
            ->assertSessionHas('error', DemoReadOnly::message());
        $this->assertFalse(Zone::query()->where('name', 'ZDEMO (READ ONLY)')->exists());

        // Reading still works, and the page explains why.
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('demo.read_only', true)
            ->where('demo.ends_on', '2026-10-31'));
        $this->get('/zones')->assertOk();

        // Session-only switches and signing out are not "new work".
        $this->post('/notifications/read-all')->assertSessionMissing('error');
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_last_day_itself_is_still_a_full_day(): void
    {
        $this->demoEnding('2026-11-01');
        $this->actingAsRole('manager');

        $this->post('/zones', ['name' => 'ZDEMO (LAST DAY)'])->assertSessionMissing('error');
        $this->assertTrue(Zone::query()->where('name', 'ZDEMO (LAST DAY)')->exists());
    }

    public function test_the_mobile_app_is_refused_with_a_code_and_can_still_read(): void
    {
        $this->demoEnding('2026-10-15');
        $token = $this->tokenForRole('admin');

        $this->withToken($token)->postJson('/api/v1/zones', ['name' => 'ZDEMO (API)', 'town' => 'KUMBA 3'])
            ->assertForbidden()
            ->assertJsonPath('code', DemoReadOnly::CODE);
        $this->withToken($token)->getJson('/api/v1/zones')->assertOk();
    }

    public function test_only_admins_see_the_countdown_and_only_in_the_last_30_days(): void
    {
        $this->demoEnding('2026-11-20');

        $this->actingAsRole('admin');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('demo.days_left', 19)
            ->where('demo.read_only', false));

        $this->actingAsRole('manager');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('demo', null));

        $this->demoEnding('2027-01-15');
        $this->actingAsRole('admin');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('demo', null));
    }

    public function test_reminders_go_once_per_stage_then_the_ended_notice_reaches_landlords_too(): void
    {
        Mail::fake();
        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'admin']);
        $this->kelvin->forceFill(['is_landlord' => true, 'status' => 'active'])->save();
        $this->demoEnding('2026-12-01');

        $this->artisan('demo:reminders', ['tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('demo:reminders', ['tenant' => $this->tenant->id])->assertSuccessful();
        $toKelvin = fn () => Mail::queued(DemoReminderMail::class, fn (DemoReminderMail $mail) => $mail->hasTo($this->kelvin->email))->pluck('stage')->all();
        $this->assertSame(['30'], $toKelvin());

        // A missed week: only the latest stage due goes out.
        $this->travelTo('2026-11-29 10:00:00');
        $this->artisan('demo:reminders', ['tenant' => $this->tenant->id]);
        $this->assertSame(['30', '7'], $toKelvin());
        $this->assertSame(['30', '7'], Tenant::find($this->tenant->id)->demo_reminders_sent);

        $this->travelTo('2026-12-02 10:00:00');
        $this->artisan('demo:reminders', ['tenant' => $this->tenant->id]);
        Mail::assertQueued(DemoReminderMail::class, fn (DemoReminderMail $mail) => $mail->stage === 'ended' && ! $mail->forLandlord);
        Mail::assertQueued(DemoReminderMail::class, fn (DemoReminderMail $mail) => $mail->stage === 'ended' && $mail->forLandlord);
        $this->assertSame('tenant.demo_ended', LandlordActivity::query()->latest('id')->value('action'));
        $this->assertEqualsCanonicalizing(['30', '7', '1', 'ended'], Tenant::find($this->tenant->id)->demo_reminders_sent);
    }

    public function test_a_landlord_can_move_the_end_date_and_activate_the_account(): void
    {
        $this->demoEnding('2026-10-31');
        $this->actingAsRole('super', landlord: true);

        $this->patch("/landlord/tenants/{$this->tenant->id}/demo", ['demo_ends_on' => '2026-12-31'])->assertSessionHas('success');
        $tenant = Tenant::find($this->tenant->id);
        $this->assertSame('2026-12-31', $tenant->demo_ends_on);
        $this->assertSame('2026-07-01', $tenant->demo_starts_on, 'Moving the end date keeps the start.');
        $this->assertFalse($tenant->isReadOnly());

        $this->post("/landlord/tenants/{$this->tenant->id}/activate")->assertSessionHas('success');
        $tenant = Tenant::find($this->tenant->id);
        $this->assertFalse($tenant->isOnDemo());
        $this->assertSame('2026-12-31', $tenant->demo_ends_on, 'Demo dates are kept for the record.');
        $this->assertSame('tenant.activated', LandlordActivity::query()->latest('id')->value('action'));

        $this->post("/landlord/tenants/{$this->tenant->id}/activate")->assertSessionHas('error');
        $this->patch("/landlord/tenants/{$this->tenant->id}/demo", ['demo_ends_on' => 'soon'])->assertSessionHasErrors('demo_ends_on');
    }

    public function test_an_activated_workspace_can_save_again(): void
    {
        $this->demoEnding('2026-10-31');
        $this->tenant->activated_at = now()->toIso8601String();
        $this->tenant->save();
        $this->actingAsRole('manager');

        $this->post('/zones', ['name' => 'ZDEMO (ACTIVATED)'])->assertSessionMissing('error');
        $this->assertTrue(Zone::query()->where('name', 'ZDEMO (ACTIVATED)')->exists());
    }

    public function test_only_landlords_can_change_the_demo(): void
    {
        $this->actingAsRole('super');

        $this->patch("/landlord/tenants/{$this->tenant->id}/demo", ['demo_ends_on' => '2026-12-31'])->assertForbidden();
        $this->post("/landlord/tenants/{$this->tenant->id}/activate")->assertForbidden();
    }
}
