import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { IconCheck, IconChecks, IconPlus, IconX } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { Modal } from '@/components/ui/Modal';
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import { hasPermission } from '@/lib/permissions';
import { useDebounce } from '@/hooks/useDebounce';
import type { PageProps, PaginatedResponse, Payment, PaymentFrequency, VerificationStatus } from '@/types';

interface PaymentsIndexProps {
    payments: PaginatedResponse<Payment>;
    filters: {
        verification_status: VerificationStatus | null;
        frequency: PaymentFrequency | null;
        // Matches the payment's customer's name (partial, case-insensitive)
        // or phone (partial) — same 'search' filter/idiom as
        // Customers/Index.tsx, applied server-side (not just to the
        // current page's rows) via PaymentRepository::paginate().
        search: string | null;
        // Echoes back the EFFECTIVE range PaymentController::index() applied
        // (including its current-month default) — always populated with
        // `from`/`to` unless scope === 'all'.
        from: string | null;
        to: string | null;
        scope: 'all' | null;
    };
    statusCounts: {
        all: number;
        pending: number;
        verified: number;
        rejected: number;
    };
}

const tabs: { key: 'all' | VerificationStatus; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'pending', label: 'Pending' },
    { key: 'verified', label: 'Verified' },
    { key: 'rejected', label: 'Rejected' },
];

// Each tab carries its own semantic color (pending = amber, verified =
// green, rejected = red) instead of every tab going generic blue when
// active — a "Pending" count should read as genuinely amber, not just
// gray-with-a-blue-active-state. The count pill stays tone-tinted even at
// rest so it doesn't fade to plain gray when the tab isn't selected.
const tabAccents: Record<'all' | VerificationStatus, { activeTab: string; activeCount: string; restCount: string }> = {
    all: {
        activeTab: 'bg-blue-600 text-white shadow-sm shadow-blue-600/20',
        activeCount: 'bg-blue-500 text-white',
        restCount: 'bg-slate-100 text-slate-600',
    },
    pending: {
        activeTab: 'bg-amber-600 text-white shadow-sm shadow-amber-600/20',
        activeCount: 'bg-amber-500 text-white',
        restCount: 'bg-amber-100 text-amber-700',
    },
    verified: {
        activeTab: 'bg-green-600 text-white shadow-sm shadow-green-600/20',
        activeCount: 'bg-green-500 text-white',
        restCount: 'bg-green-100 text-green-700',
    },
    rejected: {
        activeTab: 'bg-red-600 text-white shadow-sm shadow-red-600/20',
        activeCount: 'bg-red-500 text-white',
        restCount: 'bg-red-100 text-red-700',
    },
};

const frequencyLabels: Record<PaymentFrequency, string> = {
    monthly: 'Monthly',
    months: 'Multi-month',
    yearly: 'Yearly',
};

export default function PaymentsIndex({ payments, filters, statusCounts }: PaymentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    // RBAC v2 Wave 4: display affordances resolved from the shared
    // permission matrix (auth.user.permissions), not hardcoded role names.
    // `payments.verify` mirrors PaymentPolicy::verify (the agent's
    // zone-scoped verify is a server-side OR-branch with no matrix
    // permission, matching the old role array's exclusion of agent here);
    // `payments.delete` mirrors PaymentPolicy::delete's stricter gate.
    const canVerify = hasPermission(auth.user?.permissions, 'payments.verify');
    const canDelete = hasPermission(auth.user?.permissions, 'payments.delete');

    const [reviewing, setReviewing] = useState<Payment | null>(null);
    const [deleting, setDeleting] = useState<Payment | null>(null);
    const [destroying, setDestroying] = useState(false);
    const [isFiltering, setIsFiltering] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [search, setSearch] = useState(filters.search ?? '');
    const debouncedSearch = useDebounce(search, 300);

    const activeTab = filters.verification_status ?? 'all';

    const isAllTime = filters.scope === 'all';
    // <input type="month"> wants "YYYY-MM" — `filters.from` is always a full
    // "YYYY-MM-DD" (first of the month) when not scope === 'all', per
    // PaymentController::index()'s doc comment.
    const monthInputValue = filters.from ? filters.from.slice(0, 7) : '';
    const monthLabel = filters.from
        ? new Date(`${filters.from}T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
        : null;

    // A pending payment is only ever eligible for bulk verification when it
    // was paid at *exactly* the customer's current bill — anything else
    // (partial payment, overpayment, multi-month) needs a human to look at
    // it, same rule App\Services\PaymentVerificationService::verifyMany()
    // re-checks server-side.
    function matchesBill(payment: Payment): boolean {
        return payment.verification_status === 'pending' && Number(payment.amount) === Number(payment.customer_bill);
    }

    // Selection is scoped to whatever page/tab is currently visible — if the
    // list changes underneath (tab switch, page change, a bulk action just
    // ran), stale uuids that no longer resolve to a matching row are dropped
    // rather than silently carried into the next bulk submission.
    useEffect(() => {
        const eligible = new Set(payments.data.filter(matchesBill).map((payment) => payment.uuid));
        setSelected((current) => new Set([...current].filter((uuid) => eligible.has(uuid))));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payments.data]);

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

    const eligibleUuids = useMemo(() => payments.data.filter(matchesBill).map((payment) => payment.uuid), [payments.data]);
    const allEligibleSelected = eligibleUuids.length > 0 && eligibleUuids.every((uuid) => selected.has(uuid));

    function toggleSelectAllMatching() {
        setSelected(allEligibleSelected ? new Set() : new Set(eligibleUuids));
    }

    const [bulkVerifying, setBulkVerifying] = useState(false);

    function submitBulkVerify() {
        if (selected.size === 0) {
            return;
        }

        router.post(
            '/payments/bulk-verify',
            { payment_uuids: [...selected] },
            {
                preserveScroll: true,
                onStart: () => setBulkVerifying(true),
                onFinish: () => setBulkVerifying(false),
                onSuccess: () => setSelected(new Set()),
            },
        );
    }

    function closeDeleteModal() {
        if (destroying) {
            return;
        }
        setDeleting(null);
    }

    function submitDelete() {
        if (!deleting) {
            return;
        }

        router.delete(`/payments/${deleting.uuid}`, {
            preserveScroll: true,
            onStart: () => setDestroying(true),
            onFinish: () => {
                setDestroying(false);
                setDeleting(null);
            },
        });
    }

    function goToTab(key: 'all' | VerificationStatus) {
        router.get(
            '/payments',
            {
                verification_status: key === 'all' ? undefined : key,
                frequency: filters.frequency ?? undefined,
                search: filters.search ?? undefined,
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

    function changeFrequency(value: string) {
        router.get(
            '/payments',
            {
                verification_status: filters.verification_status ?? undefined,
                frequency: value || undefined,
                search: filters.search ?? undefined,
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

    // Debounced live search — same 300ms useDebounce idiom
    // Customers/Index.tsx's search box already uses. Preserves whichever
    // date scope is currently in effect (a specific picked month, or
    // ?scope=all) so typing a search term doesn't silently knock the view
    // out of "All time" back to the current-month default, and vice versa —
    // the two filters compose independently.
    useEffect(() => {
        if (debouncedSearch !== (filters.search ?? '')) {
            router.get(
                '/payments',
                {
                    verification_status: filters.verification_status ?? undefined,
                    frequency: filters.frequency ?? undefined,
                    search: debouncedSearch || undefined,
                    ...(isAllTime
                        ? { scope: 'all' }
                        : { from: filters.from ?? undefined, to: filters.to ?? undefined }),
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    // Jumps to a specific month picked via the native <input type="month">
    // below (same type="month" pattern Manuscripts/Index.tsx's period filter
    // already uses) — sends explicit from/to for that month's first/last
    // day, which PaymentController::index() then honors verbatim (an
    // explicit range always bypasses the server's own current-month
    // default). Picking a month this way always drops scope=all, since
    // picking one specific month is the opposite of "all time".
    function changeMonth(value: string) {
        if (!value) {
            return;
        }

        const [year, month] = value.split('-').map(Number);
        const lastDay = new Date(year, month, 0).getDate();

        router.get(
            '/payments',
            {
                verification_status: filters.verification_status ?? undefined,
                frequency: filters.frequency ?? undefined,
                search: filters.search ?? undefined,
                from: `${value}-01`,
                to: `${value}-${String(lastDay).padStart(2, '0')}`,
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

    // Explicit opt-in to the full unscoped history (PaymentController::
    // index()'s ?scope=all) — the deliberate "audit/past months" escape
    // hatch from the current-month default, never the page's own default.
    function goToAllTime() {
        router.get(
            '/payments',
            {
                verification_status: filters.verification_status ?? undefined,
                frequency: filters.frequency ?? undefined,
                search: filters.search ?? undefined,
                scope: 'all',
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

    // Returns to the page's own default — omitting from/to/scope entirely
    // lets the server re-apply its current-month default, rather than this
    // page hard-coding today's month itself.
    function goToThisMonth() {
        router.get(
            '/payments',
            {
                verification_status: filters.verification_status ?? undefined,
                frequency: filters.frequency ?? undefined,
                search: filters.search ?? undefined,
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

    // Recomputed only when the payments list itself changes, not on every
    // unrelated re-render (opening the review modal, toggling isFiltering).
    const rows = useMemo(
        () =>
            payments.data.map((payment) => ({
                payment,
                formattedDate: new Date(payment.created_at).toLocaleDateString(),
                formattedAmount: formatCurrency(payment.amount),
            })),
        [payments.data],
    );

    return (
        <AppLayout title="Payments">
            <Head title="Payments" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Payments</h2>
                    <p className="mt-1 text-sm text-slate-500">Track and verify customer payments across all zones.</p>
                </div>
                <Link
                    href="/payments/create"
                    className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 transition-colors hover:bg-blue-700"
                >
                    <IconPlus size={18} stroke={2} />
                    Record Payment
                </Link>
            </div>

            <div
                className="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 animate-fade-up sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
                style={{ animationDelay: '100ms' }}
            >
                <div className="flex flex-wrap items-center gap-1">
                    {isFiltering && <LoadingSpinner className="mr-1 h-4 w-4 text-slate-400" />}
                    {tabs.map((tab) => {
                        const count = statusCounts[tab.key];
                        const active = activeTab === tab.key;
                        const accent = tabAccents[tab.key];

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => goToTab(tab.key)}
                                className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                                    active ? accent.activeTab : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                }`}
                            >
                                {tab.label}
                                <span className={`rounded-full px-1.5 py-0.5 text-xs font-semibold ${active ? accent.activeCount : accent.restCount}`}>
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    {/* Default view = current calendar month; this cluster is the
                        explicit, deliberate escape hatch for audit/historical
                        lookup (a specific past month, or unscoped "All time") —
                        see PaymentController::index()'s doc comment. */}
                    <span className="text-xs font-medium whitespace-nowrap text-slate-500">
                        Showing: {isAllTime ? 'All time' : monthLabel}
                    </span>
                    <TextInput
                        type="month"
                        aria-label="Jump to a specific month"
                        value={monthInputValue}
                        onChange={(e) => changeMonth(e.target.value)}
                        className="rounded-lg bg-white"
                    />
                    <button
                        type="button"
                        onClick={isAllTime ? goToThisMonth : goToAllTime}
                        className={`rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors ${
                            isAllTime
                                ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
                                : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                        }`}
                    >
                        {isAllTime ? 'Back to this month' : 'All time'}
                    </button>
                </div>

                <div className="relative w-full sm:w-56">
                    <TextInput
                        id="search"
                        aria-label="Search by customer name or phone"
                        placeholder="Search name or phone"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-full rounded-lg bg-white"
                    />
                    {isFiltering && (
                        <LoadingSpinner
                            className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400"
                            label="Searching payments"
                        />
                    )}
                </div>

                <SelectInput
                    aria-label="Filter by frequency"
                    value={filters.frequency ?? ''}
                    onChange={(e) => changeFrequency(e.target.value)}
                    className="w-full rounded-lg bg-white sm:w-auto sm:min-w-[10rem]"
                >
                    <option value="">All frequencies</option>
                    <option value="monthly">Monthly</option>
                    <option value="months">Multi-month</option>
                    <option value="yearly">Yearly</option>
                </SelectInput>
            </div>

            {canVerify && activeTab === 'pending' && eligibleUuids.length > 0 && (
                <div
                    className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-300 bg-blue-100 p-3 animate-fade-up"
                    style={{ animationDelay: '150ms' }}
                >
                    <div className="flex items-center gap-3">
                        <label className="flex items-center gap-2 text-sm font-medium text-blue-900">
                            <input
                                type="checkbox"
                                checked={allEligibleSelected}
                                onChange={toggleSelectAllMatching}
                                className="h-4 w-4 rounded border-blue-300 text-blue-600 focus:ring-blue-600"
                            />
                            Select all {eligibleUuids.length} matching bill exactly
                        </label>
                        <span className="text-xs text-blue-700">
                            {selected.size > 0 ? `${selected.size} selected` : 'No payment amount discrepancies — safe to bulk-verify'}
                        </span>
                    </div>
                    <Button
                        type="button"
                        onClick={submitBulkVerify}
                        disabled={selected.size === 0 || bulkVerifying}
                        className="rounded-lg px-4 py-2 text-sm font-semibold"
                    >
                        {bulkVerifying ? <LoadingSpinner className="h-4 w-4" /> : <IconChecks size={16} stroke={2} />}
                        Verify {selected.size > 0 ? selected.size : ''} Selected
                    </Button>
                </div>
            )}

            {payments.data.length === 0 ? (
                <div className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <EmptyState title="No payments found" description="Try a different filter or record a new payment." />
                </div>
            ) : (
                <Card className="animate-fade-up p-0" style={{ animationDelay: '200ms' }}>
                    <Table>
                        <TableHead>
                            {canVerify && activeTab === 'pending' && eligibleUuids.length > 0 && <Th className="w-10" />}
                            <Th>Date</Th>
                            <Th>Customer</Th>
                            <Th>Zone</Th>
                            <Th>Amount</Th>
                            <Th>Freq.</Th>
                            <Th>Verification</Th>
                            <Th>Recorded</Th>
                            <Th>Actions</Th>
                        </TableHead>
                        <TableBody>
                            {rows.map(({ payment, formattedDate, formattedAmount }) => (
                                <tr key={payment.uuid} className="transition-colors hover:bg-slate-50">
                                    {canVerify && activeTab === 'pending' && eligibleUuids.length > 0 && (
                                        <Td>
                                            {matchesBill(payment) && (
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(payment.uuid)}
                                                    onChange={() => toggleSelected(payment.uuid)}
                                                    aria-label={`Select ${payment.customer_name}'s payment for bulk verification`}
                                                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                                />
                                            )}
                                        </Td>
                                    )}
                                    <Td>{formattedDate}</Td>
                                    <Td>{payment.customer_name}</Td>
                                    <Td>{payment.zone_name ?? '—'}</Td>
                                    <Td className="font-medium text-slate-900">
                                        {formattedAmount}
                                        {matchesBill(payment) && (
                                            <span className="ml-1.5 text-xs font-normal text-green-600" title="Matches customer's standard monthly bill exactly">
                                                (matches bill)
                                            </span>
                                        )}
                                    </Td>
                                    <Td>{frequencyLabels[payment.frequency]}</Td>
                                    <Td>
                                        {payment.verification_status === 'pending' && canVerify ? (
                                            <button type="button" onClick={() => setReviewing(payment)}>
                                                <VerificationBadge status={payment.verification_status} />
                                            </button>
                                        ) : (
                                            <VerificationBadge status={payment.verification_status} />
                                        )}
                                    </Td>
                                    <Td>
                                        <Badge tone={payment.recorded_offline ? 'yellow' : 'slate'}>
                                            {payment.recorded_offline ? 'Offline' : 'Office'}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <Dropdown label={`Actions for ${payment.customer_name}'s payment`}>
                                            <DropdownItem href={`/payments/${payment.uuid}`}>View</DropdownItem>
                                            {canVerify && <DropdownItem href={`/payments/${payment.uuid}/edit`}>Edit</DropdownItem>}
                                            {payment.verification_status === 'pending' && canVerify && (
                                                <DropdownItem onClick={() => setReviewing(payment)}>Review</DropdownItem>
                                            )}
                                            {canDelete && (
                                                <>
                                                    <DropdownDivider />
                                                    <DropdownItem onClick={() => setDeleting(payment)} variant="danger">
                                                        Delete
                                                    </DropdownItem>
                                                </>
                                            )}
                                        </Dropdown>
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="px-4">
                        <Pagination links={payments.links} />
                    </div>
                </Card>
            )}

            <VerifyModal payment={reviewing} onClose={() => setReviewing(null)} />

            <Modal
                open={deleting !== null}
                onClose={closeDeleteModal}
                title={deleting ? `Delete ${deleting.customer_name}'s payment?` : undefined}
            >
                {deleting && (
                    <div>
                        <p className="text-sm text-slate-600">
                            This permanently removes the payment record{deleting.verification ? ' and its verification details' : ''}. This
                            cannot be undone.
                        </p>
                        <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <Button type="button" variant="secondary" onClick={closeDeleteModal} disabled={destroying}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="danger"
                                onClick={submitDelete}
                                disabled={destroying}
                                className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                            >
                                {destroying && <LoadingSpinner className="h-4 w-4" />}
                                {destroying ? 'Deleting…' : 'Delete'}
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}

function VerifyModal({ payment, onClose }: { payment: Payment | null; onClose: () => void }) {
    const { data, setData, transform, post, processing, errors, reset } = useForm({
        action: 'approve' as 'approve' | 'reject',
        momo_ref: '',
        notes: '',
    });

    // Recomputed only when the payment being reviewed changes, not on every
    // keystroke into the notes/momo_ref fields below (which re-render this
    // modal via useForm's `data` state).
    const formattedPayment = useMemo(
        () =>
            payment
                ? { amount: formatCurrency(payment.amount), createdAt: new Date(payment.created_at).toLocaleString() }
                : null,
        [payment],
    );

    function close() {
        reset();
        onClose();
    }

    function submit(e: FormEvent, action: 'approve' | 'reject') {
        e.preventDefault();

        if (!payment) {
            return;
        }

        // setData('action', action) followed immediately by post() would risk
        // submitting the PREVIOUS action value — React batches the state
        // update, so `data.action` isn't guaranteed to reflect it yet on this
        // same tick. transform() runs synchronously right before the request
        // body is built, so it always sees the real action being submitted.
        setData('action', action);
        transform((formData) => ({ ...formData, action }));

        post(`/payments/${payment.uuid}/verify`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    return (
        <Modal open={payment !== null} onClose={close} title="Review Payment">
            {payment && (
                <form className="flex flex-col gap-4">
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div className="border-b border-slate-200 pb-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Amount</p>
                            <p className="mt-0.5 text-2xl font-semibold text-slate-900">{formattedPayment?.amount}</p>
                        </div>
                        <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Customer</p>
                                <p className="font-medium text-slate-900">{payment.customer_name}</p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">Date</p>
                                <p className="font-medium text-slate-900">{formattedPayment?.createdAt}</p>
                            </div>
                        </div>
                        {payment.verification?.receipt_photo_url && (
                            <a href={payment.verification.receipt_photo_url} target="_blank" rel="noreferrer" className="mt-3 block">
                                <img
                                    src={payment.verification.receipt_photo_url}
                                    alt={`Receipt photo submitted by ${payment.customer_name} — opens full size in a new tab`}
                                    className="h-28 w-28 rounded-lg object-cover ring-1 ring-slate-200"
                                />
                            </a>
                        )}
                    </div>

                    <TextInput
                        id="momo_ref"
                        label="MOMO reference (optional)"
                        value={data.momo_ref}
                        onChange={(e) => setData('momo_ref', e.target.value)}
                        error={errors.momo_ref}
                    />

                    <div className="flex flex-col gap-1">
                        <label htmlFor="notes" className="text-sm font-medium text-slate-700">
                            Notes {data.action === 'reject' && <span className="text-red-500">(required to reject)</span>}
                        </label>
                        <textarea
                            id="notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            className={`rounded-md border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                                errors.notes ? 'ring-red-400 focus:ring-red-500' : ''
                            }`}
                        />
                        {errors.notes && <p className="text-xs text-red-600">{errors.notes}</p>}
                    </div>

                    <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <Button type="button" variant="secondary" onClick={close} disabled={processing} className="rounded-lg px-4 py-2.5 text-sm font-semibold">
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            disabled={processing}
                            onClick={(e) => submit(e, 'reject')}
                            className="rounded-lg px-4 py-2.5 text-sm font-semibold"
                        >
                            {processing && data.action === 'reject' ? <LoadingSpinner className="h-4 w-4" /> : <IconX size={16} stroke={2} />}
                            Reject
                        </Button>
                        <button
                            type="button"
                            disabled={processing}
                            onClick={(e) => submit(e, 'approve')}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing && data.action === 'approve' ? <LoadingSpinner className="h-4 w-4" /> : <IconCheck size={16} stroke={2} />}
                            Approve
                        </button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
