import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Button } from '../ui/Button';
import { TextInput } from '../ui/TextInput';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';

interface ReasonSheetProps {
    visible: boolean;
    title: string;
    subtitle?: string;
    quickReasons: string[];
    confirmLabel: string;
    submitting?: boolean;
    error?: string | null;
    onCancel: () => void;
    onConfirm: (reason: string) => void;
}

/**
 * Bottom sheet asking for a reason (Reject / Void). Quick-reason chips fill
 * the text in one tap; the text stays editable so a manager can add detail.
 * The worker sees this reason on their expense, so it is required.
 */
export function ReasonSheet({
    visible,
    title,
    subtitle,
    quickReasons,
    confirmLabel,
    submitting = false,
    error,
    onCancel,
    onConfirm,
}: ReasonSheetProps) {
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (visible) {
            setReason('');
        }
    }, [visible]);

    const trimmed = reason.trim();

    return (
        <Modal visible={visible} transparent animationType="slide" onRequestClose={onCancel}>
            <Pressable style={styles.backdrop} onPress={onCancel} accessibilityLabel="Close" />
            <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.sheetWrap}>
                <View style={styles.sheet}>
                    <View style={styles.handle} />
                    <Text style={styles.title}>{title}</Text>
                    {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}

                    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
                        {quickReasons.map((quick) => {
                            const active = trimmed === quick;

                            return (
                                <Pressable
                                    key={quick}
                                    accessibilityRole="button"
                                    accessibilityState={{ selected: active }}
                                    onPress={() => setReason(quick)}
                                    style={[styles.chip, active && styles.chipActive]}
                                >
                                    <Text style={[styles.chipText, active && styles.chipTextActive]}>{quick}</Text>
                                </Pressable>
                            );
                        })}
                    </ScrollView>

                    <TextInput
                        label="Reason"
                        placeholder="Tell them why, in a few words"
                        value={reason}
                        onChangeText={setReason}
                        multiline
                        style={styles.input}
                        error={error ?? undefined}
                    />

                    <View style={styles.actions}>
                        <Button title="Cancel" variant="secondary" onPress={onCancel} fullWidth={false} style={styles.action} />
                        <Button
                            title={confirmLabel}
                            variant="danger"
                            onPress={() => onConfirm(trimmed)}
                            disabled={trimmed.length < 3}
                            loading={submitting}
                            fullWidth={false}
                            style={styles.action}
                        />
                    </View>
                </View>
            </KeyboardAvoidingView>
        </Modal>
    );
}

const styles = StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(15, 23, 42, 0.45)' },
    sheetWrap: { position: 'absolute', left: 0, right: 0, bottom: 0 },
    sheet: {
        backgroundColor: colors.surface,
        borderTopLeftRadius: radius.xl,
        borderTopRightRadius: radius.xl,
        padding: spacing.lg,
        paddingBottom: spacing.xxl,
        gap: spacing.md,
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: colors.border },
    title: { fontSize: fontSize.lg, fontWeight: '800', color: colors.textPrimary },
    subtitle: { fontSize: fontSize.sm, color: colors.textSecondary },
    chips: { gap: spacing.sm },
    chip: {
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        paddingHorizontal: spacing.md,
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: { borderColor: colors.danger, backgroundColor: colors.dangerBg },
    chipText: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipTextActive: { color: colors.danger },
    input: { minHeight: 72, textAlignVertical: 'top', paddingTop: spacing.md },
    actions: { flexDirection: 'row', gap: spacing.sm },
    action: { flex: 1 },
});
