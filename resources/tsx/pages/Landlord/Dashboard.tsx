import { Head, Link } from '@inertiajs/react';
import { IconBuildingSkyscraper, IconClockHour4, IconLayoutDashboard, IconShieldLock, IconTool, IconUsers } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { LandlordActivityList } from '@/components/shared/LandlordActivityList';
import type { LandlordActivityEntry, MaintenanceNotice } from '@/types';
import { formatMaintenanceTime } from '@/hooks/useCountdown';

interface Props {
    stats: { tenants: number; active: number; pending: number; inactive: number; users: number; landlords: number };
    pending_tenants: { id: string; name: string; created_at: string | null }[];
    maintenance_window: MaintenanceNotice | null;
    recent_activity: LandlordActivityEntry[];
}

/** Landing page of the landlord area (App\Http\Controllers\Landlord\DashboardController). */
export default function LandlordDashboard({ stats, pending_tenants, maintenance_window, recent_activity }: Props) {
    return (
        <LandlordLayout title="Dashboard" breadcrumbs={[{ label: 'Landlord' }, { label: 'Dashboard' }]}>
            <Head title="Landlord — Dashboard" />

            <div className="animate-fade-up mb-6 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconLayoutDashboard size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Platform overview</h1>
                    <p className="text-sm text-slate-500">Every workspace on CNCMS, at a glance.</p>
                </div>
            </div>

            <div className="animate-fade-up mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" style={{ animationDelay: '60ms' }}>
                <StatCard label="Workspaces" value={String(stats.tenants)} hint={`${stats.active} active · ${stats.inactive} deactivated`} icon={<IconBuildingSkyscraper size={18} />} tone="blue" />
                <StatCard label="Awaiting approval" value={String(stats.pending)} icon={<IconClockHour4 size={18} />} tone={stats.pending > 0 ? 'yellow' : 'slate'} />
                <StatCard label="Workspace users" value={String(stats.users)} icon={<IconUsers size={18} />} tone="green" />
                <StatCard label="Landlords" value={String(stats.landlords)} icon={<IconShieldLock size={18} />} tone="purple" />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="flex flex-col gap-6 lg:col-span-1">
                    <Card className="animate-fade-up" style={{ animationDelay: '120ms' }}>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <p className="font-medium text-slate-900">Maintenance</p>
                                <Link href="/landlord/maintenance" className="text-sm text-blue-600 hover:underline">
                                    Manage
                                </Link>
                            </div>
                        </CardHeader>
                        <CardBody>
                            {maintenance_window ? (
                                <div className="flex items-start gap-2 text-sm">
                                    <IconTool size={18} className={maintenance_window.active ? 'text-red-600' : 'text-amber-600'} />
                                    <div>
                                        <p className="font-medium text-slate-800">{maintenance_window.active ? 'Running now' : 'Scheduled'}</p>
                                        <p className="text-slate-500">
                                            {formatMaintenanceTime(maintenance_window.starts_at)} → {formatMaintenanceTime(maintenance_window.ends_at)}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">Nothing scheduled.</p>
                            )}
                        </CardBody>
                    </Card>

                    <Card className="animate-fade-up" style={{ animationDelay: '160ms' }}>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <p className="font-medium text-slate-900">Awaiting approval</p>
                                <Link href="/landlord/tenants?status=pending" className="text-sm text-blue-600 hover:underline">
                                    Review
                                </Link>
                            </div>
                        </CardHeader>
                        <CardBody>
                            {pending_tenants.length === 0 ? (
                                <p className="text-sm text-slate-500">No workspace is waiting.</p>
                            ) : (
                                <ul className="flex flex-col gap-2 text-sm">
                                    {pending_tenants.map((tenant) => (
                                        <li key={tenant.id} className="flex justify-between gap-2">
                                            <span className="truncate font-medium text-slate-800">{tenant.name}</span>
                                            <span className="shrink-0 text-slate-400">
                                                {tenant.created_at ? new Date(tenant.created_at).toLocaleDateString() : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody>
                    </Card>
                </div>

                <Card className="animate-fade-up lg:col-span-2" style={{ animationDelay: '200ms' }}>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <p className="font-medium text-slate-900">Recent landlord activity</p>
                            <Link href="/landlord/activity" className="text-sm text-blue-600 hover:underline">
                                Full log
                            </Link>
                        </div>
                    </CardHeader>
                    {recent_activity.length === 0 ? (
                        <CardBody>
                            <p className="text-sm text-slate-500">No landlord actions recorded yet.</p>
                        </CardBody>
                    ) : (
                        <LandlordActivityList activities={recent_activity} />
                    )}
                </Card>
            </div>
        </LandlordLayout>
    );
}
