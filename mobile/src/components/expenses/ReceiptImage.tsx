import { useState } from 'react';
import { Image, Modal, Pressable, StyleSheet, Text, View, type ImageSourcePropType, type StyleProp, type ImageStyle } from 'react-native';
import { authorisedImageSource } from '../../api/expenditures';
import { colors } from '../../theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../theme/tokens';

interface ReceiptImageProps {
    /** A photo still on this device (preferred — instant, works offline). */
    localUri?: string | null;
    /** ExpenditureResource.receipt_url — fetched with the Bearer token. */
    remoteUrl?: string | null;
    style?: StyleProp<ImageStyle>;
    /** Tapping opens a full-screen viewer (default true). */
    expandable?: boolean;
}

function resolveSource(localUri?: string | null, remoteUrl?: string | null): ImageSourcePropType | null {
    if (localUri) {
        return { uri: localUri };
    }

    return authorisedImageSource(remoteUrl);
}

/**
 * A receipt photo thumbnail that opens full screen on tap. Expense receipt
 * photos are private server-side, so a remote one is loaded through RN
 * <Image>'s `headers` support with the session's Bearer token (see
 * authorisedImageSource()). Renders a quiet placeholder if the image can't
 * load (offline, or no session).
 */
export function ReceiptImage({ localUri, remoteUrl, style, expandable = true }: ReceiptImageProps) {
    const [open, setOpen] = useState(false);
    const [failed, setFailed] = useState(false);
    const source = resolveSource(localUri, remoteUrl);

    if (!source || failed) {
        return (
            <View style={[styles.placeholder, style]}>
                <Text style={styles.placeholderText}>{failed ? 'Photo unavailable offline' : 'No photo'}</Text>
            </View>
        );
    }

    const image = (
        <Image source={source} style={[styles.thumb, style]} resizeMode="cover" onError={() => setFailed(true)} />
    );

    if (!expandable) {
        return image;
    }

    return (
        <>
            <Pressable accessibilityRole="imagebutton" accessibilityLabel="Open receipt photo" onPress={() => setOpen(true)}>
                {image}
            </Pressable>
            <Modal visible={open} animationType="fade" onRequestClose={() => setOpen(false)} statusBarTranslucent>
                <View style={styles.viewer}>
                    <Image source={source} style={styles.full} resizeMode="contain" />
                    <Pressable
                        accessibilityRole="button"
                        accessibilityLabel="Close photo"
                        onPress={() => setOpen(false)}
                        style={styles.close}
                    >
                        <Text style={styles.closeText}>Close</Text>
                    </Pressable>
                </View>
            </Modal>
        </>
    );
}

const styles = StyleSheet.create({
    thumb: { width: '100%', height: 180, borderRadius: radius.md, backgroundColor: colors.surfaceMuted },
    placeholder: {
        width: '100%',
        height: 180,
        borderRadius: radius.md,
        backgroundColor: colors.surfaceMuted,
        alignItems: 'center',
        justifyContent: 'center',
    },
    placeholderText: { fontSize: fontSize.sm, color: colors.textSecondary },
    viewer: { flex: 1, backgroundColor: '#000000', alignItems: 'center', justifyContent: 'center' },
    full: { width: '100%', height: '85%' },
    close: {
        position: 'absolute',
        bottom: spacing.xxl,
        alignSelf: 'center',
        minHeight: touchTarget.primary,
        minWidth: 160,
        paddingHorizontal: spacing.xl,
        borderRadius: radius.pill,
        backgroundColor: colors.textInverse,
        alignItems: 'center',
        justifyContent: 'center',
    },
    closeText: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
});
