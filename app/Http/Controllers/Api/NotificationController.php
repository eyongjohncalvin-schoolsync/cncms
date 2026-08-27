<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API counterpart of the web Notification controller
 * (App\Http\Controllers\NotificationController) — same NotificationService,
 * same NotificationPolicy, just returning JSON instead of a redirect.
 * Mirrors Api\ComplaintController's shape (App\Http\Controllers\
 * ComplaintController -> App\Http\Controllers\Api\ComplaintController).
 *
 * Only `acknowledge()` exists here for v1 — mobile-app-react-native.md's
 * "Log a Complaint" mobile counterpart (complaint-desk.md section 7) only
 * needs the emergency-broadcast acknowledge action to be a real online
 * action, since the routine bell/dropdown parity is deliberately NOT
 * built on mobile (in-app-notifications.md section 6 /
 * complaint-desk.md section 7's "keep it proportionate" note) — the mobile
 * app reads its routine feed and unread count from
 * App\Services\SyncService::pull()'s `notifications` block instead of a
 * separate read-tracking HTTP round trip.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function acknowledge(Request $request, Notification $notification): JsonResponse
    {
        $this->authorize('acknowledge', $notification);

        $this->notifications->acknowledge($notification, $request->user());

        // NotificationResource's read_at/acknowledged_at fields depend on
        // the recipient_read_at/recipient_acknowledged_at attributes
        // App\Repositories\Eloquent\NotificationRepository's
        // recentForUser()/unacknowledgedEmergenciesForUser() extra-select —
        // a plain route-model-bound $notification never carries those, so
        // reusing that Resource here would silently report acknowledged_at
        // as null even on success. Read the actual recipient row back
        // instead, so the mobile client's optimistic-to-confirmed state
        // transition (App\Services\SyncService::pull()'s `notifications`
        // block use the same shape) has a real timestamp to trust.
        $acknowledgedAt = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $request->user()->id)
            ->value('acknowledged_at');

        return response()->json([
            'uuid' => $notification->uuid,
            'acknowledged_at' => $acknowledgedAt?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }
}
