import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface ReversePaymentModalProps {
    open: boolean;
    onClose: () => void;
    payment: { uuid: string; customer_name: string; amount: string | number; handed_in?: boolean };
}

type MoneyChoice = 'correct' | 'refund' | 'none';

/**
 * Undo a verified payment that was wrong (PaymentController::reverse()): it
 * becomes rejected and its receipt is voided. What happens to the money:
 *  - record the correct payment — the payment form opens on it and saving
 *    reverses this one too (a handed-in payment's place in the handover
 *    goes to the correct one);
 *  - give the money back — handed-in cash only: a refund bon, paid by the
 *    cashier once approved;
 *  - nothing — the cash is still with the collector (not handed in).
 */
export function ReversePaymentModal({ open, onClose, payment }: ReversePaymentModalProps) {
    const handedIn = payment.handed_in === true;
    const [choice, setChoice] = useState<MoneyChoice>('correct');
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '', money: 'none' as 'none' | 'refund' });

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

        post(`/payments/${payment.uuid}/reverse`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    const choices: { key: MoneyChoice; label: string; hint: string }[] = [
        {
            key: 'correct',
            label: 'Record the correct payment',
            hint: handedIn
                ? 'Opens the payment form. The correct payment takes this one’s place in the handover; if it is less, the difference is refunded.'
                : 'Opens the payment form; saving reverses this one and records the right one.',
        },
        handedIn
            ? { key: 'refund', label: 'Give the money back', hint: `A refund bon for ${formatCurrency(Number(payment.amount))} goes for approval; the cashier pays the customer.` }
            : { key: 'none', label: 'Just reverse it', hint: 'Nothing was collected, or you will record the right payment later.' },
    ];

    return (
        <Modal open={open} onClose={close} title={`Reverse ${payment.customer_name}'s payment?`}>
            <form onSubmit={submit}>
                <p className="text-sm text-slate-600">
                    The payment of {formatCurrency(Number(payment.amount))} is marked rejected and its receipt is voided. If a bill already counted it,
                    the bill is corrected.
                    {handedIn && ' Its cash was already handed in, so say what happens to the money.'}
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
                            Why is it being reversed?
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
                        {choice === 'correct' ? 'Continue to the payment form' : processing ? 'Reversing…' : 'Reverse payment'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
