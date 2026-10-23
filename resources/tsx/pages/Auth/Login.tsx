import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconBrandGoogle, IconMail } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { PasswordInput } from '@/components/ui/PasswordInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <AuthLayout>
            <Head title="Log in" />
            <div className="animate-fade-up mb-7">
                <h2 className="font-display text-3xl text-slate-900">Welcome back</h2>
                <p className="mt-1.5 text-sm text-slate-500">Sign in to your workspace to continue.</p>
            </div>

            <a
                href="/auth/google/redirect"
                className="animate-fade-up inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
            >
                <IconBrandGoogle size={18} className="text-slate-500" />
                Continue with Google
            </a>
            <div className="my-4 flex items-center">
                <div className="h-px flex-grow bg-slate-200" />
                <span className="mx-3 shrink-0 text-xs font-medium tracking-wide text-slate-400 uppercase">or with your username</span>
                <div className="h-px flex-grow bg-slate-200" />
            </div>

            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5" style={{ animationDelay: '80ms' }}>
                <TextInput
                    id="username"
                    label="Username or email"
                    autoFocus
                    autoComplete="username"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                    error={errors.username}
                    className="px-3.5 py-2.5"
                />
                <PasswordInput
                    id="password"
                    label="Password"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    className="py-2.5"
                />
                <Button type="submit" disabled={processing} className="mt-2 w-full rounded-lg py-2.5 text-base font-semibold">
                    {processing ? (
                        <>
                            <LoadingSpinner className="text-white" />
                            <span>Logging in…</span>
                        </>
                    ) : (
                        'Log in'
                    )}
                </Button>
            </form>

            <div className="mt-5">
                <Link
                    href="/login/code"
                    className="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline"
                >
                    <IconMail size={16} stroke={1.75} />
                    Email me a sign-in code instead
                </Link>
            </div>

            <p className="mt-7 text-sm text-slate-500">
                New workspace?{' '}
                <a href="/register" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Sign up
                </a>
            </p>
        </AuthLayout>
    );
}
