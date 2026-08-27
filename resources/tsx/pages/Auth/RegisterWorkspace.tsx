import { Head, useForm } from '@inertiajs/react';
import { ChangeEvent, FormEvent, useState } from 'react';
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

/**
 * Shown to a user who signed up/logged in via Google and already has a
 * central account, but doesn't belong to a workspace yet. Company/workspace
 * fields only — no name/email/password, they're already authenticated.
 */
export default function RegisterWorkspace() {
    const [slugEdited, setSlugEdited] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
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
        post('/register/workspace');
    }

    return (
        <AuthLayout>
            <Head title="Set up your workspace" />
            <div className="animate-fade-up mb-6 text-center">
                <h2 className="font-display text-2xl text-slate-900">Set up your workspace</h2>
                <p className="mt-1 text-sm text-slate-500">Almost done — tell us about your business</p>
            </div>

            <form onSubmit={submit} className="animate-fade-up flex flex-col gap-4" style={{ animationDelay: '100ms' }}>
                <TextInput
                    id="company_name"
                    label="Company name"
                    autoFocus
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
                <p className="-mt-2 text-xs text-slate-400">
                    Used to identify your workspace. Lowercase letters, numbers, and hyphens only — auto-filled from
                    your company name, but you can change it.
                </p>

                <Button
                    type="submit"
                    disabled={processing}
                    className="mt-2 w-full rounded-lg py-2.5 text-base font-semibold"
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
        </AuthLayout>
    );
}
