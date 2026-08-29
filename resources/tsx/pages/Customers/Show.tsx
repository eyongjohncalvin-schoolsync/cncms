import { Head, Link } from '@inertiajs/react';
import { useMemo, type ComponentType } from 'react';
import {
    IconCash,
    IconFileDescription,
    IconPrinter,
    IconReceipt2,
    IconWallet,
    IconMapPin,
    IconPhone,
    IconGauge,
    IconFileText,
    IconAlertTriangle,
    IconEdit,
    IconUserCircle,
    IconCalendarTime,
    IconClipboardList,
    IconScale,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { EmptyState } from '@/components/ui/EmptyState';
import { Badge } from '@/components/ui/Badge';
import { StatusBadge, VerificationBadge, ArrearsAdjustmentStatusBadge } from '@/components/shared/StatusBadge';
import { CustomerStatusActions } from '@/components/customers/CustomerStatusActions';
import { ArrearsAdjustmentModal } from '@/components/customers/ArrearsAdjustmentModal';
import { formatCurrency } from '@/lib/formatCurrency';
import { prepaidCoverageLabel } from '@/lib/prepaidCoverageLabel';
import type { CustomerDetail } from '@/types';

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return parts
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

export default function CustomersShow({ customer }: { customer: CustomerDetail }) {
    // Derived, formatted values are recomputed only when the underlying
    // customer/manuscript/payments data changes, not on unrelated re-renders.
    const formattedBill = useMemo(() => formatCurrency(customer.bill), [customer.bill]);

    const manuscript = useMemo(() => {
        if (!customer.manuscript) {
            return null;
        }

        return {
            period: customer.manuscript.period,
            bill: formatCurrency(customer.manuscript.bill),
            arrears: formatCurrency(customer.manuscript.total_arrears),
            credit: formatCurrency(customer.manuscript.credit),
            totalBill: formatCurrency(customer.manuscript.total_bill),
            expires: prepaidCoverageLabel(customer.manuscript),
        };
    }, [customer.manuscript]);

    // references/prepaid-pause-handling.md section 5: a passive note near the
    // status badge — visible only while suspended/disconnected with an
    // active prepaid window on file — telling any staff member which of the
    // two preservation states currently applies, without having to check
    // settings/logs. Absent entirely for 'active'/'passive' customers, or
    // any customer with no `payment_expiration` at all.
    const prepaidStatusNote = useMemo(() => {
        const paymentExpiration = customer.manuscript?.payment_expiration ?? null;

        if (!paymentExpiration) {
            return null;
        }

        if (customer.status === 'disconnected') {
            return 'Prepaid time will resume on reconnection.';
        }

        if (customer.status === 'suspended') {
            if (customer.prepaid_paused) {
                const expiresLabel = new Date(paymentExpiration).toLocaleDateString();
                const pausedSince = customer.status_changed_at ? new Date(customer.status_changed_at).toLocaleDateString() : null;

                return pausedSince
                    ? `Prepaid through ${expiresLabel} — paused since ${pausedSince}.`
                    : `Prepaid through ${expiresLabel} — paused.`;
            }

            return 'Prepaid clock is still running.';
        }

        return null;
    }, [customer.status, customer.prepaid_paused, customer.status_changed_at, customer.manuscript]);

    const recentPayments = useMemo(
        () =>
            customer.recent_payments.map((payment) => ({
                ...payment,
                formattedDate: new Date(payment.created_at).toLocaleDateString(),
                formattedAmount: formatCurrency(payment.amount),
                formattedCredit: formatCurrency(payment.credit),
            })),
        [customer.recent_payments],
    );

    return (
        <AppLayout title={customer.name} breadcrumbs={[{ label: 'Customers', href: '/customers' }, { label: customer.name }]}>
            <Head title={customer.name} />

            {/* Profile Header */}
            <div className="animate-fade-up mb-8">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-4">
                        <div className="relative shrink-0">
                            <div className="absolute inset-0 rounded-2xl bg-linear-to-br from-indigo-500 to-indigo-600 opacity-20 blur-lg"></div>
                            <span className="relative inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-indigo-500 to-indigo-600 text-lg font-semibold text-white shadow-lg shadow-indigo-500/25">
                                {initials(customer.name)}
                            </span>
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">{customer.name}</h1>
                                <StatusBadge status={customer.status} />
                                <Badge tone="blue">{customer.level}</Badge>
                            </div>
                            {prepaidStatusNote && <p className="mt-1 text-xs text-slate-500">{prepaidStatusNote}</p>}
                            <p className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500">
                                <span className="inline-flex items-center gap-1.5">
                                    <IconMapPin size={14} stroke={1.75} />
                                    {customer.zone_name}
                                    {customer.location ? ` · ${customer.location}` : ''}
                                </span>
                                {customer.phone && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <IconPhone size={14} stroke={1.75} />
                                        {customer.phone}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                        <CustomerStatusActions customer={customer} />
                        <ArrearsAdjustmentModal customer={customer} />
                        <a
                            href={`/customers/${customer.uuid}/bill/print`}
                            target="_blank"
                            rel="noreferrer"
                            title="Print bill"
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                        >
                            <IconPrinter size={16} stroke={2} />
                            Print Bill
                        </a>
                        <Link
                            href={`/customers/${customer.uuid}/edit`}
                            className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-600"
                        >
                            <IconEdit size={16} stroke={1.75} />
                            Edit
                        </Link>
                    </div>
                </div>
            </div>

            {/* Stat Callouts */}
            <div className="animate-fade-up mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" style={{ animationDelay: '0.05s' }}>
                <StatCard label="Monthly Bill" value={formattedBill} icon={<IconCash size={20} stroke={1.75} />} tone="blue" />
                {manuscript ? (
                    <>
                        <StatCard label="Arrears" value={manuscript.arrears} icon={<IconReceipt2 size={20} stroke={1.75} />} tone="red" />
                        <StatCard label="Credit" value={manuscript.credit} icon={<IconWallet size={20} stroke={1.75} />} tone="green" />
                        <StatCard
                            label="Total Bill"
                            value={manuscript.totalBill}
                            hint={`Period: ${manuscript.period}`}
                            icon={<IconFileDescription size={20} stroke={1.75} />}
                            tone="purple"
                        />
                    </>
                ) : (
                    <div className="sm:col-span-2 lg:col-span-3">
                        <EmptyState title="No manuscript calculated yet" description="Billing figures will appear here once a manuscript run includes this customer." />
                    </div>
                )}
            </div>

            {/* Body */}
            <div className="animate-fade-up grid grid-cols-1 gap-6 lg:grid-cols-3" style={{ animationDelay: '0.1s' }}>
                <Card className="lg:col-span-1">
                    <CardHeader className="border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                <IconUserCircle size={18} stroke={1.75} />
                            </span>
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Contact &amp; Details</h3>
                                <p className="mt-0.5 text-xs text-slate-500">Profile information on file</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-4 p-6">
                        <Field icon={IconPhone} label="Phone" value={customer.phone ?? '—'} />
                        <Field icon={IconMapPin} label="Zone" value={customer.zone_name} />
                        <Field icon={IconMapPin} label="Location" value={customer.location ?? '—'} />
                        <Field icon={IconGauge} label="Level" value={customer.level} />
                        <Field icon={IconFileText} label="Description" value={customer.description ?? '—'} />
                        {customer.status_reason && (
                            <Field icon={IconAlertTriangle} label="Status Reason" value={customer.status_reason.replace(/_/g, ' ')} />
                        )}
                        {customer.status_note && <Field icon={IconAlertTriangle} label="Status Note" value={customer.status_note} />}
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                                <IconClipboardList size={18} stroke={1.75} />
                            </span>
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Latest Manuscript</h3>
                                <p className="mt-0.5 text-xs text-slate-500">Most recent billing period on record</p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardBody className="p-6">
                        {manuscript ? (
                            <div className="grid grid-cols-2 gap-6 sm:grid-cols-3">
                                <Field icon={IconCalendarTime} label="Period" value={manuscript.period} />
                                <Field icon={IconCash} label="Bill" value={manuscript.bill} />
                                <Field icon={IconCalendarTime} label="Expires" value={manuscript.expires} />
                            </div>
                        ) : (
                            <EmptyState title="No manuscript calculated yet" />
                        )}
                    </CardBody>
                </Card>
            </div>

            <div className="animate-fade-up mt-6" style={{ animationDelay: '0.15s' }}>
                <Card className="p-0">
                    <CardHeader className="border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                <IconReceipt2 size={18} stroke={1.75} />
                            </span>
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Recent Payments</h3>
                                <p className="mt-0.5 text-xs text-slate-500">Latest transactions recorded for this customer</p>
                            </div>
                        </div>
                    </CardHeader>
                    {recentPayments.length === 0 ? (
                        <div className="p-4">
                            <EmptyState title="No payments recorded" />
                        </div>
                    ) : (
                        <Table>
                            <TableHead>
                                <Th>Date</Th>
                                <Th>Amount</Th>
                                <Th>Credit</Th>
                                <Th>Frequency</Th>
                                <Th>Verification</Th>
                            </TableHead>
                            <TableBody>
                                {recentPayments.map((payment) => (
                                    <tr key={payment.uuid} className="transition-colors hover:bg-slate-50/75">
                                        <Td>{payment.formattedDate}</Td>
                                        <Td>{payment.formattedAmount}</Td>
                                        <Td>{payment.formattedCredit}</Td>
                                        <Td className="capitalize">{payment.frequency}</Td>
                                        <Td>
                                            <VerificationBadge status={payment.verification_status} />
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>
            </div>

            {/* Arrears Adjustments — a separate block from Recent Payments above,
                never interleaved rows, per this feature's design doc. */}
            <div className="animate-fade-up mt-6" style={{ animationDelay: '0.2s' }}>
                <Card className="p-0">
                    <CardHeader className="border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                                <IconScale size={18} stroke={1.75} />
                            </span>
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Arrears Adjustments</h3>
                                <p className="mt-0.5 text-xs text-slate-500">Ledger corrections requested for this customer</p>
                            </div>
                        </div>
                    </CardHeader>
                    {customer.arrears_adjustments.length === 0 ? (
                        <div className="p-4">
                            <EmptyState title="No arrears adjustments requested" />
                        </div>
                    ) : (
                        <Table>
                            <TableHead>
                                <Th>Period</Th>
                                <Th>Direction</Th>
                                <Th>Amount</Th>
                                <Th>Reason</Th>
                                <Th>Requested By</Th>
                                <Th>Status</Th>
                            </TableHead>
                            <TableBody>
                                {customer.arrears_adjustments.map((adjustment) => (
                                    <tr key={adjustment.uuid} className="transition-colors hover:bg-slate-50/75">
                                        <Td>{adjustment.target_period}</Td>
                                        <Td className="capitalize">{adjustment.direction}</Td>
                                        <Td>{formatCurrency(adjustment.amount)}</Td>
                                        <Td className="capitalize">{adjustment.reason_category.replace(/_/g, ' ')}</Td>
                                        <Td>{adjustment.requested_by_name ?? '—'}</Td>
                                        <Td>
                                            <ArrearsAdjustmentStatusBadge status={adjustment.status} />
                                        </Td>
                                    </tr>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

function Field({ icon: Icon, label, value }: { icon?: ComponentType<{ size?: number; stroke?: number; className?: string }>; label: string; value: string }) {
    return (
        <div>
            <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase">
                {Icon && <Icon size={12} stroke={1.75} />}
                {label}
            </p>
            <p className="mt-0.5 text-sm text-slate-800">{value}</p>
        </div>
    );
}
