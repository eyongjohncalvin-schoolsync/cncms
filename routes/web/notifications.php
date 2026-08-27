<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// The bell dropdown / emergency banner's three actions
// (in-app-notifications.md sections 4-5). No index route here — the list
// itself is delivered via the `notifications` shared Inertia prop (see
// App\Http\Middleware\HandleInertiaRequests), not a dedicated page.
Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
Route::post('notifications/{notification}/acknowledge', [NotificationController::class, 'acknowledge'])->name('notifications.acknowledge');
