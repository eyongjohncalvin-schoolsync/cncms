<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
Route::get('customers/{customer}/bill/print', [CustomerController::class, 'printBill'])->name('customers.bill.print');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
