<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

// JSON counterpart of routes/web/notifications.php's acknowledge action —
// see App\Http\Controllers\Api\NotificationController's class doc for why
// only acknowledge() exists here (mark-read/mark-all-read stay web-only;
// mobile's routine feed comes from GET /sync/pull's `notifications` block
// instead, per in-app-notifications.md section 6 / complaint-desk.md
// section 7). This is the "real online action, queue-and-confirm-once-
// connected if offline" endpoint the mobile emergency-interrupt screen
// calls — see mobile/src/api/sync.ts's acknowledgeNotification().
Route::post('notifications/{notification}/acknowledge', [NotificationController::class, 'acknowledge']);
