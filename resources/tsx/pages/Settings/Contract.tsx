import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconCircleCheck, IconDownload, IconSignature } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { LegalBody } from '@/components/shared/LegalBody';

interface ContractSignatureRow {
    id: number;
    version: number;
    signer_name: string;
    signer_title: string | null;
    signed_at: string;
}

interface SettingsContractProps {
    contract: { version: number; published_at: string | null; change_note: string | null; body_html: string } | null;
    needs_signature: boolean;
    default_name: string;
    signatures: ContractSignatureRow[];
}

/**
 * Settings → Contract (App\Http\Controllers\ContractController): the
 * OneTech service contract with the company's details filled in, signed
 * online by typing a name; every signed version stays downloadable as PDF.
 */
export default function SettingsContract({ contract, needs_signature, default_name, signatures }: SettingsContractProps) {
    const { data, setData, post, processing, errors } = useForm({ signer_name: default_name, signer_title: '', agree: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/settings/contract/sign', { preserveScroll: true });
    }

    return (
        <AppLayout title="Settings" breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Contract' }]}>
            <Head title="Settings — Contract" />

            <SettingsTabs active="contract" />

            <div className="grid max-w-4xl gap-6">
                {contract === null ? (
                    <Card className="animate-fade-up">
                        <CardBody>
                            <p className="text-sm text-slate-600">OneTech has not published a service contract yet. Nothing to sign for now.</p>
                        </CardBody>
                    </Card>
                ) : (
                    <Card className="animate-fade-up">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <IconSignature size={18} stroke={1.75} className="text-indigo-600" />
                                <p className="font-medium text-slate-900">Service contract with OneTech</p>
                            </div>
                            <p className="mt-1 text-sm text-slate-500">
                                Version {contract.version}
                                {contract.published_at && ` · published ${new Date(contract.published_at).toLocaleDateString(undefined, { dateStyle: 'long' })}`}
                            </p>
                        </CardHeader>
                        <CardBody>
                            {needs_signature && contract.change_note && signatures.length > 0 && (
                                <p className="mb-4 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-900">
                                    <span className="font-semibold">What changed: </span>
                                    {contract.change_note}
                                </p>
                            )}

                            <div className="max-h-[50vh] overflow-y-auto rounded-lg p-4 ring-1 ring-slate-200">
                                <LegalBody html={contract.body_html} />
                            </div>

                            {needs_signature ? (
                                <form onSubmit={submit} className="mt-5 flex flex-col gap-4 border-t border-slate-100 pt-5">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <TextInput
                                            label="Your full name"
                                            hint="Typing your name is your signature."
                                            value={data.signer_name}
                                            onChange={(event) => setData('signer_name', event.target.value)}
                                            error={errors.signer_name}
                                            required
                                        />
                                        <TextInput
                                            label="Your position (optional)"
                                            placeholder="e.g. Owner, Director"
                                            value={data.signer_title}
                                            onChange={(event) => setData('signer_title', event.target.value)}
                                            error={errors.signer_title}
                                        />
                                    </div>
                                    <label className="flex items-start gap-2.5 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={data.agree}
                                            onChange={(event) => setData('agree', event.target.checked)}
                                            className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        />
                                        <span>I have read this contract, I agree to it, and I am authorised to sign it for my company.</span>
                                    </label>
                                    {errors.agree && <p className="-mt-2 text-xs text-red-600">{errors.agree}</p>}
                                    <div>
                                        <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                            {processing && <LoadingSpinner className="mr-1.5 text-white" />}
                                            Sign the contract
                                        </Button>
                                    </div>
                                    <p className="text-xs text-slate-500">
                                        We record your name, the date and time, and your IP address with the signed text, and keep a PDF copy for you and for OneTech.
                                    </p>
                                </form>
                            ) : (
                                <p className="mt-5 flex items-center gap-2 border-t border-slate-100 pt-5 text-sm text-green-700">
                                    <IconCircleCheck size={18} stroke={1.75} />
                                    Your company has signed this version.
                                </p>
                            )}
                        </CardBody>
                    </Card>
                )}

                {signatures.length > 0 && (
                    <Card className="animate-fade-up" style={{ animationDelay: '80ms' }}>
                        <CardHeader>
                            <p className="font-medium text-slate-900">Signed copies</p>
                        </CardHeader>
                        <ul className="divide-y divide-slate-100">
                            {signatures.map((signature) => (
                                <li key={signature.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                    <div className="min-w-0 text-sm">
                                        <p className="font-medium text-slate-900">Version {signature.version}</p>
                                        <p className="truncate text-slate-500">
                                            {signature.signer_name}
                                            {signature.signer_title && `, ${signature.signer_title}`} ·{' '}
                                            {new Date(signature.signed_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                        </p>
                                    </div>
                                    <a
                                        href={`/settings/contract/${signature.id}/pdf`}
                                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-50"
                                    >
                                        <IconDownload size={16} stroke={1.75} />
                                        PDF
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
