<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

// "My account" page for workspace users — the signed-in user's own profile
// and security, inside the ['auth', 'tenant.web'] group so it has the app
// chrome. Its writes live in routes/web/account-actions.php (no workspace
// needed), shared with the landlord's /landlord/account.
Route::get('account', [AccountController::class, 'show'])->name('account.show');
