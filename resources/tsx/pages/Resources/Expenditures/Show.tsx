import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';
import {
    IconArrowLeft,
    IconBan,
    IconCheck,
    IconCircleCheck,
    IconCircleX,
    IconClockHour4,
    IconPencil,
    IconReceipt2,
    IconUpload,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Modal } from '@/components/ui/Modal';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import {
    ExpenseStatusBadge,
    FlagChip,
    PAYMENT_METHOD_OPTIONS,
    ReasonModal,
    ReceiptPicker,
    ReceiptPreview,
    TextArea,
    expenseStatusLabel,
    formatDate,
    formatDateTime,
    needsPaymentRef,
    paymentMethodLabel,
    todayIso,
} from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Expenditure, NamedRef, PageProps } from '@/types';

interface HistoryEntry {
    at: string | null;
    action: string;
    by: string | null;
    changes: Record<string, unknown>;
}

interface ExpenditureShowProps {
    expenditure: Expenditure;
    branch_name: string | null;
    history: HistoryEntry[];
    can: {
        update: boolean;
        attach_receipt: boolean;
        approve: boolean;
        reject: boolean;
        void: boolean;
    };
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
}

export default function ExpenditureShow({ expenditure, branch_name, history, can, categories, suppliers, services }: ExpenditureShowProps) {
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [modal, setModal] = useState<'reject' | 'void' | 'edit' | null>(null);
    const [isApproving, setIsApproving] = useState(false);

    const isVoid = expenditure.status === 'void';
    const base = `/resources/expenditures/${expenditure.uuid}`;
    const pageError = errors?.expenditure ?? errors?.supplier ?? errors?.template;

    function approve() {
        router.post(`${base}/approve`, {}, {
            preserveScroll: true,
            onStart: () => setIsApproving(true),
            onFinish: () => setIsApproving(false),
        });
    }

    const hasActions = can.approve || can.reject || can.update || can.void;

    return (
        <AppLayout
            title="Expense"
            breadcrumbs={[
                { label: 'Resources', href: '/resources' },
                { label: 'Expenses', href: '/resources/expenditures' },
                { label: formatCurrency(expenditure.amount) },
            ]}
        >
            <Head title={`Expense ${formatCurrency(expenditure.amount)}`} />

            <ResourcesTabs active="expenses" />

            <Link href="/resources/expenditures" className="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-800">
                <IconArrowLeft size={16} stroke={2} />
                All expenses
            </Link>

            {pageError && (
                <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{pageError}</div>
            )}

            {/* Hero */}
            <Card className="mb-4 animate-fade-up p-5 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <ExpenseStatusBadge status={expenditure.status} />
                            {expenditure.auto_approved && expenditure.status === 'approved' && (
                                <span className="text-xs font-medium text-green-700">Approved automatically</span>
                            )}
                            {expenditure.recorded_offline && <span className="text-xs text-slate-500">Recorded offline</span>}
                        </div>
                        <p
                            className={`font-display mt-2 text-4xl font-semibold tracking-tight break-words sm:text-5xl ${
                                isVoid ? 'text-slate-400 line-through' : 'text-slate-900'
                            }`}
                        >
                            {formatCurrency(expenditure.amount)}
                        </p>
                        <p className="mt-1 text-base text-slate-700">
                            {expenditure.category_name}
                            {expenditure.description ? ` — ${expenditure.description}` : ''}
                        </p>
                        <p className="mt-0.5 text-sm text-slate-500">
                            {formatDate(expenditure.spent_at)} · {paymentMethodLabel(expenditure.payment_method)}
                            {expenditure.recorded_by ? ` · by ${expenditure.recorded_by.name}` : ''}
                        </p>
                    </div>

                    {hasActions && (
                        <div className="grid grid-cols-2 gap-2 sm:flex sm:shrink-0 sm:flex-wrap sm:justify-end">
                            {can.approve && (
                                <Button onClick={approve} disabled={isApproving} className="col-span-2 min-h-[44px] bg-green-600 font-semibold shadow-green-600/20 hover:bg-green-700 sm:col-span-1">
                                    {isApproving ? <LoadingSpinner className="text-white" /> : <IconCheck size={18} stroke={2} />}
                                    Approve
                                </Button>
                            )}
                            {can.reject && (
                                <Button variant="danger" onClick={() => setModal('reject')} className="min-h-[44px]">
                                    <IconCircleX size={18} stroke={2} />
                                    Reject
                                </Button>
                            )}
                            {can.update && (
                                <Button variant="secondary" onClick={() => setModal('edit')} className="min-h-[44px]">
                                    <IconPencil size={18} stroke={1.75} />
                                    Edit
                                </Button>
                            )}
                            {can.void && (
                                <Button variant="secondary" onClick={() => setModal('void')} className="min-h-[44px] text-red-700">
                                    <IconBan size={18} stroke={1.75} />
                                    Void
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {expenditure.status === 'pending_second_approval' && expenditure.first_approved_by && (
                    <p className="mt-4 rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-800">
                        First approval by {expenditure.first_approved_by.name} — waiting for the owner's approval.
                    </p>
                )}
                {expenditure.status === 'pending_evidence' && (
                    <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Waiting for the receipt photo. Once it's added, the expense goes for approval.
                    </p>
                )}
                {expenditure.status === 'rejected' && expenditure.rejection_reason && (
                    <p className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                        <span className="font-semibold">Rejected:</span> {expenditure.rejection_reason}
                    </p>
                )}
                {isVoid && (
                    <p className="mt-4 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">
                        <span className="font-semibold">Voided:</span> {expenditure.void_reason ?? '—'} — it stays on record but no longer counts.
                    </p>
                )}

                {expenditure.flags.length > 0 && (
                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <p className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Things to check</p>
                        <div className="flex flex-wrap gap-1.5">
                            {expenditure.flags.map((flag) => (
                                <FlagChip key={flag.key} flag={flag} />
                            ))}
                        </div>
                    </div>
                )}
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="flex flex-col gap-4 lg:col-span-2">
                    <Card className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                        <CardHeader>
                            <h2 className="text-sm font-semibold text-slate-900">Details</h2>
                        </CardHeader>
                        <CardBody>
                            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                <Detail label="Category">{expenditure.category_name}</Detail>
                                <Detail label="Date spent">{formatDate(expenditure.spent_at)}</Detail>
                                <Detail label="Paid by">{paymentMethodLabel(expenditure.payment_method)}</Detail>
                                <Detail label="Transaction ID">{expenditure.payment_ref ?? '—'}</Detail>
                                <Detail label="Supplier">
                                    {expenditure.supplier ? (
                                        <>
                                            {expenditure.supplier.name}
                                            {expenditure.supplier.status === 'pending' && (
                                                <span className="ml-1 text-xs font-medium text-amber-700">(awaiting approval)</span>
                                            )}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </Detail>
                                <Detail label="Service">{expenditure.service?.name ?? 'Shared'}</Detail>
                                <Detail label="Invoice / receipt no.">{expenditure.reference_no ?? '—'}</Detail>
                                <Detail label="Branch">{branch_name ?? '—'}</Detail>
                                <Detail label="Recorded by">{expenditure.recorded_by?.name ?? '—'}</Detail>
                                <Detail label="Recorded at">{formatDateTime(expenditure.created_at)}</Detail>
                                {expenditure.recurring_period && <Detail label="Regular expense for">{expenditure.recurring_period}</Detail>}
                                <div className="sm:col-span-2">
                                    <Detail label="Description">{expenditure.description ?? '—'}</Detail>
                                </div>
                                {expenditure.notes && (
                                    <div className="sm:col-span-2">
                                        <Detail label="Notes">
                                            <span className="whitespace-pre-line">{expenditure.notes}</span>
                                        </Detail>
                                    </div>
                                )}
                            </dl>
                        </CardBody>
                    </Card>

                    <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                        <CardHeader className="flex items-center justify-between gap-2">
                            <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
                                <IconReceipt2 size={18} stroke={1.75} className="text-slate-400" />
                                Receipt
                            </h2>
                            {expenditure.receipt_url && (
                                <a href={expenditure.receipt_url} target="_blank" rel="noreferrer" className="text-sm font-medium text-blue-700 hover:text-blue-800">
                                    Open full size
                                </a>
                            )}
                        </CardHeader>
                        <CardBody>
                            {expenditure.has_receipt ? (
                                <ReceiptPreview url={expenditure.receipt_url} />
                            ) : can.attach_receipt ? (
                                <AttachReceiptForm uuid={expenditure.uuid} />
                            ) : (
                                <ReceiptPreview url={null} />
                            )}
                        </CardBody>
                    </Card>
                </div>

                <div className="flex flex-col gap-4">
                    <Card className="animate-fade-up" style={{ animationDelay: '140ms' }}>
                        <CardHeader>
                            <h2 className="text-sm font-semibold text-slate-900">Timeline</h2>
                        </CardHeader>
                        <CardBody>
                            <Timeline expenditure={expenditure} />
                        </CardBody>
                    </Card>

                    {history.length > 0 && (
                        <Card className="animate-fade-up" style={{ animationDelay: '180ms' }}>
                            <CardHeader>
                                <h2 className="text-sm font-semibold text-slate-900">Change history</h2>
                                <p className="mt-0.5 text-xs text-slate-500">From the audit log — cannot be edited.</p>
                            </CardHeader>
                            <CardBody>
                                <ol className="flex flex-col gap-3">
                                    {history.map((entry, index) => (
                                        <li key={index} className="text-sm">
                                            <p className="font-medium text-slate-800">
                                                {historyActionLabel(entry.action)}
                                                {entry.by ? <span className="font-normal text-slate-500"> · {entry.by}</span> : null}
                                            </p>
                                            <p className="text-xs text-slate-400">{formatDateTime(entry.at)}</p>
                                            {describeChanges(entry.changes).length > 0 && (
                                                <ul className="mt-1 list-disc pl-4 text-xs text-slate-600">
                                                    {describeChanges(entry.changes).map((line) => (
                                                        <li key={line}>{line}</li>
                                                    ))}
                                                </ul>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </CardBody>
                        </Card>
                    )}
                </div>
            </div>

            <ReasonModal
                open={modal === 'reject'}
                onClose={() => setModal(null)}
                title="Reject this expense"
                description="The person who recorded it will see your reason."
                url={`${base}/reject`}
                confirmLabel="Reject expense"
            />
            <ReasonModal
                open={modal === 'void'}
                onClose={() => setModal(null)}
                title="Void this expense"
                description="Voiding never deletes: the record stays in the register, marked void, but it stops counting in totals and budgets. Use it for mistakes and duplicates."
                url={`${base}/void`}
                confirmLabel="Void expense"
                variant="warning"
            />
            {can.update && (
                <Modal open={modal === 'edit'} onClose={() => setModal(null)} title="Edit expense">
                    <EditExpenseForm
                        expenditure={expenditure}
                        categories={categories}
                        suppliers={suppliers}
                        services={services}
                        onDone={() => setModal(null)}
                    />
                </Modal>
            )}
        </AppLayout>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs font-medium text-slate-500">{label}</dt>
            <dd className="mt-0.5 break-words text-slate-900">{children}</dd>
        </div>
    );
}

interface TimelineStep {
    key: string;
    title: string;
    at: string | null;
    detail?: string | null;
    tone: 'done' | 'bad' | 'muted' | 'waiting';
}

function Timeline({ expenditure }: { expenditure: Expenditure }) {
    const steps: TimelineStep[] = [
        {
            key: 'recorded',
            title: `Recorded${expenditure.recorded_by ? ` by ${expenditure.recorded_by.name}` : ''}`,
            at: expenditure.created_at,
            tone: 'done',
        },
    ];

    if (expenditure.first_approved_by) {
        steps.push({ key: 'first', title: `First approval by ${expenditure.first_approved_by.name}`, at: expenditure.first_approved_at, tone: 'done' });
    }

    if (expenditure.approved_at) {
        steps.push({
            key: 'approved',
            title: expenditure.auto_approved || !expenditure.approved_by ? 'Approved automatically' : `Approved by ${expenditure.approved_by.name}`,
            at: expenditure.approved_at,
            tone: 'done',
        });
    }

    if (expenditure.rejected_at) {
        steps.push({
            key: 'rejected',
            title: `Rejected${expenditure.rejected_by ? ` by ${expenditure.rejected_by.name}` : ''}`,
            at: expenditure.rejected_at,
            detail: expenditure.rejection_reason,
            tone: 'bad',
        });
    }

    if (expenditure.voided_at) {
        steps.push({
            key: 'voided',
            title: `Voided${expenditure.voided_by ? ` by ${expenditure.voided_by.name}` : ''}`,
            at: expenditure.voided_at,
            detail: expenditure.void_reason,
            tone: 'muted',
        });
    }

    if (expenditure.status.startsWith('pending_')) {
        steps.push({ key: 'waiting', title: expenseStatusLabel(expenditure.status), at: null, tone: 'waiting' });
    }

    return (
        <ol className="relative flex flex-col gap-4 border-l border-slate-200 pl-5">
            {steps.map((step) => (
                <li key={step.key} className="relative">
                    <span
                        className={`absolute top-0.5 -left-[29px] flex h-4.5 w-4.5 items-center justify-center rounded-full bg-white ${
                            step.tone === 'done'
                                ? 'text-green-600'
                                : step.tone === 'bad'
                                  ? 'text-red-600'
                                  : step.tone === 'waiting'
                                    ? 'text-amber-500'
                                    : 'text-slate-400'
                        }`}
                    >
                        {step.tone === 'done' && <IconCircleCheck size={18} stroke={2} />}
                        {step.tone === 'bad' && <IconCircleX size={18} stroke={2} />}
                        {step.tone === 'muted' && <IconBan size={18} stroke={2} />}
                        {step.tone === 'waiting' && <IconClockHour4 size={18} stroke={2} />}
                    </span>
                    <p className="text-sm font-medium text-slate-800">{step.title}</p>
                    {step.at && <p className="text-xs text-slate-400">{formatDateTime(step.at)}</p>}
                    {step.detail && <p className="mt-1 text-sm text-slate-600">“{step.detail}”</p>}
                </li>
            ))}
        </ol>
    );
}

function historyActionLabel(action: string): string {
    const labels: Record<string, string> = { created: 'Created', updated: 'Changed', deleted: 'Deleted' };

    return labels[action] ?? action.charAt(0).toUpperCase() + action.slice(1).replace(/_/g, ' ');
}

/** Plain-language lines for the audit log's changed fields. */
function describeChanges(changes: Record<string, unknown>): string[] {
    const lines: string[] = [];

    for (const [field, value] of Object.entries(changes ?? {})) {
        switch (field) {
            case 'status':
                lines.push(`Status: ${expenseStatusLabel(String(value))}`);
                break;
            case 'amount':
                lines.push(`Amount: ${formatCurrency(String(value))}`);
                break;
            case 'spent_at':
                lines.push(`Date: ${formatDate(String(value).slice(0, 10))}`);
                break;
            case 'payment_method':
                lines.push(`Paid by: ${paymentMethodLabel(value as string | null)}`);
                break;
            case 'payment_ref':
                lines.push(`Transaction ID: ${value ?? '—'}`);
                break;
            case 'category_id':
                lines.push('Category changed');
                break;
            case 'supplier_id':
                lines.push(value ? 'Supplier changed' : 'Supplier removed');
                break;
            case 'receipt_path':
                lines.push(value ? 'Receipt added' : 'Receipt removed');
                break;
            case 'rejection_reason':
                if (value) {
                    lines.push(`Reason: ${value}`);
                }
                break;
            case 'void_reason':
                if (value) {
                    lines.push(`Void reason: ${value}`);
                }
                break;
        }
    }

    return lines;
}

function AttachReceiptForm({ uuid }: { uuid: string }) {
    const { data, setData, post, processing, errors, setError, clearErrors } = useForm<{ receipt: File | null }>({ receipt: null });

    function choose(file: File | null) {
        clearErrors('receipt');

        if (file && file.size > 6 * 1024 * 1024) {
            setError('receipt', 'The receipt must be 6 MB or smaller.');

            return;
        }

        setData('receipt', file);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/resources/expenditures/${uuid}/receipt`, { forceFormData: true, preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <ReceiptPicker file={data.receipt} onChange={choose} error={errors.receipt} required />
            <Button type="submit" disabled={processing || !data.receipt} className="min-h-[44px] self-stretch font-semibold sm:self-start">
                {processing ? <LoadingSpinner className="text-white" /> : <IconUpload size={18} stroke={1.75} />}
                Add receipt
            </Button>
        </form>
    );
}

interface EditForm {
    category_uuid: string;
    amount: string;
    spent_at: string;
    description: string;
    notes: string;
    payment_method: string;
    payment_ref: string;
    supplier_uuid: string;
    service_uuid: string;
    reference_no: string;
}

function EditExpenseForm({
    expenditure,
    categories,
    suppliers,
    services,
    onDone,
}: {
    expenditure: Expenditure;
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
    onDone: () => void;
}) {
    const { data, setData, patch, processing, errors } = useForm<EditForm>({
        category_uuid: expenditure.category_uuid,
        amount: String(Number(expenditure.amount)),
        spent_at: expenditure.spent_at,
        description: expenditure.description ?? '',
        notes: expenditure.notes ?? '',
        payment_method: expenditure.payment_method ?? '',
        payment_ref: expenditure.payment_ref ?? '',
        supplier_uuid: expenditure.supplier?.uuid ?? '',
        service_uuid: expenditure.service?.uuid ?? '',
        reference_no: expenditure.reference_no ?? '',
    });
    const pageErrors = errors as Record<string, string | undefined>;

    // Keep a supplier that is no longer in the active list selectable.
    const supplierOptions =
        expenditure.supplier && !suppliers.some((supplier) => supplier.uuid === expenditure.supplier?.uuid)
            ? [...suppliers, { uuid: expenditure.supplier.uuid, name: expenditure.supplier.name }]
            : suppliers;

    function submit(e: FormEvent) {
        e.preventDefault();
        patch(`/resources/expenditures/${expenditure.uuid}`, { preserveScroll: true, onSuccess: () => onDone() });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <p className="text-sm text-slate-500">Saving re-checks the expense, so it may need approval again.</p>
            {pageErrors.expenditure && <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{pageErrors.expenditure}</p>}
            <TextInput
                id="edit_amount"
                label="Amount (FCFA)"
                type="number"
                inputMode="decimal"
                step="0.01"
                min="0"
                required
                value={data.amount}
                onChange={(e) => setData('amount', e.target.value)}
                error={errors.amount}
            />
            <SelectInput id="edit_category" label="Category" required value={data.category_uuid} onChange={(e) => setData('category_uuid', e.target.value)} error={errors.category_uuid}>
                {categories.map((category) => (
                    <option key={category.uuid} value={category.uuid}>
                        {category.name}
                    </option>
                ))}
            </SelectInput>
            <TextInput
                id="edit_spent_at"
                label="Date"
                type="date"
                required
                max={todayIso()}
                value={data.spent_at}
                onChange={(e) => setData('spent_at', e.target.value)}
                error={errors.spent_at}
            />
            <TextInput id="edit_description" label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} />
            <SelectInput id="edit_payment_method" label="Paid by" value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} error={errors.payment_method}>
                <option value="">Not stated</option>
                {PAYMENT_METHOD_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </SelectInput>
            {(needsPaymentRef(data.payment_method) || data.payment_method === 'bank') && (
                <TextInput
                    id="edit_payment_ref"
                    label="Transaction ID"
                    required={needsPaymentRef(data.payment_method)}
                    value={data.payment_ref}
                    onChange={(e) => setData('payment_ref', e.target.value)}
                    error={errors.payment_ref}
                />
            )}
            <SelectInput id="edit_supplier" label="Supplier" value={data.supplier_uuid} onChange={(e) => setData('supplier_uuid', e.target.value)} error={errors.supplier_uuid}>
                <option value="">No supplier</option>
                {supplierOptions.map((supplier) => (
                    <option key={supplier.uuid} value={supplier.uuid}>
                        {supplier.name}
                    </option>
                ))}
            </SelectInput>
            <SelectInput id="edit_service" label="Service" value={data.service_uuid} onChange={(e) => setData('service_uuid', e.target.value)} error={errors.service_uuid}>
                <option value="">Shared</option>
                {services.map((service) => (
                    <option key={service.uuid} value={service.uuid}>
                        {service.name}
                    </option>
                ))}
            </SelectInput>
            <TextInput id="edit_reference_no" label="Invoice / receipt no." value={data.reference_no} onChange={(e) => setData('reference_no', e.target.value)} error={errors.reference_no} />
            <TextArea id="edit_notes" label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} />
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="secondary" onClick={onDone} className="w-full sm:w-auto">
                    Cancel
                </Button>
                <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                    {processing && <LoadingSpinner className="text-white" />}
                    Save changes
                </Button>
            </div>
        </form>
    );
}
