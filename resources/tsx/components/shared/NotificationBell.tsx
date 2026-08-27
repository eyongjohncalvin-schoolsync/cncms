import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { IconBell } from '@tabler/icons-react';
import type { AppNotification, NotificationSeverity, PageProps } from '@/types';

const SEVERITY_BORDER: Record<NotificationSeverity, string> = {
    info: 'border-blue-500',
    warning: 'border-amber-500',
    urgent: 'border-orange-500',
    emergency: 'border-red-600',
};

/**
 * Header bell (AppLayout.tsx's `auth.user` cluster, next to
 * LanguageSwitcher/RoleBadge) — badge shows the unread count, panel lists
 * recent notifications with a severity-colored left border reusing the
 * same `border-l-4` language as AppLayout's flash.success/flash.error
 * banners (in-app-notifications.md section 4).
 *
 * Reads the `notifications` shared prop off usePage() — it does not poll
 * itself; AppLayout's usePoll(20000, { only: ['notifications'] }) is what
 * keeps that prop fresh. Renders nothing when the prop is null (no
 * authenticated tenant-scoped user).
 */
export function NotificationBell() {
    const { t } = useTranslation();
    const { notifications } = usePage<PageProps>().props;

    if (!notifications) {
        return null;
    }

    const { items, unread_count: unreadCount } = notifications;

    /**
     * Marking read is a passive side effect of opening the item in the
     * dropdown (in-app-notifications.md section 5) — deliberately never
     * treated as "acknowledge". Navigation to `link` only happens once the
     * mark-read POST finishes, so the redirect-back's refreshed
     * `notifications` prop is never raced by the next page's own load.
     */
    function open(notification: AppNotification) {
        router.post(
            `/notifications/${notification.uuid}/read`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    if (notification.link) {
                        router.visit(notification.link);
                    }
                },
            },
        );
    }

    function markAllRead() {
        router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: true });
    }

    return (
        <Popover className="relative">
            <PopoverButton
                className="relative rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                title={t('common.notifications')}
            >
                <IconBell size={18} stroke={1.75} />
                {unreadCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-none text-white">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </PopoverButton>

            <PopoverPanel
                anchor="bottom end"
                className="z-40 mt-2 w-80 rounded-lg border border-slate-200 bg-white shadow-lg shadow-slate-900/10"
            >
                <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                    <span className="text-sm font-semibold text-slate-900">{t('common.notifications')}</span>
                    {unreadCount > 0 && (
                        <button
                            type="button"
                            onClick={markAllRead}
                            className="text-xs font-medium text-blue-600 hover:text-blue-700"
                        >
                            {t('common.mark_all_read')}
                        </button>
                    )}
                </div>

                <div className="max-h-96 overflow-y-auto">
                    {items.length === 0 && (
                        <p className="px-3 py-6 text-center text-sm text-slate-400">{t('common.no_notifications')}</p>
                    )}

                    {items.map((notification) => (
                        <button
                            key={notification.uuid}
                            type="button"
                            onClick={() => open(notification)}
                            className={`block w-full border-l-4 px-3 py-2.5 text-left text-sm transition-colors hover:bg-slate-50 ${
                                SEVERITY_BORDER[notification.severity]
                            } ${notification.read_at ? 'bg-white' : 'bg-blue-50/50'}`}
                        >
                            <p className="font-medium text-slate-900">{notification.title}</p>
                            <p className="mt-0.5 line-clamp-2 text-slate-500">{notification.body}</p>
                        </button>
                    ))}
                </div>
            </PopoverPanel>
        </Popover>
    );
}
