import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Button } from '@/components/ui/Button';

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
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextInput
                    id="username"
                    label="Username or email"
                    autoFocus
                    autoComplete="username"
                    value={data.username}
                    onChange={(e) => setData('username', e.target.value)}
                    error={errors.username}
                />
                <TextInput
                    id="password"
                    type="password"
                    label="Password"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                />
                <Button type="submit" disabled={processing} className="mt-2 w-full">
                    Log in
                </Button>
            </form>
        </AuthLayout>
    );
}
