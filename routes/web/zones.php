<?php

declare(strict_types=1);

use App\Http\Controllers\ZoneController;
use App\Http\Controllers\ZoneCoverageController;
use Illuminate\Support\Facades\Route;

Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
// Which agents cover which zones — one branch at a time.
Route::get('zones/coverage', [ZoneCoverageController::class, 'index'])->name('zones.coverage');
Route::patch('zones/coverage', [ZoneCoverageController::class, 'update'])->name('zones.coverage.update');
Route::put('zones/coverage/limit', [ZoneCoverageController::class, 'updateLimit'])->name('zones.coverage.limit');
Route::get('zones/create', [ZoneController::class, 'create'])->name('zones.create');
Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
Route::post('zones/import', [ZoneController::class, 'import'])->name('zones.import');
Route::post('zones/bulk-delete/preview', [ZoneController::class, 'previewBulkDestroy'])->name('zones.bulk-delete.preview');
Route::post('zones/bulk-delete', [ZoneController::class, 'bulkDestroy'])->name('zones.bulk-delete');
Route::get('zones/import/template', [ZoneController::class, 'importTemplate'])->name('zones.import.template');
Route::get('zones/{zone}/edit', [ZoneController::class, 'edit'])->name('zones.edit');
Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
