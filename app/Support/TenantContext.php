<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TenantUser;

/**
 * Resolved tenant-membership context for the currently authenticated API
 * request, bound into the container as a singleton by
 * App\Http\Middleware\ResolveTenant.
 *
 * Downstream controllers/policies can read the caller's role for the
 * active tenant either by:
 *
 *   - Type-hinting `App\Support\TenantContext $context` in a controller
 *     method / constructor (resolved via DI), or
 *   - Calling `app(App\Support\TenantContext::class)`, or
 *   - Reading `$request->attributes->get('tenant_role')` /
 *     `$request->attributes->get('tenant_user')`, which the middleware
 *     also sets for convenience.
 */
final class TenantContext
{
    public function __construct(
        public readonly TenantUser $tenantUser,
        public readonly string $role,
    ) {}

    public function is(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAnyOf(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
