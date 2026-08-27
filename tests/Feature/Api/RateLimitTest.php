<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Exercises the real `throttle:login` middleware (RateLimiter::for('login',
 * ...) registered in App\Providers\AppServiceProvider) rather than stubbing
 * it out with withoutMiddleware() — that would defeat the point of this
 * test. No DatabaseTransactions/tenant setup needed: invalid-credential
 * login attempts never reach the tenant lookup in
 * Api\AuthController::login().
 *
 * CACHE_STORE is "array" in testing (phpunit.xml), and Laravel boots a
 * fresh Application — and therefore a fresh, empty array cache/rate-limiter
 * store — per test method, so there is no rate-limit leakage between test
 * methods to guard against here.
 */
class RateLimitTest extends TestCase
{
    public function test_login_is_rate_limited_after_5_attempts_per_minute(): void
    {
        $payload = [
            'email' => 'nobody@example.test',
            'password' => 'wrong-password',
        ];

        // The 5 allowed attempts (config('rate-limits.login.max_attempts'))
        // each fail authentication normally.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
        }

        // The 6th attempt within the same window must be throttled, not
        // authenticated-rejected.
        $response = $this->postJson('/api/v1/auth/login', $payload);

        $response->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    public function test_rate_limit_config_matches_the_documented_api_spec(): void
    {
        // api-spec.md section 11 — the documented target limits this
        // feature implements. See config/rate-limits.php.
        $this->assertSame(5, config('rate-limits.login.max_attempts'));
        $this->assertSame(1, config('rate-limits.login.decay_minutes'));

        $this->assertSame(60, config('rate-limits.sync.max_attempts'));
        $this->assertSame(1, config('rate-limits.sync.decay_minutes'));

        $this->assertSame(120, config('rate-limits.api.max_attempts'));
        $this->assertSame(1, config('rate-limits.api.decay_minutes'));

        $this->assertSame(10, config('rate-limits.exports.max_attempts'));
        $this->assertSame(1, config('rate-limits.exports.decay_minutes'));

        $this->assertSame(30, config('rate-limits.audit.max_attempts'));
        $this->assertSame(1, config('rate-limits.audit.decay_minutes'));
    }
}
