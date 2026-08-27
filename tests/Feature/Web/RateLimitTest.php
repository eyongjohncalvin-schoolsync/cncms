<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Tests\TestCase;

/**
 * Exercises the real `throttle:login` middleware on the web session login
 * (App\Http\Controllers\AuthController::store) — shared with the API login
 * limiter, see App\Providers\AppServiceProvider::configureRateLimiting().
 * Also confirms the Inertia-aware 429 handling added in bootstrap/app.php:
 * an Inertia request (X-Inertia header set) that gets throttled is
 * redirected back with a flash `error` prop instead of a raw error page,
 * matching how this app already treats 419 CSRF mismatches.
 *
 * Not using withoutMiddleware() — that would defeat the point of this
 * test. CACHE_STORE is "array" in testing (phpunit.xml), and Laravel boots
 * a fresh Application (and therefore a fresh rate-limiter store) per test
 * method, so there is no leakage between test methods.
 */
class RateLimitTest extends TestCase
{
    private function invalidLoginPayload(): array
    {
        return [
            'username' => 'nobody@example.test',
            'password' => 'wrong-password',
        ];
    }

    public function test_login_is_rate_limited_after_5_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $this->invalidLoginPayload())
                ->assertSessionHasErrors('username');
        }

        $response = $this->post('/login', $this->invalidLoginPayload());

        $response->assertStatus(429);
    }

    public function test_a_throttled_inertia_request_is_redirected_back_with_a_flash_error(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-Inertia' => 'true'])
                ->post('/login', $this->invalidLoginPayload());
        }

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/login', $this->invalidLoginPayload());

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
