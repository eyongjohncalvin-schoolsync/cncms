<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\SecurityNoticeMail;
use App\Models\LandlordActivity;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Landlord "Reset 2FA" (Landlord\UserController::resetTwoFactor()) for a
 * user locked out of their authenticator: landlord only, reason required,
 * never on yourself, refused while impersonating, clears the secret and
 * recovery codes, signs the user out everywhere, emails them and logs
 * `user.two_factor_reset`.
 *
 * kelvin is the landlord; patience (a workspace member) is the target.
 */
class LandlordTwoFactorResetTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use InteractsWithTwoFactor;

    private User $landlord;

    private User $patience;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->initializeTenant();

        $this->landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->landlord->forceFill(['is_landlord' => true])->save();

        $this->patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $this->patience->forceFill(['is_landlord' => false, 'status' => 'active', 'password' => 'password'])->save();
        TenantUser::query()->where('user_id', $this->patience->id)->update(['role' => 'admin', 'branch_id' => null]);

        $this->enableTwoFactor($this->patience);
    }

    private function resetTwoFactor(User $target, ?string $reason = 'Lost phone, identity confirmed by phone call.'): TestResponse
    {
        return $this->post(route('landlord.users.two-factor.reset', $target), $reason === null ? [] : ['reason' => $reason]);
    }

    public function test_a_landlord_resets_a_users_two_factor(): void
    {
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => 'reset-2fa-'.Str::random(30),
            'user_id' => $this->patience->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);
        $this->patience->createToken('Phone');

        $this->actingAs($this->landlord)
            ->from('/landlord/users')
            ->resetTwoFactor($this->patience)
            ->assertRedirect('/landlord/users')
            ->assertSessionHas('success');

        $patience = $this->patience->fresh();
        $this->assertFalse($patience->hasTwoFactorEnabled());
        $this->assertNull($patience->two_factor_secret);
        $this->assertNull($patience->two_factor_recovery_codes);
        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $patience->id)->count());
        $this->assertSame(0, $patience->tokens()->count());

        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->hasTo($patience->email)
            && $mail->kind === SecurityNoticeMail::TWO_FACTOR_RESET);

        $activity = LandlordActivity::query()->where('action', 'user.two_factor_reset')->sole();
        $this->assertSame($this->landlord->id, $activity->user_id);
        $this->assertSame((string) $patience->id, $activity->subject_id);
        $this->assertSame('Lost phone, identity confirmed by phone call.', $activity->details['reason']);
    }

    public function test_after_a_reset_the_user_signs_in_with_just_their_password(): void
    {
        $this->actingAs($this->landlord)->resetTwoFactor($this->patience)->assertSessionHas('success');

        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->post('/login', ['username' => $this->patience->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->patience);
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs($this->landlord)->resetTwoFactor($this->patience, null)->assertSessionHasErrors('reason');
        $this->actingAs($this->landlord)->resetTwoFactor($this->patience, '   ')->assertSessionHasErrors('reason');

        $this->assertTrue($this->patience->fresh()->hasTwoFactorEnabled());
        $this->assertFalse(LandlordActivity::query()->where('action', 'user.two_factor_reset')->exists());
        Mail::assertNothingQueued();
    }

    public function test_non_landlords_cannot_reset_two_factor(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->resetTwoFactor($this->patience)->assertForbidden();

        $this->assertTrue($this->patience->fresh()->hasTwoFactorEnabled());
    }

    public function test_guests_cannot_reset_two_factor(): void
    {
        $this->resetTwoFactor($this->patience)->assertRedirect('/login');

        $this->assertTrue($this->patience->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_landlord_cannot_reset_their_own_two_factor_here(): void
    {
        $this->enableTwoFactor($this->landlord);

        $this->actingAs($this->landlord)
            ->from('/landlord/users')
            ->resetTwoFactor($this->landlord)
            ->assertRedirect('/landlord/users')
            ->assertSessionHas('error');

        $this->assertTrue($this->landlord->fresh()->hasTwoFactorEnabled());
        $this->assertFalse(LandlordActivity::query()->where('action', 'user.two_factor_reset')->exists());
    }

    public function test_a_user_without_two_factor_is_refused(): void
    {
        $this->patience->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->actingAs($this->landlord)
            ->from('/landlord/users')
            ->resetTwoFactor($this->patience)
            ->assertSessionHas('error');

        $this->assertFalse(LandlordActivity::query()->where('action', 'user.two_factor_reset')->exists());
        Mail::assertNothingQueued();
    }

    public function test_refused_while_impersonating(): void
    {
        $other = User::factory()->create();
        $this->enableTwoFactor($other);

        $this->actingAs($this->landlord)
            ->post("/landlord/impersonate/{$this->patience->uuid}")
            ->assertRedirect('/dashboard');

        $this->resetTwoFactor($other)->assertForbidden();

        $this->assertTrue($other->fresh()->hasTwoFactorEnabled());
        $this->assertFalse(LandlordActivity::query()->where('action', 'user.two_factor_reset')->exists());
    }

    public function test_the_users_list_and_members_list_offer_reset_only_for_others_with_two_factor(): void
    {
        $this->enableTwoFactor($this->landlord);

        $this->actingAs($this->landlord)
            ->get('/landlord/users?search=patience')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.email', 'patience@shalomtech.dev')
                ->where('users.data.0.can_reset_two_factor', true));

        $this->get('/landlord/users?search=kelvin@shalomtech.dev')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.email', 'kelvin@shalomtech.dev')
                ->where('users.data.0.can_reset_two_factor', false));

        $this->get('/landlord/tenants/swecom/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => collect($members)
                ->contains(fn ($member) => $member['email'] === 'patience@shalomtech.dev' && $member['can_reset_two_factor'] === true)
                && collect($members)->contains(fn ($member) => $member['email'] === 'kelvin@shalomtech.dev' && $member['can_reset_two_factor'] === false)));
    }
}
