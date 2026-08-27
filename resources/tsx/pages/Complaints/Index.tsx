import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconCheck,
    IconChevronDown,
    IconClockHour4,
    IconMessageReport,
    IconPlus,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ComplaintStatusBadge } from '@/components/shared/StatusBadge';
import type { Complaint, ComplaintDashboardStats, ComplaintStatus, PaginatedResponse } from '@/types';

interface ComplaintsIndexProps {
    view: 'dashboard' | 'submission';
    complaints: PaginatedResponse<Complaint>;
    filters: {
        status: ComplaintStatus | null;
        category: string | null;
        urgent: string | null;
        sort: 'created_at' | 'title';
        direction: 'asc' | 'desc';
    };
    stats: ComplaintDashboardStats | null;
}

export default function ComplaintsIndex({ view, complaints, filters, stats }: ComplaintsIndexProps) {
    const [isFiltering, setIsFiltering] = useState(false);

    function updateQuery(next: {
        status?: string;
        category?: string;
        urgent?: string;
        sort?: 'created_at' | 'title';
        direction?: 'asc' | 'desc';
    }) {
        router.get(
            '/complaints',
            {
                status: next.status !== undefined ? next.status || undefined : filters.status ?? undefined,
                category: next.category !== undefined ? next.category || undefined : filters.category ?? undefined,
                urgent: next.urgent !== undefined ? next.urgent || undefined : filters.urgent ?? undefined,
                sort: next.sort ?? filters.sort,
                direction: next.direction ?? filters.direction,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setIsFiltering(true),
                onFinish: () => setIsFiltering(false),
            },
        );
    }

    function toggleSort(column: 'created_at' | 'title') {
        if (filters.sort === column) {
            updateQuery({ sort: column, direction: filters.direction === 'asc' ? 'desc' : 'asc' });
        } else {
            updateQuery({ sort: column, direction: 'desc' });
        }
    }

    return (
        <AppLayout title="Complaints">
            <Head title="Complaints" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Complaints</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {view === 'dashboard'
                            ? 'Every complaint, from every role — nothing is filtered by zone or branch.'
                            : 'Report an internal issue, or relay one on a customer’s behalf.'}
                    </p>
                </div>
                <Link
                    href="/complaints/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-fuchsia-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-fuchsia-600/20 transition-colors hover:bg-fuchsia-700"
                >
                    <IconPlus size={18} stroke={2} />
                    Log a Complaint
                </Link>
            </div>

            {/* Submission-first view leads with a CTA card rather than the
                counts row — references/complaint-desk.md section 6: agents/
                workers get a submission-first layout, managers/admins get
                the dashboard. Same route, same list underneath either way. */}
            {view === 'submission' && (
                <Card
                    className="mb-6 flex flex-col items-start gap-3 border-fuchsia-200 bg-fuchsia-50 p-5 animate-fade-up sm:flex-row sm:items-center sm:justify-between"
                    style={{ animationDelay: '50ms' }}
                >
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-fuchsia-100 text-fuchsia-700">
                            <IconMessageReport size={20} stroke={1.75} />
                        </span>
                        <div>
                            <p className="text-sm font-semibold text-fuchsia-900">Got an issue to report?</p>
                            <p className="text-xs text-fuchsia-700">Anyone can log a complaint — it takes under a minute.</p>
                        </div>
                    </div>
                    <Link
                        href="/complaints/create"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-fuchsia-600 px-4 py-2 text-sm font-semibold text-white hover:bg-fuchsia-700"
                    >
                        Log a Complaint
                    </Link>
                </Card>
            )}

            {view === 'dashboard' && stats && (
                <div className="mb-4 grid grid-cols-1 gap-4 animate-fade-up sm:grid-cols-2 xl:grid-cols-4" style={{ animationDelay: '100ms' }}>
                    <StatCard label="Open" value={String(stats.open)} icon={<IconMessageReport size={20} stroke={1.75} />} tone="slate" />
                    <StatCard
                        label="Approaching Deadline"
                        value={String(stats.approaching_deadline)}
                        icon={<IconClockHour4 size={20} stroke={1.75} />}
                        tone="yellow"
                    />
                    {/* Real query today, just naturally zero until the
                        escalation engine (out of scope for this pass) ever
                        writes escalated_at — see
                        ComplaintRepository::dashboardCounts(). */}
                    <StatCard label="Escalated" value={String(stats.escalated)} icon={<IconAlertTriangle size={20} stroke={1.75} />} tone="red" />
                    <StatCard
                        label="Resolved This Week"
                        value={String(stats.resolved_this_week)}
                        icon={<IconCheck size={20} stroke={1.75} />}
                        tone="green"
                    />
                </div>
            )}

            <div
                className="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 animate-fade-up sm:flex-row sm:flex-wrap sm:items-center"
                style={{ animationDelay: '150ms' }}
            >
                {isFiltering && <LoadingSpinner className="h-4 w-4 text-slate-400" />}
                <SelectInput
                    aria-label="Filter by status"
                    value={filters.status ?? ''}
                    onChange={(e) => updateQuery({ status: e.target.value })}
                    className="w-full rounded-lg bg-white sm:w-auto sm:min-w-[9rem]"
                >
                    <option value="">All statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                </SelectInput>
                <SelectInput
                    aria-label="Filter by category"
                    value={filters.category ?? ''}
                    onChange={(e) => updateQuery({ category: e.target.value })}
                    className="w-full rounded-lg bg-white sm:w-auto sm:min-w-[9rem]"
                >
                    <option value="">All categories</option>
                    <option value="operational">Operational</option>
                    <option value="customer">Customer</option>
                </SelectInput>
                <SelectInput
                    aria-label="Filter by urgency"
                    value={filters.urgent ?? ''}
                    onChange={(e) => updateQuery({ urgent: e.target.value })}
                    className="w-full rounded-lg bg-white sm:w-auto sm:min-w-[9rem]"
                >
                    <option value="">Urgent or not</option>
                    <option value="1">Urgent only</option>
                    <option value="0">Non-urgent only</option>
                </SelectInput>
                <p className="text-xs text-slate-400 sm:ml-auto">Escalated complaints always pin to the top, regardless of sort.</p>
            </div>

            {complaints.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <EmptyState title="No complaints found" description="Try a different filter, or log a new complaint." />
                </div>
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '200ms' }}>
                    <Table>
                        <TableHead>
                            <SortableTh label="Title" column="title" filters={filters} onSort={toggleSort} />
                            <Th>Category</Th>
                            <Th>State</Th>
                            <Th>Customer / Zone</Th>
                            <Th>Submitted by</Th>
                            <SortableTh label="Age" column="created_at" filters={filters} onSort={toggleSort} />
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {complaints.data.map((complaint) => (
                                <tr
                                    key={complaint.uuid}
                                    className={`transition-colors hover:bg-slate-50 ${complaint.escalated_at ? 'border-l-4 border-l-red-500 bg-red-50/40' : ''}`}
                                >
                                    <Td className="font-medium text-slate-900">
                                        {complaint.title}
                                        {complaint.urgent && (
                                            <Badge tone="red">
                                                <span className="flex items-center gap-1">
                                                    <IconAlertTriangle size={11} stroke={2} />
                                                    Urgent
                                                </span>
                                            </Badge>
                                        )}
                                    </Td>
                                    <Td className="capitalize">{complaint.category}</Td>
                                    <Td>
                                        <ComplaintStatusBadge complaint={complaint} />
                                    </Td>
                                    <Td>{complaint.customer_name ?? complaint.zone_name ?? '—'}</Td>
                                    <Td>{complaint.submitted_by_name ?? '—'}</Td>
                                    <Td>{ageLabel(complaint.created_at)}</Td>
                                    <Td>
                                        <Link href={`/complaints/${complaint.uuid}`} className="text-sm font-medium text-fuchsia-700 hover:text-fuchsia-800">
                                            View
                                        </Link>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={complaints.links} />
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}

function SortableTh({
    label,
    column,
    filters,
    onSort,
}: {
    label: string;
    column: 'created_at' | 'title';
    filters: ComplaintsIndexProps['filters'];
    onSort: (column: 'created_at' | 'title') => void;
}) {
    const active = filters.sort === column;

    return (
        <Th>
            <button type="button" onClick={() => onSort(column)} className="flex items-center gap-1 hover:text-slate-700">
                {label}
                {active && <IconChevronDown size={12} stroke={2} className={filters.direction === 'asc' ? 'rotate-180' : ''} />}
            </button>
        </Th>
    );
}

function ageLabel(createdAt: string): string {
    const hours = Math.max(0, Math.round((Date.now() - new Date(createdAt).getTime()) / (1000 * 60 * 60)));

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.round(hours / 24)}d ago`;
}
