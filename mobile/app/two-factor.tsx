import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Image, Linking, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import {
    cancelTwoFactorSetup,
    confirmTwoFactor,
    disableTwoFactor,
    fetchSecurity,
    regenerateRecoveryCodes,
    setupTwoFactor,
} from '../src/api/auth';
import { extractErrorMessage } from '../src/api/client';
import { Button } from '../src/components/ui/Button';
import { Card } from '../src/components/ui/Card';
import { TextInput } from '../src/components/ui/TextInput';
import { formatSetupKey, isCompleteOneTimeCode, normalizeOneTimeCode } from '../src/utils/twoFactor';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';
import type { SecurityOverview, TwoFactorSetup } from '../src/types/api';

type Panel = 'none' | 'disable' | 'regenerate';

/**
 * Two-factor authentication with an authenticator app (Google
 * Authenticator, Microsoft Authenticator, Authy…) — GET /auth/security,
 * POST /auth/two-factor/setup|confirm|recovery-codes, DELETE
 * /auth/two-factor. Setup shows the QR (for setting up on another phone),
 * an "Open authenticator app" link (same phone) and the key to type in.
 * Recovery codes are shown once, with Share to save them.
 */
export default function TwoFactorScreen() {
    const [overview, setOverview] = useState<SecurityOverview | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [panel, setPanel] = useState<Panel>('none');
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            setOverview(await fetchSecurity());
            setLoadError(null);
        } catch (e) {
            setLoadError(extractErrorMessage(e, "Couldn't load your security settings."));
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    function resetForm() {
        setCode('');
        setPassword('');
        setError(null);
    }

    async function run(action: () => Promise<void>, fallback: string) {
        setBusy(true);
        setError(null);

        try {
            await action();
        } catch (e) {
            setError(extractErrorMessage(e, fallback));
        } finally {
            setBusy(false);
        }
    }

    const startSetup = () =>
        run(async () => {
            setSetup(await setupTwoFactor());
            resetForm();
        }, "Couldn't start the setup.");

    const confirmSetup = () =>
        run(async () => {
            setRecoveryCodes(await confirmTwoFactor(code));
            setSetup(null);
            resetForm();
            await load();
        }, 'That code is not valid. Check the time on your phone and try again.');

    const abandonSetup = () =>
        run(async () => {
            await cancelTwoFactorSetup();
            setSetup(null);
            resetForm();
            await load();
        }, "Couldn't cancel the setup.");

    const turnOff = () =>
        run(async () => {
            await disableTwoFactor(password, code);
            setPanel('none');
            resetForm();
            await load();
        }, 'Wrong password or code.');

    const newCodes = () =>
        run(async () => {
            setRecoveryCodes(await regenerateRecoveryCodes(password));
            setPanel('none');
            resetForm();
            await load();
        }, 'Wrong password.');

    if (!overview) {
        return (
            <View style={styles.center}>
                {loadError ? <Text style={styles.errorText}>{loadError}</Text> : <ActivityIndicator color={colors.accent.home} />}
                {loadError ? <Button title="Try again" variant="secondary" onPress={load} /> : null}
            </View>
        );
    }

    const enabled = overview.two_factor.enabled;

    return (
        <ScrollView style={styles.flex} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
            {recoveryCodes ? (
                <Card style={styles.codesCard}>
                    <Text style={styles.codesTitle}>Save your recovery codes now</Text>
                    <Text style={styles.codesHelp}>
                        Each code signs you in once if you lose your phone. Keep them somewhere safe — they won't be shown again.
                    </Text>
                    <View style={styles.codesGrid}>
                        {recoveryCodes.map((recoveryCode) => (
                            <Text key={recoveryCode} selectable style={styles.code}>
                                {recoveryCode}
                            </Text>
                        ))}
                    </View>
                    <Button
                        title="Share / save codes"
                        variant="secondary"
                        onPress={() => void Share.share({ message: `CNCMS recovery codes:\n${recoveryCodes.join('\n')}` })}
                    />
                    <Button title="I've saved them" variant="ghost" onPress={() => setRecoveryCodes(null)} />
                </Card>
            ) : null}

            <Card>
                <Text style={styles.sectionTitle}>Status: {enabled ? 'On' : 'Off'}</Text>
                <Text style={styles.hint}>
                    {enabled
                        ? `Signing in asks for a code from your authenticator app. ${overview.two_factor.recovery_codes_remaining} recovery codes left.`
                        : 'Add a second step to signing in: a code from an authenticator app on your phone.'}
                </Text>
            </Card>

            {!enabled && !setup ? <Button title="Set up two-factor" size="large" onPress={startSetup} loading={busy} /> : null}

            {!enabled && setup ? (
                <Card>
                    <Text style={styles.sectionTitle}>Set up your authenticator app</Text>
                    <Text style={styles.hint}>1. Install an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…).</Text>
                    <Text style={styles.hint}>2. Tap “Open authenticator app”, scan the QR code from another phone, or type the key.</Text>
                    <Button title="Open authenticator app" variant="secondary" onPress={() => void Linking.openURL(setup.otpauth_url).catch(() => setError('No authenticator app found. Type the key instead.'))} />
                    <Image source={{ uri: setup.qr_code }} style={styles.qr} accessibilityLabel="QR code for your authenticator app" />
                    <Text style={styles.keyLabel}>Setup key</Text>
                    <Text selectable style={styles.key}>
                        {formatSetupKey(setup.secret)}
                    </Text>
                    <Text style={styles.hint}>3. Enter the 6-digit code the app shows.</Text>
                    <TextInput
                        label="Code"
                        value={code}
                        onChangeText={(text) => setCode(normalizeOneTimeCode(text, 'totp'))}
                        keyboardType="number-pad"
                        placeholder="123456"
                        error={error ?? undefined}
                    />
                    <Button title="Turn on" onPress={confirmSetup} loading={busy} disabled={!isCompleteOneTimeCode(code, 'totp')} />
                    <Button title="Cancel" variant="ghost" onPress={abandonSetup} disabled={busy} />
                </Card>
            ) : null}

            {enabled ? (
                <Card>
                    {panel === 'none' ? (
                        <View style={styles.actions}>
                            <Button title="New recovery codes" variant="secondary" onPress={() => { resetForm(); setPanel('regenerate'); }} />
                            <Button title="Turn off two-factor" variant="dangerOutline" onPress={() => { resetForm(); setPanel('disable'); }} />
                        </View>
                    ) : (
                        <View style={styles.actions}>
                            <Text style={styles.hint}>
                                {panel === 'disable'
                                    ? 'Enter your password and a code from your authenticator app (or a recovery code).'
                                    : 'Your current recovery codes will stop working.'}
                            </Text>
                            <TextInput label="Current password" value={password} onChangeText={setPassword} secureTextEntry autoCapitalize="none" />
                            {panel === 'disable' ? (
                                <TextInput
                                    label="Authenticator or recovery code"
                                    value={code}
                                    onChangeText={(text) => setCode(text.trim().toUpperCase())}
                                    autoCapitalize="characters"
                                    autoCorrect={false}
                                />
                            ) : null}
                            {error ? <Text style={styles.errorText}>{error}</Text> : null}
                            <Button
                                title={panel === 'disable' ? 'Turn off two-factor' : 'Generate new codes'}
                                variant={panel === 'disable' ? 'danger' : 'primary'}
                                onPress={panel === 'disable' ? turnOff : newCodes}
                                loading={busy}
                                disabled={password === '' || (panel === 'disable' && code === '')}
                            />
                            <Button title="Cancel" variant="ghost" onPress={() => { resetForm(); setPanel('none'); }} />
                        </View>
                    )}
                </Card>
            ) : null}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.lg, paddingBottom: spacing.xxl },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.md, padding: spacing.xl, backgroundColor: colors.background },
    sectionTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, marginBottom: spacing.sm },
    hint: { fontSize: fontSize.sm, color: colors.textSecondary, marginBottom: spacing.sm },
    errorText: { fontSize: fontSize.sm, color: colors.danger, fontWeight: '600' },
    actions: { gap: spacing.sm },
    qr: { width: 200, height: 200, alignSelf: 'center', marginVertical: spacing.md, borderRadius: radius.sm },
    keyLabel: { fontSize: fontSize.xs, color: colors.textSecondary },
    key: {
        fontSize: fontSize.md,
        fontFamily: 'monospace',
        color: colors.textPrimary,
        backgroundColor: colors.surfaceMuted,
        borderRadius: radius.sm,
        padding: spacing.sm,
        marginBottom: spacing.md,
    },
    codesCard: { borderColor: colors.status.offlineDot, gap: spacing.sm },
    codesTitle: { fontSize: fontSize.md, fontWeight: '800', color: colors.status.offlineFg },
    codesHelp: { fontSize: fontSize.sm, color: colors.textSecondary },
    codesGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginVertical: spacing.sm },
    code: {
        fontFamily: 'monospace',
        fontSize: fontSize.md,
        color: colors.textPrimary,
        backgroundColor: colors.surfaceMuted,
        borderRadius: radius.sm,
        paddingHorizontal: spacing.sm,
        paddingVertical: spacing.xs,
    },
});
