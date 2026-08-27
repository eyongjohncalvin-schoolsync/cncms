<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushTicket;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reliability half of the push pipeline (App\Jobs\SendPushNotificationJob is
 * the other half) — checks Expo's getReceipts endpoint for every ticket
 * still `pending`, dispatched by App\Support\ScheduledTasks\
 * PushReceiptCheckTaskType on every `tasks:run-due` tick (every ~15
 * minutes, task-scheduler.md), which gives each ticket roughly the
 * "~15 min later" delay the build spec calls for without inventing a
 * second, per-push-delayed cron mechanism.
 *
 * A ticket whose receipt reports `DeviceNotRegistered` invalidates its
 * device_push_tokens row (the send-time ticket response didn't already
 * catch this — DeviceNotRegistered can surface at either stage per Expo's
 * own docs). Any other error status is left alone (transient/unclear).
 * Either way the ticket is marked `checked` so it is never re-queried.
 *
 * Same best-effort contract as SendPushNotificationJob: a failed Expo call
 * here must never throw back onto the queue's retry/failed-job machinery —
 * it's logged and simply retried again on the next 15-minute tick.
 */
class CheckPushReceiptsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Expo's getReceipts endpoint accepts at most 1000 ticket ids per request. */
    private const RECEIPT_CHUNK_SIZE = 1000;

    /**
     * Safety valve: a ticket Expo never returns a receipt for (should be
     * rare — receipts are normally available within minutes) is force-
     * marked checked after this long anyway, so a permanently-missing
     * receipt can't leave an ever-growing `pending` backlog being
     * re-queried on every tick forever.
     */
    private const GIVE_UP_AFTER_HOURS = 24;

    public function handle(ExpoPushService $expo, PushTokenRepositoryInterface $pushTokens): void
    {
        $pending = PushTicket::query()->where('status', 'pending')->get();

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending->chunk(self::RECEIPT_CHUNK_SIZE) as $chunk) {
            $this->checkChunk($chunk->values(), $expo, $pushTokens);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PushTicket>  $chunk
     */
    private function checkChunk(\Illuminate\Support\Collection $chunk, ExpoPushService $expo, PushTokenRepositoryInterface $pushTokens): void
    {
        try {
            $receipts = $expo->fetchReceipts($chunk->pluck('ticket_id')->all());
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $now = Carbon::now();
        $giveUpBefore = $now->clone()->subHours(self::GIVE_UP_AFTER_HOURS);

        foreach ($chunk as $ticket) {
            $receipt = $receipts[$ticket->ticket_id] ?? null;

            if ($receipt === null) {
                // Not ready yet — leave pending, unless it's old enough
                // that we give up waiting (see GIVE_UP_AFTER_HOURS above).
                if ($ticket->created_at?->lessThan($giveUpBefore)) {
                    $ticket->update(['status' => 'checked', 'checked_at' => $now]);
                }

                continue;
            }

            if (($receipt['status'] ?? null) === 'error' && ($receipt['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $token = $ticket->devicePushToken;

                if ($token) {
                    $pushTokens->invalidate($token);
                }
            }

            $ticket->update(['status' => 'checked', 'checked_at' => $now]);
        }
    }
}
