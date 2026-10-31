<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArrearsAdjustmentController;
use Illuminate\Support\Facades\Route;

// Request-only here: mobile may create a request (ArrearsAdjustmentPolicy::
// create() is ungated for every role). Approving / rejecting from the phone
// is the manager inbox (routes/api/inbox.php, owner 2026-10-31), through the
// same maker-checker policy as the web.
Route::post('arrears-adjustments', [ArrearsAdjustmentController::class, 'store'])->name('api.arrears-adjustments.store');
