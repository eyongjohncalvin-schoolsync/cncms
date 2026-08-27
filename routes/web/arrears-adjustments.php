<?php

declare(strict_types=1);

use App\Http\Controllers\ArrearsAdjustmentController;
use Illuminate\Support\Facades\Route;

// No index/show route — see App\Http\Controllers\ArrearsAdjustmentController's
// class doc for where requesting/reviewing actually happen.
Route::post('arrears-adjustments', [ArrearsAdjustmentController::class, 'store'])->name('arrears-adjustments.store');
Route::post('arrears-adjustments/{arrearsAdjustment}/approve', [ArrearsAdjustmentController::class, 'approve'])->name('arrears-adjustments.approve');
Route::post('arrears-adjustments/{arrearsAdjustment}/reject', [ArrearsAdjustmentController::class, 'reject'])->name('arrears-adjustments.reject');
