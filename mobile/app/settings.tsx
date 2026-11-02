import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import * as Updates from 'expo-updates';
import { Card } from '../src/components/ui/Card';
import { Button } from '../src/components/ui/Button';
import { DetailRow } from '../src/components/ui/DetailRow';
import { useAuth } from '../src/auth/AuthContext';
import { biometricAvailable } from '../src/lock/appLock';
import { getAppearanceChoice, saveAppearanceChoice, type AppearanceChoice } from '../src/theme/appearance';

const APPEARANCE_CHOICES: { key: AppearanceChoice; label: string }[] = [
    { key: 'system', label: 'Match my phone' },
    { key: 'light', label: 'Light' },
    { key: 'dark', label: 'Dark' },
];
import { getSyncState, subscribeSyncState } from '../src/sync/syncStore';
import { extractErrorMessage } from '../src/api/client';
import { colors } from '../src/theme/colors';
import { formatShortDate } from '../src/utils/format';
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
 *   - Logout: reuses useAuth().logout(), which removes the company's records from the phone unless some are unsent (AuthContext; src/db/localData.ts) — the old note below predates that:
 *     (including any unsynced payments/expenditures) fully intact — see
 *     AuthContext.tsx's own doc comment. Still gated behind a native confirm
 *     (matches this app's existing point-of-no-return convention — see
 *     app/reconnect/[uuid].tsx / app/disconnect/[uuid].tsx), and the confirm
 *     copy calls out any still-queued items so an agent isn't surprised that
 *     syncing pauses until they log back in.
 *   - App version: static, read from app.config.ts via expo-constants — no
 *     backend involved. "Update" says which over-the-air update is running
 *     (its publish time and short id), so "is the fix on this phone?" has
 *     an answer.
 */
export default function SettingsScreen() {
    const router = useRouter();
    const { user, role, logout, fingerprintSignIn, setFingerprintSignIn } = useAuth();
    const syncState = useSyncExternalStore(subscribeSyncState, getSyncState);
    const [loggingOut, setLoggingOut] = useState(false);
    const [canUseFingerprint, setCanUseFingerprint] = useState(false);
    const [fingerprintBusy, setFingerprintBusy] = useState(false);
    const fingerprintOn = fingerprintSignIn !== null && fingerprintSignIn.userUuid === user?.uuid;
    const appearance = getAppearanceChoice();

    const chooseAppearance = useCallback(
        (choice: AppearanceChoice) => {
            if (choice === appearance) {
                return;
            }

            Alert.alert('Change the colours?', 'The app restarts to apply it. Nothing you recorded is lost.', [
                { text: 'Cancel', style: 'cancel' },
                {
                    text: 'Restart',
                    onPress: () => {
                        saveAppearanceChoice(choice);
                        void Updates.reloadAsync().catch(() =>
                            Alert.alert('Saved', 'Close the app and open it again to see the new colours.'),
                        );
                    },
                },
            ]);
        },
        [appearance],
    );

    useEffect(() => {
        void biometricAvailable().then(setCanUseFingerprint);
    }, []);

    const toggleFingerprint = useCallback(
        async (enabled: boolean) => {
            setFingerprintBusy(true);

            try {
                await setFingerprintSignIn(enabled);
            } catch (error) {
                Alert.alert('Could not change fingerprint sign-in', extractErrorMessage(error));
            } finally {
                setFingerprintBusy(false);
            }
        },
        [setFingerprintSignIn],
    );

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
                ? `${queuedCount} item${queuedCount === 1 ? '' : 's'} not yet sent will stay on this phone and go to the office when you sign in again. Until then nobody else can sign in on this phone.`
                : "The company's records are removed from this phone. Signing in again downloads them.";

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
                <Text style={styles.sectionTitle}>Security</Text>
                <Text style={styles.sectionHint}>Two-factor sign-in, your email address, and where you're signed in.</Text>
                <View style={styles.profileActions}>
                    <Button title="Two-factor authentication" variant="secondary" onPress={() => router.push('/two-factor')} />
                    <Button title="Change email" variant="secondary" onPress={() => router.push('/change-email')} />
                    <Button title="Signed-in devices" variant="secondary" onPress={() => router.push('/sessions')} />
                </View>
                {canUseFingerprint ? (
                    <View style={styles.switchRow}>
                        <View style={styles.flex1}>
                            <Text style={styles.switchLabel}>Sign in with fingerprint</Text>
                            <Text style={styles.sectionHint}>After you log out, sign back in on this phone without typing your password.</Text>
                        </View>
                        <Switch value={fingerprintOn} onValueChange={toggleFingerprint} disabled={fingerprintBusy} />
                    </View>
                ) : null}
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Appearance</Text>
                <Text style={styles.sectionHint}>For this phone only. The app restarts to change its colours.</Text>
                <View style={styles.choiceRow}>
                    {APPEARANCE_CHOICES.map(({ key, label }) => {
                        const selected = key === appearance;

                        return (
                            <Pressable
                                key={key}
                                accessibilityRole="button"
                                accessibilityState={{ selected }}
                                onPress={() => chooseAppearance(key)}
                                style={[styles.choice, selected && styles.choiceSelected]}
                            >
                                <Text style={[styles.choiceLabel, selected && styles.choiceLabelSelected]}>{label}</Text>
                            </Pressable>
                        );
                    })}
                </View>
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>About</Text>
                <View style={styles.fieldList}>
                    <DetailRow label="App version" value={Constants.expoConfig?.version ?? '—'} />
                    <DetailRow label="Update" value={installedUpdateLabel()} last />
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

/** The running over-the-air update: its publish date and short id, or "As installed". */
function installedUpdateLabel(): string {
    if (Updates.isEmbeddedLaunch || !Updates.updateId) {
        return 'As installed';
    }

    const published = Updates.createdAt ? `${formatShortDate(Updates.createdAt.toISOString())} ` : '';

    return `${published}(${Updates.updateId.slice(0, 8)})`;
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
    sectionHint: { fontSize: fontSize.sm, color: colors.textSecondary },
    fieldList: { gap: spacing.sm },
    // Stacked, not side-by-side — Button's fullWidth (default true) stretches
    // on the CROSS axis only, so two fullWidth buttons in a row wouldn't
    // split the row evenly. This mirrors log-complaint.tsx's own
    // confirmActions layout (two stacked full-width Buttons), the only
    // existing precedent in this app for "more than one Button together".
    profileActions: { gap: spacing.sm, marginTop: spacing.md },
    switchRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginTop: spacing.lg },
    switchLabel: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary },
    flex1: { flex: 1 },
    choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginTop: spacing.md },
    choice: { paddingVertical: spacing.sm, paddingHorizontal: spacing.md, borderRadius: 999, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
    choiceSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    choiceLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    choiceLabelSelected: { color: colors.textInverse },
    logoutButton: { marginTop: spacing.sm },
});
