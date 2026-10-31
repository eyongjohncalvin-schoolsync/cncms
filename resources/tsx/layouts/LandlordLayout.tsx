import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import {
    IconBroadcast,
    IconBuildingSkyscraper,
    IconDatabase,
    IconFileCertificate,
    IconHistory,
    IconLayoutDashboard,
    IconLogout,
    IconShieldLock,
    IconTool,
    IconUserCircle,
    IconUsers,
} from '@tabler/icons-react';
import type { BreadcrumbItem, PageProps } from '@/types';
import { Breadcrumb } from '@/components/ui/Breadcrumb';
import { MaintenanceBanner } from '@/components/shared/MaintenanceBanner';

const navItems = [
    { href: '/landlord', label: 'Dashboard', icon: IconLayoutDashboard, exact: true },
    { href: '/landlord/tenants', label: 'Tenants', icon: IconBuildingSkyscraper },
    { href: '/landlord/users', label: 'Users', icon: IconUsers },
    { href: '/landlord/maintenance', label: 'Maintenance', icon: IconTool },
    { href: '/landlord/backups', label: 'Backups', icon: IconDatabase },
    { href: '/landlord/legal', label: 'Legal documents', icon: IconFileCertificate },
    { href: '/landlord/landlords', label: 'Landlords', icon: IconShieldLock },
    { href: '/landlord/activity', label: 'Activity log', icon: IconHistory },
];

/**
 * Shell for the central/platform-level "landlord" area — kept separate
 * from AppLayout since these pages are conceptually a different app area
 * (managing tenants themselves), not part of any single tenant's own
 * scoped admin panel. Mirrors AppLayout's sidebar+header+footer shell
 * (sticky sidebar, floating header, indigo brand accent instead of
 * AppLayout's blue so the two areas read as visually distinct at a
 * glance) so a landlord user moving between the two doesn't lose their
 * bearings — deliberately not a bare, chrome-less page.
 */
export function LandlordLayout({
    title,
    children,
    breadcrumbs,
}: {
    title: string;
    children: ReactNode;
    /**
     * Optional trail rendered between the header and page content, same
     * contract/shape as AppLayout's — see components/ui/Breadcrumb.tsx.
     * Omit on pages that don't need one; nothing renders.
     */
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { auth, flash, maintenance } = usePage<PageProps>().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    return (
        <div className="flex min-h-screen bg-slate-50">
            <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col overflow-y-auto border-r border-slate-200 bg-white md:flex">
                <div className="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-4">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm shadow-indigo-600/20">
                        <IconBroadcast size={18} stroke={1.75} />
                    </span>
                    <div className="leading-tight">
                        <span className="block text-lg font-bold tracking-tight text-slate-900">CNCMS</span>
                        <span className="block text-[10px] font-semibold uppercase tracking-wide text-indigo-500">Landlord</span>
                    </div>
                </div>
                <nav className="flex-1 space-y-0.5 px-2 py-4">
                    {navItems.map((item) => {
                        const Icon = item.icon;
                        const active = item.exact ? currentPath === item.href : currentPath.startsWith(item.href);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-2.5 rounded-md border-l-[3px] px-2.5 py-2 text-sm font-medium transition-colors ${
                                    active
                                        ? 'border-indigo-600 bg-indigo-100 text-indigo-800'
                                        : 'border-transparent text-slate-600 hover:bg-indigo-50 hover:text-indigo-700'
                                }`}
                            >
                                <Icon size={18} stroke={1.75} className={active ? '' : 'text-indigo-400'} />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
                <div className="border-t border-slate-200 p-3">
                    <Link
                        href="/dashboard"
                        className="flex items-center gap-2 rounded-md px-2.5 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                    >
                        ← Back to my workspace
                    </Link>
                </div>
            </aside>

            <div className="flex flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur-sm">
                    <h1 className="text-base font-semibold text-slate-900">{title}</h1>
                    <div className="flex items-center gap-3">
                        {auth.user && (
                            <Link
                                href="/landlord/account"
                                className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100"
                                title="My account: profile, password, two-factor"
                            >
                                <IconUserCircle size={18} stroke={1.75} className="text-indigo-500" />
                                {auth.user.name}
                            </Link>
                        )}
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                            title="Log out"
                        >
                            <IconLogout size={18} stroke={1.75} />
                        </Link>
                    </div>
                </header>

                {breadcrumbs && breadcrumbs.length > 0 && (
                    <div className="border-b border-slate-200 bg-slate-50/70 px-4 py-2 md:px-6">
                        <Breadcrumb items={breadcrumbs} />
                    </div>
                )}

                <MaintenanceBanner notice={maintenance} />

                <main className="flex-1 p-4 md:p-6">
                    {flash.success && (
                        <div className="mb-4 rounded-md border-l-4 border-green-500 bg-green-100 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{flash.error}</div>
                    )}
                    {children}
                </main>

                <footer className="border-t border-slate-200 bg-white px-4 py-3 text-center text-xs text-slate-400 md:px-6">
                    © {new Date().getFullYear()} ShalomTech — CNCMS
                </footer>
            </div>
        </div>
    );
}
