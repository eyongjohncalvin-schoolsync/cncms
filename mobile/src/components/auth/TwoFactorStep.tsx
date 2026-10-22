import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import axios from 'axios';
import { useAuth } from '../../auth/AuthContext';
import { sendTwoFactorEmail } from '../../api/auth';
import { extractErrorMessage } from '../../api/client';
import { Button } from '../ui/Button';
import { TextInput } from '../ui/TextInput';
import { isCompleteOneTimeCode, normalizeOneTimeCode } from '../../utils/twoFactor';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing } from '../../theme/tokens';
import type { TwoFactorChallengeResponse, TwoFactorMethod } from '../../types/api';

const COPY: Record<TwoFactorMethod, { title: string; help: (hint: string) => string; label: string; placeholder: string }> = {
    totp: {
        title: 'Two-factor authentication',
        help: () => 'Open your authenticator app and enter the 6-digit code for CNCMS.',
        label: 'Authentication code',
        placeholder: '123456',
    },
    recovery: {
        title: 'Use a recovery code',
        help: () => 'Enter one of the recovery codes you saved when you turned on two-factor. Each works once.',
        label: 'Recovery code',
        placeholder: 'ABCDE-FGHJK',
    },
    email: {
        title: 'Check your email',
        help: (hint) => `Enter the 6-digit code we emailed to ${hint}.`,
        label: 'Email code',
        placeholder: '123456',
    },
};

/**
 * The second sign-in step for an account with two-factor on (POST
 * /auth/two-factor). Shown by app/(auth)/login.tsx in place of the
 * password form; nothing is stored until the code passes. A 410 means the
 * challenge is used up/expired — back to the start.
 */
export function TwoFactorStep({
    challenge,
    onRestart,
}: {
    challenge: TwoFactorChallengeResponse;
    onRestart: (message?: string) => void;
}) {
    const { completeTwoFactor } = useAuth();
    const [method, setMethod] = useState<TwoFactorMethod>('totp');
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [info, setInfo] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [sending, setSending] = useState(false);

    function switchTo(next: TwoFactorMethod) {
        setMethod(next);
        setCode('');
        setError(null);
    }

    function handleExpired(e: unknown): boolean {
        if (axios.isAxiosError(e) && e.response?.status === 410) {
            onRestart(extractErrorMessage(e, 'Your sign-in expired. Please sign in again.'));

            return true;
        }

        return false;
    }

    async function submit() {
        if (!isCompleteOneTimeCode(code, method) || submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            await completeTwoFactor(challenge.challenge_token, method, code);
            // app/_layout.tsx redirects into (tabs) once status flips.
        } catch (e) {
            if (!handleExpired(e)) {
                setError(extractErrorMessage(e, 'That code is not valid. Try again.'));
                setCode('');
            }
        } finally {
            setSubmitting(false);
        }
    }

    async function emailMeACode() {
        setSending(true);
        setError(null);

        try {
            setInfo(await sendTwoFactorEmail(challenge.challenge_token));
            switchTo('email');
        } catch (e) {
            if (!handleExpired(e)) {
                setError(extractErrorMessage(e, "Couldn't send the code."));
            }
        } finally {
            setSending(false);
        }
    }

    const copy = COPY[method];

    return (
        <View style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.title}>{copy.title}</Text>
                <Text style={styles.subtitle}>{copy.help(challenge.email_hint)}</Text>
            </View>

            {info ? (
                <View style={styles.infoBox}>
                    <Text style={styles.infoText}>{info}</Text>
                </View>
            ) : null}

            <TextInput
                label={copy.label}
                value={code}
                onChangeText={(text) => setCode(normalizeOneTimeCode(text, method))}
                placeholder={copy.placeholder}
                keyboardType={method === 'recovery' ? 'default' : 'number-pad'}
                autoCapitalize="characters"
                autoCorrect={false}
                autoComplete="one-time-code"
                textContentType="oneTimeCode"
                autoFocus
                returnKeyType="go"
                onSubmitEditing={submit}
                error={error ?? undefined}
            />

            <Button title="Continue" size="large" onPress={submit} loading={submitting} disabled={!isCompleteOneTimeCode(code, method)} />

            <View style={styles.links}>
                {method !== 'totp' ? <LinkText label="Use my authenticator app" onPress={() => switchTo('totp')} /> : null}
                {method !== 'recovery' && challenge.methods.includes('recovery') ? (
                    <LinkText label="Use a recovery code" onPress={() => switchTo('recovery')} />
                ) : null}
                {challenge.methods.includes('email') ? (
                    <LinkText
                        label={method === 'email' ? 'Send a new code' : `Lost your phone? Email me a code (${challenge.email_hint})`}
                        onPress={emailMeACode}
                        disabled={sending}
                    />
                ) : null}
                <LinkText label="Cancel and start over" onPress={() => onRestart()} muted />
            </View>
        </View>
    );
}

export function LinkText({ label, onPress, disabled, muted }: { label: string; onPress: () => void; disabled?: boolean; muted?: boolean }) {
    return (
        <Pressable onPress={onPress} disabled={disabled} hitSlop={8} style={styles.link}>
            <Text style={[styles.linkText, muted ? styles.linkMuted : null, disabled ? styles.linkDisabled : null]}>{label}</Text>
        </Pressable>
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
    link: { minHeight: 32, justifyContent: 'center' },
    linkText: { fontSize: fontSize.md, fontWeight: '600', color: colors.accent.home },
    linkMuted: { color: colors.textSecondary, fontWeight: '500' },
    linkDisabled: { opacity: 0.5 },
});
