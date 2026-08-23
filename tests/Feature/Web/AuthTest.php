<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
