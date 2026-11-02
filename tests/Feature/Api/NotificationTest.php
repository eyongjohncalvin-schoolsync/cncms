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
 * JSON API counterpart of tests/Feature/Web/NotificationTest.php:
 * acknowledge() for the mobile emergency-interrupt screen
 * (complaint-desk.md section 7) and markRead() for what the phone's
 * Notifications screen showed (2026-11-02).
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

    public function test_the_phone_marks_what_it_showed_read_and_it_stays_read_on_the_next_sign_in(): void
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $token = $this->tokenForRole('manager');

        $mine = app(NotificationService::class)->broadcastToUser($user, 'test.api_read', 'info', 'Seen', 'Body');
        $notMine = app(NotificationService::class)->broadcastToRole('super', 'test.api_read_not_mine', 'info', 'Not yours', 'Body');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications/read', ['uuids' => [$mine->uuid, $notMine->uuid]])
            ->assertOk()
            ->assertJsonPath('marked', 1);

        $this->assertNotNull(NotificationRecipient::query()->where('notification_id', $mine->id)->where('user_id', $user->id)->value('read_at'));
        $this->assertFalse(NotificationRecipient::query()->where('notification_id', $notMine->id)->exists());

        // A fresh sign-in pulls it back already read.
        $this->app->make('auth')->forgetGuards();
        $items = collect($this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->getJson('/api/v1/sync/pull')
            ->assertOk()
            ->json('changes.notifications.items'));

        $this->assertNotNull($items->firstWhere('uuid', $mine->uuid)['read_at'] ?? null);
    }

    public function test_marking_read_needs_a_list_of_uuids(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenForRole('manager'))
            ->postJson('/api/v1/notifications/read', ['uuids' => 'nope'])
            ->assertUnprocessable();
    }
}
