import { Link } from '@inertiajs/react';
import { IconBuildingStore, IconUsersGroup, IconTerminal2, IconBellRinging, IconPrinter } from '@tabler/icons-react';

const tabs = [
    { href: '/settings/company', label: 'Company Info', icon: IconBuildingStore },
    { href: '/settings/users', label: 'Users & Roles', icon: IconUsersGroup },
    { href: '/settings/command-runs', label: 'Command Runs', icon: IconTerminal2 },
    { href: '/settings/notifications', label: 'Notifications', icon: IconBellRinging },
    { href: '/settings/bill-printing', label: 'Bill Printing', icon: IconPrinter },
];

/**
 * Shared sub-navigation across the Settings pages (Company/Users/
 * CommandRuns/Notifications/BillPrinting) — all gated to the same roles as
 * the sidebar's single "Settings" nav item (App\Policies\CompanyPolicy::
 * view(), TenantUserPolicy::viewAny(), CommandRunPolicy::viewAny(),
 * NotificationSettingPolicy::view()). Bill Printing reuses CompanyPolicy
 * (App\Http\Controllers\SettingsBillPrintingController), same as Company
 * Info. The sidebar only links to /settings/company, so without this a
 * user landing there had no way to reach the other tabs short of typing
 * the URL.
 */
export function SettingsTabs({ active }: { active: 'company' | 'users' | 'command-runs' | 'notifications' | 'bill-printing' }) {
    return (
        <div className="mb-6 inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1 animate-fade-up">
            {tabs.map((tab) => {
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
