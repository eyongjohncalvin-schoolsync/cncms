import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconFileCertificate } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { LegalBody } from '@/components/shared/LegalBody';

/**
 * New Terms for the company to accept (App\Http\Controllers\
 * LegalAcceptanceController). Shown to the company's super/admin only;
 * one acceptance covers the whole company.
 */
export default function LegalAccept({
    version,
    published_at,
    change_note,
    body_html,
    can_accept,
}: {
    version: number;
    published_at: string | null;
    change_note: string | null;
    body_html: string;
    can_accept: boolean;
}) {
    const { data, setData, post, processing, errors } = useForm({ accept: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/legal/accept');
    }

    return (
        <AuthLayout>
            <Head title="New Terms of service" />

            <div className="animate-fade-up mb-5 flex items-start gap-3">
                <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700">
                    <IconFileCertificate size={20} stroke={1.75} />
                </span>
                <div>
                    <h2 className="font-display text-3xl text-slate-900">New Terms of service</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Version {version}
                        {published_at && ` · published ${new Date(published_at).toLocaleDateString(undefined, { dateStyle: 'long' })}`}
                    </p>
                </div>
            </div>

            {change_note && (
                <p className="mb-4 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-900">
                    <span className="font-semibold">What changed: </span>
                    {change_note}
                </p>
            )}

            <div className="max-h-[45vh] overflow-y-auto rounded-lg p-4 ring-1 ring-slate-200">
                <LegalBody html={body_html} />
            </div>

            {can_accept ? (
                <form onSubmit={submit} className="mt-5 flex flex-col gap-4">
                    <label className="flex items-start gap-2.5 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={data.accept}
                            onChange={(event) => setData('accept', event.target.checked)}
                            className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                        />
                        <span>I have read these Terms and accept them for my company.</span>
                    </label>
                    {errors.accept && <p className="-mt-2 text-xs text-red-600">{errors.accept}</p>}
                    <Button type="submit" disabled={processing} className="w-full rounded-lg py-2.5 text-base font-semibold">
                        {processing && <LoadingSpinner className="text-white" />}
                        Accept and continue
                    </Button>
                </form>
            ) : (
                <p className="mt-5 text-sm text-slate-600">Your company's administrator needs to accept these Terms.</p>
            )}

            <p className="mt-5 text-sm text-slate-500">
                Questions? Contact OneTech on <a href="/contact" className="text-blue-600 hover:underline">our contact page</a>, or{' '}
                <Link href="/logout" method="post" as="button" className="text-blue-600 hover:underline">
                    log out
                </Link>
                .
            </p>
        </AuthLayout>
    );
}
