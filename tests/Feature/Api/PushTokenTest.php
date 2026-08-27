<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DevicePushToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * POST /api/v1/devices/push-token — App\Http\Controllers\Api\PushTokenController.
 * Mirrors NotificationTest.php's shape (DatabaseTransactions +
 * InteractsWithTenantRoles against the real "swecom" tenant).
 */
class PushTokenTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_registering_a_device_creates_a_push_token_row(): void
    {
        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-abc',
                'expo_push_token' => 'ExponentPushToken[abc123]',
                'platform' => 'android',
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('uuid'));
        $this->assertNotNull($response->json('registered_at'));

        $this->assertDatabaseHas('device_push_tokens', [
            'device_id' => 'test-device-abc',
            'expo_push_token' => 'ExponentPushToken[abc123]',
            'platform' => 'android',
            'is_valid' => true,
        ], 'tenant');
    }

    public function test_registering_the_same_device_twice_upserts_rather_than_duplicating(): void
    {
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-upsert',
                'expo_push_token' => 'ExponentPushToken[first]',
                'platform' => 'android',
            ])->assertCreated();

        // Simulates a token rotation (e.g. after a reinstall) for the same
        // physical device — must replace, not duplicate.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-upsert',
                'expo_push_token' => 'ExponentPushToken[second]',
                'platform' => 'android',
            ])->assertCreated();

        $this->assertSame(1, DevicePushToken::query()->where('device_id', 'test-device-upsert')->count());
        $this->assertSame('ExponentPushToken[second]', DevicePushToken::query()->where('device_id', 'test-device-upsert')->value('expo_push_token'));
    }

    public function test_a_fresh_registration_revalidates_a_previously_invalidated_token(): void
    {
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-revalidate',
                'expo_push_token' => 'ExponentPushToken[stale]',
                'platform' => 'ios',
            ])->assertCreated();

        DevicePushToken::query()->where('device_id', 'test-device-revalidate')->update(['is_valid' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-revalidate',
                'expo_push_token' => 'ExponentPushToken[fresh]',
                'platform' => 'ios',
            ])->assertCreated();

        $this->assertTrue((bool) DevicePushToken::query()->where('device_id', 'test-device-revalidate')->value('is_valid'));
    }

    public function test_registering_a_device_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/devices/push-token', [
            'device_id' => 'test-device-unauth',
            'expo_push_token' => 'ExponentPushToken[abc]',
            'platform' => 'android',
        ]);

        $response->assertUnauthorized();
    }

    public function test_registering_a_device_validates_required_fields(): void
    {
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id', 'expo_push_token', 'platform']);
    }

    public function test_registering_a_device_rejects_an_unknown_platform(): void
    {
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/devices/push-token', [
                'device_id' => 'test-device-badplatform',
                'expo_push_token' => 'ExponentPushToken[abc]',
                'platform' => 'windows',
            ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['platform']);
    }
}
