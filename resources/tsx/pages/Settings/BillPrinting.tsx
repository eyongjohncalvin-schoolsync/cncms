import { Form, Head } from '@inertiajs/react';
import { IconPrinter, IconCheck, IconAlertCircle, IconExternalLink, IconFileText } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

type BillTemplate = 'classic' | 'compact' | 'modern';

interface SettingsBillPrintingProps {
    bill_template: BillTemplate;
    bills_per_page: number;
    templates: BillTemplate[];
    bills_per_page_options: number[];
}

const TEMPLATE_META: Record<BillTemplate, { name: string; description: string }> = {
    classic: {
        name: 'Classic Ledger',
        description:
            'Formal ruled letterhead, boxed account details, an amount-in-words line, and a signature/tear-off stub for the delivering collector.',
    },
    compact: {
        name: 'Kumba Compact',
        description:
            'Condensed receipt style with the amount due reversed out white-on-black — survives weak toner and photocopying. Used automatically for every cell whenever Bills Per Page is set above 1.',
    },
    modern: {
        name: 'Signal Modern',
        description:
            'Full A4 layout with a branded accent band, a large amount-due panel, and payment methods shown as labeled chips.',
    },
};

/**
 * A tiny static thumbnail (not a rendered PDF — see the design review this
 * cycle) showing how N bills tile onto one physical sheet. The per-TEMPLATE
 * preview below is the real PDF; this density picker only needs a simple
 * visual icon since density always uses the 'compact' template regardless
 * of which one is selected above (resources/views/pdf/bills/_grid.blade.php).
 */
function DensityDiagram({ density }: { density: number }) {
    const cellClass = 'rounded-sm border border-slate-400 bg-slate-100';

    if (density === 1) {
        return (
            <div className="flex h-16 w-12 items-center justify-center">
                <div className={`${cellClass} h-16 w-12`} />
            </div>
        );
    }

    if (density === 2) {
        return (
            <div className="flex h-16 w-12 flex-col gap-1">
                <div className={`${cellClass} flex-1`} />
                <div className={`${cellClass} flex-1`} />
            </div>
        );
    }

    return (
        <div className="grid h-16 w-12 grid-cols-2 gap-1">
            <div className={cellClass} />
            <div className={cellClass} />
            <div className={cellClass} />
            <div className={cellClass} />
        </div>
    );
}

export default function SettingsBillPrinting({
    bill_template,
    bills_per_page,
    templates,
    bills_per_page_options,
}: SettingsBillPrintingProps) {
    return (
        <AppLayout
            title="Bill Printing"
            breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Bill Printing' }]}
        >
            <Head title="Settings — Bill Printing" />

            <SettingsTabs active="bill-printing" />

            <div className="mb-8 flex items-center gap-4">
                <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                    <IconPrinter size={24} stroke={1.75} />
                </span>
                <div>
                    <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Bill Printing</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Choose the bill layout and how many bills print per sheet. Preview each layout as a real PDF
                        before saving.
                    </p>
                </div>
            </div>

            <div className="max-w-4xl">
                <Form action="/settings/bill-printing" method="patch">
                    {({ errors, processing, recentlySuccessful }) => (
                        <div className="space-y-6">
                            <Card className="animate-fade-up">
                                <CardHeader className="border-b border-slate-100">
                                    <h2 className="text-base font-semibold text-slate-900">Bill Template</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Applies to every single-customer bill printed from a customer's page, and to
                                        bulk-printed sheets set to one bill per page.
                                    </p>
                                </CardHeader>
                                <CardBody className="p-6">
                                    <div className="grid gap-4 md:grid-cols-3">
                                        {templates.map((template) => {
                                            const meta = TEMPLATE_META[template];

                                            return (
                                                <label
                                                    key={template}
                                                    className="flex cursor-pointer flex-col gap-3 rounded-xl border border-slate-200 p-4 hover:border-slate-300 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/50 has-[:checked]:ring-1 has-[:checked]:ring-blue-500"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <span className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                            <input
                                                                type="radio"
                                                                name="bill_template"
                                                                value={template}
                                                                defaultChecked={bill_template === template}
                                                                className="h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-600"
                                                            />
                                                            {meta.name}
                                                        </span>
                                                    </div>
                                                    <p className="text-xs leading-relaxed text-slate-500">{meta.description}</p>
                                                    {/*
                                                        A real dompdf-rendered PDF, not an HTML mockup — dompdf's CSS
                                                        rendering has real quirks an HTML approximation wouldn't
                                                        faithfully represent, and the whole point of a preview is
                                                        seeing what will actually print. Opens in a new tab rather
                                                        than an inline <iframe> — PDF-in-iframe rendering is
                                                        inconsistent across browsers/devices (some prompt a download
                                                        instead of rendering inline), while a plain link to the same
                                                        URL works everywhere.
                                                    */}
                                                    <a
                                                        href={`/settings/bill-printing/preview/${template}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="mt-auto inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-700"
                                                    >
                                                        <IconFileText size={14} stroke={1.75} />
                                                        View live preview (PDF)
                                                        <IconExternalLink size={12} stroke={1.75} />
                                                    </a>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {errors.bill_template && (
                                        <p className="mt-3 flex items-center gap-1 text-xs text-red-600">
                                            <IconAlertCircle size={14} />
                                            {errors.bill_template}
                                        </p>
                                    )}
                                </CardBody>
                            </Card>

                            <Card className="animate-fade-up">
                                <CardHeader className="border-b border-slate-100">
                                    <h2 className="text-base font-semibold text-slate-900">Bills Per Page</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        For bulk printing runs. Above 1, every cell always uses the Kumba Compact
                                        layout regardless of the template selected above, since it's the one sized
                                        for a shared sheet.
                                    </p>
                                </CardHeader>
                                <CardBody className="p-6">
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        {bills_per_page_options.map((option) => (
                                            <label
                                                key={option}
                                                className="flex cursor-pointer items-center gap-4 rounded-xl border border-slate-200 p-4 hover:border-slate-300 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50/50 has-[:checked]:ring-1 has-[:checked]:ring-blue-500"
                                            >
                                                <input
                                                    type="radio"
                                                    name="bills_per_page"
                                                    value={option}
                                                    defaultChecked={bills_per_page === option}
                                                    className="h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-600"
                                                />
                                                <DensityDiagram density={option} />
                                                <span className="text-sm font-medium text-slate-900">
                                                    {option} {option === 1 ? 'bill' : 'bills'} / page
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    {errors.bills_per_page && (
                                        <p className="mt-3 flex items-center gap-1 text-xs text-red-600">
                                            <IconAlertCircle size={14} />
                                            {errors.bills_per_page}
                                        </p>
                                    )}
                                </CardBody>
                            </Card>

                            <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                                <div>
                                    {recentlySuccessful && (
                                        <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600 animate-fade-up">
                                            <IconCheck size={16} />
                                            Changes saved successfully
                                        </span>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 hover:bg-slate-800"
                                >
                                    {processing && <LoadingSpinner className="mr-2 text-white" />}
                                    {processing ? 'Saving…' : 'Save Changes'}
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
