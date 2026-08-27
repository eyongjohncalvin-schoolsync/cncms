import { Head, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useState } from 'react';
import { IconBrandGoogle } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

/** lowercase, spaces -> hyphens, strip anything that isn't alphanumeric/hyphen */
function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

export default function Register() {
    // Once the visitor edits workspace_slug directly, stop overwriting it
    // from company_name — it must stay editable to let them fix collisions.
    const [slugEdited, setSlugEdited] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        company_name: '',
        company_location: '',
        company_phone: '',
        momo_number: '',
        momo_name: '',
        workspace_slug: '',
    });

    function handleCompanyNameChange(e: ChangeEvent<HTMLInputElement>) {
        const value = e.target.value;
        setData((previous) => ({
            ...previous,
            company_name: value,
            workspace_slug: slugEdited ? previous.workspace_slug : slugify(value),
        }));
    }

    function handleSlugChange(e: ChangeEvent<HTMLInputElement>) {
        setSlugEdited(true);
        setData('workspace_slug', e.target.value);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/register');
    }

    return (
        <AuthLayout>
            <Head title="Sign up" />
            <div className="animate-fade-up mb-6 text-center">
                <h2 className="font-display text-2xl text-slate-900">Create your account</h2>
                <p className="mt-1 text-sm text-slate-500">Set up your workspace in a couple of minutes</p>
            </div>

            <a
                href="/auth/google/redirect"
                className="animate-fade-up inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
                style={{ animationDelay: '100ms' }}
            >
                <IconBrandGoogle size={18} className="text-slate-500" />
                Continue with Google
            </a>

            <div className="my-5 flex items-center">
                <div className="h-px flex-grow bg-slate-200" />
                <span className="mx-3 shrink-0 text-xs font-medium tracking-wide text-slate-400 uppercase">
                    or continue with email
                </span>
                <div className="h-px flex-grow bg-slate-200" />
            </div>

            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-5" style={{ animationDelay: '150ms' }}>
                <div className="flex flex-col gap-4">
                    <div className="flex items-center gap-2">
                        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[11px] font-semibold text-white">
                            1
                        </span>
                        <h3 className="text-xs font-semibold tracking-wide text-slate-500 uppercase">Your account</h3>
                    </div>

                    <TextInput
                        id="name"
                        label="Full name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <TextInput
                        id="email"
                        type="email"
                        label="Email"
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <TextInput
                        id="password"
                        type="password"
                        label="Password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        minLength={8}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                </div>

                <div className="flex flex-col gap-4 border-t border-slate-100 pt-5">
                    <div className="flex items-center gap-2">
                        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[11px] font-semibold text-white">
                            2
                        </span>
                        <h3 className="text-xs font-semibold tracking-wide text-slate-500 uppercase">Your company</h3>
                    </div>

                    <TextInput
                        id="company_name"
                        label="Company name"
                        value={data.company_name}
                        onChange={handleCompanyNameChange}
                        error={errors.company_name}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <TextInput
                        id="company_location"
                        label="Location"
                        value={data.company_location}
                        onChange={(e) => setData('company_location', e.target.value)}
                        error={errors.company_location}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <TextInput
                        id="company_phone"
                        label="Phone"
                        value={data.company_phone}
                        onChange={(e) => setData('company_phone', e.target.value)}
                        error={errors.company_phone}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <TextInput
                        id="momo_number"
                        label="MOMO number (optional)"
                        value={data.momo_number}
                        onChange={(e) => setData('momo_number', e.target.value)}
                        error={errors.momo_number}
                        className="rounded-lg px-3.5 py-2.5"
                    />
                    <TextInput
                        id="momo_name"
                        label="MOMO account name (optional)"
                        value={data.momo_name}
                        onChange={(e) => setData('momo_name', e.target.value)}
                        error={errors.momo_name}
                        className="rounded-lg px-3.5 py-2.5"
                    />
                    <TextInput
                        id="workspace_slug"
                        label="Workspace URL"
                        value={data.workspace_slug}
                        onChange={handleSlugChange}
                        error={errors.workspace_slug}
                        className="rounded-lg px-3.5 py-2.5"
                        required
                    />
                    <p className="-mt-3 text-xs text-slate-400">
                        Used to identify your workspace. Lowercase letters, numbers, and hyphens only — auto-filled
                        from your company name, but you can change it.
                    </p>
                </div>

                <Button
                    type="submit"
                    disabled={processing}
                    className="mt-1 w-full rounded-lg py-2.5 text-base font-semibold"
                >
                    {processing ? (
                        <>
                            <LoadingSpinner className="text-white" />
                            <span>Creating your workspace…</span>
                        </>
                    ) : (
                        'Create workspace'
                    )}
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-slate-500">
                Already have an account?{' '}
                <a href="/login" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Log in
                </a>
            </p>
        </AuthLayout>
    );
}
