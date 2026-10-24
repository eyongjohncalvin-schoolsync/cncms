import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { IconAlertTriangle, IconCheck, IconInfoCircle, IconRepeat, IconShieldCheck } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { MAX_RECEIPT_BYTES, PAYMENT_METHOD_OPTIONS, ReceiptPicker, TextArea, needsPaymentRef, todayIso } from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Expenditure, ExpenseLimits, NamedRef, SupplierStatus } from '@/types';

interface ExpendituresCreateProps {
    categories: NamedRef[];
    suppliers: (NamedRef & { status: SupplierStatus })[];
    services: NamedRef[];
    limits: ExpenseLimits;
    last: Expenditure | null;
}

interface ExpenseForm {
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
    receipt: File | null;
    receipt_source: string;
}

export default function ExpendituresCreate({ categories, suppliers, services, limits, last }: ExpendituresCreateProps) {
    const today = todayIso();
    const { data, setData, post, processing, errors, transform, setError, clearErrors } = useForm<ExpenseForm>({
        category_uuid: '',
        amount: '',
        spent_at: today,
        description: '',
        notes: '',
        payment_method: 'cash',
        payment_ref: '',
        supplier_uuid: '',
        service_uuid: '',
        reference_no: '',
        receipt: null,
        receipt_source: 'upload',
    });

    const [showMore, setShowMore] = useState(false);
    const amountRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        amountRef.current?.focus();
    }, []);

    const amount = Number(data.amount) || 0;
    const receiptFrom = Number(limits.receipt_required_from);
    const autoBelow = Number(limits.auto_approve_below);
    const secondAbove = Number(limits.second_approval_above);
    const supplierFrom = Number(limits.supplier_required_from);

    const needsReceipt = amount > 0 && amount >= receiptFrom;
    const needsSupplier = amount > 0 && amount >= supplierFrom;
    const needsManager = amount > 0 && amount >= autoBelow;
    const needsOwner = amount > 0 && amount > secondAbove;

    const daysBack = useMemo(() => {
        const spent = new Date(`${data.spent_at}T00:00:00`).getTime();
        const now = new Date(`${today}T00:00:00`).getTime();

        return Number.isNaN(spent) ? 0 : Math.round((now - spent) / 86_400_000);
    }, [data.spent_at, today]);

    const selectedSupplier = suppliers.find((supplier) => supplier.uuid === data.supplier_uuid);

    function repeatLast() {
        if (!last) {
            return;
        }

        setData((current) => ({
            ...current,
            category_uuid: categories.some((category) => category.uuid === last.category_uuid) ? last.category_uuid : current.category_uuid,
            amount: String(Number(last.amount)),
            description: last.description ?? '',
            payment_method: last.payment_method ?? 'cash',
            payment_ref: '',
            supplier_uuid: last.supplier && suppliers.some((supplier) => supplier.uuid === last.supplier?.uuid) ? last.supplier.uuid : '',
            service_uuid: last.service?.uuid ?? '',
        }));
    }

    function chooseReceipt(file: File | null | undefined) {
        clearErrors('receipt');

        if (!file) {
            setData('receipt', null);

            return;
        }

        if (file.size > MAX_RECEIPT_BYTES) {
            setError('receipt', 'The receipt must be 6 MB or smaller.');

            return;
        }

        setData('receipt', file);
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        transform((form) => {
            const payload: Record<string, unknown> = { ...form };

            if (!form.receipt) {
                delete payload.receipt;
                delete payload.receipt_source;
            }

            if (!needsPaymentRef(form.payment_method)) {
                payload.payment_ref = form.payment_method === 'bank' ? form.payment_ref : '';
            }

            return payload as unknown as ExpenseForm;
        });

        post('/resources/expenditures', { forceFormData: true, preserveScroll: true });
    }

    const lastCategoryName = last?.category_name;

    return (
        <AppLayout
            title="Record expense"
            breadcrumbs={[
                { label: 'Resources', href: '/resources' },
                { label: 'Expenses', href: '/resources/expenditures' },
                { label: 'Record expense' },
            ]}
        >
            <Head title="Record expense" />

            <div className="mx-auto max-w-2xl">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3 animate-fade-up">
                    <div>
                        <h1 className="font-display text-2xl text-slate-900">Record expense</h1>
                        <p className="mt-1 text-sm text-slate-500">Amount, category, how it was paid — and a photo of the receipt.</p>
                    </div>
                    {last && (
                        <button
                            type="button"
                            onClick={repeatLast}
                            className="inline-flex min-h-[40px] items-center gap-1.5 rounded-full bg-blue-50 px-3.5 py-2 text-sm font-medium text-blue-700 ring-1 ring-inset ring-blue-200 transition-colors hover:bg-blue-100"
                        >
                            <IconRepeat size={16} stroke={2} />
                            Repeat last: {lastCategoryName} {formatCurrency(last.amount)}
                        </button>
                    )}
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Card className="animate-fade-up" style={{ animationDelay: '60ms' }}>
                        <CardBody className="flex flex-col gap-5">
                            {/* Amount — the one thing everyone types first. */}
                            <div>
                                <label htmlFor="amount" className="text-sm font-medium text-slate-700">
                                    Amount<span className="ml-0.5 text-red-500" aria-hidden="true">*</span>
                                </label>
                                <div
                                    className={`mt-1.5 flex items-baseline gap-2 rounded-xl bg-white px-4 py-3 ring-1 ring-inset focus-within:ring-2 focus-within:ring-blue-600 ${
                                        errors.amount ? 'ring-red-400' : 'ring-slate-300'
                                    }`}
                                >
                                    <input
                                        ref={amountRef}
                                        id="amount"
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        required
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className="font-display w-full min-w-0 border-0 bg-transparent p-0 text-4xl font-semibold tracking-tight text-slate-900 placeholder:text-slate-300 focus:ring-0 sm:text-5xl"
                                    />
                                    <span className="shrink-0 text-lg font-semibold text-slate-400">FCFA</span>
                                </div>
                                {errors.amount && <p className="mt-1 text-xs text-red-600">{errors.amount}</p>}
                            </div>

                            {/* Category tiles */}
                            <fieldset>
                                <legend className="text-sm font-medium text-slate-700">
                                    Category<span className="ml-0.5 text-red-500" aria-hidden="true">*</span>
                                </legend>
                                <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {categories.map((category) => {
                                        const isSelected = data.category_uuid === category.uuid;

                                        return (
                                            <button
                                                key={category.uuid}
                                                type="button"
                                                aria-pressed={isSelected}
                                                onClick={() => setData('category_uuid', category.uuid)}
                                                className={`flex min-h-[48px] items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors ${
                                                    isSelected
                                                        ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
                                                        : 'bg-slate-50 text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-100'
                                                }`}
                                            >
                                                <span className="line-clamp-2">{category.name}</span>
                                                {isSelected && <IconCheck size={16} stroke={2.5} className="shrink-0" />}
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.category_uuid && <p className="mt-1 text-xs text-red-600">{errors.category_uuid}</p>}
                            </fieldset>

                            {/* Paid by */}
                            <fieldset>
                                <legend className="text-sm font-medium text-slate-700">Paid by</legend>
                                <div className="mt-2 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 sm:grid-cols-4">
                                    {PAYMENT_METHOD_OPTIONS.map((option) => {
                                        const isSelected = data.payment_method === option.value;

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                aria-pressed={isSelected}
                                                onClick={() => setData('payment_method', option.value)}
                                                className={`min-h-[44px] rounded-lg px-2 py-2 text-sm font-medium transition-colors ${
                                                    isSelected ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:text-slate-900'
                                                }`}
                                            >
                                                {option.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.payment_method && <p className="mt-1 text-xs text-red-600">{errors.payment_method}</p>}
                                {(needsPaymentRef(data.payment_method) || data.payment_method === 'bank') && (
                                    <div className="mt-3">
                                        <TextInput
                                            id="payment_ref"
                                            label={needsPaymentRef(data.payment_method) ? 'Transaction ID' : 'Bank reference (optional)'}
                                            required={needsPaymentRef(data.payment_method)}
                                            value={data.payment_ref}
                                            onChange={(e) => setData('payment_ref', e.target.value)}
                                            placeholder={needsPaymentRef(data.payment_method) ? 'From the MoMo / Orange Money SMS' : ''}
                                            error={errors.payment_ref}
                                            autoComplete="off"
                                        />
                                    </div>
                                )}
                            </fieldset>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <TextInput
                                    id="spent_at"
                                    type="date"
                                    label="Date"
                                    required
                                    max={today}
                                    value={data.spent_at}
                                    onChange={(e) => setData('spent_at', e.target.value)}
                                    error={errors.spent_at}
                                    hint={
                                        daysBack > limits.backdate_days
                                            ? `More than ${limits.backdate_days} day${limits.backdate_days === 1 ? '' : 's'} back — a manager will check it.`
                                            : undefined
                                    }
                                />
                                <TextInput
                                    id="description"
                                    label="What was it for?"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="e.g. Fuel for zone rounds"
                                    error={errors.description}
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <SelectInput
                                        id="supplier_uuid"
                                        label={needsSupplier ? 'Supplier (required)' : 'Supplier'}
                                        value={data.supplier_uuid}
                                        onChange={(e) => setData('supplier_uuid', e.target.value)}
                                        error={errors.supplier_uuid}
                                    >
                                        <option value="">{needsSupplier ? 'Choose who was paid' : 'No supplier'}</option>
                                        {suppliers.map((supplier) => (
                                            <option key={supplier.uuid} value={supplier.uuid}>
                                                {supplier.name}
                                                {supplier.status === 'pending' ? ' (awaiting approval)' : ''}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    {selectedSupplier?.status === 'pending' && (
                                        <p className="mt-1 text-xs text-amber-700">This supplier is not approved yet, so a manager will review the expense.</p>
                                    )}
                                    <Link href="/resources/suppliers" className="mt-1 inline-block text-xs font-medium text-blue-700 hover:text-blue-800">
                                        Add a new supplier
                                    </Link>
                                </div>
                                <SelectInput
                                    id="service_uuid"
                                    label="For which service"
                                    value={data.service_uuid}
                                    onChange={(e) => setData('service_uuid', e.target.value)}
                                    error={errors.service_uuid}
                                >
                                    <option value="">Shared (all services)</option>
                                    {services.map((service) => (
                                        <option key={service.uuid} value={service.uuid}>
                                            {service.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>
                        </CardBody>
                    </Card>

                    <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
                        <CardBody>
                            <ReceiptPicker
                                file={data.receipt}
                                onChange={chooseReceipt}
                                error={errors.receipt}
                                required={needsReceipt}
                            />
                        </CardBody>
                    </Card>

                    {/* Live rules, computed from the company's limits. */}
                    <Card className="animate-fade-up" style={{ animationDelay: '140ms' }}>
                        <CardBody className="flex flex-col gap-2">
                            <p className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
                                <IconShieldCheck size={18} stroke={1.75} className="text-blue-600" />
                                {amount <= 0
                                    ? 'How approval works'
                                    : needsOwner
                                      ? 'A manager, then the owner, approve this'
                                      : needsManager
                                        ? 'A manager approves this'
                                        : 'Approved automatically if nothing looks unusual'}
                            </p>
                            <ul className="flex flex-col gap-1.5 text-sm">
                                <Hint active={needsReceipt}>A receipt photo is required from {formatCurrency(limits.receipt_required_from)}.</Hint>
                                <Hint active={needsManager}>From {formatCurrency(limits.auto_approve_below)} a manager approves.</Hint>
                                <Hint active={needsOwner}>Above {formatCurrency(limits.second_approval_above)} the owner also approves.</Hint>
                                <Hint active={needsSupplier}>Suppliers are required from {formatCurrency(limits.supplier_required_from)}.</Hint>
                            </ul>
                        </CardBody>
                    </Card>

                    <div className="animate-fade-up" style={{ animationDelay: '160ms' }}>
                        <button
                            type="button"
                            onClick={() => setShowMore((value) => !value)}
                            className="text-sm font-medium text-blue-700 hover:text-blue-800"
                            aria-expanded={showMore}
                        >
                            {showMore ? 'Hide extra details' : 'Add invoice number or notes'}
                        </button>
                        {showMore && (
                            <Card className="mt-2">
                                <CardBody className="flex flex-col gap-4">
                                    <TextInput
                                        id="reference_no"
                                        label="Invoice / receipt number"
                                        value={data.reference_no}
                                        onChange={(e) => setData('reference_no', e.target.value)}
                                        error={errors.reference_no}
                                    />
                                    <TextArea
                                        id="notes"
                                        label="Notes"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        error={errors.notes}
                                    />
                                </CardBody>
                            </Card>
                        )}
                    </div>

                    {/* Sticky on phones so Save is always one tap away. */}
                    <div className="sticky bottom-0 z-10 -mx-4 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:flex-row sm:justify-end sm:border-0 sm:bg-transparent sm:px-0">
                        <Link href="/resources/expenditures" className="w-full sm:w-auto">
                            <Button type="button" variant="secondary" className="w-full px-5 py-2.5 font-semibold sm:w-auto">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing} className="min-h-[48px] w-full px-6 py-2.5 text-base font-semibold sm:min-h-0 sm:w-auto sm:text-sm">
                            {processing && <LoadingSpinner className="text-white" />}
                            {processing ? 'Saving…' : amount > 0 ? `Save ${formatCurrency(amount)}` : 'Save expense'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}

function Hint({ active, children }: { active: boolean; children: ReactNode }) {
    return (
        <li className={`flex items-start gap-2 ${active ? 'font-medium text-amber-800' : 'text-slate-500'}`}>
            {active ? (
                <IconAlertTriangle size={16} stroke={2} className="mt-0.5 shrink-0 text-amber-500" />
            ) : (
                <IconInfoCircle size={16} stroke={1.75} className="mt-0.5 shrink-0 text-slate-400" />
            )}
            <span>{children}</span>
        </li>
    );
}
