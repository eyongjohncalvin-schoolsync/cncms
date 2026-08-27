import { FormEvent, ReactNode, useMemo, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconArrowLeft, IconCash, IconUpload, IconWallet } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { ArrearsAdjustmentModal } from '@/components/customers/ArrearsAdjustmentModal';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Payment } from '@/types';

interface PaymentsShowProps {
    payment: Payment;
    can_manage: boolean;
    can_delete: boolean;
}

const frequencyLabels: Record<Payment['frequency'], string> = {
    monthly: 'Monthly',
    months: 'Multi-month',
    yearly: 'Yearly',
};

export default function PaymentsShow({ payment, can_manage, can_delete }: PaymentsShowProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [destroying, setDestroying] = useState(false);

    function closeDeleteModal() {
        if (destroying) {
            return;
        }
        setConfirmingDelete(false);
    }

    function submitDelete() {
        router.delete(`/payments/${payment.uuid}`, {
            onStart: () => setDestroying(true),
            onFinish: () => setDestroying(false),
        });
    }

    const { data, setData, post, processing, errors, reset } = useForm<{ receipt: File | null }>({
        receipt: null,
    });

    // Derived, formatted values are recomputed only when the underlying
    // payment record changes, not on unrelated re-renders (e.g. typing into
    // the receipt-upload form state above).
    const formatted = useMemo(
        () => ({
            amount: formatCurrency(payment.amount),
            credit: formatCurrency(payment.credit),
            createdAt: new Date(payment.created_at).toLocaleString(),
            collectedAt: payment.collected_at ? new Date(payment.collected_at).toLocaleString() : null,
            processedAt: payment.processed_at ? new Date(payment.processed_at).toLocaleString() : '—',
            verifiedAt: payment.verification?.verified_at ? new Date(payment.verification.verified_at).toLocaleString() : '—',
        }),
        [payment],
    );

    // Mapped into the `{uuid, name, manuscript: {total_arrears}}` shape
    // ArrearsAdjustmentModal expects — same shape Customers/Show.tsx
    // already passes it. `customer_total_arrears` is only ever populated
    // on this page (PaymentController::show() eager-loads it specifically
    // for this), so `manuscript` is null rather than omitted when absent.
    const arrearsCustomer = useMemo(
        () => ({
            uuid: payment.customer_uuid,
            name: payment.customer_name,
            manuscript: payment.customer_total_arrears != null ? { total_arrears: payment.customer_total_arrears } : null,
        }),
        [payment.customer_uuid, payment.customer_name, payment.customer_total_arrears],
    );

    function submit(e: FormEvent) {
        e.preventDefault();

        post(`/payments/${payment.uuid}/receipt`, {
            forceFormData: true,
            onSuccess: () => {
                reset();
                setFileName(null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    }

    return (
        <AppLayout
            title="Payment Detail"
            breadcrumbs={[{ label: 'Payments', href: '/payments' }, { label: payment.customer_name }]}
        >
            <Head title="Payment Detail" />

            <div className="mb-6 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <Link href="/payments" className="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <IconArrowLeft size={16} stroke={2} />
                    Back to Payments
                </Link>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="font-display text-2xl text-slate-900">Payment Detail</h2>
                        <p className="mt-1 text-sm text-slate-500">{payment.customer_name}</p>
                    </div>
                    {/* Adjust Arrears is ungated (ArrearsAdjustmentPolicy::create() —
                        any authenticated tenant user, same as ComplaintPolicy::
                        create()), so it renders unconditionally here, unlike
                        Edit/Delete Payment which stay behind can_manage/can_delete. */}
                    <div className="flex items-center gap-2">
                        <ArrearsAdjustmentModal customer={arrearsCustomer} />
                        {can_manage && (
                            <Link
                                href={`/payments/${payment.uuid}/edit`}
                                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                            >
                                Edit Payment
                            </Link>
                        )}
                        {can_delete && (
                            <Button type="button" variant="danger" onClick={() => setConfirmingDelete(true)} className="rounded-lg px-4 py-2 text-sm font-semibold">
                                Delete Payment
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="mb-4 grid max-w-3xl grid-cols-1 gap-4 animate-fade-up sm:grid-cols-2" style={{ animationDelay: '100ms' }}>
                <StatCard label="Amount" value={formatted.amount} icon={<IconCash size={20} stroke={1.75} />} tone="green" />
                <StatCard label="Credit" value={formatted.credit} icon={<IconWallet size={20} stroke={1.75} />} tone="blue" />
            </div>

            <div className="grid max-w-3xl grid-cols-1 gap-4 md:grid-cols-2">
                <Card className="animate-fade-up" style={{ animationDelay: '200ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Payment</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col divide-y divide-slate-100 text-sm">
                        <Row label="Customer" value={payment.customer_name} />
                        <Row label="Zone" value={payment.zone_name ?? '—'} />
                        <Row label="Frequency" value={frequencyLabels[payment.frequency]} />
                        {payment.months !== null && <Row label="Months" value={String(payment.months)} />}
                        <Row label="Expiration" value={payment.expiration_date ?? '—'} />
                        <Row label="Recorded" value={payment.recorded_offline ? 'Offline (field agent)' : 'Office'} />
                        {payment.recorded_by_device && <Row label="Device" value={payment.recorded_by_device} />}
                        <Row label="Created" value={formatted.createdAt} />
                        {formatted.collectedAt && <Row label="Collected" value={formatted.collectedAt} />}
                        <Row label="Processed" value={formatted.processedAt} />
                        <Row label="Verification" value={<VerificationBadge status={payment.verification_status} />} />
                    </CardBody>
                </Card>

                <Card className="animate-fade-up" style={{ animationDelay: '300ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Verification</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-3 text-sm">
                        {payment.verification ? (
                            <>
                                <div className="flex flex-col divide-y divide-slate-100">
                                    <Row label="Status" value={payment.verification.status} />
                                    <Row label="MOMO ref" value={payment.verification.momo_ref ?? '—'} />
                                    <Row label="MOMO status" value={payment.verification.momo_status ?? '—'} />
                                    <Row label="Verified by" value={payment.verification.verified_by ?? '—'} />
                                    <Row label="Verified at" value={formatted.verifiedAt} />
                                    <Row label="Notes" value={payment.verification.notes ?? '—'} />
                                </div>
                                {payment.verification.receipt_photo_url ? (
                                    <a href={payment.verification.receipt_photo_url} target="_blank" rel="noreferrer">
                                        <img
                                            src={payment.verification.receipt_photo_url}
                                            alt={`Receipt photo for ${payment.customer_name}'s payment of ${formatted.amount} — opens full size in a new tab`}
                                            className="mt-1 h-32 w-32 rounded-lg object-cover ring-1 ring-slate-200"
                                        />
                                    </a>
                                ) : (
                                    <p className="text-slate-500">No receipt photo yet.</p>
                                )}
                            </>
                        ) : (
                            <p className="text-slate-500">Not yet reviewed.</p>
                        )}

                        {payment.verification_status === 'verified' ? (
                            <p className="mt-3 border-t border-slate-200 pt-3 text-slate-500">
                                This payment is already verified — its receipt evidence is locked. Reject the payment to attach new evidence.
                            </p>
                        ) : (
                            <form onSubmit={submit} className="mt-3 flex flex-col gap-2 border-t border-slate-200 pt-3">
                                <label className="text-sm font-medium text-slate-700">Upload receipt photo</label>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/*"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        setData('receipt', file);
                                        setFileName(file?.name ?? null);
                                    }}
                                    className="cursor-pointer rounded-md text-sm text-slate-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                />
                                {errors.receipt && <p className="text-xs text-red-600">{errors.receipt}</p>}
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing || !fileName}
                                    className="self-start rounded-lg px-4 py-2.5 text-sm font-semibold"
                                >
                                    {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconUpload size={16} stroke={2} />}
                                    {processing ? 'Uploading…' : 'Upload Receipt'}
                                </Button>
                            </form>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Modal open={confirmingDelete} onClose={closeDeleteModal} title={`Delete ${payment.customer_name}'s payment?`}>
                <p className="text-sm text-slate-600">
                    This permanently removes the payment record{payment.verification ? ' and its verification details' : ''}. This cannot be
                    undone.
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
            </Modal>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2 first:pt-0 last:pb-0">
            <span className="text-slate-500">{label}</span>
            <span className="text-right font-medium text-slate-900">{value}</span>
        </div>
    );
}
