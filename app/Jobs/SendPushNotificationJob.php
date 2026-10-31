<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DevicePushToken;
use App\Models\Notification;
use App\Models\PushTicket;
use App\Models\User;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use App\Services\ExpoPushService;
use App\Support\PushNotifications\PushTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The mobile push "fast channel" layered on top of the existing in-app
 * notification system (App\Services\NotificationService::create() dispatches
 * this after every Notification row is created — see that method's doc
 * comment). Deliberately NOT a model observer: this codebase's existing
 * NotificationService doc explicitly favors direct calls at one choke point
 * over implicit event wiring.
 *
 * Two hard eligibility gates, both enforced here rather than at the
 * dispatch call site (so NotificationService::create() stays a single
 * unconditional dispatch — the job itself is the one place "should this
 * push at all" is decided):
 *
 *   1. severity — 'urgent'/'emergency' push; a 'warning' addressed to one
 *      person pushes silently (the manager inbox's "Today" alerts, wave 2
 *      of docs/plans/mobile-manager-app.md); 'info' never does — the core
 *      "don't flood people" decision.
 *   2. audience — even when the underlying notification's own audience is
 *      broader (e.g. a full-staff emergency broadcast), push only reaches
 *      the `agent` role subset; every other role already sees it via the
 *      existing web polling / mobile pull cycle. Investors never receive
 *      push at all (no mobile app presence). See
 *      App\Repositories\Eloquent\PushTokenRepository::tokensForAudience().
 *
 * Push failure must NEVER affect the underlying Notification/
 * NotificationRecipient rows or the existing pull-based delivery — every
 * Expo call below is wrapped so a transient failure here is logged and
 * swallowed, never thrown back to the queue worker as a job failure that
 * could be mistaken for "the notification failed to send."
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Expo's push send endpoint accepts at most 100 messages per request. */
    private const CHUNK_SIZE = 100;

    /** Looks for a not-yet-committed notification this many times. */
    private const int TRIES = 3;

    public int $tries = self::TRIES;

    /** @var list<int> recipients in their quiet hours, worked out once per send */
    private array $quietUserIds = [];

    public function __construct(
        public readonly string $notificationUuid,
    ) {}

    public function handle(PushTokenRepositoryInterface $pushTokens, ExpoPushService $expo): void
    {
        $notification = Notification::query()->where('uuid', $this->notificationUuid)->first();

        if (! $notification) {
            // Created inside a transaction that hasn't committed yet: look
            // again shortly. Still missing after that = rolled back, so no push.
            if ($this->attempts() < self::TRIES) {
                $this->release(10);
            }

            return;
        }

        // urgent/emergency push; a `warning` addressed to one person pushes
        // too, silently (the manager inbox's "Today" alerts). Info never does.
        $pushes = in_array($notification->severity, ['urgent', 'emergency'], true)
            || ($notification->severity === 'warning' && $notification->broadcast_scope === 'user');

        if (! $pushes) {
            return;
        }

        $tokens = $pushTokens->tokensForAudience($notification);

        if ($tokens->isEmpty()) {
            return;
        }

        $template = PushTemplates::resolve($notification->type, $notification->severity);

        // Recipients in their quiet hours get an urgent alert silently.
        $this->quietUserIds = $notification->severity === 'urgent'
            ? User::query()->whereIn('id', $tokens->pluck('user_id')->unique())->get(['id', 'alert_quiet_start', 'alert_quiet_end'])
                ->filter(fn (User $user): bool => $user->inQuietHours())->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : [];

        foreach ($tokens->chunk(self::CHUNK_SIZE) as $chunk) {
            $this->sendChunk($notification, $chunk->values(), $template, $expo, $pushTokens);
        }
    }

    /**
     * @param  Collection<int, DevicePushToken>  $chunk
     * @param  array{title: string, body: string}  $template
     */
    private function sendChunk(
        Notification $notification,
        Collection $chunk,
        array $template,
        ExpoPushService $expo,
        PushTokenRepositoryInterface $pushTokens,
    ): void {
        $messages = $chunk->map(fn (DevicePushToken $token): array => [
            'to' => $token->expo_push_token,
            'title' => $template['title'],
            'body' => $template['body'],
            ...$this->delivery($notification, $token),
            'data' => [
                'notification_uuid' => $notification->uuid,
                'link' => $notification->link,
                'severity' => $notification->severity,
                'type' => $notification->type,
                'source_type' => $notification->source_type,
                'source_uuid' => $notification->source_uuid,
            ],
        ])->all();

        try {
            $tickets = $expo->sendBatch($messages);
        } catch (Throwable $e) {
            // Best-effort fast channel — a failed HTTP round trip to Expo
            // (network blip, Expo outage) must never fail this job back
            // onto the queue's retry/failed-job machinery in a way that
            // looks like the underlying notification failed. Logged via
            // report() for visibility, then this chunk is simply skipped.
            report($e);

            return;
        }

        foreach ($tickets as $index => $ticket) {
            $token = $chunk->get($index);

            if (! $token) {
                continue;
            }

            try {
                $this->handleTicket($notification, $token, $ticket, $expo, $pushTokens);
            } catch (Throwable $e) {
                // One malformed/duplicate ticket must not stop the rest of
                // this chunk's tickets from being recorded.
                report($e);
            }
        }
    }

    /**
     * How it arrives. The channel ids match the Android channels the app
     * creates (mobile/src/notifications): `emergency` heads-up, `urgent`
     * with sound, `quiet` without — used for a `warning` and for an urgent
     * alert during the recipient's quiet hours. Emergencies always ring.
     *
     * @return array{priority: string, sound: string|null, channelId: string}
     */
    private function delivery(Notification $notification, DevicePushToken $token): array
    {
        $quiet = $notification->severity === 'warning'
            || ($notification->severity === 'urgent' && in_array((int) $token->user_id, $this->quietUserIds, true));

        return $quiet
            ? ['priority' => 'normal', 'sound' => null, 'channelId' => 'quiet']
            : ['priority' => 'high', 'sound' => 'default', 'channelId' => $notification->severity];
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function handleTicket(
        Notification $notification,
        DevicePushToken $token,
        array $ticket,
        ExpoPushService $expo,
        PushTokenRepositoryInterface $pushTokens,
    ): void {
        if (($ticket['status'] ?? null) === 'error') {
            // Ticket-level response handling: DeviceNotRegistered is
            // immediate and certain (the token is gone, e.g. app
            // uninstalled) — invalidate right away rather than waiting for
            // a receipt check. Any other error is transient/unclear, so the
            // token is left alone (leave-alone-on-transient-errors, per the
            // build spec) and just logged.
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                $pushTokens->invalidate($token);
            } else {
                $expo->logUnexpectedTicketError((string) ($ticket['id'] ?? 'unknown'), $ticket);
            }

            return;
        }

        if (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
            PushTicket::query()->create([
                'ticket_id' => $ticket['id'],
                'device_push_token_id' => $token->id,
                'source_notification_uuid' => $notification->uuid,
                'status' => 'pending',
            ]);

            $pushTokens->touchLastUsed($token);
        }
    }
}
