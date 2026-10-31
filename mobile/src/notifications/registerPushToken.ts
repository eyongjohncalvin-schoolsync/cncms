import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import Constants from 'expo-constants';
import { registerPushTokenRequest } from '../api/devices';
import { getOrCreateDeviceId } from '../db/syncMeta';

/**
 * Phone push for this device (docs/plans/mobile-manager-app.md, wave 2):
 * the Android channels the server's SendPushNotificationJob addresses by
 * `channelId`, the permission prompt, and registering the Expo push token
 * with POST /devices/push-token. Fire-and-forget: a refusal or a failed
 * call leaves the app working exactly as before (alerts still arrive in the
 * in-app list on the next sync); it is simply tried again next launch.
 *
 * Channels: `emergency` (heads-up, sound), `urgent` (sound), `quiet` (no
 * sound — the inbox's "Today" alerts and anything during quiet hours).
 */
export async function setUpNotificationChannels(): Promise<void> {
    if (Platform.OS !== 'android') {
        return;
    }

    await Notifications.setNotificationChannelAsync('emergency', {
        name: 'Emergencies',
        importance: Notifications.AndroidImportance.MAX,
        sound: 'default',
        vibrationPattern: [0, 400, 200, 400],
    });
    await Notifications.setNotificationChannelAsync('urgent', {
        name: 'Needs you now',
        importance: Notifications.AndroidImportance.HIGH,
        sound: 'default',
    });
    await Notifications.setNotificationChannelAsync('quiet', {
        name: 'Quiet updates',
        importance: Notifications.AndroidImportance.LOW,
        sound: null,
    });
}

export async function registerPushToken(): Promise<void> {
    // Emulators and simulators can't receive push.
    if (!Device.isDevice || (Platform.OS !== 'android' && Platform.OS !== 'ios')) {
        return;
    }

    try {
        await setUpNotificationChannels();

        let { status } = await Notifications.getPermissionsAsync();

        if (status !== 'granted') {
            ({ status } = await Notifications.requestPermissionsAsync());
        }

        if (status !== 'granted') {
            return;
        }

        const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId;
        const token = await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined);

        await registerPushTokenRequest({
            device_id: await getOrCreateDeviceId(),
            expo_push_token: token.data,
            platform: Platform.OS,
        });
    } catch {
        // Best effort — see the file doc.
    }
}
