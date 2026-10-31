import { useEffect } from 'react';
import * as Notifications from 'expo-notifications';
import { useRouter } from 'expo-router';
import { registerPushToken } from './registerPushToken';
import { refreshInbox } from '../inbox/inboxStore';
import { resolvePushTapTarget, type PushTapData } from '../utils/pushRouting';

// Shown even while the app is open — a manager in the app still wants the banner.
Notifications.setNotificationHandler({
    handleNotification: async () => ({
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: true,
        shouldSetBadge: false,
    }),
});

/** Taps already acted on, so a re-mount never opens the same one twice. */
const opened = new Set<string>();

function dataOf(notification: Notifications.Notification): PushTapData {
    return (notification.request.content.data ?? {}) as PushTapData;
}

/**
 * Phone push while signed in (docs/plans/mobile-manager-app.md, wave 2):
 * registers this device, refreshes the inbox when an alert about a
 * decision arrives, and opens the right screen when one is tapped —
 * including the tap that launched the app. Mounted once by the signed-in
 * tabs layout.
 */
export function usePushNotifications(): void {
    const router = useRouter();

    useEffect(() => {
        void registerPushToken();

        const received = Notifications.addNotificationReceivedListener((notification) => {
            if (resolvePushTapTarget(dataOf(notification)) === '/inbox') {
                void refreshInbox();
            }
        });

        const open = (response: Notifications.NotificationResponse | null) => {
            const id = response?.notification.request.identifier;

            if (response && id !== undefined && !opened.has(id)) {
                opened.add(id);
                router.push(resolvePushTapTarget(dataOf(response.notification)));
            }
        };

        const tapped = Notifications.addNotificationResponseReceivedListener(open);
        void Notifications.getLastNotificationResponseAsync().then(open);

        return () => {
            received.remove();
            tapped.remove();
        };
    }, [router]);
}
