import { Link } from '@inertiajs/react';
import { IconUsersGroup, IconShieldLock } from '@tabler/icons-react';

const tabs = [
    { href: '/users', label: 'Users', icon: IconUsersGroup, key: 'users' as const },
    { href: '/users/roles', label: 'Roles & permissions', icon: IconShieldLock, key: 'roles' as const },
];

/**
 * Sub-navigation for the Users Control Center's two pages (RBAC v2 Wave 3).
 * Mirrors components/settings/SettingsTabs.tsx exactly in styling — the same
 * pill group — but sits on the detached `/users` path, not `/settings`.
 * Both destinations are server-gated: /users by TenantUserPolicy::viewAny
 * (`users.view`), /users/roles by RolePolicy::viewAny (`roles.manage`), so a
 * user with only `users.view` who clicks "Roles & permissions" gets a 403.
 */
export function UsersControlCenterTabs({ active }: { active: 'users' | 'roles' }) {
    return (
        <div className="mb-6 inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 animate-fade-up">
            {tabs.map((tab) => {
                const Icon = tab.icon;
                const isActive = tab.key === active;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                            isActive ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        <Icon size={16} stroke={1.75} />
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
