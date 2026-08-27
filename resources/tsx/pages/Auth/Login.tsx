import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
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
            <div className="animate-fade-up mb-6 text-center">
                <h2 className="font-display text-2xl text-slate-900">Welcome back</h2>
                <p className="mt-1 text-sm text-slate-500">Sign in to your workspace to continue</p>
            </div>
            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5" style={{ animationDelay: '100ms' }}>
                <TextInput
                    id="username"
                    label="Username or email"
                    autoFocus
                    autoComplete="username"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                    error={errors.username}
                    className="rounded-lg px-3.5 py-2.5"
                />
                <TextInput
                    id="password"
                    type="password"
                    label="Password"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    className="rounded-lg px-3.5 py-2.5"
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
            <p className="mt-6 text-center text-sm text-slate-500">
                New workspace?{' '}
                <a href="/register" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Sign up
                </a>
            </p>
        </AuthLayout>
    );
}
