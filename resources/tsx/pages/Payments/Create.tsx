import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { VerificationBadge } from '@/components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatCurrency';
import type { Customer, CustomerRecentPayment, PaymentFrequency } from '@/types';

function frequencyLabel(freq: PaymentFrequency): string {
    return freq === 'monthly' ? 'Monthly' : freq === 'months' ? 'Multi-month' : 'Yearly';
}

interface PaymentsCreateProps {
    customers: Customer[];
}

const frequencyOptions: { value: PaymentFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'months', label: 'Multi-month' },
    { value: 'yearly', label: 'Yearly' },
];

// Segmented control replacing bare native radio inputs — reuses the same
// pill-toggle look as the Single/Bulk switch above (rounded-lg bg-slate-100
// track, white "chip" for the active option) instead of unstyled browser
// radios, which were the most dated-looking control on this page.
function FrequencySelector({
    label,
    value,
    onChange,
    error,
    required,
}: {
    label: string;
    value: PaymentFrequency;
    onChange: (value: PaymentFrequency) => void;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-sm font-medium text-slate-700">
                {label}
                {required && (
                    <span className="ml-0.5 text-red-500" aria-hidden="true">
                        *
                    </span>
                )}
            </span>
            <div className="inline-flex w-fit rounded-lg bg-slate-100 p-1">
                {frequencyOptions.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={value === option.value}
                        onClick={() => onChange(option.value)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                            value === option.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

// A `disconnected` or `suspended` customer must be reconnected
// (Customers/Show.tsx's reconnect action) before a new payment can be
// recorded against them — see App\Http\Requests\StorePaymentRequest and
// App\Services\PaymentService::createBulk(). `passive` is deliberately NOT
// filtered out here; that status stays payable. Both the single and bulk
// pickers filter these customers out entirely rather than showing them
// disabled: a disconnected/suspended customer is never a valid payment
// target from this page, so there's nothing useful for the office worker
// to do by seeing it in the list — better to keep the picker showing only
// customers they can actually record a payment for than to let them pick
// one and discover the problem via a validation error.
function isPayable(customer: Customer): boolean {
    return customer.status !== 'disconnected' && customer.status !== 'suspended';
}

// Explains the isPayable() filter above to the office worker actually
// looking for a customer in the picker — otherwise a disconnected/suspended
// customer just silently isn't there, with no clue why. Links to the
// dedicated bulk-capable disconnect/suspend/reconnect workboard
// (Disconnections/Index.tsx) where the reconnect action actually lives.
function MissingCustomerNote() {
    return (
        <p className="text-xs text-slate-500">
            Don&apos;t see a customer?{' '}
            <Link href="/disconnections" className="font-medium text-blue-700 hover:underline">
                They may be disconnected or suspended — reconnect them first.
            </Link>
        </p>
    );
}

export default function PaymentsCreate({ customers }: PaymentsCreateProps) {
    const [mode, setMode] = useState<'single' | 'bulk'>('single');
    const payableCustomers = useMemo(() => customers.filter(isPayable), [customers]);

    return (
        <AppLayout
            title="Record Payment"
            breadcrumbs={[{ label: 'Payments', href: '/payments' }, { label: 'Record Payment' }]}
        >
            <Head title="Record Payment" />

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <div>
                    <h2 className="font-display text-2xl text-slate-900">Record Payment</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        {mode === 'single'
                            ? 'Log a customer payment and update their billing status.'
                            : 'Record standard monthly-bill payments for several customers at once.'}
                    </p>
                </div>
                <div className="inline-flex rounded-lg bg-slate-100 p-1">
                    {(['single', 'bulk'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            onClick={() => setMode(option)}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                mode === option ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            {option === 'single' ? 'Single' : 'Bulk'}
                        </button>
                    ))}
                </div>
            </div>

            {mode === 'single' ? <SinglePaymentForm customers={payableCustomers} /> : <BulkPaymentForm customers={payableCustomers} />}
        </AppLayout>
    );
}

function SinglePaymentForm({ customers }: { customers: Customer[] }) {
    const [search, setSearch] = useState('');

    const { data, setData, post, processing, errors } = useForm({
        customer_uuid: '',
        amount: '',
        credit: '',
        frequency: 'monthly' as PaymentFrequency,
        months: '',
        clear_arrears_first: false,
    });

    const filteredCustomers = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return customers;
        }

        return customers.filter(
            (customer) => customer.name.toLowerCase().includes(term) || (customer.phone ?? '').toLowerCase().includes(term),
        );
    }, [customers, search]);

    // Recomputed only when the customer list or the selected uuid changes,
    // not on every keystroke in the search box (which re-renders this
    // component but doesn't affect which customer is selected).
    const selectedCustomer = useMemo(
        () => customers.find((customer) => customer.uuid === data.customer_uuid) ?? null,
        [customers, data.customer_uuid],
    );

    const amountBelowBill =
        selectedCustomer && data.amount !== '' && Number(data.amount) < Number(selectedCustomer.bill) && data.frequency === 'monthly';

    // Formatted once per selected-customer change and reused at both call
    // sites below, instead of re-running Intl.NumberFormat on every render.
    const formattedSelectedBill = useMemo(
        () => (selectedCustomer ? formatCurrency(selectedCustomer.bill) : null),
        [selectedCustomer],
    );

    // Selected customer's most recent payment, for the side panel's "Last
    // payment status" section. `undefined` = not yet fetched for the
    // current selection, `null` = fetched and the customer has no
    // payments yet. Fetched via a plain background fetch() rather than
    // router.visit()/Inertia — this must not navigate or reload the page
    // while the office worker is still filling in the form.
    const [lastPayment, setLastPayment] = useState<CustomerRecentPayment | null | undefined>(undefined);
    const [lastPaymentStatus, setLastPaymentStatus] = useState<'idle' | 'loading' | 'loaded' | 'error'>('idle');

    useEffect(() => {
        if (!selectedCustomer) {
            setLastPayment(undefined);
            setLastPaymentStatus('idle');
            return;
        }

        let cancelled = false;
        setLastPaymentStatus('loading');
        setLastPayment(undefined);

        fetch(`/customers/${selectedCustomer.uuid}/last-payment`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((body: { payment: CustomerRecentPayment | null }) => {
                if (cancelled) {
                    return;
                }

                setLastPayment(body.payment);
                setLastPaymentStatus('loaded');
            })
            .catch(() => {
                if (!cancelled) {
                    setLastPaymentStatus('error');
                }
            });

        return () => {
            cancelled = true;
        };
        // Re-fetch only when the *selected customer* changes, not on every
        // keystroke elsewhere in the form.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedCustomer?.uuid]);

    // Live, read-only arithmetic reference for the frequency the office
    // worker has picked — this is a GUIDE only. Per product direction, it
    // must never auto-fill `data.amount`; the office worker always types
    // the amount themselves, this just helps them double-check the math.
    const guideAmount = useMemo(() => {
        if (!selectedCustomer) {
            return null;
        }

        const bill = Number(selectedCustomer.bill);

        if (data.frequency === 'monthly') {
            return bill;
        }

        if (data.frequency === 'yearly') {
            return bill * 12;
        }

        const months = Number(data.months);

        if (!data.months || Number.isNaN(months) || months <= 0) {
            return null;
        }

        return bill * months;
    }, [selectedCustomer, data.frequency, data.months]);

    // Draw-down Q1 (references/prepayment-drawdown.md): the "clear arrears
    // first" toggle is only relevant on a months/yearly prepayment for a
    // customer who currently owes.
    const isPrepayment = data.frequency === 'months' || data.frequency === 'yearly';
    const selectedArrears = selectedCustomer ? Number(selectedCustomer.total_arrears ?? 0) : 0;
    const showClearArrearsToggle = isPrepayment && selectedArrears > 0;

    // A read-only preview of how the payment splits, mirroring the mobile
    // Record Payment screen's guide. Never auto-fills anything.
    const prepaymentSplit = useMemo(() => {
        if (!showClearArrearsToggle || !selectedCustomer) {
            return null;
        }

        const rate = Number(selectedCustomer.bill);
        const amount = Number(data.amount);

        if (!Number.isFinite(rate) || rate <= 0 || !Number.isFinite(amount) || amount <= 0) {
            return null;
        }

        if (data.clear_arrears_first) {
            const cleared = Math.min(amount, selectedArrears);
            const months = Math.floor((amount - cleared) / rate);

            return `Clears ${formatCurrency(cleared)} of arrears, then covers ~${months} prepaid month(s).`;
        }

        const intended = data.frequency === 'yearly' ? 12 : Number(data.months) || 0;
        const months = Math.min(intended, Math.floor(amount / rate));

        return `Covers ${months} prepaid month(s); ${formatCurrency(selectedArrears)} arrears stays due.`;
    }, [showClearArrearsToggle, selectedCustomer, data.amount, data.months, data.frequency, data.clear_arrears_first, selectedArrears]);

    // Keep the flag from lingering once it stops being relevant.
    useEffect(() => {
        if (!showClearArrearsToggle && data.clear_arrears_first) {
            setData('clear_arrears_first', false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [showClearArrearsToggle]);

    function selectCustomer(uuid: string) {
        setData((current) => {
            const customer = customers.find((c) => c.uuid === uuid);

            return {
                ...current,
                customer_uuid: uuid,
                amount: customer && current.frequency === 'monthly' ? customer.bill : current.amount,
            };
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/payments');
    }

    return (
        <div className="flex max-w-5xl flex-col items-start gap-6 lg:flex-row">
            <form onSubmit={submit} className="w-full animate-fade-up lg:max-w-2xl" style={{ animationDelay: '100ms' }}>
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Payment details</h3>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <label className="text-sm font-medium text-slate-700">
                                Customer
                                <span className="ml-0.5 text-red-500" aria-hidden="true">
                                    *
                                </span>
                            </label>
                            <TextInput
                                placeholder="Search by name or phone…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <SelectInput
                                value={data.customer_uuid}
                                onChange={(e) => selectCustomer(e.target.value)}
                                error={errors.customer_uuid}
                                required
                            >
                                <option value="">Select a customer…</option>
                                {filteredCustomers.map((customer) => (
                                    <option key={customer.uuid} value={customer.uuid}>
                                        {customer.name}
                                        {customer.phone ? ` — ${customer.phone}` : ''} ({customer.zone_name})
                                    </option>
                                ))}
                            </SelectInput>
                            {selectedCustomer && (
                                <p className="text-xs text-slate-500">
                                    Monthly bill: {formattedSelectedBill} · Status: {selectedCustomer.status}
                                </p>
                            )}
                            <MissingCustomerNote />
                        </div>

                        <TextInput
                            id="amount"
                            label="Amount (FCFA)"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            error={errors.amount}
                            required
                        />
                        {amountBelowBill && (
                            <p className="-mt-3 text-xs text-yellow-600">
                                Amount is less than this customer&apos;s monthly bill ({formattedSelectedBill}).
                            </p>
                        )}

                        <FrequencySelector
                            label="Frequency"
                            value={data.frequency}
                            onChange={(freq) => setData('frequency', freq)}
                            error={errors.frequency}
                            required
                        />

                        {data.frequency === 'months' && (
                            <TextInput
                                id="months"
                                label="Number of months"
                                type="number"
                                min="1"
                                value={data.months}
                                onChange={(e) => setData('months', e.target.value)}
                                error={errors.months}
                                required
                            />
                        )}

                        {showClearArrearsToggle && (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <label className="flex items-start gap-2 text-sm font-medium text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.clear_arrears_first}
                                        onChange={(e) => setData('clear_arrears_first', e.target.checked)}
                                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                    />
                                    <span>
                                        Clear the {formatCurrency(selectedArrears)} arrears first, then buy prepaid months with the rest
                                    </span>
                                </label>
                                {prepaymentSplit && <p className="mt-2 pl-6 text-xs text-slate-500">{prepaymentSplit}</p>}
                            </div>
                        )}

                        <TextInput
                            id="credit"
                            label="Credit (optional)"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.credit}
                            onChange={(e) => setData('credit', e.target.value)}
                            error={errors.credit}
                        />

                        <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                            <Link href="/payments" className="w-full sm:w-auto">
                                <Button type="button" variant="secondary" className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                {processing && <LoadingSpinner className="h-4 w-4" />}
                                {processing ? 'Saving…' : 'Record Payment'}
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            </form>

            <div className="w-full animate-fade-up lg:sticky lg:top-6 lg:w-80 lg:flex-shrink-0" style={{ animationDelay: '150ms' }}>
                {selectedCustomer ? (
                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Last payment status</h3>
                            </CardHeader>
                            <CardBody>
                                {lastPaymentStatus === 'loading' && (
                                    <div className="flex items-center gap-2 text-sm text-slate-500">
                                        <LoadingSpinner className="h-4 w-4" />
                                        Loading payment history…
                                    </div>
                                )}
                                {lastPaymentStatus === 'error' && (
                                    <p className="text-sm text-red-600">Couldn&apos;t load payment history. Try selecting the customer again.</p>
                                )}
                                {lastPaymentStatus === 'loaded' && lastPayment === null && (
                                    <p className="text-sm text-slate-500">No payment history yet.</p>
                                )}
                                {lastPaymentStatus === 'loaded' && lastPayment && (
                                    <dl className="flex flex-col gap-2 text-sm">
                                        <div className="flex items-center justify-between">
                                            <dt className="text-slate-500">Amount</dt>
                                            <dd className="font-medium text-slate-900">{formatCurrency(lastPayment.amount)}</dd>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <dt className="text-slate-500">Date</dt>
                                            <dd className="text-slate-700">{new Date(lastPayment.created_at).toLocaleDateString()}</dd>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <dt className="text-slate-500">Frequency</dt>
                                            <dd className="text-slate-700">{frequencyLabel(lastPayment.frequency)}</dd>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <dt className="text-slate-500">Status</dt>
                                            <dd>
                                                <VerificationBadge status={lastPayment.verification_status} />
                                            </dd>
                                        </div>
                                    </dl>
                                )}
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Frequency calculation guide</h3>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-2">
                                <p className="text-xs font-medium tracking-wide text-slate-400 uppercase">Reference only — not auto-filled</p>
                                <p className="text-sm text-slate-600">
                                    Monthly bill: <span className="font-medium text-slate-900">{formattedSelectedBill}</span>
                                </p>
                                {data.frequency === 'monthly' && (
                                    <p className="text-sm text-slate-600">
                                        Suggested amount for <span className="font-medium">Monthly</span>:{' '}
                                        <span className="font-semibold text-slate-900">{formatCurrency(selectedCustomer.bill)}</span>
                                    </p>
                                )}
                                {data.frequency === 'yearly' && (
                                    <p className="text-sm text-slate-600">
                                        Suggested amount for <span className="font-medium">Yearly</span> ({formattedSelectedBill} × 12):{' '}
                                        <span className="font-semibold text-slate-900">{formatCurrency(String(guideAmount))}</span>
                                    </p>
                                )}
                                {data.frequency === 'months' &&
                                    (guideAmount !== null ? (
                                        <p className="text-sm text-slate-600">
                                            Suggested amount for <span className="font-medium">{data.months} month(s)</span> (
                                            {formattedSelectedBill} × {data.months}):{' '}
                                            <span className="font-semibold text-slate-900">{formatCurrency(String(guideAmount))}</span>
                                        </p>
                                    ) : (
                                        <p className="text-sm text-slate-500">Enter a number of months above to see a suggested amount.</p>
                                    ))}
                                <p className="mt-1 text-xs text-slate-400">
                                    This is just a guide to help you work out the amount — it won&apos;t be entered for you. Type the amount the
                                    customer actually paid into the Amount field above.
                                </p>
                            </CardBody>
                        </Card>
                    </div>
                ) : (
                    <Card>
                        <CardBody>
                            <p className="text-sm text-slate-500">Select a customer to see their payment history and a frequency guide.</p>
                        </CardBody>
                    </Card>
                )}
            </div>
        </div>
    );
}

/**
 * Records one payment per selected customer, each at that customer's own
 * bill — no per-row amount field, because the whole point is "these
 * customers each paid exactly their standard monthly bill" (see
 * App\Services\PaymentService::createBulk()). A customer needing a
 * different amount, credit, or partial payment still goes through the
 * Single form.
 */
function BulkPaymentForm({ customers }: { customers: Customer[] }) {
    const [search, setSearch] = useState('');
    const [zoneFilter, setZoneFilter] = useState('');
    const [selected, setSelected] = useState<Set<string>>(new Set());

    const { data, setData, transform, post, processing, errors } = useForm({
        customer_uuids: [] as string[],
        frequency: 'monthly' as PaymentFrequency,
        months: '',
    });

    const zones = useMemo(() => {
        const names = new Set(customers.map((customer) => customer.zone_name).filter((name): name is string => Boolean(name)));
        return [...names].sort();
    }, [customers]);

    const filteredCustomers = useMemo(() => {
        const term = search.trim().toLowerCase();

        return customers.filter((customer) => {
            if (zoneFilter && customer.zone_name !== zoneFilter) {
                return false;
            }

            if (!term) {
                return true;
            }

            return customer.name.toLowerCase().includes(term) || (customer.phone ?? '').toLowerCase().includes(term);
        });
    }, [customers, search, zoneFilter]);

    const allFilteredSelected = filteredCustomers.length > 0 && filteredCustomers.every((customer) => selected.has(customer.uuid));

    function toggle(uuid: string) {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(uuid)) {
                next.delete(uuid);
            } else {
                next.add(uuid);
            }
            return next;
        });
    }

    function toggleAllFiltered() {
        setSelected((current) => {
            const next = new Set(current);
            filteredCustomers.forEach((customer) => (allFilteredSelected ? next.delete(customer.uuid) : next.add(customer.uuid)));
            return next;
        });
    }

    const selectedCustomers = useMemo(() => customers.filter((customer) => selected.has(customer.uuid)), [customers, selected]);

    const totalAmount = useMemo(
        () => selectedCustomers.reduce((sum, customer) => sum + Number(customer.bill), 0),
        [selectedCustomers],
    );

    function submit(e: FormEvent) {
        e.preventDefault();

        const uuids = [...selected];

        // Same stale-closure hazard as Payments/Index.tsx's VerifyModal:
        // setData() alone wouldn't be guaranteed to land before post()
        // reads it, since React batches the update. transform() runs
        // synchronously right before the request body is built.
        setData('customer_uuids', uuids);
        transform((formData) => ({ ...formData, customer_uuids: uuids }));

        post('/payments/bulk', { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="max-w-4xl animate-fade-up" style={{ animationDelay: '100ms' }}>
            <Card>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Select customers</h3>
                </CardHeader>
                <CardBody className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <div className="sm:flex-1">
                            <TextInput placeholder="Search by name or phone…" value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                        <div className="sm:w-56">
                            <SelectInput value={zoneFilter} onChange={(e) => setZoneFilter(e.target.value)}>
                                <option value="">All zones</option>
                                {zones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </SelectInput>
                        </div>
                    </div>
                    <MissingCustomerNote />

                    <div className="flex items-center justify-between">
                        <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                type="checkbox"
                                checked={allFilteredSelected}
                                onChange={toggleAllFiltered}
                                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                            />
                            Select all {filteredCustomers.length} shown
                        </label>
                        <span className="text-sm text-slate-500">{selected.size} selected</span>
                    </div>
                    {errors.customer_uuids && <p className="text-xs text-red-600">{errors.customer_uuids}</p>}

                    <div className="max-h-96 overflow-y-auto rounded-lg border border-slate-200">
                        {filteredCustomers.length === 0 ? (
                            <p className="p-4 text-center text-sm text-slate-500">No customers match this search.</p>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {filteredCustomers.map((customer) => (
                                    <li key={customer.uuid}>
                                        <label className="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-slate-50">
                                            <span className="flex items-center gap-3">
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(customer.uuid)}
                                                    onChange={() => toggle(customer.uuid)}
                                                    className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                                />
                                                <span>
                                                    <span className="font-medium text-slate-900">{customer.name}</span>
                                                    <span className="text-slate-500"> · {customer.zone_name}</span>
                                                </span>
                                            </span>
                                            <span className="font-medium text-slate-700">{formatCurrency(customer.bill)}</span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <FrequencySelector
                        label="Frequency (applies to all selected)"
                        value={data.frequency}
                        onChange={(freq) => setData('frequency', freq)}
                        error={errors.frequency}
                        required
                    />

                    {data.frequency === 'months' && (
                        <TextInput
                            id="bulk_months"
                            label="Number of months"
                            type="number"
                            min="1"
                            value={data.months}
                            onChange={(e) => setData('months', e.target.value)}
                            error={errors.months}
                            required
                        />
                    )}

                    <div className="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-slate-600">
                            {selected.size} payment{selected.size === 1 ? '' : 's'} · total {formatCurrency(String(totalAmount))}
                        </p>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Link href="/payments" className="w-full sm:w-auto">
                                <Button type="button" variant="secondary" className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                    Cancel
                                </Button>
                            </Link>
                            <Button
                                type="submit"
                                disabled={processing || selected.size === 0}
                                className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                            >
                                {processing && <LoadingSpinner className="h-4 w-4" />}
                                {processing ? 'Saving…' : `Record ${selected.size || ''} Payments`}
                            </Button>
                        </div>
                    </div>
                </CardBody>
            </Card>
        </form>
    );
}
