<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckPushReceiptsJob;
use App\Models\DevicePushToken;
use App\Models\PushTicket;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Support\ScheduledTasks\PushReceiptCheckTaskType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * App\Jobs\CheckPushReceiptsJob — the reliability half of the push
 * pipeline, invoked every `tasks:run-due` tick via
 * App\Support\ScheduledTasks\PushReceiptCheckTaskType (task-scheduler.md).
 */
class CheckPushReceiptsJobTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        $this->agent = User::query()->where('email', 'divine@shalomtech.dev')->firstOrFail();
    }

    private function pendingTicket(string $ticketId, ?Carbon $createdAt = null): PushTicket
    {
        $token = DevicePushToken::query()->create([
            'user_id' => $this->agent->id,
            'device_id' => "device-{$ticketId}",
            'expo_push_token' => "ExponentPushToken[{$ticketId}]",
            'platform' => 'android',
            'is_valid' => true,
            'registered_at' => now(),
        ]);

        $ticket = PushTicket::query()->create([
            'ticket_id' => $ticketId,
            'device_push_token_id' => $token->id,
            'source_notification_uuid' => null,
            'status' => 'pending',
        ]);

        if ($createdAt) {
            $ticket->forceFill(['created_at' => $createdAt])->save();
        }

        return $ticket;
    }

    public function test_a_devicenotregistered_receipt_invalidates_the_token_and_marks_the_ticket_checked(): void
    {
        $ticket = $this->pendingTicket('ticket-gone');

        Http::fake([
            'https://exp.host/--/api/v2/push/getReceipts' => Http::response([
                'data' => ['ticket-gone' => ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']]],
            ]),
        ]);

        CheckPushReceiptsJob::dispatchSync();

        $ticket->refresh();
        $this->assertSame('checked', $ticket->status);
        $this->assertNotNull($ticket->checked_at);
        $this->assertFalse((bool) $ticket->devicePushToken->fresh()->is_valid);
    }

    public function test_an_ok_receipt_marks_the_ticket_checked_and_leaves_the_token_valid(): void
    {
        $ticket = $this->pendingTicket('ticket-ok');

        Http::fake([
            'https://exp.host/--/api/v2/push/getReceipts' => Http::response([
                'data' => ['ticket-ok' => ['status' => 'ok']],
            ]),
        ]);

        CheckPushReceiptsJob::dispatchSync();

        $ticket->refresh();
        $this->assertSame('checked', $ticket->status);
        $this->assertTrue((bool) $ticket->devicePushToken->fresh()->is_valid);
    }

    public function test_a_receipt_not_yet_available_is_left_pending_for_the_next_tick(): void
    {
        $ticket = $this->pendingTicket('ticket-notyet');

        Http::fake([
            'https://exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => []]),
        ]);

        CheckPushReceiptsJob::dispatchSync();

        $ticket->refresh();
        $this->assertSame('pending', $ticket->status);
        $this->assertNull($ticket->checked_at);
    }

    public function test_a_receipt_that_never_becomes_available_is_eventually_given_up_on(): void
    {
        $ticket = $this->pendingTicket('ticket-stale', Carbon::now()->subHours(25));

        Http::fake([
            'https://exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => []]),
        ]);

        CheckPushReceiptsJob::dispatchSync();

        $ticket->refresh();
        $this->assertSame('checked', $ticket->status, 'a ticket older than the give-up window must be marked checked even with no receipt, to bound the pending backlog.');
    }

    public function test_no_pending_tickets_means_no_http_call_at_all(): void
    {
        Http::fake();

        CheckPushReceiptsJob::dispatchSync();

        Http::assertNothingSent();
    }

    public function test_push_receipt_check_is_registered_as_a_system_owned_always_enabled_scheduled_task(): void
    {
        $task = ScheduledTask::query()->where('task_type', 'push_receipt_check')->first();

        $this->assertNotNull($task, 'the seed migration must have created this row.');
        $this->assertTrue($task->enabled);

        $taskType = app(PushReceiptCheckTaskType::class);
        $this->assertSame('push_receipt_check', $taskType->taskType());
        $this->assertTrue($taskType->isDue($task, Carbon::now()), 'must run on every tick, not a configurable schedule.');
    }
}
