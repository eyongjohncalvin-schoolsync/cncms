<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs against the real local dev Postgres database (see phpunit.xml —
 * sqlite is intentionally NOT used, Stancl schema-per-tenant needs real
 * Postgres). DatabaseTransactions wraps BOTH the central `pgsql`
 * connection (opened automatically by the trait, since it's the default
 * connection before tenancy is touched) and the dynamically-created
 * `tenant` connection (wrapped manually below, since that connection
 * doesn't exist yet when the trait's own beginDatabaseTransaction() runs).
 * Tenancy is initialized once, in setUp(), to the single "swecom" tenant
 * and deliberately never ended during the test — see the ResolveTenant
 * class doc for why re-initializing to the same tenant mid-request is a
 * safe no-op, which is what makes this approach work.
 */
class AuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find('swecom');

        tenancy()->initialize($tenant);

        DB::connection('tenant')->beginTransaction();

        $this->beforeApplicationDestroyed(function () {
            DB::connection('tenant')->rollBack();
        });
    }

    public function test_login_issues_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'agent01@example.test',
            'username' => 'agent01',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent01@example.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.uuid', $user->uuid)
            ->assertJsonStructure(['user' => ['uuid', 'name', 'username', 'email', 'role'], 'token']);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'agent02@example.test',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent02@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()->assertJson(['message' => 'Logged out']);

        // Laravel's "sanctum" RequestGuard caches the resolved user for the
        // lifetime of the guard singleton, which — inside a single test
        // method — persists across every simulated request (the app
        // container isn't rebuilt between calls). Forget the cached guard
        // so this second call actually re-resolves the (now revoked) token
        // instead of returning the cached result from the request above.
        $this->app->make('auth')->forgetGuards();

        // The revoked token must no longer authenticate.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_authenticated_user_with_tenant_membership_resolves_role(): void
    {
        // Uses the already-seeded owner (kelvin@shalomtech.dev, role=super
        // for tenant swecom) rather than creating a fresh user + TenantUser
        // row here: TenantUser lives on the separate `tenant` connection
        // (a distinct Postgres session from `pgsql`), so a freshly-created
        // central User row — still uncommitted inside this test's `pgsql`
        // transaction — is not yet visible to that session, and inserting
        // a TenantUser row referencing it trips the cross-schema foreign
        // key (tenant_users_user_id_foreign -> public.users). Reusing an
        // already-committed row sidesteps that cross-connection visibility
        // gap entirely.
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('role', 'super')
            ->assertJsonPath('user.uuid', $user->uuid);
    }

    public function test_authenticated_user_without_tenant_membership_is_forbidden(): void
    {
        // Deliberately no TenantUser row created for this user.
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }
}
