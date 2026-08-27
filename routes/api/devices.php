<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PushTokenController;
use Illuminate\Support\Facades\Route;

// Mobile push token registration (mobile-push-notifications build notes) —
// upserted by (user, device_id), fire-and-forget from the client right
// after login. See App\Http\Controllers\Api\PushTokenController's class
// doc.
Route::post('devices/push-token', [PushTokenController::class, 'store']);
