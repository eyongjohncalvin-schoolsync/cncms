import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { IconClockHour4, IconLoader2, IconMoodSad } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';

interface PendingProps {
    /** The tenant schema is still being built on the queue (no membership row yet). */
    provisioning: boolean;
    status: 'pending' | 'rejected' | 'approved';
    workspace_name?: string | null;
}

export default function Pending({ provisioning, status, workspace_name }: PendingProps) {
    const isRejected = status === 'rejected';

    // Poll while the workspace is still being set up OR is waiting on a
    // landlord — advance to the dashboard the moment it's approved, so the
    // user never has to refresh by hand.
    const polling = provisioning || status === 'pending';
    usePoll(polling ? 5000 : 0, { only: ['provisioning', 'status', 'workspace_name'] });

    useEffect(() => {
        if (status === 'approved') {
            router.visit('/dashboard');
        }
    }, [status]);

    const title = isRejected ? 'Workspace not approved' : provisioning ? 'Setting up your workspace' : 'Awaiting approval';

    return (
        <AuthLayout>
            <Head title={title} />
            <div className="animate-fade-up flex flex-col items-center text-center">
                <span
                    className={`mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full ${
                        isRejected ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'
                    }`}
                >
                    {isRejected ? (
                        <IconMoodSad size={28} stroke={1.75} />
                    ) : provisioning ? (
                        <IconLoader2 size={28} stroke={1.75} className="animate-spin" />
                    ) : (
                        <IconClockHour4 size={28} stroke={1.75} />
                    )}
                </span>
                <h2 className="font-display text-2xl text-slate-900">
                    {isRejected
                        ? 'This workspace was not approved'
                        : provisioning
                          ? 'Setting up your workspace…'
                          : 'Your workspace is awaiting approval'}
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
                        : provisioning
                          ? 'This takes a minute — the page updates itself when it’s ready.'
                          : 'A landlord reviews new workspace requests before access is granted. This page updates itself once approved.'}
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
