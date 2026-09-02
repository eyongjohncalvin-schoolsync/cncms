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
    IconDeviceMobile,
    IconShieldLock,
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
    // "Agent App" (mobile build download) — every accent above is claimed;
    // lime is the last unused Tailwind hue that stays distinct from green
    // (Payments) and teal (Zones) at the muted icon tint.
    lime: { active: 'bg-lime-100 text-lime-800', hover: 'hover:bg-lime-50 hover:text-lime-700', border: 'border-lime-600', icon: 'text-lime-500' },
    // "Users Control Center" (RBAC v2 Wave 3) — sky is the one remaining
    // blue-family hue not used by Dashboard (blue) / Customers (indigo) /
    // Audit (cyan), fitting for an access-control surface without colliding.
    sky: { active: 'bg-sky-100 text-sky-800', hover: 'hover:bg-sky-50 hover:text-sky-700', border: 'border-sky-600', icon: 'text-sky-400' },
};

// `labelKey` is an i18next key (resources/tsx/lang/{en,fr}/common.json)
// rather than a literal display string — nav labels are the acceptance
// proof for the language-support infra (see language-support.md section 8,
// rollout step 1). Resolved via t() in the component body below, not here,
// since useTranslation() is a hook and this array is module-level.
//
// RBAC v2 Wave 4: every item now carries an optional `permission` string
// checked against `auth.user.permissions` (the resolved per-role matrix
// from HandleInertiaRequests::share(), `['*']` for super) — no more
// hardcoded `*_ROLES` arrays keyed off `auth.user.role`. `permission`
// omitted (dashboard) = always shown to any authenticated user. Every
// seeded system role holds `customers.view` / `zones.view` / `payments.view`
// / `agents.view` / `complaints.view`, so gating those base items changes
// nothing for existing users — but a future custom role without, say,
// `payments.view` correctly won't see Payments. (`manuscripts.view` is NOT
// seeded to `worker`, matching ManuscriptPolicy::viewAny, so a worker
// stops seeing a Manuscripts link that only ever 403'd for them anyway.)
// Complaint Desk keeps its "visible to every role" premise
// (references/complaint-desk.md section 6) via `complaints.view`, which
// every seeded role holds.
const navItems = [
    { href: '/dashboard', labelKey: 'common.dashboard', icon: IconLayoutDashboard, accent: 'blue' as const },
    { href: '/customers', labelKey: 'common.customers', icon: IconUsers, accent: 'indigo' as const, permission: 'customers.view' },
    { href: '/zones', labelKey: 'common.zones', icon: IconMapPin, accent: 'teal' as const, permission: 'zones.view' },
    { href: '/payments', labelKey: 'common.payments', icon: IconCash, accent: 'green' as const, permission: 'payments.view' },
    { href: '/manuscripts', labelKey: 'common.manuscripts', icon: IconFileDescription, accent: 'amber' as const, permission: 'manuscripts.view' },
    { href: '/agents', labelKey: 'common.agents', icon: IconUserCog, accent: 'purple' as const, permission: 'agents.view' },
    { href: '/complaints', labelKey: 'common.complaints', icon: IconMessageReport, accent: 'fuchsia' as const, permission: 'complaints.view' },
];

// RBAC v2 Wave 4: the conditionally-appended items below are each gated by
// a permission string (or a small `canAny` of them) resolved against
// `auth.user.permissions`, replacing the old hardcoded `*_ROLES` arrays.
// The permission named on each is the one its destination's server-side
// Policy / controller actually checks after Wave 2's enforcement swap
// (docs/plans/rbac-v2-configurable-roles.md's "Wave 1: final catalog"), so
// the nav link and the real gate never disagree.

// Settings — there is no single "settings" permission; the real gates on
// the Settings surface are CompanyPolicy::update (`company.update`),
// CommandRunPolicy::viewAny (`command_runs.view`), and ServicePolicy
// (`services.manage`, services.md sections 6-7). Shown if the user holds
// any of the three.
const settingsNavItem = { href: '/settings/company', labelKey: 'common.settings', icon: IconSettings, accent: 'slate' as const };

// Manage Branches — BranchPolicy::create/update/delete → `branches.manage`.
const branchesNavItem = { href: '/branches', labelKey: 'common.branches', icon: IconBuildingCommunity, accent: 'slate' as const };

// Resources (P&L dashboard) — ExpenditurePolicy::viewDashboard →
// `expenditures.dashboard`. Agents can still record expenditures via
// /resources/expenditures/create (that's `expenditures.create`), but the
// dashboard landing page itself is `expenditures.dashboard`.
const resourcesNavItem = { href: '/resources', labelKey: 'common.resources', icon: IconReportMoney, accent: 'pink' as const };

// Audit Log — AuditLogPolicy::viewAny → `audit.view`.
const auditNavItem = { href: '/audit/logs', labelKey: 'common.audit_log', icon: IconHistory, accent: 'cyan' as const };

// Disconnections (bulk customer status workboard) — CustomerPolicy::
// viewStatusBoard → `customers.status_board`.
const disconnectionsNavItem = { href: '/disconnections', labelKey: 'common.disconnections', icon: IconUserOff, accent: 'red' as const };

// `agent` can't reach the status board (`customers.status_board`) but CAN
// see the arrears-based "flagged for non-payment" tab for their own zone
// (CustomerPolicy::viewEligibilityBoard → `customers.eligibility_board`, see
// DisconnectionsController::eligibilityIndex()). This is the agent's variant
// entry into the same board, so it's shown only when the user has
// `customers.eligibility_board` but NOT `customers.status_board` — anyone
// with the full board gets `disconnectionsNavItem` instead.
const eligibilityNavItem = { href: '/disconnections?eligible=1', labelKey: 'common.flagged_customers', icon: IconUserOff, accent: 'red' as const };

// Reports (Daily/Weekly/Monthly) — ReportPolicy::view → `reports.view`.
// Deliberately a TOP-LEVEL item, not nested under Resources: agents hold
// `reports.view` (for their zone-fenced field figures — see
// ReportController::defaultTierForRole()) but not `expenditures.dashboard`.
const reportsNavItem = { href: '/reports', labelKey: 'common.reports', icon: IconChartBar, accent: 'orange' as const };

// "Get the Agent App" (/agent-app) — the mobile field app's install page.
// AgentAppController checks the same S A M G set as ManuscriptPolicy::
// viewAny, and there is no dedicated `mobile.sync`/`agent_app` permission
// (deliberately deferred — see the plan's Wave 2 notes), so `manuscripts.view`
// is the agreed proxy: it resolves to exactly that role set and
// AgentAppController was migrated to it in Wave 2.
const agentAppNavItem = { href: '/agent-app', labelKey: 'common.agent_app', icon: IconDeviceMobile, accent: 'lime' as const };

// "Users Control Center" (/users) — RBAC v2 Wave 3. Shown when the resolved
// permission list contains `users.view`. TenantUserPolicy::viewAny is the
// real server-side gate on /users.
const usersControlCenterNavItem = { href: '/users', labelKey: 'common.users_control_center', icon: IconShieldLock, accent: 'sky' as const };

/**
 * The permission-gated nav list, in display order. Identical for the desktop
 * `<aside>` and the mobile drawer — both call this so the item set, order,
 * and gating never fork. `permissions` is `auth.user?.permissions ?? []`
 * (the resolved per-role matrix from HandleInertiaRequests::share(), `['*']`
 * for super); an empty list (no authenticated user) gets only the ungated
 * dashboard. RBAC v2 Wave 4: `role` is no longer needed here — every gate is
 * now a permission check.
 */
export function buildVisibleNavItems(permissions: string[] = []) {
    const can = (permission: string) => permissions.includes('*') || permissions.includes(permission);
    const canAny = (...perms: string[]) => perms.some(can);

    return [
        ...navItems.filter((item) => !item.permission || can(item.permission)),
        ...(can('customers.status_board') ? [disconnectionsNavItem] : []),
        // The agent variant — only when the user has the eligibility board
        // but NOT the full status board (which supersedes it).
        ...(can('customers.eligibility_board') && !can('customers.status_board') ? [eligibilityNavItem] : []),
        ...(can('reports.view') ? [reportsNavItem] : []),
        ...(can('manuscripts.view') ? [agentAppNavItem] : []),
        ...(can('expenditures.dashboard') ? [resourcesNavItem] : []),
        ...(can('audit.view') ? [auditNavItem] : []),
        ...(can('branches.manage') ? [branchesNavItem] : []),
        ...(can('users.view') ? [usersControlCenterNavItem] : []),
        ...(canAny('company.update', 'command_runs.view', 'services.manage') ? [settingsNavItem] : []),
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
    permissions = [],
    currentPath,
    companyName,
    onNavigate,
}: {
    /** auth.user.permissions from the Inertia share (`['*']` for super). */
    permissions?: string[];
    currentPath: string;
    companyName?: string | null;
    onNavigate?: () => void;
}) {
    const { t } = useTranslation();
    const visibleNavItems = buildVisibleNavItems(permissions);

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
