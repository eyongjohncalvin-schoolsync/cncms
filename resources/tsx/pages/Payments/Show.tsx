import { FormEvent, ReactNode, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Payment } from '@/types';

interface PaymentsShowProps {
    payment: Payment;
}

const frequencyLabels: Record<Payment['frequency'], string> = {
    monthly: 'Monthly',
    months: 'Multi-month',
    yearly: 'Yearly',
};

export default function PaymentsShow({ payment }: PaymentsShowProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{ receipt: File | null }>({
        receipt: null,
    });

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
        <AppLayout title="Payment Detail">
            <Head title="Payment Detail" />

            <div className="mb-4">
                <Link href="/payments" className="text-sm font-medium text-blue-600 hover:text-blue-700">
                    &larr; Back to Payments
                </Link>
            </div>

            <div className="grid max-w-3xl grid-cols-1 gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Payment</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-2 text-sm">
                        <Row label="Customer" value={payment.customer_name} />
                        <Row label="Zone" value={payment.zone_name ?? '—'} />
                        <Row label="Amount" value={formatCurrency(payment.amount)} />
                        <Row label="Credit" value={formatCurrency(payment.credit)} />
                        <Row label="Frequency" value={frequencyLabels[payment.frequency]} />
                        {payment.months !== null && <Row label="Months" value={String(payment.months)} />}
                        <Row label="Expiration" value={payment.expiration_date ?? '—'} />
                        <Row label="Recorded" value={payment.recorded_offline ? 'Offline (field agent)' : 'Office'} />
                        {payment.recorded_by_device && <Row label="Device" value={payment.recorded_by_device} />}
                        <Row label="Created" value={new Date(payment.created_at).toLocaleString()} />
                        <Row label="Processed" value={payment.processed_at ? new Date(payment.processed_at).toLocaleString() : '—'} />
                        <Row label="Verification" value={<VerificationBadge status={payment.verification_status} />} />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Verification</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-2 text-sm">
                        {payment.verification ? (
                            <>
                                <Row label="Status" value={payment.verification.status} />
                                <Row label="MOMO ref" value={payment.verification.momo_ref ?? '—'} />
                                <Row label="MOMO status" value={payment.verification.momo_status ?? '—'} />
                                <Row label="Verified by" value={payment.verification.verified_by ?? '—'} />
                                <Row
                                    label="Verified at"
                                    value={payment.verification.verified_at ? new Date(payment.verification.verified_at).toLocaleString() : '—'}
                                />
                                <Row label="Notes" value={payment.verification.notes ?? '—'} />
                                {payment.verification.receipt_photo_url ? (
                                    <a href={payment.verification.receipt_photo_url} target="_blank" rel="noreferrer">
                                        <img
                                            src={payment.verification.receipt_photo_url}
                                            alt="Receipt"
                                            className="mt-1 h-32 w-32 rounded-md object-cover ring-1 ring-slate-200"
                                        />
                                    </a>
                                ) : (
                                    <p className="text-slate-500">No receipt photo yet.</p>
                                )}
                            </>
                        ) : (
                            <p className="text-slate-500">Not yet reviewed.</p>
                        )}

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
                                className="text-sm text-slate-600"
                            />
                            {errors.receipt && <p className="text-xs text-red-600">{errors.receipt}</p>}
                            <Button type="submit" variant="secondary" disabled={processing || !fileName} className="self-start">
                                {processing ? 'Uploading…' : 'Upload Receipt'}
                            </Button>
                        </form>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-slate-500">{label}</span>
            <span className="font-medium text-slate-900">{value}</span>
        </div>
    );
}
