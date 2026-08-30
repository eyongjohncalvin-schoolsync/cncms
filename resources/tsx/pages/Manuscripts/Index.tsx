import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MenuItem } from '@headlessui/react';
import { useMemo, useState } from 'react';
import {
    IconAlertTriangle,
    IconBrandWhatsapp,
    IconCalculator,
    IconCash,
    IconChevronDown,
    IconDownload,
    IconFileTypePdf,
    IconFileTypeXls,
    IconReceipt2,
    IconScale,
    IconSearch,
    IconUsers,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { StatCard } from '@/components/ui/StatCard';
import { Button } from '@/components/ui/Button';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Dropdown, DropdownItem, DropdownDivider } from '@/components/ui/Dropdown';
import { ArrearsAdjustmentModal } from '@/components/customers/ArrearsAdjustmentModal';
import { PreRunReviewPanel } from '@/components/manuscripts/PreRunReviewPanel';
import { usePreRunReview } from '@/hooks/usePreRunReview';
import { formatCurrency } from '@/lib/formatCurrency';
import { prepaidCoverageLabel } from '@/lib/prepaidCoverageLabel';
import type { Manuscript, ManuscriptSummary, PageProps, PaginatedResponse, Zone } from '@/types';

interface ManuscriptFilters {
    period?: string;
    zone_uuid?: string;
    status?: string;
    search?: string;
}

interface ManuscriptsIndexProps {
    period: string;
    filters: ManuscriptFilters;
    manuscripts: PaginatedResponse<Manuscript>;
    summary: ManuscriptSummary;
    zones: Zone[];
}

/**
 * 2026-08-28 correction (business-rules.md section 2): a manuscript run
 * triggered near month-end governs the UPCOMING month, not the one it's
 * clicked in — matches the identical default now computed server-side in
 * ManuscriptController::calculate()/preRunReview()/preRunReviewFull().
 * Computed independently of the page's own (possibly filtered-to-a-past-
 * period) `period` prop — Run Calculation must always default to the real
 * upcoming period regardless of which period the admin happens to be
 * browsing.
 */
function upcomingPeriod(): string {
    const now = new Date();
    const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
}

const EXPORT_ROLES = ['super', 'admin', 'manager'];
const CALCULATE_ROLES = ['super', 'admin'];
// Matches App\Policies\ManuscriptPolicy::sendBill() — same roles as
// viewAny()/view(), so in practice anyone who can reach this page already
// qualifies; kept explicit for the same reason EXPORT_ROLES/CALCULATE_ROLES
// are, rather than assuming that alignment holds forever.
const SEND_BILL_ROLES = ['super', 'admin', 'manager', 'agent'];

export default function ManuscriptsIndex({ period, filters, manuscripts, summary, zones }: ManuscriptsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const canExport = role !== null && EXPORT_ROLES.includes(role);
    const canCalculate = role !== null && CALCULATE_ROLES.includes(role);
    const canSendBill = role !== null && SEND_BILL_ROLES.includes(role);

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [isFiltering, setIsFiltering] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    // Adjust-Arrears modal target. Held at page level, NOT inside the row
    // <Dropdown> — a Headless UI menu unmounts its contents the instant an
    // item is clicked, which would tear the modal down mid-open ("flashes
    // and disappears, backdrop stuck").
    const [adjustCustomer, setAdjustCustomer] = useState<{
        uuid: string;
        name: string;
        manuscript: { total_arrears: string; credit: string };
    } | null>(null);

    const calculateForm = useForm({ period: upcomingPeriod(), confirmed_rerun: false });

    // Fetched on-demand, only while the confirm modal is actually open — not
    // on page load or on every filter change (the design ask) — keyed on
    // calculateForm.data.period (the value that will actually be submitted)
    // rather than the page's own `period` prop, so the review list can never
    // silently disagree with what Calculate is about to run.
    const preRunReview = usePreRunReview(calculateForm.data.period, undefined, confirmOpen);

    // Advisory, not a hard per-row gate (a legitimately long list — e.g.
    // early in the month — must stay usable): the submit button only waits
    // for the review list's FIRST load attempt to settle (data or an error,
    // either counts as "the admin has now seen it") before becoming
    // reachable, never for every flagged name to be individually dismissed.
    const reviewSettled = preRunReview.data !== null || preRunReview.error !== null;

    // dispatch() throws a validation error on the `period` field for TWO
    // distinct reasons that must not be conflated (final-verification-pass
    // fix): ManuscriptRerunGuard (a PUBLISHED period — confirmed_rerun:true
    // genuinely unblocks a resubmit) vs idx_command_runs_period_inflight's
    // unique-violation catch in ManuscriptGenerationBatchService::dispatch()
    // (a run for this period is still queued/pending_review — checking a
    // confirmation box and resubmitting hits this exact same error again,
    // since confirmed_rerun only bypasses the rerun guard, not the in-flight
    // lock). Distinguished by matching the rerun guard's own fixed closing
    // sentence (ManuscriptRerunGuard::describe()) rather than guessing from
    // field presence alone, so the checkbox is only ever offered when it can
    // actually help.
    const periodError = calculateForm.errors.period;
    const rerunBlocked = !!periodError && periodError.includes('Confirm the rerun if you really intend to recompute this period.');
    const inFlightBlocked = !!periodError && !rerunBlocked;

    function openConfirm() {
        calculateForm.clearErrors();
        calculateForm.setData('confirmed_rerun', false);
        setConfirmOpen(true);
    }

    function closeConfirm() {
        setConfirmOpen(false);
        calculateForm.clearErrors();
        calculateForm.setData('confirmed_rerun', false);
    }

    function applyFilter(next: Partial<ManuscriptFilters>) {
        router.get(
            '/manuscripts',
            { ...filters, period, ...next },
            {
                preserveState: true,
                replace: true,
                onStart: () => setIsFiltering(true),
                onFinish: () => setIsFiltering(false),
            },
        );
    }

    function submitSearch() {
        applyFilter({ search: search.trim() || undefined });
    }

    function submitCalculate() {
        calculateForm.post('/manuscripts/calculate', {
            onSuccess: () => setConfirmOpen(false),
        });
    }

    // Fire-and-forget: logs a `messages` row (channel: 'whatsapp', status:
    // 'link_opened') alongside the wa.me link the browser opens itself via
    // the anchor's own href/target="_blank" — see
    // ManuscriptController::sendBill()'s doc comment for why no backend
    // round-trip is needed to actually send. preserveScroll/preserveState
    // keep this from disrupting the list the staff member is working
    // through (bill-notifications.md section 5's "queue" UX note).
    function recordBillSent(customerUuid: string) {
        router.post(
            `/manuscripts/${customerUuid}/send-bill`,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    // Recomputed only when the period/filters actually change, not on every
    // unrelated re-render (e.g. opening the calculate-confirmation modal).
    const exportParams = useMemo(() => {
        const params = new URLSearchParams();
        params.set('period', period);
        if (filters.zone_uuid) params.set('zone_uuid', filters.zone_uuid);
        if (filters.status) params.set('status', filters.status);
        if (filters.search) params.set('search', filters.search);
        return params;
    }, [period, filters.zone_uuid, filters.status, filters.search]);

    // Consolidated down to 3 compact cards (was 6, one of them an
    // oversized "hero" card) — the owner's explicit call was fewer/smaller
    // stat cards, not a rearrangement at the same size. Billed+arrears and
    // collected+rate are each folded into a single card via StatCard's
    // `hint` slot rather than getting their own card, since they're two
    // views of the same figure (arrears is part of what's billed;
    // collection_rate is collected÷billed) rather than independent facts.
    const stats = useMemo(
        () => ({
            totalCustomers: summary.total_customers.toLocaleString(),
            totalBilled: formatCurrency(summary.total_bill),
            arrearsHint: `Arrears: ${formatCurrency(summary.total_arrears)}`,
            totalCollected: formatCurrency(summary.total_collected),
            collectionRateHint: `Collection rate: ${summary.collection_rate}%`,
        }),
        [summary],
    );

    // Row-level formatted currency values, recomputed only when the
    // manuscripts page itself changes — mirrors the `rows` pattern already
    // used on Customers/Index.tsx, Zones/Index.tsx, Payments/Index.tsx and
    // Agents/Index.tsx.
    const rows = useMemo(
        () =>
            manuscripts.data.map((manuscript) => ({
                manuscript,
                formattedBill: formatCurrency(manuscript.bill),
                formattedArrears: formatCurrency(manuscript.total_arrears),
                formattedCredit: formatCurrency(manuscript.credit),
                formattedTotalBill: formatCurrency(manuscript.total_bill),
                // Mapped into the `{uuid, name, manuscript: {total_arrears, credit}}`
                // shape ArrearsAdjustmentModal expects (same shape
                // Customers/Show.tsx already passes it) — trivial reshaping
                // of fields this row already carries, not a new fetch.
                arrearsCustomer: {
                    uuid: manuscript.customer_uuid,
                    name: manuscript.customer_name,
                    manuscript: { total_arrears: manuscript.total_arrears, credit: manuscript.credit },
                },
            })),
        [manuscripts.data],
    );

    return (
        <AppLayout title="Manuscripts">
            <Head title="Manuscripts" />

            <div className="animate-fade-up mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">Manuscripts — Current Period</h1>
                    <p className="mt-1 text-sm text-slate-500">Billing snapshot, arrears and collection status for every customer.</p>
                </div>
                <div className="flex gap-2">
                    {canExport && (
                        <Dropdown
                            align="end"
                            trigger={
                                <span className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                                    <IconDownload size={18} stroke={1.75} />
                                    Export
                                    <IconChevronDown size={16} stroke={1.75} />
                                </span>
                            }
                        >
                            {/* Plain <a download> anchors, not Inertia <Link>s / DropdownItem's
                                href branch — these are file downloads the browser must handle
                                itself, not client-side visits. */}
                            {/* Portrait is the default register layout (fits more
                                customer rows per page); landscape is the wider
                                alternative — both map to ?orientation on the same
                                export route. */}
                            <MenuItem>
                                <a
                                    href={`/manuscripts/export?${exportParams.toString()}&orientation=portrait`}
                                    download
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-slate-700 transition-colors data-focus:bg-slate-100"
                                >
                                    <IconFileTypePdf size={16} stroke={1.75} />
                                    Download PDF (Portrait)
                                </a>
                            </MenuItem>
                            <MenuItem>
                                <a
                                    href={`/manuscripts/export?${exportParams.toString()}&orientation=landscape`}
                                    download
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-slate-700 transition-colors data-focus:bg-slate-100"
                                >
                                    <IconFileTypePdf size={16} stroke={1.75} />
                                    Download PDF (Landscape)
                                </a>
                            </MenuItem>
                            <MenuItem>
                                <a
                                    href={`/manuscripts/export?${exportParams.toString()}&format=xlsx`}
                                    download
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-slate-700 transition-colors data-focus:bg-slate-100"
                                >
                                    <IconFileTypeXls size={16} stroke={1.75} />
                                    Download Excel
                                </a>
                            </MenuItem>

                            <div className="my-1 h-px bg-slate-200" />

                            {/* The actual customer bill slips (not the register) — every
                                active customer's bill for this period, tiled N-up per the
                                Bill Printing setting and ordered by zone then name so an
                                agent's zone comes out as one contiguous stack. Honours the
                                same period/zone/status/search filters. */}
                            <MenuItem>
                                <a
                                    href={`/manuscripts/bills?${exportParams.toString()}`}
                                    download
                                    className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-slate-700 transition-colors data-focus:bg-slate-100"
                                >
                                    <IconReceipt2 size={16} stroke={1.75} />
                                    Download Bills (by zone)
                                </a>
                            </MenuItem>
                        </Dropdown>
                    )}
                    {canCalculate && (
                        <Button onClick={openConfirm} className="rounded-lg px-3.5 py-2.5 text-sm font-semibold shadow-sm shadow-blue-600/20">
                            <IconCalculator size={18} stroke={1.75} />
                            Run Manuscript Calculation
                        </Button>
                    )}
                </div>
            </div>

            {/* Period dropped as its own card — the filter bar just below already
                has a live period picker, so a static "Period" tile only repeated
                it. Total Billed and Total Collected each absorb their related
                figure (arrears, collection rate) via StatCard's `hint` line
                instead of a separate card, per the owner's call to shrink this
                row rather than just re-lay it out. */}
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                    <StatCard label="Total Customers" value={stats.totalCustomers} icon={<IconUsers size={20} stroke={1.75} />} tone="blue" />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '0.1s' }}>
                    <StatCard
                        label="Total Billed"
                        value={stats.totalBilled}
                        hint={stats.arrearsHint}
                        icon={<IconReceipt2 size={20} stroke={1.75} />}
                        tone="purple"
                    />
                </div>
                <div className="animate-fade-up" style={{ animationDelay: '0.15s' }}>
                    <StatCard
                        label="Total Collected"
                        value={stats.totalCollected}
                        hint={stats.collectionRateHint}
                        icon={<IconCash size={20} stroke={1.75} />}
                        tone="green"
                    />
                </div>
            </div>

            <div className="animate-fade-up mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4" style={{ animationDelay: '0.25s' }}>
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <TextInput
                        id="period"
                        label="Period"
                        type="month"
                        value={period}
                        onChange={(e) => applyFilter({ period: e.target.value })}
                        className="rounded-lg bg-white"
                    />
                    <SelectInput
                        id="zone"
                        label="Zone"
                        value={filters.zone_uuid ?? ''}
                        onChange={(e) => applyFilter({ zone_uuid: e.target.value || undefined })}
                        className="rounded-lg bg-white"
                    >
                        <option value="">All zones</option>
                        {zones.map((zone) => (
                            <option key={zone.uuid} value={zone.uuid}>
                                {zone.name}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        id="status"
                        label="Status"
                        value={filters.status ?? ''}
                        onChange={(e) => applyFilter({ status: e.target.value || undefined })}
                        className="rounded-lg bg-white"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="passive">Passive</option>
                        <option value="disconnected">Disconnected</option>
                        <option value="suspended">Suspended</option>
                    </SelectInput>
                    <div className="flex items-end gap-2">
                        <TextInput
                            id="search"
                            label="Search"
                            placeholder="Customer name or phone"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                            className="rounded-lg bg-white"
                        />
                        <Button type="button" variant="secondary" onClick={submitSearch} className="h-[38px]">
                            <IconSearch size={15} stroke={1.75} />
                            Search
                        </Button>
                    </div>
                    {isFiltering && <LoadingSpinner className="mb-2 text-slate-400" />}
                </div>
            </div>

            <div className="animate-fade-up" style={{ animationDelay: '0.3s' }}>
                {manuscripts.data.length === 0 ? (
                    <EmptyState title="No manuscripts for this period" description="Run the manuscript calculation to generate one." />
                ) : (
                    <>
                        <Table>
                            <TableHead>
                                <Th>#</Th>
                                <Th>Name</Th>
                                <Th>Code</Th>
                                <Th>Phone</Th>
                                <Th>Zone</Th>
                                <Th>Level</Th>
                                <Th>Bill</Th>
                                <Th>Arrears</Th>
                                <Th>Credit</Th>
                                <Th>Expiry</Th>
                                <Th>Total Bill</Th>
                                <Th>Status</Th>
                                <Th>Actions</Th>
                            </TableHead>
                            <TableBody>
                                {rows.map(({ manuscript, formattedBill, formattedArrears, formattedCredit, formattedTotalBill, arrearsCustomer }, index) => (
                                    <tr key={manuscript.customer_uuid} className="transition-colors hover:bg-slate-50">
                                        <Td>{(manuscripts.meta.current_page - 1) * manuscripts.meta.per_page + index + 1}</Td>
                                        <Td>{manuscript.customer_name}</Td>
                                        <Td className="font-mono text-xs uppercase">{manuscript.customer_code}</Td>
                                        <Td>{manuscript.phone ?? '—'}</Td>
                                        <Td>{manuscript.zone_name ?? '—'}</Td>
                                        <Td>{manuscript.level}</Td>
                                        <Td>{formattedBill}</Td>
                                        <Td>{formattedArrears}</Td>
                                        <Td>{formattedCredit}</Td>
                                        <Td>{prepaidCoverageLabel(manuscript)}</Td>
                                        <Td>{formattedTotalBill}</Td>
                                        <Td>
                                            <StatusBadge status={manuscript.status} />
                                        </Td>
                                        <Td>
                                            {/* Same Dropdown/DropdownItem/DropdownDivider kebab-menu pattern
                                                Customers/Index.tsx's Actions column uses — regroups this row's
                                                pre-existing Send Bill action alongside the new Adjust Arrears
                                                entry, one dropdown per row, distinctly labeled. */}
                                            <Dropdown label={`Actions for ${manuscript.customer_name}`}>
                                                <DropdownItem
                                                    onClick={() => setAdjustCustomer(arrearsCustomer)}
                                                    icon={<IconScale size={16} stroke={1.75} />}
                                                >
                                                    Adjust Arrears
                                                </DropdownItem>
                                                {canSendBill && (
                                                    <>
                                                        <DropdownDivider />
                                                        {manuscript.wa_link ? (
                                                            // DropdownItem's `href` branch always renders through
                                                            // Inertia's <Link> (router.visit()), which would wrongly
                                                            // intercept this external wa.me URL instead of letting the
                                                            // browser open it in a new tab — the exact reason the
                                                            // original markup used a plain <a>. window.open() here
                                                            // preserves that same "opens in a new tab" behavior while
                                                            // still composing as a plain onClick DropdownItem.
                                                            <DropdownItem
                                                                onClick={() => {
                                                                    window.open(manuscript.wa_link!, '_blank', 'noopener,noreferrer');
                                                                    recordBillSent(manuscript.customer_uuid);
                                                                }}
                                                                icon={<IconBrandWhatsapp size={16} stroke={1.75} />}
                                                            >
                                                                Send Bill
                                                            </DropdownItem>
                                                        ) : manuscript.status !== 'active' ? (
                                                            // A null wa_link for a non-active customer is the
                                                            // server refusing to send a bill (owner decision,
                                                            // 2026-08 — BillNotificationService::composeMessage()),
                                                            // NOT a missing phone. Say so plainly; the server
                                                            // refusal in ManuscriptController::sendBill() stays
                                                            // the real guard.
                                                            <DropdownItem disabled icon={<IconBrandWhatsapp size={16} stroke={1.75} />}>
                                                                Customer not active
                                                            </DropdownItem>
                                                        ) : (
                                                            <DropdownItem disabled icon={<IconBrandWhatsapp size={16} stroke={1.75} />}>
                                                                No phone on file
                                                            </DropdownItem>
                                                        )}
                                                    </>
                                                )}
                                            </Dropdown>
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination links={manuscripts.links} />
                    </>
                )}
            </div>

            <Modal open={confirmOpen} onClose={closeConfirm} title="Run Manuscript Calculation">
                <div className="flex flex-col gap-3">
                    <PreRunReviewPanel
                        period={calculateForm.data.period}
                        loading={preRunReview.loading}
                        error={preRunReview.error}
                        data={preRunReview.data}
                        onReload={preRunReview.reload}
                    />

                    <div className="flex gap-3 rounded-lg bg-amber-100 p-3 ring-1 ring-inset ring-amber-300">
                        <IconAlertTriangle size={20} stroke={1.75} className="mt-0.5 shrink-0 text-amber-600" />
                        <p className="text-sm text-amber-800">
                            This is a real, consequential action. It <strong>overwrites the existing manuscript</strong> for period{' '}
                            <strong>{calculateForm.data.period}</strong> — recalculating bills, arrears and credit for every
                            customer from live billing data. Export the current manuscript first if you need a record of it.
                            This action cannot be undone.
                        </p>
                    </div>

                    {/* Escalation for the ManuscriptRerunGuard rejection (task-scheduler.md's
                        2026-08-27 stage 3 addendum) — a validation error on `period` means a
                        published run already exists for this period. Resubmitting with
                        confirmed_rerun:true requires the admin to explicitly check this box
                        first; it is never set automatically. */}
                    {rerunBlocked && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-3">
                            <p className="text-sm text-red-800">{calculateForm.errors.period}</p>
                            <label className="mt-2 flex items-start gap-2 text-sm text-red-900">
                                <input
                                    type="checkbox"
                                    checked={calculateForm.data.confirmed_rerun}
                                    onChange={(e) => calculateForm.setData('confirmed_rerun', e.target.checked)}
                                    className="mt-0.5 h-4 w-4 rounded border-red-300 text-red-600 focus:ring-red-600"
                                />
                                I understand this period was already calculated and published, and I want to recompute it
                                anyway.
                            </label>
                        </div>
                    )}

                    {/* The OTHER validation error `period` can carry: a run for this period is
                        still queued/pending_review right now (idx_command_runs_period_inflight,
                        surfaced via ManuscriptGenerationBatchService::dispatch()'s unique-violation
                        catch). No checkbox here — confirmed_rerun does not bypass this lock, only
                        ManuscriptRerunGuard, so resubmitting after checking it would just hit this
                        exact same error again. Point the admin at the one real way to clear a stuck
                        run instead. */}
                    {inFlightBlocked && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-3">
                            <p className="text-sm text-red-800">{calculateForm.errors.period}</p>
                            <p className="mt-2 text-sm text-red-900">
                                If that run is genuinely stuck (not actively progressing), a{' '}
                                <span className="font-semibold">super/admin</span> can cancel it from{' '}
                                <a href="/settings/command-runs" target="_blank" rel="noreferrer" className="underline">
                                    Settings → Command Runs
                                </a>{' '}
                                to free this period up again.
                            </p>
                        </div>
                    )}
                </div>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={closeConfirm} disabled={calculateForm.processing}>
                        Cancel
                    </Button>
                    <Button
                        variant="danger"
                        onClick={submitCalculate}
                        disabled={
                            calculateForm.processing ||
                            !reviewSettled ||
                            (rerunBlocked && !calculateForm.data.confirmed_rerun) ||
                            inFlightBlocked
                        }
                    >
                        {calculateForm.processing ? (
                            <>
                                <LoadingSpinner />
                                Running…
                            </>
                        ) : (
                            'Yes, Run Calculation'
                        )}
                    </Button>
                </div>
            </Modal>

            {/* Rendered here, outside the table/Dropdown, so clicking the
                menu item (which closes the Dropdown) can't unmount it.
                Keyed by uuid + only mounted while a target is selected, so
                each open starts with a clean form for the right customer. */}
            {adjustCustomer && (
                <ArrearsAdjustmentModal
                    key={adjustCustomer.uuid}
                    customer={adjustCustomer}
                    open
                    onClose={() => setAdjustCustomer(null)}
                />
            )}
        </AppLayout>
    );
}
