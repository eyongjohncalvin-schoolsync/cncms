import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { IconHourglass } from '@tabler/icons-react';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { TextInput } from '@/components/ui/TextInput';
import { LoadingSpinner } from '@/components/ui/LoadingSpinner';
import type { LandlordTenant } from '@/types';

export function formatDemoDate(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Badge text and tone for a tenant's demo state, shared with the tenants list. */
export function demoStatus(tenant: LandlordTenant): { label: string; tone: 'green' | 'yellow' | 'red' | 'slate' } | null {
    if (tenant.demo_days_left === null) {
        return tenant.activated_at ? { label: 'Full account', tone: 'green' } : null;
    }

    if (tenant.demo_days_left < 0) {
        return { label: 'Demo ended · read-only', tone: 'red' };
    }

    return { label: `Demo · ${tenant.demo_days_left} day${tenant.demo_days_left === 1 ? '' : 's'} left`, tone: tenant.demo_days_left <= 30 ? 'yellow' : 'slate' };
}

/**
 * The free demo (Landlord\TenantController::updateDemo / activate): change
 * its last day, or activate the workspace as a full account. After the
 * last day the workspace is read-only until activated.
 */
export function DemoCard({ tenant }: { tenant: LandlordTenant }) {
    const { data, setData, patch, processing, errors } = useForm({ demo_ends_on: tenant.demo_ends_on ?? '' });
    const status = demoStatus(tenant);
    const onDemo = tenant.demo_days_left !== null;

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/landlord/tenants/${tenant.id}/demo`, { preserveScroll: true });
    }

    function activate() {
        if (confirm(`Activate ${tenant.name} as a full account? The demo ends and their staff can save again.`)) {
            router.post(`/landlord/tenants/${tenant.id}/activate`, {}, { preserveScroll: true });
        }
    }

    return (
        <Card className="animate-fade-up mt-6 max-w-md" style={{ animationDelay: '110ms' }}>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <IconHourglass size={18} stroke={1.75} className="text-indigo-600" />
                        <p className="font-medium text-slate-900">Free demo</p>
                    </div>
                    <Badge tone={status?.tone ?? 'green'}>{status?.label ?? 'Full account'}</Badge>
                </div>
            </CardHeader>
            <CardBody>
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-slate-600">
                        {onDemo && tenant.demo_ends_on
                            ? (tenant.demo_days_left ?? 0) < 0
                                ? `The demo ended on ${formatDemoDate(tenant.demo_ends_on)}. Staff can sign in, view and export, but cannot save anything until you activate the account. Nothing is deleted.`
                                : `Demo from ${tenant.demo_starts_on ? formatDemoDate(tenant.demo_starts_on) : '—'} to ${formatDemoDate(tenant.demo_ends_on)} (last day). Admins get reminders 30, 7 and 1 day before; after it the workspace becomes read-only.`
                            : tenant.activated_at
                              ? `Activated on ${formatDemoDate(tenant.activated_at.slice(0, 10))}. No limits.`
                              : 'Full account, never on a demo. Setting an end date puts it on a demo.'}
                    </p>

                    <form onSubmit={submit} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <TextInput
                                label={onDemo ? 'Last day of the demo' : 'Put on a demo until'}
                                type="date"
                                value={data.demo_ends_on}
                                onChange={(event) => setData('demo_ends_on', event.target.value)}
                                error={errors.demo_ends_on}
                                required
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={processing}>
                            {processing && <LoadingSpinner className="mr-1.5" />}
                            Save date
                        </Button>
                    </form>

                    {onDemo && (
                        <div className="border-t border-slate-100 pt-4">
                            <Button type="button" variant="primary" onClick={activate} className="w-full sm:w-auto">
                                Activate full account
                            </Button>
                        </div>
                    )}
                </div>
            </CardBody>
        </Card>
    );
}
