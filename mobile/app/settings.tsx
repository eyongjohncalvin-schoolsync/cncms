import { useCallback, useState, useSyncExternalStore } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { Card } from '../src/components/ui/Card';
import { Button } from '../src/components/ui/Button';
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
 *   - Profile: read-only display of the `/auth/me` identity + role. No
 *     profile-edit endpoint exists anywhere in routes/api.php (only
 *     auth/login, auth/logout, auth/me) — a non-admin user has no server-side
 *     path to change their own name/email today, so this is display-only
 *     rather than a form that would silently no-op.
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
                    <Field label="Name" value={user?.name ?? '—'} />
                    <Field label="Username" value={user?.username ?? '—'} />
                    <Field label="Email" value={user?.email ?? '—'} />
                    <Field label="Role" value={role ? capitalize(role) : '—'} last />
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>About</Text>
                <View style={styles.fieldList}>
                    <Field label="App version" value={Constants.expoConfig?.version ?? '—'} last />
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

function Field({ label, value, last = false }: { label: string; value: string; last?: boolean }) {
    return (
        <View style={[styles.fieldRow, !last && styles.fieldRowDivider]}>
            <Text style={styles.fieldLabel}>{label}</Text>
            <Text style={styles.fieldValue} numberOfLines={1}>
                {value}
            </Text>
        </View>
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
    fieldRow: {
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: spacing.md,
        paddingBottom: spacing.sm,
    },
    fieldRowDivider: {
        borderBottomWidth: 1,
        borderBottomColor: colors.border,
    },
    fieldLabel: { fontSize: fontSize.sm, color: colors.textSecondary },
    fieldValue: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary, flexShrink: 1, textAlign: 'right' },
    logoutButton: { marginTop: spacing.sm },
});
