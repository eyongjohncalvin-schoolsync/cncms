import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconCalendarRepeat, IconCopy, IconDeviceFloppy, IconPencil, IconPlus, IconPower } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { Modal } from '@/components/ui/Modal';
import { SelectInput } from '@/components/ui/SelectInput';
import { TextInput } from '@/components/ui/TextInput';
import { ResourcesTabs } from '@/components/resources/ResourcesTabs';
import { ExpenseStatusBadge, PAYMENT_METHOD_OPTIONS, paymentMethodLabel } from '@/components/resources/ExpenseUi';
import { formatCurrency } from '@/lib/formatCurrency';
import type { ExpenseLimits, NamedRef, PageProps } from '@/types';

interface BudgetRow {
    category_uuid: string;
    category_name: string;
    budget: string | null;
    approved: string;
    pending: string;
}

interface RecurringRow {
    uuid: string;
    name: string;
    amount: string;
    payment_method: string | null;
    day_of_month: number;
    is_active: boolean;
    category_uuid: string | null;
    category_name: string | null;
    supplier_uuid: string | null;
    supplier_name: string | null;
    service_uuid: string | null;
    service_name: string | null;
    recorded: { uuid: string; status: string; amount: string } | null;
}

interface SettingsProps {
    period: string;
    can_manage: boolean;
    can_record: boolean;
    limits: ExpenseLimits;
    budgets: BudgetRow[];
    recurring: RecurringRow[];
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
}

function monthLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);

    if (!year || !month) {
        return period;
    }

    return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

function currentPeriod(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

export default function ResourcesSettings({ period, can_manage, can_record, limits, budgets, recurring, categories, suppliers, services }: SettingsProps) {
    const { errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const [isLoading, setIsLoading] = useState(false);

    function changePeriod(next: string) {
        if (!next) {
            return;
        }

        router.get('/resources/settings', { period: next }, {
            preserveState: false,
            preserveScroll: true,
            replace: true,
            onStart: () => setIsLoading(true),
            onFinish: () => setIsLoading(false),
        });
    }

    return (
        <AppLayout title="Budgets & limits" breadcrumbs={[{ label: 'Resources', href: '/resources' }, { label: 'Budgets & limits' }]}>
            <Head title="Budgets & limits" />

            <ResourcesTabs active="settings" />

            <div className="mb-5 flex flex-col gap-3 animate-fade-up sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="font-display text-2xl text-slate-900">Budgets &amp; limits</h1>
                    <p className="mt-1 text-sm text-slate-500">Monthly budget per category, regular expenses, and when a person must approve.</p>
                </div>
                <div className="flex items-end gap-2">
                    <div className="w-full sm:w-44">
                        <TextInput id="period" label="Month" type="month" value={period} onChange={(e) => changePeriod(e.target.value)} />
                    </div>
                    {isLoading && <LoadingSpinner className="mb-2.5 text-blue-600" />}
                </div>
            </div>

            {(errors?.template || errors?.period) && (
                <div className="mb-4 rounded-md border-l-4 border-red-500 bg-red-100 px-4 py-3 text-sm text-red-800">{errors.template ?? errors.period}</div>
            )}

            <div className="flex flex-col gap-4">
                <BudgetsCard key={`${period}:${budgets.map((row) => row.budget ?? '').join(',')}`} period={period} budgets={budgets} canManage={can_manage} />
                <RecurringCard
                    period={period}
                    recurring={recurring}
                    canManage={can_manage}
                    canRecord={can_record}
                    categories={categories}
                    suppliers={suppliers}
                    services={services}
                />
                <LimitsCard limits={limits} canManage={can_manage} />
            </div>
        </AppLayout>
    );
}

function BudgetsCard({ period, budgets, canManage }: { period: string; budgets: BudgetRow[]; canManage: boolean }) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        period,
        budgets: budgets.map((row) => ({ category_uuid: row.category_uuid, amount: row.budget !== null ? String(Number(row.budget)) : '' })),
    });
    const [isCopying, setIsCopying] = useState(false);

    const totals = budgets.reduce(
        (sum, row) => ({
            budget: sum.budget + Number(row.budget ?? 0),
            approved: sum.approved + Number(row.approved),
            pending: sum.pending + Number(row.pending),
        }),
        { budget: 0, approved: 0, pending: 0 },
    );

    function setAmount(index: number, amount: string) {
        setData(
            'budgets',
            data.budgets.map((row, i) => (i === index ? { ...row, amount } : row)),
        );
    }

    function save(e: FormEvent) {
        e.preventDefault();
        post('/resources/settings/budgets', { preserveScroll: true });
    }

    function copyLastMonth() {
        router.post('/resources/settings/budgets/copy', { period }, {
            preserveScroll: true,
            onStart: () => setIsCopying(true),
            onFinish: () => setIsCopying(false),
        });
    }

    const errorList = errors as Record<string, string | undefined>;

    return (
        <Card className="animate-fade-up" style={{ animationDelay: '60ms' }}>
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-sm font-semibold text-slate-900">Budgets — {monthLabel(period)}</h2>
                    <p className="mt-0.5 text-xs text-slate-500">
                        {formatCurrency(totals.approved)} approved
                        {totals.pending > 0 ? ` + ${formatCurrency(totals.pending)} waiting` : ''}
                        {totals.budget > 0 ? ` of ${formatCurrency(totals.budget)} budgeted` : ''}
                    </p>
                </div>
                {canManage && (
                    <Button variant="secondary" onClick={copyLastMonth} disabled={isCopying} className="min-h-[40px] w-full sm:w-auto">
                        {isCopying ? <LoadingSpinner /> : <IconCopy size={17} stroke={1.75} />}
                        Copy last month
                    </Button>
                )}
            </CardHeader>
            <form onSubmit={save}>
                {budgets.length === 0 ? (
                    <CardBody>
                        <EmptyState title="No active categories" description="Add expense categories first." />
                    </CardBody>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {budgets.map((row, index) => {
                            const budget = Number(canManage ? data.budgets[index]?.amount : row.budget) || 0;
                            const approved = Number(row.approved);
                            const pending = Number(row.pending);
                            const pct = budget > 0 ? (approved / budget) * 100 : 0;
                            const pendingPct = budget > 0 ? (pending / budget) * 100 : 0;
                            const barColor = pct > 100 ? 'bg-red-500' : pct >= 80 ? 'bg-amber-500' : 'bg-green-500';

                            return (
                                <li key={row.category_uuid} className="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1.4fr)] sm:items-center sm:gap-4">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-slate-900">{row.category_name}</p>
                                        <p className="text-xs text-slate-500">
                                            {formatCurrency(approved)} spent
                                            {pending > 0 ? ` · ${formatCurrency(pending)} waiting` : ''}
                                        </p>
                                    </div>
                                    <div>
                                        {canManage ? (
                                            <TextInput
                                                id={`budget_${row.category_uuid}`}
                                                aria-label={`Budget for ${row.category_name}`}
                                                type="number"
                                                inputMode="decimal"
                                                min="0"
                                                step="1"
                                                placeholder="No budget"
                                                value={data.budgets[index]?.amount ?? ''}
                                                onChange={(e) => setAmount(index, e.target.value)}
                                                error={errorList[`budgets.${index}.amount`]}
                                            />
                                        ) : (
                                            <p className="text-sm font-medium text-slate-800">{row.budget !== null ? formatCurrency(row.budget) : 'No budget'}</p>
                                        )}
                                    </div>
                                    <div>
                                        {budget > 0 ? (
                                            <>
                                                <div
                                                    className="flex h-2.5 overflow-hidden rounded-full bg-slate-100"
                                                    role="progressbar"
                                                    aria-valuenow={Math.round(pct)}
                                                    aria-valuemin={0}
                                                    aria-valuemax={100}
                                                    aria-label={`${row.category_name} budget used`}
                                                >
                                                    <div className={`h-full ${barColor}`} style={{ width: `${Math.min(100, pct)}%` }} />
                                                    {pendingPct > 0 && pct < 100 && (
                                                        <div className="h-full bg-slate-300" style={{ width: `${Math.min(100 - Math.min(100, pct), pendingPct)}%` }} />
                                                    )}
                                                </div>
                                                <p className={`mt-1 text-xs ${pct > 100 ? 'font-semibold text-red-700' : pct >= 80 ? 'text-amber-700' : 'text-slate-500'}`}>
                                                    {Math.round(pct)}% used
                                                    {pct > 100 ? ` — over by ${formatCurrency(approved - budget)}` : ` — ${formatCurrency(budget - approved)} left`}
                                                </p>
                                            </>
                                        ) : (
                                            <p className="text-xs text-slate-400">No budget set</p>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {canManage && budgets.length > 0 && (
                    <div className="flex flex-col gap-2 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-slate-500">Leave blank to remove a category's budget.</p>
                        <Button type="submit" disabled={processing || !isDirty} className="min-h-[44px] w-full font-semibold sm:w-auto">
                            {processing ? <LoadingSpinner className="text-white" /> : <IconDeviceFloppy size={18} stroke={1.75} />}
                            Save budgets
                        </Button>
                    </div>
                )}
            </form>
        </Card>
    );
}

function RecurringCard({
    period,
    recurring,
    canManage,
    canRecord,
    categories,
    suppliers,
    services,
}: {
    period: string;
    recurring: RecurringRow[];
    canManage: boolean;
    canRecord: boolean;
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
}) {
    const [recording, setRecording] = useState<RecurringRow | null>(null);
    const [editing, setEditing] = useState<RecurringRow | null>(null);
    const [adding, setAdding] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const isFuture = period > currentPeriod();

    function toggleActive(row: RecurringRow) {
        router.patch(`/resources/recurring/${row.uuid}`, { is_active: !row.is_active }, {
            preserveScroll: true,
            onStart: () => setBusy(row.uuid),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <Card className="animate-fade-up" style={{ animationDelay: '100ms' }}>
            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
                        <IconCalendarRepeat size={18} stroke={1.75} className="text-slate-400" />
                        Regular expenses
                    </h2>
                    <p className="mt-0.5 text-xs text-slate-500">Rent, salaries, electricity… record each month in one tap. Normal approval rules apply.</p>
                </div>
                {canManage && (
                    <Button variant="secondary" onClick={() => setAdding(true)} className="min-h-[40px] w-full sm:w-auto">
                        <IconPlus size={17} stroke={2} />
                        Add regular expense
                    </Button>
                )}
            </CardHeader>
            {recurring.length === 0 ? (
                <CardBody>
                    <EmptyState title="No regular expenses yet" description="Add the bills you pay every month so they're never forgotten." />
                </CardBody>
            ) : (
                <ul className="divide-y divide-slate-100">
                    {recurring.map((row) => (
                        <li key={row.uuid} className={`flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between ${row.is_active ? '' : 'opacity-60'}`}>
                            <div className="min-w-0">
                                <p className="font-medium text-slate-900">
                                    {row.name}
                                    {!row.is_active && <span className="ml-2 text-xs font-normal text-slate-500">(switched off)</span>}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {formatCurrency(row.amount)} · day {row.day_of_month} · {row.category_name ?? '—'}
                                    {row.payment_method ? ` · ${paymentMethodLabel(row.payment_method)}` : ''}
                                    {row.supplier_name ? ` · ${row.supplier_name}` : ''}
                                    {` · ${row.service_name ?? 'Shared'}`}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {row.recorded ? (
                                    <Link
                                        href={`/resources/expenditures/${row.recorded.uuid}`}
                                        className="inline-flex min-h-[40px] items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-1.5 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-100"
                                    >
                                        Recorded · {formatCurrency(row.recorded.amount)}
                                        <ExpenseStatusBadge status={row.recorded.status} />
                                    </Link>
                                ) : (
                                    canRecord &&
                                    row.is_active && (
                                        <Button onClick={() => setRecording(row)} disabled={isFuture} className="min-h-[40px] flex-1 sm:flex-none" title={isFuture ? 'This month has not started yet' : undefined}>
                                            Record for {monthLabel(period)}
                                        </Button>
                                    )
                                )}
                                {canManage && (
                                    <>
                                        <Button variant="secondary" onClick={() => setEditing(row)} aria-label={`Edit ${row.name}`} className="min-h-[40px]">
                                            <IconPencil size={17} stroke={1.75} />
                                            <span className="sm:sr-only">Edit</span>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() => toggleActive(row)}
                                            disabled={busy !== null}
                                            className="min-h-[40px]"
                                            title={row.is_active ? 'Switch off' : 'Switch on'}
                                        >
                                            {busy === row.uuid ? <LoadingSpinner /> : <IconPower size={17} stroke={1.75} />}
                                            {row.is_active ? 'Switch off' : 'Switch on'}
                                        </Button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Modal open={recording !== null} onClose={() => setRecording(null)} title={recording ? `Record ${recording.name}` : 'Record'}>
                {recording && <RecordRecurringForm key={recording.uuid} row={recording} period={period} onDone={() => setRecording(null)} />}
            </Modal>

            <Modal
                open={adding || editing !== null}
                onClose={() => {
                    setAdding(false);
                    setEditing(null);
                }}
                title={editing ? `Edit ${editing.name}` : 'Add regular expense'}
            >
                {(adding || editing) && (
                    <RecurringTemplateForm
                        key={editing?.uuid ?? 'new'}
                        initial={editing}
                        categories={categories}
                        suppliers={suppliers}
                        services={services}
                        onDone={() => {
                            setAdding(false);
                            setEditing(null);
                        }}
                    />
                )}
            </Modal>
        </Card>
    );
}

function RecordRecurringForm({ row, period, onDone }: { row: RecurringRow; period: string; onDone: () => void }) {
    const { data, setData, post, processing, errors, transform } = useForm({ period, amount: String(Number(row.amount)) });

    function submit(e: FormEvent) {
        e.preventDefault();
        transform((form) => ({ period: form.period, amount: Number(form.amount) === Number(row.amount) ? '' : form.amount }));
        post(`/resources/recurring/${row.uuid}/record`, { preserveScroll: true, onSuccess: () => onDone() });
    }

    const errorList = errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <p className="text-sm text-slate-600">
                Records {row.name} for <span className="font-medium">{monthLabel(period)}</span>. Change the amount if this month's bill is different (e.g.
                electricity).
            </p>
            <TextInput
                id="record_amount"
                label="Amount (FCFA)"
                type="number"
                inputMode="decimal"
                min="0"
                step="0.01"
                required
                value={data.amount}
                onChange={(e) => setData('amount', e.target.value)}
                error={errors.amount ?? errorList.period ?? errorList.template}
            />
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="secondary" onClick={onDone} className="w-full sm:w-auto">
                    Cancel
                </Button>
                <Button type="submit" disabled={processing} className="w-full font-semibold sm:w-auto">
                    {processing && <LoadingSpinner className="text-white" />}
                    Record {data.amount ? formatCurrency(data.amount) : ''}
                </Button>
            </div>
        </form>
    );
}

function RecurringTemplateForm({
    initial,
    categories,
    suppliers,
    services,
    onDone,
}: {
    initial: RecurringRow | null;
    categories: NamedRef[];
    suppliers: NamedRef[];
    services: NamedRef[];
    onDone: () => void;
}) {
    const { data, setData, post, patch, processing, errors } = useForm({
        name: initial?.name ?? '',
        category_uuid: initial?.category_uuid ?? '',
        supplier_uuid: initial?.supplier_uuid ?? '',
        service_uuid: initial?.service_uuid ?? '',
        amount: initial ? String(Number(initial.amount)) : '',
        payment_method: initial?.payment_method ?? '',
        day_of_month: initial ? String(initial.day_of_month) : '1',
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        if (initial) {
            patch(`/resources/recurring/${initial.uuid}`, { preserveScroll: true, onSuccess: () => onDone() });
        } else {
            post('/resources/recurring', { preserveScroll: true, onSuccess: () => onDone() });
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <TextInput id="rec_name" label="Name" required placeholder="e.g. Office rent" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <TextInput
                    id="rec_amount"
                    label="Usual amount (FCFA)"
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.01"
                    required
                    value={data.amount}
                    onChange={(e) => setData('amount', e.target.value)}
                    error={errors.amount}
                />
                <TextInput
                    id="rec_day"
                    label="Day of month (1–28)"
                    type="number"
                    inputMode="numeric"
                    min="1"
                    max="28"
                    required
                    value={data.day_of_month}
                    onChange={(e) => setData('day_of_month', e.target.value)}
                    error={errors.day_of_month}
                />
            </div>
            <SelectInput id="rec_category" label="Category" required value={data.category_uuid} onChange={(e) => setData('category_uuid', e.target.value)} error={errors.category_uuid}>
                <option value="">Choose a category</option>
                {categories.map((category) => (
                    <option key={category.uuid} value={category.uuid}>
                        {category.name}
                    </option>
                ))}
            </SelectInput>
            <SelectInput id="rec_supplier" label="Supplier" value={data.supplier_uuid} onChange={(e) => setData('supplier_uuid', e.target.value)} error={errors.supplier_uuid}>
                <option value="">No supplier</option>
                {suppliers.map((supplier) => (
                    <option key={supplier.uuid} value={supplier.uuid}>
                        {supplier.name}
                    </option>
                ))}
            </SelectInput>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <SelectInput id="rec_service" label="Service" value={data.service_uuid} onChange={(e) => setData('service_uuid', e.target.value)} error={errors.service_uuid}>
                    <option value="">Shared</option>
                    {services.map((service) => (
                        <option key={service.uuid} value={service.uuid}>
                            {service.name}
                        </option>
                    ))}
                </SelectInput>
                <SelectInput id="rec_method" label="Paid by" value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} error={errors.payment_method}>
                    <option value="">Not stated</option>
                    {PAYMENT_METHOD_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </SelectInput>
            </div>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="secondary" onClick={onDone} className="w-full sm:w-auto">
                    Cancel
                </Button>
                <Button type="submit" disabled={processing} className="w-full font-semibold sm:w-auto">
                    {processing && <LoadingSpinner className="text-white" />}
                    {initial ? 'Save changes' : 'Add regular expense'}
                </Button>
            </div>
        </form>
    );
}

const LIMIT_FIELDS: { key: keyof ExpenseLimits; field: string; label: string; hint: string; isDays?: boolean }[] = [
    { key: 'auto_approve_below', field: 'expense_auto_approve_below', label: 'Approved automatically below', hint: 'Clean expenses under this need no one.' },
    { key: 'second_approval_above', field: 'expense_second_approval_above', label: 'Owner also approves above', hint: 'A manager first, then the owner.' },
    { key: 'receipt_required_from', field: 'expense_receipt_required_from', label: 'Receipt photo required from', hint: 'Without it the expense waits.' },
    { key: 'supplier_required_from', field: 'expense_supplier_required_from', label: 'Supplier required from', hint: 'Who was paid must be named.' },
    { key: 'backdate_days', field: 'expense_backdate_days', label: 'Days back allowed without review', hint: 'Older dates are checked by a manager.', isDays: true },
];

function LimitsCard({ limits, canManage }: { limits: ExpenseLimits; canManage: boolean }) {
    const { data, setData, patch, processing, errors, isDirty } = useForm<Record<string, string>>({
        expense_auto_approve_below: String(Number(limits.auto_approve_below)),
        expense_second_approval_above: String(Number(limits.second_approval_above)),
        expense_receipt_required_from: String(Number(limits.receipt_required_from)),
        expense_supplier_required_from: String(Number(limits.supplier_required_from)),
        expense_backdate_days: String(limits.backdate_days),
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        patch('/resources/settings/limits', { preserveScroll: true });
    }

    return (
        <Card className="animate-fade-up" style={{ animationDelay: '140ms' }}>
            <CardHeader>
                <h2 className="text-sm font-semibold text-slate-900">Approval &amp; evidence limits</h2>
                <p className="mt-0.5 text-xs text-slate-500">
                    {canManage ? 'These rules apply to every new expense.' : 'Set by the owner. Only people allowed to change resources settings can edit them.'}
                </p>
            </CardHeader>
            <CardBody>
                {canManage ? (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {LIMIT_FIELDS.map((item) => (
                                <TextInput
                                    key={item.field}
                                    id={item.field}
                                    label={item.isDays ? item.label : `${item.label} (FCFA)`}
                                    type="number"
                                    inputMode="numeric"
                                    min="0"
                                    max={item.isDays ? 31 : undefined}
                                    step="1"
                                    required
                                    value={data[item.field]}
                                    onChange={(e) => setData(item.field, e.target.value)}
                                    error={errors[item.field]}
                                    hint={item.hint}
                                />
                            ))}
                        </div>
                        <div className="flex justify-end">
                            <Button type="submit" disabled={processing || !isDirty} className="min-h-[44px] w-full font-semibold sm:w-auto">
                                {processing ? <LoadingSpinner className="text-white" /> : <IconDeviceFloppy size={18} stroke={1.75} />}
                                Save limits
                            </Button>
                        </div>
                    </form>
                ) : (
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {LIMIT_FIELDS.map((item) => (
                            <div key={item.field} className="rounded-lg bg-slate-50 px-3 py-2.5">
                                <dt className="text-xs text-slate-500">{item.label}</dt>
                                <dd className="mt-0.5 font-semibold text-slate-900">
                                    {item.isDays ? `${limits[item.key]} day(s)` : formatCurrency(limits[item.key] as string)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}
            </CardBody>
        </Card>
    );
}
