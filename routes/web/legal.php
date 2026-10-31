<?php

declare(strict_types=1);

use App\Http\Controllers\ContractController;
use App\Http\Controllers\LegalAcceptanceController;
use Illuminate\Support\Facades\Route;

// New Terms for the company's super/admin to accept (EnsureLegalAccepted).
Route::get('legal/accept', [LegalAcceptanceController::class, 'show'])->name('legal.accept.show');
Route::post('legal/accept', [LegalAcceptanceController::class, 'store'])->name('legal.accept.store');

// The OneTech service contract, signed online by the company's super/admin.
Route::get('settings/contract', [ContractController::class, 'show'])->name('contract.show');
Route::post('settings/contract/sign', [ContractController::class, 'sign'])->name('contract.sign');
Route::get('settings/contract/{signature}/pdf', [ContractController::class, 'pdf'])->whereNumber('signature')->name('contract.pdf');
