<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Expo's push HTTP API — a plain Http::post() call, NOT
 * the laravel-notification-channels/expo package (deliberately: pulling in
 * that wrapper package would resurrect the exact "two competing
 * notification systems" problem already avoided when this app retired its
 * old `alerts` table; this narrow a surface — two endpoints, no channel
 * abstraction needed — doesn't justify a dependency). See
 * App\Jobs\SendPushNotificationJob (send) and App\Jobs\CheckPushReceiptsJob
 * (getReceipts) for the only two callers.
 *
 * Both Expo endpoints accept up to 100 (send) / 1000 (getReceipts) items
 * per request — callers are responsible for chunking before calling in
 * here (App\Jobs\SendPushNotificationJob::CHUNK_SIZE /
 * App\Jobs\CheckPushReceiptsJob::RECEIPT_CHUNK_SIZE); this class does not
 * re-chunk internally.
 */
class ExpoPushService
{
    private const SEND_URL = 'https://exp.host/--/api/v2/push/send';

    private const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    /**
     * @param  array<int, array<string, mixed>>  $messages  Expo message objects — {to, title, body, data, priority, sound, ...}.
     * @return array<int, array<string, mixed>> One "ticket" per message, in the SAME order as $messages — either
     *                                           {status: 'ok', id: string} or {status: 'error', message: string, details?: array}.
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function sendBatch(array $messages): array
    {
        $response = $this->request()->post(self::SEND_URL, $messages);

        $response->throw();

        return $response->json('data', []);
    }

    /**
     * @param  array<int, string>  $ticketIds
     * @return array<string, array<string, mixed>> Map of ticket_id => receipt {status, message?, details?}. A ticket
     *                                              id Expo doesn't yet have a receipt for is simply absent from the
     *                                              map (not yet ready — callers should leave it pending and retry
     *                                              on a later tick).
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function fetchReceipts(array $ticketIds): array
    {
        $response = $this->request()->post(self::RECEIPTS_URL, ['ids' => $ticketIds]);

        $response->throw();

        return $response->json('data', []);
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
        ])->timeout(15);

        $accessToken = config('services.expo.access_token');

        if ($accessToken) {
            $http = $http->withToken($accessToken);
        }

        return $http;
    }

    /**
     * Best-effort log of an unexpected per-ticket Expo error (anything
     * other than the DeviceNotRegistered case callers already handle
     * explicitly) — never thrown, this is a fire-and-forget delivery
     * channel layered on top of the real (pull-based) notification system,
     * per App\Jobs\SendPushNotificationJob's class doc.
     */
    public function logUnexpectedTicketError(string $ticketId, array $ticket): void
    {
        Log::warning('Expo push ticket error', ['ticket_id' => $ticketId, 'ticket' => $ticket]);
    }
}
