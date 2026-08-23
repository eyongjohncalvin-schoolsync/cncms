import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';
import type { Zone } from '@/types';

export default function ZonesEdit({ zone }: { zone: Zone }) {
    return (
        <AppLayout title={`Edit ${zone.name}`}>
            <Head title={`Edit ${zone.name}`} />

            <Card className="max-w-md">
                <CardBody>
                    <Form action={`/zones/${zone.uuid}`} method="patch" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput
                                    id="name"
                                    name="name"
                                    label="Name"
                                    defaultValue={zone.name}
                                    error={errors.name}
                                    required
                                />
                                <TextInput id="town" name="town" label="Town" defaultValue={zone.town} error={errors.town} />

                                <div className="flex items-center gap-3 pt-2">
                                    <Button type="submit" disabled={processing}>
                                        Save Changes
                                    </Button>
                                    <Link href="/zones" className="text-sm text-slate-600 hover:underline">
                                        Cancel
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
