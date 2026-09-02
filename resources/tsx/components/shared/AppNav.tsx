import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
    IconBroadcast,
    IconLayoutDashboard,
    IconUsers,
    IconMapPin,
    IconCash,
    IconFileDescription,
    IconUserCog,
    IconSettings,
    IconReportMoney,
    IconBuildingCommunity,
    IconHistory,
    IconUserOff,
    IconChartBar,
    IconMessageReport,
} from '@tabler/icons-react';

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

/**
 * The role-gated nav list, in display order. Identical for the desktop
 * `<aside>` and the mobile drawer — both call this so the item set, order,
 * and gating never fork. `role` is `auth.user?.role ?? null`; a null role
 * (no authenticated user) gets just the ungated base items.
 */
export function buildVisibleNavItems(role: string | null) {
    return [
        ...navItems,
        ...(role !== null && DISCONNECTIONS_ROLES.includes(role) ? [disconnectionsNavItem] : []),
        ...(role !== null && ELIGIBILITY_NAV_ROLES.includes(role) ? [eligibilityNavItem] : []),
        ...(role !== null && REPORTS_ROLES.includes(role) ? [reportsNavItem] : []),
        ...(role !== null && RESOURCES_ROLES.includes(role) ? [resourcesNavItem] : []),
        ...(role !== null && AUDIT_ROLES.includes(role) ? [auditNavItem] : []),
        ...(role !== null && BRANCHES_ROLES.includes(role) ? [branchesNavItem] : []),
        ...(role !== null && SETTINGS_ROLES.includes(role) ? [settingsNavItem] : []),
    ];
}

/**
 * The logo chip + company-name block and the role-gated nav link list,
 * shared verbatim by the desktop `<aside>` (AppLayout) and the mobile
 * off-canvas drawer (MobileNavDrawer) so neither forks the item list,
 * accents, or active-state styling. Rendered as a fragment of two
 * `relative`-positioned blocks so it layers above the sidebar's absolute
 * gradient wash on desktop; `relative` is inert in the drawer.
 *
 * `onNavigate` fires when any link is tapped — the drawer passes its close
 * handler so a tapped link dismisses the drawer immediately (belt-and-braces
 * alongside AppLayout's router 'navigate' listener).
 */
export function AppNav({
    role,
    currentPath,
    companyName,
    onNavigate,
}: {
    role: string | null;
    currentPath: string;
    companyName?: string | null;
    onNavigate?: () => void;
}) {
    const { t } = useTranslation();
    const visibleNavItems = buildVisibleNavItems(role);

    return (
        <>
            <div className="relative flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-200/70 px-4">
                <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 text-white shadow-md shadow-blue-600/25 ring-1 ring-white/20">
                    <IconBroadcast size={19} stroke={1.75} />
                </span>
                <div className="min-w-0 leading-tight">
                    <p className="font-display text-lg text-slate-900">CNCMS</p>
                    {companyName && (
                        <p className="truncate text-[10px] font-medium tracking-wide text-slate-400">
                            {companyName}
                        </p>
                    )}
                </div>
            </div>
            <nav className="relative flex-1 space-y-0.5 overflow-y-auto px-2 py-4">
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
                            onClick={onNavigate}
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
        </>
    );
}
