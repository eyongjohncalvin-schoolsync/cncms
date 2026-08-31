import { Link, usePage, usePoll } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    IconBroadcast,
    IconLayoutDashboard,
    IconUsers,
    IconMapPin,
    IconCash,
    IconFileDescription,
    IconUserCog,
    IconLogout,
    IconSettings,
    IconReportMoney,
    IconBuildingSkyscraper,
    IconBuildingCommunity,
    IconHistory,
    IconUserOff,
    IconChartBar,
    IconMessageReport,
} from '@tabler/icons-react';
import type { BreadcrumbItem, PageProps } from '@/types';
import { RoleBadge } from '@/components/shared/StatusBadge';
import { LanguageSwitcher } from '@/components/shared/LanguageSwitcher';
import { NotificationBell } from '@/components/shared/NotificationBell';
import { EmergencyBanner } from '@/components/shared/EmergencyBanner';
import { Breadcrumb } from '@/components/ui/Breadcrumb';

// CoreUI-style nav color coding: each item gets its own accent instead of a
// single blue tone for everything. `active`/`hover` classes are kept
// separate per item so the "active" state (solid tint + left border) reads
// clearly distinct from the lighter "hover" tint.
type NavAccent = {
    active: string;
    hover: string;
    border: string;
    /**
     * Muted tint applied to the icon (not the label text) even when the
     * item is inactive, so the sidebar doesn't read as monochrome-gray
     * with a single colored active item — each item's own accent stays
     * faintly visible at rest, and resolves to full color on hover/active.
     */
    icon: string;
};

const NAV_ACCENTS: Record<string, NavAccent> = {
    blue: { active: 'bg-blue-100 text-blue-800', hover: 'hover:bg-blue-50 hover:text-blue-700', border: 'border-blue-600', icon: 'text-blue-400' },
    indigo: { active: 'bg-indigo-100 text-indigo-800', hover: 'hover:bg-indigo-50 hover:text-indigo-700', border: 'border-indigo-600', icon: 'text-indigo-400' },
    teal: { active: 'bg-teal-100 text-teal-800', hover: 'hover:bg-teal-50 hover:text-teal-700', border: 'border-teal-600', icon: 'text-teal-400' },
    green: { active: 'bg-green-100 text-green-800', hover: 'hover:bg-green-50 hover:text-green-700', border: 'border-green-600', icon: 'text-green-500' },
    amber: { active: 'bg-amber-100 text-amber-800', hover: 'hover:bg-amber-50 hover:text-amber-700', border: 'border-amber-600', icon: 'text-amber-500' },
    purple: { active: 'bg-purple-100 text-purple-800', hover: 'hover:bg-purple-50 hover:text-purple-700', border: 'border-purple-600', icon: 'text-purple-400' },
    pink: { active: 'bg-pink-100 text-pink-800', hover: 'hover:bg-pink-50 hover:text-pink-700', border: 'border-pink-600', icon: 'text-pink-400' },
    cyan: { active: 'bg-cyan-100 text-cyan-800', hover: 'hover:bg-cyan-50 hover:text-cyan-700', border: 'border-cyan-600', icon: 'text-cyan-500' },
    slate: { active: 'bg-slate-200 text-slate-900', hover: 'hover:bg-slate-100 hover:text-slate-800', border: 'border-slate-500', icon: 'text-slate-400' },
    red: { active: 'bg-red-100 text-red-800', hover: 'hover:bg-red-50 hover:text-red-700', border: 'border-red-600', icon: 'text-red-400' },
    // Every other accent is already claimed by an existing nav item (pink =
    // Resources, cyan = Audit, etc.) — orange is genuinely new, reserved for
    // Reports below.
    orange: { active: 'bg-orange-100 text-orange-800', hover: 'hover:bg-orange-50 hover:text-orange-700', border: 'border-orange-600', icon: 'text-orange-400' },
    // Complaint Desk (references/complaint-desk.md section 6) — every other
    // key above is already claimed, and reusing red/amber specifically
    // would make the icon read as alarming even when the queue is empty,
    // contradicting the "calm until actually urgent" rule this app already
    // established for the mobile sync strip. fuchsia is genuinely new.
    fuchsia: { active: 'bg-fuchsia-100 text-fuchsia-800', hover: 'hover:bg-fuchsia-50 hover:text-fuchsia-700', border: 'border-fuchsia-600', icon: 'text-fuchsia-400' },
};

// `labelKey` is an i18next key (resources/tsx/lang/{en,fr}/common.json)
// rather than a literal display string — nav labels are the acceptance
// proof for the language-support infra (see language-support.md section 8,
// rollout step 1). Resolved via t() in the component body below, not here,
// since useTranslation() is a hook and this array is module-level.
// Complaint Desk is deliberately un-gated here (no *_ROLES filter list like
// every other item added below) — references/complaint-desk.md section 6:
// "visible to every role, same tier as Dashboard/Customers/Payments (not
// role-gated like Settings/Resources/Audit)." The feature's entire premise
// is universal visibility; hiding it from any role would contradict that,
// so it's a permanent member of the base navItems array rather than
// conditionally appended like the *_ROLES-gated items further down.
const navItems = [
    { href: '/dashboard', labelKey: 'common.dashboard', icon: IconLayoutDashboard, accent: 'blue' as const },
    { href: '/customers', labelKey: 'common.customers', icon: IconUsers, accent: 'indigo' as const },
    { href: '/zones', labelKey: 'common.zones', icon: IconMapPin, accent: 'teal' as const },
    { href: '/payments', labelKey: 'common.payments', icon: IconCash, accent: 'green' as const },
    { href: '/manuscripts', labelKey: 'common.manuscripts', icon: IconFileDescription, accent: 'amber' as const },
    { href: '/agents', labelKey: 'common.agents', icon: IconUserCog, accent: 'purple' as const },
    { href: '/complaints', labelKey: 'common.complaints', icon: IconMessageReport, accent: 'fuchsia' as const },
];

// Settings is admin-only per web-admin-spec.md's nav spec ("SETTINGS
// [admin only]") — filtered into navItems below rather than always shown,
// same client-side role-gating idea as Agents/Index.tsx's canManage check.
// The server-side Policies (CompanyPolicy, TenantUserPolicy, CommandRunPolicy)
// are the actual source of truth; this only hides the link from other roles.
const settingsNavItem = { href: '/settings/company', labelKey: 'common.settings', icon: IconSettings, accent: 'slate' as const };
const SETTINGS_ROLES = ['super', 'admin'];

// Branches (Manage Branches) is office-only per BranchPolicy::create()
// (super/admin — deliberately narrower than ZonePolicy's super/admin/
// manager, see branches-and-locations.md section 8) — same client-side
// hide-the-link idea as Settings above; BranchPolicy is the real
// server-side gate.
const branchesNavItem = { href: '/branches', labelKey: 'common.branches', icon: IconBuildingCommunity, accent: 'slate' as const };
const BRANCHES_ROLES = ['super', 'admin'];

// Resources (P&L dashboard) is gated to the same roles as
// ExpenditurePolicy::viewDashboard() (super/admin/manager) — agents can
// still record expenditures via /resources/expenditures/create, but the
// dashboard landing page at /resources itself is office-only, so the nav
// link is hidden for agents/workers the same way Settings is hidden for
// non-admins above.
const resourcesNavItem = { href: '/resources', labelKey: 'common.resources', icon: IconReportMoney, accent: 'pink' as const };
const RESOURCES_ROLES = ['super', 'admin', 'manager'];

// Audit Log viewing is gated to the same roles as AuditLogPolicy::viewAny()
// (super/admin/manager) — enforced server-side by the policy; this only
// hides the link client-side for agents/workers, same idea as
// Settings/Resources above. A non-privileged user who navigates there
// directly still just gets a 403 page.
const auditNavItem = { href: '/audit/logs', labelKey: 'common.audit_log', icon: IconHistory, accent: 'cyan' as const };
const AUDIT_ROLES = ['super', 'admin', 'manager'];

// Disconnections (bulk customer status workboard) is gated to the same
// roles as CustomerPolicy::viewStatusBoard() (super/admin/manager) — same
// idea as Resources/Audit/Settings above. Agents/workers keep their
// existing single-customer view access via /customers/{customer}, they just
// don't get this dedicated bulk-action page in the nav.
const disconnectionsNavItem = { href: '/disconnections', labelKey: 'common.disconnections', icon: IconUserOff, accent: 'red' as const };
const DISCONNECTIONS_ROLES = ['super', 'admin', 'manager'];

// `agent` can't reach the status board (still viewStatusBoard-gated,
// super/admin/manager only) but CAN see the arrears-based "flagged for
// non-payment" tab for their own zone (CustomerPolicy::viewEligibilityBoard()
// — see DisconnectionsController::eligibilityIndex()), so they get a
// dedicated nav entry straight into that view instead of the default board.
const eligibilityNavItem = { href: '/disconnections?eligible=1', labelKey: 'common.flagged_customers', icon: IconUserOff, accent: 'red' as const };
const ELIGIBILITY_NAV_ROLES = ['agent'];

// Reports (Daily/Weekly/Monthly — App\Policies\ReportPolicy::view()) is a
// new TOP-LEVEL nav item, deliberately NOT nested under Resources: Resources
// is gated to RESOURCES_ROLES (super/admin/manager), which excludes agent,
// but agents need their own zone-fenced daily figures in the field — see
// App\Http\Controllers\ReportController::defaultTierForRole(). `worker` is
// still excluded, matching ReportPolicy::view()'s role set exactly.
const reportsNavItem = { href: '/reports', labelKey: 'common.reports', icon: IconChartBar, accent: 'orange' as const };
const REPORTS_ROLES = ['super', 'admin', 'manager', 'agent'];

export function AppLayout({
    title,
    children,
    breadcrumbs,
}: {
    title: string;
    children: ReactNode;
    /**
     * Optional trail rendered between the header and page content (e.g.
     * "Home / Customers / John Doe"). Omit entirely on pages that haven't
     * been wired up yet — nothing renders and layout is unaffected.
     */
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { auth, flash, notifications } = usePage<PageProps>().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';
    const role = auth.user?.role ?? null;
    const { t } = useTranslation();

    // Keeps the bell/badge and emergency banner fresh without a real-time
    // push channel (in-app-notifications.md section 1 — polling is the
    // deliberate v1 choice here, not a placeholder for something better).
    // `only: ['notifications']` keeps each tick a cheap partial reload
    // rather than re-fetching the whole page's props. No-ops harmlessly
    // when there's no authenticated user (notifications stays null either
    // way).
    usePoll(20000, { only: ['notifications'] });

    // Drives the header's "floating" shadow/border — flat while at the top
    // of the page, a subtle elevated shadow once content has scrolled
    // beneath it, rather than a shadow that's always present. Guarded for
    // SSR (no `window` at render time) the same way `currentPath` above is.
    const [scrolled, setScrolled] = useState(false);
    useEffect(() => {
        function onScroll() {
            setScrolled(window.scrollY > 4);
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);
    const visibleNavItems = [
        ...navItems,
        ...(role !== null && DISCONNECTIONS_ROLES.includes(role) ? [disconnectionsNavItem] : []),
        ...(role !== null && ELIGIBILITY_NAV_ROLES.includes(role) ? [eligibilityNavItem] : []),
        ...(role !== null && REPORTS_ROLES.includes(role) ? [reportsNavItem] : []),
        ...(role !== null && RESOURCES_ROLES.includes(role) ? [resourcesNavItem] : []),
        ...(role !== null && AUDIT_ROLES.includes(role) ? [auditNavItem] : []),
        ...(role !== null && BRANCHES_ROLES.includes(role) ? [branchesNavItem] : []),
        ...(role !== null && SETTINGS_ROLES.includes(role) ? [settingsNavItem] : []),
    ];

    return (
        <div className="flex min-h-screen bg-slate-50">
            {/* Sidebar — light and airy, on the same blue-slate family as
                the auth pages: a soft white → slate → blue-50 gradient with
                a faint blue glow, a hairline right edge, the auth logo chip,
                and smooth soft-blue nav states. Each item keeps its own
                accent-tinted icon at rest so the list isn't monochrome; the
                active state is a gentle blue pill. */}
            <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col overflow-y-auto border-r border-slate-200/70 bg-slate-50 md:flex">
                <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-screen">
                    <div className="absolute inset-0 bg-gradient-to-b from-white via-slate-50 to-blue-50/50" />
                    <div className="absolute -top-16 left-1/2 h-52 w-52 -translate-x-1/2 rounded-full bg-blue-200/25 blur-3xl" />
                </div>

                <div className="relative flex h-14 shrink-0 items-center gap-2.5 border-b border-slate-200/70 px-4">
                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white shadow-md shadow-blue-600/25 ring-1 ring-blue-600/10">
                        <IconBroadcast size={18} stroke={1.75} />
                    </span>
                    <div className="leading-tight">
                        <p className="font-display text-lg text-slate-900">CNCMS</p>
                        <p className="text-[10px] font-medium tracking-wide text-slate-400">SWECOM PLC</p>
                    </div>
                </div>
                <nav className="relative flex-1 space-y-0.5 px-2 py-4">
                    {visibleNavItems.map((item) => {
                        const Icon = item.icon;
                        // item.href may carry a query string (eligibilityNavItem),
                        // which currentPath (pathname only) never contains — compare
                        // against the path portion so the "Flagged Customers" entry
                        // still highlights correctly.
                        const itemPath = item.href.split('?')[0];
                        const active = item.href === settingsNavItem.href
                            ? currentPath.startsWith('/settings')
                            : currentPath.startsWith(itemPath);
                        const accent = NAV_ACCENTS[item.accent];

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-2.5 rounded-lg border-l-[3px] px-2.5 py-2 text-sm font-medium transition-all duration-150 ${
                                    active
                                        ? 'border-blue-500 bg-blue-50 text-blue-700 shadow-sm shadow-blue-600/[0.06]'
                                        : 'border-transparent text-slate-600 hover:bg-white hover:text-slate-900 hover:shadow-sm hover:shadow-slate-900/[0.04]'
                                }`}
                            >
                                <Icon
                                    size={18}
                                    stroke={1.75}
                                    className={active ? 'text-blue-600' : accent.icon}
                                />
                                {t(item.labelKey)}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="flex flex-1 flex-col">
                <header
                    className={`sticky top-0 z-30 flex h-14 items-center justify-between border-b bg-white/85 px-4 backdrop-blur-md transition-shadow duration-200 md:px-6 ${
                        scrolled ? 'border-slate-200/80 shadow-sm shadow-slate-900/5' : 'border-slate-200/60'
                    }`}
                >
                    {/* Hairline blue accent tying the header to the sidebar's
                        blue-slate palette (matches the auth pages). */}
                    <span
                        aria-hidden="true"
                        className="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-blue-600 via-blue-500 to-transparent"
                    />
                    <h1 className="font-display text-xl text-slate-900">{title}</h1>
                    <div className="flex items-center gap-2 sm:gap-3">
                        {auth.user && (
                            <>
                                {auth.user.is_landlord && (
                                    <Link
                                        href="/landlord/tenants"
                                        className="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-indigo-600 transition-colors hover:bg-indigo-50 hover:text-indigo-700"
                                        title={t('common.landlord')}
                                    >
                                        <IconBuildingSkyscraper size={16} stroke={1.75} />
                                        <span className="hidden sm:inline">{t('common.landlord')}</span>
                                    </Link>
                                )}
                                <NotificationBell />
                                <LanguageSwitcher />
                                <div className="flex items-center gap-2 rounded-full bg-slate-100 py-1 pr-1 pl-3">
                                    <span className="hidden text-sm font-medium text-slate-700 sm:inline">
                                        {auth.user.name}
                                    </span>
                                    <RoleBadge role={auth.user.role} />
                                </div>
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    className="rounded-md p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700"
                                    title={t('common.log_out')}
                                >
                                    <IconLogout size={18} stroke={1.75} />
                                </Link>
                            </>
                        )}
                    </div>
                </header>

                {breadcrumbs && breadcrumbs.length > 0 && (
                    <div className="border-b border-slate-200 bg-slate-50/70 px-4 py-2 md:px-6">
                        <Breadcrumb items={breadcrumbs} />
                    </div>
                )}

                <EmergencyBanner notifications={notifications?.emergency ?? []} />

                <main className="flex-1 p-4 md:p-6">
                    {flash.success && (
                        <div className="mb-4 rounded-md border-l-4 border-green-500 bg-green-100 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">
                            {flash.error}
                        </div>
                    )}
                    {children}
                </main>

                <footer className="border-t border-slate-200 bg-white px-4 py-3 text-center text-xs text-slate-400 md:px-6">
                    {t('common.footer_copyright', { year: new Date().getFullYear() })}
                </footer>
            </div>
        </div>
    );
}
