import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { IconTool } from '@tabler/icons-react';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { MaintenanceNotice } from '@/types';
import { formatCountdown, formatMaintenanceTime, useCountdown } from '@/hooks/useCountdown';

/**
 * Where ResolveTenantWeb holds tenant users while a maintenance window is
 * running (App\Http\Controllers\MaintenanceController). Counts down to the
 * end, and polls so an early end or an extension shows up without a manual
 * refresh — the server redirects to the dashboard once the window is over.
 */
export default function Maintenance({ window: notice }: { window: MaintenanceNotice }) {
    const { t, i18n } = useTranslation();
    const remaining = useCountdown(notice.ends_at);

    usePoll(30000, { only: ['window'] });

    useEffect(() => {
        if (remaining === 0) {
            router.visit('/dashboard');
        }
    }, [remaining]);

    return (
        <AuthLayout>
            <Head title={t('common.maintenance_page_title')} />
            <div className="animate-fade-up flex flex-col items-center text-center">
                <span className="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                    <IconTool size={28} stroke={1.75} />
                </span>
                <h2 className="font-display text-2xl text-slate-900">{t('common.maintenance_page_heading')}</h2>
                {notice.message && <p className="mt-2 text-sm text-slate-600">{notice.message}</p>}

                <p className="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('common.maintenance_page_back_in')}</p>
                <p className="font-display text-5xl tabular-nums text-slate-900">{formatCountdown(remaining)}</p>
                <p className="mt-2 text-sm text-slate-500">
                    {t('common.maintenance_page_until', { end: formatMaintenanceTime(notice.ends_at, i18n.language) })}
                </p>

                <p className="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
                    {t('common.maintenance_page_offline_note')}
                </p>
                <p className="mt-3 text-xs text-slate-400">{t('common.maintenance_page_refreshes')}</p>

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="mt-6 rounded text-sm font-medium text-blue-600 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    {t('common.log_out')}
                </Link>
            </div>
        </AuthLayout>
    );
}
