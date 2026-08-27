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
    stats: {
        total_customers: number;
        active_customers: number;
        pending_verifications: number;
        monthly_income: string;
        total_arrears: string;
        collection_rate: number;
        pending_arrears_adjustments: number;
    };
}

export default function Dashboard({ period, stats }: DashboardProps) {
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
                    Pending Arrears Adjustments — the maker-checker review
                    queue's only top-level pointer anywhere in the app (the
                    "Adjust Arrears" request modal lives on each customer's
                    own page, and the approve/reject queue itself lives on
                    the Audit Log page's "Arrears Adjustments" sub-tab; this
                    card is the thing that tells someone that queue exists
                    at all, and links straight to it). Same
                    "pending-count-as-a-nudge" idea as "Pending Verification"
                    above, for the same maker-checker shape
                    (App\Services\ArrearsAdjustmentService::dashboard()).
                */}
                <Link
                    href="/audit/logs?view=arrears_adjustments"
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
