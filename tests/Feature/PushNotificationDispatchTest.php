<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DevicePushToken;
use App\Models\PushTicket;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * App\Jobs\SendPushNotificationJob — the mobile push "fast channel" wired
 * into App\Services\NotificationService::create(). QUEUE_CONNECTION=sync in
 * testing (phpunit.xml) means every broadcastTo*() call below runs the job
 * synchronously in-process, so assertions immediately after are meaningful
 * without a real queue worker (same reasoning as
 * ManuscriptGenerationBatchServiceTest's class doc).
 *
 * Uses the real, already-committed seeded demo users (DemoTransactionalDataSeeder)
 * rather than creating fresh ones, for the same cross-connection-visibility
 * reason InteractsWithTenantRoles documents: divine@shalomtech.dev (agent),
 * blessing@shalomtech.dev (agent), terence@shalomtech.dev (manager).
 */
class PushNotificationDispatchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $agent;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->agent = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
        $this->manager = User::query()->where('email', 'terence@shalomtech.dev')->firstOrFail();
    }

    private function registerToken(User $user, string $deviceId = 'device-1'): DevicePushToken
    {
        return DevicePushToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'expo_push_token' => "ExponentPushToken[{$user->username}-{$deviceId}]",
            'platform' => 'android',
            'is_valid' => true,
            'registered_at' => now(),
        ]);
    }

    public function test_info_never_pushes_and_a_warning_only_when_addressed_to_one_person(): void
    {
        $this->registerToken($this->agent);
        Http::fake();

        app(NotificationService::class)->broadcastToUser($this->agent, 'test.info', 'info', 'T', 'B');
        app(NotificationService::class)->broadcastToRole('agent', 'test.warning', 'warning', 'T', 'B');

        Http::assertNothingSent();
    }

    public function test_a_warning_to_one_person_arrives_silently(): void
    {
        $this->registerToken($this->manager);
        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-quiet-1']]])]);

        app(NotificationService::class)->broadcastToUser($this->manager, 'inbox.reversal', 'warning', 'T', 'B');

        Http::assertSent(function (Request $request) {
            $message = $request->data()[0];

            return $message['channelId'] === 'quiet'
                && $message['sound'] === null
                && $message['priority'] === 'normal'
                && $message['title'] === 'A collector asks to reverse a payment';
        });
    }

    public function test_quiet_hours_silence_urgent_alerts_but_never_emergencies(): void
    {
        $this->travelTo('2026-11-02 22:30:00'); // 23:30 in Cameroon
        $this->manager->forceFill(['alert_quiet_start' => '22:00', 'alert_quiet_end' => '06:00'])->save();
        $this->registerToken($this->manager);
        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-night']]])]);

        app(NotificationService::class)->broadcastToUser($this->manager, 'inbox.voucher', 'urgent', 'T', 'B');
        app(NotificationService::class)->broadcastToUser($this->manager, 'complaint.escalated', 'emergency', 'T', 'B');

        $channels = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data()[0]['channelId'])->all();
        $this->assertSame(['quiet', 'emergency'], $channels);
    }

    public function test_urgent_and_emergency_dispatch_a_push_using_the_fixed_template_never_raw_title_or_body(): void
    {
        $this->registerToken($this->agent);

        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-emergency-1']]]),
        ]);

        $sensitiveTitle = 'RAW SENSITIVE: complaint about Mrs Ngozi';
        $sensitiveBody = 'Customer Mrs Ngozi at Kumba 3 reported a serious issue involving her account.';

        $notification = app(NotificationService::class)->broadcastToUser(
            $this->agent, 'complaint.escalated', 'emergency', $sensitiveTitle, $sensitiveBody
        );

        Http::assertSent(function (Request $request) use ($notification, $sensitiveTitle, $sensitiveBody) {
            if ($request->url() !== 'https://exp.host/--/api/v2/push/send') {
                return false;
            }

            $messages = $request->data();
            $this->assertCount(1, $messages);

            $message = $messages[0];
            $this->assertSame('Urgent: a complaint needs your attention', $message['title']);
            $this->assertSame('Open 48+ hours with no action taken. Tap to review and acknowledge.', $message['body']);
            $this->assertSame('high', $message['priority']);
            $this->assertSame('default', $message['sound']);
            $this->assertSame('emergency', $message['channelId']);
            $this->assertSame($notification->uuid, $message['data']['notification_uuid']);
            $this->assertSame('emergency', $message['data']['severity']);

            // The real, potentially-identifying complaint text must NEVER
            // appear anywhere in the outbound push payload.
            $payload = json_encode($messages);
            $this->assertStringNotContainsString($sensitiveTitle, $payload);
            $this->assertStringNotContainsString($sensitiveBody, $payload);
            $this->assertStringNotContainsString('Ngozi', $payload);

            return true;
        });

        $this->assertDatabaseHas('push_tickets', [
            'ticket_id' => 'ticket-emergency-1',
            'status' => 'pending',
            'source_notification_uuid' => $notification->uuid,
        ], 'tenant');
    }

    public function test_push_only_reaches_the_agent_role_even_for_a_broadcast_to_all_emergency(): void
    {
        $agentToken = $this->registerToken($this->agent);
        $this->registerToken($this->manager);

        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-broadcast-1']]]),
        ]);

        app(NotificationService::class)->broadcastToAll('test.staffwide', 'emergency', 'T', 'B');

        Http::assertSent(function (Request $request) use ($agentToken) {
            $messages = $request->data();

            // Only the agent's token — the manager, though also in the
            // notification's own (broadcast_scope='all') audience, must
            // never receive a push (build spec: push always narrows to the
            // agent role subset regardless of the underlying notification's
            // own broader audience).
            return count($messages) === 1 && $messages[0]['to'] === $agentToken->expo_push_token;
        });
    }

    public function test_an_investor_gets_pushes_addressed_to_them_but_never_broadcasts(): void
    {
        TenantUser::query()->where('user_id', $this->agent->id)->update(['is_investor' => true]);
        $this->registerToken($this->agent);

        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-investor']]])]);

        app(NotificationService::class)->broadcastToAll('test.staffwide', 'emergency', 'T', 'B');
        Http::assertNothingSent();

        // Addressed to them, e.g. a stakeholder voucher to approve from the app.
        app(NotificationService::class)->broadcastToUser($this->agent, 'voucher.stakeholder_approval', 'urgent', 'T', 'B');
        Http::assertSentCount(1);
    }

    public function test_a_manager_now_gets_pushes_addressed_to_them(): void
    {
        $this->registerToken($this->manager);
        Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-manager']]])]);

        app(NotificationService::class)->broadcastToUser($this->manager, 'inbox.voucher', 'urgent', 'T', 'B');

        Http::assertSent(fn (Request $request) => $request->data()[0]['title'] === 'A voucher needs your approval' && $request->data()[0]['channelId'] === 'urgent');
    }

    public function test_a_devicenotregistered_ticket_error_immediately_invalidates_the_token(): void
    {
        $token = $this->registerToken($this->agent);

        Http::fake([
            'https://exp.host/*' => Http::response([
                'data' => [['status' => 'error', 'message' => 'Device not registered', 'details' => ['error' => 'DeviceNotRegistered']]],
            ]),
        ]);

        app(NotificationService::class)->broadcastToUser($this->agent, 'test.gone', 'urgent', 'T', 'B');

        $this->assertFalse((bool) $token->fresh()->is_valid);
        $this->assertSame(0, PushTicket::query()->where('device_push_token_id', $token->id)->count());
    }

    public function test_a_transient_ticket_error_leaves_the_token_valid(): void
    {
        $token = $this->registerToken($this->agent);

        Http::fake([
            'https://exp.host/*' => Http::response([
                'data' => [['status' => 'error', 'message' => 'Message too big', 'details' => ['error' => 'MessageTooBig']]],
            ]),
        ]);

        app(NotificationService::class)->broadcastToUser($this->agent, 'test.transient', 'urgent', 'T', 'B');

        $this->assertTrue((bool) $token->fresh()->is_valid);
    }

    public function test_a_notification_with_no_registered_agent_tokens_never_calls_expo(): void
    {
        Http::fake();

        app(NotificationService::class)->broadcastToUser($this->manager, 'test.nomobile', 'emergency', 'T', 'B');

        Http::assertNothingSent();
    }
}
