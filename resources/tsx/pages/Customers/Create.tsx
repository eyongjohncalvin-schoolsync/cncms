import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { IconUserPlus, IconUser, IconMapPin, IconWallet, IconPhone, IconGauge, IconFileText, IconArrowLeft } from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { ServicesPicker } from '@/components/customers/ServicesPicker';
import type { CustomerLevel, CustomerServiceSelectionForm, ServiceCatalogueEntry, Zone } from '@/types';

interface CustomersCreateProps {
    zones: Zone[];
    service_catalogue: ServiceCatalogueEntry[];
}

const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];

export default function CustomersCreate({ zones, service_catalogue }: CustomersCreateProps) {
    const defaultService = service_catalogue.find((s) => s.is_default);

    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        zone_uuid: string;
        phone: string;
        location: string;
        level: CustomerLevel;
        description: string;
        services: CustomerServiceSelectionForm[];
    }>({
        name: '',
        zone_uuid: '',
        phone: '',
        location: '',
        level: 'normal',
        description: '',
        // TV (or whatever the operator marked as default) starts pre-ticked
        // — services.md's explicit ask.
        services: defaultService
            ? [{ service_uuid: defaultService.uuid, service_variant_uuid: null, price: defaultService.price }]
            : [],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/customers');
    }

    return (
        <AppLayout title="Add Customer" breadcrumbs={[{ label: 'Customers', href: '/customers' }, { label: 'Add Customer' }]}>
            <Head title="Add Customer" />

            {/* Header */}
            <div className="animate-fade-up mb-8">
                <div className="flex items-center gap-4">
                    <div className="relative">
                        <div className="absolute inset-0 rounded-2xl bg-linear-to-br from-indigo-500 to-indigo-600 opacity-20 blur-lg"></div>
                        <span className="relative inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25">
                            <IconUserPlus size={24} stroke={1.75} />
                        </span>
                    </div>
                    <div>
                        <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Add Customer</h1>
                        <p className="mt-1 text-sm text-slate-500">Create a new subscriber account and assign it to a zone</p>
                    </div>
                </div>
            </div>

            <div className="max-w-3xl">
                <form onSubmit={submit} className="space-y-6">
                    {/* Identity & Location */}
                    <Card className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                        <CardHeader className="border-b border-slate-100">
                            <div className="flex items-center gap-3">
                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                    <IconUser size={18} stroke={1.75} />
                                </span>
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">Identity &amp; Location</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">Who this customer is and where to find them</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardBody className="p-6">
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <TextInput
                                        id="name"
                                        name="name"
                                        label="Full Name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        error={errors.name}
                                        required
                                        placeholder="e.g., Ekema Divine"
                                        className="rounded-xl px-4 py-3"
                                    />
                                </div>
                                <div>
                                    <SelectInput
                                        id="zone_uuid"
                                        name="zone_uuid"
                                        label="Zone"
                                        value={data.zone_uuid}
                                        onChange={(e) => setData('zone_uuid', e.target.value)}
                                        error={errors.zone_uuid}
                                        required
                                        className="rounded-xl px-4 py-3"
                                    >
                                        <option value="">Select a zone</option>
                                        {zones.map((zone) => (
                                            <option key={zone.uuid} value={zone.uuid}>
                                                {zone.name}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                        <IconMapPin size={12} />
                                        Determines which agent collects this account
                                    </p>
                                </div>
                                <div>
                                    <TextInput
                                        id="phone"
                                        name="phone"
                                        label="Phone"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        error={errors.phone}
                                        required
                                        placeholder="+237 6XX XXX XXX"
                                        className="rounded-xl px-4 py-3"
                                    />
                                    <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                        <IconPhone size={12} />
                                        Used for billing SMS and reminders
                                    </p>
                                </div>
                                <div className="sm:col-span-2">
                                    <TextInput
                                        id="location"
                                        name="location"
                                        label="Location"
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        error={errors.location}
                                        placeholder="House / street / landmark"
                                        className="rounded-xl px-4 py-3"
                                    />
                                    <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                        <IconMapPin size={12} />
                                        Helps agents find the customer within the zone
                                    </p>
                                </div>
                            </div>
                        </CardBody>
                    </Card>

                    {/* Services & Billing */}
                    <Card className="animate-fade-up" style={{ animationDelay: '0.1s' }}>
                        <CardHeader className="border-b border-slate-100">
                            <div className="flex items-center gap-3">
                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                    <IconWallet size={18} stroke={1.75} />
                                </span>
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">Services &amp; Billing</h2>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Which services this customer has applied for — the monthly bill is their total
                                    </p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardBody className="space-y-6 p-6">
                            <ServicesPicker
                                catalogue={service_catalogue}
                                value={data.services}
                                onChange={(services) => setData('services', services)}
                                error={errors.services}
                            />

                            <div className="grid gap-6 sm:grid-cols-2">
                                <div>
                                    <SelectInput
                                        id="level"
                                        name="level"
                                        label="Level"
                                        value={data.level}
                                        onChange={(e) => setData('level', e.target.value as CustomerLevel)}
                                        error={errors.level}
                                        className="rounded-xl px-4 py-3"
                                    >
                                        {levelOptions.map((level) => (
                                            <option key={level} value={level}>
                                                {level}
                                            </option>
                                        ))}
                                    </SelectInput>
                                    <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                        <IconGauge size={12} />
                                        Vip / Operator accounts get priority handling
                                    </p>
                                </div>
                                <div className="sm:col-span-2">
                                    <TextInput
                                        id="description"
                                        name="description"
                                        label="Description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        error={errors.description}
                                        placeholder="Optional internal note about this customer"
                                        className="rounded-xl px-4 py-3"
                                    />
                                    <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                        <IconFileText size={12} />
                                        Visible to staff only, not printed on bills
                                    </p>
                                </div>
                            </div>
                        </CardBody>
                    </Card>

                    {/* Action Bar */}
                    <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <Link
                            href="/customers"
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700"
                        >
                            <IconArrowLeft size={16} stroke={1.75} />
                            Back to Customers
                        </Link>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <Link href="/customers" className="w-full sm:w-auto">
                                <Button type="button" variant="secondary" className="w-full rounded-xl px-5 py-2.5 text-sm font-medium sm:w-auto">
                                    Cancel
                                </Button>
                            </Link>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="w-full rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-900/10 hover:bg-slate-800 sm:w-auto"
                            >
                                {processing && <LoadingSpinner className="mr-2 text-white" />}
                                {processing ? 'Creating…' : 'Create Customer'}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
