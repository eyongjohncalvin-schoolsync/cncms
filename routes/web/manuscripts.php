<?php

declare(strict_types=1);

use App\Http\Controllers\ManuscriptController;
use Illuminate\Support\Facades\Route;

Route::get('manuscripts', [ManuscriptController::class, 'index'])->name('manuscripts.index');

// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top
// of the group-level 'throttle:web' (300/min) applied in routes/web.php —
// the tighter export limit always trips first, so this is the effective
// ceiling.
Route::get('manuscripts/export', [ManuscriptController::class, 'export'])
    ->name('manuscripts.export')
    ->middleware('throttle:exports');

Route::post('manuscripts/calculate', [ManuscriptController::class, 'calculate'])->name('manuscripts.calculate');

// Logs a `messages` row for the manual WhatsApp "Send Bill" action — see
// ManuscriptController::sendBill()'s doc comment. {customer} binds by
// Customer's uuid route key (App\Models\Customer's #[RouteKey('uuid')]),
// matching routes/web/customers.php's convention.
Route::post('manuscripts/{customer}/send-bill', [ManuscriptController::class, 'sendBill'])->name('manuscripts.send-bill');
