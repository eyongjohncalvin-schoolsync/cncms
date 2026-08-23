<?php

declare(strict_types=1);

use App\Http\Controllers\ManuscriptController;
use Illuminate\Support\Facades\Route;

Route::get('manuscripts', [ManuscriptController::class, 'index'])->name('manuscripts.index');
Route::get('manuscripts/export', [ManuscriptController::class, 'export'])->name('manuscripts.export');
Route::post('manuscripts/calculate', [ManuscriptController::class, 'calculate'])->name('manuscripts.calculate');
