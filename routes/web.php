<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
});

require __DIR__.'/web/auth.php';
require __DIR__.'/web/register.php';
require __DIR__.'/web/workspace.php';

// Central/platform-level "landlord" pages (tenant management) — own
// top-level require with its own ['auth', 'landlord'] middleware group,
// since these routes must NOT go through tenant.web. See
// routes/web/landlord.php's doc comment.
require __DIR__.'/web/landlord.php';

// Public, signed, unauthenticated receipt-PDF link — its own top-level
// require for the same reason as landlord.php above: it must NOT go through
// ['auth', 'tenant.web']. See that file's doc comment.
require __DIR__.'/web/payment-receipts-public.php';

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
| 'throttle:web' (300/min/user, config/rate-limits.php) is the general
| ceiling for this whole group — generous enough not to interfere with
| normal fast clicking/pagination (or busy test suites), but a real cap
| against a compromised/scripted session hammering these pages. Individual
| export/audit pages (manuscripts/export, customers/{customer}/bill/print,
| audit/logs) layer an additional, tighter limiter on top directly in
| their own route files — the tighter one always trips first.
|
*/
Route::middleware(['auth', 'tenant.web', 'throttle:web'])->group(function () {
    require __DIR__.'/web/dashboard.php';
    require __DIR__.'/web/manuscripts.php';
    require __DIR__.'/web/bills.php';
    require __DIR__.'/web/agents.php';
    require __DIR__.'/web/payments.php';
    require __DIR__.'/web/customers.php';
    require __DIR__.'/web/disconnections.php';
    require __DIR__.'/web/zones.php';
    require __DIR__.'/web/branches.php';
    require __DIR__.'/web/settings.php';
    require __DIR__.'/web/users.php';
    require __DIR__.'/web/audit.php';
    require __DIR__.'/web/resources.php';
    require __DIR__.'/web/reports.php';
    require __DIR__.'/web/notifications.php';
    require __DIR__.'/web/complaints.php';
    require __DIR__.'/web/arrears-adjustments.php';
    require __DIR__.'/web/agent-app.php';
});
