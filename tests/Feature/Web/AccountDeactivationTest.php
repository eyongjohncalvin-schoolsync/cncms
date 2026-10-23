<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Deactivating a user signs them out everywhere at once
 * (AccountSecurityService::deactivate()), and an already-open session or
 * token of a passive user is refused on its next request — web
 * (EnsureAccountIsActive) and API (Sanctum token check in
 * AppServiceProvider).
 *
 * kelvin (seeded owner) acts as super; patience (seeded member) is the one
 * deactivated.
 */
class AccountDeactivationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $kelvin;

    private User $patience;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->patience = User::query()->where('email', 'patience@shalomtech.dev')->firstOrFail();
        $this->patience->forceFill(['is_landlord' => false, 'status' => 'active'])->save();
        TenantUser::query()->where('user_id', $this->patience->id)->update(['role' => 'admin', 'branch_id' => null]);
    }

    private function insertSession(User $user): void
    {
        DB::connection('pgsql')->table('sessions')->insert([
            'id' => 'deactivate-test-'.Str::random(30),
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function sessionCount(User $user): int
    {
        return DB::connection('pgsql')->table('sessions')->where('user_id', $user->id)->count();
    }

    public function test_deactivating_a_user_ends_their_sessions_and_revokes_their_tokens(): void
    {
        $this->insertSession($this->patience);
        $this->insertSession($this->patience);
        $this->patience->createToken('Phone');
        $this->insertSession($this->kelvin);
        $kelvinSessions = $this->sessionCount($this->kelvin);

        TenantUser::query()->where('user_id', $this->kelvin->id)->update(['role' => 'super']);
        $patienceMembership = TenantUser::query()->where('user_id', $this->patience->id)->firstOrFail();

        $this->actingAs($this->kelvin)
            ->post("/users/{$patienceMembership->id}/deactivate")
            ->assertRedirect(route('users.index'));

        $this->assertSame('passive', $this->patience->fresh()->status);
        $this->assertSame(0, $this->sessionCount($this->patience));
        $this->assertSame(0, $this->patience->tokens()->count());

        // Only the deactivated person is signed out.
        $this->assertSame($kelvinSessions, $this->sessionCount($this->kelvin));
    }

    public function test_an_open_web_session_of_a_passive_user_is_refused_on_its_next_request(): void
    {
        $this->actingAs($this->patience)->get('/dashboard')->assertOk();

        $this->patience->forceFill(['status' => 'passive'])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => EnsureAccountIsActive::MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_passive_users_open_session_is_refused_on_account_routes_too(): void
    {
        $this->actingAs($this->patience)->get('/account')->assertOk();

        $this->patience->forceFill(['status' => 'passive'])->save();

        $this->patch('/account/profile', ['name' => 'Changed'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame('Changed', $this->patience->fresh()->name);
    }

    public function test_a_json_request_from_a_passive_users_session_gets_401(): void
    {
        $this->actingAs($this->patience);
        $this->patience->forceFill(['status' => 'passive'])->save();

        $this->getJson('/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_a_passive_users_api_token_stops_working(): void
    {
        $token = $this->patience->createToken('Phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/security')->assertOk();

        $this->patience->forceFill(['status' => 'passive'])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/security')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_active_users_tokens_keep_working(): void
    {
        $token = $this->patience->createToken('Phone')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_an_impersonation_of_a_user_who_becomes_passive_returns_the_landlord_to_their_own_account(): void
    {
        $this->kelvin->forceFill(['is_landlord' => true])->save();

        $this->actingAs($this->kelvin)
            ->post("/landlord/impersonate/{$this->patience->uuid}")
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->patience);

        $this->patience->forceFill(['status' => 'passive'])->save();

        // A real next request loads the user afresh from the session.
        $this->app['auth']->forgetGuards();

        $this->get('/dashboard')
            ->assertRedirect(route('landlord.users.index'))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->kelvin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
    }
}
