import { useState } from 'react';
import {
    Image,
    KeyboardAvoidingView,
    Platform,
    ScrollView,
    StyleSheet,
    Text,
    View,
} from 'react-native';
import { useAuth } from '../../src/auth/AuthContext';
import { Button } from '../../src/components/ui/Button';
import { TextInput } from '../../src/components/ui/TextInput';
import { extractErrorMessage } from '../../src/api/client';
import { colors } from '../../src/theme/colors';
import { fontSize, spacing } from '../../src/theme/tokens';

export default function LoginScreen() {
    const { login } = useAuth();
    const [identifier, setIdentifier] = useState('');
    const [password, setPassword] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const canSubmit = identifier.trim().length > 0 && password.length > 0 && !submitting;

    async function handleSubmit() {
        if (!canSubmit) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            await login(identifier.trim(), password);
            // Navigation to (tabs) happens automatically via the
            // segments-based redirect in app/_layout.tsx once `status`
            // flips to 'authenticated'.
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not sign in. Check your credentials and try again.'));
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

                <View style={styles.form}>
                    <TextInput
                        label="Email or username"
                        autoCapitalize="none"
                        autoCorrect={false}
                        keyboardType="email-address"
                        value={identifier}
                        onChangeText={setIdentifier}
                        placeholder="you@shalomtech.dev"
                        returnKeyType="next"
                    />
                    <TextInput
                        label="Password"
                        secureTextEntry
                        value={password}
                        onChangeText={setPassword}
                        placeholder="••••••••"
                        returnKeyType="go"
                        onSubmitEditing={handleSubmit}
                    />

                    {error ? <Text style={styles.error}>{error}</Text> : null}

                    <Button title="Sign in" onPress={handleSubmit} loading={submitting} disabled={!canSubmit} size="large" />
                </View>

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
});
