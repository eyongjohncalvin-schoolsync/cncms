<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\SecurityCodeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * "My account" (/account, and /landlord/account for landlords — same page
 * and actions, no workspace needed): profile, password, code-confirmed email change,
 * authenticator two-factor, sessions, and the impersonation block. Uses the
 * seeded owner (kelvin@shalomtech.dev / password), who belongs to the
 * swecom workspace; every write rolls back with the test transaction.
 */
class AccountTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use InteractsWithTwoFactor;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();

        $this->user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->user->forceFill([
            'status' => 'active',
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    private function insertSession(string $id, User $user): void
    {
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->subMinutes(5)->getTimestamp(),
        ]);
    }

    public function test_the_account_page_renders_the_users_own_details(): void
    {
        $this->insertSession('other-session-'.str_repeat('x', 20), $this->user);
        $this->user->createToken('Pixel 7');

        $this->actingAs($this->user)
            ->get('/account')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Show')
                ->where('account.email', 'kelvin@shalomtech.dev')
                ->where('twoFactor.enabled', false)
                ->where('twoFactor.setup', null)
                ->where('sessions.0.ip_address', '10.0.0.9')
                ->where('devices.0.name', 'Pixel 7')
                ->where('isImpersonating', false));
    }

    public function test_guests_cannot_open_the_account_page(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/landlord/account')->assertRedirect('/login');
    }

    public function test_workspace_users_get_the_workspace_shell(): void
    {
        $this->actingAs($this->user)
            ->get('/account')
            ->assertInertia(fn (Assert $page) => $page->component('Account/Show')->where('area', 'workspace'));
    }

    public function test_a_landlord_without_any_workspace_manages_their_own_account(): void
    {
        $landlord = User::factory()->create(['password' => 'old-password-123']);
        $landlord->forceFill(['is_landlord' => true])->save();

        $this->actingAs($landlord)
            ->get('/landlord/account')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Show')
                ->where('area', 'landlord')
                ->where('account.email', $landlord->email));

        // The shared actions need no workspace.
        $this->patch('/account/profile', ['name' => 'Platform Owner', 'username' => 'platform-owner', 'locale' => 'en'])
            ->assertSessionHasNoErrors();
        $this->put('/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'New-password-456!',
            'password_confirmation' => 'New-password-456!',
        ])->assertSessionHasNoErrors();

        $fresh = $landlord->fresh();
        $this->assertSame('Platform Owner', $fresh->name);
        $this->assertTrue(Hash::check('New-password-456!', $fresh->password));
    }

    public function test_the_landlord_account_page_is_for_landlords_only(): void
    {
        $this->user->forceFill(['is_landlord' => false])->save();

        $this->actingAs($this->user)->get('/landlord/account')->assertForbidden();
    }

    public function test_profile_update_changes_name_username_and_language(): void
    {
        $this->actingAs($this->user)
            ->patch('/account/profile', ['name' => 'Kelvin E.', 'username' => 'kelvin-e', 'locale' => 'fr'])
            ->assertSessionHasNoErrors();

        $fresh = $this->user->fresh();
        $this->assertSame('Kelvin E.', $fresh->name);
        $this->assertSame('kelvin-e', $fresh->username);
        $this->assertSame('fr', $fresh->locale);
    }

    public function test_profile_update_rejects_a_username_taken_by_someone_else(): void
    {
        $other = User::factory()->create(['username' => 'already-taken']);

        $this->actingAs($this->user)
            ->patch('/account/profile', ['name' => 'Kelvin', 'username' => $other->username, 'locale' => 'en'])
            ->assertSessionHasErrors('username');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/account/password', ['current_password' => 'wrong', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        Mail::assertNothingQueued();
    }

    public function test_password_change_signs_out_other_sessions_and_devices_and_sends_a_notice(): void
    {
        $this->insertSession('other-session-'.str_repeat('y', 20), $this->user);
        $this->user->createToken('Old phone');

        $this->actingAs($this->user)
            ->put('/account/password', ['current_password' => 'password', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewPassw0rd!', $this->user->fresh()->password));
        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, $this->user->tokens()->count());
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::PASSWORD_CHANGED
            && $mail->hasTo('kelvin@shalomtech.dev'));
    }

    public function test_email_change_sends_a_code_to_the_new_address_and_switches_only_once_confirmed(): void
    {
        $this->actingAs($this->user)
            ->post('/account/email', ['email' => 'kelvin.new@example.test', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertSame('kelvin@shalomtech.dev', $this->user->fresh()->email);
        $code = $this->lastEmailedCode('kelvin.new@example.test');

        $this->get('/account')->assertInertia(fn (Assert $page) => $page->where('account.pending_email', 'kelvin.new@example.test'));

        $this->post('/account/email/confirm', ['code' => $this->wrongCode($code)])->assertSessionHasErrors('code');
        $this->assertSame('kelvin@shalomtech.dev', $this->user->fresh()->email);

        $this->post('/account/email/confirm', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertSame('kelvin.new@example.test', $this->user->fresh()->email);

        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::EMAIL_CHANGED
            && $mail->hasTo('kelvin@shalomtech.dev')
            && $mail->newEmail === 'kelvin.new@example.test');

        // Single use.
        $this->post('/account/email/confirm', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_email_change_needs_the_password_and_a_free_address(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->post('/account/email', ['email' => 'kelvin.new@example.test', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->post('/account/email', ['email' => $other->email, 'current_password' => 'password'])
            ->assertSessionHasErrors('email');

        Mail::assertNotQueued(SecurityCodeMail::class);
    }

    public function test_an_expired_email_change_code_is_refused(): void
    {
        $this->actingAs($this->user)->post('/account/email', ['email' => 'kelvin.new@example.test', 'current_password' => 'password']);
        $code = $this->lastEmailedCode();

        $this->travel(11)->minutes();

        $this->post('/account/email/confirm', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertSame('kelvin@shalomtech.dev', $this->user->fresh()->email);
    }

    public function test_two_factor_setup_shows_a_qr_and_turns_on_with_a_valid_code(): void
    {
        $this->actingAs($this->user)->post('/account/two-factor')->assertSessionHasNoErrors();

        $secret = $this->user->fresh()->two_factor_secret;
        $this->assertNotNull($secret);
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
        $this->assertNotSame($secret, DB::connection('pgsql')->table('users')->where('id', $this->user->id)->value('two_factor_secret'), 'The secret is encrypted at rest.');

        $this->get('/account')->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.setup.secret', $secret)
            ->where('twoFactor.setup.qr_code', fn (string $qr) => str_starts_with($qr, 'data:image/png;base64,')));

        $this->post('/account/two-factor/confirm', ['code' => $this->wrongCode(Totp::codeAt($secret, Totp::timeStep()))])
            ->assertSessionHasErrors('code');
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->post('/account/two-factor/confirm', ['code' => Totp::codeAt($secret, Totp::timeStep())])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('recovery_codes', fn (array $codes) => count($codes) === 8);

        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $this->user->fresh()->two_factor_recovery_codes);
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::TWO_FACTOR_ENABLED);
    }

    public function test_turning_two_factor_off_needs_the_password_and_a_code(): void
    {
        $secret = $this->enableTwoFactor($this->user)['secret'];

        $this->actingAs($this->user)
            ->delete('/account/two-factor', ['password' => 'wrong', 'code' => $this->totpCode($secret)])
            ->assertSessionHasErrors('password');

        $this->delete('/account/two-factor', ['password' => 'password', 'code' => $this->wrongCode($this->totpCode($secret))])
            ->assertSessionHasErrors('code');
        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        $this->delete('/account/two-factor', ['password' => 'password', 'code' => $this->totpCode($secret)])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($this->user->fresh()->two_factor_secret);
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::TWO_FACTOR_DISABLED);
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $oldCode = $this->enableTwoFactor($this->user)['recovery_codes'][0];

        $this->actingAs($this->user)
            ->post('/account/two-factor/recovery-codes', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');

        $this->post('/account/two-factor/recovery-codes', ['password' => 'password'])
            ->assertSessionHas('recovery_codes', fn (array $codes) => count($codes) === 8);

        $this->assertCount(8, $this->user->fresh()->two_factor_recovery_codes);
        $this->assertFalse(app(TwoFactorService::class)->useRecoveryCode($this->user->fresh(), $oldCode));
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::RECOVERY_CODES_REGENERATED);
    }

    public function test_sign_out_of_other_sessions_needs_the_password(): void
    {
        $this->insertSession('other-session-'.str_repeat('z', 20), $this->user);
        $this->user->createToken('Phone');

        $this->actingAs($this->user)->delete('/account/sessions', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(1, $this->user->tokens()->count());

        $this->delete('/account/sessions', ['password' => 'password'])->assertSessionHas('success');

        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_an_impersonating_landlord_can_view_but_not_change_the_users_account(): void
    {
        $this->user->forceFill(['is_landlord' => true])->save();
        $patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();
        TenantUser::query()->where('user_id', $patience->id)->update(['role' => 'admin', 'branch_id' => null]);

        $this->actingAs($this->user)->post("/landlord/impersonate/{$patience->uuid}")->assertRedirect('/dashboard');

        $this->get('/account')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('account.email', 'patience@shalomtech.dev')
            ->where('isImpersonating', true));

        $this->patch('/account/profile', ['name' => 'Hacked', 'username' => $patience->username, 'locale' => 'en'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->put('/account/password', ['current_password' => 'password', 'password' => 'NewPassw0rd!', 'password_confirmation' => 'NewPassw0rd!'])
            ->assertSessionHas('error');
        $this->post('/account/two-factor')->assertSessionHas('error');
        $this->post('/account/email', ['email' => 'x@example.test', 'current_password' => 'password'])->assertSessionHas('error');
        $this->delete('/account/sessions', ['password' => 'password'])->assertSessionHas('error');

        $fresh = $patience->fresh();
        $this->assertNotSame('Hacked', $fresh->name);
        $this->assertNull($fresh->two_factor_secret);
        Mail::assertNothingQueued();
    }
}
