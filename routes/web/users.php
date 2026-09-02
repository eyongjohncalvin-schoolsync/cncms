<?php

declare(strict_types=1);

use App\Http\Controllers\UsersControlCenter\RoleController;
use App\Http\Controllers\UsersControlCenter\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Users Control Center routes  (RBAC v2 Wave 3)
|--------------------------------------------------------------------------
|
| Top-level `/users`, deliberately NOT under `/settings` — see
| docs/plans/rbac-v2-configurable-roles.md, "Users Control Center (new nav,
| detached from Settings)". Required from routes/web.php inside the
| ['auth','tenant.web','throttle:web'] group.
|
| Two tabs, two controllers:
|   - Users        (UserController)  — gate: users.view / users.manage
|   - Roles & perms (RoleController) — gate: roles.manage
|
| Server-side Policies (TenantUserPolicy, RolePolicy) are the source of
| truth; the nav link is also permission-gated client-side (AppNav.tsx)
| but that is only a UX nicety.
|
*/

// Users tab
Route::get('users', [UserController::class, 'index'])->name('users.index');
Route::post('users', [UserController::class, 'store'])->name('users.store');
Route::patch('users/{tenantUser}', [UserController::class, 'update'])->name('users.update');
Route::post('users/{tenantUser}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');

// Roles & permissions tab
Route::get('users/roles', [RoleController::class, 'index'])->name('users.roles.index');
Route::post('users/roles', [RoleController::class, 'store'])->name('users.roles.store');
Route::patch('users/roles/{role}', [RoleController::class, 'update'])->name('users.roles.update');
Route::delete('users/roles/{role}', [RoleController::class, 'destroy'])->name('users.roles.destroy');
