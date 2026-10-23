import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { PageProps } from '@/types';

/**
 * "Forgot password?" (App\Http\Controllers\PasswordResetController): step 1
 * asks for the username/email and emails a 6-digit code; step 2 (once
 * `identifier` is remembered in the session) takes the code and the new
 * password. The server answers step 1 the same way whether or not the
 * account exists.
 */
export default function ForgotPassword({ identifier }: { identifier: string | null }) {
    const { flash } = usePage<PageProps>().props;

    return (
        <AuthLayout>
            <Head title="Reset your password" />
            <div className="animate-fade-up mb-7">
                <h2 className="font-display text-3xl text-slate-900">Reset your password</h2>
                <p className="mt-1.5 text-sm text-slate-500">
                    {identifier
                        ? `Enter the 6-digit code we emailed for ${identifier} and choose a new password.`
                        : "Enter your username or email. We'll email you a 6-digit code to set a new password."}
                </p>
            </div>

            {flash.success && (
                <div className="mb-5 rounded-md border-l-4 border-green-500 bg-green-50 px-4 py-3 text-sm text-green-800">{flash.success}</div>
            )}

            {identifier ? <ResetStep identifier={identifier} /> : <IdentifierStep />}

            <p className="mt-7 text-sm text-slate-500">
                <Link href="/login" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}

function IdentifierStep() {
    const { data, setData, post, processing, errors } = useForm({ identifier: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5">
            <TextInput
                id="identifier"
                label="Username or email"
                autoFocus
                autoComplete="username"
                value={data.identifier}
                onChange={(e) => setData('identifier', e.target.value)}
                error={errors.identifier}
                className="px-3.5 py-2.5"
            />
            <Button type="submit" disabled={processing || data.identifier.trim() === ''} className="w-full rounded-lg py-2.5 text-base font-semibold">
                {processing ? (
                    <>
                        <LoadingSpinner className="text-white" />
                        <span>Sending…</span>
                    </>
                ) : (
                    'Email me a code'
                )}
            </Button>
        </form>
    );
}

function ResetStep({ identifier }: { identifier: string }) {
    const { data, setData, post, processing, errors } = useForm({ identifier, code: '', password: '', password_confirmation: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/reset-password', { onError: () => setData('code', '') });
    }

    return (
        <>
            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5">
                <TextInput
                    id="code"
                    label="Code"
                    autoFocus
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    placeholder="123456"
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                    error={errors.code}
                    className="px-3.5 py-2.5 font-mono tracking-widest"
                />
                <PasswordInput
                    id="password"
                    label="New password"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    className="py-2.5"
                />
                <PasswordInput
                    id="password_confirmation"
                    label="Confirm new password"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                    className="py-2.5"
                />
                <Button
                    type="submit"
                    disabled={processing || data.code.trim() === '' || data.password === ''}
                    className="w-full rounded-lg py-2.5 text-base font-semibold"
                >
                    {processing ? (
                        <>
                            <LoadingSpinner className="text-white" />
                            <span>Saving…</span>
                        </>
                    ) : (
                        'Set new password'
                    )}
                </Button>
            </form>
            <p className="mt-3 text-xs text-slate-500">Setting a new password signs you out of every browser and phone.</p>
            <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <button
                    type="button"
                    onClick={() => router.post('/forgot-password', { identifier })}
                    className="font-medium text-blue-600 hover:text-blue-700"
                >
                    Send a new code
                </button>
                <button type="button" onClick={() => router.delete('/forgot-password')} className="text-slate-500 hover:text-slate-700">
                    Use a different account
                </button>
            </div>
        </>
    );
}
