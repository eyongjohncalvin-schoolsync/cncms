import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { PageProps } from '@/types';

/**
 * Passwordless sign-in (App\Http\Controllers\LoginCodeController): step 1
 * asks for the username/email and emails a 6-digit code; step 2 (once
 * `identifier` is remembered in the session) takes the code. The server
 * answers step 1 the same way whether or not the account exists.
 */
export default function LoginCode({ identifier }: { identifier: string | null }) {
    const { flash } = usePage<PageProps>().props;

    return (
        <AuthLayout>
            <Head title="Sign in with an email code" />
            <div className="animate-fade-up mb-7">
                <h2 className="font-display text-3xl text-slate-900">Sign in with a code</h2>
                <p className="mt-1.5 text-sm text-slate-500">
                    {identifier ? `Enter the 6-digit code we emailed for ${identifier}.` : "We'll email you a 6-digit code — no password needed."}
                </p>
            </div>

            {flash.success && (
                <div className="mb-5 rounded-md border-l-4 border-green-500 bg-green-50 px-4 py-3 text-sm text-green-800">{flash.success}</div>
            )}

            {identifier ? <CodeStep identifier={identifier} /> : <IdentifierStep />}

            <p className="mt-7 text-sm text-slate-500">
                <Link href="/login" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Sign in with a password instead
                </Link>
            </p>
        </AuthLayout>
    );
}

function IdentifierStep() {
    const { data, setData, post, processing, errors } = useForm({ identifier: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login/code');
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

function CodeStep({ identifier }: { identifier: string }) {
    const { data, setData, post, processing, errors } = useForm({ identifier, code: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login/code/verify', { onError: () => setData('code', '') });
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
                <Button type="submit" disabled={processing || data.code.trim() === ''} className="w-full rounded-lg py-2.5 text-base font-semibold">
                    {processing ? (
                        <>
                            <LoadingSpinner className="text-white" />
                            <span>Signing in…</span>
                        </>
                    ) : (
                        'Sign in'
                    )}
                </Button>
            </form>
            <div className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <button
                    type="button"
                    onClick={() => router.post('/login/code', { identifier })}
                    className="font-medium text-blue-600 hover:text-blue-700"
                >
                    Send a new code
                </button>
                <button type="button" onClick={() => router.delete('/login/code')} className="text-slate-500 hover:text-slate-700">
                    Use a different account
                </button>
            </div>
        </>
    );
}
