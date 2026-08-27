<?php

declare(strict_types=1);

use App\Http\Controllers\BranchController;
use Illuminate\Support\Facades\Route;

Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
Route::get('branches/create', [BranchController::class, 'create'])->name('branches.create');
Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
