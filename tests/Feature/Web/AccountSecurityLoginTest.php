<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Mail\SecurityCodeMail;
use App\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Web sign-in with account security: the two-factor challenge after a
 * password / Google / email-code first factor, passwordless email-code
 * sign-in, and passive accounts refused on every path. Factory users have
 * no workspace, so a completed sign-in lands on /dashboard (intended).
 */
class AccountSecurityLoginTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTwoFactor;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function passwordLogin(User $user): TestResponse
    {
        return $this->post('/login', ['username' => $user->username, 'password' => 'password']);
    }

    public function test_password_login_without_two_factor_signs_in_directly(): void
    {
        $user = User::factory()->create();

        $this->passwordLogin($user)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_login_with_two_factor_requires_the_challenge_and_does_not_sign_in(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->passwordLogin($user)->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertNotNull(session('login.challenge_token'));

        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/TwoFactorChallenge')
                ->where('methods', ['totp', 'recovery', 'email']));
    }

    public function test_a_valid_authenticator_code_completes_the_login(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $this->passwordLogin($user);

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('login.challenge_token'));
    }

    public function test_a_wrong_authenticator_code_is_refused(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $this->passwordLogin($user);

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->wrongCode($this->totpCode($secret))])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_same_authenticator_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];
        $code = $this->totpCode($secret);

        $this->passwordLogin($user);
        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $code])->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->passwordLogin($user);
        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_too_many_wrong_codes_end_the_challenge(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];
        $wrong = $this->wrongCode($this->totpCode($secret));

        $this->passwordLogin($user);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $wrong])
            ->assertRedirect(route('login'));

        // Even the right code no longer helps: start over.
        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_expired_challenge_sends_the_user_back_to_login(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $this->passwordLogin($user);

        $this->travel(11)->minutes();

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_challenge_page_without_a_pending_login_redirects_to_login(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = User::factory()->create();
        $recoveryCode = $this->enableTwoFactor($user)['recovery_codes'][0];

        $this->passwordLogin($user);
        $this->post(route('two-factor.challenge.store'), ['method' => 'recovery', 'code' => strtolower($recoveryCode)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(2, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');

        $this->passwordLogin($user);
        $this->post(route('two-factor.challenge.store'), ['method' => 'recovery', 'code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_after_a_password_the_second_factor_can_be_an_emailed_code(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->passwordLogin($user);

        $this->post(route('two-factor.challenge.email'))->assertSessionHas('success');
        $code = $this->lastEmailedCode($user->email);

        $this->post(route('two-factor.challenge.store'), ['method' => 'email', 'code' => $code])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_google_login_with_two_factor_requires_the_challenge(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        Socialite::fake('google', SocialiteUser::fake(['email' => $user->email, 'name' => $user->name]));

        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_passive_users_cannot_log_in_with_a_password_or_google(): void
    {
        $user = User::factory()->create(['status' => 'passive']);

        $this->passwordLogin($user)->assertSessionHasErrors('username');
        $this->assertGuest();

        Socialite::fake('google', SocialiteUser::fake(['email' => $user->email, 'name' => $user->name]));

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_email_code_login_signs_in_a_user_without_two_factor(): void
    {
        $user = User::factory()->create();

        $this->get(route('login-code.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/LoginCode'));

        $this->post(route('login-code.send'), ['identifier' => $user->email])
            ->assertRedirect(route('login-code.create'))
            ->assertSessionHas('success');

        $code = $this->lastEmailedCode($user->email);

        $this->post(route('login-code.verify'), ['identifier' => $user->email, 'code' => $code])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_code_login_gives_the_same_answer_for_unknown_accounts_and_sends_nothing(): void
    {
        $this->post(route('login-code.send'), ['identifier' => 'nobody-at-all@example.test'])
            ->assertRedirect(route('login-code.create'))
            ->assertSessionHas('success', 'If that account exists, we have emailed it a 6-digit code. It expires in 10 minutes.');

        Mail::assertNothingQueued();
    }

    public function test_email_code_login_refuses_passive_users_and_sends_nothing(): void
    {
        $user = User::factory()->create(['status' => 'passive']);

        $this->post(route('login-code.send'), ['identifier' => $user->username])->assertSessionHas('success');

        Mail::assertNothingQueued();

        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_email_code_login_rejects_wrong_expired_and_reused_codes(): void
    {
        $user = User::factory()->create();

        $this->post(route('login-code.send'), ['identifier' => $user->username]);
        $code = $this->lastEmailedCode();

        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $this->wrongCode($code)])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $code])->assertRedirect('/dashboard');
        $this->post('/logout');

        // Reused.
        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        // Expired.
        $this->post(route('login-code.send'), ['identifier' => $user->username]);
        $fresh = $this->lastEmailedCode();
        $this->travel(11)->minutes();

        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $fresh])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_an_emailed_code_dies_after_five_wrong_attempts(): void
    {
        $user = User::factory()->create();

        $this->post(route('login-code.send'), ['identifier' => $user->username]);
        $code = $this->lastEmailedCode();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $this->wrongCode($code)]);
        }

        $this->post(route('login-code.verify'), ['identifier' => $user->username, 'code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_sending_codes_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login-code.send'), ['identifier' => $user->username])->assertRedirect(route('login-code.create'));
        }

        $this->post(route('login-code.send'), ['identifier' => $user->username])->assertStatus(429);

        Mail::assertQueuedCount(3);
    }

    public function test_email_code_login_does_not_bypass_the_authenticator_step(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user)['secret'];

        $this->post(route('login-code.send'), ['identifier' => $user->email]);
        $code = $this->lastEmailedCode();

        $this->post(route('login-code.verify'), ['identifier' => $user->email, 'code' => $code])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // The emailed-code route can't serve as the second factor too.
        $this->get(route('two-factor.challenge'))
            ->assertInertia(fn (Assert $page) => $page->where('methods', ['totp', 'recovery']));
        $this->post(route('two-factor.challenge.email'))->assertSessionHasErrors('code');
        Mail::assertQueued(SecurityCodeMail::class, 1);

        $this->post(route('two-factor.challenge.store'), ['method' => 'email', 'code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.challenge.store'), ['method' => 'totp', 'code' => $this->totpCode($secret)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(LoginChallenge::FACTOR_EMAIL_CODE, LoginChallenge::query()->where('user_id', $user->id)->value('first_factor'));
    }
}
