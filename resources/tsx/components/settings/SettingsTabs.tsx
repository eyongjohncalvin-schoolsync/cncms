import { Link } from '@inertiajs/react';
import { IconBuildingStore, IconTerminal2, IconBellRinging, IconPrinter, IconBroadcast, IconGift, IconCash, IconCalendarDollar, IconSignature } from '@tabler/icons-react';
import { useCan } from '@/lib/permissions';

// "Users & Roles" moved out of Settings entirely in RBAC v2 Wave 3 — it's
// now the top-level "Users Control Center" nav item (/users), gated by the
// `users.view` permission rather than sharing the Settings admin gate.
//
// Each tab shows only to a role holding its controller's permission (owner,
// 2026-11-02: nothing shown that the role wasn't given).
export const SETTINGS_TABS = [
    { href: '/settings/company', label: 'Company Info', icon: IconBuildingStore, permission: 'company.view' },
    { href: '/settings/command-runs', label: 'Command Runs', icon: IconTerminal2, permission: 'command_runs.view' },
    { href: '/settings/notifications', label: 'Notifications', icon: IconBellRinging, permission: 'company.view' },
    { href: '/settings/bill-printing', label: 'Bill Printing', icon: IconPrinter, permission: 'company.view' },
    { href: '/settings/services', label: 'Services', icon: IconBroadcast, permission: 'services.manage' },
    { href: '/settings/installation-offers', label: 'Promotions', icon: IconGift, permission: 'installations.offers' },
    { href: '/settings/prepayment-offers', label: 'Prepayment offers', icon: IconCalendarDollar, permission: 'payments.offers' },
    // Grades also need headquarters (SalaryPolicy::manageScale) — the page says so.
    { href: '/settings/salaries', label: 'Salaries', icon: IconCash, permission: 'salaries.manage' },
    { href: '/settings/contract', label: 'Contract', icon: IconSignature, permission: 'company.sign_contract' },
] as const;

/**
 * Shared sub-navigation across the Settings pages — only the tabs this
 * person's role may open.
 */
export function SettingsTabs({
    active,
}: {
    active: 'company' | 'command-runs' | 'notifications' | 'bill-printing' | 'services' | 'installation-offers' | 'prepayment-offers' | 'salaries' | 'contract';
}) {
    const can = useCan();

    return (
        <div className="mb-6 inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 animate-fade-up">
            {SETTINGS_TABS.filter((tab) => can(tab.permission)).map((tab) => {
                const Icon = tab.icon;
                const isActive = tab.href === `/settings/${active}`;

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
