import { Head, Link } from '@inertiajs/react';
import { IconHistory } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/ui/Pagination';
import { LandlordActivityList } from '@/components/shared/LandlordActivityList';
import type { LandlordActivityEntry, PaginationLink } from '@/types';

const SUBJECT_TABS: { label: string; value: string | null }[] = [
    { label: 'All', value: null },
    { label: 'Tenants', value: 'tenant' },
    { label: 'Maintenance', value: 'maintenance' },
    { label: 'Landlord access', value: 'user' },
];

/** Paginated landlord activity log (App\Http\Controllers\Landlord\ActivityController). */
export default function LandlordActivityIndex({
    activities,
    filters,
}: {
    activities: { data: LandlordActivityEntry[]; links: PaginationLink[]; total: number };
    filters: { subject: string | null };
}) {
    return (
        <LandlordLayout title="Activity log" breadcrumbs={[{ label: 'Landlord', href: '/landlord' }, { label: 'Activity log' }]}>
            <Head title="Landlord — Activity log" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconHistory size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Activity log</h1>
                    <p className="text-sm text-slate-500">Every platform-level action by a landlord, newest first. {activities.total} in total.</p>
                </div>
            </div>

            <div className="animate-fade-up mb-4 flex flex-wrap gap-1" role="group" aria-label="Filter by subject">
                {SUBJECT_TABS.map((tab) => {
                    const isActive = (filters.subject ?? null) === tab.value;

                    return (
                        <Link
                            key={tab.label}
                            href={tab.value ? `/landlord/activity?subject=${tab.value}` : '/landlord/activity'}
                            aria-current={isActive ? 'page' : undefined}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                isActive ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                            }`}
                        >
                            {tab.label}
                        </Link>
                    );
                })}
            </div>

            {activities.data.length === 0 ? (
                <EmptyState title="Nothing recorded yet" description="Landlord actions appear here as they happen." />
            ) : (
                <>
                    <Card>
                        <LandlordActivityList activities={activities.data} />
                    </Card>
                    <div className="mt-4">
                        <Pagination links={activities.links} />
                    </div>
                </>
            )}
        </LandlordLayout>
    );
}
