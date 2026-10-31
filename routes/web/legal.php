<?php

declare(strict_types=1);

use App\Http\Controllers\LegalAcceptanceController;
use Illuminate\Support\Facades\Route;

// New Terms for the company's super/admin to accept (EnsureLegalAccepted).
Route::get('legal/accept', [LegalAcceptanceController::class, 'show'])->name('legal.accept.show');
Route::post('legal/accept', [LegalAcceptanceController::class, 'store'])->name('legal.accept.store');
