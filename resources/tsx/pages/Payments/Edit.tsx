import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { IconArrowLeft } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Payment, PaymentFrequency } from '@/types';

interface PaymentsEditProps {
    payment: Payment;
}

const frequencyOptions: { value: PaymentFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'months', label: 'Multi-month' },
    { value: 'yearly', label: 'Yearly' },
];

// Same segmented-control look as Payments/Create.tsx's FrequencySelector —
// duplicated here rather than shared because Create.tsx doesn't export it
// (it's a page-local component there too).
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

/**
 * Corrects a recorded payment's amount/frequency/months/credit — distinct
 * from the Review/Verify action on Payments/Index.tsx and Payments/Show.tsx,
 * which only approves or rejects a *pending* payment and never lets anyone
 * change these fields. Only reachable when the controller's `can_manage`
 * flag (App\Http\Controllers\PaymentController::show(), mirroring
 * PaymentPolicy::update()) is true; App\Http\Requests\UpdatePaymentRequest
 * enforces the same rule server-side regardless.
 *
 * There is deliberately no customer picker here — a payment isn't reassigned
 * to a different customer after the fact (App\DataTransferObjects\
 * PaymentData/UpdatePaymentRequest don't accept a customer field at all). If
 * a payment was recorded against the wrong customer, record a new one
 * instead.
 */
export default function PaymentsEdit({ payment }: PaymentsEditProps) {
    const { data, setData, put, processing, errors } = useForm({
        amount: payment.amount,
        credit: payment.credit,
        frequency: payment.frequency,
        months: payment.months !== null ? String(payment.months) : '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/payments/${payment.uuid}`);
    }

    return (
        <AppLayout
            title="Edit Payment"
            breadcrumbs={[
                { label: 'Payments', href: '/payments' },
                { label: payment.customer_name, href: `/payments/${payment.uuid}` },
                { label: 'Edit' },
            ]}
        >
            <Head title="Edit Payment" />

            <div className="mb-6 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <Link
                    href={`/payments/${payment.uuid}`}
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"
                >
                    <IconArrowLeft size={16} stroke={2} />
                    Back to Payment
                </Link>
                <h2 className="mt-2 font-display text-2xl text-slate-900">Edit Payment</h2>
                <p className="mt-1 text-sm text-slate-500">{payment.customer_name}</p>
            </div>

            <form onSubmit={submit} className="max-w-2xl animate-fade-up" style={{ animationDelay: '100ms' }}>
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Payment details</h3>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-4">
                        <p className="text-xs text-slate-500">
                            Correcting a recorded payment. The customer this payment belongs to can&apos;t be changed here — record a new
                            payment instead if it was recorded against the wrong customer.
                        </p>

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

                        <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                            <Link href={`/payments/${payment.uuid}`}>
                                <Button type="button" variant="secondary" className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                {processing && <LoadingSpinner className="h-4 w-4" />}
                                {processing ? 'Saving…' : 'Save Changes'}
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            </form>
        </AppLayout>
    );
}
