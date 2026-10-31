import { useCallback, useEffect, useMemo, useSyncExternalStore } from 'react';
import { Linking, Pressable, RefreshControl, SectionList, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useRouter } from 'expo-router';
import { EmptyState } from '../../src/components/ui/EmptyState';
import { getInboxState, refreshInbox, subscribeInboxState } from '../../src/inbox/inboxStore';
import { webUrl, type InboxItem, type InboxSeverity } from '../../src/api/inbox';
import { formatFcfa, formatRelativeTime } from '../../src/utils/format';
import { colors } from '../../src/theme/colors';
import { fontSize, radius, shadow, spacing, touchTarget } from '../../src/theme/tokens';

const SECTIONS: { severity: InboxSeverity; title: string }[] = [
    { severity: 'now', title: 'Now' },
    { severity: 'today', title: 'Today' },
    { severity: 'fyi', title: 'For your information' },
];

const REFRESH_EVERY_MS = 60_000;

/**
 * The manager inbox (docs/plans/mobile-manager-app.md, wave 1; the agreed
 * design's "C" structure): everything waiting for this user, Now / Today /
 * FYI. Tap an item to decide it; work done on the website opens there.
 * Refreshed on focus and every minute while open; offline, the last list
 * stays, marked as such — deciding needs a connection.
 */
export default function InboxScreen() {
    const router = useRouter();
    const inbox = useSyncExternalStore(subscribeInboxState, getInboxState);

    useFocusEffect(
        useCallback(() => {
            void refreshInbox();
            const timer = setInterval(() => void refreshInbox(), REFRESH_EVERY_MS);

            return () => clearInterval(timer);
        }, []),
    );

    useEffect(() => {
        void refreshInbox();
    }, []);

    const sections = useMemo(
        () =>
            SECTIONS.map((section) => ({ ...section, data: inbox.items.filter((item) => item.severity === section.severity) })).filter(
                (section) => section.data.length > 0,
            ),
        [inbox.items],
    );

    function open(item: InboxItem) {
        if (item.actions.length === 0) {
            void Linking.openURL(webUrl(item.web_path));

            return;
        }

        router.push({ pathname: '/inbox-item', params: { key: item.key } });
    }

    const subtitle = inbox.items.length === 0 ? 'Nothing waiting for you' : `${inbox.items.length} waiting for you`;

    return (
        <SectionList
            style={styles.flex}
            contentContainerStyle={styles.content}
            sections={sections}
            keyExtractor={(item) => item.key}
            stickySectionHeadersEnabled={false}
            refreshControl={<RefreshControl refreshing={inbox.loading && inbox.loadedAt !== null} onRefresh={() => void refreshInbox()} />}
            ListHeaderComponent={
                <View style={styles.header}>
                    <Text style={styles.heading} accessibilityRole="header">
                        Inbox
                    </Text>
                    <Text style={styles.subheading}>{subtitle}</Text>
                    {inbox.offline ? (
                        <Text style={styles.offline}>No connection — showing the last list. Deciding needs internet.</Text>
                    ) : null}
                    {inbox.error ? <Text style={styles.error}>{inbox.error}</Text> : null}
                </View>
            }
            renderSectionHeader={({ section }) => (
                <Text style={[styles.sectionTitle, section.severity === 'now' && styles.sectionNow]}>{section.title.toUpperCase()}</Text>
            )}
            renderItem={({ item }) => <InboxRow item={item} onPress={() => open(item)} />}
            ListEmptyComponent={
                inbox.loadedAt !== null ? (
                    <EmptyState title="All done" subtitle="Approvals, complaints to assign and cash alerts show up here as they arrive." />
                ) : null
            }
        />
    );
}

function InboxRow({ item, onPress }: { item: InboxItem; onPress: () => void }) {
    const amount = item.amount !== null ? Number(item.amount) : null;
    const onWebsite = item.actions.length === 0;

    return (
        <Pressable
            accessibilityRole="button"
            accessibilityHint={onWebsite ? 'Opens on the website' : 'Opens the decision'}
            onPress={onPress}
            style={({ pressed }) => [styles.row, item.severity === 'now' && styles.rowNow, { opacity: pressed ? 0.9 : 1 }]}
        >
            <View style={styles.rowText}>
                <Text style={styles.rowTitle} numberOfLines={2}>
                    {item.title}
                </Text>
                <Text style={styles.rowSubtitle} numberOfLines={2}>
                    {item.subtitle}
                </Text>
                <Text style={styles.rowMeta}>
                    {onWebsite ? 'Open on the website' : item.created_at ? formatRelativeTime(item.created_at) : ''}
                </Text>
            </View>
            {amount !== null ? <Text style={styles.rowAmount}>{(amount < 0 ? '−' : '') + formatFcfa(Math.abs(amount))}</Text> : null}
        </Pressable>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xxl },
    header: { gap: 2, marginBottom: spacing.sm },
    heading: { fontSize: fontSize.xl, fontWeight: '800', color: colors.textPrimary },
    subheading: { fontSize: fontSize.sm, color: colors.textSecondary },
    offline: { marginTop: spacing.sm, fontSize: fontSize.sm, color: colors.status.offlineFg, backgroundColor: colors.status.offlineBg, padding: spacing.sm, borderRadius: radius.md },
    error: { marginTop: spacing.sm, fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    sectionTitle: { marginTop: spacing.md, marginBottom: spacing.xs, fontSize: fontSize.xs, fontWeight: '800', letterSpacing: 0.6, color: colors.textSecondary },
    sectionNow: { color: colors.accent.history },
    row: {
        minHeight: touchTarget.primary,
        flexDirection: 'row',
        alignItems: 'center',
        gap: spacing.md,
        padding: spacing.md,
        borderRadius: radius.lg,
        backgroundColor: colors.surface,
        borderWidth: 1,
        borderColor: colors.border,
        marginBottom: spacing.sm,
        ...shadow.card,
    },
    rowNow: { borderColor: colors.accent.history, borderWidth: 1.5 },
    rowText: { flex: 1, gap: 2 },
    rowTitle: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    rowSubtitle: { fontSize: fontSize.sm, color: colors.textSecondary },
    rowMeta: { fontSize: fontSize.xs, color: colors.textSecondary, marginTop: 2 },
    rowAmount: { fontSize: fontSize.md, fontWeight: '800', color: colors.textPrimary },
});
