import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconChecks, IconChevronDown, IconScale } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ArrearsAdjustmentStatusBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { ArrearsAdjustmentAuditRow, PaginatedResponse } from '@/types';

interface ArrearsAdjustmentsData {
    stats: { pending_approval: number; applied_this_month: number; total_written_off: string };
    adjustments: PaginatedResponse<ArrearsAdjustmentAuditRow>;
}

interface ArrearsAdjustmentsIndexProps extends ArrearsAdjustmentsData {
    filters: { status?: string };
}

// '' = every status. 'awaiting_approval' covers both pending stages (the
// same set as the "Pending Approval" stat card).
const statusTabs: { key: string; label: string }[] = [
    { key: '', label: 'All' },
    { key: 'awaiting_approval', label: 'Awaiting approval' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
];

/**
 * The arrears-adjustment review queue — its own page since 2026-09-22
 * (previously the Audit Log's "Arrears Adjustments" sub-tab, whose
 * pagination fell back to All Activity). ArrearsAdjustmentController::index
 * builds the payload; page links keep the status filter (withQueryString).
 */
export default function ArrearsAdjustmentsIndex({ stats, adjustments, filters }: ArrearsAdjustmentsIndexProps) {
    const activeStatus = filters.status ?? '';

    return (
        <AppLayout title="Arrears Adjustments">
            <Head title="Arrears Adjustments" />

            <div className="animate-fade-up mb-4 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 text-purple-700">
                    <IconScale size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Arrears Adjustments</h1>
                    <p className="text-sm text-slate-500">Review, approve or reject requested corrections to customers' arrears and credit.</p>
                </div>
            </div>

            <div className="animate-fade-up mb-4 flex gap-1 overflow-x-auto border-b border-slate-200" style={{ animationDelay: '40ms' }}>
                {statusTabs.map((tab) => (
                    <button
                        key={tab.key || 'all'}
                        type="button"
                        onClick={() => router.get('/arrears-adjustments', tab.key ? { status: tab.key } : {}, { preserveScroll: true })}
                        className={`-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                            activeStatus === tab.key
                                ? 'border-purple-600 text-purple-700'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <ArrearsAdjustmentsTab data={{ stats, adjustments }} />
        </AppLayout>
    );
}

function describeDirection(row: ArrearsAdjustmentAuditRow): string {
    if (row.target === 'credit') {
        return row.direction === 'increase' ? 'claws credit back' : 'grants credit';
    }
    return row.direction === 'decrease' ? 'reduces what the customer owes' : 'increases what the customer owes';
}

/**
 * Before/after balance for one row of the Arrears Adjustments audit table
 * (2026-08-28 addendum — the audit-trail request: "what changes were made
 * to a customer's arrears"; 2026-08-30 — target-aware for credit rows). The
 * "before" figure is `arrears_snapshot` (or `credit_snapshot` for a
 * `target === 'credit'` row) — a real, permanently stored fact captured at
 * request time. "After" is only ever shown for `status === 'approved'` rows:
 * that is the one point at which `snapshot ± amount` is the actual resulting
 * figure for `target_period` (ArrearsAdjustmentService::approve() applies
 * exactly this delta — via a real ManuscriptCalculator run, or, for an
 * imported baseline, a bounded direct write; see arrears-adjustment.md §4 and
 * §14). For anything still pending, or rejected, nothing has been applied to
 * the ledger yet — showing a computed "after" there would misstate this
 * table's own contract of showing only what genuinely happened, not a
 * projection (that guidance-only preview already exists in the request form
 * itself, ArrearsAdjustmentModal's balanceAfter).
 */
function BalanceChange({ row }: { row: ArrearsAdjustmentAuditRow }) {
    const isCredit = row.target === 'credit';
    // The "before" figure is the snapshot for the side this row corrects.
    // A credit row's credit_snapshot can be null on very old rows — fall back
    // to a dash rather than rendering "NaN".
    const snapshot = isCredit ? row.credit_snapshot : row.arrears_snapshot;

    if (snapshot === null || snapshot === undefined) {
        return <span className="text-slate-400">—</span>;
    }

    const before = formatCurrency(snapshot);

    if (row.status !== 'approved') {
        return <span className="text-slate-500">{before}</span>;
    }

    const beforeNumber = Number(snapshot);
    const amountNumber = Number(row.amount);
    // Arrears: 'decrease' reduces. Credit: 'increase' (claw back) reduces.
    const reduces = isCredit ? row.direction === 'increase' : row.direction === 'decrease';
    const after = reduces ? Math.max(0, beforeNumber - amountNumber) : beforeNumber + amountNumber;

    return (
        <span className="whitespace-nowrap text-slate-700">
            {before} <span className="text-slate-400">→</span> <span className="font-semibold text-slate-900">{formatCurrency(String(after))}</span>
            <span className="ml-1 text-xs text-slate-400">{isCredit ? 'credit' : 'arrears'}</span>
        </span>
    );
}

/**
 * One row of the Arrears Adjustments table, plus its expandable Details
 * panel. Kept as its own component so each row owns
 * its own `expanded` state. The decision buttons are driven purely by the
 * server-resolved `can_approve`/`can_reject` flags — this component never
 * re-derives the maker≠checker / two-stage gate. "Second approve" vs
 * "Approve" is a label-only distinction; both post to the same endpoint and
 * ArrearsAdjustmentService decides which stage the row is actually at.
 */
function ArrearsAdjustmentRow({
    row,
    busy,
    selected,
    onToggleSelected,
    onApprove,
    onReject,
}: {
    row: ArrearsAdjustmentAuditRow;
    busy: boolean;
    selected: boolean;
    onToggleSelected: () => void;
    onApprove: () => void;
    onReject: () => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const detailId = `arrears-detail-${row.uuid}`;
    const isSecondApproval = row.status === 'pending_second_approval';

    return (
        <>
            <tr className="transition-colors hover:bg-slate-50/70">
                <Td>
                    {row.can_approve && (
                        <input
                            type="checkbox"
                            checked={selected}
                            onChange={onToggleSelected}
                            aria-label={`Select ${row.customer_name ?? 'this'} adjustment for bulk approval`}
                            className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                        />
                    )}
                </Td>
                <Td className="whitespace-nowrap">{row.created_at ? new Date(row.created_at).toLocaleDateString() : '—'}</Td>
                <Td>{row.customer_name ?? '—'}</Td>
                <Td>{row.target_period}</Td>
                <Td className="whitespace-nowrap">
                    {row.direction === 'decrease' ? '−' : '+'}
                    {formatCurrency(row.amount)}
                    {row.target === 'credit' && (
                        <span className="ml-1 rounded bg-purple-100 px-1 text-xs font-medium text-purple-700">credit</span>
                    )}
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
                    <td colSpan={12} className="border-t border-slate-200 bg-slate-50 p-0">
                        <div className="grid grid-cols-1 gap-x-8 gap-y-3 p-4 text-sm md:grid-cols-2">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Reason given</p>
                                <p className="mt-1 whitespace-pre-wrap text-slate-700">{row.reason_note || '—'}</p>
                            </div>
                            <div className="space-y-1 text-slate-600">
                                <p>
                                    <span className="text-slate-400">Target: </span>
                                    <span className="capitalize">{row.target ?? 'arrears'}</span>
                                </p>
                                <p>
                                    <span className="text-slate-400">Direction: </span>
                                    <span className="capitalize">{row.direction}</span> ({describeDirection(row)})
                                </p>
                                <p>
                                    <span className="text-slate-400">{row.target === 'credit' ? 'Credit' : 'Arrears'} at request time: </span>
                                    {row.target === 'credit'
                                        ? row.credit_snapshot === null
                                            ? '—'
                                            : formatCurrency(row.credit_snapshot)
                                        : formatCurrency(row.arrears_snapshot)}
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
 * The Arrears Adjustments review table (this feature's design doc): its own
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
function ArrearsAdjustmentsTab({ data }: { data: ArrearsAdjustmentsData }) {
    const { stats, adjustments } = data;
    const [busyUuid, setBusyUuid] = useState<string | null>(null);
    const [rejecting, setRejecting] = useState<ArrearsAdjustmentAuditRow | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [confirmingSelfApproval, setConfirmingSelfApproval] = useState<ArrearsAdjustmentAuditRow | null>(null);

    // Bulk approval — only rows the server says this user may approve are
    // selectable; the server re-checks each one anyway and skips the rest.
    const eligibleUuids = useMemo(() => adjustments.data.filter((row) => row.can_approve).map((row) => row.uuid), [adjustments.data]);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [bulkApproving, setBulkApproving] = useState(false);
    const [confirmingBulkSelfApproval, setConfirmingBulkSelfApproval] = useState(false);
    const allEligibleSelected = eligibleUuids.length > 0 && eligibleUuids.every((uuid) => selected.has(uuid));
    const selectedOwnCount = adjustments.data.filter((row) => row.is_own_request && selected.has(row.uuid)).length;

    // Drop selections that no longer resolve to an approvable row on this
    // page (page change, or rows just decided).
    useEffect(() => {
        const eligible = new Set(eligibleUuids);
        setSelected((current) => new Set([...current].filter((uuid) => eligible.has(uuid))));
    }, [eligibleUuids]);

    function toggleSelected(uuid: string) {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(uuid)) {
                next.delete(uuid);
            } else {
                next.add(uuid);
            }
            return next;
        });
    }

    function bulkApprove() {
        router.post(
            '/arrears-adjustments/bulk-approve',
            { adjustment_uuids: [...selected] },
            {
                preserveScroll: true,
                onStart: () => setBulkApproving(true),
                onFinish: () => setBulkApproving(false),
                onSuccess: () => {
                    setSelected(new Set());
                    setConfirmingBulkSelfApproval(false);
                },
            },
        );
    }

    // Same "never silent" rule as the single-row self-approval below.
    function onBulkApprove() {
        if (selectedOwnCount > 0) {
            setConfirmingBulkSelfApproval(true);
            return;
        }

        bulkApprove();
    }

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
                    {eligibleUuids.length > 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <p className="text-sm text-slate-600">
                                {selected.size > 0 ? `${selected.size} selected` : 'Select requests to approve several at once.'}
                            </p>
                            <Button
                                type="button"
                                variant="primary"
                                onClick={onBulkApprove}
                                disabled={selected.size === 0 || bulkApproving}
                                className="px-3 py-1.5 text-sm"
                            >
                                {bulkApproving ? <LoadingSpinner className="h-4 w-4" /> : <IconChecks size={16} stroke={2} />}
                                Approve {selected.size > 0 ? selected.size : ''} Selected
                            </Button>
                        </div>
                    )}
                    <Table>
                        <TableHead>
                            <Th>
                                {eligibleUuids.length > 0 && (
                                    <input
                                        type="checkbox"
                                        checked={allEligibleSelected}
                                        onChange={() => setSelected(allEligibleSelected ? new Set() : new Set(eligibleUuids))}
                                        aria-label="Select every request on this page you can approve"
                                        className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                    />
                                )}
                            </Th>
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
                                    busy={busyUuid === row.uuid || bulkApproving}
                                    selected={selected.has(row.uuid)}
                                    onToggleSelected={() => toggleSelected(row.uuid)}
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

            {confirmingBulkSelfApproval && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                        <h3 className="text-base font-semibold text-slate-900">Approve your own requests?</h3>
                        <p className="mt-1 text-sm text-slate-500">
                            {selectedOwnCount === 1 ? '1 of the selected requests was' : `${selectedOwnCount} of the selected requests were`} raised
                            by you. Approving them yourself bypasses the second-reviewer check that normally applies to other staff.
                            This is recorded in the audit log.
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setConfirmingBulkSelfApproval(false)}>
                                Cancel
                            </Button>
                            <Button type="button" variant="primary" disabled={bulkApproving} onClick={bulkApprove}>
                                {bulkApproving && <LoadingSpinner className="h-4 w-4" />}
                                Approve {selected.size} anyway
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
