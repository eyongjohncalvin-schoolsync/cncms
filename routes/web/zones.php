<?php

declare(strict_types=1);

use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;

Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');
Route::get('zones/create', [ZoneController::class, 'create'])->name('zones.create');
Route::post('zones', [ZoneController::class, 'store'])->name('zones.store');
Route::get('zones/{zone}/edit', [ZoneController::class, 'edit'])->name('zones.edit');
Route::patch('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update');
Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy');
