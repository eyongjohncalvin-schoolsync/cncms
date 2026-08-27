import { Form, Head, Link } from '@inertiajs/react';
import {
    IconUserEdit,
    IconUser,
    IconMapPin,
    IconWallet,
    IconPhone,
    IconGauge,
    IconFileText,
    IconArrowLeft,
    IconToggleRight,
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import { BillInput } from '@/components/shared/BillInput';
import { StatusBadge } from '@/components/shared/StatusBadge';
import type { Customer, CustomerLevel, CustomerStatus, Zone } from '@/types';

interface CustomersEditProps {
    customer: Customer;
    zones: Zone[];
}

const levelOptions: CustomerLevel[] = ['normal', 'Vip', 'Operator'];
const statusOptions: CustomerStatus[] = ['active', 'passive', 'disconnected', 'suspended'];

export default function CustomersEdit({ customer, zones }: CustomersEditProps) {
    return (
        <AppLayout
            title={`Edit ${customer.name}`}
            breadcrumbs={[
                { label: 'Customers', href: '/customers' },
                { label: customer.name, href: `/customers/${customer.uuid}` },
                { label: 'Edit' },
            ]}
        >
            <Head title={`Edit ${customer.name}`} />

            {/* Header */}
            <div className="animate-fade-up mb-8">
                <div className="flex items-center gap-4">
                    <div className="relative">
                        <div className="absolute inset-0 rounded-2xl bg-linear-to-br from-amber-500 to-orange-600 opacity-20 blur-lg"></div>
                        <span className="relative inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-amber-500 to-orange-600 text-white shadow-lg shadow-orange-500/25">
                            <IconUserEdit size={24} stroke={1.75} />
                        </span>
                    </div>
                    <div>
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="font-display text-3xl font-semibold tracking-tight text-slate-900">Edit {customer.name}</h1>
                            <StatusBadge status={customer.status} />
                        </div>
                        <p className="mt-1 text-sm text-slate-500">Update this subscriber&apos;s details, billing, and status</p>
                    </div>
                </div>
            </div>

            <div className="max-w-3xl">
                <Form action={`/customers/${customer.uuid}`} method="patch">
                    {({ errors, processing }) => (
                        <div className="space-y-6">
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
                                                defaultValue={customer.name}
                                                error={errors.name}
                                                required
                                                className="rounded-xl px-4 py-3"
                                            />
                                        </div>
                                        <div>
                                            <SelectInput
                                                id="zone_uuid"
                                                name="zone_uuid"
                                                label="Zone"
                                                defaultValue={customer.zone_uuid}
                                                error={errors.zone_uuid}
                                                required
                                                className="rounded-xl px-4 py-3"
                                            >
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
                                                defaultValue={customer.phone ?? ''}
                                                error={errors.phone}
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
                                                defaultValue={customer.location ?? ''}
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

                            {/* Service & Billing */}
                            <Card className="animate-fade-up" style={{ animationDelay: '0.1s' }}>
                                <CardHeader className="border-b border-slate-100">
                                    <div className="flex items-center gap-3">
                                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                            <IconWallet size={18} stroke={1.75} />
                                        </span>
                                        <div>
                                            <h2 className="text-base font-semibold text-slate-900">Service &amp; Billing</h2>
                                            <p className="mt-0.5 text-xs text-slate-500">Subscription tier and monthly charge</p>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="p-6">
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <SelectInput
                                                id="level"
                                                name="level"
                                                label="Level"
                                                defaultValue={customer.level}
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
                                        <div>
                                            <BillInput defaultValue={customer.bill} error={errors.bill} />
                                            <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
                                                <IconWallet size={12} />
                                                Charged each billing period
                                            </p>
                                        </div>
                                    </div>
                                </CardBody>
                            </Card>

                            {/* Account Status */}
                            <Card className="animate-fade-up" style={{ animationDelay: '0.15s' }}>
                                <CardHeader className="border-b border-slate-100">
                                    <div className="flex items-center gap-3">
                                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                                            <IconToggleRight size={18} stroke={1.75} />
                                        </span>
                                        <div>
                                            <h2 className="text-base font-semibold text-slate-900">Account Status</h2>
                                            <p className="mt-0.5 text-xs text-slate-500">Current standing of this subscription</p>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="p-6">
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <SelectInput
                                                id="status"
                                                name="status"
                                                label="Status"
                                                defaultValue={customer.status}
                                                error={errors.status}
                                                className="rounded-xl px-4 py-3"
                                            >
                                                {statusOptions.map((status) => (
                                                    <option key={status} value={status}>
                                                        {status}
                                                    </option>
                                                ))}
                                            </SelectInput>
                                            <p className="mt-2 text-xs text-slate-500">
                                                For disconnect/suspend/reconnect with a reason and note, prefer the quick actions on the
                                                customer&apos;s profile page — they keep an audit trail.
                                            </p>
                                        </div>
                                    </div>
                                </CardBody>
                            </Card>

                            {/* Action Bar */}
                            <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                                <Link
                                    href={`/customers/${customer.uuid}`}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700"
                                >
                                    <IconArrowLeft size={16} stroke={1.75} />
                                    Back to Profile
                                </Link>
                                <div className="flex items-center gap-3">
                                    <Link href={`/customers/${customer.uuid}`}>
                                        <Button type="button" variant="secondary" className="rounded-xl px-5 py-2.5 text-sm font-medium">
                                            Cancel
                                        </Button>
                                    </Link>
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
                        </div>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
