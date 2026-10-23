import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconShieldOff } from '@tabler/icons-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

export interface ResetTwoFactorTarget {
    uuid: string;
    name: string;
}

/**
 * Landlord "Reset 2FA" confirmation (App\Http\Controllers\Landlord\
 * UserController::resetTwoFactor()): asks for the reason, which goes to the
 * landlord activity log. Used on /landlord/users and the tenant Members list.
 */
export function ResetTwoFactorModal({ target, onClose }: { target: ResetTwoFactorTarget | null; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ reason: '' });

    function close() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!target) {
            return;
        }

        post(`/landlord/users/${target.uuid}/two-factor/reset`, {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    }

    return (
        <Modal open={target !== null} onClose={close} title={target ? `Reset two-factor for ${target.name}?` : undefined}>
            {target && (
                <form onSubmit={submit}>
                    <div className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <IconShieldOff size={26} stroke={1.75} />
                    </div>
                    <p className="text-sm text-slate-600">
                        Only do this after confirming who you are talking to. Their authenticator and recovery codes stop working, they are signed out of
                        every browser and phone, and they get an email. They can then sign in with just their password and turn two-factor back on.
                    </p>
                    <div className="mt-4">
                        <label htmlFor="reset-two-factor-reason" className="mb-1 block text-sm font-medium text-slate-700">
                            Reason (required)
                        </label>
                        <textarea
                            id="reset-two-factor-reason"
                            value={data.reason}
                            onChange={(event) => setData('reason', event.target.value)}
                            rows={3}
                            className="w-full rounded-lg border-0 px-3.5 py-2.5 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 focus:outline-none"
                            placeholder="e.g. Lost phone, identity confirmed by a call to the workspace owner."
                        />
                        {errors.reason && <p className="mt-1 text-sm text-red-600">{errors.reason}</p>}
                    </div>
                    <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <Button type="button" variant="secondary" onClick={close} disabled={processing}>
                            Cancel
                        </Button>
                        <button
                            type="submit"
                            disabled={processing || data.reason.trim() === ''}
                            className="inline-flex items-center justify-center gap-1.5 rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-amber-700 disabled:opacity-50"
                        >
                            {processing && <LoadingSpinner className="text-white" />}
                            {processing ? 'Resetting…' : 'Reset 2FA'}
                        </button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
