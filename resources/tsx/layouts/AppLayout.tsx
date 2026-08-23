import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import {
    IconLayoutDashboard,
    IconUsers,
    IconMapPin,
    IconCash,
    IconFileDescription,
    IconUserCog,
    IconLogout,
} from '@tabler/icons-react';
import type { PageProps } from '@/types';
import { RoleBadge } from '@/components/shared/StatusBadge';

const navItems = [
    { href: '/dashboard', label: 'Dashboard', icon: IconLayoutDashboard },
    { href: '/customers', label: 'Customers', icon: IconUsers },
    { href: '/zones', label: 'Zones', icon: IconMapPin },
    { href: '/payments', label: 'Payments', icon: IconCash },
    { href: '/manuscripts', label: 'Manuscripts', icon: IconFileDescription },
    { href: '/agents', label: 'Agents', icon: IconUserCog },
];

export function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash } = usePage<PageProps>().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    return (
        <div className="flex min-h-screen bg-slate-50">
            <aside className="hidden w-56 shrink-0 flex-col border-r border-slate-200 bg-white md:flex">
                <div className="flex h-14 items-center border-b border-slate-200 px-4">
                    <span className="text-lg font-semibold text-slate-900">CNCMS</span>
                </div>
                <nav className="flex-1 space-y-0.5 px-2 py-4">
                    {navItems.map((item) => {
                        const Icon = item.icon;
                        const active = currentPath.startsWith(item.href);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                                    active
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                <Icon size={18} stroke={1.75} />
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="flex flex-1 flex-col">
                <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4">
                    <h1 className="text-base font-semibold text-slate-900">{title}</h1>
                    <div className="flex items-center gap-3">
                        {auth.user && (
                            <>
                                <div className="text-right">
                                    <p className="text-sm font-medium text-slate-900">{auth.user.name}</p>
                                </div>
                                <RoleBadge role={auth.user.role} />
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                                    title="Log out"
                                >
                                    <IconLogout size={18} stroke={1.75} />
                                </Link>
                            </>
                        )}
                    </div>
                </header>

                <main className="flex-1 p-4 md:p-6">
                    {flash.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                            {flash.error}
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
