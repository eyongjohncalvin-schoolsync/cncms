import { FormEvent, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table, TableBody, TableHead, Th, Td } from '@/components/ui/Table';
import { Pagination } from '@/components/ui/Pagination';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { PageProps, PaginatedResponse, Payment, PaymentFrequency, VerificationStatus } from '@/types';

interface PaymentsIndexProps {
    payments: PaginatedResponse<Payment>;
    filters: {
        verification_status: VerificationStatus | null;
        frequency: PaymentFrequency | null;
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

const frequencyLabels: Record<PaymentFrequency, string> = {
    monthly: 'Monthly',
    months: 'Multi-month',
    yearly: 'Yearly',
};

export default function PaymentsIndex({ payments, filters, statusCounts }: PaymentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canVerify = auth.user?.role === 'super' || auth.user?.role === 'admin' || auth.user?.role === 'manager';

    const [reviewing, setReviewing] = useState<Payment | null>(null);

    const activeTab = filters.verification_status ?? 'all';

    function goToTab(key: 'all' | VerificationStatus) {
        router.get(
            '/payments',
            { verification_status: key === 'all' ? undefined : key, frequency: filters.frequency ?? undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function changeFrequency(value: string) {
        router.get(
            '/payments',
            { verification_status: filters.verification_status ?? undefined, frequency: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AppLayout title="Payments">
            <Head title="Payments" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-1">
                    {tabs.map((tab) => {
                        const count = statusCounts[tab.key];
                        const active = activeTab === tab.key;

                        return (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => goToTab(tab.key)}
                                className={`flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                                    active ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                }`}
                            >
                                {tab.label}
                                <span
                                    className={`rounded-full px-1.5 py-0.5 text-xs ${
                                        active ? 'bg-blue-500 text-white' : 'bg-slate-100 text-slate-600'
                                    }`}
                                >
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="flex items-center gap-2">
                    <SelectInput
                        aria-label="Filter by frequency"
                        value={filters.frequency ?? ''}
                        onChange={(e) => changeFrequency(e.target.value)}
                        className="min-w-[10rem]"
                    >
                        <option value="">All frequencies</option>
                        <option value="monthly">Monthly</option>
                        <option value="months">Multi-month</option>
                        <option value="yearly">Yearly</option>
                    </SelectInput>
                    <Link href="/payments/create">
                        <Button>Record Payment</Button>
                    </Link>
                </div>
            </div>

            {payments.data.length === 0 ? (
                <EmptyState title="No payments found" description="Try a different filter or record a new payment." />
            ) : (
                <Card className="p-0">
                    <Table>
                        <TableHead>
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
                            {payments.data.map((payment) => (
                                <tr key={payment.uuid}>
                                    <Td>{new Date(payment.created_at).toLocaleDateString()}</Td>
                                    <Td>{payment.customer_name}</Td>
                                    <Td>{payment.zone_name ?? '—'}</Td>
                                    <Td className="font-medium text-slate-900">{formatCurrency(payment.amount)}</Td>
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
                                        <div className="flex items-center gap-3">
                                            <Link href={`/payments/${payment.uuid}`} className="text-sm font-medium text-blue-600 hover:text-blue-700">
                                                View
                                            </Link>
                                            {payment.verification_status === 'pending' && canVerify && (
                                                <button
                                                    type="button"
                                                    onClick={() => setReviewing(payment)}
                                                    className="text-sm font-medium text-blue-600 hover:text-blue-700"
                                                >
                                                    Review
                                                </button>
                                            )}
                                        </div>
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
        </AppLayout>
    );
}

function VerifyModal({ payment, onClose }: { payment: Payment | null; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        action: 'approve' as 'approve' | 'reject',
        momo_ref: '',
        notes: '',
    });

    function close() {
        reset();
        onClose();
    }

    function submit(e: FormEvent, action: 'approve' | 'reject') {
        e.preventDefault();

        if (!payment) {
            return;
        }

        setData('action', action);

        post(`/payments/${payment.uuid}/verify`, {
            data: { ...data, action },
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    return (
        <Modal open={payment !== null} onClose={close} title="Review Payment">
            {payment && (
                <form className="flex flex-col gap-4">
                    <div className="rounded-md bg-slate-50 p-3 text-sm">
                        <p>
                            <span className="text-slate-500">Customer:</span>{' '}
                            <span className="font-medium text-slate-900">{payment.customer_name}</span>
                        </p>
                        <p>
                            <span className="text-slate-500">Amount:</span>{' '}
                            <span className="font-medium text-slate-900">{formatCurrency(payment.amount)}</span>
                        </p>
                        <p>
                            <span className="text-slate-500">Date:</span>{' '}
                            <span className="font-medium text-slate-900">{new Date(payment.created_at).toLocaleString()}</span>
                        </p>
                        {payment.verification?.receipt_photo_url && (
                            <a href={payment.verification.receipt_photo_url} target="_blank" rel="noreferrer" className="mt-2 block">
                                <img
                                    src={payment.verification.receipt_photo_url}
                                    alt="Receipt"
                                    className="h-24 w-24 rounded-md object-cover ring-1 ring-slate-200"
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

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={close} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="button" variant="danger" disabled={processing} onClick={(e) => submit(e, 'reject')}>
                            Reject
                        </Button>
                        <Button type="button" disabled={processing} onClick={(e) => submit(e, 'approve')}>
                            Approve
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
