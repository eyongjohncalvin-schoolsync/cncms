import { Form, Head } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

type Overridable =
    | 'location'
    | 'phone'
    | 'email'
    | 'tech_number'
    | 'momo_number'
    | 'momo_name'
    | 'reconnection_fine'
    | 'relocation_fee'
    | 'reinstallation_fee';

interface BranchSettingsProps {
    branch: { uuid: string; name: string; code: string | null } & Record<Overridable, string | null>;
    /** Headquarters' value for each field — what the branch uses when its own is empty. */
    headquarters: Record<Overridable, string | null>;
    can_manage_branches: boolean;
}

const CONTACT_FIELDS: { name: Overridable; label: string; type?: string }[] = [
    { name: 'location', label: 'Address' },
    { name: 'phone', label: 'Phone' },
    { name: 'email', label: 'Email', type: 'email' },
    { name: 'tech_number', label: 'Technician line' },
    { name: 'momo_number', label: 'MoMo number' },
    { name: 'momo_name', label: 'MoMo account name' },
];

const FEE_FIELDS: { name: Overridable; label: string }[] = [
    { name: 'reconnection_fine', label: 'Reconnection fine (FCFA)' },
    { name: 'relocation_fee', label: 'Relocation fee (FCFA)' },
    { name: 'reinstallation_fee', label: 'Re-installation fee (FCFA)' },
];

/**
 * A branch's own contact details, MoMo number and fees (branches v2 D4,
 * App\Http\Controllers\BranchSettingsController). What its bills, receipts
 * and messages print and what its staff charge; an empty field uses
 * headquarters' value, shown as the placeholder.
 */
export default function BranchSettings({ branch, headquarters, can_manage_branches }: BranchSettingsProps) {
    const placeholder = (field: Overridable) => (headquarters[field] ? `HQ: ${headquarters[field]}` : 'Not set at HQ');

    return (
        <AppLayout
            title={`${branch.name} settings`}
            breadcrumbs={[
                ...(can_manage_branches ? [{ label: 'Branches', href: '/branches' }] : []),
                { label: branch.name },
                { label: 'Details & fees' },
            ]}
        >
            <Head title={`${branch.name} settings`} />

            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-2xl text-slate-900">{branch.name}</h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">
                    What this branch's bills, receipts and messages show, and the fees its staff charge. Leave a
                    field empty to use headquarters' value.
                </p>
            </div>

            <Form action={`/branches/${branch.uuid}/settings`} method="patch" options={{ preserveScroll: true }}>
                {({ errors, processing }) => (
                    <div className="grid max-w-4xl gap-6 lg:grid-cols-2">
                        <Card className="animate-fade-up">
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Contact &amp; MoMo</h3>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-4">
                                <TextInput
                                    id="code"
                                    name="code"
                                    label="Short code (e.g. KBA)"
                                    defaultValue={branch.code ?? ''}
                                    error={errors.code}
                                    maxLength={10}
                                />
                                {CONTACT_FIELDS.map((field) => (
                                    <TextInput
                                        key={field.name}
                                        id={field.name}
                                        name={field.name}
                                        type={field.type ?? 'text'}
                                        label={field.label}
                                        defaultValue={branch[field.name] ?? ''}
                                        placeholder={placeholder(field.name)}
                                        error={errors[field.name]}
                                    />
                                ))}
                            </CardBody>
                        </Card>

                        <Card className="animate-fade-up" style={{ animationDelay: '0.05s' }}>
                            <CardHeader>
                                <h3 className="text-sm font-semibold text-slate-900">Fees</h3>
                            </CardHeader>
                            <CardBody className="flex flex-col gap-4">
                                {FEE_FIELDS.map((field) => (
                                    <TextInput
                                        key={field.name}
                                        id={field.name}
                                        name={field.name}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        label={field.label}
                                        defaultValue={branch[field.name] ?? ''}
                                        placeholder={placeholder(field.name)}
                                        error={errors[field.name]}
                                    />
                                ))}
                                <p className="text-xs text-slate-500">
                                    A lower fee than headquarters' is allowed — towns with competition may charge less.
                                </p>
                            </CardBody>
                        </Card>

                        <div className="lg:col-span-2">
                            <Button type="submit" disabled={processing} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                {processing && <LoadingSpinner className="text-white" />}
                                {processing ? 'Saving…' : 'Save settings'}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </AppLayout>
    );
}
