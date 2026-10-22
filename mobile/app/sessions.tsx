import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { fetchSessions, signOutOtherSessions } from '../src/api/auth';
import { extractErrorMessage } from '../src/api/client';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { TextInput } from '../src/components/ui/TextInput';
import { describeUserAgent } from '../src/utils/twoFactor';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';
import type { SessionsResponse } from '../src/types/api';

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

/**
 * "Signed-in devices" — GET /auth/sessions lists web sessions and app
 * devices (this phone marked); DELETE /auth/sessions (password required)
 * signs out everything except this phone.
 */
export default function SessionsScreen() {
    const [data, setData] = useState<SessionsResponse | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        setRefreshing(true);

        try {
            setData(await fetchSessions());
            setLoadError(null);
        } catch (e) {
            setLoadError(extractErrorMessage(e, "Couldn't load your devices."));
        } finally {
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    async function signOutOthers() {
        setBusy(true);
        setError(null);

        try {
            await signOutOtherSessions(password);
            setPassword('');
            setConfirming(false);
            setDone('Signed out of all other sessions and devices.');
            await load();
        } catch (e) {
            setError(extractErrorMessage(e, 'Wrong password.'));
        } finally {
            setBusy(false);
        }
    }

    if (!data) {
        return (
            <View style={styles.center}>
                {loadError ? <Text style={styles.errorText}>{loadError}</Text> : <ActivityIndicator color={colors.accent.home} />}
                {loadError ? <Button title="Try again" variant="secondary" onPress={load} /> : null}
            </View>
        );
    }

    return (
        <ScrollView
            style={styles.flex}
            contentContainerStyle={styles.content}
            keyboardShouldPersistTaps="handled"
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={load} />}
        >
            {done ? <Text style={styles.doneText}>{done}</Text> : null}

            <Card>
                <Text style={styles.sectionTitle}>Mobile app</Text>
                {data.devices.map((device) => (
                    <View key={device.id} style={styles.row}>
                        <Text style={styles.rowTitle}>
                            {device.name === 'api' ? 'Mobile app' : device.name}
                            {device.is_current ? '  · This phone' : ''}
                        </Text>
                        <Text style={styles.rowMeta}>Last used {when(device.last_used_at ?? device.created_at)}</Text>
                    </View>
                ))}
            </Card>

            <Card>
                <Text style={styles.sectionTitle}>Web browsers</Text>
                {data.sessions.length === 0 ? <Text style={styles.rowMeta}>Not signed in on the web.</Text> : null}
                {data.sessions.map((session, index) => (
                    <View key={`${session.last_active_at}-${index}`} style={styles.row}>
                        <Text style={styles.rowTitle}>{describeUserAgent(session.user_agent)}</Text>
                        <Text style={styles.rowMeta}>
                            {session.ip_address ?? '—'} · last active {when(session.last_active_at)}
                        </Text>
                    </View>
                ))}
            </Card>

            {confirming ? (
                <Card>
                    <Text style={styles.hint}>Enter your password to sign out everywhere except this phone.</Text>
                    <TextInput label="Current password" value={password} onChangeText={setPassword} secureTextEntry autoCapitalize="none" error={error ?? undefined} />
                    <View style={styles.actions}>
                        <Button title="Sign out other devices" variant="danger" onPress={signOutOthers} loading={busy} disabled={password === ''} />
                        <Button title="Cancel" variant="ghost" onPress={() => setConfirming(false)} />
                    </View>
                </Card>
            ) : (
                <Button title="Sign out of all other sessions and devices" variant="secondary" onPress={() => setConfirming(true)} />
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.md, padding: spacing.xl, backgroundColor: colors.background },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, marginBottom: spacing.sm },
    row: { paddingVertical: spacing.sm, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.border },
    rowTitle: { fontSize: fontSize.md, fontWeight: '600', color: colors.textPrimary },
    rowMeta: { fontSize: fontSize.sm, color: colors.textSecondary },
    hint: { fontSize: fontSize.sm, color: colors.textSecondary, marginBottom: spacing.sm },
    errorText: { fontSize: fontSize.sm, color: colors.danger, fontWeight: '600' },
    doneText: { fontSize: fontSize.sm, color: colors.status.syncedFg, fontWeight: '600' },
    actions: { gap: spacing.sm, marginTop: spacing.sm },
});
