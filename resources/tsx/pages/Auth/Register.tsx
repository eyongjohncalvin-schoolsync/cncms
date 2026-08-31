import { Head, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useState } from 'react';
import { IconBrandGoogle } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { TextInput } from '@/components/ui/TextInput';
import { PasswordInput } from '@/components/ui/PasswordInput';
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

function StepHeading({ n, children }: { n: number; children: string }) {
    return (
        <div className="flex items-center gap-2">
            <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[11px] font-semibold text-white">
                {n}
            </span>
            <h3 className="text-xs font-semibold tracking-wide text-slate-500 uppercase">{children}</h3>
        </div>
    );
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

    const field = 'px-3.5 py-2.5';

    return (
        <AuthLayout>
            <Head title="Sign up" />
            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-3xl text-slate-900">Create your workspace</h2>
                <p className="mt-1.5 text-sm text-slate-500">
                    Your account and your company, set up in a couple of minutes.
                </p>
            </div>

            <a
                href="/auth/google/redirect"
                className="animate-fade-up inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
                style={{ animationDelay: '80ms' }}
            >
                <IconBrandGoogle size={18} className="text-slate-500" />
                Continue with Google
            </a>

            <div className="my-5 flex items-center">
                <div className="h-px flex-grow bg-slate-200" />
                <span className="mx-3 shrink-0 text-xs font-medium tracking-wide text-slate-400 uppercase">
                    or with email
                </span>
                <div className="h-px flex-grow bg-slate-200" />
            </div>

            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-6" style={{ animationDelay: '130ms' }}>
                <div className="flex flex-col gap-4">
                    <StepHeading n={1}>Your account</StepHeading>

                    <TextInput
                        id="name"
                        label="Full name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        className={field}
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
                        className={field}
                        required
                    />
                    <PasswordInput
                        id="password"
                        label="Password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        minLength={8}
                        className="py-2.5"
                        required
                    />
                </div>

                <div className="flex flex-col gap-4 border-t border-slate-100 pt-6">
                    <StepHeading n={2}>Your company</StepHeading>

                    <TextInput
                        id="company_name"
                        label="Company name"
                        value={data.company_name}
                        onChange={handleCompanyNameChange}
                        error={errors.company_name}
                        className={field}
                        required
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            id="company_location"
                            label="Location"
                            value={data.company_location}
                            onChange={(e) => setData('company_location', e.target.value)}
                            error={errors.company_location}
                            className={field}
                            required
                        />
                        <TextInput
                            id="company_phone"
                            label="Phone"
                            value={data.company_phone}
                            onChange={(e) => setData('company_phone', e.target.value)}
                            error={errors.company_phone}
                            className={field}
                            required
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            id="momo_number"
                            label="MOMO number"
                            value={data.momo_number}
                            onChange={(e) => setData('momo_number', e.target.value)}
                            error={errors.momo_number}
                            className={field}
                        />
                        <TextInput
                            id="momo_name"
                            label="MOMO account name"
                            value={data.momo_name}
                            onChange={(e) => setData('momo_name', e.target.value)}
                            error={errors.momo_name}
                            className={field}
                        />
                    </div>
                    <p className="-mt-2 text-xs text-slate-400">
                        MOMO details are optional — you can add them later in Settings.
                    </p>

                    <div className="flex flex-col gap-1.5">
                        <TextInput
                            id="workspace_slug"
                            label="Workspace URL"
                            value={data.workspace_slug}
                            onChange={handleSlugChange}
                            error={errors.workspace_slug}
                            className={field}
                            required
                        />
                        <p className="text-xs text-slate-400">
                            Identifies your workspace — lowercase letters, numbers, and hyphens. Auto-filled from
                            your company name; change it if you like.
                        </p>
                    </div>
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

            <p className="mt-6 text-sm text-slate-500">
                Already have an account?{' '}
                <a href="/login" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                    Log in
                </a>
            </p>
        </AuthLayout>
    );
}
