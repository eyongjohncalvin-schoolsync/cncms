import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { IconTool } from '@tabler/icons-react';
import type { MaintenanceNotice } from '@/types';
import { formatCountdown, formatMaintenanceTime, useCountdown } from '@/hooks/useCountdown';

/**
 * Countdown strip for the shared `maintenance` prop (App\Support\
 * MaintenanceMode), rendered by AppLayout, LandlordLayout and AuthLayout.
 * Amber while scheduled ("starts in …"); red while running, which only a
 * landlord ever sees here — everyone else is held on pages/Maintenance.
 *
 * When a countdown reaches zero the page reloads, so a tenant user is moved
 * onto the maintenance page (ResolveTenantWeb) the moment it starts.
 */
export function MaintenanceBanner({ notice }: { notice: MaintenanceNotice | null | undefined }) {
    const { t, i18n } = useTranslation();
    const remaining = useCountdown(notice ? (notice.active ? notice.ends_at : notice.starts_at) : null);
    const reachedZero = notice !== null && notice !== undefined && remaining === 0;

    useEffect(() => {
        if (reachedZero) {
            router.reload();
        }
    }, [reachedZero]);

    if (!notice) {
        return null;
    }

    const values = {
        countdown: formatCountdown(remaining),
        start: formatMaintenanceTime(notice.starts_at, i18n.language),
        end: formatMaintenanceTime(notice.ends_at, i18n.language),
    };

    return (
        <div
            role="status"
            className={`flex items-start gap-2.5 border-b px-4 py-2.5 text-sm md:px-6 ${
                notice.active ? 'border-red-800 bg-red-700 text-white' : 'border-amber-300 bg-amber-100 text-amber-900'
            }`}
        >
            <IconTool size={18} stroke={1.75} className="mt-0.5 shrink-0" />
            <div className="min-w-0">
                <p className="font-semibold tabular-nums">
                    {notice.active ? t('common.maintenance_active_title') : t('common.maintenance_scheduled_title', values)}
                </p>
                <p className={notice.active ? 'text-red-100' : 'text-amber-800'}>
                    {notice.active ? t('common.maintenance_active_body', values) : t('common.maintenance_scheduled_body', values)}
                    {notice.message && <span className="block">{notice.message}</span>}
                </p>
            </div>
        </div>
    );
}
