import { FormEvent, ReactNode, useMemo, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { IconArrowLeft, IconBrandWhatsapp, IconCash, IconDownload, IconMail, IconPrinter, IconReceipt, IconUpload, IconWallet } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { StatCard } from '@/components/ui/StatCard';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { ArrearsAdjustmentModal } from '@/components/customers/ArrearsAdjustmentModal';
import { ReversePaymentModal } from '@/components/shared/ReversePaymentModal';
import { CoverageNote } from '@/components/shared/CoverageNote';
import { CompensationModal } from '@/components/customers/FreeMonthsCard';
import { formatCurrency } from '@/lib/formatCurrency';
import { paymentMethodLabel } from '@/components/shared/PaymentMethodFields';
import type { CustomerCoverage, CustomerFreeMonths, PageProps, Payment, PaymentReceipt } from '@/types';

function formatRelative(iso: string): string {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return iso;
    }
    const seconds = Math.round((Date.now() - then) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
    return new Date(iso).toLocaleDateString();
}

interface PaymentsShowProps {
    payment: Payment;
    receipt: PaymentReceipt | null;
    can_manage: boolean;
    can_delete: boolean;
    can_issue_receipt: boolean;
    can_reverse: boolean;
    /** The customer's months (free → paid → billed) and the "Give free months" dialog's options. */
    coverage?: CustomerCoverage | null;
    free_months?: CustomerFreeMonths | null;
}

const frequencyLabels: Record<Payment['frequency'], string> = {
    monthly: 'Monthly',
    months: 'Multi-month',
    yearly: 'Yearly',
};

export default function PaymentsShow({ payment, receipt, can_manage, can_delete, can_issue_receipt, can_reverse, coverage, free_months }: PaymentsShowProps) {
    const [givingFreeMonths, setGivingFreeMonths] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [reversing, setReversing] = useState(false);
    const [fileName, setFileName] = useState<string | null>(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [destroying, setDestroying] = useState(false);
    const [issuingReceipt, setIssuingReceipt] = useState(false);
    const [sendingWhatsapp, setSendingWhatsapp] = useState(false);
    const [sendingEmail, setSendingEmail] = useState(false);

    // A receipt is only a record of a verified payment. Auto-issued on
    // verify(); this manual action covers payments recorded before receipts
    // shipped and re-issuing a voided one (server re-checks the gate + status).
    const isVerified = payment.verification_status === 'verified';
    const receiptVoided = receipt?.status === 'void';
    const canIssueNow = can_issue_receipt && isVerified && (!receipt || receiptVoided);
    const canSendWhatsapp = !!receipt && !receiptVoided && !!payment.customer_phone;

    // The wa.me link is minted server-side (from the frozen snapshot + a
    // fresh signed PDF URL) and flashed back on the POST — mirror the bill
    // "Send Bill" flow: record the send, then let the browser open WhatsApp
    // itself in a new tab.
    function sendWhatsapp() {
        router.post(
            `/payments/${payment.uuid}/receipt/send-whatsapp`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSendingWhatsapp(true),
                onFinish: () => setSendingWhatsapp(false),
                onSuccess: (page) => {
                    const url = (page.props as unknown as PageProps).flash?.whatsapp_url;
                    if (url) {
                        window.open(url, '_blank', 'noopener,noreferrer');
                    }
                },
            },
        );
    }

    function sendEmail() {
        router.post(
            `/payments/${payment.uuid}/receipt/send-email`,
            {},
            {
                preserveScroll: true,
                onStart: () => setSendingEmail(true),
                onFinish: () => setSendingEmail(false),
            },
        );
    }

    function issueReceipt() {
        router.post(
            `/payments/${payment.uuid}/receipt/issue`,
            {},
            {
                preserveScroll: true,
                onStart: () => setIssuingReceipt(true),
                onFinish: () => setIssuingReceipt(false),
            },
        );
    }

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
                    <div className="flex flex-wrap items-center gap-2">
                        <ArrearsAdjustmentModal customer={arrearsCustomer} />
                        {free_months?.can_request && (
                            <Button type="button" variant="secondary" onClick={() => setGivingFreeMonths(true)} className="rounded-lg px-4 py-2 text-sm font-semibold">
                                Give free months
                            </Button>
                        )}
                        {can_manage && (
                            <Link
                                href={`/payments/${payment.uuid}/edit`}
                                className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                            >
                                Edit Payment
                            </Link>
                        )}
                        {can_reverse && (
                            <Button type="button" variant="secondary" onClick={() => setReversing(true)} className="rounded-lg px-4 py-2 text-sm font-semibold">
                                Reverse Payment
                            </Button>
                        )}
                        {can_delete && !isVerified && !receipt && !payment.handed_in && (
                            <Button type="button" variant="danger" onClick={() => setConfirmingDelete(true)} className="rounded-lg px-4 py-2 text-sm font-semibold">
                                Delete Payment
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            {payment.reversed_at && (
                <div role="status" className="mb-4 max-w-3xl rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <p className="font-semibold">Reversed on {new Date(payment.reversed_at).toLocaleString()} — its receipt is void.</p>
                    {payment.reversal_reason && <p className="mt-1">Reason: {payment.reversal_reason}</p>}
                </div>
            )}

            {payment.possible_duplicate && (
                // Compare with the first payment this month before approving or rejecting.
                <div role="alert" className="mb-4 max-w-3xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">Possible duplicate — the customer already paid this month.</p>
                    {payment.duplicate_of && (
                        <p className="mt-1">
                            Earlier payment:{' '}
                            <Link href={`/payments/${payment.duplicate_of.uuid}`} className="font-semibold underline underline-offset-2">
                                {formatCurrency(Number(payment.duplicate_of.amount))}
                                {payment.duplicate_of.collected_at && ` on ${new Date(payment.duplicate_of.collected_at).toLocaleDateString()}`}
                            </Link>
                            {payment.duplicate_of.receipt_number && ` · receipt ${payment.duplicate_of.receipt_number}`}
                            {payment.duplicate_of.recorded_by_name && ` · recorded by ${payment.duplicate_of.recorded_by_name}`} · {payment.duplicate_of.verification_status}
                        </p>
                    )}
                    <p className="mt-1">
                        Reason given: <strong>{payment.duplicate_reason ?? '—'}</strong>
                        {payment.duplicate_note && ` — “${payment.duplicate_note}”`}
                    </p>
                    <p className="mt-2 text-xs text-amber-800">It can only be approved by someone in the office other than the person who recorded it.</p>
                </div>
            )}

            {(payment.purpose ?? 'subscription') === 'subscription' && (
                <div className="mb-4 max-w-3xl">
                    <CoverageNote coverage={coverage} />
                </div>
            )}

            {free_months && givingFreeMonths && (
                <CompensationModal open onClose={() => setGivingFreeMonths(false)} customerUuid={payment.customer_uuid} freeMonths={free_months} />
            )}

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
                        <Row label="Paid by" value={paymentMethodLabel(payment.method)} />
                        {payment.method_reference && <Row label="Transaction ID" value={payment.method_reference} />}
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

                <Card className="animate-fade-up" style={{ animationDelay: '400ms' }}>
                    <CardHeader>
                        <h2 className="text-sm font-semibold text-slate-900">Receipt</h2>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-3 text-sm">
                        {receipt ? (
                            <>
                                <div className="flex flex-col divide-y divide-slate-100">
                                    <Row label="Receipt no." value={receipt.receipt_number} />
                                    <Row
                                        label="Issued"
                                        value={receipt.issued_at ? new Date(receipt.issued_at).toLocaleString() : '—'}
                                    />
                                    <Row label="Amount" value={formatCurrency(receipt.amount)} />
                                    <Row
                                        label="Status"
                                        value={
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                    receipt.status === 'void'
                                                        ? 'bg-red-100 text-red-700'
                                                        : 'bg-green-100 text-green-700'
                                                }`}
                                            >
                                                {receipt.status === 'void' ? 'Void' : 'Issued'}
                                            </span>
                                        }
                                    />
                                </div>

                                {receipt.status === 'void' && (
                                    <p className="text-slate-500">
                                        This receipt was voided (the payment was rejected). Re-verify the payment, or
                                        re-issue below.
                                    </p>
                                )}

                                <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-3">
                                    <a
                                        href={receipt.download_url}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                                    >
                                        <IconDownload size={16} stroke={2} />
                                        Download PDF
                                    </a>
                                    {/* Roll formats for pocket Bluetooth printers. Every print after the
                                        first is stamped DUPLICATE on the receipt itself. */}
                                    {(['thermal58', 'thermal80'] as const).map((format) => (
                                        <a
                                            key={format}
                                            href={`${receipt.download_url}?format=${format}`}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50"
                                        >
                                            <IconPrinter size={16} stroke={2} />
                                            {format === 'thermal58' ? '58 mm roll' : '80 mm roll'}
                                        </a>
                                    ))}
                                    {canIssueNow && (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={issueReceipt}
                                            disabled={issuingReceipt}
                                            className="rounded-lg px-4 py-2 text-sm font-semibold"
                                        >
                                            {issuingReceipt && <LoadingSpinner className="h-4 w-4" />}
                                            Re-issue
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={sendWhatsapp}
                                        disabled={!canSendWhatsapp || sendingWhatsapp}
                                        title={
                                            canSendWhatsapp
                                                ? undefined
                                                : "This customer has no phone on file — the receipt can't be sent by WhatsApp."
                                        }
                                        className="rounded-lg px-4 py-2 text-sm font-semibold"
                                    >
                                        {sendingWhatsapp ? (
                                            <LoadingSpinner className="h-4 w-4" />
                                        ) : (
                                            <IconBrandWhatsapp size={16} stroke={2} />
                                        )}
                                        Send via WhatsApp
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={sendEmail}
                                        disabled={receipt.email_unavailable_reason !== null || sendingEmail}
                                        title={receipt.email_unavailable_reason ?? `Email the PDF to ${receipt.email_to}`}
                                        className="rounded-lg px-4 py-2 text-sm font-semibold"
                                    >
                                        {sendingEmail ? <LoadingSpinner className="h-4 w-4" /> : <IconMail size={16} stroke={2} />}
                                        Email receipt
                                    </Button>
                                </div>

                                {receipt.last_sent_at && (
                                    <p className="text-xs text-slate-500">
                                        Last sent {formatRelative(receipt.last_sent_at)}
                                        {receipt.last_sent_channel === 'email' ? ' by email' : receipt.last_sent_channel ? ' by WhatsApp' : ''}
                                        {receipt.sent_count > 1 ? ` (${receipt.sent_count} times in all)` : ''}.
                                    </p>
                                )}

                                {receipt.email_unavailable_reason && receipt.status !== 'void' && (
                                    <p className="text-xs text-slate-500">Email: {receipt.email_unavailable_reason}</p>
                                )}

                                {!payment.customer_phone && (
                                    <p className="text-xs text-slate-500">
                                        This customer has no phone on file — the receipt can't be sent by WhatsApp.
                                    </p>
                                )}
                            </>
                        ) : (
                            <>
                                <p className="flex items-center gap-1.5 text-slate-500">
                                    <IconReceipt size={16} stroke={1.75} />
                                    {isVerified
                                        ? 'No receipt issued yet.'
                                        : 'A receipt is issued automatically once this payment is verified.'}
                                </p>
                                {canIssueNow && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={issueReceipt}
                                        disabled={issuingReceipt}
                                        className="self-start rounded-lg px-4 py-2.5 text-sm font-semibold"
                                    >
                                        {issuingReceipt ? <LoadingSpinner className="h-4 w-4" /> : <IconReceipt size={16} stroke={2} />}
                                        Issue receipt
                                    </Button>
                                )}
                            </>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Modal open={confirmingDelete} onClose={closeDeleteModal} title={`Delete ${payment.customer_name}'s payment?`}>
                <p className="text-sm text-slate-600">
                    This permanently removes the payment record{payment.verification ? ' and its verification details' : ''}. This cannot be
                    undone.
                </p>
                <div className="mt-6 flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={closeDeleteModal} disabled={destroying} className="w-full sm:w-auto">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="danger"
                        onClick={submitDelete}
                        disabled={destroying}
                        className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                    >
                        {destroying && <LoadingSpinner className="h-4 w-4" />}
                        {destroying ? 'Deleting…' : 'Delete'}
                    </Button>
                </div>
            </Modal>

            <ReversePaymentModal open={reversing} onClose={() => setReversing(false)} payment={payment} freeMonths={free_months} />
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
