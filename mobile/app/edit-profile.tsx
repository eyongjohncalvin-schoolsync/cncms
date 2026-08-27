import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Button } from '../src/components/ui/Button';
import { TextInput as UiTextInput } from '../src/components/ui/TextInput';
import { useAuth } from '../src/auth/AuthContext';
import { updateProfile } from '../src/api/auth';
import { extractErrorMessage } from '../src/api/client';
import { validateProfileForm, type ProfileFormErrors } from '../src/utils/validation';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing } from '../src/theme/tokens';

/**
 * "Edit profile" — the self-service name/username/email form
 * PATCH /auth/profile makes possible (mobile-app-react-native.md §11
 * addendum). Reached from settings.tsx's Profile card, which was
 * read-only until this endpoint existed (see that screen's own doc
 * comment history) — this is the real thing, not a workaround.
 *
 * A standalone Stack route (registered in app/_layout.tsx with
 * presentation: 'modal'), matching this app's existing convention for
 * every reached-one-tap-deeper form (record-expense, log-complaint,
 * reconnect/disconnect) rather than introducing React Native's `Modal`
 * component, which has zero precedent anywhere else in this codebase.
 *
 * On success, merges the response straight into AuthContext's cached
 * profile via updateCachedUser() — the display refreshes immediately with
 * no re-login and no extra /auth/me round-trip (see that function's own
 * doc comment).
 */
export default function EditProfileScreen() {
    const router = useRouter();
    const { user, updateCachedUser } = useAuth();

    const [name, setName] = useState(user?.name ?? '');
    const [username, setUsername] = useState(user?.username ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [errors, setErrors] = useState<ProfileFormErrors>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit() {
        const result = validateProfileForm({ name, username, email });

        if (!result.valid) {
            setErrors(result.errors);
            setSubmitError(null);
            return;
        }

        setErrors({});
        setSubmitError(null);
        setSubmitting(true);

        try {
            const response = await updateProfile({
                name: name.trim(),
                username: username.trim(),
                email: email.trim(),
            });

            await updateCachedUser(response.user);
            router.back();
        } catch (error: unknown) {
            // Server-side uniqueness rejection (username/email already
            // taken by another account) — surface the specific field
            // message when the API returned one (Laravel's standard 422
            // {message, errors: {field: [...]}} shape), falling back to
            // extractErrorMessage's generic copy for anything else (network
            // failure, unexpected 500). No shared helper for this exists
            // elsewhere in the app (every other mobile form only validates
            // client-side before submit), so this is scoped locally rather
            // than widening src/api/client.ts's extractErrorMessage for a
            // single caller.
            const fieldErrors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
                ?.data?.errors;

            if (fieldErrors?.username?.[0] || fieldErrors?.email?.[0] || fieldErrors?.name?.[0]) {
                setErrors({
                    name: fieldErrors?.name?.[0],
                    username: fieldErrors?.username?.[0],
                    email: fieldErrors?.email?.[0],
                });
            } else {
                setSubmitError(extractErrorMessage(error, "Couldn't save your profile."));
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <KeyboardAvoidingView
            style={styles.flex}
            behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
            <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
                <Text style={styles.hint}>Update your name, username, or email. This does not change your password.</Text>

                <UiTextInput
                    label="Name"
                    value={name}
                    onChangeText={(text) => {
                        setName(text);
                        setErrors((prev) => ({ ...prev, name: undefined }));
                    }}
                    autoCapitalize="words"
                    error={errors.name}
                />

                <UiTextInput
                    label="Username"
                    value={username}
                    onChangeText={(text) => {
                        setUsername(text);
                        setErrors((prev) => ({ ...prev, username: undefined }));
                    }}
                    autoCapitalize="none"
                    autoCorrect={false}
                    error={errors.username}
                />

                <UiTextInput
                    label="Email"
                    value={email}
                    onChangeText={(text) => {
                        setEmail(text);
                        setErrors((prev) => ({ ...prev, email: undefined }));
                    }}
                    autoCapitalize="none"
                    autoCorrect={false}
                    keyboardType="email-address"
                    error={errors.email}
                />

                {submitError ? (
                    <View style={styles.submitErrorBox}>
                        <Text style={styles.submitErrorText}>{submitError}</Text>
                    </View>
                ) : null}

                <Button title="Save changes" size="large" loading={submitting} onPress={handleSubmit} />
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
});
