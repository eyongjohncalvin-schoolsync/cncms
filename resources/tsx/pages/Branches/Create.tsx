import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';

export default function BranchesCreate() {
    return (
        <AppLayout title="Add Branch" breadcrumbs={[{ label: 'Branches', href: '/branches' }, { label: 'Add Branch' }]}>
            <Head title="Add Branch" />

            <div className="animate-fade-up mb-6">
                <h2 className="font-display text-2xl text-slate-900">Add Branch</h2>
                <p className="mt-1 text-sm text-slate-500">Create a new office/location for this operator.</p>
            </div>

            <Card className="animate-fade-up max-w-md" style={{ animationDelay: '0.05s' }}>
                <CardHeader>
                    <h3 className="text-sm font-semibold text-slate-900">Branch details</h3>
                </CardHeader>
                <CardBody>
                    <Form action="/branches" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" error={errors.name} required />

                                <div className="flex items-center gap-3 border-t border-slate-200 pt-4">
                                    <Button type="submit" disabled={processing} className="rounded-lg px-5 py-2.5 text-sm font-semibold">
                                        {processing && <LoadingSpinner className="text-white" />}
                                        {processing ? 'Creating…' : 'Create Branch'}
                                    </Button>
                                    <Link href="/branches">
                                        <Button type="button" variant="secondary" className="rounded-lg px-5 py-2.5 text-sm font-semibold">
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
