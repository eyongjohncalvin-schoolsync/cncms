<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('resources', [ResourceController::class, 'dashboard'])->name('resources.dashboard');

Route::get('resources/expenditures', [ResourceController::class, 'expenditures'])->name('resources.expenditures.index');
Route::get('resources/expenditures/create', [ResourceController::class, 'createExpenditure'])->name('resources.expenditures.create');
Route::post('resources/expenditures', [ResourceController::class, 'storeExpenditure'])->name('resources.expenditures.store');
Route::patch('resources/expenditures/{expenditure}', [ResourceController::class, 'updateExpenditure'])->name('resources.expenditures.update');
Route::delete('resources/expenditures/{expenditure}', [ResourceController::class, 'destroyExpenditure'])->name('resources.expenditures.destroy');

Route::get('resources/categories', [ResourceController::class, 'categories'])->name('resources.categories.index');
Route::post('resources/categories', [ResourceController::class, 'storeCategory'])->name('resources.categories.store');
Route::patch('resources/categories/{category}', [ResourceController::class, 'updateCategory'])->name('resources.categories.update');
Route::delete('resources/categories/{category}', [ResourceController::class, 'destroyCategory'])->name('resources.categories.destroy');
