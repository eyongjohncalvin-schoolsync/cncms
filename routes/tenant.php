<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

// CNCMS resolves tenancy server-side from the authenticated user's
// tenant_users membership (see App\Http\Middleware\ResolveTenant), not from
// the request domain — this domain-based group is unused scaffolding kept
// only as a smoke-test route. It must NOT use `/`: Laravel's RouteCollection
// keys routes by method+domain+uri, so a domain-less `/` here would silently
// overwrite routes/web.php's real `/` route (confirmed via `route:list`).
Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/tenant-info', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });
});
