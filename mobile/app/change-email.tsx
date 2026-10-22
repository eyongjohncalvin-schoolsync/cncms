import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { cancelEmailChange, confirmEmailChange, fetchSecurity, requestEmailChange } from '../src/api/auth';
import { extractErrorMessage } from '../src/api/client';
import { Button } from '../src/components/ui/Button';
import { TextInput } from '../src/components/ui/TextInput';
import { isCompleteOneTimeCode, normalizeOneTimeCode } from '../src/utils/twoFactor';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

type FieldErrors = { email?: string; current_password?: string; code?: string };

function fieldErrors(error: unknown): FieldErrors {
    const errors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;

    return { email: errors?.email?.[0], current_password: errors?.current_password?.[0], code: errors?.code?.[0] };
}

/**
 * "Change email" — POST /auth/email sends a 6-digit code to the NEW
 * address; POST /auth/email/confirm switches the account once it comes
 * back (the old address gets a notice). Until then the current email stays.
 */
export default function ChangeEmailScreen() {
    const router = useRouter();
    const { user, updateCachedUser } = useAuth();
    const [pendingEmail, setPendingEmail] = useState<string | null>(null);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        void fetchSecurity()
            .then((overview) => setPendingEmail(overview.pending_email))
            .catch(() => undefined);
    }, []);

    async function run(action: () => Promise<void>, fallback: string) {
        setBusy(true);
        setErrors({});
        setSubmitError(null);

        try {
            await action();
        } catch (e) {
            const fields = fieldErrors(e);

            if (fields.email || fields.current_password || fields.code) {
                setErrors(fields);
            } else {
                setSubmitError(extractErrorMessage(e, fallback));
            }
        } finally {
            setBusy(false);
        }
    }

    const sendCode = () =>
        run(async () => {
            await requestEmailChange(email.trim(), password);
            setPendingEmail(email.trim());
            setPassword('');
            setCode('');
        }, "Couldn't send the code.");

    const confirm = () =>
        run(async () => {
            const newEmail = await confirmEmailChange(code);
            await updateCachedUser({ email: newEmail });
            router.back();
        }, "Couldn't confirm the code.");

    const cancel = () =>
        run(async () => {
            await cancelEmailChange();
            setPendingEmail(null);
            setCode('');
        }, "Couldn't cancel.");

    return (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
            <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
                <Text style={styles.hint}>
                    Current email: <Text style={styles.strong}>{user?.email ?? '—'}</Text>
                </Text>

                {pendingEmail ? (
                    <>
                        <Text style={styles.hint}>
                            We sent a 6-digit code to <Text style={styles.strong}>{pendingEmail}</Text>. Enter it to finish the change.
                        </Text>
                        <TextInput
                            label="Code"
                            value={code}
                            onChangeText={(text) => setCode(normalizeOneTimeCode(text, 'email'))}
                            keyboardType="number-pad"
                            placeholder="123456"
                            autoComplete="one-time-code"
                            textContentType="oneTimeCode"
                            error={errors.code}
                        />
                        {submitError ? <ErrorBox message={submitError} /> : null}
                        <Button title="Confirm new email" size="large" onPress={confirm} loading={busy} disabled={!isCompleteOneTimeCode(code, 'email')} />
                        <Button title="Use a different address" variant="ghost" onPress={cancel} disabled={busy} />
                    </>
                ) : (
                    <>
                        <Text style={styles.hint}>We'll email a code to the new address to make sure it's yours.</Text>
                        <TextInput
                            label="New email address"
                            value={email}
                            onChangeText={setEmail}
                            autoCapitalize="none"
                            autoCorrect={false}
                            keyboardType="email-address"
                            error={errors.email}
                        />
                        <TextInput
                            label="Current password"
                            value={password}
                            onChangeText={setPassword}
                            secureTextEntry
                            autoCapitalize="none"
                            error={errors.current_password}
                        />
                        {submitError ? <ErrorBox message={submitError} /> : null}
                        <Button title="Send code" size="large" onPress={sendCode} loading={busy} disabled={email.trim() === '' || password === ''} />
                    </>
                )}
            </ScrollView>
        </KeyboardAvoidingView>
    );
}

function ErrorBox({ message }: { message: string }) {
    return (
        <View style={styles.submitErrorBox}>
            <Text style={styles.submitErrorText}>{message}</Text>
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    hint: { fontSize: fontSize.sm, color: colors.textSecondary },
    strong: { fontWeight: '700', color: colors.textPrimary },
    submitErrorBox: { backgroundColor: colors.status.errorBg, borderRadius: radius.md, padding: spacing.md },
    submitErrorText: { fontSize: fontSize.sm, color: colors.status.errorFg },
});
