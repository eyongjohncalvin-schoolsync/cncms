<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArrearsAdjustmentController;
use Illuminate\Support\Facades\Route;

// Request-only, matching Api\ArrearsAdjustmentController's own class doc:
// mobile may create a request (ArrearsAdjustmentPolicy::create() is
// ungated for every role), but the approve()/reject() maker-checker review
// actions stay web-only (routes/web/arrears-adjustments.php) — deliberately
// not mirrored here.
Route::post('arrears-adjustments', [ArrearsAdjustmentController::class, 'store'])->name('api.arrears-adjustments.store');
