<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

// JSON counterpart of routes/web/notifications.php's acknowledge action —
// mobile's routine feed comes from GET /sync/pull's `notifications` block
// (in-app-notifications.md section 6 / complaint-desk.md section 7).
// Acknowledge is the "real online action, queue-and-confirm-once-
// connected if offline" endpoint the mobile emergency-interrupt screen
// calls — see mobile/src/api/sync.ts's acknowledgeNotification().
// The phone marks what it showed as read (2026-11-02).
Route::post('notifications/read', [NotificationController::class, 'markRead']);
Route::post('notifications/{notification}/acknowledge', [NotificationController::class, 'acknowledge']);
