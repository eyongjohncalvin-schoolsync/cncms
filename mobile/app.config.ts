import type { ExpoConfig, ConfigContext } from 'expo/config';

/**
 * Dynamic config (app.config.ts) instead of static app.json, so the API
 * base URL and other environment-dependent values can be read from process
 * env at build/start time. See src/api/config.ts for the actual runtime
 * resolution used by the app (EXPO_PUBLIC_API_BASE_URL); `extra.apiBaseUrl`
 * here is kept for parity/inspection via Constants.expoConfig.extra and for
 * EAS Build profiles to override per-environment later.
 */
export default ({ config }: ConfigContext): ExpoConfig => ({
    ...config,
    name: 'CNCMS Field Agent',
    slug: 'cncms-mobile',
    scheme: 'cncms',
    version: '1.0.0',
    orientation: 'portrait',
    icon: './assets/icon.png',
    userInterfaceStyle: 'light', // dark mode deliberately deferred — mobile-app-react-native.md §6
    ios: {
        supportsTablet: true,
        bundleIdentifier: 'com.shalomtech.cncms.mobile',
    },
    android: {
        // Android-first per mobile-app-react-native.md §1 — Cameroon's
        // Android market share dominance means iOS isn't a v1 priority.
        package: 'com.shalomtech.cncms.mobile',
        adaptiveIcon: {
            backgroundColor: '#E6F4FE',
            foregroundImage: './assets/android-icon-foreground.png',
            backgroundImage: './assets/android-icon-background.png',
            monochromeImage: './assets/android-icon-monochrome.png',
        },
        predictiveBackGestureEnabled: false,
    },
    web: {
        favicon: './assets/favicon.png',
        bundler: 'metro',
    },
    plugins: [
        'expo-router',
        'expo-secure-store',
        'expo-splash-screen',
        'expo-sqlite',
        'expo-status-bar',
        [
            'expo-image-picker',
            {
                // Camera-only capture for receipt photos (Record Expense /
                // Record Payment) per mobile-app-react-native.md §4 — no
                // gallery picker, so no photo-library usage description is
                // requested.
                cameraPermission: 'Allow $(PRODUCT_NAME) to use the camera to attach receipt photos.',
            },
        ],
        // Expo push notifications (mobile-push-notifications build notes).
        // No extra plugin config needed for v1 — the default notification
        // icon/color and Android channels are set up at runtime instead
        // (src/notifications/registerPushToken.ts's ensureAndroidChannels(),
        // called before every token registration, since Expo requires
        // channels to exist before a token is meaningfully associated with
        // one on Android).
        'expo-notifications',
    ],
    extra: {
        apiBaseUrl: process.env.EXPO_PUBLIC_API_BASE_URL ?? null,
        // Set 2026-08 via `eas build` (owner ran this from their own Expo
        // account, @miskhan) — EAS CLI can't auto-write this into a dynamic
        // config (app.config.ts), only a static app.json, so it's filled in
        // by hand here per its own printed instructions. Required for
        // getExpoPushTokenAsync() to work at all — registerPushToken() below
        // detects a missing id and skips registration silently otherwise.
        eas: { projectId: '71316a4b-46b5-4f19-83f5-5c54a5bc62b0' },
    },
});
