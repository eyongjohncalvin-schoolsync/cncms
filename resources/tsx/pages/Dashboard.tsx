import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import {
    IconAlertCircle,
    IconCash,
    IconClockExclamation,
    IconPercentage,
    IconReceipt2,
    IconScale,
    IconUserCheck,
    IconUsers,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { formatCurrency } from '@/lib/formatCurrency';

interface DashboardProps {
    period: string;
    /** Cash controls: open cash alerts (deferred; null when the viewer can't see them). */
    cash_alerts?: number | null;
    /** Zones with no active responsible collector (deferred; only for agents.manage). */
    uncovered_zones?: number | null;
    /** docs/plans/expenditures-and-spending-controls.md, items 5–6 — deferred, P&L viewers only. */
    spending?: {
        period: string;
        spent: string;
        income: string;
        net: string;
        deficit: boolean;
        cap: string | null;
        cap_pct: number | null;
        budget_warnings: number;
    } | null;
    stats: {
        total_customers: number;
        active_customers: number;
        pending_verifications: number;
        monthly_income: string;
        /** Verified installation fees this month — income, but kept apart from subscriptions. */
        installation_income?: string;
        total_arrears: string;
        collection_rate: number;
        pending_arrears_adjustments: number;
        /** Field registrations awaiting the office (installation phase 3). */
        pending_activations?: number;
    };
}

export default function Dashboard({ period, stats, cash_alerts, uncovered_zones, spending }: DashboardProps) {
    // Memoized so the derived hint string isn't recomputed on every render
    // (e.g. re-renders triggered by unrelated layout/navigation state) —
    // only when the stats payload itself changes.
    const activePercentHint = useMemo(() => {
        const percent = stats.total_customers ? Math.round((stats.active_customers / stats.total_customers) * 100) : 0;
        return `${percent}% of total`;
    }, [stats.active_customers, stats.total_customers]);

    const hasPending = stats.pending_verifications > 0;
    const hasPendingArrearsAdjustments = stats.pending_arrears_adjustments > 0;

    // Built entirely from the stats payload already sent to this page
    // (monthly_income / total_arrears) — no new backend data required.
    const incomeVsArrearsData = useMemo(
        () => [
            { name: 'Monthly Income', value: Number(stats.monthly_income) || 0 },
            { name: 'Total Arrears', value: Number(stats.total_arrears) || 0 },
        ],
        [stats.monthly_income, stats.total_arrears],
    );

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            {typeof cash_alerts === 'number' && cash_alerts > 0 && (
                <Link
                    href="/cash/alerts"
                    className="mb-4 flex items-center justify-between rounded-lg border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 hover:bg-red-100"
                >
                    <span>
                        <strong>{cash_alerts}</strong> cash control alert{cash_alerts === 1 ? '' : 's'} need attention — cash not handed over, shortages, unsigned vouchers…
                    </span>
                    <span className="font-semibold">Review →</span>
                </Link>
            )}

            {typeof uncovered_zones === 'number' && uncovered_zones > 0 && (
                <Link
                    href="/zones/coverage"
                    className="mb-4 flex items-center justify-between rounded-lg border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800 hover:bg-red-100"
                >
                    <span>
                        <strong>{uncovered_zones}</strong> zone{uncovered_zones === 1 ? ' has' : 's have'} no active collector — nobody is accountable for their money, and
                        their bills print no collector.
                    </span>
                    <span className="font-semibold">Zone coverage →</span>
                </Link>
            )}

            {spending && (spending.deficit || (spending.cap_pct ?? 0) >= 80 || spending.budget_warnings > 0) && (
                <Link
                    href="/resources/expenditures"
                    className={`mb-4 flex items-center justify-between rounded-lg border-l-4 px-4 py-3 text-sm ${
                        spending.deficit ? 'border-red-500 bg-red-50 text-red-800 hover:bg-red-100' : 'border-amber-500 bg-amber-50 text-amber-800 hover:bg-amber-100'
                    }`}
                >
                    <span>
                        {spending.deficit
                            ? `Spending is above income this month: ${formatCurrency(spending.spent)} spent against ${formatCurrency(spending.income)} verified income.`
                            : (spending.cap_pct ?? 0) >= 80
                              ? `${spending.cap_pct}% of the monthly spending cap is committed.`
                              : `${spending.budget_warnings} expense categor${spending.budget_warnings === 1 ? 'y is' : 'ies are'} near or over budget.`}
                    </span>
                    <span className="font-semibold">Expenditures →</span>
                </Link>
            )}

            <div className="mb-6 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <h2 className="font-display text-2xl text-slate-900">Dashboard</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Overview for <span className="font-medium text-slate-700">{period}</span>
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                    <StatCard
                        label="Total Customers"
                        value={stats.total_customers.toLocaleString()}
                        icon={<IconUsers size={20} stroke={1.75} />}
                        tone="blue"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '110ms' }}>
                    <StatCard
                        label="Active Customers"
                        value={stats.active_customers.toLocaleString()}
                        hint={activePercentHint}
                        icon={<IconUserCheck size={20} stroke={1.75} />}
                        tone="green"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '160ms' }}>
                    <StatCard
                        label="Pending Verification"
                        value={stats.pending_verifications.toLocaleString()}
                        hint={hasPending ? 'Action needed' : undefined}
                        icon={hasPending ? <IconAlertCircle size={20} stroke={1.75} /> : <IconClockExclamation size={20} stroke={1.75} />}
                        tone={hasPending ? 'red' : 'yellow'}
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '210ms' }}>
                    <StatCard
                        label="Monthly Income"
                        value={formatCurrency(stats.monthly_income)}
                        hint={
                            Number(stats.installation_income ?? 0) > 0
                                ? `Subscriptions · + ${formatCurrency(stats.installation_income ?? '0')} installations`
                                : 'Subscriptions'
                        }
                        icon={<IconCash size={20} stroke={1.75} />}
                        tone="green"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '260ms' }}>
                    <StatCard
                        label="Total Arrears"
                        value={formatCurrency(stats.total_arrears)}
                        icon={<IconReceipt2 size={20} stroke={1.75} />}
                        tone="red"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '310ms' }}>
                    <StatCard
                        label="Collection Rate"
                        value={`${stats.collection_rate}%`}
                        icon={<IconPercentage size={20} stroke={1.75} />}
                        tone="purple"
                    />
                </div>
                {/*
                    Pending Arrears Adjustments — a nudge to the maker-checker
                    review queue (the "Adjust Arrears" request modal lives on
                    each customer's own page; the approve/reject queue has its
                    own page, /arrears-adjustments, opened on its "Awaiting
                    approval" filter — both pending stages). Same "pending-count-as-a-nudge" idea as "Pending
                    Verification" above, for the same maker-checker shape
                    (App\Services\ArrearsAdjustmentService::dashboard()).
                */}
                <Link
                    href="/arrears-adjustments?status=awaiting_approval"
                    className="animate-fade-up rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-600"
                    style={{ animationDelay: '360ms' }}
                >
                    <StatCard
                        label="Pending Arrears Adjustments"
                        value={stats.pending_arrears_adjustments.toLocaleString()}
                        hint={hasPendingArrearsAdjustments ? 'Awaiting review' : undefined}
                        icon={<IconScale size={20} stroke={1.75} />}
                        tone={hasPendingArrearsAdjustments ? 'red' : 'purple'}
                    />
                </Link>

                {/* Customers registered by field agents awaiting the office
                    (installation phase 3) — the activation queue. */}
                {(stats.pending_activations ?? 0) > 0 && (
                    <Link
                        href="/customers?pending_activation=1"
                        className="animate-fade-up rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                        style={{ animationDelay: '370ms' }}
                    >
                        <StatCard
                            label="Pending Activation"
                            value={(stats.pending_activations ?? 0).toLocaleString()}
                            hint="Registered in the field — not billed yet"
                            icon={<IconUserCheck size={20} stroke={1.75} />}
                            tone="red"
                        />
                    </Link>
                )}
            </div>

            <Card className="mt-4 animate-fade-up p-0" style={{ animationDelay: '380ms' }}>
                <CardHeader>
                    <h2 className="text-sm font-semibold text-slate-900">Income vs. Arrears — {period}</h2>
                </CardHeader>
                <CardBody>
                    <div style={{ width: '100%', height: 240 }}>
                        <ResponsiveContainer>
                            <BarChart data={incomeVsArrearsData}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value)} />
                                <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                                    <Cell fill="#16a34a" />
                                    <Cell fill="#dc2626" />
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
