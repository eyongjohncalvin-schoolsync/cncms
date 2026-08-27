<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\NotificationRecipient;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * JSON API counterpart of tests/Feature/Web/NotificationTest.php, covering
 * only App\Http\Controllers\Api\NotificationController::acknowledge() — the
 * one action the mobile emergency-interrupt screen needs to be a real
 * online action (complaint-desk.md section 7 / in-app-notifications.md
 * section 6). mark-read/mark-all-read stay web-only, so there is nothing
 * else to cover here for v1 (see that controller's class doc).
 */
class NotificationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    public function test_acknowledging_an_emergency_notification_sets_acknowledged_at_and_returns_it(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $this->tokenForRole('manager');

        $notification = app(NotificationService::class)->broadcastToUser($user, 'test.api_ack', 'emergency', 'Critical', 'Act now');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$notification->uuid}/acknowledge");

        $response->assertOk()->assertJsonPath('uuid', $notification->uuid);
        $this->assertNotNull($response->json('acknowledged_at'));

        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($recipient);
        $this->assertNotNull($recipient->acknowledged_at);
        // Acknowledging must never imply reading — genuinely independent
        // columns from day one (in-app-notifications.md section 5).
        $this->assertNull($recipient->read_at);
    }

    public function test_acknowledging_is_idempotent(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $this->tokenForRole('manager');

        $notification = app(NotificationService::class)->broadcastToUser($user, 'test.api_ack_idempotent', 'emergency', 'Critical', 'Act now');

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$notification->uuid}/acknowledge")
            ->assertOk();

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$notification->uuid}/acknowledge")
            ->assertOk();

        // A retried "acknowledge" (e.g. the mobile queue flushing twice
        // after a flaky connection) must not move the timestamp forward.
        $this->assertSame($first->json('acknowledged_at'), $second->json('acknowledged_at'));
    }

    public function test_a_user_cannot_acknowledge_a_notification_outside_their_own_audience(): void
    {
        $token = $this->tokenForRole('manager');

        $notification = app(NotificationService::class)->broadcastToRole('super', 'test.api_ack_not_mine', 'emergency', 'Not yours', 'Body');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$notification->uuid}/acknowledge");

        $response->assertForbidden();
    }
}
