<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\SecurityCodeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Mobile API account security: the two-factor login challenge, email-code
 * sign-in, passive accounts, email change with a code, authenticator setup
 * and sessions/devices. Factory users (no workspace) are enough — none of
 * these endpoints need a tenant.
 */
class AccountSecurityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;
    use InteractsWithTwoFactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
    }

    private function bearer(User $user, string $name = 'api'): string
    {
        return 'Bearer '.$user->createToken($name)->plainTextToken;
    }

    public function test_login_without_two_factor_issues_a_token_named_after_the_device(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password', 'device_name' => 'Tecno Spark 10'])
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $this->assertSame('Tecno Spark 10', $user->tokens()->value('name'));
    }

    public function test_login_with_two_factor_returns_a_challenge_and_no_token(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('methods', ['totp', 'recovery', 'email'])
            ->assertJsonMissingPath('token')
            ->assertJsonStructure(['challenge_token', 'email_hint']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_challenge_issues_a_token_for_a_valid_authenticator_code_once(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->wrongCode($this->totpCode($secret))])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CODE');

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->totpCode($secret), 'device_name' => 'Pixel'])
            ->assertOk()
            ->assertJsonStructure(['user' => ['uuid'], 'token']);

        $this->assertSame(1, $user->tokens()->count());

        // Single use.
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->totpCode($secret, 1)])
            ->assertStatus(410)
            ->assertJsonPath('code', 'CHALLENGE_EXPIRED');
    }

    public function test_the_challenge_expires_and_locks_after_too_many_attempts(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];
        $wrong = $this->wrongCode($this->totpCode($secret));

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $wrong])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $wrong])->assertStatus(410);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->totpCode($secret)])->assertStatus(410);

        $expired = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');
        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $expired, 'method' => 'totp', 'code' => $this->totpCode($secret)])->assertStatus(410);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_recovery_codes_and_emailed_codes_satisfy_the_challenge(): void
    {
        $user = User::factory()->create();
        $recovery = $this->enableTwoFactor($user)['recovery_codes'][1];

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'recovery', 'code' => $recovery])->assertOk();

        $again = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $again, 'method' => 'recovery', 'code' => $recovery])->assertStatus(422);

        $this->postJson('/api/v1/auth/two-factor/email', ['challenge_token' => $again])->assertOk();
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $again, 'method' => 'email', 'code' => $this->lastEmailedCode()])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_email_code_login_issues_a_token_and_never_reveals_unknown_accounts(): void
    {
        $user = User::factory()->create();

        $unknown = $this->postJson('/api/v1/auth/login-code', ['identifier' => 'ghost@example.test'])->assertOk()->json('message');
        Mail::assertNothingQueued();

        $known = $this->postJson('/api/v1/auth/login-code', ['identifier' => $user->email])->assertOk()->json('message');
        $this->assertSame($unknown, $known);

        $code = $this->lastEmailedCode($user->email);

        $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->email, 'code' => $this->wrongCode($code)])->assertStatus(422);
        $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->email, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->email, 'code' => $code])->assertStatus(422);
    }

    public function test_email_code_login_with_two_factor_still_needs_the_authenticator(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $this->postJson('/api/v1/auth/login-code', ['identifier' => $user->username]);
        $code = $this->lastEmailedCode();

        $challenge = $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->username, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('methods', ['totp', 'recovery'])
            ->assertJsonMissingPath('token')
            ->json('challenge_token');

        $this->postJson('/api/v1/auth/two-factor/email', ['challenge_token' => $challenge])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'email', 'code' => $code])->assertStatus(422);

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_passive_users_cannot_log_in_by_password_or_code(): void
    {
        $user = User::factory()->create(['status' => 'passive']);

        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');

        $this->postJson('/api/v1/auth/login-code', ['identifier' => $user->username])->assertOk();
        Mail::assertNothingQueued();

        $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->username, 'code' => '123456'])->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_user_deactivated_mid_challenge_cannot_finish_signing_in(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_token');
        $user->update(['status' => 'passive']);

        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'method' => 'totp', 'code' => $this->totpCode($secret)])->assertStatus(410);
    }

    public function test_code_sending_and_verifying_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login-code', ['identifier' => $user->username])->assertOk();
        }
        $this->postJson('/api/v1/auth/login-code', ['identifier' => $user->username])->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->username, 'code' => '000000']);
        }
        $this->postJson('/api/v1/auth/login-code/verify', ['identifier' => $user->username, 'code' => '000000'])->assertStatus(429);
    }

    public function test_email_change_is_confirmed_with_a_code_to_the_new_address(): void
    {
        $user = User::factory()->create(['email' => 'before@example.test']);
        $auth = $this->bearer($user);

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/auth/email', ['email' => 'after@example.test', 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/auth/email', ['email' => 'after@example.test', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('pending_email', 'after@example.test');

        $code = $this->lastEmailedCode('after@example.test');

        $this->withHeader('Authorization', $auth)->getJson('/api/v1/auth/security')->assertJsonPath('pending_email', 'after@example.test');

        $this->withHeader('Authorization', $auth)->postJson('/api/v1/auth/email/confirm', ['code' => $this->wrongCode($code)])->assertStatus(422);
        $this->withHeader('Authorization', $auth)->postJson('/api/v1/auth/email/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('email', 'after@example.test');

        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::EMAIL_CHANGED && $mail->hasTo('before@example.test'));
    }

    public function test_two_factor_setup_confirm_regenerate_and_disable(): void
    {
        $user = User::factory()->create();
        $auth = $this->bearer($user);

        $setup = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/auth/two-factor/setup')
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url', 'qr_code'])
            ->json();

        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_url']);

        $codes = $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($setup['secret'], Totp::timeStep())])
            ->assertOk()
            ->json('recovery_codes');

        $this->assertCount(8, $codes);
        $this->withHeader('Authorization', $auth)->getJson('/api/v1/auth/security')->assertJsonPath('two_factor.enabled', true);
        $this->withHeader('Authorization', $auth)->postJson('/api/v1/auth/two-factor/setup')->assertStatus(409);

        $this->withHeader('Authorization', $auth)
            ->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'password'])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes');

        $this->withHeader('Authorization', $auth)
            ->deleteJson('/api/v1/auth/two-factor', ['password' => 'password', 'code' => $codes[0]])
            ->assertStatus(422);

        $this->withHeader('Authorization', $auth)
            ->deleteJson('/api/v1/auth/two-factor', ['password' => 'password', 'code' => Totp::codeAt($setup['secret'], Totp::timeStep() + 1)])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        Mail::assertQueued(SecurityNoticeMail::class, 3);
    }

    public function test_sessions_lists_devices_and_signs_out_everything_else(): void
    {
        $user = User::factory()->create();
        $auth = $this->bearer($user, 'This phone');
        $user->createToken('Old phone');
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => 'api-test-session-'.str_repeat('q', 20),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Firefox',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->withHeader('Authorization', $auth)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonCount(2, 'devices')
            ->assertJsonFragment(['name' => 'This phone', 'is_current' => true]);

        $this->withHeader('Authorization', $auth)->deleteJson('/api/v1/auth/sessions', ['password' => 'wrong'])->assertStatus(422);
        $this->withHeader('Authorization', $auth)->deleteJson('/api/v1/auth/sessions', ['password' => 'password'])->assertOk();

        $this->assertSame(['This phone'], $user->tokens()->pluck('name')->all());
        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_api_password_change_sends_a_notice_and_ends_web_sessions(): void
    {
        $user = User::factory()->create();
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => 'api-pw-session-'.str_repeat('r', 20),
            'user_id' => $user->id,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->withHeader('Authorization', $this->bearer($user))
            ->patchJson('/api/v1/auth/password', ['current_password' => 'password', 'new_password' => 'newpass123', 'new_password_confirmation' => 'newpass123'])
            ->assertOk();

        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $user->id)->count());
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::PASSWORD_CHANGED);
        Mail::assertNotQueued(SecurityCodeMail::class);
    }
}
