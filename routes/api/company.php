<?php

declare(strict_types=1);

use App\Http\Controllers\CompanyLogoController;
use Illuminate\Support\Facades\Route;

// The signed-in user's own tenant logo for the mobile app — see CompanyLogoController.
Route::get('company/logo', [CompanyLogoController::class, 'show']);
