import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * RBAC v2 (docs/plans/rbac-v2-configurable-roles.md): client-side permission
 * check against the resolved per-role matrix shared on
 * `auth.user.permissions` (`['*']` for a super role). This is a DISPLAY
 * affordance only — every real gate is the server-side Policy/controller,
 * which resolves through the exact same matrix. Mirrors the tiny `can`
 * helper inside `buildVisibleNavItems` (components/shared/AppNav.tsx), the
 * mobile `useAuth().can()`, and `TenantContext::can()` on the backend.
 */
export function hasPermission(permissions: string[] | null | undefined, permission: string): boolean {
    if (!permissions) {
        return false;
    }

    return permissions.includes('*') || permissions.includes(permission);
}

/**
 * `const can = useCan(); can('customers.create')` — the signed-in person's
 * permissions from the shared props, so a page shows only what its role may
 * use (owner, 2026-11-02: nothing shown that the role wasn't given).
 */
export function useCan(): (permission: string) => boolean {
    const permissions = usePage<PageProps>().props.auth.user?.permissions;

    return (permission: string) => hasPermission(permissions, permission);
}
