import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface ReversePaymentModalProps {
    open: boolean;
    onClose: () => void;
    payment: {
        uuid: string;
        customer_name: string;
        amount: string | number;
        handed_in?: boolean;
        purpose?: string;
        verification_status?: string;
        recorded_by_name?: string | null;
    };
    /** When given and allowed: "Give free months instead" (the usual allowance and approval apply). */
    freeMonths?: { can_request: boolean; max_months: number } | null;
}

type MoneyChoice = 'correct' | 'refund' | 'none';

/**
 * Undo a payment that was wrong (PaymentController::reverse()): a verified
 * one is reversed (rejected, receipt voided); a pending one whose collector
 * owes a handover for it is rejected the same way. Either way the money
 * question is answered, so nothing leaves the books without a trace (owner,
 * 2026-10-28):
 *  - record the correct payment — the payment form opens on it; the money
 *    taken stands for the correct one, in the collector's name;
 *  - recorded by mistake, no money was taken — off the collector's
 *    handover; if they already handed cash in for it, a bon gives it back
 *    to them;
 *  - give the money back — a refund bon for the customer, paid by the
 *    cashier once approved.
 */
export function ReversePaymentModal({ open, onClose, payment, freeMonths = null }: ReversePaymentModalProps) {
    const handedIn = payment.handed_in === true;
    const rejecting = payment.verification_status === 'pending';
    const collector = payment.recorded_by_name ?? 'the collector';
    const [choice, setChoice] = useState<MoneyChoice>('correct');
    const [giveFree, setGiveFree] = useState(false);
    const canGiveFree = !rejecting && freeMonths?.can_request === true && (payment.purpose ?? 'subscription') === 'subscription';
    const { data, setData, transform, post, processing, errors, reset, clearErrors } = useForm({ reason: '', money: 'none' as 'none' | 'refund', free_months: '1' });

    function close() {
        if (processing) {
            return;
        }
        reset();
        clearErrors();
        setChoice('correct');
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (choice === 'correct') {
            router.get('/payments/create', { replaces: payment.uuid });

            return;
        }

        transform((values) => ({ ...values, free_months: canGiveFree && giveFree ? values.free_months : null }));
        post(`/payments/${payment.uuid}/reverse`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    const amount = formatCurrency(Number(payment.amount));
    const choices: { key: MoneyChoice; label: string; hint: string }[] = [
        {
            key: 'correct',
            label: 'Record the correct payment',
            hint: handedIn
                ? 'Opens the payment form. The correct payment takes this one’s place in the handover; if it is less, the difference is refunded.'
                : `Opens the payment form. ${collector} still hands in the ${amount}; if the correct payment is less, the difference is refunded.`,
        },
        {
            key: 'none',
            label: 'Recorded by mistake — no money was taken',
            hint: handedIn
                ? `${collector} handed in ${amount} for it: a bon gives that cash back to them once approved.`
                : `It leaves ${collector}'s handover — they no longer owe it.`,
        },
        {
            key: 'refund',
            label: 'Give the money back',
            hint: handedIn
                ? `A refund bon for ${amount} goes for approval; the cashier pays the customer.`
                : `${collector} still hands in the ${amount}; a refund bon pays the customer back once approved.`,
        },
    ];

    return (
        <Modal open={open} onClose={close} title={`${rejecting ? 'Reject' : 'Reverse'} ${payment.customer_name}'s payment?`}>
            <form onSubmit={submit}>
                <p className="text-sm text-slate-600">
                    {rejecting
                        ? `The payment of ${amount} is marked rejected. Say what happened to the money.`
                        : `The payment of ${amount} is marked rejected and its receipt is voided. If a bill already counted it, the bill is corrected. Say what happens to the money.`}
                </p>

                <fieldset className="mt-4 flex flex-col gap-2">
                    {choices.map((option) => (
                        <label
                            key={option.key}
                            className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${choice === option.key ? 'border-blue-500 bg-blue-50' : 'border-slate-200'}`}
                        >
                            <input
                                type="radio"
                                name="money"
                                className="mt-1"
                                checked={choice === option.key}
                                onChange={() => {
                                    setChoice(option.key);
                                    setData('money', option.key === 'refund' ? 'refund' : 'none');
                                }}
                            />
                            <span>
                                <span className="block text-sm font-medium text-slate-900">{option.label}</span>
                                <span className="block text-xs text-slate-500">{option.hint}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>

                {choice !== 'correct' && (
                    <>
                        <label htmlFor="reverse-reason" className="mt-4 block text-sm font-medium text-slate-700">
                            Why is it being {rejecting ? 'rejected' : 'reversed'}?
                        </label>
                        <textarea
                            id="reverse-reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            rows={3}
                            maxLength={500}
                            required
                            className="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. The customer paid twice by mistake"
                        />
                        {errors.reason && <p className="mt-1 text-sm text-red-600">{errors.reason}</p>}

                        {canGiveFree && (
                            <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                <label className="flex items-center gap-2 text-sm font-medium text-emerald-900">
                                    <input type="checkbox" checked={giveFree} onChange={(e) => setGiveFree(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
                                    Give free months instead
                                </label>
                                {giveFree && (
                                    <div className="mt-2 flex items-center gap-2 text-sm text-emerald-900">
                                        <input
                                            type="number"
                                            min={1}
                                            max={freeMonths?.max_months ?? 3}
                                            value={data.free_months}
                                            onChange={(e) => setData('free_months', e.target.value)}
                                            className="w-20 rounded-lg border-slate-300 text-sm"
                                            aria-label="Free months"
                                        />
                                        month(s), after the customer&apos;s existing free and paid months. Beyond the yearly allowance an admin approves.
                                    </div>
                                )}
                                {(errors as Record<string, string | undefined>).free_months && (
                                    <p className="mt-1 text-sm text-red-600">{(errors as Record<string, string | undefined>).free_months}</p>
                                )}
                            </div>
                        )}
                    </>
                )}
                {(errors as Record<string, string | undefined>).money && (
                    <p className="mt-2 text-sm text-red-600">{(errors as Record<string, string | undefined>).money}</p>
                )}

                <div className="mt-6 flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={close} disabled={processing} className="w-full sm:w-auto">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant={choice === 'correct' ? 'primary' : 'danger'}
                        disabled={processing || (choice !== 'correct' && data.reason.trim().length < 3)}
                        className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                    >
                        {processing && <LoadingSpinner className="h-4 w-4" />}
                        {choice === 'correct'
                            ? 'Continue to the payment form'
                            : processing
                              ? rejecting
                                  ? 'Rejecting…'
                                  : 'Reversing…'
                              : rejecting
                                ? 'Reject payment'
                                : 'Reverse payment'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
