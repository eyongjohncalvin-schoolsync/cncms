import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { formatCurrency } from '@/lib/formatCurrency';

interface ReversePaymentModalProps {
    open: boolean;
    onClose: () => void;
    payment: { uuid: string; customer_name: string; amount: string | number };
}

/**
 * Undo a verified payment that was wrong (PaymentController::reverse()):
 * it becomes rejected and its receipt is voided. The correct payment is then
 * recorded again.
 */
export function ReversePaymentModal({ open, onClose, payment }: ReversePaymentModalProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });

    function close() {
        if (processing) {
            return;
        }
        reset();
        clearErrors();
        onClose();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post(`/payments/${payment.uuid}/reverse`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    return (
        <Modal open={open} onClose={close} title={`Reverse ${payment.customer_name}'s payment?`}>
            <form onSubmit={submit}>
                <p className="text-sm text-slate-600">
                    The payment of {formatCurrency(Number(payment.amount))} is marked rejected and its receipt is voided. If a bill already
                    counted it, an arrears adjustment adding it back goes for approval. Record the correct payment afterwards.
                </p>
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
                    placeholder="e.g. Wrong amount entered — customer paid 5,000"
                />
                {errors.reason && <p className="mt-1 text-sm text-red-600">{errors.reason}</p>}
                <div className="mt-6 flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                    <Button type="button" variant="secondary" onClick={close} disabled={processing} className="w-full sm:w-auto">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="danger"
                        disabled={processing || data.reason.trim().length < 3}
                        className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold sm:w-auto"
                    >
                        {processing && <LoadingSpinner className="h-4 w-4" />}
                        {processing ? 'Reversing…' : 'Reverse payment'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
