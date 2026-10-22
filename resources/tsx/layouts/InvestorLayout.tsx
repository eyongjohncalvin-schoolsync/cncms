import { Link, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import { IconBroadcast, IconChartBar, IconChecklist, IconLogout } from '@tabler/icons-react';
import type { PageProps } from '@/types';

// Reports (ReportPolicy::view) and Approvals — vouchers above the company's
// limits need every investor's approval (docs/plans/cash-controls.md).
const INVESTOR_LINKS = [
    { href: '/reports', label: 'Reports', icon: IconChartBar },
    { href: '/vouchers', label: 'Approvals', icon: IconChecklist },
];

function InvestorNavLink({ href, label, icon: Icon, active }: (typeof INVESTOR_LINKS)[number] & { active: boolean }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-2.5 rounded-md border-l-[3px] px-2.5 py-2 text-sm font-medium ${
                active ? 'border-amber-600 bg-amber-100 text-amber-800' : 'border-transparent text-slate-600 hover:bg-amber-50 hover:text-amber-700'
            }`}
        >
            <Icon size={18} stroke={1.75} />
            {label}
        </Link>
    );
}

/**
 * Shell for the Investor tier — see references/rbac-permissions.md section
 * 7 and App\Policies\ReportPolicy::view()'s doc comment. Structurally
 * mirrors LandlordLayout.tsx's sidebar+header shell (a distinct file, not a
 * conditional branch inside AppLayout, per that doc's explicit call), with
 * two deliberate differences from LandlordLayout:
 *
 *   - A minimal nav: just Reports and Approvals (INVESTOR_LINKS) — the two
 *     things an investor does.
 *   - No "back to my workspace" link — investors have no other workspace;
 *     LandlordLayout's equivalent link exists because a landlord is also
 *     a normal tenant member elsewhere, which an investor is not (their
 *     tenant_users.role sits at the `worker` floor purely as a defensive
 *     backstop, not because they actually do worker work anywhere).
 *
 * Distinct branding accent (amber, vs AppLayout's blue and LandlordLayout's
 * indigo) so this reads as visually its own area at a glance.
 */
export function InvestorLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, flash } = usePage<PageProps>().props;
    const { url } = usePage();

    return (
        <div className="flex min-h-screen bg-slate-50">
            <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col overflow-y-auto border-r border-slate-200 bg-white md:flex">
                <div className="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-4">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-600 text-white shadow-sm shadow-amber-600/20">
                        <IconBroadcast size={18} stroke={1.75} />
                    </span>
                    <div className="leading-tight">
                        <span className="block text-lg font-bold tracking-tight text-slate-900">CNCMS</span>
                        <span className="block text-[10px] font-semibold uppercase tracking-wide text-amber-600">Investor</span>
                    </div>
                </div>
                <nav className="flex-1 space-y-0.5 px-2 py-4">
                    {INVESTOR_LINKS.map((link) => (
                        <InvestorNavLink key={link.href} {...link} active={url.startsWith(link.href)} />
                    ))}
                </nav>
            </aside>

            <div className="flex flex-1 flex-col">
                <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur-sm">
                    <div className="flex items-center gap-3">
                        <h1 className="text-base font-semibold text-slate-900">{title}</h1>
                        {/* Phones: the sidebar is hidden, so the two pages are linked here. */}
                        <div className="flex gap-1 md:hidden">
                            {INVESTOR_LINKS.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className={`rounded-md px-2 py-1 text-xs font-medium ${url.startsWith(link.href) ? 'bg-amber-100 text-amber-800' : 'text-slate-500'}`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        {auth.user && <span className="text-sm font-medium text-slate-700">{auth.user.name}</span>}
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
