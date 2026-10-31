import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { IconExternalLink, IconFileCertificate } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Table, TableHead, TableBody, Th, Td } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

interface VersionShape {
    version: number;
    status: string;
    body_en: string;
    body_fr: string;
    change_note: string;
    requires_acceptance: boolean;
    published_at: string | null;
}

interface HistoryRow {
    version: number;
    status: 'published' | 'retired';
    published_at: string | null;
    published_by: string | null;
    change_note: string | null;
    requires_acceptance: boolean;
    acceptances: number;
}

interface AcceptanceRow {
    company: string;
    user: string | null;
    accepted_at: string;
    ip_address: string | null;
}

const CONTRACT_BLANKS = ['{company_name}', '{company_location}', '{owner_name}', '{demo_start}', '{demo_end}', '{today}'];

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

type EditProps = {
    type: string;
    label: string;
    current: VersionShape | null;
    draft: VersionShape | null;
    history: HistoryRow[];
    acceptances: AcceptanceRow[];
    waiting: string[];
};

/**
 * Landlord → Legal documents → one document (App\Http\Controllers\
 * Landlord\LegalDocumentController::edit): edit the draft in both
 * languages, preview it as it will look on cncms.tech, publish, and see
 * the history and who accepted the live version.
 *
 * Keyed on the draft/live version, so the form starts over from the right
 * text after a first save, a publish or a discard.
 */
export default function LandlordLegalEditPage(props: EditProps) {
    return <LandlordLegalEdit key={props.draft ? `draft-${props.draft.version}` : `live-${props.current?.version ?? 0}`} {...props} />;
}

function LandlordLegalEdit({
    type,
    label,
    current,
    draft,
    history,
    acceptances,
    waiting,
}: EditProps) {
    const source = draft ?? current;
    const [language, setLanguage] = useState<'en' | 'fr'>('en');
    const [busy, setBusy] = useState<'publish' | 'discard' | null>(null);
    const { data, setData, setDefaults, put, processing, errors, isDirty } = useForm({
        body_en: source?.body_en ?? '',
        body_fr: source?.body_fr ?? '',
        change_note: draft?.change_note ?? '',
        requires_acceptance: draft?.requires_acceptance ?? (type === 'terms'),
    });

    const nextVersion = draft?.version ?? (current ? current.version + 1 : 1);
    const canPublish = draft !== null && !isDirty;

    function save(event: FormEvent) {
        event.preventDefault();
        put(`/landlord/legal/${type}/draft`, { preserveScroll: true, onSuccess: () => setDefaults() });
    }

    function publish() {
        const warning = data.requires_acceptance && type === 'terms' ? ' Every company admin will be asked to accept it at their next page view.' : '';

        if (!confirm(`Publish ${label} version ${nextVersion}? It goes live on cncms.tech straight away.${warning}`)) {
            return;
        }

        setBusy('publish');
        router.post(`/landlord/legal/${type}/publish`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    }

    function discard() {
        if (!confirm('Discard this draft? The live version stays as it is.')) {
            return;
        }

        setBusy('discard');
        router.delete(`/landlord/legal/${type}/draft`, { preserveScroll: true, onFinish: () => setBusy(null) });
    }

    return (
        <LandlordLayout title={label} breadcrumbs={[{ label: 'Landlord' }, { label: 'Legal documents', href: '/landlord/legal' }, { label }]}>
            <Head title={`Landlord — ${label}`} />

            <div className="animate-fade-up mb-6 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconFileCertificate size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">{label}</h1>
                    <p className="text-sm text-slate-500">
                        {current ? `Version ${current.version} is live since ${formatDate(current.published_at)}.` : 'Nothing published yet.'}{' '}
                        {draft ? `You are editing the draft of version ${draft.version}.` : `Saving starts the draft of version ${nextVersion}.`}
                    </p>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <Card className="animate-fade-up min-w-0">
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex gap-1" role="tablist" aria-label="Language">
                                {(['en', 'fr'] as const).map((code) => (
                                    <button
                                        key={code}
                                        type="button"
                                        role="tab"
                                        aria-selected={language === code}
                                        onClick={() => setLanguage(code)}
                                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                                            language === code ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        {code === 'en' ? 'English' : 'Français'}
                                    </button>
                                ))}
                            </div>
                            {draft && (
                                <div className="flex gap-3 text-sm">
                                    {(['en', 'fr'] as const).map((code) => (
                                        <a
                                            key={code}
                                            href={`/landlord/legal/${type}/preview/draft?locale=${code}`}
                                            target="_blank"
                                            rel="noopener"
                                            className="inline-flex items-center gap-1 text-blue-600 hover:underline"
                                        >
                                            Preview {code.toUpperCase()} <IconExternalLink size={14} />
                                        </a>
                                    ))}
                                </div>
                            )}
                        </div>
                    </CardHeader>
                    <CardBody>
                        <form onSubmit={save} className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1.5">
                                <label htmlFor={`body_${language}`} className="text-sm font-medium text-slate-700">
                                    {language === 'en' ? 'English text' : 'French text'}{' '}
                                    <span className="font-normal text-slate-400">
                                        {language === 'fr' ? '(optional: English is shown when empty)' : ''}
                                    </span>
                                </label>
                                <textarea
                                    id={`body_${language}`}
                                    rows={22}
                                    value={language === 'en' ? data.body_en : data.body_fr}
                                    onChange={(event) => setData(language === 'en' ? 'body_en' : 'body_fr', event.target.value)}
                                    className="w-full rounded-lg border-0 px-3.5 py-2.5 font-mono text-[13px] leading-relaxed text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600"
                                />
                                {(errors.body_en || errors.body_fr) && <p className="text-xs text-red-600">{errors.body_en ?? errors.body_fr}</p>}
                                <p className="text-xs text-slate-400">
                                    <code>## Heading</code> starts a section · <code>- item</code> makes a list · <code>**bold**</code> · a blank line starts a new
                                    paragraph.
                                </p>
                                {type === 'contract' && (
                                    <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        Blanks filled in for each company when it signs: {CONTRACT_BLANKS.join(' · ')}
                                    </p>
                                )}
                            </div>

                            <TextInput
                                id="change_note"
                                label="What changed (shown to companies asked to accept)"
                                placeholder="e.g. Clearer rules for the end of the demo."
                                value={data.change_note}
                                onChange={(event) => setData('change_note', event.target.value)}
                                error={errors.change_note}
                                maxLength={255}
                            />

                            {type === 'terms' && (
                                <label className="flex items-start gap-2.5 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={data.requires_acceptance}
                                        onChange={(event) => setData('requires_acceptance', event.target.checked)}
                                        className="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                    />
                                    <span>
                                        Companies must accept this version
                                        <span className="block text-xs text-slate-400">
                                            Each company's super/admin is asked at their next page view. Untick for small fixes like spelling.
                                        </span>
                                    </span>
                                </label>
                            )}

                            <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                                <Button type="submit" variant="secondary" disabled={processing || (!isDirty && draft !== null)}>
                                    {processing && <LoadingSpinner />}
                                    Save draft
                                </Button>
                                <Button type="button" onClick={publish} disabled={!canPublish || busy !== null}>
                                    {busy === 'publish' && <LoadingSpinner className="text-white" />}
                                    Publish version {nextVersion}
                                </Button>
                                {draft && (
                                    <Button type="button" variant="danger" onClick={discard} disabled={busy !== null}>
                                        Discard draft
                                    </Button>
                                )}
                                {isDirty && <span className="text-xs text-amber-700">Unsaved changes: save the draft before you preview or publish.</span>}
                            </div>
                        </form>
                    </CardBody>
                </Card>

                <div className="flex min-w-0 flex-col gap-6">
                    {type === 'terms' && current?.requires_acceptance && (
                        <Card className="animate-fade-up">
                            <CardHeader>
                                <p className="font-medium text-slate-900">Version {current.version}: still to accept</p>
                            </CardHeader>
                            <CardBody>
                                {waiting.length === 0 ? (
                                    <p className="text-sm text-green-700">Every approved company has accepted.</p>
                                ) : (
                                    <ul className="flex flex-col gap-1 text-sm text-slate-700">
                                        {waiting.map((company) => (
                                            <li key={company}>{company}</li>
                                        ))}
                                    </ul>
                                )}
                            </CardBody>
                        </Card>
                    )}

                    {current && type !== 'contract' && (
                        <Card className="animate-fade-up">
                            <CardHeader>
                                <p className="font-medium text-slate-900">Accepted version {current.version}</p>
                            </CardHeader>
                            {acceptances.length === 0 ? (
                                <CardBody>
                                    <p className="text-sm text-slate-500">No acceptances yet.</p>
                                </CardBody>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {acceptances.map((acceptance, index) => (
                                        <li key={index} className="px-4 py-2.5 text-sm">
                                            <p className="font-medium text-slate-900">{acceptance.company}</p>
                                            <p className="text-xs text-slate-500">
                                                {acceptance.user ?? 'Unknown'} · {formatDate(acceptance.accepted_at)}
                                                {acceptance.ip_address && ` · ${acceptance.ip_address}`}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                </div>
            </div>

            <h2 className="mt-8 mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">History</h2>
            {history.length === 0 ? (
                <p className="text-sm text-slate-500">No published versions yet.</p>
            ) : (
                <Card>
                    <Table>
                        <TableHead>
                            <tr>
                                <Th>Version</Th>
                                <Th>Status</Th>
                                <Th>Published</Th>
                                <Th>By</Th>
                                <Th>What changed</Th>
                                <Th>Acceptances</Th>
                                <Th />
                            </tr>
                        </TableHead>
                        <TableBody>
                            {history.map((row) => (
                                <tr key={row.version}>
                                    <Td>{row.version}</Td>
                                    <Td>
                                        <Badge tone={row.status === 'published' ? 'green' : 'slate'}>{row.status === 'published' ? 'Live' : 'Retired'}</Badge>
                                    </Td>
                                    <Td>{formatDate(row.published_at)}</Td>
                                    <Td>{row.published_by ?? '—'}</Td>
                                    <Td className="max-w-xs truncate">{row.change_note ?? '—'}</Td>
                                    <Td>{row.acceptances}</Td>
                                    <Td className="whitespace-nowrap text-right text-sm">
                                        {(['en', 'fr'] as const).map((code) => (
                                            <a
                                                key={code}
                                                href={`/landlord/legal/${type}/preview/${row.version}?locale=${code}`}
                                                target="_blank"
                                                rel="noopener"
                                                className="ml-3 text-blue-600 hover:underline"
                                            >
                                                View {code.toUpperCase()}
                                            </a>
                                        ))}
                                    </Td>
                                </tr>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <p className="mt-6 text-sm">
                <Link href="/landlord/legal" className="text-slate-600 hover:underline">
                    ← All legal documents
                </Link>
            </p>
        </LandlordLayout>
    );
}
