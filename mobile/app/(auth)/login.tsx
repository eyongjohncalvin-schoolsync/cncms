import { useState } from 'react';
import {
    Alert,
    Image,
    KeyboardAvoidingView,
    Platform,
    ScrollView,
    StyleSheet,
    Text,
    View,
} from 'react-native';
import { OtherPersonsDataError, useAuth } from '../../src/auth/AuthContext';
import { LoginCodeStep } from '../../src/components/auth/LoginCodeStep';
import { LinkText, TwoFactorStep } from '../../src/components/auth/TwoFactorStep';
import { Button } from '../../src/components/ui/Button';
import { TextInput } from '../../src/components/ui/TextInput';
import { extractErrorMessage } from '../../src/api/client';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';
import type { TwoFactorChallengeResponse } from '../../src/types/api';

export default function LoginScreen() {
    const { login, otherPersonsUnsent, discardOtherData, fingerprintSignIn, loginWithFingerprint } = useAuth();
    const [fingerprintBusy, setFingerprintBusy] = useState(false);

    async function handleFingerprint() {
        setFingerprintBusy(true);
        setError(null);

        try {
            // Signed in: the redirect in app/_layout.tsx takes over, as with the password.
            await loginWithFingerprint();
        } catch (e) {
            setError(e instanceof OtherPersonsDataError ? null : extractErrorMessage(e, 'Could not sign in with your fingerprint. Use your password.'));
        } finally {
            setFingerprintBusy(false);
        }
    }
    const [identifier, setIdentifier] = useState('');
    const [password, setPassword] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // 'password' (default), 'code' (passwordless email code), or a pending
    // two-factor challenge after either first factor.
    const [mode, setMode] = useState<'password' | 'code'>('password');
    const [challenge, setChallenge] = useState<TwoFactorChallengeResponse | null>(null);

    function restart(message?: string) {
        setChallenge(null);
        setMode('password');
        setPassword('');
        setError(message ?? null);
    }

    const canSubmit = identifier.trim().length > 0 && password.length > 0 && !submitting;

    async function handleSubmit() {
        if (!canSubmit) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const outcome = await login(identifier.trim(), password);
            // Signed in: navigation to (tabs) happens automatically via the
            // segments-based redirect in app/_layout.tsx once `status`
            // flips to 'authenticated'. Two-factor: show the code step.
            if (outcome.status === 'two_factor') {
                setChallenge(outcome.challenge);
            }
        } catch (e) {
            // Refused for the previous person's unsent records: the notice above explains it.
            setError(e instanceof OtherPersonsDataError ? null : extractErrorMessage(e, 'Could not sign in. Check your credentials and try again.'));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <KeyboardAvoidingView
            style={styles.flex}
            behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
            <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
                <View style={styles.header}>
                    <Image source={require('../../assets/brand-mark.png')} style={styles.brandMark} />
                    <Text style={styles.title}>CNCMS Field Agent</Text>
                    <Text style={styles.subtitle}>Sign in to record payments and expenses in the field.</Text>
                </View>

                {otherPersonsUnsent !== null ? (
                    <View style={styles.otherData} accessibilityLiveRegion="polite">
                        <Text style={styles.otherDataTitle}>
                            The previous person left {otherPersonsUnsent} record{otherPersonsUnsent === 1 ? '' : 's'} on this phone not yet sent to the office.
                        </Text>
                        <Text style={styles.otherDataBody}>
                            Records are never shown to, or sent under, someone else's account. Ask that person to sign in here so they are sent — or, if that can't happen, delete them.
                        </Text>
                        <Button
                            title="Delete their records"
                            variant="dangerOutline"
                            onPress={() =>
                                Alert.alert(
                                    'Delete them?',
                                    `${otherPersonsUnsent} record${otherPersonsUnsent === 1 ? '' : 's'} recorded on this phone will be deleted and never reach the office. This can't be undone.`,
                                    [
                                        { text: 'Cancel', style: 'cancel' },
                                        {
                                            text: 'Delete',
                                            style: 'destructive',
                                            onPress: () => {
                                                void discardOtherData().then(() => setError('Deleted. Sign in again.'));
                                            },
                                        },
                                    ],
                                )
                            }
                        />
                    </View>
                ) : null}

                {challenge ? (
                    <TwoFactorStep challenge={challenge} onRestart={restart} />
                ) : mode === 'code' ? (
                    <LoginCodeStep initialIdentifier={identifier.trim()} onTwoFactor={setChallenge} onCancel={() => restart()} />
                ) : (
                    <View style={styles.form}>
                        {fingerprintSignIn ? (
                            <View style={styles.fingerprint}>
                                <Button
                                    title={`Sign in as ${fingerprintSignIn.name} with fingerprint`}
                                    onPress={handleFingerprint}
                                    loading={fingerprintBusy}
                                    disabled={submitting}
                                    size="large"
                                />
                                <Text style={styles.orPassword}>or sign in with a password</Text>
                            </View>
                        ) : null}
                        {/* autoComplete / textContentType let the phone's password manager (Google Password Manager) offer to save and fill these. */}
                        <TextInput
                            label="Email or username"
                            autoCapitalize="none"
                            autoCorrect={false}
                            autoComplete="username"
                            textContentType="username"
                            importantForAutofill="yes"
                            keyboardType="email-address"
                            value={identifier}
                            onChangeText={setIdentifier}
                            placeholder="Your username or email"
                            returnKeyType="next"
                        />
                        <TextInput
                            label="Password"
                            secureTextEntry
                            autoComplete="current-password"
                            textContentType="password"
                            importantForAutofill="yes"
                            value={password}
                            onChangeText={setPassword}
                            placeholder="••••••••"
                            returnKeyType="go"
                            onSubmitEditing={handleSubmit}
                        />

                        {error ? <Text style={styles.error}>{error}</Text> : null}

                        <Button title="Sign in" onPress={handleSubmit} loading={submitting} disabled={!canSubmit} size="large" />

                        <LinkText
                            label="Email me a sign-in code instead"
                            onPress={() => {
                                setError(null);
                                setMode('code');
                            }}
                        />
                    </View>
                )}

                <Text style={styles.footnote}>
                    Works offline once signed in — your recorded payments are never lost, even if the
                    connection drops.
                </Text>
            </ScrollView>
        </KeyboardAvoidingView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    scroll: {
        flexGrow: 1,
        justifyContent: 'center',
        padding: spacing.xl,
        gap: spacing.xl,
    },
    header: { alignItems: 'flex-start', gap: spacing.xs },
    brandMark: {
        width: 56,
        height: 56,
        borderRadius: 14,
        marginBottom: spacing.xs,
    },
    title: {
        fontSize: fontSize.xxl,
        fontWeight: '800',
        color: colors.textPrimary,
    },
    subtitle: {
        fontSize: fontSize.md,
        color: colors.textSecondary,
    },
    form: { gap: spacing.lg },
    fingerprint: { gap: spacing.sm },
    orPassword: { fontSize: fontSize.sm, color: colors.textSecondary, textAlign: 'center' },
    error: {
        fontSize: fontSize.sm,
        color: colors.danger,
        fontWeight: '600',
    },
    footnote: {
        fontSize: fontSize.xs,
        color: colors.textSecondary,
        textAlign: 'center',
    },
    otherData: { gap: spacing.sm, padding: spacing.md, borderRadius: 12, backgroundColor: colors.status.offlineBg },
    otherDataTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.status.offlineFg },
    otherDataBody: { fontSize: fontSize.sm, color: colors.status.offlineFg },
});
