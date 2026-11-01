import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useAuth } from '../src/auth/AuthContext';
import { Badge } from '../src/components/ui/Badge';
import { Card } from '../src/components/ui/Card';
import { EmptyState } from '../src/components/ui/EmptyState';
import { fetchDesk, type DeskComplaint, type DeskView } from '../src/api/complaintDesk';
import { extractErrorMessage } from '../src/api/client';
import { deskStatus } from '../src/utils/deskStatus';
import { formatRelativeTime } from '../src/utils/format';
import { colors } from '../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../src/theme/tokens';

const TABS: { view: DeskView; label: string }[] = [
    { view: 'unassigned', label: 'To assign' },
    { view: 'open', label: 'All open' },
    { view: 'mine', label: 'My jobs' },
];

const EMPTY: Record<DeskView, { title: string; subtitle: string }> = {
    unassigned: { title: 'Nothing to assign', subtitle: 'Every open complaint has a technician.' },
    open: { title: 'No open complaints', subtitle: 'Everything reported has been resolved.' },
    mine: { title: 'No jobs for you', subtitle: 'When a complaint is given to you, your phone rings and it shows here.' },
};

/**
 * The complaint desk on the phone (docs/plans/mobile-manager-app.md,
 * wave 3). Whoever assigns technicians sees To assign / All open / My
 * jobs; a technician sees only their own jobs. Online only — read from the
 * server on every visit and pull-to-refresh.
 */
export default function ComplaintDeskScreen() {
    const router = useRouter();
    const { can } = useAuth();
    const params = useLocalSearchParams<{ view?: DeskView }>();
    const assigns = can('complaints.assign');
    const tabs = assigns ? TABS : TABS.filter((tab) => tab.view === 'mine');
    const [view, setView] = useState<DeskView>(tabs.some((tab) => tab.view === params.view) ? params.view! : tabs[0].view);
    const [items, setItems] = useState<DeskComplaint[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    const load = useCallback(async (which: DeskView) => {
        setRefreshing(true);

        try {
            setItems(await fetchDesk(which));
            setError(null);
        } catch (e) {
            setError(extractErrorMessage(e, 'Could not load complaints. Check your connection.'));
        } finally {
            setRefreshing(false);
        }
    }, []);

    useFocusEffect(
        useCallback(() => {
            void load(view);
        }, [load, view]),
    );

    function choose(next: DeskView) {
        setItems(null);
        setView(next);
    }

    return (
        <View style={styles.flex}>
            <Stack.Screen options={{ title: assigns ? 'Complaint desk' : 'My jobs' }} />

            {tabs.length > 1 ? (
                <View style={styles.tabs} accessibilityRole="tablist">
                    {tabs.map((tab) => (
                        <Pressable
                            key={tab.view}
                            accessibilityRole="tab"
                            accessibilityState={{ selected: view === tab.view }}
                            onPress={() => choose(tab.view)}
                            style={[styles.tab, view === tab.view && styles.tabActive]}
                        >
                            <Text style={[styles.tabText, view === tab.view && styles.tabTextActive]}>{tab.label}</Text>
                        </Pressable>
                    ))}
                </View>
            ) : null}

            {error ? <Text style={styles.error}>{error}</Text> : null}

            <FlatList
                data={items ?? []}
                keyExtractor={(item) => item.uuid}
                contentContainerStyle={styles.content}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => void load(view)} />}
                ListEmptyComponent={items !== null && !error ? <EmptyState title={EMPTY[view].title} subtitle={EMPTY[view].subtitle} /> : null}
                renderItem={({ item }) => {
                    const badge = deskStatus(item);
                    const where = item.customer_name ?? item.zone_name ?? 'General';

                    return (
                        <Card accentColor={item.urgent ? colors.danger : colors.accent.complaint} onPress={() => router.push({ pathname: '/complaint/[uuid]', params: { uuid: item.uuid } })}>
                            <View style={styles.row}>
                                <Text style={styles.title} numberOfLines={2}>
                                    {item.urgent ? 'URGENT · ' : ''}
                                    {item.title}
                                </Text>
                                <Badge label={badge.label} tone={badge.tone} />
                            </View>
                            <Text style={styles.meta}>
                                {where}
                                {item.zone_name && item.customer_name ? ` · ${item.zone_name}` : ''} · {formatRelativeTime(item.created_at)}
                            </Text>
                            {view !== 'mine' ? <Text style={styles.meta}>{item.assigned_to ? `With ${item.assigned_to.name}` : 'Nobody assigned'}</Text> : null}
                        </Card>
                    );
                }}
            />
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    content: { padding: spacing.lg, gap: spacing.sm, paddingBottom: spacing.xxl },
    tabs: { flexDirection: 'row', gap: spacing.xs, paddingHorizontal: spacing.lg, paddingTop: spacing.md },
    tab: { flex: 1, minHeight: touchTarget.floor, alignItems: 'center', justifyContent: 'center', borderRadius: radius.md, borderWidth: 1, borderColor: colors.border },
    tabActive: { backgroundColor: colors.accent.complaint, borderColor: colors.accent.complaint },
    tabText: { fontSize: fontSize.sm, fontWeight: '700', color: colors.textPrimary },
    tabTextActive: { color: colors.textInverse },
    error: { margin: spacing.lg, marginBottom: 0, fontSize: fontSize.sm, color: colors.status.errorFg, backgroundColor: colors.status.errorBg, padding: spacing.sm, borderRadius: radius.md },
    row: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.sm },
    title: { flex: 1, fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary },
    meta: { fontSize: fontSize.sm, color: colors.textSecondary, marginTop: 2 },
});
