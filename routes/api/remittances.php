<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RemittanceController;
use Illuminate\Support\Facades\Route;

// The daily income form for the app (docs/plans/cash-controls.md, wave 4).
// Submit only — receiving a handover stays on the web (the cashier's desk).
Route::get('remittances/preview', [RemittanceController::class, 'preview'])->name('api.remittances.preview');
Route::get('remittances', [RemittanceController::class, 'index'])->name('api.remittances.index');
Route::post('remittances', [RemittanceController::class, 'store'])->name('api.remittances.store');
