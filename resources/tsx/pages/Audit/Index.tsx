import { memo, useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconChevronDown, IconHistory, IconScale, IconSearch } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { ArrearsAdjustmentStatusBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type {
    ArrearsAdjustmentAuditRow,
    AuditAction,
    AuditLogEntry,
    AuditLogFilters,
    AuditLogUser,
    PaginatedResponse,
} from '@/types';

interface ArrearsAdjustmentsTabData {
    stats: { pending_approval: number; applied_this_month: number; total_written_off: string };
    adjustments: PaginatedResponse<ArrearsAdjustmentAuditRow>;
}

interface AuditIndexProps {
    view: 'activity' | 'arrears_adjustments';
    logs: PaginatedResponse<AuditLogEntry>;
    filters: AuditLogFilters;
    tables: string[];
    users: AuditLogUser[];
    arrears_adjustments: ArrearsAdjustmentsTabData | null;
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

export default function AuditIndex({ view, logs, filters, tables, users, arrears_adjustments }: AuditIndexProps) {
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

            {/* "All Activity" | "Arrears Adjustments" sub-tab — mirrors
                SettingsTabs' pattern, gated to the same roles as the rest of
                this page (server-side, AuditLogPolicy::viewAny). */}
            <div className="animate-fade-up mb-4 flex gap-1 border-b border-slate-200" style={{ animationDelay: '40ms' }}>
                {(
                    [
                        { key: 'activity', label: 'All Activity' },
                        { key: 'arrears_adjustments', label: 'Arrears Adjustments' },
                    ] as const
                ).map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => router.get('/audit/logs', tab.key === 'activity' ? {} : { view: tab.key }, { preserveState: false })}
                        className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                            view === tab.key
                                ? 'border-blue-600 text-blue-700'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {view === 'arrears_adjustments' && arrears_adjustments ? (
                <ArrearsAdjustmentsTab data={arrears_adjustments} />
            ) : (
                <ActivityTab logs={logs} filters={filters} tables={tables} users={users} search={search} setSearch={setSearch} />
            )}
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
                <div className="flex flex-wrap items-end gap-3">
                    <SelectInput
                        id="filter-table"
                        label="Table"
                        value={filters.table_name ?? ''}
                        onChange={(e) => apply({ table_name: e.target.value || null })}
                        className="min-w-[10rem]"
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
                        className="min-w-[10rem]"
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

                    <div className="flex flex-1 items-end gap-2">
                        <TextInput
                            id="filter-search"
                            label="Search"
                            placeholder="Search by customer name, agent name, description…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                            className="min-w-[16rem]"
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

/**
 * Before/after balance for one row of the Arrears Adjustments audit table
 * (2026-08-28 addendum — the audit-trail request: "what changes were made
 * to a customer's arrears"). `arrears_snapshot` (the "before" figure) is a
 * real, permanently stored fact captured at request time — always shown.
 * "After" is only ever shown for `status === 'approved'` rows: that is the
 * one point at which `arrears_snapshot ± amount` is the actual resulting
 * `total_arrears` for `target_period` (ArrearsAdjustmentService::approve()
 * applies exactly this delta via a real ManuscriptCalculator run — see
 * arrears-adjustment.md §4). For anything still pending, or rejected,
 * nothing has been applied to the ledger yet — showing a computed "after"
 * there would misstate this table's own contract of showing only what
 * genuinely happened, not a projection (that guidance-only preview already
 * exists in the request form itself, ArrearsAdjustmentModal's balanceAfter).
 */
function BalanceChange({ row }: { row: ArrearsAdjustmentAuditRow }) {
    const before = formatCurrency(row.arrears_snapshot);

    if (row.status !== 'approved') {
        return <span className="text-slate-500">{before}</span>;
    }

    const beforeNumber = Number(row.arrears_snapshot);
    const amountNumber = Number(row.amount);
    const after =
        row.direction === 'decrease' ? Math.max(0, beforeNumber - amountNumber) : beforeNumber + amountNumber;

    return (
        <span className="whitespace-nowrap text-slate-700">
            {before} <span className="text-slate-400">→</span> <span className="font-semibold text-slate-900">{formatCurrency(String(after))}</span>
        </span>
    );
}

/**
 * One row of the Arrears Adjustments sub-tab, plus its expandable Details
 * panel. Kept as its own component (like AuditLogRow above) so each row owns
 * its own `expanded` state. The decision buttons are driven purely by the
 * server-resolved `can_approve`/`can_reject` flags — this component never
 * re-derives the maker≠checker / two-stage gate. "Second approve" vs
 * "Approve" is a label-only distinction; both post to the same endpoint and
 * ArrearsAdjustmentService decides which stage the row is actually at.
 */
function ArrearsAdjustmentRow({
    row,
    busy,
    onApprove,
    onReject,
}: {
    row: ArrearsAdjustmentAuditRow;
    busy: boolean;
    onApprove: () => void;
    onReject: () => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const detailId = `arrears-detail-${row.uuid}`;
    const isSecondApproval = row.status === 'pending_second_approval';

    return (
        <>
            <tr className="transition-colors hover:bg-slate-50/70">
                <Td className="whitespace-nowrap">{row.created_at ? new Date(row.created_at).toLocaleDateString() : '—'}</Td>
                <Td>{row.customer_name ?? '—'}</Td>
                <Td>{row.target_period}</Td>
                <Td className="capitalize">
                    {row.direction === 'decrease' ? '−' : '+'}
                    {formatCurrency(row.amount)}
                </Td>
                <Td>
                    <BalanceChange row={row} />
                </Td>
                <Td className="capitalize">{row.reason_category.replace(/_/g, ' ')}</Td>
                <Td>{row.requested_by_name ?? '—'}</Td>
                <Td>
                    {row.second_approved_by_name ?? row.approved_by_name ?? '—'}
                    {row.status === 'pending_second_approval' && row.approved_by_name && (
                        <span className="block text-xs text-slate-400">1st: {row.approved_by_name}</span>
                    )}
                </Td>
                <Td>
                    <ArrearsAdjustmentStatusBadge status={row.status} />
                    {row.status === 'rejected' && row.rejection_reason && (
                        <span className="block max-w-[16rem] text-xs text-slate-400">{row.rejection_reason}</span>
                    )}
                </Td>
                <Td>
                    <button
                        type="button"
                        onClick={() => setExpanded((value) => !value)}
                        aria-expanded={expanded}
                        aria-controls={detailId}
                        className="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700"
                    >
                        {expanded ? 'Hide' : 'View'}
                        <span className="sr-only">details for this adjustment</span>
                        <IconChevronDown size={14} stroke={2} aria-hidden="true" className={`transition-transform ${expanded ? 'rotate-180' : ''}`} />
                    </button>
                </Td>
                <Td>
                    <div className="flex gap-2">
                        {row.can_approve && (
                            <Button
                                type="button"
                                variant="primary"
                                disabled={busy}
                                onClick={onApprove}
                                className="px-2.5 py-1.5 text-xs"
                            >
                                {isSecondApproval ? 'Second approve' : 'Approve'}
                            </Button>
                        )}
                        {row.can_reject && (
                            <Button
                                type="button"
                                variant="danger"
                                disabled={busy}
                                onClick={onReject}
                                className="px-2.5 py-1.5 text-xs"
                            >
                                Reject
                            </Button>
                        )}
                    </div>
                </Td>
            </tr>
            {expanded && (
                <tr id={detailId}>
                    <td colSpan={11} className="border-t border-slate-200 bg-slate-50 p-0">
                        <div className="grid grid-cols-1 gap-x-8 gap-y-3 p-4 text-sm md:grid-cols-2">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Reason given</p>
                                <p className="mt-1 whitespace-pre-wrap text-slate-700">{row.reason_note || '—'}</p>
                            </div>
                            <div className="space-y-1 text-slate-600">
                                <p>
                                    <span className="text-slate-400">Direction: </span>
                                    <span className="capitalize">{row.direction}</span> ({row.direction === 'decrease' ? 'reduces' : 'increases'} what the customer owes)
                                </p>
                                <p>
                                    <span className="text-slate-400">Balance at request time: </span>
                                    {formatCurrency(row.arrears_snapshot)}
                                </p>
                                {row.customer_uuid && (
                                    <p>
                                        <Link href={`/customers/${row.customer_uuid}`} className="font-medium text-blue-600 hover:text-blue-700">
                                            Open {row.customer_name ?? 'customer'} →
                                        </Link>
                                    </p>
                                )}
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

/**
 * The Arrears Adjustment sub-tab (this feature's design doc): its own
 * StatCard row (Pending Approval / Applied This Month / Total Written Off)
 * and table (Date, Customer, Amount, Balance, Reason, Requested by, Approved
 * by, Status), with inline Approve/Reject actions — `can_approve`/
 * `can_reject` are resolved server-side per row
 * (App\Policies\ArrearsAdjustmentPolicy is state-dependent on the
 * adjustment's current status), so this component never re-derives who may
 * act. The first-approval button reads "Second approve" once a row is at
 * `pending_second_approval` — same endpoint, the service decides which
 * stage it is. Each row expands (Details) to show the requester's
 * free-text `reason_note` and a link to the customer, the context a
 * reviewer needs before deciding. The Balance column (2026-08-28 addendum)
 * is this page's answer to "what changed" — see BalanceChange above.
 */
function ArrearsAdjustmentsTab({ data }: { data: ArrearsAdjustmentsTabData }) {
    const { stats, adjustments } = data;
    const [busyUuid, setBusyUuid] = useState<string | null>(null);
    const [rejecting, setRejecting] = useState<ArrearsAdjustmentAuditRow | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [confirmingSelfApproval, setConfirmingSelfApproval] = useState<ArrearsAdjustmentAuditRow | null>(null);

    function approve(row: ArrearsAdjustmentAuditRow) {
        setBusyUuid(row.uuid);
        router.post(
            `/arrears-adjustments/${row.uuid}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyUuid(null),
                onSuccess: () => setConfirmingSelfApproval(null),
            },
        );
    }

    // A `super` acting on a request they raised themselves bypasses the
    // second-reviewer check — allowed by ArrearsAdjustmentPolicy, but never
    // silent. Everyone else's rows approve immediately as before.
    function onApprove(row: ArrearsAdjustmentAuditRow) {
        if (row.is_own_request) {
            setConfirmingSelfApproval(row);
            return;
        }

        approve(row);
    }

    function submitRejection() {
        if (!rejecting) return;

        setBusyUuid(rejecting.uuid);
        router.post(
            `/arrears-adjustments/${rejecting.uuid}/reject`,
            { rejection_reason: rejectionReason },
            {
                preserveScroll: true,
                onFinish: () => setBusyUuid(null),
                onSuccess: () => {
                    setRejecting(null);
                    setRejectionReason('');
                },
            },
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="animate-fade-up grid grid-cols-1 gap-4 sm:grid-cols-3" style={{ animationDelay: '80ms' }}>
                <StatCard label="Pending Approval" value={stats.pending_approval.toLocaleString()} icon={<IconScale size={20} stroke={1.75} />} tone="yellow" />
                <StatCard label="Applied This Month" value={stats.applied_this_month.toLocaleString()} icon={<IconScale size={20} stroke={1.75} />} tone="green" />
                <StatCard label="Total Written Off" value={formatCurrency(stats.total_written_off)} icon={<IconScale size={20} stroke={1.75} />} tone="purple" />
            </div>

            {adjustments.data.length === 0 ? (
                <EmptyState title="No arrears adjustments" description="Requests submitted from a customer's page will appear here." />
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '160ms' }}>
                    <Table>
                        <TableHead>
                            <Th>Date</Th>
                            <Th>Customer</Th>
                            <Th>Period</Th>
                            <Th>Amount</Th>
                            <Th>Balance</Th>
                            <Th>Reason</Th>
                            <Th>Requested by</Th>
                            <Th>Approved by</Th>
                            <Th>Status</Th>
                            <Th>Details</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {adjustments.data.map((row) => (
                                <ArrearsAdjustmentRow
                                    key={row.uuid}
                                    row={row}
                                    busy={busyUuid === row.uuid}
                                    onApprove={() => onApprove(row)}
                                    onReject={() => setRejecting(row)}
                                />
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={adjustments.links} />
                    </div>
                </Card>
            )}

            {rejecting && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="text-base font-semibold text-slate-900">
                            Reject adjustment for {rejecting.customer_name ?? 'this customer'}
                        </h3>
                        <p className="mt-1 text-sm text-slate-500">This request will be permanently marked rejected — no ledger effect.</p>
                        <textarea
                            rows={3}
                            required
                            value={rejectionReason}
                            onChange={(e) => setRejectionReason(e.target.value)}
                            placeholder="Reason for rejection…"
                            className="mt-3 w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-red-500"
                        />
                        <div className="mt-4 flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => {
                                    setRejecting(null);
                                    setRejectionReason('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="danger"
                                disabled={rejectionReason.trim() === '' || busyUuid === rejecting.uuid}
                                onClick={submitRejection}
                            >
                                {busyUuid === rejecting.uuid && <LoadingSpinner className="h-4 w-4" />}
                                Confirm Rejection
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {confirmingSelfApproval && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="text-base font-semibold text-slate-900">Approve your own request?</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            You raised this arrears adjustment. Approving it yourself bypasses the second-reviewer check that
                            normally applies to other staff. This is recorded in the audit log.
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setConfirmingSelfApproval(null)}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                disabled={busyUuid === confirmingSelfApproval.uuid}
                                onClick={() => approve(confirmingSelfApproval)}
                            >
                                {busyUuid === confirmingSelfApproval.uuid && <LoadingSpinner className="h-4 w-4" />}
                                Approve anyway
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
