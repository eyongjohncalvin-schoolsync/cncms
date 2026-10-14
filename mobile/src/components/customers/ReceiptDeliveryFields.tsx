import { Pressable, StyleSheet, Text, View } from 'react-native';
import { TextInput } from '../ui/TextInput';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';
import type { PreferredChannel } from '../../types/api';

const CHANNELS: Array<{ value: PreferredChannel; label: string }> = [
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'email', label: 'Email' },
    { value: 'print', label: 'Printed' },
];

interface ReceiptDeliveryFieldsProps {
    email: string;
    onEmailChange: (email: string) => void;
    channel: PreferredChannel;
    onChannelChange: (channel: PreferredChannel) => void;
    emailError?: string;
}

/**
 * "How should this customer get their receipts?" — email address plus the
 * preferred channel (WhatsApp / Email / Printed), shared by Add Customer and
 * Edit Customer. Mirrors the web form's ReceiptDeliveryFields.tsx; the
 * server's "To send" list and auto-email follow this choice.
 */
export function ReceiptDeliveryFields({ email, onEmailChange, channel, onChannelChange, emailError }: ReceiptDeliveryFieldsProps) {
    return (
        <View>
            <TextInput
                label="Email (optional)"
                value={email}
                onChangeText={onEmailChange}
                error={emailError}
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                placeholder="name@example.com"
            />
            <Text style={styles.label}>Send receipts by</Text>
            <View style={styles.row}>
                {CHANNELS.map((option) => {
                    const selected = option.value === channel;

                    return (
                        <Pressable
                            key={option.value}
                            accessibilityRole="button"
                            accessibilityState={{ selected }}
                            onPress={() => onChannelChange(option.value)}
                            style={[styles.chip, selected && styles.chipSelected]}
                        >
                            <Text style={[styles.chipLabel, selected && styles.chipLabelSelected]}>{option.label}</Text>
                        </Pressable>
                    );
                })}
            </View>
            {channel === 'email' && !email.trim() ? (
                <Text style={styles.hint}>No email yet — receipts will go by WhatsApp until one is added.</Text>
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    label: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textSecondary, marginBottom: spacing.xs },
    row: { flexDirection: 'row', gap: spacing.sm },
    chip: {
        flex: 1,
        alignItems: 'center',
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.lg,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipSelected: { backgroundColor: colors.accent.customers, borderColor: colors.accent.customers },
    chipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipLabelSelected: { color: colors.textInverse },
    hint: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: spacing.xs },
});
