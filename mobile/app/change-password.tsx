import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { TextInput as UiTextInput } from '../src/components/ui/TextInput';
import { updatePassword } from '../src/api/auth';
import { extractErrorMessage } from '../src/api/client';
import { validatePasswordForm, type PasswordFormErrors } from '../src/utils/validation';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

/**
 * "Change password" — PATCH /auth/password (mobile-app-react-native.md §11
 * addendum). Standalone Stack route, same reasoning as app/edit-profile.tsx
 * for why this is a route rather than an inline React Native `Modal`.
 *
 * On success, the server has already revoked every OTHER active token for
 * this account (App\Http\Controllers\Api\AuthController::updatePassword())
 * — this device's own token (the one this very request used) stays valid,
 * so there is deliberately NO local logout/token handling here: the agent
 * simply sees a confirmation and returns to Settings, still signed in.
 */
export default function ChangePasswordScreen() {
    const router = useRouter();

    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [errors, setErrors] = useState<PasswordFormErrors>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [done, setDone] = useState(false);

    async function handleSubmit() {
        const result = validatePasswordForm({ currentPassword, newPassword, confirmPassword });

        if (!result.valid) {
            setErrors(result.errors);
            setSubmitError(null);
            return;
        }

        setErrors({});
        setSubmitError(null);
        setSubmitting(true);

        try {
            await updatePassword({
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: confirmPassword,
            });

            setDone(true);
        } catch (error: unknown) {
            // Wrong-current-password lands on the `current_password` field
            // specifically (UpdatePasswordRequest's own validation error) —
            // same local field-error extraction as edit-profile.tsx, kept
            // local for the same reason (no shared helper exists for this
            // in src/api/client.ts, and this is the only other caller).
            const fieldErrors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
                ?.data?.errors;

            if (fieldErrors?.current_password?.[0] || fieldErrors?.new_password?.[0]) {
                setErrors({
                    currentPassword: fieldErrors?.current_password?.[0],
                    newPassword: fieldErrors?.new_password?.[0],
                });
            } else {
                setSubmitError(extractErrorMessage(error, "Couldn't change your password."));
            }
        } finally {
            setSubmitting(false);
        }
    }

    if (done) {
        return (
            <View style={styles.confirmFlex}>
                <Text style={styles.confirmTitle}>Password changed</Text>
                <Text style={styles.confirmBody}>
                    Your password has been updated. You're still signed in on this device.
                </Text>
                <Button title="Done" onPress={() => router.back()} />
            </View>
        );
    }

    return (
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
            <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
                <Text style={styles.hint}>
                    Choose a new password of at least 8 characters, with at least one letter and one number.
                </Text>

                <UiTextInput
                    label="Current password"
                    value={currentPassword}
                    onChangeText={(text) => {
                        setCurrentPassword(text);
                        setErrors((prev) => ({ ...prev, currentPassword: undefined }));
                    }}
                    secureTextEntry
                    autoCapitalize="none"
                    autoCorrect={false}
                    error={errors.currentPassword}
                />

                <UiTextInput
                    label="New password"
                    value={newPassword}
                    onChangeText={(text) => {
                        setNewPassword(text);
                        setErrors((prev) => ({ ...prev, newPassword: undefined }));
                    }}
                    secureTextEntry
                    autoCapitalize="none"
                    autoCorrect={false}
                    error={errors.newPassword}
                />

                <UiTextInput
                    label="Confirm new password"
                    value={confirmPassword}
                    onChangeText={(text) => {
                        setConfirmPassword(text);
                        setErrors((prev) => ({ ...prev, confirmPassword: undefined }));
                    }}
                    secureTextEntry
                    autoCapitalize="none"
                    autoCorrect={false}
                    error={errors.confirmPassword}
                />

                {submitError ? (
                    <View style={styles.submitErrorBox}>
                        <Text style={styles.submitErrorText}>{submitError}</Text>
                    </View>
                ) : null}

                <Button title="Change password" size="large" loading={submitting} onPress={handleSubmit} />
            </ScrollView>
        </KeyboardAvoidingView>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.md, paddingBottom: spacing.xxl },
    hint: { fontSize: fontSize.sm, color: colors.textSecondary, marginBottom: spacing.xs },
    submitErrorBox: {
        backgroundColor: colors.status.errorBg,
        borderRadius: radius.md,
        padding: spacing.md,
    },
    submitErrorText: { fontSize: fontSize.sm, color: colors.status.errorFg },
    confirmFlex: {
        flex: 1,
        backgroundColor: colors.background,
        alignItems: 'center',
        justifyContent: 'center',
        padding: spacing.xl,
        gap: spacing.md,
    },
    confirmTitle: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    confirmBody: { fontSize: fontSize.md, color: colors.textSecondary, textAlign: 'center', marginBottom: spacing.md },
});
