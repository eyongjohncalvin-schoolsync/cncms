<?php

declare(strict_types=1);

use App\Http\Controllers\ComplaintController;
use Illuminate\Support\Facades\Route;

Route::get('complaints', [ComplaintController::class, 'index'])->name('complaints.index');
Route::get('complaints/create', [ComplaintController::class, 'create'])->name('complaints.create');
Route::post('complaints', [ComplaintController::class, 'store'])->name('complaints.store');
Route::get('complaints/duplicates', [ComplaintController::class, 'duplicates'])->name('complaints.duplicates');
Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
Route::post('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve'])->name('complaints.resolve');
Route::post('complaints/{complaint}/reopen', [ComplaintController::class, 'reopen'])->name('complaints.reopen');
Route::post('complaints/{complaint}/link-duplicate', [ComplaintController::class, 'linkDuplicate'])->name('complaints.link-duplicate');
Route::post('complaints/{complaint}/assign', [ComplaintController::class, 'assign'])->name('complaints.assign');
Route::post('complaints/{complaint}/notify-investors', [ComplaintController::class, 'notifyInvestors'])->name('complaints.notify-investors');
