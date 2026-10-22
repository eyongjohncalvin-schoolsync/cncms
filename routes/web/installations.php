<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerChargeController;
use Illuminate\Support\Facades\Route;

// Installation fees (docs/plans/installation-and-promotions.md, phase 1) —
// the customer page's installation actions. CustomerChargePolicy.
Route::post('customers/{customer}/installation-charges', [CustomerChargeController::class, 'store'])->name('installation-charges.store');
Route::post('installation-charges/{customerCharge}/payments', [CustomerChargeController::class, 'storePayment'])->name('installation-charges.payments.store');
Route::post('installation-charges/{customerCharge}/waiver', [CustomerChargeController::class, 'requestWaiver'])->name('installation-charges.waiver.request');
Route::post('installation-charges/{customerCharge}/waiver/approve', [CustomerChargeController::class, 'approveWaiver'])->name('installation-charges.waiver.approve');
Route::post('installation-charges/{customerCharge}/waiver/reject', [CustomerChargeController::class, 'rejectWaiver'])->name('installation-charges.waiver.reject');
