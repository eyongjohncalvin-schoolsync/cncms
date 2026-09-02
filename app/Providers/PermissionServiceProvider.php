<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\Permission;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires RBAC v2 (docs/plans/rbac-v2-configurable-roles.md) into Laravel's
 * Gate so `$user->can('customers.create')`, `@can`, `Gate::allows(...)`,
 * and (Wave 2) the Policy classes all resolve through the role→permission
 * matrix.
 *
 * Wave 1 scope: this provider ONLY adds the new dotted-string abilities and
 * the super bypass. It changes no Policy method and no controller, so the
 * only observable effect today is that the new abilities exist (consumed by
 * the Inertia share, Api\AuthController::me(), and the Wave 1 tests) and
 * that a `super` role short-circuits Gate checks — which every current
 * Policy already grants super anyway via `isAnyOf('super', ...)`.
 */
class PermissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The single hardcoded rule that stays (plan doc): `super` bypasses
        // every check, so a misconfigured matrix can never lock the owner
        // out. Returning null (not false) when not super leaves the normal
        // Gate/Policy resolution untouched. Guarded by app()->bound() so
        // central-context requests (landlord area, console, pre-tenant
        // auth) — where no TenantContext exists — are unaffected.
        //
        // Wave 1 scopes the bypass to THIS catalog's abilities only — it
        // must not yet override the existing Policy classes (e.g.
        // ComplaintPolicy::resolve / ArrearsAdjustmentPolicy::approve deny a
        // super in specific maker≠checker cases; those policies still run
        // their own `isAnyOf` logic until Wave 2 rewrites them). Wave 2
        // decides whether to broaden this to a truly global bypass.
        $catalog = array_flip(Permission::values());

        Gate::before(function ($user, string $ability) use ($catalog): ?bool {
            if (! isset($catalog[$ability]) || ! app()->bound(TenantContext::class)) {
                return null;
            }

            return app(TenantContext::class)->isSuper() ? true : null;
        });

        // One Gate ability per catalog entry. Each delegates to the
        // per-request-cached resolution on TenantContext. `app()->bound()`
        // keeps these false (not erroring) outside a resolved tenant.
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn ($user): bool => app()->bound(TenantContext::class)
                    && app(TenantContext::class)->can($permission->value),
            );
        }
    }
}
