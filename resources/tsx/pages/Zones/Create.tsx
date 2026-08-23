import { Form, Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/layouts/AppLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { TextInput } from '@/components/ui/TextInput';

export default function ZonesCreate() {
    return (
        <AppLayout title="Add Zone">
            <Head title="Add Zone" />

            <Card className="max-w-md">
                <CardBody>
                    <Form action="/zones" method="post" className="flex flex-col gap-4">
                        {({ errors, processing }) => (
                            <>
                                <TextInput id="name" name="name" label="Name" error={errors.name} required />
                                <TextInput id="town" name="town" label="Town" error={errors.town} />

                                <div className="flex items-center gap-3 pt-2">
                                    <Button type="submit" disabled={processing}>
                                        Create Zone
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
