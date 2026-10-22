import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconDeviceMobile, IconKey, IconMail } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { PageProps } from '@/types';

type Method = 'totp' | 'recovery' | 'email';

const COPY: Record<Method, { title: string; help: (hint: string) => string; label: string; placeholder: string }> = {
    totp: {
        title: 'Two-factor authentication',
        help: () => 'Open your authenticator app and enter the 6-digit code for CNCMS.',
        label: 'Authentication code',
        placeholder: '123456',
    },
    recovery: {
        title: 'Use a recovery code',
        help: () => 'Enter one of the recovery codes you saved when you turned on two-factor. Each code works once.',
        label: 'Recovery code',
        placeholder: 'ABCDE-FGHJK',
    },
    email: {
        title: 'Check your email',
        help: (hint) => `Enter the 6-digit code we emailed to ${hint}.`,
        label: 'Email code',
        placeholder: '123456',
    },
};

/**
 * Second sign-in step for users with two-factor on
 * (App\Http\Controllers\TwoFactorChallengeController). The user is not
 * signed in yet: the pending challenge lives in the session for 10 minutes.
 * `methods` drops "email" when the first factor was itself an email code.
 */
export default function TwoFactorChallenge({ methods, emailHint }: { methods: Method[]; emailHint: string }) {
    const { flash } = usePage<PageProps>().props;
    const [method, setMethod] = useState<Method>('totp');
    const [sending, setSending] = useState(false);
    const { data, setData, post, processing, errors, clearErrors } = useForm({ method: 'totp' as Method, code: '' });

    function switchTo(next: Method) {
        setMethod(next);
        setData({ method: next, code: '' });
        clearErrors();
    }

    function sendEmailCode() {
        setSending(true);
        router.post(
            '/two-factor-challenge/email',
            {},
            {
                preserveState: true,
                onSuccess: () => switchTo('email'),
                onFinish: () => setSending(false),
            },
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/two-factor-challenge', { preserveState: true, onError: () => setData('code', '') });
    }

    const copy = COPY[method];

    return (
        <AuthLayout>
            <Head title="Two-factor authentication" />
            <div className="animate-fade-up mb-7">
                <h2 className="font-display text-3xl text-slate-900">{copy.title}</h2>
                <p className="mt-1.5 text-sm text-slate-500">{copy.help(emailHint)}</p>
            </div>

            {flash.success && (
                <div className="mb-5 rounded-md border-l-4 border-green-500 bg-green-50 px-4 py-3 text-sm text-green-800">{flash.success}</div>
            )}

            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5" style={{ animationDelay: '80ms' }}>
                <TextInput
                    id="code"
                    label={copy.label}
                    autoFocus
                    autoComplete="one-time-code"
                    inputMode={method === 'recovery' ? 'text' : 'numeric'}
                    placeholder={copy.placeholder}
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                    error={errors.code ?? errors.method}
                    className="px-3.5 py-2.5 font-mono tracking-widest"
                />
                <Button type="submit" disabled={processing || data.code.trim() === ''} className="w-full rounded-lg py-2.5 text-base font-semibold">
                    {processing ? (
                        <>
                            <LoadingSpinner className="text-white" />
                            <span>Checking…</span>
                        </>
                    ) : (
                        'Continue'
                    )}
                </Button>
            </form>

            <div className="mt-7 flex flex-col gap-2 border-t border-slate-100 pt-5 text-sm">
                {method !== 'totp' && (
                    <button type="button" onClick={() => switchTo('totp')} className="flex items-center gap-2 text-left font-medium text-blue-600 hover:text-blue-700">
                        <IconDeviceMobile size={16} stroke={1.75} /> Use my authenticator app
                    </button>
                )}
                {method !== 'recovery' && methods.includes('recovery') && (
                    <button type="button" onClick={() => switchTo('recovery')} className="flex items-center gap-2 text-left font-medium text-blue-600 hover:text-blue-700">
                        <IconKey size={16} stroke={1.75} /> Use a recovery code
                    </button>
                )}
                {methods.includes('email') && (
                    <button
                        type="button"
                        onClick={sendEmailCode}
                        disabled={sending}
                        className="flex items-center gap-2 text-left font-medium text-blue-600 hover:text-blue-700 disabled:opacity-50"
                    >
                        <IconMail size={16} stroke={1.75} />
                        {method === 'email' ? 'Send a new code' : `Lost your phone? Email me a code at ${emailHint}`}
                    </button>
                )}
                <Link href="/login" className="mt-2 text-slate-500 hover:text-slate-700 hover:underline">
                    Cancel and start over
                </Link>
            </div>
        </AuthLayout>
    );
}
