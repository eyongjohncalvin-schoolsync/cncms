import { Form, Head } from '@inertiajs/react';
import {
    IconBuildingStore,
    IconMapPin,
    IconMail,
    IconPhone,
    IconHeadset,
    IconWallet,
    IconFileText,
    IconUpload,
    IconCheck,
    IconAlertCircle,
    IconBuildingBank
} from '@tabler/icons-react';
import { AppLayout } from '@/layouts/AppLayout';
import { SettingsTabs } from '@/components/settings/SettingsTabs';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Company } from '@/types';
import { useState } from 'react'; // Add this import

export default function SettingsCompany({ company }: { company: Company | null }) {
    // Add state declaration here
    const [activeSection, setActiveSection] = useState('general');

    // Add sections array here
    const sections = [
        { id: 'general', label: 'General', icon: IconBuildingStore },
        { id: 'contact', label: 'Contact & Support', icon: IconHeadset },
        { id: 'payments', label: 'Payments (MOMO)', icon: IconWallet },
        { id: 'legal', label: 'Legal & Tax', icon: IconFileText },
        { id: 'branding', label: 'Branding', icon: IconUpload },
    ];

    return (
        <AppLayout
            title="Company Info"
            breadcrumbs={[{ label: 'Settings', href: '/settings/company' }, { label: 'Company Info' }]}
        >
            <Head title="Settings — Company Info" />

            <SettingsTabs active="company" />

            {/* Header Section */}
            <div className="mb-8">
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="relative">
                            <div className="absolute inset-0 bg-linear-to-br from-blue-500 to-blue-600 rounded-2xl blur-lg opacity-20"></div>
                            <span className="relative inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25">
                                <IconBuildingStore size={24} stroke={1.75} />
                            </span>
                        </div>
                        <div>
                            <h1 className="font-display text-3xl font-semibold text-slate-900 tracking-tight">
                                Company Information
                            </h1>
                            <p className="mt-1 text-sm text-slate-500">
                                Manage your company profile, contact details, and payment settings
                            </p>
                        </div>
                    </div>
                    {company && (
                        <div className="hidden sm:flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-medium">
                            <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                            Active
                        </div>
                    )}
                </div>
            </div>

            {!company ? (
                <Card className="max-w-3xl mx-auto">
                    <CardBody className="text-center py-12">
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100">
                            <IconAlertCircle className="h-8 w-8 text-amber-600" />
                        </div>
                        <h3 className="mt-4 text-lg font-medium text-slate-900">No Company Record Found</h3>
                        <p className="mt-2 text-sm text-slate-500 max-w-md mx-auto">
                            Contact a super administrator to initialize your company profile and settings.
                        </p>
                    </CardBody>
                </Card>
            ) : (
                <div className="max-w-5xl mx-auto">
                    {/* Section Navigation */}
                    <div className="mb-6 flex gap-2 overflow-x-auto pb-2">
                        {sections.map((section) => {
                            const Icon = section.icon;
                            const isActive = activeSection === section.id;
                            return (
                                <button
                                    key={section.id}
                                    onClick={() => setActiveSection(section.id)}
                                    className={`
                                        flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium
                                        transition-all duration-200 whitespace-nowrap
                                        ${isActive
                                            ? 'bg-slate-900 text-white shadow-lg shadow-slate-900/10'
                                            : 'bg-white text-slate-600 hover:bg-slate-50 border border-slate-200'
                                        }
                                    `}
                                >
                                    <Icon size={16} stroke={1.75} />
                                    {section.label}
                                </button>
                            );
                        })}
                    </div>

                    <Form action="/settings/company" method="patch">
                        {({ errors, processing, recentlySuccessful }) => (
                            <div className="space-y-6">
                                {/*
                                    Every section stays MOUNTED and is toggled with `hidden`,
                                    not conditionally rendered. Inertia's <Form> serializes only
                                    the inputs currently in the DOM — if inactive sections were
                                    unmounted, saving from any tab but "General" would POST a
                                    partial payload and 422 on the untouched `required` fields
                                    (name/location/phone/reconnection_fine/…). Same one-form,
                                    all-fields-present pattern as Settings/Notifications and
                                    Settings/BillPrinting.
                                */}
                                <div className={activeSection === 'general' ? '' : 'hidden'}>
                                    <Card className="animate-fade-up">
                                        <CardHeader className="border-b border-slate-100">
                                            <div className="flex items-center gap-3">
                                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                                    <IconBuildingStore size={18} stroke={1.75} />
                                                </span>
                                                <div>
                                                    <h2 className="text-base font-semibold text-slate-900">General Information</h2>
                                                    <p className="text-xs text-slate-500 mt-0.5">Basic company details</p>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardBody className="p-6">
                                            <div className="grid gap-6 md:grid-cols-2">
                                                <div className="md:col-span-2">
                                                    <TextInput
                                                        id="name"
                                                        name="name"
                                                        label="Company Name"
                                                        defaultValue={company.name}
                                                        error={errors.name}
                                                        required
                                                        placeholder="Enter company name"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                </div>
                                                <div>
                                                    <TextInput
                                                        id="location"
                                                        name="location"
                                                        label="Location"
                                                        defaultValue={company.location}
                                                        error={errors.location}
                                                        required
                                                        placeholder="e.g., 3/CORNERS"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500 flex items-center gap-1">
                                                        <IconMapPin size={12} />
                                                        Short area/town tag shown on bills
                                                    </p>
                                                </div>
                                                <div>
                                                    <TextInput
                                                        id="head_office"
                                                        name="head_office"
                                                        label="Head Office Address"
                                                        defaultValue={company.head_office ?? ''}
                                                        error={errors.head_office}
                                                        placeholder="Full postal address"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        For official documents and letterheads
                                                    </p>
                                                </div>
                                            </div>
                                        </CardBody>
                                    </Card>
                                </div>

                                <div className={activeSection === 'contact' ? '' : 'hidden'}>
                                    <Card className="animate-fade-up">
                                        <CardHeader className="border-b border-slate-100">
                                            <div className="flex items-center gap-3">
                                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                                                    <IconHeadset size={18} stroke={1.75} />
                                                </span>
                                                <div>
                                                    <h2 className="text-base font-semibold text-slate-900">Contact & Support</h2>
                                                    <p className="text-xs text-slate-500 mt-0.5">Customer support channels</p>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardBody className="p-6">
                                            <div className="grid gap-6 md:grid-cols-2">
                                                <div>
                                                    <TextInput
                                                        id="email"
                                                        name="email"
                                                        type="email"
                                                        label="Email Address"
                                                        defaultValue={company.email ?? ''}
                                                        error={errors.email}
                                                        placeholder="contact@company.com"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500 flex items-center gap-1">
                                                        <IconMail size={12} />
                                                        Primary contact email
                                                    </p>
                                                </div>
                                                <div>
                                                    <TextInput
                                                        id="phone"
                                                        name="phone"
                                                        label="Phone Number"
                                                        defaultValue={company.phone}
                                                        error={errors.phone}
                                                        required
                                                        placeholder="+237 XXX XXX XXX"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500 flex items-center gap-1">
                                                        <IconPhone size={12} />
                                                        Main business line
                                                    </p>
                                                </div>
                                                <div className="md:col-span-2">
                                                    <TextInput
                                                        id="tech_number"
                                                        name="tech_number"
                                                        label="Technical Support Number"
                                                        defaultValue={company.tech_number ?? ''}
                                                        error={errors.tech_number}
                                                        placeholder="+237 XXX XXX XXX"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Dedicated line for technical issues
                                                    </p>
                                                </div>
                                            </div>
                                        </CardBody>
                                    </Card>
                                </div>

                                <div className={activeSection === 'payments' ? '' : 'hidden'}>
                                    <Card className="animate-fade-up">
                                        <CardHeader className="border-b border-slate-100">
                                            <div className="flex items-center gap-3">
                                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                                    <IconWallet size={18} stroke={1.75} />
                                                </span>
                                                <div>
                                                    <h2 className="text-base font-semibold text-slate-900">Payment Settings</h2>
                                                    <p className="text-xs text-slate-500 mt-0.5">Mobile Money configuration</p>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardBody className="p-6">
                                            <div className="grid gap-6 md:grid-cols-2">
                                                <div>
                                                    <TextInput
                                                        id="momo_number"
                                                        name="momo_number"
                                                        label="MOMO Number(s)"
                                                        defaultValue={company.momo_number ?? ''}
                                                        error={errors.momo_number}
                                                        placeholder="+237 XXX XXX XXX"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Multiple numbers separated by commas
                                                    </p>
                                                </div>
                                                <div>
                                                    <TextInput
                                                        id="momo_name"
                                                        name="momo_name"
                                                        label="MOMO Account Name(s)"
                                                        defaultValue={company.momo_name ?? ''}
                                                        error={errors.momo_name}
                                                        placeholder="Account holder name"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Match with MOMO numbers above
                                                    </p>
                                                </div>
                                                <div className="md:col-span-2">
                                                    <TextInput
                                                        id="reconnection_fine"
                                                        name="reconnection_fine"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        label="Reconnection Fine (FCFA)"
                                                        defaultValue={company.reconnection_fine}
                                                        error={errors.reconnection_fine}
                                                        required
                                                        placeholder="5000"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Applied when reconnecting disconnected customers
                                                    </p>
                                                </div>
                                                <div className="md:col-span-2">
                                                    <TextInput
                                                        id="arrears_second_approval_threshold"
                                                        name="arrears_second_approval_threshold"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        label="Arrears Adjustment — Second Approval Threshold (FCFA)"
                                                        defaultValue={company.arrears_second_approval_threshold}
                                                        error={errors.arrears_second_approval_threshold}
                                                        required
                                                        placeholder="20000"
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        Arrears adjustments above this amount require a second, more senior approval before they take effect
                                                    </p>
                                                </div>
                                            </div>
                                        </CardBody>
                                    </Card>
                                </div>

                                <div className={activeSection === 'legal' ? '' : 'hidden'}>
                                    <Card className="animate-fade-up">
                                        <CardHeader className="border-b border-slate-100">
                                            <div className="flex items-center gap-3">
                                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-orange-50 text-orange-600">
                                                    <IconFileText size={18} stroke={1.75} />
                                                </span>
                                                <div>
                                                    <h2 className="text-base font-semibold text-slate-900">Legal & Tax Information</h2>
                                                    <p className="text-xs text-slate-500 mt-0.5">Regulatory compliance details</p>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardBody className="p-6">
                                            <div className="grid gap-6 md:grid-cols-2">
                                                <div>
                                                    <TextInput
                                                        id="rccm_number"
                                                        name="rccm_number"
                                                        label="RCCM Number"
                                                        placeholder="RC/DLA/2019/PM/127651"
                                                        defaultValue={company.rccm_number ?? ''}
                                                        error={errors.rccm_number}
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        OHADA commercial registration
                                                    </p>
                                                </div>
                                                <div>
                                                    <TextInput
                                                        id="niu"
                                                        name="niu"
                                                        label="NIU (Taxpayer Number)"
                                                        placeholder="M012345678901A"
                                                        defaultValue={company.niu ?? ''}
                                                        error={errors.niu}
                                                        className="rounded-xl px-4 py-3"
                                                    />
                                                    <p className="mt-2 text-xs text-slate-500">
                                                        DGI taxpayer identification
                                                    </p>
                                                </div>
                                            </div>
                                        </CardBody>
                                    </Card>
                                </div>

                                <div className={activeSection === 'branding' ? '' : 'hidden'}>
                                    <Card className="animate-fade-up">
                                        <CardHeader className="border-b border-slate-100">
                                            <div className="flex items-center gap-3">
                                                <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-pink-50 text-pink-600">
                                                    <IconUpload size={18} stroke={1.75} />
                                                </span>
                                                <div>
                                                    <h2 className="text-base font-semibold text-slate-900">Branding & Logo</h2>
                                                    <p className="text-xs text-slate-500 mt-0.5">Visual identity for documents</p>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardBody className="p-6">
                                            <div className="space-y-4">
                                                <label htmlFor="logo" className="text-sm font-medium text-slate-700">
                                                    Company Logo
                                                </label>
                                                <div className="flex flex-col sm:flex-row items-start gap-4 p-6 rounded-xl border-2 border-dashed border-slate-200 bg-slate-50/50 hover:border-slate-300 transition-colors">
                                                    {company.logo_url ? (
                                                        <div className="relative group">
                                                            <img
                                                                src={company.logo_url}
                                                                alt="Current company logo"
                                                                className="h-24 w-24 rounded-xl border border-slate-200 bg-white object-contain p-2 shadow-sm"
                                                            />
                                                            <div className="absolute inset-0 bg-black/40 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                                                <span className="text-xs text-white font-medium">Replace</span>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <div className="flex h-24 w-24 items-center justify-center rounded-xl border-2 border-dashed border-slate-300 bg-white">
                                                            <IconBuildingBank size={32} className="text-slate-300" />
                                                        </div>
                                                    )}
                                                    <div className="flex-1">
                                                        <input
                                                            id="logo"
                                                            name="logo"
                                                            type="file"
                                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                                            className="block w-full text-sm text-slate-600
                                                                file:mr-4 file:py-2.5 file:px-5 file:rounded-lg
                                                                file:border-0 file:text-sm file:font-medium
                                                                file:bg-slate-900 file:text-white
                                                                hover:file:bg-slate-700 file:transition-colors
                                                                file:cursor-pointer"
                                                        />
                                                        <p className="mt-3 text-xs text-slate-500 leading-relaxed">
                                                            JPG, PNG, WEBP or SVG up to 2MB. Used on bills and manuscripts.
                                                        </p>
                                                    </div>
                                                </div>
                                                {errors.logo && (
                                                    <p className="text-xs text-red-600 flex items-center gap-1">
                                                        <IconAlertCircle size={14} />
                                                        {errors.logo}
                                                    </p>
                                                )}
                                            </div>
                                        </CardBody>
                                    </Card>
                                </div>

                                {/* Action Bar */}
                                <div className="flex flex-col gap-3 bg-white rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-3">
                                        {recentlySuccessful && (
                                            <span className="flex items-center gap-1.5 text-sm font-medium text-emerald-600 animate-fade-up">
                                                <IconCheck size={16} />
                                                Changes saved successfully
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => window.location.reload()}
                                            className="w-full rounded-xl px-5 py-2.5 text-sm font-medium sm:w-auto"
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="w-full rounded-xl px-6 py-2.5 text-sm font-semibold bg-slate-900 hover:bg-slate-800 text-white shadow-lg shadow-slate-900/10 sm:w-auto"
                                        >
                                            {processing && <LoadingSpinner className="mr-2 text-white" />}
                                            {processing ? 'Saving Changes...' : 'Save Changes'}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </Form>
                </div>
            )}
        </AppLayout>
    );
}
