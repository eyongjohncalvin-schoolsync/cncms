import { Head } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { IconClockHour4, IconMoodSad } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';

interface PendingProps {
    status: 'pending' | 'rejected';
    workspace_name?: string | null;
}

export default function Pending({ status, workspace_name }: PendingProps) {
    const isRejected = status === 'rejected';

    return (
        <AuthLayout>
            <Head title={isRejected ? 'Workspace not approved' : 'Awaiting approval'} />
            <div className="animate-fade-up flex flex-col items-center text-center">
                <span
                    className={`mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full ${
                        isRejected ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'
                    }`}
                >
                    {isRejected ? <IconMoodSad size={28} stroke={1.75} /> : <IconClockHour4 size={28} stroke={1.75} />}
                </span>
                <h2 className="font-display text-2xl text-slate-900">
                    {isRejected ? 'This workspace was not approved' : 'Your workspace is awaiting approval'}
                </h2>
                <p className="mt-1.5 text-sm text-slate-500">
                    {workspace_name && (
                        <>
                            Workspace: <span className="font-medium text-slate-700">{workspace_name}</span>
                            <br />
                        </>
                    )}
                    {isRejected
                        ? 'Contact support if you believe this is a mistake.'
                        : 'A landlord reviews new workspace requests before access is granted. Check back soon.'}
                </p>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="mt-6 rounded text-sm font-medium text-blue-600 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    Log out
                </Link>
            </div>
        </AuthLayout>
    );
}
