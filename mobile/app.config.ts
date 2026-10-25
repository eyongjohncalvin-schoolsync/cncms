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
    // Keep in step with AGENT_APP_VERSION on the server: a lower installed
    // version shows agents the "new version available" banner.
    version: '1.0.1',
    // Over-the-air updates (expo-updates): a JS-only change published with
    // `eas update` reaches phones on their next app open. The fingerprint
    // policy means an update only ever lands on a build with the same native
    // code — anything native (a new module, a permission) needs a new APK.
    runtimeVersion: { policy: 'fingerprint' },
    updates: {
        url: 'https://u.expo.dev/71316a4b-46b5-4f19-83f5-5c54a5bc62b0',
        checkAutomatically: 'ON_LOAD',
        // Open at once with what the phone has; a new update downloads in the
        // background and applies on the next open — never a wait on slow data.
        fallbackToCacheTimeout: 0,
    },
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
            // Matches the OneTech mark's own gradient base (dark purple) —
            // only used as a fallback fill if backgroundImage can't load,
            // so keep it in the same family as android-icon-background.png.
            backgroundColor: '#1E0B4B',
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
        [
            'expo-splash-screen',
            {
                // Was previously registered with no options — the native
                // splash screen fell back to Expo's own default (a plain
                // white flash) even though assets/splash-icon.png existed
                // in the repo; it was never actually wired to anything.
                image: './assets/splash-icon.png',
                imageWidth: 200,
                backgroundColor: '#1E0B4B',
                dark: {
                    image: './assets/splash-icon.png',
                    backgroundColor: '#1E0B4B',
                },
            },
        ],
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
