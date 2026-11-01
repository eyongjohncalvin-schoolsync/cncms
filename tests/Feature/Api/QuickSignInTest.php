<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fingerprint sign-in on the phone (App\Services\QuickSignInService): a
 * key that only buys a token, survives "Log out", and dies with the
 * account's other tokens. Same tenant/transaction setup as AuthTest.
 */
class QuickSignInTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        tenancy()->initialize(Tenant::find('swecom'));

        DB::connection('tenant')->beginTransaction();

        $this->beforeApplicationDestroyed(function () {
            if (DB::connection('tenant')->transactionLevel() > 0) {
                DB::connection('tenant')->rollBack();
            }
        });
    }

    /**
     * @return array{0: User, 1: string, 2: array{id: int, key: string}}
     */
    private function signedInWithKey(string $device = 'Pixel 7'): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $quick = $this->withToken($token)
            ->postJson('/api/v1/auth/quick-sign-in', ['device_name' => $device])
            ->assertCreated()
            ->assertJsonStructure(['id', 'key'])
            ->json();

        $this->forgetAuth();

        return [$user, $token, $quick];
    }

    private function forgetAuth(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->flushHeaders();
    }

    public function test_the_key_signs_in_without_a_password(): void
    {
        [$user, , $quick] = $this->signedInWithKey();

        $token = $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key'], 'device_name' => 'Pixel 7'])
            ->assertOk()
            ->assertJsonPath('user.uuid', $user->uuid)
            ->json('token');

        $this->forgetAuth();
        $this->withToken($token)->getJson('/api/v1/auth/security')->assertOk();
    }

    public function test_the_key_itself_can_never_call_the_api(): void
    {
        [, , $quick] = $this->signedInWithKey();

        $this->withToken($quick['key'])->getJson('/api/v1/auth/security')->assertUnauthorized();
    }

    public function test_an_unknown_key_or_an_ordinary_token_is_refused(): void
    {
        $this->postJson('/api/v1/auth/quick-login', ['key' => '999|not-a-real-key'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'QUICK_SIGN_IN_INVALID');

        $ordinary = User::factory()->create()->createToken('api')->plainTextToken;

        $this->postJson('/api/v1/auth/quick-login', ['key' => $ordinary])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'QUICK_SIGN_IN_INVALID');
    }

    public function test_log_out_keeps_the_key_but_signing_out_other_devices_ends_it(): void
    {
        [$user, $token, $quick] = $this->signedInWithKey();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->forgetAuth();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key']])->assertOk();

        $other = $user->createToken('api')->plainTextToken;
        $this->forgetAuth();
        $this->withToken($other)->deleteJson('/api/v1/auth/sessions', ['password' => 'password'])->assertOk();
        $this->forgetAuth();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key']])->assertUnauthorized();
    }

    public function test_turning_it_off_ends_the_key_and_only_the_owner_can(): void
    {
        [$user, $token, $quick] = $this->signedInWithKey();

        $stranger = User::factory()->create()->createToken('api')->plainTextToken;
        $this->withToken($stranger)->deleteJson("/api/v1/auth/quick-sign-in/{$quick['id']}")->assertOk();
        $this->forgetAuth();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key']])->assertOk();
        $this->forgetAuth();

        $this->withToken($token)->deleteJson("/api/v1/auth/quick-sign-in/{$quick['id']}")->assertOk();
        $this->forgetAuth();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key']])->assertUnauthorized();
    }

    public function test_a_new_key_for_the_same_phone_replaces_the_old_one(): void
    {
        [$user, $token, $first] = $this->signedInWithKey('Pixel 7');

        $this->withToken($token)->postJson('/api/v1/auth/quick-sign-in', ['device_name' => 'Pixel 7'])->assertCreated();
        $this->forgetAuth();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $first['key']])->assertUnauthorized();
    }

    public function test_a_deactivated_account_cannot_use_its_key(): void
    {
        [$user, , $quick] = $this->signedInWithKey();

        $user->forceFill(['status' => 'passive'])->save();

        $this->postJson('/api/v1/auth/quick-login', ['key' => $quick['key']])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }
}
