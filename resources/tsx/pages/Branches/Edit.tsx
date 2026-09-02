import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Branch } from '@/types';

interface BranchesEditProps {
    branch: Branch;
}

export default function BranchesEdit({ branch }: BranchesEditProps) {
    return (
        <AppLayout
            title={`Edit ${branch.name}`}
            breadcrumbs={[
                { label: 'Branches', href: '/branches' },
                { label: branch.name, href: `/branches/${branch.uuid}/edit` },
                { label: 'Edit' },
            ]}
        >
            <Head title={`Edit ${branch.name}`} />

            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-2xl text-slate-900">Edit {branch.name}</h2>
                <p className="mt-1 text-sm text-slate-500">Update this branch's name.</p>
            </div>

            <Card className="animate-fade-up max-w-md" style={{ animationDelay: '0.05s' }}>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Branch details</h3>
                </CardHeader>
                <CardBody>
                    <Form action={`/branches/${branch.uuid}`} method="patch" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput
                                    id="name"
                                    name="name"
                                    label="Name"
                                    defaultValue={branch.name}
                                    error={errors.name}
                                    required
                                />

                                <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
                                    <Button type="submit" disabled={processing} className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                        {processing && <LoadingSpinner className="text-white" />}
                                        {processing ? 'Saving…' : 'Save Changes'}
                                    </Button>
                                    <Link href="/branches" className="w-full sm:w-auto">
                                        <Button type="button" variant="secondary" className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto">
                                            Cancel
                                        </Button>
                                    </Link>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
