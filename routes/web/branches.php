<?php

declare(strict_types=1);

use App\Http\Controllers\BranchController;
use App\Http\Controllers\BranchSettingsController;
use App\Http\Controllers\BranchViewController;
use Illuminate\Support\Facades\Route;

// Branch switcher (branches v2): which branch an unfenced user is viewing.
Route::put('branch-view', [BranchViewController::class, 'update'])->name('branch-view.update');

// A branch's own details, MoMo number, fees and prices (branches v2 D4).
Route::get('my-branch', [BranchSettingsController::class, 'mine'])->name('branches.mine');
Route::get('branches/{branch}/settings', [BranchSettingsController::class, 'edit'])->name('branches.settings.edit');
Route::patch('branches/{branch}/settings', [BranchSettingsController::class, 'update'])->name('branches.settings.update');
Route::post('branches/{branch}/prices', [BranchSettingsController::class, 'setPrice'])->name('branches.prices.store');

Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
Route::get('branches/create', [BranchController::class, 'create'])->name('branches.create');
Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
// Guided setup after creating a branch (and any time later): quick-add zones + checklist.
Route::get('branches/{branch}/setup', [BranchController::class, 'setup'])->name('branches.setup');
Route::post('branches/{branch}/zones', [BranchController::class, 'storeZones'])->name('branches.zones.store');
Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
