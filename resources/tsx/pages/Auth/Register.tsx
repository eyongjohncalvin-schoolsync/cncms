import { Head, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useEffect, useState } from 'react';
import { IconArrowLeft, IconBrandGoogle, IconChevronDown } from '@tabler/icons-react';
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

const ACCOUNT_FIELDS = ['name', 'email', 'password'] as const;

export default function Register() {
    // Single <form>, single POST — `step` only controls how much is on
    // screen at once, so the page never scrolls a full-height form.
    const [step, setStep] = useState<1 | 2>(1);
    const [showMomo, setShowMomo] = useState(false);
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

    // A server-side validation error on an account field means the user
    // can't see it from step 2 — bounce them back.
    useEffect(() => {
        if (ACCOUNT_FIELDS.some((f) => errors[f])) {
            setStep(1);
        }
    }, [errors]);

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

    function goToStep2() {
        // Let the browser surface its own "required" / type / minlength
        // messages before advancing.
        const form = document.getElementById('register-form') as HTMLFormElement | null;
        const invalid = ACCOUNT_FIELDS.some((f) => {
            const el = form?.elements.namedItem(f) as HTMLInputElement | null;
            return el ? !el.reportValidity() : false;
        });
        if (!invalid) {
            setStep(2);
        }
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/register');
    }

    const field = 'px-3.5 py-2.5';

    return (
        <AuthLayout>
            <Head title="Sign up" />

            <div className="animate-fade-up mb-5">
                <p className="text-xs font-semibold tracking-wide text-blue-600 uppercase">Step {step} of 2</p>
                <h2 className="font-display mt-1 text-3xl text-slate-900">
                    {step === 1 ? 'Create your account' : 'About your company'}
                </h2>
                <p className="mt-1 text-sm text-slate-500">
                    {step === 1
                        ? 'This is the account you sign in with.'
                        : 'The details that appear on your bills and the workspace URL.'}
                </p>
            </div>

            {step === 1 && (
                <>
                    <a
                        href="/auth/google/redirect"
                        className="animate-fade-up inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 transition-colors hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400"
                    >
                        <IconBrandGoogle size={18} className="text-slate-500" />
                        Continue with Google
                    </a>
                    <div className="my-4 flex items-center">
                        <div className="h-px flex-grow bg-slate-200" />
                        <span className="mx-3 shrink-0 text-xs font-medium tracking-wide text-slate-400 uppercase">
                            or with email
                        </span>
                        <div className="h-px flex-grow bg-slate-200" />
                    </div>
                </>
            )}

            <form id="register-form" onSubmit={submit} className="animate-fade-up flex flex-col gap-4">
                {/* Step 1 stays mounted (just hidden) on step 2 so its values are
                    still submitted with the single POST. */}
                <div className={step === 1 ? 'flex flex-col gap-4' : 'hidden'}>
                    <TextInput
                        id="name"
                        name="name"
                        label="Full name"
                        autoFocus
                        autoComplete="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        className={field}
                        required={step === 1}
                    />
                    <TextInput
                        id="email"
                        name="email"
                        type="email"
                        label="Email"
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        error={errors.email}
                        className={field}
                        required={step === 1}
                    />
                    <PasswordInput
                        id="password"
                        name="password"
                        label="Password"
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        error={errors.password}
                        minLength={8}
                        className="py-2.5"
                        required={step === 1}
                    />
                    <Button
                        type="button"
                        onClick={goToStep2}
                        className="mt-1 w-full rounded-lg py-2.5 text-base font-semibold"
                    >
                        Continue
                    </Button>
                </div>

                <div className={step === 2 ? 'flex flex-col gap-4' : 'hidden'}>
                    <TextInput
                        id="company_name"
                        label="Company name"
                        value={data.company_name}
                        onChange={handleCompanyNameChange}
                        error={errors.company_name}
                        className={field}
                        required={step === 2}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            id="company_location"
                            label="Location"
                            value={data.company_location}
                            onChange={(e) => setData('company_location', e.target.value)}
                            error={errors.company_location}
                            className={field}
                            required={step === 2}
                        />
                        <TextInput
                            id="company_phone"
                            label="Phone"
                            value={data.company_phone}
                            onChange={(e) => setData('company_phone', e.target.value)}
                            error={errors.company_phone}
                            className={field}
                            required={step === 2}
                        />
                    </div>

                    <TextInput
                        id="workspace_slug"
                        label="Workspace URL"
                        value={data.workspace_slug}
                        onChange={handleSlugChange}
                        error={errors.workspace_slug}
                        className={field}
                        required={step === 2}
                        hint="Lowercase letters, numbers, and hyphens. Auto-filled from your company name."
                    />

                    {/* MOMO is optional — kept out of the default flow so the
                        step isn't longer than it needs to be. */}
                    <div className="rounded-lg ring-1 ring-inset ring-slate-200">
                        <button
                            type="button"
                            onClick={() => setShowMomo((v) => !v)}
                            className="flex w-full items-center justify-between px-3.5 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:text-slate-900"
                        >
                            Add Mobile Money details (optional)
                            <IconChevronDown
                                size={16}
                                className={`transition-transform ${showMomo ? 'rotate-180' : ''}`}
                            />
                        </button>
                        {showMomo && (
                            <div className="grid gap-4 border-t border-slate-100 px-3.5 py-3.5 sm:grid-cols-2">
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
                        )}
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
                    <button
                        type="button"
                        onClick={() => setStep(1)}
                        className="inline-flex items-center justify-center gap-1 text-sm font-medium text-slate-500 transition-colors hover:text-slate-800"
                    >
                        <IconArrowLeft size={15} />
                        Back to account details
                    </button>
                </div>
            </form>

            {step === 1 && (
                <p className="mt-6 text-sm text-slate-500">
                    Already have an account?{' '}
                    <a href="/login" className="font-medium text-blue-600 hover:text-blue-700 hover:underline">
                        Log in
                    </a>
                </p>
            )}
        </AuthLayout>
    );
}
