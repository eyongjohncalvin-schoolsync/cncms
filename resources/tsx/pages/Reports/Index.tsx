import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import {
    IconAlertTriangle,
    IconCash,
    IconClockExclamation,
    IconDownload,
    IconReceipt2,
    IconScale,
    IconUserCheck,
    IconUserMinus,
    IconUserPlus,
    IconUsers,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { InvestorLayout } from '@/layouts/InvestorLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import type { StatCardDelta } from '@/components/ui/StatCard';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { DailyReport, MonthlyReport, PageProps, ReportData, ReportDelta, ReportTier, WeeklyReport } from '@/types';

interface ReportsIndexProps {
    tier: ReportTier;
    date: string | null;
    report: ReportData;
    can_export: boolean;
}

const TIER_LABELS: Record<ReportTier, string> = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' };
const AGENT_LAYOUT_ROLES = ['agent'];

// -----------------------------------------------------------------
// Pure date helpers — no date library in this project; only the small set
// of operations the period switcher needs (step by day/week/month, format
// as the native <input type="date"|"month"> value). Deliberately client-
// local Date math for the picker's own bookkeeping — the actual figures are
// always computed server-side in WAT (App\Support\BusinessTimezone), and
// `report.is_current`/`report.label` (both server-computed) are what drive
// the forward-arrow-disabled state and the displayed label, not this.
// -----------------------------------------------------------------
function pad2(n: number): string {
    return n.toString().padStart(2, '0');
}
function toDateStr(d: Date): string {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}
function toMonthStr(d: Date): string {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`;
}
function parseDateStr(s: string): Date {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, (m || 1) - 1, d || 1);
}
function parseMonthStr(s: string): Date {
    const [y, m] = s.split('-').map(Number);
    return new Date(y, (m || 1) - 1, 1);
}
function addDays(d: Date, n: number): Date {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}
function addMonths(d: Date, n: number): Date {
    const r = new Date(d);
    r.setMonth(r.getMonth() + n);
    return r;
}

function deltaWithGood(delta: ReportDelta | null, goodDirection: 'up' | 'down', label: string): StatCardDelta | undefined {
    if (!delta) {
        return undefined;
    }
    return { pct: delta.pct, direction: delta.direction, goodDirection, label };
}

export default function ReportsIndex({ tier, date, report, can_export: canExport }: ReportsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const isAgentLayout = role !== null && AGENT_LAYOUT_ROLES.includes(role);
    // Investor tier — same "one route, server picks payload/scope, frontend
    // picks layout per role" convention this file already uses for the
    // agent-vs-office split above; investor layout selection is decided
    // client-side off the shared `is_investor` flag (App\Http\Middleware\
    // HandleInertiaRequests::share()), same as AGENT_LAYOUT_ROLES is
    // decided off `auth.user.role`. See references/rbac-permissions.md
    // section 7 and resources/tsx/layouts/InvestorLayout.tsx.
    const isInvestor = auth.user?.is_investor ?? false;
    const Layout = isInvestor ? InvestorLayout : AppLayout;

    const [isLoading, setIsLoading] = useState(false);

    // The anchor is the calendar date/month this tier is currently viewing —
    // re-derived whenever the server sends new tier/date props (a real
    // navigation happened), so switching tiers preserves the underlying
    // calendar position (viewing week-of-Aug-12 then tapping Monthly shows
    // August, not the current month) per the task spec.
    const [anchor, setAnchor] = useState<Date>(() => (date ? (tier === 'monthly' ? parseMonthStr(date) : parseDateStr(date)) : new Date()));
    useEffect(() => {
        setAnchor(date ? (tier === 'monthly' ? parseMonthStr(date) : parseDateStr(date)) : new Date());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tier, date]);

    function navigate(nextTier: ReportTier, nextAnchor: Date) {
        const param = nextTier === 'monthly' ? toMonthStr(nextAnchor) : toDateStr(nextAnchor);
        router.get(
            '/reports',
            { tier: nextTier, date: param },
            {
                preserveState: true,
                replace: true,
                onStart: () => setIsLoading(true),
                onFinish: () => setIsLoading(false),
            },
        );
    }

    function switchTier(nextTier: ReportTier) {
        if (nextTier === tier) {
            return;
        }
        navigate(nextTier, anchor);
    }

    function step(direction: -1 | 1) {
        const next = tier === 'daily' ? addDays(anchor, direction) : tier === 'weekly' ? addDays(anchor, direction * 7) : addMonths(anchor, direction);
        navigate(tier, next);
    }

    function onPickDate(value: string) {
        if (!value) {
            return;
        }
        navigate(tier, tier === 'monthly' ? parseMonthStr(value) : parseDateStr(value));
    }

    function resetToCurrent() {
        navigate(tier, new Date());
    }

    const inputValue = tier === 'monthly' ? toMonthStr(anchor) : toDateStr(anchor);
    const resetLabel = tier === 'daily' ? 'Today' : tier === 'weekly' ? 'This week' : 'This month';

    return (
        <Layout title="Reports">
            <Head title="Reports" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 animate-fade-up">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Reports</h1>
                    <p className="text-sm text-slate-500">Operational and financial reporting — distinct from the monthly P&amp;L dashboard.</p>
                </div>
                {canExport && tier === 'monthly' && (
                    <a href={`/reports/export?date=${(report as MonthlyReport).period}`} target="_blank" rel="noreferrer">
                        <Button variant="secondary">
                            <IconDownload size={16} stroke={1.75} className="mr-1.5 inline" />
                            Export PDF
                        </Button>
                    </a>
                )}
            </div>

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 animate-fade-up" style={{ animationDelay: '40ms' }}>
                <div className="inline-flex gap-1 rounded-lg bg-slate-100 p-1">
                    {(['daily', 'weekly', 'monthly'] as ReportTier[]).map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => switchTier(t)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 ${
                                tier === t ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            {TIER_LABELS[t]}
                        </button>
                    ))}
                </div>

                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => step(-1)}
                        aria-label="Previous period"
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600"
                    >
                        ‹
                    </button>
                    <div className="relative inline-flex min-w-[9rem] items-center justify-center">
                        <span className="pointer-events-none px-2 text-sm font-semibold text-slate-900">{report.label}</span>
                        <input
                            type={tier === 'monthly' ? 'month' : 'date'}
                            value={inputValue}
                            onChange={(e) => onPickDate(e.target.value)}
                            aria-label="Jump to a specific period"
                            className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                        />
                    </div>
                    <button
                        type="button"
                        onClick={() => step(1)}
                        disabled={report.is_current}
                        aria-label="Next period"
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-30"
                    >
                        ›
                    </button>
                    {!report.is_current && (
                        <button type="button" onClick={resetToCurrent} className="ml-2 text-xs font-medium text-blue-700 hover:underline">
                            {resetLabel}
                        </button>
                    )}
                    {isLoading && <LoadingSpinner className="ml-2 text-blue-600" label="Loading report" />}
                </div>
            </div>

            {isAgentLayout ? (
                <AgentView tier={tier} report={report} />
            ) : tier === 'daily' ? (
                <DailyView report={report as DailyReport} />
            ) : tier === 'weekly' ? (
                <WeeklyView report={report as WeeklyReport} role={role} />
            ) : (
                <MonthlyView report={report as MonthlyReport} />
            )}
        </Layout>
    );
}

// -----------------------------------------------------------------
// Agent layout — same route, deliberately different (and much lighter)
// rendering: single-column mobile-first stack, 2–3 StatCards, no charts at
// any tier, no zone-comparison table. Primarily exercised on the Daily tier
// (the agent role's default landing tier), but stays sane if an agent
// switches to Weekly/Monthly — those tiers' payloads are still zone-fenced
// on the backend, this view just reads the figures that make sense on a
// phone in the field.
// -----------------------------------------------------------------
function AgentView({ tier, report }: { tier: ReportTier; report: ReportData }) {
    if (tier === 'daily') {
        const r = report as DailyReport;
        return (
            <div className="mx-auto flex max-w-md flex-col gap-4 animate-fade-up">
                <StatCard label="My Collections Today" value={formatCurrency(r.payments.verified)} icon={<IconCash size={20} stroke={1.75} />} tone="green" />
                <StatCard label="Customers Paid" value={r.payments.count.toLocaleString()} icon={<IconUserCheck size={20} stroke={1.75} />} tone="blue" />
                <StatCard
                    label="Customers Outstanding"
                    value={r.pending_queue.count.toLocaleString()}
                    hint={r.pending_queue.count > 0 ? `${formatCurrency(r.pending_queue.total)} pending` : undefined}
                    icon={<IconClockExclamation size={20} stroke={1.75} />}
                    tone={r.pending_queue.count > 0 ? 'yellow' : 'slate'}
                />
                <Card className="p-0">
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Outstanding Payments</h2>
                    </CardHeader>
                    {r.pending_queue.items.length === 0 ? (
                        <CardBody>
                            <EmptyState title="Nothing outstanding" description="No pending payments in your zone right now." />
                        </CardBody>
                    ) : (
                        <ul className="divide-y divide-slate-100">
                            {r.pending_queue.items.map((item) => (
                                <li key={item.uuid} className="flex items-center justify-between px-4 py-2.5 text-sm">
                                    <span className="font-medium text-slate-800">{item.customer_name}</span>
                                    <span className="text-right">
                                        <span className="block font-medium text-slate-900">{formatCurrency(item.amount)}</span>
                                        <span className="block text-xs text-slate-500">{item.age_hours}h ago</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        );
    }

    if (tier === 'weekly') {
        const r = report as WeeklyReport;
        return (
            <div className="mx-auto flex max-w-md flex-col gap-4 animate-fade-up">
                <StatCard label="My Collections This Week" value={formatCurrency(r.collections.verified)} icon={<IconCash size={20} stroke={1.75} />} tone="green" />
                <StatCard label="Payments" value={r.collections.count.toLocaleString()} icon={<IconUserCheck size={20} stroke={1.75} />} tone="blue" />
                <StatCard label="New Customers" value={r.new_customers.toLocaleString()} icon={<IconUserPlus size={20} stroke={1.75} />} tone="purple" />
            </div>
        );
    }

    const r = report as MonthlyReport;
    return (
        <div className="mx-auto flex max-w-md flex-col gap-4 animate-fade-up">
            <StatCard
                label="My Collections This Month"
                value={formatCurrency(r.collections_cash_received.verified)}
                icon={<IconCash size={20} stroke={1.75} />}
                tone="green"
            />
            <StatCard label="Payments" value={r.collections_cash_received.count.toLocaleString()} icon={<IconUserCheck size={20} stroke={1.75} />} tone="blue" />
            <StatCard label="Collection Rate" value={`${r.collection_health.collection_rate}%`} icon={<IconReceipt2 size={20} stroke={1.75} />} tone="purple" />
        </div>
    );
}

// -----------------------------------------------------------------
// Daily — stat cards only + one flat table of today's payments. No charts.
// -----------------------------------------------------------------
function DailyView({ report }: { report: DailyReport }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 animate-fade-up" style={{ animationDelay: '80ms' }}>
                <StatCard
                    label="Collected Today"
                    value={formatCurrency(report.payments.verified)}
                    hint={`${report.payments.count} payments · ${formatCurrency(report.payments.pending)} pending`}
                    icon={<IconCash size={20} stroke={1.75} />}
                    tone="green"
                />
                <StatCard
                    label="Pending Verification"
                    value={report.pending_queue.count.toLocaleString()}
                    hint={report.pending_queue.oldest_age_hours !== null ? `Oldest: ${report.pending_queue.oldest_age_hours}h` : undefined}
                    icon={<IconClockExclamation size={20} stroke={1.75} />}
                    tone={report.pending_queue.count > 0 ? 'yellow' : 'slate'}
                />
                <StatCard
                    label="New Customers"
                    value={report.new_customers.count.toLocaleString()}
                    icon={<IconUserPlus size={20} stroke={1.75} />}
                    tone="blue"
                />
                <StatCard
                    label="Expenditures Today"
                    value={formatCurrency(report.expenditures.total)}
                    hint={`${report.expenditures.count} entries`}
                    icon={<IconReceipt2 size={20} stroke={1.75} />}
                    tone="red"
                />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 animate-fade-up" style={{ animationDelay: '120ms' }}>
                <StatCard
                    label="Verifications Actioned"
                    value={(report.verifications_actioned.approved + report.verifications_actioned.rejected).toLocaleString()}
                    hint={`${report.verifications_actioned.approved} approved · ${report.verifications_actioned.rejected} rejected`}
                    icon={<IconUserCheck size={20} stroke={1.75} />}
                    tone="purple"
                />
                <StatCard
                    label="Offline Sync Arrivals"
                    value={report.offline_sync.count.toLocaleString()}
                    hint={report.offline_sync.count > 0 ? formatCurrency(report.offline_sync.total) : undefined}
                    icon={<IconUsers size={20} stroke={1.75} />}
                    tone="slate"
                />
                {report.status_changes.map((change) => (
                    <StatCard
                        key={`${change.from}-${change.to}`}
                        label={`${change.from ?? '—'} → ${change.to ?? '—'}`}
                        value={change.count.toLocaleString()}
                        icon={<IconUserMinus size={20} stroke={1.75} />}
                        tone="yellow"
                    />
                ))}
            </div>

            <Card className="p-0 animate-fade-up" style={{ animationDelay: '160ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Today's Payments</h2>
                </CardHeader>
                {report.payments_today.length === 0 ? (
                    <CardBody>
                        <EmptyState title="No payments recorded" description="Nothing has been recorded for this day yet." />
                    </CardBody>
                ) : (
                    <Table>
                        <TableHead>
                            <Th>Customer</Th>
                            <Th>Zone</Th>
                            <Th>Amount</Th>
                            <Th>Status</Th>
                            <Th>Time</Th>
                        </TableHead>
                        <TableBody>
                            {report.payments_today.map((row) => (
                                <tr key={row.uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{row.customer_name}</Td>
                                    <Td>{row.zone_name}</Td>
                                    <Td>{formatCurrency(row.amount)}</Td>
                                    <Td>
                                        <VerificationBadge status={row.verification_status} />
                                    </Td>
                                    <Td>{new Date(row.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
        </div>
    );
}

// -----------------------------------------------------------------
// Weekly — stat card row + exactly one 7-bar BarChart + a zone/branch
// breakdown table.
// -----------------------------------------------------------------
function WeeklyView({ report, role }: { report: WeeklyReport; role: string | null }) {
    const chartData = useMemo(
        () => report.daily_breakdown.map((point) => ({ date: point.date.slice(5), value: Number(point.verified) })),
        [report.daily_breakdown],
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 animate-fade-up" style={{ animationDelay: '80ms' }}>
                <StatCard
                    label="Collections This Week"
                    value={formatCurrency(report.collections.verified)}
                    delta={deltaWithGood(report.deltas.collections_total, 'up', 'vs last week')}
                    hint={report.deltas.collections_total ? undefined : '—'}
                    icon={<IconCash size={20} stroke={1.75} />}
                    tone="green"
                />
                <StatCard
                    label="Payments"
                    value={report.collections.count.toLocaleString()}
                    delta={deltaWithGood(report.deltas.payment_count, 'up', 'vs last week')}
                    hint={report.deltas.payment_count ? undefined : '—'}
                    icon={<IconUserCheck size={20} stroke={1.75} />}
                    tone="blue"
                />
                <StatCard
                    label="New Customers"
                    value={report.new_customers.toLocaleString()}
                    delta={deltaWithGood(report.deltas.new_customers, 'up', 'vs last week')}
                    hint={report.deltas.new_customers ? undefined : '—'}
                    icon={<IconUserPlus size={20} stroke={1.75} />}
                    tone="purple"
                />
                <StatCard
                    label="Net Disconnections"
                    value={report.net_disconnections.toLocaleString()}
                    delta={deltaWithGood(report.deltas.net_disconnections, 'down', 'vs last week')}
                    hint={report.deltas.net_disconnections ? undefined : '—'}
                    icon={<IconUserMinus size={20} stroke={1.75} />}
                    tone={report.net_disconnections > 0 ? 'red' : 'slate'}
                />
            </div>

            <Card className="animate-fade-up" style={{ animationDelay: '140ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Collections Per Day</h2>
                </CardHeader>
                <CardBody>
                    <ErrorBoundary compact>
                        <div
                            style={{ width: '100%', height: 260 }}
                            role="img"
                            aria-label={`Bar chart of verified collections per day this week, totalling ${formatCurrency(report.collections.verified)}.`}
                        >
                            <ResponsiveContainer>
                                <BarChart data={chartData}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                    <XAxis dataKey="date" tick={{ fontSize: 12 }} />
                                    <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value)} />
                                    <Tooltip formatter={(value) => formatCurrency(Number(value) || 0)} />
                                    <Bar dataKey="value" radius={[4, 4, 0, 0]} fill="#16a34a" />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ErrorBoundary>
                </CardBody>
            </Card>

            {report.league_table.length > 0 && (
                <Card className="p-0 animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">
                            {role === 'manager' ? 'Zone League Table (Your Branch)' : 'Zone League Table'}
                        </h2>
                    </CardHeader>
                    <Table>
                        <TableHead>
                            <Th>Zone</Th>
                            <Th>Collected</Th>
                            <Th>Expected</Th>
                            <Th>Ratio</Th>
                        </TableHead>
                        <TableBody>
                            {report.league_table.map((row) => (
                                <tr key={row.zone_uuid} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{row.zone_name}</Td>
                                    <Td>{formatCurrency(row.collected)}</Td>
                                    <Td>{formatCurrency(row.expected)}</Td>
                                    <Td>
                                        <Badge tone={row.ratio_pct >= 80 ? 'green' : row.ratio_pct >= 50 ? 'yellow' : 'red'}>{row.ratio_pct}%</Badge>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {report.verification_sla.length > 0 && (
                <Card className="p-0 animate-fade-up" style={{ animationDelay: '240ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Verification SLA — Pending &gt; 7 Days</h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            manuscript:calculate excludes non-verified payments — this backlog is unbilled revenue.
                        </p>
                    </CardHeader>
                    <Table>
                        <TableHead>
                            <Th>Branch</Th>
                            <Th>Count</Th>
                            <Th>Amount</Th>
                        </TableHead>
                        <TableBody>
                            {report.verification_sla.map((row) => (
                                <tr key={row.branch_name} className="transition-colors hover:bg-slate-50/70">
                                    <Td className="font-medium text-slate-900">{row.branch_name}</Td>
                                    <Td>
                                        <span className="inline-flex items-center gap-1 text-red-700">
                                            <IconAlertTriangle size={14} stroke={1.75} />
                                            {row.count}
                                        </span>
                                    </Td>
                                    <Td>{formatCurrency(row.total)}</Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </div>
    );
}

// -----------------------------------------------------------------
// Monthly — stat card row + day-by-day trend (AreaChart) + a breakdown
// chart-adjacent table + link to the full P&L dashboard.
// -----------------------------------------------------------------
function MonthlyView({ report }: { report: MonthlyReport }) {
    const trendData = useMemo(
        () => report.trend.map((point) => ({ date: point.date.slice(8), value: Number(point.verified) })),
        [report.trend],
    );

    const arrears = report.collection_health.arrears_aging;
    const arrearsData = useMemo(
        () => [
            { name: '1x bill', value: arrears['1x'] },
            { name: '2x bill', value: arrears['2x'] },
            { name: '3x+ bill', value: arrears['3x_plus'] },
        ],
        [arrears],
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5 animate-fade-up" style={{ animationDelay: '80ms' }}>
                <StatCard
                    label="Collections (cash received)"
                    value={formatCurrency(report.collections_cash_received.verified)}
                    hint={`${report.collections_cash_received.count} payments, all statuses shown separately`}
                    icon={<IconCash size={20} stroke={1.75} />}
                    tone="green"
                />
                <StatCard
                    label="Arrears Adjustments (written off)"
                    value={formatCurrency(report.arrears_adjustments_written_off.total)}
                    hint={`${report.arrears_adjustments_written_off.count} adjustment${report.arrears_adjustments_written_off.count === 1 ? '' : 's'} — not a payment`}
                    icon={<IconScale size={20} stroke={1.75} />}
                    tone="blue"
                />
                <StatCard
                    label="Collection Rate (billing ledger)"
                    value={`${report.collection_health.collection_rate}%`}
                    icon={<IconReceipt2 size={20} stroke={1.75} />}
                    tone="purple"
                />
                <StatCard
                    label="3x+ Arrears Customers"
                    value={arrears['3x_plus'].toLocaleString()}
                    hint="Same threshold as the arrears-eligibility board"
                    icon={<IconAlertTriangle size={20} stroke={1.75} />}
                    tone={arrears['3x_plus'] > 0 ? 'red' : 'slate'}
                />
                <StatCard
                    label="Billing Run"
                    value={report.billing_ledger ? `${report.billing_ledger.customers_processed ?? 0} customers` : 'Not run'}
                    hint={report.billing_ledger?.ran_at ? new Date(report.billing_ledger.ran_at).toLocaleString() : undefined}
                    icon={<IconUsers size={20} stroke={1.75} />}
                    tone={report.billing_ledger ? 'blue' : 'yellow'}
                />
            </div>

            {report.billing_ledger === null && (
                <EmptyState
                    title="Billing run not yet executed for this period"
                    description="Run manuscript:calculate for this period to populate the billing (ledger) figures."
                />
            )}

            <Card className="animate-fade-up" style={{ animationDelay: '140ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Collections Trend — {report.label}</h2>
                </CardHeader>
                <CardBody>
                    {trendData.length === 0 ? (
                        <EmptyState title="No payments recorded" description="No payments were recorded for this period." />
                    ) : (
                        <ErrorBoundary compact>
                            <div
                                style={{ width: '100%', height: 260 }}
                                role="img"
                                aria-label={`Line chart of daily verified collections across ${report.label}, totalling ${formatCurrency(report.collections_cash_received.verified)}.`}
                            >
                                <ResponsiveContainer>
                                    <AreaChart data={trendData}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                        <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                                        <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value)} />
                                        <Tooltip formatter={(value) => formatCurrency(Number(value) || 0)} />
                                        <Area type="monotone" dataKey="value" stroke="#2563eb" fill="#bfdbfe" />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        </ErrorBoundary>
                    )}
                </CardBody>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Arrears Aging</h2>
                    </CardHeader>
                    <CardBody>
                        <ErrorBoundary compact>
                            <div
                                style={{ width: '100%', height: 260 }}
                                role="img"
                                aria-label={`Bar chart of active customers by arrears aging bucket: ${arrearsData.map((d) => `${d.name} ${d.value}`).join(', ')}.`}
                            >
                                <ResponsiveContainer>
                                    <BarChart data={arrearsData}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                        <YAxis allowDecimals={false} />
                                        <Tooltip />
                                        <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                                            <Cell fill="#facc15" />
                                            <Cell fill="#f97316" />
                                            <Cell fill="#dc2626" />
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </ErrorBoundary>
                    </CardBody>
                </Card>

                <Card className="p-0 animate-fade-up" style={{ animationDelay: '240ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Billing (ledger)</h2>
                        {report.billing_ledger?.ran_at && (
                            <p className="mt-0.5 text-xs text-slate-500">Run at {new Date(report.billing_ledger.ran_at).toLocaleString()}</p>
                        )}
                    </CardHeader>
                    <CardBody>
                        {report.billing_ledger === null ? (
                            <EmptyState title="Billing run not yet executed" description="No manuscript:calculate run found for this period." />
                        ) : (
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt className="text-slate-500">Total Billed</dt>
                                    <dd className="font-medium text-slate-900">{formatCurrency(report.billing_ledger.total_bill_sum ?? '0')}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Total Arrears</dt>
                                    <dd className="font-medium text-slate-900">{formatCurrency(report.billing_ledger.total_arrears_sum ?? '0')}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Total Credit</dt>
                                    <dd className="font-medium text-slate-900">{formatCurrency(report.billing_ledger.total_credit_sum ?? '0')}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Frozen Customers</dt>
                                    <dd className="font-medium text-slate-900">{report.billing_ledger.frozen_customers ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Errors</dt>
                                    <dd className={`font-medium ${report.billing_ledger.errors ? 'text-red-600' : 'text-slate-900'}`}>
                                        {report.billing_ledger.errors ?? 0}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">Duration</dt>
                                    <dd className="font-medium text-slate-900">{report.billing_ledger.duration_ms ? `${report.billing_ledger.duration_ms}ms` : '—'}</dd>
                                </div>
                            </dl>
                        )}
                    </CardBody>
                </Card>
            </div>

            {report.pnl && (
                <Card className="animate-fade-up p-4" style={{ animationDelay: '280ms' }}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-900">Profit &amp; Loss</h2>
                            <p className="text-xs text-slate-500">
                                Net {formatCurrency(report.pnl.pnl.net)} · Margin {report.pnl.pnl.margin_pct}%
                            </p>
                        </div>
                        <Link href={`/resources?period=${report.period}`} className="text-sm font-medium text-blue-700 hover:underline">
                            View full P&amp;L →
                        </Link>
                    </div>
                </Card>
            )}
        </div>
    );
}
