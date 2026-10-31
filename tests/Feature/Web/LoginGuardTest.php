<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\AccountBlockedMail;
use App\Mail\LoginAttackAlertMail;
use App\Models\LandlordActivity;
use App\Models\LoginFailure;
use App\Models\User;
use App\Services\LoginGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Account blocks after repeated wrong passwords, web and mobile
 * (App\Services\LoginGuard), the alerts, and Landlord → Security
 * (Landlord\SecurityController). The per-minute `login` rate limiter is
 * switched off here so the block itself is what's tested.
 */
class LoginGuardTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo('2026-11-02 09:00:00');
        $this->user = User::factory()->create();
    }

    private function webLogin(string $password, ?string $username = null): TestResponse
    {
        return $this->post('/login', ['username' => $username ?? $this->user->username, 'password' => $password]);
    }

    private function wrongPasswords(int $times, ?string $username = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->webLogin('wrong-'.$i, $username);
        }
    }

    public function test_ten_wrong_passwords_block_the_account_for_fifteen_minutes(): void
    {
        $this->wrongPasswords(9);
        $this->webLogin('wrong')->assertSessionHasErrors(['username' => 'Too many wrong passwords. For your safety, password sign-in for this account is blocked until 10:15 (Cameroon time). You can still sign in with a code sent to your email.']);

        // Even the right password is refused while it lasts.
        $this->webLogin('password')->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertSame(1, LoginFailure::query()->where('user_id', $this->user->id)->where('reason', LoginFailure::WHILE_BLOCKED)->count());

        Mail::assertQueued(AccountBlockedMail::class, fn (AccountBlockedMail $mail) => $mail->hasTo($this->user->email) && $mail->failures === 10);
        Mail::assertQueuedCount(1);
        $this->assertSame('security.account_blocked', LandlordActivity::query()->latest('id')->value('action'));

        $this->travel(16)->minutes();
        $this->webLogin('password')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(0, LoginFailure::query()->where('user_id', $this->user->id)->whereNull('cleared_at')->where('reason', LoginFailure::WRONG_PASSWORD)->count(), 'A correct password clears the count.');
    }

    public function test_failing_again_after_the_first_block_blocks_it_for_an_hour(): void
    {
        $this->wrongPasswords(10);
        $this->travel(16)->minutes();
        $this->webLogin('wrong-again')->assertSessionHasErrors('username');
        $this->assertStringContainsString('blocked until 11:16', session('errors')->first('username'));

        // Still blocked once the first ten have left the hour's window.
        $this->travel(45)->minutes();
        $this->webLogin('password')->assertSessionHasErrors('username');
        $this->assertSame(1, count(app(LoginGuard::class)->blockedAccounts()));

        $this->travel(16)->minutes();
        $this->webLogin('password')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
        Mail::assertQueued(AccountBlockedMail::class, 2);
    }

    public function test_a_correct_password_in_between_resets_the_count(): void
    {
        $this->wrongPasswords(9);
        $this->webLogin('password')->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->wrongPasswords(9);

        $this->webLogin('password')->assertSessionHasNoErrors();
        Mail::assertNothingQueued();
    }

    public function test_the_mobile_app_gets_a_clear_code_and_the_block_covers_both(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => 'nope'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => 'nope'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'ACCOUNT_BLOCKED');
        $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => 'password'])->assertStatus(423);
        $this->webLogin('password')->assertSessionHasErrors('username');

        $this->assertSame(10, LoginFailure::query()->where('user_id', $this->user->id)->where('channel', LoginFailure::MOBILE)->where('reason', LoginFailure::WRONG_PASSWORD)->count());
    }

    public function test_an_unknown_account_is_blocked_the_same_way_without_email(): void
    {
        $this->wrongPasswords(10, 'Nobody.Here');

        $this->webLogin('anything', 'nobody.here')->assertSessionHasErrors('username');
        $this->assertStringContainsString('blocked until', session('errors')->first('username'));
        Mail::assertNotQueued(AccountBlockedMail::class);
    }

    public function test_one_device_trying_many_accounts_alerts_the_landlords_once(): void
    {
        $landlord = User::factory()->create(['is_landlord' => true]);

        for ($account = 1; $account <= 11; $account++) {
            $this->webLogin('wrong', "guess{$account}");
        }

        Mail::assertQueued(LoginAttackAlertMail::class, fn (LoginAttackAlertMail $mail) => $mail->hasTo($landlord->email) && $mail->accounts === 10);
        $this->assertSame(1, Mail::queued(LoginAttackAlertMail::class, fn (LoginAttackAlertMail $mail) => $mail->hasTo($landlord->email))->count());
    }

    public function test_thirty_wrong_passwords_from_one_device_block_it_whatever_usernames_are_typed(): void
    {
        $landlord = User::factory()->create(['is_landlord' => true]);

        for ($i = 1; $i < 30; $i++) {
            $this->webLogin('wrong', "made-up-{$i}");
        }

        $this->webLogin('wrong', 'made-up-30')->assertSessionHasErrors('username');
        $this->assertStringContainsString('from this network', session('errors')->first('username'));

        // Every account is refused from that address, even with the right password…
        $this->webLogin('password')->assertSessionHasErrors('username');
        $this->postJson('/api/v1/auth/login', ['username' => $this->user->username, 'password' => 'password'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'DEVICE_BLOCKED');
        $this->assertGuest();

        // …but not from anywhere else.
        $this->withServerVariables(['REMOTE_ADDR' => '102.244.7.7'])->webLogin('password')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);

        Mail::assertQueued(LoginAttackAlertMail::class, fn (LoginAttackAlertMail $mail) => $mail->hasTo($landlord->email) && $mail->blockedUntil !== null);
        $this->assertTrue(LandlordActivity::query()->where('action', 'security.device_blocked')->exists());
    }

    public function test_the_device_block_lifts_after_an_hour_and_a_correct_password_does_not_reset_it(): void
    {
        for ($i = 1; $i <= 29; $i++) {
            $this->webLogin('wrong', "made-up-{$i}");
        }

        // A colleague signing in correctly on the same network clears only their own account.
        $this->webLogin('password')->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->webLogin('wrong', 'made-up-30');

        $this->webLogin('password')->assertSessionHasErrors('username');

        $this->travel(61)->minutes();
        $this->webLogin('password')->assertSessionHasNoErrors();
    }

    public function test_trusted_addresses_are_never_device_blocked(): void
    {
        config(['rate-limits.login_block.trusted_ips' => ['127.0.0.1']]);

        for ($i = 1; $i <= 31; $i++) {
            $this->webLogin('wrong', "made-up-{$i}");
        }

        $this->webLogin('password')->assertSessionHasNoErrors();
    }

    public function test_the_landlord_sees_a_blocked_device_and_can_unblock_it(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->webLogin('wrong', "made-up-{$i}");
        }

        $this->actingAs(User::factory()->create(['is_landlord' => true]));

        $this->get('/landlord/security')->assertInertia(fn (Assert $page) => $page
            ->where('blocked_devices.0.ip_address', '127.0.0.1')
            ->where('blocked_devices.0.accounts', 30)
            ->where('your_ip', '127.0.0.1'));

        $this->post('/landlord/security/unblock-device', ['ip_address' => '127.0.0.1'])->assertSessionHas('success');
        $this->assertSame('security.device_unblocked', LandlordActivity::query()->latest('id')->value('action'));
        $this->post('/landlord/security/unblock-device', ['ip_address' => 'not-an-ip'])->assertSessionHasErrors('ip_address');

        $this->post('/logout');
        $this->webLogin('password')->assertSessionHasNoErrors();
    }

    public function test_the_landlord_sees_blocked_accounts_and_can_unblock(): void
    {
        $this->wrongPasswords(10);
        $landlord = User::factory()->create(['is_landlord' => true]);
        $this->actingAs($landlord);

        $this->get('/landlord/security')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Landlord/Security/Index')
            ->where('summary.blocked_now', 1)
            ->where('blocked.0.user_uuid', $this->user->uuid)
            ->where('blocked.0.failures', 10)
            ->where('recent.0.channel', 'web'));

        $this->post('/landlord/security/unblock', ['user_uuid' => $this->user->uuid, 'identifier' => $this->user->username])->assertSessionHas('success');
        $this->assertSame('security.unblocked', LandlordActivity::query()->latest('id')->value('action'));

        $this->post('/logout');
        $this->webLogin('password')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_only_landlords_open_the_security_page(): void
    {
        $this->actingAs($this->user);

        $this->get('/landlord/security')->assertForbidden();
        $this->post('/landlord/security/unblock', ['identifier' => 'x'])->assertForbidden();
    }

    public function test_failures_are_kept_ninety_days(): void
    {
        $this->wrongPasswords(2);
        $this->travel(91)->days();
        $this->wrongPasswords(1);

        $this->artisan('model:prune', ['--model' => [LoginFailure::class]])->assertSuccessful();

        $this->assertSame(1, LoginFailure::query()->where('user_id', $this->user->id)->count());
    }
}
