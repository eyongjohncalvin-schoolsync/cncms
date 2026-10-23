<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerChargeController;
use App\Http\Controllers\FreeMonthGrantController;
use Illuminate\Support\Facades\Route;

// Installation fees (docs/plans/installation-and-promotions.md, phase 1) —
// the customer page's installation actions. CustomerChargePolicy. Money
// writes, so refused while a landlord impersonates (E9,
// docs/plans/expenditures-and-spending-controls.md).
Route::middleware('not-impersonating:money')->group(function () {
    Route::post('customers/{customer}/installation-charges', [CustomerChargeController::class, 'store'])->name('installation-charges.store');
    Route::post('installation-charges/{customerCharge}/payments', [CustomerChargeController::class, 'storePayment'])->name('installation-charges.payments.store');
    Route::post('installation-charges/{customerCharge}/waiver', [CustomerChargeController::class, 'requestWaiver'])->name('installation-charges.waiver.request');
    Route::post('installation-charges/{customerCharge}/waiver/approve', [CustomerChargeController::class, 'approveWaiver'])->name('installation-charges.waiver.approve');
    Route::post('installation-charges/{customerCharge}/waiver/reject', [CustomerChargeController::class, 'rejectWaiver'])->name('installation-charges.waiver.reject');

    // Phase 2: a fee below the offer's (D8) and "Refund installation" (D9).
    Route::post('installation-charges/{customerCharge}/fee/approve', [CustomerChargeController::class, 'approveFee'])->name('installation-charges.fee.approve');
    Route::post('installation-charges/{customerCharge}/fee/reject', [CustomerChargeController::class, 'rejectFee'])->name('installation-charges.fee.reject');
    Route::post('installation-charges/{customerCharge}/refund', [CustomerChargeController::class, 'requestRefund'])->name('installation-charges.refund.request');
    Route::post('installation-charges/{customerCharge}/refund/approve', [CustomerChargeController::class, 'approveRefund'])->name('installation-charges.refund.approve');
    Route::post('installation-charges/{customerCharge}/refund/reject', [CustomerChargeController::class, 'rejectRefund'])->name('installation-charges.refund.reject');

    // Free months (compensation, D12; approvals for anything beyond an offer).
    Route::post('customers/{customer}/free-months', [FreeMonthGrantController::class, 'store'])->name('free-months.store');
    Route::post('free-months/{freeMonthGrant}/approve', [FreeMonthGrantController::class, 'approve'])->name('free-months.approve');
    Route::post('free-months/{freeMonthGrant}/reject', [FreeMonthGrantController::class, 'reject'])->name('free-months.reject');
});
