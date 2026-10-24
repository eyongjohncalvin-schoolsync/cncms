import { router } from '@inertiajs/react';
import { ChangeEvent, DragEvent, FormEvent, TextareaHTMLAttributes, forwardRef, useEffect, useMemo, useRef, useState } from 'react';
import { IconAlertOctagon, IconAlertTriangle, IconCamera, IconFileTypePdf, IconPhotoOff, IconX } from '@tabler/icons-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { ExpenditureFlag, ExpenditureStatus, ExpensePaymentMethod } from '@/types';

/**
 * Shared building blocks for the Resources (expenses) pages: labels for
 * statuses and payment methods, the anomaly-flag chips, the receipt
 * preview and the "give a reason" modal used by Reject and Void.
 */

export const PAYMENT_METHOD_OPTIONS: { value: ExpensePaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'mtn_momo', label: 'MTN MoMo' },
    { value: 'orange_money', label: 'Orange Money' },
    { value: 'bank', label: 'Bank' },
];

export function paymentMethodLabel(method: string | null | undefined): string {
    if (!method) {
        return '—';
    }

    return PAYMENT_METHOD_OPTIONS.find((option) => option.value === method)?.label ?? method;
}

/** MoMo and Orange Money payments need their transaction reference. */
export function needsPaymentRef(method: string | null | undefined): boolean {
    return method === 'mtn_momo' || method === 'orange_money';
}

const STATUS_META: Record<ExpenditureStatus, { label: string; tone: 'slate' | 'green' | 'yellow' | 'red' | 'blue' }> = {
    pending_evidence: { label: 'Needs receipt', tone: 'yellow' },
    pending_approval: { label: 'Waiting approval', tone: 'blue' },
    pending_second_approval: { label: 'Owner approval', tone: 'blue' },
    approved: { label: 'Approved', tone: 'green' },
    rejected: { label: 'Rejected', tone: 'red' },
    void: { label: 'Void', tone: 'slate' },
};

export function expenseStatusLabel(status: string): string {
    return STATUS_META[status as ExpenditureStatus]?.label ?? status;
}

export function isPendingStatus(status: string): boolean {
    return status.startsWith('pending_');
}

export function ExpenseStatusBadge({ status }: { status: string }) {
    const meta = STATUS_META[status as ExpenditureStatus] ?? { label: status, tone: 'slate' as const };

    return <Badge tone={meta.tone}>{meta.label}</Badge>;
}

/** Flags that point at possible fraud rather than a missing detail. */
const SERIOUS_FLAGS = ['duplicate_receipt', 'duplicate_entry', 'split', 'over_budget'];

export function FlagChip({ flag }: { flag: ExpenditureFlag }) {
    const serious = SERIOUS_FLAGS.includes(flag.key);

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
                serious ? 'bg-red-50 text-red-800 ring-red-600/30' : 'bg-amber-50 text-amber-800 ring-amber-600/30'
            }`}
        >
            <IconAlertTriangle size={12} stroke={2} aria-hidden="true" />
            {flag.label}
        </span>
    );
}

/** Up to `max` flag chips plus a "+n" counter for the rest. */
export function FlagChips({ flags, max }: { flags: ExpenditureFlag[]; max?: number }) {
    if (flags.length === 0) {
        return null;
    }

    const shown = max === undefined ? flags : flags.slice(0, max);
    const hidden = flags.length - shown.length;

    return (
        <div className="flex flex-wrap gap-1">
            {shown.map((flag) => (
                <FlagChip key={flag.key} flag={flag} />
            ))}
            {hidden > 0 && (
                <span
                    className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                    title={flags.slice(shown.length).map((flag) => flag.label).join(', ')}
                >
                    +{hidden}
                </span>
            )}
        </div>
    );
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** Today's date as YYYY-MM-DD in the browser's local time zone. */
export function todayIso(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/**
 * The receipt image (or a PDF link). The receipt route doesn't say its
 * type up front, so we try it as an image and fall back to a link.
 */
export function ReceiptPreview({ url, compact = false }: { url: string | null; compact?: boolean }) {
    const [isNotImage, setIsNotImage] = useState(false);

    if (!url) {
        return (
            <div
                className={`flex items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-400 ${
                    compact ? 'h-20 w-20' : 'h-40 w-full'
                }`}
            >
                <IconPhotoOff size={compact ? 22 : 28} stroke={1.5} aria-label="No receipt" />
                {!compact && 'No receipt yet'}
            </div>
        );
    }

    if (isNotImage) {
        return (
            <a
                href={url}
                target="_blank"
                rel="noreferrer"
                className={`flex flex-col items-center justify-center gap-1 rounded-lg border border-slate-200 bg-slate-50 text-sm font-medium text-blue-700 hover:bg-blue-50 ${
                    compact ? 'h-20 w-20 text-xs' : 'h-40 w-full'
                }`}
            >
                <IconFileTypePdf size={compact ? 24 : 32} stroke={1.5} />
                Open receipt
            </a>
        );
    }

    return (
        <a href={url} target="_blank" rel="noreferrer" className="block shrink-0" title="Open the receipt">
            <img
                src={url}
                alt="Receipt"
                loading="lazy"
                onError={() => setIsNotImage(true)}
                className={`rounded-lg border border-slate-200 bg-slate-50 object-cover ${compact ? 'h-20 w-20' : 'max-h-96 w-full object-contain'}`}
            />
        </a>
    );
}

interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    label?: string;
    error?: string;
}

export const TextArea = forwardRef<HTMLTextAreaElement, TextAreaProps>(({ label, error, className = '', id, ...props }, ref) => (
    <div className="flex flex-col gap-1.5">
        {label && (
            <label htmlFor={id} className="text-sm font-medium text-slate-700">
                {label}
                {props.required && (
                    <span className="ml-0.5 text-red-500" aria-hidden="true">
                        *
                    </span>
                )}
            </label>
        )}
        <textarea
            ref={ref}
            id={id}
            rows={3}
            className={`w-full rounded-lg border-0 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 hover:ring-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:py-2 sm:text-sm ${
                error ? 'ring-red-400 hover:ring-red-400 focus:ring-red-500' : ''
            } ${className}`}
            {...props}
        />
        {error && <p className="text-xs text-red-600">{error}</p>}
    </div>
));

TextArea.displayName = 'TextArea';

/**
 * Reject / Void: both need a written reason, posted as `{ reason }`.
 */
export function ReasonModal({
    open,
    onClose,
    title,
    description,
    url,
    confirmLabel,
    variant = 'danger',
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    url: string;
    confirmLabel: string;
    variant?: 'danger' | 'warning';
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    function close() {
        setReason('');
        setError(undefined);
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        router.post(
            url,
            { reason },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => close(),
                onError: (errors) => setError(errors.reason ?? errors.expenditure ?? Object.values(errors)[0]),
            },
        );
    }

    return (
        <Modal open={open} onClose={close} title={title}>
            <form onSubmit={submit} className="flex flex-col gap-4">
                {description && <p className="text-sm text-slate-600">{description}</p>}
                <TextArea
                    id="decision_reason"
                    label="Reason"
                    required
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="Explain why, so the person who recorded it understands."
                    error={error}
                    autoFocus
                />
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={close} className="w-full sm:w-auto">
                        Cancel
                    </Button>
                    <Button type="submit" variant={variant} disabled={processing || reason.trim() === ''} className="w-full sm:w-auto">
                        {processing && <LoadingSpinner className="text-white" />}
                        {confirmLabel}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export const MAX_RECEIPT_BYTES = 6 * 1024 * 1024;
const RECEIPT_ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,.heic,application/pdf';

/**
 * Tap to take/choose a photo (phones open the camera), or drop a file on
 * desktop. Shows a preview of images and the name of a PDF.
 */
export function ReceiptPicker({
    file,
    onChange,
    error,
    required = false,
    label = 'Receipt photo',
}: {
    file: File | null;
    onChange: (file: File | null) => void;
    error?: string;
    required?: boolean;
    label?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const previewUrl = useMemo(() => (file && file.type.startsWith('image/') ? URL.createObjectURL(file) : null), [file]);

    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl]);

    function handleInput(e: ChangeEvent<HTMLInputElement>) {
        onChange(e.target.files?.[0] ?? null);
        e.target.value = '';
    }

    function handleDrop(e: DragEvent<HTMLDivElement>) {
        e.preventDefault();
        setIsDragging(false);
        onChange(e.dataTransfer.files?.[0] ?? null);
    }

    return (
        <div>
            <p className="text-sm font-medium text-slate-700">
                {label}
                {required ? <span className="ml-1 text-xs font-semibold text-amber-700">required for this amount</span> : <span className="ml-1 text-xs text-slate-400">(optional)</span>}
            </p>

            {file ? (
                <div className="mt-2 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    {previewUrl ? (
                        <img src={previewUrl} alt="Receipt preview" className="h-20 w-20 shrink-0 rounded-md object-cover" />
                    ) : (
                        <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-md bg-white text-red-600 ring-1 ring-slate-200">
                            <IconFileTypePdf size={32} stroke={1.5} />
                        </div>
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-slate-900">{file.name}</p>
                        <p className="text-xs text-slate-500">{(file.size / 1024 / 1024).toFixed(1)} MB</p>
                        <button type="button" onClick={() => inputRef.current?.click()} className="mt-1 text-xs font-medium text-blue-700">
                            Change
                        </button>
                    </div>
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        aria-label="Remove receipt"
                        className="rounded-md p-2 text-slate-400 hover:bg-white hover:text-slate-600"
                    >
                        <IconX size={18} stroke={2} />
                    </button>
                </div>
            ) : (
                <div
                    role="button"
                    tabIndex={0}
                    onClick={() => inputRef.current?.click()}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            inputRef.current?.click();
                        }
                    }}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setIsDragging(true);
                    }}
                    onDragLeave={() => setIsDragging(false)}
                    onDrop={handleDrop}
                    className={`mt-2 flex min-h-[96px] cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-5 text-center transition-colors ${
                        isDragging
                            ? 'border-blue-400 bg-blue-50'
                            : required
                              ? 'border-amber-300 bg-amber-50/50 hover:bg-amber-50'
                              : 'border-slate-300 bg-slate-50/50 hover:bg-slate-50'
                    }`}
                >
                    <IconCamera size={28} stroke={1.5} className={required ? 'text-amber-600' : 'text-slate-400'} />
                    <p className="text-sm font-medium text-slate-700">Take a photo or choose a file</p>
                    <p className="text-xs text-slate-400">JPG, PNG, WEBP, HEIC or PDF · up to 6 MB</p>
                </div>
            )}

            <input ref={inputRef} type="file" accept={RECEIPT_ACCEPT} className="hidden" onChange={handleInput} />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Red warning when a supplier's phone or MoMo number is a staff member's. */
export function StaffMatchWarning({ matches }: { matches: string[] }) {
    return (
        <p className="mt-1.5 flex items-start gap-1.5 rounded-md bg-red-50 px-2 py-1.5 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-200">
            <IconAlertOctagon size={15} stroke={2} className="mt-px shrink-0" />
            This number belongs to staff: {matches.join(', ')}
        </p>
    );
}
