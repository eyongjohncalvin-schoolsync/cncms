<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BillController;
use Illuminate\Support\Facades\Route;

// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top
// of the group-level 'throttle:api' (120/min) applied in routes/api.php —
// the tighter export limit always trips first, so this is the effective
// ceiling.
Route::get('bills/{customer}/print', [BillController::class, 'print'])->middleware('throttle:exports');

// Mobile "Send Bill via WhatsApp" (manual mode — bill-notifications.md
// section 1/6.2): lightweight JSON, not a PDF export, so it stays under the
// default 'throttle:api' ceiling from routes/api.php rather than the
// tighter 'throttle:exports' limiter above.
Route::get('bills/{customer}/whatsapp-message', [BillController::class, 'whatsappMessage']);
