<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Session-cookie web login (App\Http\Controllers\AuthController), distinct
 * from the Sanctum bearer-token API login covered by
 * tests/Feature/Api/AuthTest.php. Reuses the real seeded owner
 * (kelvin@shalomtech.dev / password), same as the API tests, rather than
 * creating a throwaway user.
 */
class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'username' => 'kelvin@shalomtech.dev',
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail());
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->post('/login', [
            'username' => 'kelvin@shalomtech.dev',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_a_logged_in_user_can_log_out(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_guests_are_redirected_away_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    /**
     * Reproduces the exact reported bug's residual, non-exploitable-today
     * footgun (see AuthController::store()'s `url.intended` guard):
     * log in as a landlord user, log out, then — on the SAME browser
     * session, before anyone logs in again — a stray/background request
     * hits the landlord-only area. Laravel's Authenticate middleware sets
     * `url.intended` on that request's (post-logout, still-anonymous)
     * session. A completely different, non-landlord user then logs in on
     * that same browser. Before the fix, `redirect()->intended()` would
     * send this new, non-landlord user's post-login redirect straight at
     * the landlord area (EnsureLandlord already 403s the *page* if they
     * land on it — this test is about the *redirect target* itself never
     * being computed that way to begin with).
     */
    public function test_a_stray_landlord_probe_between_logout_and_the_next_login_does_not_leak_into_that_logins_redirect_target(): void
    {
        $landlord = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $landlord->forceFill(['is_landlord' => true])->save();

        $otherNonLandlordUser = User::factory()->create();

        // 1. Log in as the landlord user and confirm the landlord area is
        // actually reachable (mirrors the live trace's first assertion).
        $this->post('/login', [
            'username' => $landlord->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->get('/landlord/tenants')->assertOk();

        // 2. Log out. Session is fully invalidated.
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        // 3. Before anyone logs in again, something on this same browser
        // probes the landlord-only page again. Authenticate middleware
        // sets `url.intended` on this fresh, still-anonymous session.
        $this->get('/landlord/tenants')->assertRedirect('/login');

        // 4. A different, non-landlord user now logs in on that same
        // browser/session. The redirect target must be the ordinary
        // dashboard, never the landlord area this user has no access to.
        $loginResponse = $this->post('/login', [
            'username' => $otherNonLandlordUser->email,
            'password' => 'password',
        ]);

        $loginResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($otherNonLandlordUser);
    }

    /**
     * Confirms Fix 1's Inertia::clearHistory() call in AuthController::
     * destroy() genuinely reaches the client: per the Inertia protocol
     * (vendor/inertiajs/inertia-laravel/src/Response.php's
     * resolveClearHistory()), a page response that should clear the
     * browser's cached history.state carries `clearHistory: true` in its
     * page object. That flag is written to the session by
     * Inertia::clearHistory() and consumed by the *next* Inertia response
     * — i.e. the GET /login the client's router follows after the POST
     * /logout redirect — so this test follows that same two-step sequence
     * rather than asserting on the logout response itself.
     */
    public function test_logging_out_instructs_the_next_page_load_to_clear_inertias_history_cache(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $response = $this->get('/login')->assertOk();

        // AssertableInertia has no dedicated hasClearHistory() assertion —
        // only Response::resolveClearHistory() (server) exposes the flag,
        // and AssertableInertia::toArray() is the one public accessor that
        // surfaces it on the test side (it only appears in the array when
        // true — see Testing/AssertableInertia.php's toArray()).
        $page = Assert::fromTestResponse($response)->toArray();

        $this->assertTrue(
            $page['clearHistory'] ?? false,
            'The first Inertia page rendered after logout should carry clearHistory: true so the browser purges its cached history.state.'
        );
    }

    /**
     * Fix 3: Auth::attempt() no longer hardcodes "remember me" to true.
     * Auth/Login.tsx has no remember-me checkbox, so there's no user intent
     * to honor — a successful login should not queue a long-lived recaller
     * cookie the user never asked for.
     */
    public function test_logging_in_does_not_issue_a_persistent_remember_me_cookie(): void
    {
        $response = $this->post('/login', [
            'username' => 'kelvin@shalomtech.dev',
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');

        $rememberCookieName = Auth::guard()->getRecallerName();

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame(
                $rememberCookieName,
                $cookie->getName(),
                'Login should not queue a remember-me recaller cookie without explicit user intent.'
            );
        }
    }
}
