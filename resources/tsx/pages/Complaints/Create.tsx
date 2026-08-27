import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { IconAlertTriangle, IconChevronDown, IconChevronRight, IconMessageReport } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { ComplaintCategory, ComplaintDuplicateCandidate } from '@/types';

interface ComplaintCustomerOption {
    uuid: string;
    name: string;
    phone: string | null;
    zone_name: string | null;
}

interface ComplaintsCreateProps {
    customers: ComplaintCustomerOption[];
}

// Faster to tap than a dropdown — references/complaint-desk.md section 6.
const categoryChips: { value: ComplaintCategory; label: string; hint: string }[] = [
    { value: 'operational', label: 'Operational', hint: "An internal system/process issue — e.g. \"my zone's list won't sync.\"" },
    { value: 'customer', label: 'Customer', hint: 'A complaint relayed on a specific customer’s behalf.' },
];

export default function ComplaintsCreate({ customers }: ComplaintsCreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        category: 'operational' as ComplaintCategory,
        title: '',
        description: '',
        urgent: false,
        customer_uuid: '',
    });

    const [descriptionOpen, setDescriptionOpen] = useState(false);
    const [customerSearch, setCustomerSearch] = useState('');

    const filteredCustomers = useMemo(() => {
        const term = customerSearch.trim().toLowerCase();

        if (!term) {
            return customers;
        }

        return customers.filter(
            (customer) => customer.name.toLowerCase().includes(term) || (customer.phone ?? '').toLowerCase().includes(term),
        );
    }, [customers, customerSearch]);

    // Submission-time soft duplicate guard (references/complaint-desk.md
    // section 4.1) — plain background fetch(), never blocking, same
    // "cancelled flag" convention as Payments/Create.tsx's last-payment
    // lookup. Re-runs whenever category changes, or (for a customer
    // complaint) whenever the selected customer changes.
    const [duplicates, setDuplicates] = useState<ComplaintDuplicateCandidate[]>([]);
    const [checkingDuplicates, setCheckingDuplicates] = useState(false);

    useEffect(() => {
        if (data.category === 'customer' && !data.customer_uuid) {
            setDuplicates([]);
            return;
        }

        let cancelled = false;
        setCheckingDuplicates(true);

        const params = new URLSearchParams({ category: data.category });
        if (data.category === 'customer') {
            params.set('customer_uuid', data.customer_uuid);
        }

        fetch(`/complaints/duplicates?${params.toString()}`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((body: { complaints: ComplaintDuplicateCandidate[] }) => {
                if (!cancelled) {
                    setDuplicates(body.complaints);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setDuplicates([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setCheckingDuplicates(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.category, data.customer_uuid]);

    function selectCategory(category: ComplaintCategory) {
        setData((current) => ({ ...current, category, customer_uuid: category === 'operational' ? '' : current.customer_uuid }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/complaints');
    }

    return (
        <AppLayout title="Log a Complaint" breadcrumbs={[{ label: 'Complaints', href: '/complaints' }, { label: 'Log a Complaint' }]}>
            <Head title="Log a Complaint" />

            <div className="mb-6 animate-fade-up" style={{ animationDelay: '0ms' }}>
                <h2 className="font-display text-2xl text-slate-900">Log a Complaint</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Report an internal issue, or relay a complaint on a customer&apos;s behalf. Every role can submit one.
                </p>
            </div>

            <form onSubmit={submit} className="max-w-2xl animate-fade-up" style={{ animationDelay: '100ms' }}>
                <Card>
                    <CardHeader>
                        <h3 className="text-sm font-semibold text-slate-900">Complaint details</h3>
                    </CardHeader>
                    <CardBody className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1.5">
                            <span className="text-sm font-medium text-slate-700">
                                Category
                                <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>
                            </span>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {categoryChips.map((chip) => (
                                    <button
                                        key={chip.value}
                                        type="button"
                                        aria-pressed={data.category === chip.value}
                                        onClick={() => selectCategory(chip.value)}
                                        className={`flex flex-col gap-1 rounded-lg border p-3 text-left transition-colors ${
                                            data.category === chip.value
                                                ? 'border-fuchsia-400 bg-fuchsia-50 ring-1 ring-inset ring-fuchsia-300'
                                                : 'border-slate-200 bg-white hover:bg-slate-50'
                                        }`}
                                    >
                                        <span className={`text-sm font-semibold ${data.category === chip.value ? 'text-fuchsia-800' : 'text-slate-900'}`}>
                                            {chip.label}
                                        </span>
                                        <span className="text-xs text-slate-500">{chip.hint}</span>
                                    </button>
                                ))}
                            </div>
                            {errors.category && <p className="text-xs text-red-600">{errors.category}</p>}
                        </div>

                        {data.category === 'customer' && (
                            <div className="flex flex-col gap-1.5">
                                <label className="text-sm font-medium text-slate-700">
                                    Customer
                                    <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>
                                </label>
                                <TextInput
                                    placeholder="Search by name or phone…"
                                    value={customerSearch}
                                    onChange={(e) => setCustomerSearch(e.target.value)}
                                />
                                <select
                                    value={data.customer_uuid}
                                    onChange={(e) => setData('customer_uuid', e.target.value)}
                                    className={`rounded-lg border-0 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-blue-600 ${
                                        errors.customer_uuid ? 'ring-red-400' : ''
                                    }`}
                                >
                                    <option value="">Select a customer…</option>
                                    {filteredCustomers.map((customer) => (
                                        <option key={customer.uuid} value={customer.uuid}>
                                            {customer.name}
                                            {customer.phone ? ` — ${customer.phone}` : ''} {customer.zone_name ? `(${customer.zone_name})` : ''}
                                        </option>
                                    ))}
                                </select>
                                {errors.customer_uuid && <p className="text-xs text-red-600">{errors.customer_uuid}</p>}
                            </div>
                        )}

                        {(checkingDuplicates || duplicates.length > 0) && (
                            <div className="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">
                                {checkingDuplicates ? (
                                    <span className="flex items-center gap-2 text-amber-800">
                                        <LoadingSpinner className="h-4 w-4" />
                                        Checking for similar open complaints…
                                    </span>
                                ) : (
                                    <>
                                        <p className="font-medium text-amber-900">
                                            There{duplicates.length === 1 ? "'s" : ' are'} already {duplicates.length} open complaint
                                            {duplicates.length === 1 ? '' : 's'} that may match this — view first, or still file a new one below.
                                        </p>
                                        <ul className="flex flex-col gap-1">
                                            {duplicates.slice(0, 5).map((candidate) => (
                                                <li key={candidate.uuid}>
                                                    <Link
                                                        href={`/complaints/${candidate.uuid}`}
                                                        className="font-medium text-amber-800 underline decoration-amber-400 hover:text-amber-900"
                                                    >
                                                        {candidate.title}
                                                    </Link>
                                                    <span className="text-amber-700">
                                                        {' '}
                                                        — submitted by {candidate.submitted_by_name ?? 'someone'} on{' '}
                                                        {new Date(candidate.created_at).toLocaleDateString()}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                        )}

                        <TextInput
                            id="title"
                            label="Title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            error={errors.title}
                            placeholder="Short summary of the issue"
                            required
                        />

                        {/* Optional, collapsed by default — mirrors
                            Payments/Create.tsx's "Frequency calculation guide"
                            reference-only collapsible card treatment. */}
                        <div className="flex flex-col gap-1.5">
                            <button
                                type="button"
                                onClick={() => setDescriptionOpen((open) => !open)}
                                className="flex items-center gap-1.5 self-start text-sm font-medium text-slate-600 hover:text-slate-900"
                            >
                                {descriptionOpen ? <IconChevronDown size={16} stroke={2} /> : <IconChevronRight size={16} stroke={2} />}
                                Add more detail (optional)
                            </button>
                            {descriptionOpen && (
                                <textarea
                                    id="description"
                                    rows={4}
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Anything that helps whoever picks this up…"
                                    className="rounded-lg border-0 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-xs ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-blue-600"
                                />
                            )}
                            {errors.description && <p className="text-xs text-red-600">{errors.description}</p>}
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <label className="text-sm font-medium text-slate-700">Photo (optional)</label>
                            <input
                                type="file"
                                accept="image/*"
                                disabled
                                className="cursor-not-allowed rounded-md text-sm text-slate-400 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-400"
                            />
                            <p className="text-xs text-slate-400">Photo attachments are coming in a follow-up update.</p>
                        </div>

                        {/* The urgent fast-path toggle — deliberately separate
                            from and visually distinct from the rest of the
                            form, never a routine severity picker every
                            submission touches (references/complaint-desk.md
                            section 6). */}
                        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3">
                            <input
                                type="checkbox"
                                checked={data.urgent}
                                onChange={(e) => setData('urgent', e.target.checked)}
                                className="mt-0.5 h-4 w-4 rounded border-red-300 text-red-600 focus:ring-red-600"
                            />
                            <span>
                                <span className="flex items-center gap-1.5 text-sm font-semibold text-red-800">
                                    <IconAlertTriangle size={16} stroke={2} />
                                    This can&apos;t wait
                                </span>
                                <span className="text-xs text-red-700">
                                    Only check this for something urgent enough to need attention right now — not for routine issues. Everything else is
                                    still tracked and followed up automatically either way.
                                </span>
                            </span>
                        </label>

                        <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
                            <Link href="/complaints">
                                <Button type="button" variant="secondary" className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                    Cancel
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                {processing ? <LoadingSpinner className="h-4 w-4" /> : <IconMessageReport size={16} stroke={2} />}
                                {processing ? 'Submitting…' : 'Submit Complaint'}
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            </form>
        </AppLayout>
    );
}
