import { Head } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
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
    };
}

export default function Dashboard({ period, stats }: DashboardProps) {
    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />

            <p className="mb-4 text-sm text-slate-500">Period: {period}</p>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <StatCard label="Total Customers" value={stats.total_customers.toLocaleString()} />
                <StatCard
                    label="Active Customers"
                    value={stats.active_customers.toLocaleString()}
                    hint={`${stats.total_customers ? Math.round((stats.active_customers / stats.total_customers) * 100) : 0}% of total`}
                />
                <StatCard
                    label="Pending Verification"
                    value={stats.pending_verifications.toLocaleString()}
                    hint={stats.pending_verifications > 0 ? 'Action needed' : undefined}
                />
                <StatCard label="Monthly Income" value={formatCurrency(stats.monthly_income)} />
                <StatCard label="Total Arrears" value={formatCurrency(stats.total_arrears)} />
                <StatCard label="Collection Rate" value={`${stats.collection_rate}%`} />
            </div>
        </AppLayout>
    );
}
