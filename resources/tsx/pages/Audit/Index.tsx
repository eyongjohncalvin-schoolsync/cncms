import { memo, useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { IconChevronDown, IconHistory, IconSearch } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import type { AuditAction, AuditLogEntry, AuditLogFilters, AuditLogUser, PaginatedResponse } from '@/types';

interface AuditIndexProps {
    logs: PaginatedResponse<AuditLogEntry>;
    filters: AuditLogFilters;
    tables: string[];
    users: AuditLogUser[];
}

const actionTabs: { key: 'all' | AuditAction; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'create', label: 'Create' },
    { key: 'update', label: 'Update' },
    { key: 'delete', label: 'Delete' },
];

const actionTone: Record<AuditAction, 'green' | 'blue' | 'red'> = {
    create: 'green',
    update: 'blue',
    delete: 'red',
};

export default function AuditIndex({ logs, filters, tables, users }: AuditIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const removeStart = router.on('start', () => setIsLoading(true));
        const removeFinish = router.on('finish', () => setIsLoading(false));

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    return (
        <AppLayout title="Audit Log">
            <Head title="Audit Log" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-cyan-100 text-cyan-700">
                    <IconHistory size={20} stroke={1.75} />
                </span>
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="font-display text-2xl text-slate-900">Audit Log</h1>
                        {isLoading && <LoadingSpinner className="text-blue-600" />}
                    </div>
                    <p className="text-sm text-slate-500">Every create/update/delete recorded across the workspace.</p>
                </div>
            </div>

            {/* Arrears Adjustments used to be a sub-tab here; it now has its
                own page (/arrears-adjustments). */}
            <ActivityTab logs={logs} filters={filters} tables={tables} users={users} search={search} setSearch={setSearch} />
        </AppLayout>
    );
}

function ActivityTab({
    logs,
    filters,
    tables,
    users,
    search,
    setSearch,
}: {
    logs: PaginatedResponse<AuditLogEntry>;
    filters: AuditLogFilters;
    tables: string[];
    users: AuditLogUser[];
    search: string;
    setSearch: (value: string) => void;
}) {
    function apply(next: Partial<AuditLogFilters>) {
        const merged = { ...filters, ...next };

        router.get(
            '/audit/logs',
            {
                table_name: merged.table_name || undefined,
                action: merged.action || undefined,
                user_uuid: merged.user_uuid || undefined,
                search: merged.search || undefined,
                record_uuid: merged.record_uuid || undefined,
                from: merged.from || undefined,
                to: merged.to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch() {
        apply({ search: search.trim() || null });
    }

    const activeAction = filters.action ?? 'all';

    return (
        <>
            <Card className="animate-fade-up mb-4 p-4" style={{ animationDelay: '80ms' }}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Filters</p>
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <SelectInput
                        id="filter-table"
                        label="Table"
                        value={filters.table_name ?? ''}
                        onChange={(e) => apply({ table_name: e.target.value || null })}
                        className="w-full sm:w-auto sm:min-w-[10rem]"
                    >
                        <option value="">All tables</option>
                        {tables.map((table) => (
                            <option key={table} value={table}>
                                {table.replace(/_/g, ' ')}
                            </option>
                        ))}
                    </SelectInput>

                    <SelectInput
                        id="filter-user"
                        label="User"
                        value={filters.user_uuid ?? ''}
                        onChange={(e) => apply({ user_uuid: e.target.value || null })}
                        className="w-full sm:w-auto sm:min-w-[10rem]"
                    >
                        <option value="">All users</option>
                        {users.map((user) => (
                            <option key={user.uuid} value={user.uuid}>
                                {user.name}
                            </option>
                        ))}
                    </SelectInput>

                    <TextInput
                        id="filter-from"
                        label="From"
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(e) => apply({ from: e.target.value || null })}
                    />

                    <TextInput
                        id="filter-to"
                        label="To"
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(e) => apply({ to: e.target.value || null })}
                    />

                    <div className="flex w-full items-end gap-2 sm:flex-1">
                        <TextInput
                            id="filter-search"
                            label="Search"
                            placeholder="Search by customer name, agent name, description…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                            className="w-full sm:min-w-[16rem]"
                        />
                        <Button type="button" variant="secondary" onClick={submitSearch} className="h-[38px]">
                            <IconSearch size={15} stroke={1.75} />
                            Search
                        </Button>
                    </div>
                </div>

                <div className="mt-4 border-t border-slate-100 pt-4">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Action</p>
                    <div className="flex flex-wrap gap-1">
                        {actionTabs.map((tab) => {
                            const active = activeAction === tab.key;

                            return (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => apply({ action: tab.key === 'all' ? null : tab.key })}
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                        active
                                            ? 'bg-blue-600 text-white'
                                            : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </Card>

            {logs.data.length === 0 ? (
                <EmptyState title="No audit events found" description="Try a different filter or date range." />
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '160ms' }}>
                    <Table>
                        <TableHead>
                            <Th>Timestamp</Th>
                            <Th>User</Th>
                            <Th>Table</Th>
                            <Th>Action</Th>
                            <Th>Summary</Th>
                            <Th>Details</Th>
                        </TableHead>
                        <TableBody>
                            {logs.data.map((log) => (
                                // Isolated per-row: the old/new values JSON here is arbitrary
                                // stored data, not app-controlled — one malformed row (e.g. a
                                // value JSON.stringify can't handle) shouldn't crash the whole
                                // audit log table.
                                <ErrorBoundary key={log.id} compact>
                                    <AuditLogRow log={log} />
                                </ErrorBoundary>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={logs.links} />
                    </div>
                </Card>
            )}
        </>
    );
}

const AuditLogRow = memo(function AuditLogRow({ log }: { log: AuditLogEntry }) {
    const [expanded, setExpanded] = useState(false);

    const oldValuesJson = useMemo(() => JSON.stringify(log.old_values, null, 2) ?? 'null', [log.old_values]);
    const newValuesJson = useMemo(() => JSON.stringify(log.new_values, null, 2) ?? 'null', [log.new_values]);
    const detailId = `audit-detail-${log.id}`;

    return (
        <>
            <tr className="transition-colors hover:bg-slate-50/70">
                <Td className="whitespace-nowrap">{new Date(log.created_at).toLocaleString()}</Td>
                <Td>{log.user?.name ?? 'System'}</Td>
                <Td className="whitespace-nowrap">{log.table_name}</Td>
                <Td>
                    <Badge tone={actionTone[log.action]}>{log.action}</Badge>
                </Td>
                <Td>{log.summary}</Td>
                <Td>
                    <button
                        type="button"
                        onClick={() => setExpanded((value) => !value)}
                        aria-expanded={expanded}
                        aria-controls={detailId}
                        className="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700"
                    >
                        {expanded ? 'Hide' : 'View'}
                        <span className="sr-only">details for this audit entry</span>
                        <IconChevronDown
                            size={14}
                            stroke={2}
                            aria-hidden="true"
                            className={`transition-transform ${expanded ? 'rotate-180' : ''}`}
                        />
                    </button>
                </Td>
            </tr>
            {expanded && (
                <tr id={detailId}>
                    <td colSpan={6} className="border-t border-slate-200 bg-slate-50 p-0">
                        <div className="p-4">
                            <div className="grid grid-cols-1 gap-3 text-xs md:grid-cols-2">
                                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 font-semibold uppercase tracking-wide text-slate-500">
                                        Old values
                                    </p>
                                    <pre className="max-h-64 overflow-auto p-3 font-mono text-[11px] leading-relaxed text-slate-700">
                                        {oldValuesJson}
                                    </pre>
                                </div>
                                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 font-semibold uppercase tracking-wide text-slate-500">
                                        New values
                                    </p>
                                    <pre className="max-h-64 overflow-auto p-3 font-mono text-[11px] leading-relaxed text-slate-700">
                                        {newValuesJson}
                                    </pre>
                                </div>
                            </div>
                            <p className="mt-3 text-xs text-slate-400">
                                Record: {log.record_uuid} {log.ip_address ? `· IP: ${log.ip_address}` : ''}{' '}
                                {log.device_id ? `· Device: ${log.device_id}` : ''}
                            </p>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
});
