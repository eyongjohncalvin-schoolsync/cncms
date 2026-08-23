<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
});

require __DIR__.'/web/auth.php';

/*
|--------------------------------------------------------------------------
| Tenant-scoped Inertia pages
|--------------------------------------------------------------------------
|
| Every page below requires a logged-in session AND tenant membership
| (App\Http\Middleware\ResolveTenantWeb). Each resource area owns its own
| route file under routes/web/ (dashboard.php, customers.php, payments.php,
| ...) rather than all being crammed into this one file, so independent
| workstreams building different pages never collide on the same file.
| Add a `require` line here as each new area's route file is created.
|
*/
Route::middleware(['auth', 'tenant.web'])->group(function () {
    require __DIR__.'/web/dashboard.php';
    require __DIR__.'/web/manuscripts.php';
    require __DIR__.'/web/agents.php';
    require __DIR__.'/web/payments.php';
    require __DIR__.'/web/customers.php';
    require __DIR__.'/web/zones.php';
});
