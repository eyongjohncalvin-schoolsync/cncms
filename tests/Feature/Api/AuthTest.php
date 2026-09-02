<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Runs against the real local dev Postgres database (see phpunit.xml —
 * sqlite is intentionally NOT used, Stancl schema-per-tenant needs real
 * Postgres). DatabaseTransactions wraps BOTH the central `pgsql`
 * connection and the dynamically-created `tenant` connection (wrapped
 * manually below, since that connection doesn't exist yet when the trait's
 * own beginDatabaseTransaction() runs).
 *
 * `$connectionsToTransact = ['pgsql']` is load-bearing: without it the
 * trait transacts the *default* connection resolved by name `null`, and
 * Stancl's DatabaseTenancyBootstrapper repoints `database.default` at
 * `tenant` the moment setUp() initializes tenancy. The trait opens its
 * transaction on `pgsql` (before tenancy) but, at teardown, rolls back
 * whatever `null` resolves to *then* — `tenant` — leaving the central
 * transaction (every `User::factory()` insert, every `createToken()` row)
 * open on an abandoned connection. That zombie `idle in transaction`
 * backend holds its locks, so the next test that inserts a user with a
 * colliding unique `username`/`email` blocks forever on it. Naming the
 * connection explicitly makes the trait resolve `pgsql` by name at both
 * ends, regardless of where `database.default` points.
 *
 * Tenancy is initialized once, in setUp(), to the single "swecom" tenant
 * and deliberately never ended during the test — see the ResolveTenant
 * class doc for why re-initializing to the same tenant mid-request is a
 * safe no-op, which is what makes this approach work.
 */
class AuthTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find('swecom');

        tenancy()->initialize($tenant);

        DB::connection('tenant')->beginTransaction();

        $this->beforeApplicationDestroyed(function () {
            if (DB::connection('tenant')->transactionLevel() > 0) {
                DB::connection('tenant')->rollBack();
            }
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

    public function test_deactivated_tenant_blocks_the_api_even_with_a_valid_token(): void
    {
        // Same already-committed owner used by the resolves-role test above.
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $user->createToken('api')->plainTextToken;

        $tenant = Tenant::find('swecom');

        try {
            $tenant->update(['is_active' => false]);
            $this->app->make('auth')->forgetGuards();

            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/auth/me')
                ->assertStatus(403)
                ->assertJsonPath('code', 'WORKSPACE_SUSPENDED');
        } finally {
            // Restore explicitly — Tenant writes are central-pinned and may
            // land outside this test's rolled-back transaction.
            Tenant::find('swecom')?->update(['is_active' => true]);
        }
    }

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'username' => 'oldusername',
            'email' => 'old@example.test',
        ]);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'New Name',
                'username' => 'newusername',
                'email' => 'new@example.test',
            ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.username', 'newusername')
            ->assertJsonPath('user.email', 'new@example.test');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('newusername', $user->fresh()->username);
        $this->assertSame('new@example.test', $user->fresh()->email);
    }

    public function test_profile_update_rejects_username_already_taken_by_another_user(): void
    {
        User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create(['username' => 'mine']);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', ['username' => 'taken']);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_profile_update_rejects_email_already_taken_by_another_user(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create(['email' => 'mine@example.test']);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', ['email' => 'taken@example.test']);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_profile_update_allows_a_user_to_keep_their_own_current_username_and_email(): void
    {
        $user = User::factory()->create(['username' => 'mine', 'email' => 'mine@example.test']);
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'Updated Name',
                'username' => 'mine',
                'email' => 'mine@example.test',
            ]);

        $response->assertOk()->assertJsonPath('user.name', 'Updated Name');
    }

    public function test_user_can_change_own_password(): void
    {
        $user = User::factory()->create(); // factory default password is 'password'
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'newpass123',
                'new_password_confirmation' => 'newpass123',
            ]);

        $response->assertOk();

        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'not-the-real-password',
                'new_password' => 'newpass123',
                'new_password_confirmation' => 'newpass123',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('current_password');

        // The password must be unchanged.
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_rejects_a_weak_new_password(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'short',
                'new_password_confirmation' => 'short',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('new_password');
    }

    public function test_password_change_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('api')->plainTextToken;
        $otherToken = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'newpass123',
                'new_password_confirmation' => 'newpass123',
            ]);

        $response->assertOk();

        $this->app->make('auth')->forgetGuards();

        // The current token (the one used to make this request) keeps working.
        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403); // no tenant membership on this factory user — still proves the token authenticates.

        $this->app->make('auth')->forgetGuards();

        // The other token was revoked.
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
