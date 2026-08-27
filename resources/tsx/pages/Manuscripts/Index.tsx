import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    IconAlertTriangle,
    IconBrandWhatsapp,
    IconCalculator,
    IconCash,
    IconDownload,
    IconReceipt2,
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
import { formatCurrency } from '@/lib/formatCurrency';
import type { Manuscript, ManuscriptSummary, PageProps, PaginatedResponse, Zone } from '@/types';

interface ManuscriptFilters {
    period?: string;
    zone_uuid?: string;
    status?: string;
}

interface ManuscriptsIndexProps {
    period: string;
    filters: ManuscriptFilters;
    manuscripts: PaginatedResponse<Manuscript>;
    summary: ManuscriptSummary;
    zones: Zone[];
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

    const calculateForm = useForm({ period });

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
        return params;
    }, [period, filters.zone_uuid, filters.status]);

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
                        <a
                            href={`/manuscripts/export?${exportParams.toString()}`}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                        >
                            <IconDownload size={18} stroke={1.75} />
                            Export
                        </a>
                    )}
                    {canCalculate && (
                        <Button onClick={() => setConfirmOpen(true)} className="rounded-lg px-3.5 py-2.5 text-sm font-semibold shadow-sm shadow-blue-600/20">
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
                                {canSendBill && <Th>Bill Reminder</Th>}
                            </TableHead>
                            <TableBody>
                                {rows.map(({ manuscript, formattedBill, formattedArrears, formattedCredit, formattedTotalBill }, index) => (
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
                                        <Td>{manuscript.payment_expiration ?? '—'}</Td>
                                        <Td>{formattedTotalBill}</Td>
                                        <Td>
                                            <StatusBadge status={manuscript.status} />
                                        </Td>
                                        {canSendBill && (
                                            <Td>
                                                {manuscript.wa_link ? (
                                                    <a
                                                        href={manuscript.wa_link}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        onClick={() => recordBillSent(manuscript.customer_uuid)}
                                                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-100"
                                                    >
                                                        <IconBrandWhatsapp size={14} stroke={1.75} />
                                                        Send Bill
                                                    </a>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-xs text-slate-400">
                                                        No phone on file
                                                    </span>
                                                )}
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination links={manuscripts.links} />
                    </>
                )}
            </div>

            <Modal open={confirmOpen} onClose={() => setConfirmOpen(false)} title="Run Manuscript Calculation">
                <div className="flex gap-3 rounded-lg bg-amber-100 p-3 ring-1 ring-inset ring-amber-300">
                    <IconAlertTriangle size={20} stroke={1.75} className="mt-0.5 shrink-0 text-amber-600" />
                    <p className="text-sm text-amber-800">
                        This is a real, consequential action. It <strong>overwrites the existing manuscript</strong> for period{' '}
                        <strong>{period}</strong> — recalculating bills, arrears and credit for every customer from live billing
                        data. Export the current manuscript first if you need a record of it. This action cannot be undone.
                    </p>
                </div>
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setConfirmOpen(false)} disabled={calculateForm.processing}>
                        Cancel
                    </Button>
                    <Button variant="danger" onClick={submitCalculate} disabled={calculateForm.processing}>
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
        </AppLayout>
    );
}
