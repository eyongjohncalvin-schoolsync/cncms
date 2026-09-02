import { useEffect, useRef } from 'react';
import { ActivityIndicator, View, StyleSheet, Text } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { Stack, useRouter, useSegments } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { DatabaseProvider, useDatabaseStatus } from '../src/db/DatabaseProvider';
import { syncManager } from '../src/sync/SyncManager';
import { refreshNotificationsState } from '../src/notifications/notificationStore';
import { getEmergenciesNeedingInterrupt } from '../src/db/notifications';
import { shouldTriggerEmergencyInterrupt } from '../src/utils/emergencyState';
import { colors } from '../src/theme/colors';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { retry: 1 },
    },
});

/**
 * Auth-gated navigation shell. Standard Expo Router "protected routes"
 * pattern via useSegments()/useRouter() redirects: an unauthenticated user
 * is force-routed into the (auth) stack and can never see (tabs); an
 * authenticated user landing on (auth) is bounced into (tabs). See
 * mobile-app-react-native.md §4's IA and the architecture-brainstorm
 * expert's (auth)/(tabs) grouping this mirrors.
 */
function RootNavigation() {
    const { status } = useAuth();
    const { ready, error } = useDatabaseStatus();
    const segments = useSegments();
    const router = useRouter();
    const emergencyCheckedThisSession = useRef(false);

    useEffect(() => {
        if (!ready || status === 'loading') {
            return;
        }

        const inAuthGroup = segments[0] === '(auth)';

        if (status === 'unauthenticated' && !inAuthGroup) {
            router.replace('/(auth)/login');
        } else if (status === 'authenticated' && inAuthGroup) {
            router.replace('/(tabs)');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [status, ready, segments.join('/')]);

    useEffect(() => {
        // SyncManager is self-contained (owns its own AppState/NetInfo/timer
        // listeners) — started exactly once, only once both the local DB is
        // ready and there is a real session to sync for. Never started for
        // an unauthenticated session; stopping it on logout is deliberately
        // NOT done here since a background push mid-logout should still be
        // allowed to finish rather than being torn down abruptly — the next
        // login will find start() already idempotent.
        if (ready && status === 'authenticated') {
            syncManager.start();
        }
    }, [ready, status]);

    // complaint-desk.md section 7: the full-screen emergency interrupt
    // fires "on next app open when an unacknowledged severity: 'emergency'
    // notification exists" — checked exactly once per app open (the
    // `emergencyCheckedThisSession` guard), from the LOCAL cache (source of
    // truth, available even before this session's first pull completes —
    // an agent who force-quit the app with an unacknowledged emergency
    // still sees it immediately on relaunch, offline or not) rather than
    // waiting on a live network call. Deliberately NOT re-checked on every
    // foreground/segment change — that would turn a one-time interrupt
    // into a repeated one, which is exactly the "never a blocking modal"
    // rule this screen is the sole deliberate exception to; every
    // subsequent moment until acknowledged is the persistent
    // EmergencyBanner's job instead (mobile-app-react-native.md section 5 /
    // complaint-desk.md section 7).
    useEffect(() => {
        if (!ready || status !== 'authenticated' || emergencyCheckedThisSession.current) {
            return;
        }

        emergencyCheckedThisSession.current = true;

        void refreshNotificationsState();

        void getEmergenciesNeedingInterrupt().then((rows) => {
            if (shouldTriggerEmergencyInterrupt(rows.length)) {
                router.push('/emergency');
            }
        });
    }, [ready, status, router]);

    if (error) {
        return (
            <View style={styles.center}>
                <Text style={styles.errorTitle}>Couldn't start the local database</Text>
                <Text style={styles.errorBody}>{error}</Text>
            </View>
        );
    }

    if (!ready || status === 'loading') {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" color={colors.accent.payment} />
            </View>
        );
    }

    return (
        <Stack screenOptions={{ headerShown: false }}>
            <Stack.Screen name="(auth)" />
            <Stack.Screen name="(tabs)" />
            <Stack.Screen
                name="record-expense"
                options={{ presentation: 'modal', headerShown: true, title: 'Record Expense' }}
            />
            <Stack.Screen
                name="log-complaint"
                options={{ presentation: 'modal', headerShown: true, title: 'Log a Complaint' }}
            />
            <Stack.Screen
                name="notifications"
                options={{ presentation: 'modal', headerShown: true, title: 'Notifications' }}
            />
            <Stack.Screen
                name="sync-status"
                options={{ presentation: 'modal', headerShown: true, title: 'Sync Status' }}
            />
            <Stack.Screen
                name="reconnect/[uuid]"
                options={{ presentation: 'modal', headerShown: true, title: 'Reconnect & Pay' }}
            />
            <Stack.Screen
                name="disconnect/[uuid]"
                options={{ presentation: 'modal', headerShown: true, title: 'Disconnect' }}
            />
            {/*
              2026-08-28: the mobile REQUEST side of the Arrears Adjustment
              maker-checker workflow (arrears-adjustment.md) — see
              app/adjust-arrears/[uuid].tsx's own doc comment. Registered
              alongside reconnect/disconnect above since it's the same
              "per-customer, online-only, modal-with-a-title" shape.
            */}
            <Stack.Screen
                name="adjust-arrears/[uuid]"
                options={{ presentation: 'modal', headerShown: true, title: 'Adjust Arrears / Credit' }}
            />
            {/*
              Wave 2 of payment-receipts-and-whatsapp.md — read-only view of a
              verified payment's business-issued receipt, reached by the
              payment's server uuid from Customer Detail's "Last payment"
              card. Same per-customer, online-only, modal-with-a-title shape
              as reconnect/disconnect/adjust-arrears above.
            */}
            <Stack.Screen
                name="receipt/[uuid]"
                options={{ presentation: 'modal', headerShown: true, title: 'Receipt' }}
            />
            {/*
              2026-08-27: 7 new screens landed in parallel this session (see
              (tabs)/more.tsx's doc comment for the full IA reasoning), each
              reachable from the new More tab. Every one already sets its own
              in-screen title via its own <Stack.Screen options> where it
              needs one, matching this app's existing per-screen-title
              convention (see reconnect/disconnect above) — headerShown:true
              here just guarantees the native back arrow every modal screen
              in this app already gets.
            */}
            <Stack.Screen name="settings" options={{ presentation: 'modal', headerShown: true, title: 'Settings' }} />
            {/*
              2026-08-27 addendum: self-service profile/password update
              (mobile-app-react-native.md §11 addendum) — both reached one
              tap deeper from settings.tsx's Profile card, same modal-route
              shape as every other form screen above.
            */}
            <Stack.Screen
                name="edit-profile"
                options={{ presentation: 'modal', headerShown: true, title: 'Edit Profile' }}
            />
            <Stack.Screen
                name="change-password"
                options={{ presentation: 'modal', headerShown: true, title: 'Change Password' }}
            />
            <Stack.Screen name="reports" options={{ presentation: 'modal', headerShown: true, title: 'Reports' }} />
            <Stack.Screen name="resources" options={{ presentation: 'modal', headerShown: true, title: 'Resources' }} />
            <Stack.Screen name="zones" options={{ presentation: 'modal', headerShown: true, title: 'Zones' }} />
            <Stack.Screen
                name="agent-profile"
                options={{ presentation: 'modal', headerShown: true, title: 'My Profile' }}
            />
            <Stack.Screen
                name="disconnections"
                options={{ presentation: 'modal', headerShown: true, title: 'Disconnections' }}
            />
            <Stack.Screen
                name="complaints"
                options={{ presentation: 'modal', headerShown: true, title: 'Complaints' }}
            />
            <Stack.Screen
                name="manuscript"
                options={{ presentation: 'modal', headerShown: true, title: 'Manuscript' }}
            />
            {/*
              complaint-desk.md section 7: the one screen in this app that
              deliberately blocks. fullScreenModal (not 'modal') + no
              header + gestureEnabled:false means no swipe-down-to-dismiss
              and no back gesture/button — the ONLY way off this screen is
              the in-screen "Acknowledge" button (app/emergency.tsx),
              because dismiss must never be mistakable for acknowledge.
            */}
            <Stack.Screen
                name="emergency"
                options={{ presentation: 'fullScreenModal', headerShown: false, gestureEnabled: false }}
            />
        </Stack>
    );
}

export default function RootLayout() {
    return (
        <GestureHandlerRootView style={{ flex: 1 }}>
            <SafeAreaProvider>
                <QueryClientProvider client={queryClient}>
                    <DatabaseProvider>
                        <AuthProvider>
                            <RootNavigation />
                        </AuthProvider>
                    </DatabaseProvider>
                </QueryClientProvider>
                <StatusBar style="dark" />
            </SafeAreaProvider>
        </GestureHandlerRootView>
    );
}

const styles = StyleSheet.create({
    center: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.background,
        padding: 24,
        gap: 8,
    },
    errorTitle: {
        fontSize: 16,
        fontWeight: '700',
        color: colors.danger,
        textAlign: 'center',
    },
    errorBody: {
        fontSize: 14,
        color: colors.textSecondary,
        textAlign: 'center',
    },
});
