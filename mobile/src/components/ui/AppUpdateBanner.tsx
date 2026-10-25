import { useSyncExternalStore } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import * as Application from 'expo-application';
import { getAppUpdateState, subscribeAppUpdateState } from '../../maintenance/appUpdateStore';
import { needsUpdate } from '../../utils/appUpdate';
import { colors } from '../../theme/colors';
import { fontSize, spacing } from '../../theme/tokens';

/**
 * "New version available" (src/utils/appUpdate.ts), stacked with the other
 * banners above the sync strip in app/(tabs)/_layout.tsx. Blue, like
 * syncing: news, not a problem — work carries on in the old build. Compares
 * the installed APK's own version (expo-application), which an over-the-air
 * update never changes.
 */
export function AppUpdateBanner() {
    const info = useSyncExternalStore(subscribeAppUpdateState, getAppUpdateState);

    if (!info || !needsUpdate(Application.nativeApplicationVersion, info)) {
        return null;
    }

    return (
        <Pressable
            accessibilityRole="link"
            accessibilityHint="Opens the download for the new version"
            onPress={() => void Linking.openURL(info.downloadUrl)}
            style={styles.banner}
        >
            <View style={styles.dot} />
            <Text style={styles.label} numberOfLines={2}>
                Version {info.latestVersion} is available — tap to download and install it.
            </Text>
        </Pressable>
    );
}

const styles = StyleSheet.create({
    banner: {
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.sm,
        minHeight: 32,
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.xs,
        backgroundColor: colors.status.syncingBg,
        borderBottomWidth: 1,
        borderBottomColor: colors.status.syncingDot,
    },
    dot: {
        width: 8,
        height: 8,
        borderRadius: 4,
        backgroundColor: colors.status.syncingDot,
    },
    label: {
        flexShrink: 1,
        fontSize: fontSize.xs,
        fontWeight: '700',
        color: colors.status.syncingFg,
    },
});
