<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InboxController;
use Illuminate\Support\Facades\Route;

// The manager inbox (docs/plans/mobile-manager-app.md, wave 1): what needs
// the signed-in user, and the decisions taken from it. Same policies and
// services as the web; assigning a complaint is POST complaints/{complaint}/assign.
Route::get('inbox', [InboxController::class, 'index'])->name('api.inbox');
Route::post('inbox/vouchers/{voucher}/approve', [InboxController::class, 'approveVoucher'])->name('api.inbox.vouchers.approve');
Route::post('inbox/vouchers/{voucher}/reject', [InboxController::class, 'rejectVoucher'])->name('api.inbox.vouchers.reject');
Route::post('inbox/arrears-adjustments/{arrearsAdjustment}/approve', [InboxController::class, 'approveArrears'])->name('api.inbox.arrears.approve');
Route::post('inbox/arrears-adjustments/{arrearsAdjustment}/reject', [InboxController::class, 'rejectArrears'])->name('api.inbox.arrears.reject');
Route::post('inbox/reversal-requests/{reversalRequest}/reverse', [InboxController::class, 'reverse'])->name('api.inbox.reversals.reverse');
Route::post('inbox/reversal-requests/{reversalRequest}/decline', [InboxController::class, 'declineReversal'])->name('api.inbox.reversals.decline');
Route::get('inbox/complaints/{complaint}/technicians', [InboxController::class, 'technicians'])->name('api.inbox.complaints.technicians');
