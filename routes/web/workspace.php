<?php

declare(strict_types=1);

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('workspace/pending', [WorkspaceController::class, 'pending'])
    ->middleware('auth')
    ->name('workspace.pending');
