import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { IconCheck, IconCircleX, IconUserCheck } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import { ExpenseStatusBadge, FlagChips, ReasonModal, ReceiptPreview, StaffMatchWarning, formatDate, paymentMethodLabel } from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Expenditure, PageProps } from '@/types';

interface PendingSupplier {
    uuid: string;
    name: string;
    phone: string | null;
    momo_number: string | null;
    created_by: string | null;
    staff_matches: string[];
}

interface ApprovalsProps {
    items: Expenditure[];
    can_approve: boolean;
    pending_suppliers: PendingSupplier[];
}

export default function Approvals({ items, can_approve, pending_suppliers }: ApprovalsProps) {
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [rejecting, setRejecting] = useState<Expenditure | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const pageError = errors?.expenditure ?? errors?.supplier;
    const total = items.reduce((sum, item) => sum + Number(item.amount), 0);

    function post(url: string, key: string) {
        router.post(url, {}, {
            preserveScroll: true,
            onStart: () => setBusy(key),
            onFinish: () => setBusy(null),
        });
    }

    const nothingWaiting = items.length === 0 && pending_suppliers.length === 0;

    return (
        <AppLayout title="Approvals" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Approvals' }]}>
            <Head title="Approvals" />

            <ResourcesTabs active="approvals" />

            <div className="mb-5 animate-fade-up">
                <h1 className="font-display text-2xl text-slate-900">Approvals</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {items.length > 0
                        ? `${items.length} expense${items.length === 1 ? '' : 's'} waiting · ${formatCurrency(total)}`
                        : 'Expenses and suppliers waiting for your decision.'}
                </p>
            </div>

            {pageError && <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{pageError}</div>}

            {nothingWaiting ? (
                <div className="animate-fade-up" style={{ animationDelay: '80ms' }}>
                    <EmptyState title="Nothing is waiting for you" description="New expenses that need a decision will appear here." />
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    {items.length > 0 && (
                        <section className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                            <h2 className="mb-2 text-sm font-semibold text-slate-700">Expenses</h2>
                            <ul className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                {items.map((item) => (
                                    <li key={item.uuid}>
                                        <Card className="h-full">
                                            <CardBody className="flex gap-3">
                                                <ReceiptPreview url={item.receipt_url} compact />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <Link
                                                            href={`/resources/expenditures/${item.uuid}`}
                                                            className="font-display text-xl font-semibold tracking-tight text-slate-900 hover:text-blue-700"
                                                        >
                                                            {formatCurrency(item.amount)}
                                                        </Link>
                                                        <ExpenseStatusBadge status={item.status} />
                                                    </div>
                                                    <p className="truncate text-sm font-medium text-slate-700">
                                                        {item.category_name}
                                                        {item.description ? ` — ${item.description}` : ''}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-slate-500">
                                                        {formatDate(item.spent_at)} · {paymentMethodLabel(item.payment_method)}
                                                        {item.supplier ? ` · ${item.supplier.name}` : ''}
                                                        {item.recorded_by ? ` · by ${item.recorded_by.name}` : ''}
                                                    </p>
                                                    {item.status === 'pending_second_approval' && (
                                                        <p className="mt-1.5 inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-800 ring-1 ring-inset ring-blue-200">
                                                            Second approval
                                                            {item.first_approved_by ? ` · first by ${item.first_approved_by.name}` : ''}
                                                        </p>
                                                    )}
                                                    {item.flags.length > 0 && (
                                                        <div className="mt-2">
                                                            <FlagChips flags={item.flags} />
                                                        </div>
                                                    )}
                                                </div>
                                            </CardBody>
                                            {can_approve && (
                                                <div className="grid grid-cols-2 gap-2 border-t border-slate-100 px-4 py-3">
                                                    <Button
                                                        onClick={() => post(`/resources/expenditures/${item.uuid}/approve`, item.uuid)}
                                                        disabled={busy !== null || item.status === 'pending_evidence'}
                                                        className="min-h-[44px] bg-green-600 font-semibold shadow-green-600/20 hover:bg-green-700"
                                                    >
                                                        {busy === item.uuid ? <LoadingSpinner className="text-white" /> : <IconCheck size={18} stroke={2} />}
                                                        Approve
                                                    </Button>
                                                    <Button variant="secondary" onClick={() => setRejecting(item)} className="min-h-[44px] font-semibold text-red-700">
                                                        <IconCircleX size={18} stroke={2} />
                                                        Reject
                                                    </Button>
                                                </div>
                                            )}
                                        </Card>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {pending_suppliers.length > 0 && (
                        <section className="animate-fade-up" style={{ animationDelay: '120ms' }}>
                            <Card>
                                <CardHeader>
                                    <h2 className="text-sm font-semibold text-slate-900">New or changed suppliers</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">Check the name and numbers before approving — payments to them then skip extra review.</p>
                                </CardHeader>
                                <ul className="divide-y divide-slate-100">
                                    {pending_suppliers.map((supplier) => (
                                        <li key={supplier.uuid} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="min-w-0">
                                                <p className="font-medium text-slate-900">{supplier.name}</p>
                                                <p className="text-xs text-slate-500">
                                                    {supplier.phone ? `Phone ${supplier.phone}` : 'No phone'}
                                                    {supplier.momo_number ? ` · MoMo ${supplier.momo_number}` : ''}
                                                    {supplier.created_by ? ` · added by ${supplier.created_by}` : ''}
                                                </p>
                                                {supplier.staff_matches.length > 0 && <StaffMatchWarning matches={supplier.staff_matches} />}
                                            </div>
                                            <Button
                                                onClick={() => post(`/resources/suppliers/${supplier.uuid}/approve`, supplier.uuid)}
                                                disabled={busy !== null}
                                                variant={supplier.staff_matches.length > 0 ? 'warning' : 'primary'}
                                                className="min-h-[44px] w-full shrink-0 sm:w-auto"
                                            >
                                                {busy === supplier.uuid ? <LoadingSpinner className="text-white" /> : <IconUserCheck size={18} stroke={1.75} />}
                                                Approve supplier
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        </section>
                    )}
                </div>
            )}

            <ReasonModal
                open={rejecting !== null}
                onClose={() => setRejecting(null)}
                title={rejecting ? `Reject ${formatCurrency(rejecting.amount)}` : 'Reject expense'}
                description="The person who recorded it will see your reason."
                url={rejecting ? `/resources/expenditures/${rejecting.uuid}/reject` : ''}
                confirmLabel="Reject expense"
            />
        </AppLayout>
    );
}
