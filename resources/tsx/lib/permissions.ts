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
