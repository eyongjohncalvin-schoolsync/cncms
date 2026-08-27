import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { IconAlertTriangle } from '@tabler/icons-react';
import type { AppNotification } from '@/types';

interface EmergencyBannerProps {
    notifications: AppNotification[];
}

/**
 * Full-width, persistent, critical-colored banner rendered above <main>
 * in AppLayout.tsx whenever the current user has at least one
 * unacknowledged severity: 'emergency' notification
 * (in-app-notifications.md section 4).
 *
 * This is a generic, reusable primitive — NOT hardcoded to any one
 * notification `type`. It renders whatever unacknowledged-emergency
 * notifications the `notifications` shared prop's `emergency` array
 * contains, regardless of what feature created them. The Complaint Desk
 * feature's top-tier escalation banner is meant to reuse/extend this same
 * component (references/complaint-desk.md section 6) rather than building
 * its own.
 *
 * Deliberately never dismiss-to-hide: the only way an item leaves this
 * list is the explicit "Acknowledge" button below — clicking elsewhere,
 * navigating away, or an "x" close control never counts (the PagerDuty/
 * Opsgenie model in-app-notifications.md section 5 draws on). There is no
 * close button on purpose.
 */
export function EmergencyBanner({ notifications }: EmergencyBannerProps) {
    const { t } = useTranslation();

    if (notifications.length === 0) {
        return null;
    }

    function acknowledge(uuid: string) {
        router.post(`/notifications/${uuid}/acknowledge`, {}, { preserveScroll: true, preserveState: true });
    }

    return (
        <div className="space-y-2 border-b border-red-800 bg-red-700 px-4 py-3 md:px-6">
            {notifications.map((notification) => (
                <div key={notification.uuid} className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-2 text-white">
                        <IconAlertTriangle size={20} stroke={1.75} className="mt-0.5 shrink-0" />
                        <div>
                            <p className="text-sm font-semibold">{notification.title}</p>
                            <p className="text-sm text-red-100">{notification.body}</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => acknowledge(notification.uuid)}
                        className="shrink-0 self-start rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-red-700 shadow-sm transition-colors hover:bg-red-50 sm:self-auto"
                    >
                        {t('common.acknowledge')}
                    </button>
                </div>
            ))}
        </div>
    );
}
