import { Link, usePage } from '@inertiajs/react';
import { hasPermission } from '@/lib/permissions';
import type { PageProps } from '@/types';

type ResourcesTab = 'dashboard' | 'expenses' | 'approvals' | 'suppliers' | 'settings';

/**
 * Sub-navigation shared by the Resources pages. Each tab is shown only to
 * people whose permissions reach the page behind it (the server-side
 * policies remain the real gate). Scrolls sideways on a phone.
 */
export function ResourcesTabs({ active }: { active: ResourcesTab }) {
    const { auth } = usePage<PageProps>().props;
    const permissions = auth.user?.permissions;
    const can = (permission: string) => hasPermission(permissions, permission);

    const tabs: { key: ResourcesTab; label: string; href: string; visible: boolean }[] = [
        { key: 'dashboard', label: 'Dashboard', href: '/resources', visible: can('expenditures.dashboard') },
        { key: 'expenses', label: 'Expenses', href: '/resources/expenditures', visible: can('expenditures.view') },
        {
            key: 'approvals',
            label: 'Approvals',
            href: '/resources/approvals',
            visible: can('expenditures.approve') || can('expenditures.approve_large') || can('suppliers.manage'),
        },
        { key: 'suppliers', label: 'Suppliers', href: '/resources/suppliers', visible: can('expenditures.view') },
        { key: 'settings', label: 'Budgets & limits', href: '/resources/settings', visible: can('expenditures.view') },
    ];

    return (
        <nav
            aria-label="Resources sections"
            className="-mx-4 mb-5 flex gap-1 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0"
        >
            {tabs
                .filter((tab) => tab.visible)
                .map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={tab.key === active ? 'page' : undefined}
                        className={`shrink-0 rounded-full px-3.5 py-2 text-sm font-medium whitespace-nowrap transition-colors ${
                            tab.key === active
                                ? 'bg-slate-900 text-white'
                                : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 hover:text-slate-900'
                        }`}
                    >
                        {tab.label}
                    </Link>
                ))}
        </nav>
    );
}
