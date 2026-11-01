import { useEffect, useRef, useState } from 'react';
import { Alert, Animated, Easing, Pressable, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import { colors } from '../../theme/colors';
import { fontSize, radius, shadow, spacing, touchTarget } from '../../theme/tokens';
import { HOLD_MS } from '../../utils/holdToConfirm';

interface HoldButtonProps {
    /** e.g. "Hold to approve · 250 000". */
    title: string;
    onConfirm: () => void;
    variant?: 'primary' | 'danger';
    disabled?: boolean;
    style?: StyleProp<ViewStyle>;
}

/**
 * Press and hold to confirm a decision about a big amount (wave 6, design
 * B): a bar fills while the finger stays down; letting go early cancels.
 * A screen reader can't hold, so its "activate" asks for a confirmation
 * instead — same decision, never a way around it.
 */
export function HoldButton({ title, onConfirm, variant = 'primary', disabled = false, style }: HoldButtonProps) {
    const progress = useRef(new Animated.Value(0)).current;
    const [holding, setHolding] = useState(false);
    const animation = useRef<Animated.CompositeAnimation | null>(null);

    useEffect(() => () => animation.current?.stop(), []);

    function start() {
        if (disabled) {
            return;
        }

        setHolding(true);
        animation.current = Animated.timing(progress, { toValue: 1, duration: HOLD_MS, easing: Easing.linear, useNativeDriver: false });
        animation.current.start(({ finished }) => {
            setHolding(false);
            progress.setValue(0);

            if (finished) {
                onConfirm();
            }
        });
    }

    function cancel() {
        animation.current?.stop();
    }

    const fill = variant === 'danger' ? colors.danger : colors.accent.payment;

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityLabel={title}
            accessibilityHint="Press and hold to confirm"
            accessibilityState={{ disabled }}
            accessibilityActions={[{ name: 'activate' }]}
            onAccessibilityAction={() => Alert.alert('Confirm', title, [{ text: 'Cancel', style: 'cancel' }, { text: 'Confirm', onPress: onConfirm }])}
            onPressIn={start}
            onPressOut={cancel}
            disabled={disabled}
            style={[styles.base, { backgroundColor: fill, opacity: disabled ? 0.5 : 1 }, shadow.card, style]}
        >
            <Text style={styles.label} numberOfLines={1}>
                {holding ? 'Keep holding…' : title}
            </Text>
            <View style={styles.track}>
                <Animated.View style={[styles.bar, { width: progress.interpolate({ inputRange: [0, 1], outputRange: ['0%', '100%'] }) }]} />
            </View>
        </Pressable>
    );
}

const styles = StyleSheet.create({
    base: {
        minHeight: touchTarget.primary,
        borderRadius: radius.md,
        alignItems: 'center',
        justifyContent: 'center',
        paddingHorizontal: spacing.lg,
        gap: spacing.xs,
        alignSelf: 'stretch',
    },
    label: { fontSize: fontSize.md, fontWeight: '700', color: colors.textInverse },
    track: { width: '70%', height: 4, borderRadius: 2, backgroundColor: 'rgba(127,127,127,0.35)', overflow: 'hidden' },
    bar: { height: 4, backgroundColor: colors.textInverse },
});
