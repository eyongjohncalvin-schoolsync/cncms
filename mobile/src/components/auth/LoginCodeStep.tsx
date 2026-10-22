import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../auth/AuthContext';
import { sendLoginCode } from '../../api/auth';
import { extractErrorMessage } from '../../api/client';
import { Button } from '../ui/Button';
import { TextInput } from '../ui/TextInput';
import { LinkText } from './TwoFactorStep';
import { isCompleteOneTimeCode, normalizeOneTimeCode } from '../../utils/twoFactor';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing } from '../../theme/tokens';
import type { TwoFactorChallengeResponse } from '../../types/api';

/**
 * Passwordless sign-in (POST /auth/login-code then /auth/login-code/verify):
 * username/email -> 6-digit code by email -> signed in. The server answers
 * the first step identically whether or not the account exists. With
 * two-factor on, the code is only the first factor: `onTwoFactor` hands
 * the challenge to TwoFactorStep (authenticator / recovery code only).
 */
export function LoginCodeStep({
    initialIdentifier,
    onTwoFactor,
    onCancel,
}: {
    initialIdentifier: string;
    onTwoFactor: (challenge: TwoFactorChallengeResponse) => void;
    onCancel: () => void;
}) {
    const { loginWithCode } = useAuth();
    const [identifier, setIdentifier] = useState(initialIdentifier);
    const [codeSent, setCodeSent] = useState(false);
    const [code, setCode] = useState('');
    const [info, setInfo] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function send() {
        if (identifier.trim() === '' || busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            setInfo(await sendLoginCode(identifier.trim()));
            setCodeSent(true);
            setCode('');
        } catch (e) {
            setError(extractErrorMessage(e, "Couldn't send the code. Try again."));
        } finally {
            setBusy(false);
        }
    }

    async function verify() {
        if (!isCompleteOneTimeCode(code, 'email') || busy) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const outcome = await loginWithCode(identifier.trim(), code);

            if (outcome.status === 'two_factor') {
                onTwoFactor(outcome.challenge);
            }
        } catch (e) {
            setError(extractErrorMessage(e, 'That code is wrong or has expired.'));
            setCode('');
        } finally {
            setBusy(false);
        }
    }

    return (
        <View style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.title}>Sign in with a code</Text>
                <Text style={styles.subtitle}>
                    {codeSent ? `Enter the 6-digit code we emailed for ${identifier.trim()}.` : "We'll email you a 6-digit code — no password needed."}
                </Text>
            </View>

            {info ? (
                <View style={styles.infoBox}>
                    <Text style={styles.infoText}>{info}</Text>
                </View>
            ) : null}

            {!codeSent ? (
                <>
                    <TextInput
                        label="Email or username"
                        autoCapitalize="none"
                        autoCorrect={false}
                        keyboardType="email-address"
                        value={identifier}
                        onChangeText={setIdentifier}
                        returnKeyType="go"
                        onSubmitEditing={send}
                        error={error ?? undefined}
                    />
                    <Button title="Email me a code" size="large" onPress={send} loading={busy} disabled={identifier.trim() === ''} />
                </>
            ) : (
                <>
                    <TextInput
                        label="Code"
                        value={code}
                        onChangeText={(text) => setCode(normalizeOneTimeCode(text, 'email'))}
                        placeholder="123456"
                        keyboardType="number-pad"
                        autoComplete="one-time-code"
                        textContentType="oneTimeCode"
                        autoFocus
                        returnKeyType="go"
                        onSubmitEditing={verify}
                        error={error ?? undefined}
                    />
                    <Button title="Sign in" size="large" onPress={verify} loading={busy} disabled={!isCompleteOneTimeCode(code, 'email')} />
                </>
            )}

            <View style={styles.links}>
                {codeSent ? <LinkText label="Send a new code" onPress={send} disabled={busy} /> : null}
                {codeSent ? (
                    <LinkText
                        label="Use a different account"
                        onPress={() => {
                            setCodeSent(false);
                            setInfo(null);
                            setError(null);
                        }}
                        muted
                    />
                ) : null}
                <LinkText label="Sign in with a password instead" onPress={onCancel} muted />
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    container: { gap: spacing.lg },
    header: { gap: spacing.xs },
    title: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    subtitle: { fontSize: fontSize.md, color: colors.textSecondary },
    infoBox: { backgroundColor: colors.status.syncedBg, borderRadius: radius.md, padding: spacing.md },
    infoText: { fontSize: fontSize.sm, color: colors.status.syncedFg },
    links: { gap: spacing.md, marginTop: spacing.xs },
});
