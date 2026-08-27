import { useCallback, useState, useSyncExternalStore } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import { Card } from '../src/components/ui/Card';
import { Button } from '../src/components/ui/Button';
import { DetailRow } from '../src/components/ui/DetailRow';
import { useAuth } from '../src/auth/AuthContext';
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { extractErrorMessage } from '../src/api/client';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';

/**
 * "Settings" — the field agent's OWN app settings, not a mirror of the web
 * admin's tenant-wide Settings pages (Company/Notifications/Bill Printing/
 * Command Run are all genuinely admin-office-only, gated by
 * SettingsUserController/SettingsNotificationController's `authorize()`
 * calls, and out of scope here by design, not oversight).
 *
 * Deliberately scoped to only what a real API endpoint supports today:
 *   - Profile: display of the `/auth/me` identity + role, with real
 *     "Edit profile" and "Change password" actions (2026-08-27 addendum —
 *     see mobile-app-react-native.md §11 addendum). This was read-only
 *     until PATCH /auth/profile and PATCH /auth/password existed — see
 *     app/edit-profile.tsx / app/change-password.tsx, both new standalone
 *     modal routes. Role stays display-only here (no self-service role
 *     change exists or should exist — that's an office-only action, see
 *     SettingsUserController::update()).
 *   - Notification preferences: NOT built. App\Models\NotificationSetting is
 *     a single-row-PER-TENANT table (whatsapp/email/sms channel toggles +
 *     Twilio credentials), gated by SettingsNotificationController's
 *     `authorize('view', NotificationSetting::class)` — there is no
 *     per-user notification-preference model or endpoint to wire up.
 *   - Language: NOT built. language-support.md itself is marked "Design,
 *     not yet implemented — build on explicit go-ahead only"; the
 *     `users.locale` column and any locale-setting endpoint it describes
 *     don't exist in the codebase yet, so there is nothing real to call.
 *   - Logout: reuses useAuth().logout(), which already leaves local SQLite
 *     (including any unsynced payments/expenditures) fully intact — see
 *     AuthContext.tsx's own doc comment. Still gated behind a native confirm
 *     (matches this app's existing point-of-no-return convention — see
 *     app/reconnect/[uuid].tsx / app/disconnect/[uuid].tsx), and the confirm
 *     copy calls out any still-queued items so an agent isn't surprised that
 *     syncing pauses until they log back in.
 *   - App version: static, read from app.config.ts via expo-constants — no
 *     backend involved.
 */
export default function SettingsScreen() {
    const router = useRouter();
    const { user, role, logout } = useAuth();
    const syncState = useSyncExternalStore(subscribeSyncState, getSyncState);
    const [loggingOut, setLoggingOut] = useState(false);

    const performLogout = useCallback(async () => {
        setLoggingOut(true);

        try {
            await logout();
        } catch (error) {
            // logout() already best-effort-catches the network call itself
            // (see AuthContext.tsx) and always clears local session state —
            // this is a defensive net for anything unexpected, not the
            // expected path.
            Alert.alert('Something went wrong', extractErrorMessage(error));
        } finally {
            setLoggingOut(false);
        }
    }, [logout]);

    const confirmLogout = useCallback(() => {
        const { queuedCount } = syncState;
        const message =
            queuedCount > 0
                ? `${queuedCount} item${queuedCount === 1 ? '' : 's'} still waiting to sync will stay saved on this device and sync automatically once you log back in.`
                : 'You can log back in at any time.';

        Alert.alert('Log out?', message, [
            { text: 'Cancel', style: 'cancel' },
            { text: 'Log Out', style: 'destructive', onPress: () => void performLogout() },
        ]);
    }, [syncState, performLogout]);

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
            <Card>
                <Text style={styles.sectionTitle}>Profile</Text>
                <View style={styles.fieldList}>
                    <DetailRow label="Name" value={user?.name ?? '—'} />
                    <DetailRow label="Username" value={user?.username ?? '—'} />
                    <DetailRow label="Email" value={user?.email ?? '—'} />
                    <DetailRow label="Role" value={role ? capitalize(role) : '—'} last />
                </View>
                <View style={styles.profileActions}>
                    <Button
                        title="Edit profile"
                        variant="secondary"
                        onPress={() => router.push('/edit-profile')}
                    />
                    <Button
                        title="Change password"
                        variant="secondary"
                        onPress={() => router.push('/change-password')}
                    />
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>About</Text>
                <View style={styles.fieldList}>
                    <DetailRow label="App version" value={Constants.expoConfig?.version ?? '—'} last />
                </View>
            </Card>

            <Button
                title={loggingOut ? 'Logging out…' : 'Log Out'}
                onPress={confirmLogout}
                variant="secondary"
                size="large"
                loading={loggingOut}
                style={styles.logoutButton}
            />
        </ScrollView>
    );
}

/** No shared string-casing helper exists in src/utils/format.ts — this is a
 * one-off local capitalizer, only used for the role label. */
function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, marginBottom: spacing.sm },
    fieldList: { gap: spacing.sm },
    // Stacked, not side-by-side — Button's fullWidth (default true) stretches
    // on the CROSS axis only, so two fullWidth buttons in a row wouldn't
    // split the row evenly. This mirrors log-complaint.tsx's own
    // confirmActions layout (two stacked full-width Buttons), the only
    // existing precedent in this app for "more than one Button together".
    profileActions: { gap: spacing.sm, marginTop: spacing.md },
    logoutButton: { marginTop: spacing.sm },
});
