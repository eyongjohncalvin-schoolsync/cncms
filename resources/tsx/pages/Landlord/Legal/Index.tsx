import { Head, Link } from '@inertiajs/react';
import { IconFileCertificate } from '@tabler/icons-react';
import { LandlordLayout } from '@/layouts/LandlordLayout';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';

interface LegalDocumentSummary {
    type: string;
    label: string;
    version: number | null;
    published_at: string | null;
    requires_acceptance: boolean;
    has_draft: boolean;
    accepted: number | null;
    companies: number;
}

/**
 * Landlord → Legal documents (App\Http\Controllers\Landlord\
 * LegalDocumentController::index): each document's live version, any
 * draft in progress, and how many companies accepted the live Terms.
 */
export default function LandlordLegalIndex({ documents }: { documents: LegalDocumentSummary[] }) {
    return (
        <LandlordLayout title="Legal documents" breadcrumbs={[{ label: 'Landlord' }, { label: 'Legal documents' }]}>
            <Head title="Landlord — Legal documents" />

            <div className="animate-fade-up mb-6 flex items-center gap-3">
                <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-700">
                    <IconFileCertificate size={20} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-2xl font-semibold text-slate-900">Legal documents</h1>
                    <p className="max-w-2xl text-sm text-slate-500">
                        Write each document in English and French, save a draft, preview it, then publish. A published version is live on cncms.tech at
                        once; old versions are kept with who accepted them.
                    </p>
                </div>
            </div>

            <Card className="animate-fade-up max-w-3xl divide-y divide-slate-100">
                {documents.map((document) => (
                    <Link
                        key={document.type}
                        href={`/landlord/legal/${document.type}`}
                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 transition-colors hover:bg-slate-50"
                    >
                        <div className="min-w-0">
                            <p className="font-medium text-slate-900">{document.label}</p>
                            <p className="text-sm text-slate-500">
                                {document.version
                                    ? `Version ${document.version} live since ${new Date(document.published_at ?? '').toLocaleDateString(undefined, { dateStyle: 'medium' })}`
                                    : 'Nothing published yet'}
                                {document.accepted !== null && ` · accepted by ${document.accepted} of ${document.companies} companies`}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            {document.requires_acceptance && <Badge tone="yellow">Must accept</Badge>}
                            {document.has_draft && <Badge tone="slate">Draft in progress</Badge>}
                        </div>
                    </Link>
                ))}
            </Card>
        </LandlordLayout>
    );
}
