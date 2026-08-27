import { View, Text, StyleSheet } from 'react-native';
import { Link, Stack } from 'expo-router';
import { colors } from '../src/theme/colors';
import { fontSize, spacing } from '../src/theme/tokens';

export default function NotFoundScreen() {
    return (
        <>
            <Stack.Screen options={{ title: 'Not found' }} />
            <View style={styles.container}>
                <Text style={styles.title}>This screen doesn't exist.</Text>
                <Link href="/" style={styles.link}>
                    Go back home
                </Link>
            </View>
        </>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        alignItems: 'center',
        justifyContent: 'center',
        padding: spacing.xl,
        backgroundColor: colors.background,
        gap: spacing.md,
    },
    title: { fontSize: fontSize.lg, fontWeight: '700', color: colors.textPrimary },
    link: { fontSize: fontSize.md, color: colors.accent.payment, fontWeight: '600' },
});
