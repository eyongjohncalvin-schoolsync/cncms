<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\PasswordResetController;
use App\Mail\SecurityCodeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\SecurityCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * API "Forgot password?" (POST /api/v1/auth/password/forgot and
 * /auth/password/reset), for a later mobile build.
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

    private function reset(User $user, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => $user->email,
            'code' => $code,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }

    public function test_forgot_answers_the_same_for_known_unknown_and_passive_accounts(): void
    {
        $user = User::factory()->create();
        $passive = User::factory()->create(['status' => 'passive']);

        foreach ([$user->username, 'nobody-'.Str::random(8), $passive->username] as $identifier) {
            $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $identifier])
                ->assertOk()
                ->assertExactJson(['message' => PasswordResetController::SENT_MESSAGE]);
        }

        Mail::assertQueuedCount(1);
        Mail::assertQueued(SecurityCodeMail::class, fn (SecurityCodeMail $mail) => $mail->hasTo($user->email)
            && $mail->purpose === SecurityCode::PURPOSE_PASSWORD_RESET);
    }

    public function test_reset_sets_the_password_revokes_every_token_and_issues_none(): void
    {
        $user = User::factory()->create();
        $user->createToken('Phone 1');
        $user->createToken('Phone 2');

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $user->email]);

        $this->reset($user, $this->lastEmailedCode())
            ->assertOk()
            ->assertJsonMissingPath('token');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        Mail::assertQueued(SecurityNoticeMail::class, fn (SecurityNoticeMail $mail) => $mail->kind === SecurityNoticeMail::PASSWORD_RESET);

        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_a_wrong_or_reused_code_gets_invalid_code(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $user->email]);
        $code = $this->lastEmailedCode();

        $this->reset($user, $this->wrongCode($code))->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
        $this->reset($user, $code)->assertOk();
        $this->reset($user, $code)->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_an_unknown_account_gets_the_same_invalid_code_answer(): void
    {
        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => 'nobody-'.Str::random(8),
            'code' => '123456',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_weak_passwords_fail_validation(): void
    {
        $this->postJson('/api/v1/auth/password/reset', [
            'identifier' => 'someone',
            'code' => '123456',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_two_factor_is_still_required_after_a_reset(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $user->email]);
        $this->reset($user, $this->lastEmailedCode())->assertOk();

        $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token');
    }

    public function test_forgot_and_reset_are_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $user->username])->assertOk();
        }
        $this->postJson('/api/v1/auth/password/forgot', ['identifier' => $user->username])->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->reset($user, '000000');
        }
        $this->reset($user, '000000')->assertStatus(429);
    }
}
