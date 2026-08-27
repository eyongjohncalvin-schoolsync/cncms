import { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { Card } from '../../../src/components/ui/Card';
import { TextInput } from '../../../src/components/ui/TextInput';
import { EmptyState } from '../../../src/components/ui/EmptyState';
import { getAllCustomers } from '../../../src/db/customers';
import { subscribeSyncState, getSyncState } from '../../../src/sync/syncStore';
import { colors } from '../../../src/theme/colors';
import { fontSize, radius, spacing, touchTarget } from '../../../src/theme/tokens';
import { formatFcfa } from '../../../src/utils/format';
import type { LocalCustomer } from '../../../src/types/db';

type FilterKey = 'all' | 'owes-money' | 'paid-up' | 'disconnected';

const FILTERS: Array<{ key: FilterKey; label: string }> = [
    { key: 'all', label: 'All' },
    { key: 'owes-money', label: 'Owes money' },
    { key: 'paid-up', label: 'Paid up' },
    { key: 'disconnected', label: 'Disconnected' },
];

/** status-color dot per mobile-app-react-native.md §4: active=green,
 * passive=gray, disconnected=red, suspended=orange. */
const STATUS_DOT_COLOR: Record<string, string> = {
    active: colors.status.syncedDot,
    passive: colors.textSecondary,
    disconnected: colors.status.errorDot,
    suspended: colors.status.offlineDot,
};

function arrearsOf(customer: LocalCustomer): number {
    return customer.total_arrears ?? 0;
}

function matchesSearch(customer: LocalCustomer, query: string): boolean {
    if (!query) {
        return true;
    }

    const needle = query.trim().toLowerCase();
    const nameMatch = customer.name.toLowerCase().includes(needle);
    const phoneDigits = (customer.phone ?? '').replace(/\D/g, '');
    const needleDigits = needle.replace(/\D/g, '');
    const phoneMatch = needleDigits.length > 0 && phoneDigits.includes(needleDigits);

    return nameMatch || phoneMatch;
}

function matchesFilter(customer: LocalCustomer, filter: FilterKey): boolean {
    switch (filter) {
        case 'owes-money':
            return arrearsOf(customer) > 0;
        case 'paid-up':
            return arrearsOf(customer) <= 0;
        case 'disconnected':
            return customer.status === 'disconnected';
        case 'all':
        default:
            return true;
    }
}

/**
 * Customer List — cached from local SQLite, scoped to the agent's own zone
 * server-side already (SyncService::upsertedCustomers()'s zone fence), so
 * no client-side zone filtering happens here; this just renders what
 * SyncManager has already pulled. See mobile-app-react-native.md §4.
 *
 * Supports `?filter=owes-money` (and the other FilterKey values) as a deep
 * link, matching Home's "Continue your route" shortcut.
 */
export default function CustomersScreen() {
    const router = useRouter();
    const params = useLocalSearchParams<{ filter?: string }>();

    const [customers, setCustomers] = useState<LocalCustomer[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<FilterKey>(
        FILTERS.some((f) => f.key === params.filter) ? (params.filter as FilterKey) : 'all',
    );

    const refresh = useCallback(() => {
        setLoading(true);
        void getAllCustomers()
            .then(setCustomers)
            .finally(() => setLoading(false));
    }, []);

    useFocusEffect(refresh);

    // Route param can change (a fresh navigation from Home's shortcut while
    // already on this tab) without a full remount — keep the chip in sync.
    useEffect(() => {
        if (params.filter && FILTERS.some((f) => f.key === params.filter)) {
            setFilter(params.filter as FilterKey);
        }
    }, [params.filter]);

    // Refresh the moment a pull lands, same pattern as Home — arrears/status
    // can change from a background sync without the agent leaving the tab.
    useEffect(() => {
        let lastSeen = getSyncState().lastSyncAt;

        return subscribeSyncState(() => {
            const current = getSyncState().lastSyncAt;

            if (current !== lastSeen) {
                lastSeen = current;
                refresh();
            }
        });
    }, [refresh]);

    const filtered = useMemo(
        () =>
            customers
                .filter((c) => matchesFilter(c, filter))
                .filter((c) => matchesSearch(c, search)),
        [customers, filter, search],
    );

    function handleCall(phone: string) {
        void Linking.openURL(`tel:${phone}`);
    }

    function renderItem({ item }: { item: LocalCustomer }) {
        const arrears = arrearsOf(item);

        return (
            <Card onPress={() => router.push(`/(tabs)/customers/${item.uuid}`)} style={styles.row}>
                <View style={styles.rowTop}>
                    <View style={styles.nameRow}>
                        <View
                            style={[styles.dot, { backgroundColor: STATUS_DOT_COLOR[item.status] ?? colors.textSecondary }]}
                        />
                        <Text style={styles.name} numberOfLines={1}>
                            {item.name}
                        </Text>
                    </View>
                    {arrears > 0 ? <Text style={styles.arrears}>{formatFcfa(arrears)}</Text> : null}
                </View>
                {item.phone ? (
                    <Pressable onPress={() => handleCall(item.phone as string)} hitSlop={8}>
                        <Text style={styles.phone}>{item.phone}</Text>
                    </Pressable>
                ) : (
                    <Text style={styles.phoneMissing}>No phone on file</Text>
                )}
            </Card>
        );
    }

    return (
        <View style={styles.flex}>
            <View style={styles.header}>
                <TextInput
                    placeholder="Search by name or phone"
                    value={search}
                    onChangeText={setSearch}
                    autoCapitalize="none"
                    autoCorrect={false}
                />
                <FlatList
                    horizontal
                    data={FILTERS}
                    keyExtractor={(f) => f.key}
                    showsHorizontalScrollIndicator={false}
                    contentContainerStyle={styles.chipRow}
                    renderItem={({ item }) => {
                        const active = item.key === filter;

                        return (
                            <Pressable
                                accessibilityRole="button"
                                accessibilityState={{ selected: active }}
                                onPress={() => setFilter(item.key)}
                                style={[styles.chip, active && styles.chipActive]}
                            >
                                <Text style={[styles.chipLabel, active && styles.chipLabelActive]}>{item.label}</Text>
                            </Pressable>
                        );
                    }}
                />
            </View>

            {!loading && filtered.length === 0 ? (
                <EmptyState
                    title={customers.length === 0 ? 'No customers cached yet' : 'No matches'}
                    subtitle={
                        customers.length === 0
                            ? 'Your zone\'s customer list syncs to this device automatically once online.'
                            : 'Try a different search or filter.'
                    }
                />
            ) : (
                <FlatList
                    data={filtered}
                    keyExtractor={(c) => c.uuid}
                    renderItem={renderItem}
                    contentContainerStyle={styles.listContent}
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    flex: { flex: 1, backgroundColor: colors.background },
    header: { padding: spacing.lg, gap: spacing.md },
    chipRow: { gap: spacing.sm },
    // minHeight/justifyContent: padding alone put this under the 48dp
    // touch-target floor (mobile-app-react-native.md §6) — these filter
    // chips are tapped at the start of every zone round, same reasoning as
    // record-expense.tsx's date chips.
    chip: {
        paddingHorizontal: spacing.md,
        paddingVertical: spacing.sm,
        minHeight: touchTarget.floor,
        justifyContent: 'center',
        borderRadius: radius.pill,
        borderWidth: 1,
        borderColor: colors.border,
        backgroundColor: colors.surface,
    },
    chipActive: {
        backgroundColor: colors.accent.customers,
        borderColor: colors.accent.customers,
    },
    chipLabel: { fontSize: fontSize.sm, fontWeight: '600', color: colors.textPrimary },
    chipLabelActive: { color: colors.textInverse },
    listContent: { padding: spacing.lg, paddingTop: 0, gap: spacing.sm },
    row: { gap: spacing.xs },
    rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
    nameRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, flexShrink: 1 },
    dot: { width: 10, height: 10, borderRadius: 5 },
    name: { fontSize: fontSize.md, fontWeight: '700', color: colors.textPrimary, flexShrink: 1 },
    arrears: { fontSize: fontSize.md, fontWeight: '800', color: colors.danger },
    phone: { fontSize: fontSize.sm, color: colors.accent.home, fontWeight: '600' },
    phoneMissing: { fontSize: fontSize.sm, color: colors.textSecondary },
});
