<?php

declare(strict_types=1);

use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchViewController;
use Illuminate\Support\Facades\Route;

// Branch switcher (branches v2): which branch an unfenced user is viewing.
Route::put('branch-view', [BranchViewController::class, 'update'])->name('branch-view.update');

Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
Route::get('branches/create', [BranchController::class, 'create'])->name('branches.create');
Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
