import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import { SelectInput } from '@/components/ui/SelectInput';
import { Button } from '@/components/ui/Button';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { Agent, Zone } from '@/types';

interface AgentsEditProps {
    agent: Agent;
    zones: Zone[];
}

export default function AgentsEdit({ agent, zones }: AgentsEditProps) {
    return (
        <AppLayout title="Edit Agent" breadcrumbs={[{ label: 'Agents', href: '/agents' }, { label: agent.name }]}>
            <Head title={`Edit ${agent.name}`} />

            <div className="mb-6 animate-fade-up">
                <h1 className="font-display text-2xl font-semibold tracking-tight text-slate-900">Edit Agent</h1>
                <p className="mt-1 text-sm text-slate-500">Update {agent.name}&apos;s details, zone assignment, and status.</p>
            </div>

            <Card className="max-w-2xl animate-fade-up" style={{ animationDelay: '0.08s' }}>
                <CardHeader>
                    <h2 className="font-display text-base font-semibold text-slate-900">Agent details</h2>
                    <p className="mt-0.5 text-xs text-slate-500">Fields marked required must be filled in.</p>
                </CardHeader>
                <CardBody>
                    <Form action={`/agents/${agent.uuid}`} method="put" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" required defaultValue={agent.name} error={errors.name} />
                                <TextInput
                                    id="location"
                                    name="location"
                                    label="Location"
                                    required
                                    defaultValue={agent.location}
                                    error={errors.location}
                                />
                                <TextInput id="phone" name="phone" label="Phone" required defaultValue={agent.phone} error={errors.phone} />

                                <SelectInput
                                    id="zone_uuid"
                                    name="zone_uuid"
                                    label="Zone"
                                    required
                                    defaultValue={agent.zone_uuid}
                                    error={errors.zone_uuid}
                                >
                                    {zones.map((zone) => (
                                        <option key={zone.uuid} value={zone.uuid}>
                                            {zone.name}
                                        </option>
                                    ))}
                                </SelectInput>

                                <TextInput
                                    id="salary"
                                    name="salary"
                                    label="Salary (FCFA)"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    required
                                    defaultValue={agent.salary}
                                    error={errors.salary}
                                />
                                <TextInput
                                    id="email"
                                    name="email"
                                    label="Email"
                                    type="email"
                                    defaultValue={agent.email ?? ''}
                                    error={errors.email}
                                />
                                <TextInput
                                    id="dob"
                                    name="dob"
                                    label="Date of Birth"
                                    type="date"
                                    defaultValue={agent.dob ? agent.dob.substring(0, 10) : ''}
                                    error={errors.dob}
                                />

                                <SelectInput
                                    id="marital_status"
                                    name="marital_status"
                                    label="Married"
                                    defaultValue={agent.marital_status ?? ''}
                                    error={errors.marital_status}
                                >
                                    <option value="">Unspecified</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </SelectInput>

                                <TextInput
                                    id="children"
                                    name="children"
                                    label="Number of Children"
                                    type="number"
                                    min={0}
                                    defaultValue={agent.children ?? ''}
                                    error={errors.children}
                                />

                                <SelectInput id="status" name="status" label="Status" defaultValue={agent.status} error={errors.status}>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </SelectInput>

                                <p className="text-xs text-slate-500">
                                    Last synced: {agent.last_sync_at ?? 'Never'}
                                </p>

                                <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                                    <Link href="/agents" className="w-full sm:w-auto">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                                        >
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full rounded-lg px-5 py-2.5 text-sm font-semibold sm:w-auto"
                                    >
                                        {processing ? (
                                            <>
                                                <LoadingSpinner />
                                                Saving…
                                            </>
                                        ) : (
                                            'Save Changes'
                                        )}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
