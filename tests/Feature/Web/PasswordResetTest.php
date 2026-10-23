<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Http\Controllers\PasswordResetController;
use App\Mail\SecurityCodeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\SecurityCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Web "Forgot password?" (PasswordResetController): an emailed 6-digit code,
 * then a new password. Generic answers, throttled, passive accounts
 * refused, everything signed out afterwards, and two-factor left on.
 */
class PasswordResetTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTwoFactor;

    private const string NEW_PASSWORD = 'N3w-Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function requestCode(string $identifier): TestResponse
    {
        return $this->post(route('password.email'), ['identifier' => $identifier]);
    }

    private function reset(User $user, string $code, string $password = self::NEW_PASSWORD): TestResponse
    {
        return $this->post(route('password.update'), [
            'identifier' => $user->username,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function insertSession(User $user): void
    {
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => 'reset-test-'.Str::random(30),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_the_forgot_password_page_starts_at_step_one(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword')->where('identifier', null));
    }

    public function test_requesting_a_code_by_username_or_email_emails_a_reset_code(): void
    {
        $user = User::factory()->create();

        $this->requestCode($user->email)
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('success', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHas(PasswordResetController::IDENTIFIER_SESSION_KEY, $user->email);

        Mail::assertQueued(SecurityCodeMail::class, fn (SecurityCodeMail $mail) => $mail->hasTo($user->email)
            && $mail->purpose === SecurityCode::PURPOSE_PASSWORD_RESET
            && str_contains($mail->envelope()->subject, 'password reset code'));

        $this->get('/forgot-password')
            ->assertInertia(fn (Assert $page) => $page->where('identifier', $user->email));
    }

    public function test_an_unknown_account_gets_the_same_answer_and_no_email(): void
    {
        $this->requestCode('nobody-'.Str::random(8))
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('success', PasswordResetController::SENT_MESSAGE);

        Mail::assertNothingQueued();
    }

    public function test_a_valid_code_sets_the_new_password_signs_out_everywhere_and_sends_a_notice(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user);
        $user->createToken('Phone');

        $this->requestCode($user->username);
        $code = $this->lastEmailedCode($user->email);

        $this->reset($user, $code)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'))
            ->assertSessionHas('success')
            ->assertSessionMissing(PasswordResetController::IDENTIFIER_SESSION_KEY);

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, DB::connection('pgsql')->table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, $user->tokens()->count());

        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->hasTo($user->email)
            && $mail->kind === SecurityNoticeMail::PASSWORD_RESET);

        $this->post('/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();

        $this->reset($user, $this->wrongCode($code))->assertSessionHasErrors(['code' => PasswordResetController::INVALID_CODE_MESSAGE]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Mail::assertNotQueued(SecurityNoticeMail::class);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();

        $this->travel(11)->minutes();

        $this->reset($user, $code)->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_code_works_only_once(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();

        $this->reset($user, $code)->assertSessionHasNoErrors();
        $this->reset($user, $code, 'An0ther-Passw0rd!')->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_code_dies_after_five_wrong_guesses(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();

        for ($i = 0; $i < 5; $i++) {
            $this->reset($user, $this->wrongCode($code));
        }

        $this->reset($user, $code)->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_weak_password_is_rejected_without_spending_the_code(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();

        $this->post(route('password.update'), [
            'identifier' => $user->username,
            'code' => $code,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->reset($user, $code)->assertSessionHasNoErrors();
    }

    public function test_a_code_for_another_purpose_cannot_reset_the_password(): void
    {
        $user = User::factory()->create();
        $this->post(route('login-code.send'), ['identifier' => $user->username]);
        $loginCode = $this->lastEmailedCode();

        $this->reset($user, $loginCode)->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_passive_users_get_the_generic_answer_and_cannot_reset(): void
    {
        $user = User::factory()->create(['status' => 'passive']);

        $this->requestCode($user->username)->assertSessionHas('success', PasswordResetController::SENT_MESSAGE);
        Mail::assertNothingQueued();

        // Even a code issued while still active stops working once passive.
        $user->update(['status' => 'active']);
        $this->requestCode($user->username);
        $code = $this->lastEmailedCode();
        $user->update(['status' => 'passive']);

        $this->reset($user, $code)->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_two_factor_stays_on_and_is_still_required_after_a_reset(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->requestCode($user->username);
        $this->reset($user, $this->lastEmailedCode())->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->post('/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_a_google_only_user_can_set_a_password(): void
    {
        $user = User::factory()->create(['password' => Str::random(40)]);

        $this->requestCode($user->email);
        $this->reset($user, $this->lastEmailedCode())->assertSessionHasNoErrors();

        $this->post('/login', ['username' => $user->email, 'password' => self::NEW_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_requesting_codes_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->requestCode($user->username)->assertRedirect(route('password.request'));
        }

        $this->requestCode($user->username)->assertStatus(429);
        Mail::assertQueuedCount(3);
    }

    public function test_submitting_codes_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->reset($user, '000000');
        }

        $this->reset($user, '000000')->assertStatus(429);
    }

    public function test_use_a_different_account_returns_to_step_one(): void
    {
        $this->requestCode('someone');

        $this->delete(route('password.cancel'))
            ->assertRedirect(route('password.request'))
            ->assertSessionMissing(PasswordResetController::IDENTIFIER_SESSION_KEY);
    }

    public function test_signed_in_users_are_sent_away_from_the_forgot_password_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/forgot-password')->assertRedirect();
    }
}
